<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPApps\Runtime\Core\Manifest\Parser;

class OAuthController
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly SignatureVerifier $signatureVerifier
    ) {}

    public function registerRoutes(): void
    {
        register_rest_route('apps/v1', '/auth/install', [
            'methods' => 'POST',
            'callback' => [$this, 'handleInstall'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('apps/v1', '/auth/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'handleApprove'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('apps/v1', '/token', [
            'methods' => 'POST',
            'callback' => [$this, 'handleTokenExchange'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('apps/v1', '/token/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'handleTokenRefresh'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Step 1: Admin submits a manifest URL to install an app.
     */
    public function handleInstall(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $manifestUrl = $request->get_param('manifest_url');

        if (empty($manifestUrl) || !filter_var($manifestUrl, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'A valid manifest URL is required.', ['status' => 400]);
        }

        $plugin = \WPApps\Runtime\Core\Plugin::instance();
        $parser = $plugin->getManifestParser();

        $manifest = $parser->fetchAndParse($manifestUrl);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        // Return manifest for admin to review permissions
        return new WP_REST_Response([
            'app' => $manifest['app'],
            'permissions' => $manifest['permissions'],
            'hooks' => $manifest['hooks'] ?? [],
            'surfaces' => $manifest['surfaces'] ?? [],
            'manifest_url' => $manifestUrl,
        ], 200);
    }

    /**
     * Step 2: Admin approves the app's permissions.
     */
    public function handleApprove(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $manifestUrl = $request->get_param('manifest_url');

        if (empty($manifestUrl)) {
            return new WP_Error('missing_param', 'manifest_url is required.', ['status' => 400]);
        }

        $plugin = \WPApps\Runtime\Core\Plugin::instance();
        $parser = $plugin->getManifestParser();

        $manifest = $parser->fetchAndParse($manifestUrl);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        // Install the app
        $result = $parser->install($manifest, $manifestUrl);
        if (is_wp_error($result)) {
            return $result;
        }

        // Generate auth code
        $scopes = $manifest['permissions']['scopes'];
        $authCode = $this->tokenManager->createAuthCode($result['app_id'], $scopes);

        // POST the auth callback to the app
        $callbackUrl = rtrim($manifest['runtime']['endpoint'], '/') . $manifest['runtime']['auth_callback'];

        $siteId = $this->getSiteId();
        $callbackBody = wp_json_encode([
            'site_url' => home_url(),
            'site_id' => $siteId,
            'auth_code' => $authCode,
            'scopes_granted' => $scopes,
        ]);

        $headers = $this->signatureVerifier->buildHeaders(
            $callbackBody,
            $result['shared_secret'],
            $siteId
        );
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post($callbackUrl, [
            'body' => $callbackBody,
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'callback_failed',
                'Failed to notify app: ' . $response->get_error_message(),
                ['status' => 502]
            );
        }

        // Activate the app
        $parser->updateStatus($result['app_id'], 'active');

        return new WP_REST_Response([
            'status' => 'installed',
            'app_id' => $result['app_id'],
            'message' => 'App installed and activated.',
        ], 201);
    }

    /**
     * Step 3: App exchanges auth code for tokens.
     */
    public function handleTokenExchange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId = $request->get_param('app_id');
        $code = $request->get_param('code');

        if (empty($appId) || empty($code)) {
            return new WP_Error('missing_params', 'app_id and code are required.', ['status' => 400]);
        }

        $result = $this->tokenManager->exchangeAuthCode($appId, $code);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Refresh an expired access token.
     */
    public function handleTokenRefresh(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $appId = $request->get_param('app_id');
        $refreshToken = $request->get_param('refresh_token');

        if (empty($appId) || empty($refreshToken)) {
            return new WP_Error('missing_params', 'app_id and refresh_token are required.', ['status' => 400]);
        }

        $result = $this->tokenManager->refreshToken($appId, $refreshToken);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    private function getSiteId(): string
    {
        $siteId = get_option('wp_apps_site_id');
        if (!$siteId) {
            $siteId = bin2hex(random_bytes(4));
            update_option('wp_apps_site_id', $siteId);
        }
        return $siteId;
    }
}
