<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Auth;

class SignatureVerifier
{
    /**
     * Generate HMAC-SHA256 signature for an outbound request body.
     */
    public function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Verify an inbound signature from an app.
     */
    public function verify(string $body, string $signature, string $secret): bool
    {
        $expected = $this->sign($body, $secret);
        return hash_equals("sha256={$expected}", $signature);
    }

    /**
     * Build the signature headers for an outbound request.
     *
     * @return array<string, string>
     */
    public function buildHeaders(
        string $body,
        string $secret,
        string $siteId,
        string $hook = '',
        string $deliveryId = ''
    ): array {
        $timestamp = time();
        $signature = $this->sign($body, $secret);

        $headers = [
            'X-WP-Apps-Signature' => "sha256={$signature}",
            'X-WP-Apps-Site-Id' => $siteId,
            'X-WP-Apps-Timestamp' => (string) $timestamp,
        ];

        if ($hook !== '') {
            $headers['X-WP-Apps-Hook'] = $hook;
        }

        if ($deliveryId !== '') {
            $headers['X-WP-Apps-Delivery-Id'] = $deliveryId;
        }

        return $headers;
    }

    /**
     * Check if a timestamp is within the allowed window (5 minutes).
     */
    public function isTimestampValid(int $timestamp, int $windowSeconds = 300): bool
    {
        return abs(time() - $timestamp) <= $windowSeconds;
    }
}
