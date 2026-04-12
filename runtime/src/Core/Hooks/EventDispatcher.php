<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Hooks;

use WPApps\Runtime\Core\Auth\SignatureVerifier;

/**
 * Tier 1: Async event webhooks.
 *
 * Fires webhooks to apps when WordPress events occur (save_post, user_register, etc.).
 * Always async fire-and-forget. NEVER blocks page loads.
 * This is the preferred integration model.
 */
class EventDispatcher
{
    public function __construct(
        private readonly Registry $registry,
        private readonly SignatureVerifier $signatureVerifier
    ) {}

    /**
     * Register event webhooks for all active apps.
     */
    public function registerForApps(array $apps): void
    {
        foreach ($apps as $app) {
            $manifest = $app['manifest'];

            foreach ($manifest['hooks']['events'] ?? [] as $eventDef) {
                $eventName = $eventDef['event'];

                if (!$this->registry->isAllowed($eventName, 'events')) {
                    continue;
                }

                $priority = $eventDef['priority'] ?? 10;

                add_action($eventName, function () use ($app, $eventName) {
                    $args = func_get_args();
                    $this->dispatch($app, $eventName, $args);
                }, $priority, 10);
            }

            // Also support legacy 'actions' key with async:true for backwards compat
            foreach ($manifest['hooks']['actions'] ?? [] as $hookDef) {
                if (empty($hookDef['async'])) {
                    continue; // Non-async actions are Tier 2 — handled by RuntimeHookDispatcher
                }

                $hookName = $hookDef['hook'];
                if (!$this->registry->isAllowed($hookName, 'events')) {
                    continue;
                }

                $priority = $hookDef['priority'] ?? 10;

                add_action($hookName, function () use ($app, $hookName) {
                    $args = func_get_args();
                    $this->dispatch($app, $hookName, $args);
                }, $priority, 10);
            }
        }
    }

    /**
     * Fire-and-forget webhook to app.
     */
    private function dispatch(array $app, string $event, array $args): void
    {
        $deliveryId = wp_generate_uuid4();

        $payload = [
            'delivery_id' => $deliveryId,
            'event' => $event,
            'type' => 'event',
            'args' => $this->serializeArgs($args),
            'context' => $this->buildContext(),
            'site' => [
                'url' => home_url(),
                'id' => get_option('wp_apps_site_id', ''),
            ],
        ];

        $body = wp_json_encode($payload);
        $webhookUrl = rtrim($app['endpoint'], '/') . ($app['manifest']['runtime']['webhook_path'] ?? '/hooks');
        $siteId = get_option('wp_apps_site_id', '');

        $headers = $this->signatureVerifier->buildHeaders($body, $app['shared_secret'], $siteId, $event, $deliveryId);
        $headers['Content-Type'] = 'application/json';

        // Always non-blocking. App processes in the background.
        wp_remote_post($webhookUrl, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 0.5,
            'blocking' => false,
            'sslverify' => !$this->isLocalEndpoint($app['endpoint']),
        ]);

        $this->logEvent($app['app_id'], $event, $deliveryId);
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
        ];

        if ($post instanceof \WP_Post) {
            $context['post_id'] = $post->ID;
            $context['post_type'] = $post->post_type;
            $context['post_status'] = $post->post_status;
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

    private function logEvent(string $appId, string $event, string $deliveryId): void
    {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}apps_audit_log",
            [
                'app_id' => $appId,
                'action_type' => 'event_webhook',
                'hook' => $event,
                'delivery_id' => $deliveryId,
                'details' => 'fired',
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }
}
