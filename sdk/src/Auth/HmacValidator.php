<?php

declare(strict_types=1);

namespace WPApps\SDK\Auth;

class HmacValidator
{
    public function __construct(
        private readonly string $sharedSecret
    ) {}

    /**
     * Validate an incoming webhook request.
     *
     * @param string $body     Raw request body
     * @param string $signature Value of X-WP-Apps-Signature header
     * @param int    $timestamp Value of X-WP-Apps-Timestamp header
     * @return bool
     */
    public function validate(string $body, string $signature, int $timestamp): bool
    {
        // Reject requests older than 5 minutes
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->sharedSecret);

        return hash_equals($expected, $signature);
    }
}
