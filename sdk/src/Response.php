<?php

declare(strict_types=1);

namespace WPApps\SDK;

class Response
{
    private function __construct(
        public readonly int $statusCode,
        public readonly array $body
    ) {}

    /**
     * Response for a filter hook — returns the modified value.
     */
    public static function filter(mixed $value, string $deliveryId = ''): self
    {
        return new self(200, [
            'delivery_id' => $deliveryId,
            'status' => 'ok',
            'result' => $value,
        ]);
    }

    /**
     * Response for an action hook — simple acknowledgment.
     */
    public static function ok(string $deliveryId = ''): self
    {
        return new self(200, [
            'delivery_id' => $deliveryId,
            'status' => 'ok',
        ]);
    }

    /**
     * Response for a surface render — returns UI components.
     */
    public static function ui(array $components): self
    {
        return new self(200, [
            'status' => 'ok',
            'components' => $components,
        ]);
    }

    /**
     * Response for a block render — returns cached HTML.
     */
    public static function block(string $html): self
    {
        return new self(200, [
            'status' => 'ok',
            'html' => $html,
        ]);
    }

    /**
     * Generic JSON response.
     */
    public static function json(array $data, int $statusCode = 200): self
    {
        return new self($statusCode, $data);
    }

    /**
     * Error response.
     */
    public static function error(string $code, string $message, string $deliveryId = ''): self
    {
        return new self(500, [
            'delivery_id' => $deliveryId,
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}
