<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Hooks;

class Registry
{
    /**
     * Tier 1: Event webhooks (async, zero page-load cost).
     * These are the PREFERRED integration model.
     */
    private const EVENTS = [
        'save_post', 'delete_post', 'transition_post_status',
        'add_attachment', 'edit_attachment', 'delete_attachment',
        'user_register', 'profile_update', 'delete_user',
        'wp_login', 'wp_logout',
        'wp_insert_comment', 'edit_comment', 'delete_comment', 'transition_comment_status',
        'created_term', 'edited_term', 'delete_term',
    ];

    /**
     * Tier 2: Runtime filters (adds latency — use sparingly).
     * the_content, the_title, the_excerpt are DISCOURAGED — use blocks instead.
     */
    private const FILTERS = [
        'wp_head' => ['timeout_max' => 2000],
        'wp_footer' => ['timeout_max' => 2000],
        'document_title_parts' => ['timeout_max' => 2000],
        // Discouraged — prefer blocks and post meta
        'the_content' => ['timeout_max' => 2000, 'discouraged' => true],
        'the_title' => ['timeout_max' => 2000, 'discouraged' => true],
        'the_excerpt' => ['timeout_max' => 2000, 'discouraged' => true],
        'body_class' => ['timeout_max' => 2000, 'discouraged' => true],
        'post_class' => ['timeout_max' => 2000, 'discouraged' => true],
        // Admin-only (no frontend cost)
        'admin_notices' => ['timeout_max' => 5000],
        'dashboard_glance_items' => ['timeout_max' => 5000],
        'rest_pre_dispatch' => ['timeout_max' => 5000],
        'rest_post_dispatch' => ['timeout_max' => 5000],
    ];

    /**
     * Tier 2: Sync actions (rare — most actions should be async events).
     */
    private const SYNC_ACTIONS = [
        'save_post' => ['timeout_max' => 10000],
        'transition_post_status' => ['timeout_max' => 10000],
    ];

    public function isAllowed(string $hook, string $type): bool
    {
        return match ($type) {
            'events' => in_array($hook, self::EVENTS, true),
            'filters' => isset(self::FILTERS[$hook]),
            'actions' => isset(self::SYNC_ACTIONS[$hook]),
            default => false,
        };
    }

    public function getHookConfig(string $hook, string $type): ?array
    {
        return match ($type) {
            'filters' => self::FILTERS[$hook] ?? null,
            'actions' => self::SYNC_ACTIONS[$hook] ?? null,
            default => null,
        };
    }

    public function getEffectiveTimeout(string $hook, string $type, int $declaredTimeout): int
    {
        $config = $this->getHookConfig($hook, $type);
        $max = $config['timeout_max'] ?? 5000;
        return min($declaredTimeout, $max);
    }

    public function isDiscouraged(string $hook): bool
    {
        return self::FILTERS[$hook]['discouraged'] ?? false;
    }

    /**
     * @return array<string>
     */
    public function getEvents(): array
    {
        return self::EVENTS;
    }
}
