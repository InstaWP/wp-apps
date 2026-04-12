<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Admin;

use WPApps\Runtime\Core\Manifest\Parser;
use WPApps\Runtime\Core\Manifest\Validator;
use WPApps\Runtime\Core\Auth\TokenManager;
use WPApps\Runtime\Core\Auth\SignatureVerifier;

class AdminPage
{
    private Parser $parser;
    private TokenManager $tokenManager;
    private SignatureVerifier $signatureVerifier;

    public function __construct(Parser $parser, TokenManager $tokenManager, SignatureVerifier $signatureVerifier)
    {
        $this->parser = $parser;
        $this->tokenManager = $tokenManager;
        $this->signatureVerifier = $signatureVerifier;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_init', [$this, 'handleActions']);
        add_action('admin_init', [$this, 'handleInstallSubmission']);
    }

    public function addMenuPages(): void
    {
        add_menu_page(
            'WP Apps',
            'Apps',
            'manage_options',
            'wp-apps',
            [$this, 'renderListPage'],
            'dashicons-grid-view',
            65
        );

        add_submenu_page(
            'wp-apps',
            'All Apps',
            'All Apps',
            'manage_options',
            'wp-apps',
            [$this, 'renderListPage']
        );

        add_submenu_page(
            'wp-apps',
            'Install App',
            'Install New',
            'manage_options',
            'wp-apps-install',
            [$this, 'renderInstallPage']
        );
    }

    public function handleActions(): void
    {
        if (!isset($_GET['wp-apps-action']) || !current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key($_GET['wp-apps-action']);
        $appId = sanitize_text_field($_GET['app-id'] ?? '');
        $nonce = $_GET['_wpnonce'] ?? '';

        if (!wp_verify_nonce($nonce, "wp-apps-{$action}-{$appId}")) {
            wp_die('Invalid nonce.');
        }

        switch ($action) {
            case 'activate':
                $this->parser->updateStatus($appId, 'active');
                $redirect = admin_url('admin.php?page=wp-apps&activated=' . urlencode($appId));
                break;

            case 'deactivate':
                $this->parser->updateStatus($appId, 'inactive');
                $redirect = admin_url('admin.php?page=wp-apps&deactivated=' . urlencode($appId));
                break;

            case 'uninstall':
                $this->tokenManager->revokeAll($appId);
                global $wpdb;
                $wpdb->delete("{$wpdb->prefix}apps_installed", ['app_id' => $appId]);
                $redirect = admin_url('admin.php?page=wp-apps&uninstalled=' . urlencode($appId));
                break;

            default:
                return;
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function renderListPage(): void
    {
        global $wpdb;

        $apps = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}apps_installed ORDER BY installed_at DESC",
            ARRAY_A
        ) ?: [];

        // Notices
        if (isset($_GET['activated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>App activated.</p></div>';
        }
        if (isset($_GET['deactivated'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>App deactivated.</p></div>';
        }
        if (isset($_GET['uninstalled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>App uninstalled.</p></div>';
        }
        if (isset($_GET['installed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>App installed and activated!</p></div>';
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Apps</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-apps-install')); ?>" class="page-title-action">Install New</a>
            <hr class="wp-header-end">

            <?php if (empty($apps)): ?>
                <div style="text-align:center;padding:4rem 2rem;">
                    <span class="dashicons dashicons-grid-view" style="font-size:48px;width:48px;height:48px;color:#ddd;"></span>
                    <h2 style="margin-top:1rem;color:#666;">No apps installed</h2>
                    <p style="color:#999;">Apps are sandboxed extensions that run as external services.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-apps-install')); ?>" class="button button-primary" style="margin-top:1rem;">Install Your First App</a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:25%;">App</th>
                            <th style="width:15%;">Version</th>
                            <th style="width:20%;">Status</th>
                            <th style="width:20%;">Endpoint</th>
                            <th style="width:20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apps as $app): ?>
                            <?php
                            $manifest = json_decode($app['manifest_json'], true);
                            $scopes = json_decode($app['scopes_granted'], true);
                            $statusColor = match ($app['status']) {
                                'active' => '#00a32a',
                                'inactive' => '#996800',
                                default => '#787c82',
                            };
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($app['name']); ?></strong>
                                    <br><small style="color:#999;"><?php echo esc_html($app['app_id']); ?></small>
                                    <?php if ($manifest['app']['description'] ?? ''): ?>
                                        <br><small><?php echo esc_html($manifest['app']['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($app['version']); ?></td>
                                <td>
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $statusColor; ?>;margin-right:6px;vertical-align:middle;"></span>
                                    <?php echo esc_html(ucfirst($app['status'])); ?>
                                    <?php if ((int)$app['health_failures'] > 0): ?>
                                        <br><small style="color:#d63638;">Health failures: <?php echo (int)$app['health_failures']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size:11px;word-break:break-all;"><?php echo esc_html($app['endpoint']); ?></code>
                                </td>
                                <td>
                                    <?php if ($app['status'] === 'active'): ?>
                                        <a href="<?php echo wp_nonce_url(
                                            admin_url("admin.php?page=wp-apps&wp-apps-action=deactivate&app-id=" . urlencode($app['app_id'])),
                                            'wp-apps-deactivate-' . $app['app_id']
                                        ); ?>" class="button button-small">Deactivate</a>
                                    <?php else: ?>
                                        <a href="<?php echo wp_nonce_url(
                                            admin_url("admin.php?page=wp-apps&wp-apps-action=activate&app-id=" . urlencode($app['app_id'])),
                                            'wp-apps-activate-' . $app['app_id']
                                        ); ?>" class="button button-small button-primary">Activate</a>
                                    <?php endif; ?>
                                    <a href="<?php echo wp_nonce_url(
                                        admin_url("admin.php?page=wp-apps&wp-apps-action=uninstall&app-id=" . urlencode($app['app_id'])),
                                        'wp-apps-uninstall-' . $app['app_id']
                                    ); ?>" class="button button-small" style="color:#b32d2e;" onclick="return confirm('Uninstall this app? All app data will be removed.');">Uninstall</a>

                                    <div style="margin-top:8px;">
                                        <small style="color:#999;">Scopes:</small><br>
                                        <?php foreach ($scopes as $scope): ?>
                                            <code style="font-size:10px;background:#f0f0f1;padding:1px 5px;margin:1px 0;display:inline-block;"><?php echo esc_html($scope); ?></code>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top:2rem;padding:1rem;background:#f0f6fc;border-left:4px solid #72aee6;">
                <strong>WP Apps Runtime</strong> v<?php echo WP_APPS_VERSION; ?><br>
                <small style="color:#666;">Apps are sandboxed external services that interact with WordPress through a structured API protocol. <a href="https://wp-apps.org" target="_blank">Learn more &rarr;</a></small>
            </div>
        </div>
        <?php
    }

    /**
     * Handle install form submission early in admin_init (before output starts).
     */
    public function handleInstallSubmission(): void
    {
        if (!isset($_POST['wp-apps-confirm-install']) || !current_user_can('manage_options')) {
            return;
        }

        if (!check_admin_referer('wp-apps-install')) {
            return;
        }

        $manifestUrl = esc_url_raw($_POST['manifest_url'] ?? '');
        $manifest = $this->parser->fetchAndParse($manifestUrl);

        if (is_wp_error($manifest)) {
            // Store error in transient so renderInstallPage can display it
            set_transient('wp_apps_install_error', $manifest->get_error_message(), 60);
            wp_safe_redirect(admin_url('admin.php?page=wp-apps-install&error=1'));
            exit;
        }

        $result = $this->parser->install($manifest, $manifestUrl);
        if (is_wp_error($result)) {
            set_transient('wp_apps_install_error', $result->get_error_message(), 60);
            wp_safe_redirect(admin_url('admin.php?page=wp-apps-install&error=1'));
            exit;
        }

        // Generate auth code and send to app
        $scopes = $manifest['permissions']['scopes'];
        $authCode = $this->tokenManager->createAuthCode($result['app_id'], $scopes);

        $callbackUrl = rtrim($manifest['runtime']['endpoint'], '/') . $manifest['runtime']['auth_callback'];
        $siteId = get_option('wp_apps_site_id', bin2hex(random_bytes(4)));

        $callbackBody = wp_json_encode([
            'site_url' => home_url(),
            'site_id' => $siteId,
            'auth_code' => $authCode,
            'scopes_granted' => $scopes,
        ]);

        $headers = $this->signatureVerifier->buildHeaders($callbackBody, $result['shared_secret'], $siteId);
        $headers['Content-Type'] = 'application/json';

        wp_remote_post($callbackUrl, [
            'body' => $callbackBody,
            'headers' => $headers,
            'timeout' => 15,
        ]);

        $this->parser->updateStatus($result['app_id'], 'active');

        wp_safe_redirect(admin_url('admin.php?page=wp-apps&installed=1'));
        exit;
    }

    public function renderInstallPage(): void
    {
        $error = '';
        $manifest = null;
        $manifestUrl = '';

        // Check for stored error from redirect
        $storedError = get_transient('wp_apps_install_error');
        if ($storedError) {
            $error = $storedError;
            delete_transient('wp_apps_install_error');
        }

        // Handle manifest fetch (step 1 - stays in render since it needs to show results)
        if (isset($_POST['wp-apps-fetch-manifest']) && check_admin_referer('wp-apps-install')) {
            $manifestUrl = esc_url_raw($_POST['manifest_url'] ?? '');
            $manifest = $this->parser->fetchAndParse($manifestUrl);
            if (is_wp_error($manifest)) {
                $error = $manifest->get_error_message();
                $manifest = null;
            }
        }

        ?>
        <div class="wrap">
            <h1>Install App</h1>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!$manifest): ?>
                <!-- Step 1: Enter manifest URL -->
                <div class="card" style="max-width:600px;margin-top:1rem;">
                    <h2 style="margin-top:0;">Install from Manifest URL</h2>
                    <p>Enter the URL of the app's <code>wp-app.json</code> manifest file.</p>
                    <form method="post">
                        <?php wp_nonce_field('wp-apps-install'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="manifest_url">Manifest URL</label></th>
                                <td>
                                    <input type="url" name="manifest_url" id="manifest_url"
                                           class="regular-text" required
                                           placeholder="https://my-app.example.com/wp-app.json"
                                           value="<?php echo esc_attr($manifestUrl); ?>">
                                    <p class="description">The app server must be running and accessible.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="wp-apps-fetch-manifest" class="button button-primary">Fetch &amp; Review</button>
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <!-- Step 2: Review permissions and confirm -->
                <div class="card" style="max-width:700px;margin-top:1rem;">
                    <h2 style="margin-top:0;"><?php echo esc_html($manifest['app']['name']); ?> <small>v<?php echo esc_html($manifest['app']['version']); ?></small></h2>
                    <p><?php echo esc_html($manifest['app']['description']); ?></p>
                    <p><small>By <?php echo esc_html($manifest['app']['author']['name'] ?? 'Unknown'); ?> &middot; <?php echo esc_html($manifest['app']['license']); ?></small></p>

                    <hr>

                    <h3>Requested Permissions</h3>
                    <p>This app is requesting access to the following:</p>
                    <table class="widefat" style="margin:1rem 0;">
                        <tbody>
                            <?php foreach ($manifest['permissions']['scopes'] as $scope): ?>
                                <tr>
                                    <td style="width:40%;"><code><?php echo esc_html($scope); ?></code></td>
                                    <td style="color:#666;"><?php echo esc_html($this->describeScopeHuman($scope)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($manifest['hooks'])): ?>
                        <h3>Hook Subscriptions</h3>
                        <?php foreach (['filters', 'actions'] as $type): ?>
                            <?php if (!empty($manifest['hooks'][$type])): ?>
                                <?php foreach ($manifest['hooks'][$type] as $hook): ?>
                                    <p>
                                        <span class="dashicons dashicons-<?php echo $type === 'filters' ? 'filter' : 'update'; ?>" style="color:#666;"></span>
                                        <code><?php echo esc_html($hook['hook']); ?></code>
                                        <?php if ($hook['description'] ?? ''): ?>
                                            — <small><?php echo esc_html($hook['description']); ?></small>
                                        <?php endif; ?>
                                    </p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <hr>

                    <p><strong>Endpoint:</strong> <code><?php echo esc_html($manifest['runtime']['endpoint']); ?></code></p>

                    <form method="post">
                        <?php wp_nonce_field('wp-apps-install'); ?>
                        <input type="hidden" name="manifest_url" value="<?php echo esc_attr($manifestUrl); ?>">
                        <p class="submit" style="display:flex;gap:8px;">
                            <button type="submit" name="wp-apps-confirm-install" class="button button-primary">Approve &amp; Install</button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-apps-install')); ?>" class="button">Cancel</a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function describeScopeHuman(string $scope): string
    {
        $descriptions = [
            'posts:read' => 'Read all posts',
            'posts:read:published' => 'Read published posts only',
            'posts:write' => 'Create and update posts',
            'posts:delete' => 'Delete posts',
            'postmeta:read' => 'Read post metadata',
            'postmeta:write' => 'Write post metadata',
            'users:read:basic' => 'Read user display names and roles',
            'users:read:full' => 'Read full user profiles',
            'users:write' => 'Modify user profiles',
            'media:read' => 'Read media library',
            'media:write' => 'Upload and modify media',
            'comments:read' => 'Read comments',
            'comments:write' => 'Create and moderate comments',
            'site:read' => 'Read site settings',
            'site:write' => 'Modify site settings',
            'email:send' => 'Send emails',
            'cron:register' => 'Register scheduled jobs',
            'blocks:register' => 'Register custom blocks',
            'rest:extend' => 'Register custom API endpoints',
        ];

        if (isset($descriptions[$scope])) {
            return $descriptions[$scope];
        }

        if (preg_match('/^options:(read|write):(.+)$/', $scope, $m)) {
            return ucfirst($m[1]) . " options matching '{$m[2]}'";
        }

        return $scope;
    }
}
