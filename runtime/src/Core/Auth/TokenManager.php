<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Auth;

use WP_Error;

class TokenManager
{
    private const ACCESS_TOKEN_TTL = 3600;      // 1 hour
    private const REFRESH_TOKEN_TTL = 7776000;  // 90 days
    private const AUTH_CODE_TTL = 600;           // 10 minutes

    /**
     * Generate an auth code for the OAuth flow.
     */
    public function createAuthCode(string $appId, array $scopes): string
    {
        global $wpdb;

        $code = bin2hex(random_bytes(32));

        $wpdb->insert(
            "{$wpdb->prefix}apps_auth_codes",
            [
                'app_id' => $appId,
                'code' => hash('sha256', $code),
                'scopes' => wp_json_encode($scopes),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::AUTH_CODE_TTL),
            ],
            ['%s', '%s', '%s', '%s']
        );

        return $code;
    }

    /**
     * Exchange an auth code for access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array}|WP_Error
     */
    public function exchangeAuthCode(string $appId, string $code): array|WP_Error
    {
        global $wpdb;

        $codeHash = hash('sha256', $code);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}apps_auth_codes
                 WHERE app_id = %s AND code = %s AND used = 0 AND expires_at > %s",
                $appId,
                $codeHash,
                gmdate('Y-m-d H:i:s')
            ),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('invalid_code', 'Auth code is invalid, expired, or already used.', ['status' => 400]);
        }

        // Mark code as used
        $wpdb->update(
            "{$wpdb->prefix}apps_auth_codes",
            ['used' => 1],
            ['id' => $row['id']],
            ['%d'],
            ['%d']
        );

        $scopes = json_decode($row['scopes'], true);

        return $this->createTokenPair($appId, $scopes);
    }

    /**
     * Create a new access + refresh token pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array}
     */
    public function createTokenPair(string $appId, array $scopes): array
    {
        global $wpdb;

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        $wpdb->insert(
            "{$wpdb->prefix}apps_tokens",
            [
                'app_id' => $appId,
                'access_token_hash' => hash('sha256', $accessToken),
                'refresh_token_hash' => hash('sha256', $refreshToken),
                'refresh_token_encrypted' => $this->encrypt($refreshToken),
                'scopes' => wp_json_encode($scopes),
                'access_expires_at' => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL),
                'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'token_type' => 'Bearer',
            'scopes' => $scopes,
        ];
    }

    /**
     * Validate an access token and return its data.
     *
     * @return array{app_id: string, scopes: array}|WP_Error
     */
    public function validateAccessToken(string $token): array|WP_Error
    {
        global $wpdb;

        $hash = hash('sha256', $token);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT app_id, scopes, access_expires_at
                 FROM {$wpdb->prefix}apps_tokens
                 WHERE access_token_hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('invalid_token', 'Access token is invalid.', ['status' => 401]);
        }

        if (strtotime($row['access_expires_at']) < time()) {
            return new WP_Error('expired_token', 'Access token has expired.', ['status' => 401]);
        }

        return [
            'app_id' => $row['app_id'],
            'scopes' => json_decode($row['scopes'], true),
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scopes: array}|WP_Error
     */
    public function refreshToken(string $appId, string $refreshToken): array|WP_Error
    {
        global $wpdb;

        $hash = hash('sha256', $refreshToken);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}apps_tokens
                 WHERE app_id = %s AND refresh_token_hash = %s AND refresh_expires_at > %s",
                $appId,
                $hash,
                gmdate('Y-m-d H:i:s')
            ),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('invalid_refresh_token', 'Refresh token is invalid or expired.', ['status' => 401]);
        }

        // Delete old token pair (rotation)
        $wpdb->delete(
            "{$wpdb->prefix}apps_tokens",
            ['id' => $row['id']],
            ['%d']
        );

        $scopes = json_decode($row['scopes'], true);

        return $this->createTokenPair($appId, $scopes);
    }

    /**
     * Revoke all tokens for an app.
     */
    public function revokeAll(string $appId): void
    {
        global $wpdb;

        $wpdb->delete(
            "{$wpdb->prefix}apps_tokens",
            ['app_id' => $appId],
            ['%s']
        );
    }

    private function encrypt(string $value): string
    {
        $key = wp_salt('auth');
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    private function decrypt(string $value): string
    {
        $key = wp_salt('auth');
        $parts = explode('::', base64_decode($value), 2);
        if (count($parts) !== 2) {
            return '';
        }
        return openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]) ?: '';
    }
}
