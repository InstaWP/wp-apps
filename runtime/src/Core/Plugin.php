<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core;

use WPApps\Runtime\Core\Auth\OAuthController;
use WPApps\Runtime\Core\Auth\TokenManager;
use WPApps\Runtime\Core\Auth\SignatureVerifier;
use WPApps\Runtime\Core\Gateway\Router;
use WPApps\Runtime\Core\Gateway\PermissionEnforcer;
use WPApps\Runtime\Core\Hooks\EventDispatcher;
use WPApps\Runtime\Core\Hooks\RuntimeHookDispatcher;
use WPApps\Runtime\Core\Hooks\Registry;
use WPApps\Runtime\Core\Manifest\Parser;
use WPApps\Runtime\Core\Manifest\Validator;
use WPApps\Runtime\Core\Admin\AdminPage;
use WPApps\Runtime\Core\UI\BlockManager;
use WPApps\Runtime\Core\UI\MetaRenderer;

final class Plugin
{
    private static ?self $instance = null;

    private Parser $manifestParser;
    private Validator $manifestValidator;
    private TokenManager $tokenManager;
    private SignatureVerifier $signatureVerifier;
    private OAuthController $oauthController;
    private Router $router;
    private PermissionEnforcer $permissionEnforcer;
    private Registry $hookRegistry;
    private EventDispatcher $eventDispatcher;
    private RuntimeHookDispatcher $runtimeHookDispatcher;
    private AdminPage $adminPage;
    private BlockManager $blockManager;
    private MetaRenderer $metaRenderer;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->boot();
    }

    private function boot(): void
    {
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function init(): void
    {
        // Auto-create tables if they don't exist
        $this->maybeCreateTables();

        // Core services
        $this->manifestValidator = new Validator();
        $this->manifestParser = new Parser($this->manifestValidator);
        $this->tokenManager = new TokenManager();
        $this->signatureVerifier = new SignatureVerifier();
        $this->permissionEnforcer = new PermissionEnforcer();
        $this->oauthController = new OAuthController($this->tokenManager, $this->signatureVerifier);
        $this->hookRegistry = new Registry();

        // Tier 1: Event webhooks (async, zero page-load cost)
        $this->eventDispatcher = new EventDispatcher($this->hookRegistry, $this->signatureVerifier);

        // Tier 2: Runtime hooks (escape hatch, adds latency)
        $this->runtimeHookDispatcher = new RuntimeHookDispatcher($this->hookRegistry, $this->signatureVerifier);

        // Block registration and cached rendering
        $this->blockManager = new BlockManager($this->signatureVerifier);

        // Post meta → wp_head rendering
        $this->metaRenderer = new MetaRenderer();
        $this->metaRenderer->register();

        // API gateway
        $this->router = new Router($this->tokenManager, $this->permissionEnforcer);

        // Admin UI
        $this->adminPage = new AdminPage($this->manifestParser, $this->tokenManager, $this->signatureVerifier);
        $this->adminPage->register();

        // Register hooks and blocks for active apps
        $apps = $this->manifestParser->getActiveApps();
        $this->eventDispatcher->registerForApps($apps);
        $this->runtimeHookDispatcher->registerForApps($apps);
        $this->blockManager->registerForApps($apps);

        // Invalidate block caches when posts are saved
        add_action('save_post', [$this->blockManager, 'invalidateForPost']);
    }

    public function registerRoutes(): void
    {
        $this->oauthController->registerRoutes();
        $this->router->registerRoutes();

        // Cache purge endpoint
        register_rest_route('apps/v1', '/cache/purge', [
            'methods' => 'POST',
            'callback' => [$this, 'handleCachePurge'],
            'permission_callback' => [$this->router, 'authenticateRequest'],
        ]);
    }

    public function handleCachePurge(\WP_REST_Request $request): \WP_REST_Response
    {
        $appId = $request->get_param('_app_id');
        $data = $request->get_json_params();
        $scope = $data['scope'] ?? 'all';

        if ($scope === 'block') {
            $this->blockManager->invalidate(
                $appId,
                $data['block_name'] ?? '',
                (int) ($data['post_id'] ?? 0)
            );
        } else {
            $this->blockManager->invalidate($appId);
        }

        return new \WP_REST_Response(['status' => 'purged', 'scope' => $scope], 200);
    }

    public function getManifestParser(): Parser
    {
        return $this->manifestParser;
    }

    public function getTokenManager(): TokenManager
    {
        return $this->tokenManager;
    }

    private function maybeCreateTables(): void
    {
        $installed = get_option('wp_apps_db_version', '');
        if ($installed === WP_APPS_VERSION) {
            return;
        }

        $this->createTables();
        update_option('wp_apps_db_version', WP_APPS_VERSION);
    }

    private function createTables(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}apps_installed (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            version VARCHAR(50) NOT NULL,
            manifest_url TEXT NOT NULL,
            manifest_json LONGTEXT NOT NULL,
            endpoint VARCHAR(2048) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'installed',
            shared_secret VARCHAR(255) NOT NULL,
            scopes_granted TEXT NOT NULL,
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            health_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY app_id (app_id)
        ) {$charset};

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}apps_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id VARCHAR(255) NOT NULL,
            access_token_hash VARCHAR(64) NOT NULL,
            refresh_token_hash VARCHAR(64) NOT NULL,
            refresh_token_encrypted TEXT NOT NULL,
            scopes TEXT NOT NULL,
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY access_token_hash (access_token_hash),
            KEY app_id (app_id)
        ) {$charset};

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}apps_auth_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id VARCHAR(255) NOT NULL,
            code VARCHAR(64) NOT NULL,
            scopes TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY app_id (app_id)
        ) {$charset};

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}apps_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            app_id VARCHAR(255) NOT NULL,
            action_type VARCHAR(30) NOT NULL,
            method VARCHAR(10) DEFAULT NULL,
            endpoint VARCHAR(2048) DEFAULT NULL,
            hook VARCHAR(255) DEFAULT NULL,
            delivery_id VARCHAR(36) DEFAULT NULL,
            status_code SMALLINT UNSIGNED DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY app_id (app_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
