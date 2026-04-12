<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Hooks;

use WPApps\Runtime\Core\Auth\SignatureVerifier;

/**
 * Tier 2: Runtime hooks (ESCAPE HATCH — use sparingly).
 *
 * Dispatches render-path filters (the_content, wp_head, etc.) to apps via HTTP.
 * Each dispatch adds latency to the page load. Apps should prefer:
 * - Blocks for frontend UI
 * - Post meta + MetaRenderer for wp_head data
 * - Event webhooks for reactivity
 *
 * Only use this when the above patterns are insufficient.
 */
class RuntimeHookDispatcher
{
    /** Hooks that trigger a performance warning in admin */
    private const PERF_WARNING_HOOKS = ['the_content', 'the_title', 'the_excerpt', 'body_class', 'post_class'];

    public function __construct(
        private readonly Registry $registry,
        private readonly SignatureVerifier $signatureVerifier
    ) {}

    /**
     * Register runtime hooks for all active apps.
     */
    public function registerForApps(array $apps): void
    {
        foreach ($apps as $app) {
            $manifest = $app['manifest'];

            // Register filters (Tier 2)
            foreach ($manifest['hooks']['filters'] ?? [] as $hookDef) {
                $hookName = $hookDef['hook'];

                if (!$this->registry->isAllowed($hookName, 'filters')) {
                    continue;
                }

                // Log performance warning for render-path hooks
                if (in_array($hookName, self::PERF_WARNING_HOOKS, true)) {
                    $this->logPerfWarning($app['app_id'], $hookName);
                }

                $priority = $hookDef['priority'] ?? 10;
                $timeout = $this->registry->getEffectiveTimeout(
                    $hookName,
                    'filters',
                    $hookDef['timeout_ms'] ?? 5000
                );

                add_filter($hookName, function () use ($app, $hookName, $timeout) {
                    $args = func_get_args();
                    return $this->dispatchFilter($app, $hookName, $args, $timeout);
                }, $priority);
            }

            // Register non-async actions (Tier 2 — sync actions are rare)
            foreach ($manifest['hooks']['actions'] ?? [] as $hookDef) {
                if (!empty($hookDef['async'])) {
                    continue; // Async actions are handled by EventDispatcher
                }

                $hookName = $hookDef['hook'];
                if (!$this->registry->isAllowed($hookName, 'actions')) {
                    continue;
                }

                $priority = $hookDef['priority'] ?? 10;
                $timeout = $this->registry->getEffectiveTimeout(
                    $hookName,
                    'actions',
                    $hookDef['timeout_ms'] ?? 5000
                );

                add_action($hookName, function () use ($app, $hookName, $timeout) {
                    $args = func_get_args();
                    $this->dispatchSyncAction($app, $hookName, $args, $timeout);
                }, $priority, 10);
            }
        }
    }

    /**
     * Dispatch a filter and return the modified value.
     * On failure: fail open with unmodified value.
     */
    private function dispatchFilter(array $app, string $hook, array $args, int $timeoutMs): mixed
    {
        $originalValue = $args[0] ?? '';
        $deliveryId = wp_generate_uuid4();

        $payload = $this->buildPayload($hook, 'filter', $args, $deliveryId);
        $body = wp_json_encode($payload);

        $webhookUrl = rtrim($app['endpoint'], '/') . ($app['manifest']['runtime']['webhook_path'] ?? '/hooks');
        $siteId = get_option('wp_apps_site_id', '');

        $headers = $this->signatureVerifier->buildHeaders($body, $app['shared_secret'], $siteId, $hook, $deliveryId);
        $headers['Content-Type'] = 'application/json';

        $startTime = microtime(true);

        $response = wp_remote_post($webhookUrl, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutMs / 1000,
            'sslverify' => !$this->isLocalEndpoint($app['endpoint']),
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if (is_wp_error($response)) {
            $this->logDispatch($app['app_id'], $hook, $deliveryId, 0, $durationMs, 'timeout');
            return $originalValue;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        $this->logDispatch($app['app_id'], $hook, $deliveryId, $statusCode, $durationMs, 'ok');

        if ($statusCode !== 200 || ($responseBody['status'] ?? '') !== 'ok') {
            return $originalValue;
        }

        return $responseBody['result'] ?? $originalValue;
    }

    private function dispatchSyncAction(array $app, string $hook, array $args, int $timeoutMs): void
    {
        $deliveryId = wp_generate_uuid4();
        $payload = $this->buildPayload($hook, 'action', $args, $deliveryId);
        $body = wp_json_encode($payload);

        $webhookUrl = rtrim($app['endpoint'], '/') . ($app['manifest']['runtime']['webhook_path'] ?? '/hooks');
        $siteId = get_option('wp_apps_site_id', '');

        $headers = $this->signatureVerifier->buildHeaders($body, $app['shared_secret'], $siteId, $hook, $deliveryId);
        $headers['Content-Type'] = 'application/json';

        $startTime = microtime(true);
        $response = wp_remote_post($webhookUrl, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutMs / 1000,
            'sslverify' => !$this->isLocalEndpoint($app['endpoint']),
        ]);
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $status = is_wp_error($response) ? 'error' : 'ok';
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $this->logDispatch($app['app_id'], $hook, $deliveryId, $code, $durationMs, $status);
    }

    private function buildPayload(string $hook, string $type, array $args, string $deliveryId): array
    {
        return [
            'delivery_id' => $deliveryId,
            'hook' => $hook,
            'type' => $type,
            'args' => $this->serializeArgs($args),
            'context' => $this->buildContext(),
            'site' => [
                'url' => home_url(),
                'id' => get_option('wp_apps_site_id', ''),
            ],
        ];
    }

    private function serializeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if ($arg instanceof \WP_Post) {
                return [
                    'ID' => $arg->ID,
                    'post_title' => $arg->post_title,
                    'post_content' => $arg->post_content,
                    'post_status' => $arg->post_status,
                    'post_type' => $arg->post_type,
                    'post_author' => (int) $arg->post_author,
                ];
            }
            if (is_object($arg)) {
                return (array) $arg;
            }
            return $arg;
        }, $args);
    }

    private function buildContext(): array
    {
        global $post;

        $context = [
            'timestamp' => time(),
            'locale' => get_locale(),
            'is_admin' => is_admin(),
            'is_rest' => defined('REST_REQUEST') && REST_REQUEST,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ];

        if ($post instanceof \WP_Post) {
            $context['post_id'] = $post->ID;
            $context['post_type'] = $post->post_type;
            $context['post_status'] = $post->post_status;
            $context['post_title'] = $post->post_title;
        }

        $currentUser = wp_get_current_user();
        if ($currentUser->ID > 0) {
            $context['user_id'] = $currentUser->ID;
            $context['user_role'] = $currentUser->roles[0] ?? '';
        }

        return $context;
    }

    private function isLocalEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function logDispatch(string $appId, string $hook, string $deliveryId, int $statusCode, int $durationMs, string $status): void
    {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}apps_audit_log",
            [
                'app_id' => $appId,
                'action_type' => 'runtime_hook',
                'hook' => $hook,
                'delivery_id' => $deliveryId,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'details' => $status,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s']
        );
    }

    private function logPerfWarning(string $appId, string $hook): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_notices', function () use ($appId, $hook) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>WP Apps Performance Warning:</strong> ';
            echo 'App <code>' . esc_html($appId) . '</code> subscribes to <code>' . esc_html($hook) . '</code> ';
            echo '(render-path filter). This adds an HTTP round-trip to every page load. ';
            echo 'Consider using blocks or post meta instead.';
            echo '</p></div>';
        });
    }
}
