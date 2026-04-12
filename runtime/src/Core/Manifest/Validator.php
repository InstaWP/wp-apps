<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Manifest;

use WP_Error;

class Validator
{
    private const REQUIRED_APP_FIELDS = ['id', 'name', 'version', 'description', 'author', 'license'];
    private const REQUIRED_RUNTIME_FIELDS = ['endpoint', 'health_check', 'auth_callback', 'webhook_path'];

    private const SCOPE_PATTERN = '/^[a-z_]+:(read|write|delete|send|register|extend)(:[a-z_*]+)?$/';

    private const KNOWN_SCOPES = [
        'posts:read', 'posts:read:published', 'posts:write', 'posts:delete',
        'postmeta:read', 'postmeta:write',
        'users:read:basic', 'users:read:full', 'users:write',
        'media:read', 'media:write',
        'comments:read', 'comments:write',
        'taxonomies:read', 'taxonomies:write',
        'menus:read', 'menus:write',
        'site:read', 'site:write',
        'themes:read', 'plugins:read',
        'email:send', 'cron:register', 'blocks:register', 'rest:extend',
    ];

    private const MAX_TIMEOUT_MS = 30000;
    private const MAX_PAYLOAD_BYTES = 5 * 1024 * 1024; // 5MB

    /**
     * @return true|WP_Error
     */
    public function validate(array $manifest): true|WP_Error
    {
        // Validate app section
        if (!isset($manifest['app']) || !is_array($manifest['app'])) {
            return new WP_Error('invalid_manifest', 'Missing required "app" section.');
        }

        foreach (self::REQUIRED_APP_FIELDS as $field) {
            if (empty($manifest['app'][$field])) {
                return new WP_Error('invalid_manifest', "Missing required app field: {$field}");
            }
        }

        // Validate app.id format (reverse domain)
        if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*(\.[a-z][a-z0-9-]+)$/', $manifest['app']['id'])) {
            return new WP_Error('invalid_manifest', 'app.id must be a reverse-domain identifier (e.g., com.example.my-app).');
        }

        // Validate runtime section
        if (!isset($manifest['runtime']) || !is_array($manifest['runtime'])) {
            return new WP_Error('invalid_manifest', 'Missing required "runtime" section.');
        }

        foreach (self::REQUIRED_RUNTIME_FIELDS as $field) {
            if (empty($manifest['runtime'][$field])) {
                return new WP_Error('invalid_manifest', "Missing required runtime field: {$field}");
            }
        }

        // Validate endpoint is HTTPS (except localhost for dev)
        $endpoint = $manifest['runtime']['endpoint'];
        $host = parse_url($endpoint, PHP_URL_HOST);
        if ($host !== 'localhost' && $host !== '127.0.0.1' && !str_starts_with($endpoint, 'https://')) {
            return new WP_Error('invalid_manifest', 'runtime.endpoint must use HTTPS (except localhost).');
        }

        // Validate timeout
        $timeout = $manifest['runtime']['timeout_ms'] ?? 5000;
        if ($timeout > self::MAX_TIMEOUT_MS) {
            return new WP_Error('invalid_manifest', "runtime.timeout_ms cannot exceed " . self::MAX_TIMEOUT_MS);
        }

        // Validate payload size
        $payload = $manifest['runtime']['max_payload_bytes'] ?? 1048576;
        if ($payload > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error('invalid_manifest', "runtime.max_payload_bytes cannot exceed " . self::MAX_PAYLOAD_BYTES);
        }

        // Validate permissions
        if (!isset($manifest['permissions']['scopes']) || !is_array($manifest['permissions']['scopes'])) {
            return new WP_Error('invalid_manifest', 'Missing required permissions.scopes array.');
        }

        foreach ($manifest['permissions']['scopes'] as $scope) {
            if (!$this->isValidScope($scope)) {
                return new WP_Error('invalid_manifest', "Invalid scope format: {$scope}");
            }
        }

        // Validate hooks if present
        if (isset($manifest['hooks'])) {
            $result = $this->validateHooks($manifest['hooks']);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }

    private function isValidScope(string $scope): bool
    {
        if (in_array($scope, self::KNOWN_SCOPES, true)) {
            return true;
        }

        return (bool) preg_match(self::SCOPE_PATTERN, $scope);
    }

    private function validateHooks(array $hooks): true|WP_Error
    {
        foreach (['filters', 'actions'] as $type) {
            if (!isset($hooks[$type])) {
                continue;
            }

            if (!is_array($hooks[$type])) {
                return new WP_Error('invalid_manifest', "hooks.{$type} must be an array.");
            }

            foreach ($hooks[$type] as $hook) {
                if (empty($hook['hook'])) {
                    return new WP_Error('invalid_manifest', "Each hook subscription must have a 'hook' field.");
                }

                if (isset($hook['timeout_ms']) && $hook['timeout_ms'] > self::MAX_TIMEOUT_MS) {
                    return new WP_Error(
                        'invalid_manifest',
                        "Hook {$hook['hook']} timeout_ms cannot exceed " . self::MAX_TIMEOUT_MS
                    );
                }
            }
        }

        return true;
    }
}
