<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\UI;

use WPApps\Runtime\Core\Auth\SignatureVerifier;

/**
 * Manages block registration and cached rendering for apps.
 *
 * Blocks are the PREFERRED way for apps to render frontend UI.
 * The app is called once (at save/edit or on first render), the output
 * is cached, and subsequent page loads serve cached HTML with zero app calls.
 */
class BlockManager
{
    private const CACHE_GROUP = 'wp_apps_blocks';

    public function __construct(
        private readonly SignatureVerifier $signatureVerifier
    ) {}

    /** @var array<array{name: string, title: string, description: string, category: string, icon: string}> */
    private array $editorBlocks = [];

    /**
     * Register blocks declared by active apps.
     */
    public function registerForApps(array $apps): void
    {
        foreach ($apps as $app) {
            $manifest = $app['manifest'];

            foreach ($manifest['surfaces']['blocks'] ?? [] as $blockDef) {
                $this->registerBlock($app, $blockDef);
            }
        }

        // Enqueue editor JS to make blocks appear in the inserter
        if (!empty($this->editorBlocks)) {
            add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorScript']);
        }
    }

    private function registerBlock(array $app, array $blockDef): void
    {
        $blockName = $blockDef['name'];
        $cacheTtl = $blockDef['cache_ttl'] ?? 3600;

        // Register as Gutenberg block
        register_block_type($blockName, [
            'api_version' => 3,
            'title' => $blockDef['title'] ?? $blockName,
            'description' => $blockDef['description'] ?? '',
            'category' => $blockDef['category'] ?? 'widgets',
            'icon' => $blockDef['icon'] ?? 'grid-view',
            'attributes' => [
                'appId' => ['type' => 'string', 'default' => $app['app_id']],
                'blockConfig' => ['type' => 'object', 'default' => []],
            ],
            'render_callback' => function (array $attributes, string $content) use ($app, $blockName, $cacheTtl): string {
                return $this->renderBlock($app, $blockName, $attributes, $cacheTtl);
            },
        ]);

        // Track for editor JS registration
        $this->editorBlocks[] = [
            'name' => $blockName,
            'title' => $blockDef['title'] ?? $blockName,
            'description' => $blockDef['description'] ?? '',
            'category' => $blockDef['category'] ?? 'widgets',
            'icon' => $blockDef['icon'] ?? 'grid-view',
        ];

        // Register as shortcode for Elementor, Divi, Classic Editor, etc.
        // Block name "wpapps/contact-form" → shortcode [wpapps-contact-form]
        $shortcode = str_replace('/', '-', $blockName);
        add_shortcode($shortcode, function (array $atts) use ($app, $blockName, $cacheTtl): string {
            $attributes = shortcode_atts([
                'appId' => $app['app_id'],
                'blockConfig' => [],
            ], $atts);
            return $this->renderBlock($app, $blockName, $attributes, $cacheTtl);
        });
    }

    /**
     * Enqueue inline JS in the block editor to register app blocks in the inserter.
     */
    public function enqueueEditorScript(): void
    {
        $handle = 'wp-apps-blocks-editor';

        wp_register_script($handle, '', ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render'], WP_APPS_VERSION, true);
        wp_enqueue_script($handle);

        $js = "( function() {\n";
        $js .= "    var el = wp.element.createElement;\n";
        $js .= "    var registerBlockType = wp.blocks.registerBlockType;\n";
        $js .= "    var useBlockProps = wp.blockEditor.useBlockProps;\n";
        $js .= "    var ServerSideRender = wp.serverSideRender;\n\n";

        foreach ($this->editorBlocks as $block) {
            $name = esc_js($block['name']);
            $title = esc_js($block['title']);
            $desc = esc_js($block['description']);
            $category = esc_js($block['category']);
            $icon = esc_js($block['icon']);

            $js .= "    registerBlockType( '{$name}', {\n";
            $js .= "        title: '{$title}',\n";
            $js .= "        description: '{$desc}',\n";
            $js .= "        category: '{$category}',\n";
            $js .= "        icon: '{$icon}',\n";
            $js .= "        edit: function( props ) {\n";
            $js .= "            var blockProps = useBlockProps();\n";
            $js .= "            return el( 'div', blockProps,\n";
            $js .= "                el( ServerSideRender, { block: '{$name}', attributes: props.attributes } )\n";
            $js .= "            );\n";
            $js .= "        },\n";
            $js .= "        save: function() { return null; }\n";
            $js .= "    } );\n\n";
        }

        $js .= "} )();\n";

        wp_add_inline_script($handle, $js);
    }

    /**
     * Render a block, using cache when available.
     */
    private function renderBlock(array $app, string $blockName, array $attributes, int $cacheTtl): string
    {
        global $post;

        $postId = $post->ID ?? 0;

        // Build cache key
        $cacheKey = $this->buildCacheKey($app['app_id'], $blockName, $postId, $attributes);

        // Check cache
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // Call app to render
        $html = $this->fetchFromApp($app, $blockName, $attributes, $postId);

        // Cache the result
        if ($html !== '' && $cacheTtl > 0) {
            set_transient($cacheKey, $html, $cacheTtl);
        }

        return $html;
    }

    /**
     * Call the app's block render endpoint.
     */
    private function fetchFromApp(array $app, string $blockName, array $attributes, int $postId): string
    {
        $payload = [
            'surface' => 'block',
            'block_name' => $blockName,
            'action' => 'render',
            'attributes' => $attributes['blockConfig'] ?? [],
            'context' => [
                'post_id' => $postId,
                'is_editor' => defined('REST_REQUEST') && REST_REQUEST,
            ],
        ];

        $body = wp_json_encode($payload);
        $webhookUrl = rtrim($app['endpoint'], '/') . '/surfaces/blocks';
        $siteId = get_option('wp_apps_site_id', '');

        $headers = $this->signatureVerifier->buildHeaders($body, $app['shared_secret'], $siteId);
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post($webhookUrl, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 5,
            'sslverify' => !$this->isLocalEndpoint($app['endpoint']),
        ]);

        if (is_wp_error($response)) {
            return '<!-- WP Apps: block render failed (' . esc_html($app['app_id']) . ') -->';
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200 || !isset($responseBody['html'])) {
            return '<!-- WP Apps: block render error (' . esc_html($app['app_id']) . ') -->';
        }

        return $responseBody['html'];
    }

    /**
     * Invalidate block cache for a specific post or all posts.
     */
    public function invalidate(string $appId, string $blockName = '', int $postId = 0): void
    {
        // For targeted invalidation, we delete the specific transient
        if ($blockName && $postId) {
            $cacheKey = $this->buildCacheKey($appId, $blockName, $postId, []);
            delete_transient($cacheKey);
            return;
        }

        // For broader invalidation, we use a version bump approach
        $versionKey = "wp_apps_block_version_{$appId}";
        $version = (int) get_option($versionKey, 0);
        update_option($versionKey, $version + 1, false);
    }

    /**
     * Invalidate all block caches for a post (called on save_post).
     */
    public function invalidateForPost(int $postId): void
    {
        // Parse the post content for app blocks and invalidate each
        $post = get_post($postId);
        if (!$post) {
            return;
        }

        $blocks = parse_blocks($post->post_content);
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $appId = $block['attrs']['appId'] ?? '';
            if ($appId) {
                $cacheKey = $this->buildCacheKey($appId, $block['blockName'], $postId, $block['attrs'] ?? []);
                delete_transient($cacheKey);
            }
        }
    }

    private function buildCacheKey(string $appId, string $blockName, int $postId, array $attributes): string
    {
        $version = (int) get_option("wp_apps_block_version_{$appId}", 0);
        $attrHash = md5(wp_json_encode($attributes));
        return "wpapps_blk_{$appId}_{$blockName}_{$postId}_{$attrHash}_v{$version}";
    }

    private function isLocalEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
