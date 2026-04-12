<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Manifest;

use WP_Error;

class Parser
{
    public function __construct(
        private readonly Validator $validator
    ) {}

    /**
     * Fetch and parse a manifest from a URL.
     *
     * @return array|WP_Error Parsed manifest array or error.
     */
    public function fetchAndParse(string $manifestUrl): array|WP_Error
    {
        $response = wp_remote_get($manifestUrl, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('manifest_fetch_failed', 'Could not fetch manifest: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('manifest_fetch_failed', "Manifest URL returned HTTP {$code}.");
        }

        $body = wp_remote_retrieve_body($response);
        $manifest = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('manifest_parse_failed', 'Manifest is not valid JSON: ' . json_last_error_msg());
        }

        $valid = $this->validator->validate($manifest);
        if (is_wp_error($valid)) {
            return $valid;
        }

        return $manifest;
    }

    /**
     * Parse a manifest from a local JSON string.
     *
     * @return array|WP_Error
     */
    public function parse(string $json): array|WP_Error
    {
        $manifest = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('manifest_parse_failed', 'Invalid JSON: ' . json_last_error_msg());
        }

        $valid = $this->validator->validate($manifest);
        if (is_wp_error($valid)) {
            return $valid;
        }

        return $manifest;
    }

    /**
     * Install an app from a validated manifest.
     *
     * @return array{app_id: string, shared_secret: string}|WP_Error
     */
    public function install(array $manifest, string $manifestUrl): array|WP_Error
    {
        global $wpdb;

        $appId = $manifest['app']['id'];

        // Check for duplicate
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}apps_installed WHERE app_id = %s",
                $appId
            )
        );

        if ($existing) {
            return new WP_Error('app_already_installed', "App '{$appId}' is already installed.");
        }

        // Generate shared secret for HMAC signing
        $sharedSecret = bin2hex(random_bytes(32));

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}apps_installed",
            [
                'app_id' => $appId,
                'name' => $manifest['app']['name'],
                'version' => $manifest['app']['version'],
                'manifest_url' => $manifestUrl,
                'manifest_json' => wp_json_encode($manifest),
                'endpoint' => $manifest['runtime']['endpoint'],
                'status' => 'installed',
                'shared_secret' => $sharedSecret,
                'scopes_granted' => wp_json_encode($manifest['permissions']['scopes']),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('install_failed', 'Failed to save app to database.');
        }

        return [
            'app_id' => $appId,
            'shared_secret' => $sharedSecret,
        ];
    }

    /**
     * Get an installed app by its app_id.
     */
    public function getApp(string $appId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}apps_installed WHERE app_id = %s",
                $appId
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row['manifest'] = json_decode($row['manifest_json'], true);
        $row['scopes'] = json_decode($row['scopes_granted'], true);

        return $row;
    }

    /**
     * Get all active apps.
     *
     * @return array<array>
     */
    public function getActiveApps(): array
    {
        global $wpdb;

        // Suppress errors if table doesn't exist yet (first load)
        $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}apps_installed WHERE status = 'active'",
            ARRAY_A
        );
        $wpdb->suppress_errors(false);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['manifest'] = json_decode($row['manifest_json'], true);
            $row['scopes'] = json_decode($row['scopes_granted'], true);
            return $row;
        }, $rows);
    }

    /**
     * Update app status.
     */
    public function updateStatus(string $appId, string $status): bool
    {
        global $wpdb;

        $updated = $wpdb->update(
            "{$wpdb->prefix}apps_installed",
            ['status' => $status],
            ['app_id' => $appId],
            ['%s'],
            ['%s']
        );

        return $updated !== false;
    }
}
