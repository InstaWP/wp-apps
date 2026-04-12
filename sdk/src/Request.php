<?php

declare(strict_types=1);

namespace WPApps\SDK;

class Request
{
    public readonly string $deliveryId;
    public readonly string $hook;
    public readonly string $type;
    public readonly array $args;
    public readonly array $context;
    public readonly array $site;
    public readonly ApiClient $api;

    // Surface fields
    public readonly ?string $surface;
    public readonly ?string $surfaceId;
    public readonly ?string $action;
    public readonly ?array $interaction;

    public function __construct(array $data, ApiClient $api)
    {
        $this->deliveryId = $data['delivery_id'] ?? '';
        $this->hook = $data['hook'] ?? '';
        $this->type = $data['type'] ?? '';
        $this->args = $data['args'] ?? [];
        $this->context = $data['context'] ?? [];
        $this->site = $data['site'] ?? [];
        $this->api = $api;

        // Surface request fields
        $this->surface = $data['surface'] ?? null;
        $this->surfaceId = $data['surface_id'] ?? null;
        $this->action = $data['action'] ?? null;
        $this->interaction = $data['interaction'] ?? null;
    }

    /**
     * Check if this is a hook request.
     */
    public function isHook(): bool
    {
        return $this->hook !== '';
    }

    /**
     * Check if this is a surface render/interaction request.
     */
    public function isSurface(): bool
    {
        return $this->surface !== null;
    }
}
