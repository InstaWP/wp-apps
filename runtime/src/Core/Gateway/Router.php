<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\Gateway;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPApps\Runtime\Core\Auth\TokenManager;

class Router
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly PermissionEnforcer $permissionEnforcer
    ) {}

    public function registerRoutes(): void
    {
        // Posts
        register_rest_route('apps/v1', '/posts', [
            'methods' => 'GET',
            'callback' => [$this, 'listPosts'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        register_rest_route('apps/v1', '/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPost'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        register_rest_route('apps/v1', '/posts', [
            'methods' => 'POST',
            'callback' => [$this, 'createPost'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        register_rest_route('apps/v1', '/posts/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'updatePost'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        register_rest_route('apps/v1', '/posts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'deletePost'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        // Post meta
        register_rest_route('apps/v1', '/posts/(?P<id>\d+)/meta', [
            'methods' => 'GET',
            'callback' => [$this, 'getPostMeta'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);

        register_rest_route('apps/v1', '/posts/(?P<id>\d+)/meta/(?P<key>.+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'setPostMeta'],
            'permission_callback' => [$this, 'authenticateRequest'],
        ]);
    }

    /**
     * Authenticate request via Bearer token and check scope.
     */
    public function authenticateRequest(WP_REST_Request $request): true|WP_Error
    {
        $authHeader = $request->get_header('authorization');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return new WP_Error('missing_token', 'Authorization header with Bearer token is required.', ['status' => 401]);
        }

        $token = substr($authHeader, 7);
        $result = $this->tokenManager->validateAccessToken($token);

        if (is_wp_error($result)) {
            return $result;
        }

        // Determine required scope for this route
        $method = $request->get_method();
        $route = $request->get_route();
        $requiredScope = $this->permissionEnforcer->getRequiredScope($method, $route);

        if ($requiredScope && !$this->permissionEnforcer->hasScope($result['scopes'], $requiredScope)) {
            return new WP_Error(
                'insufficient_scope',
                "This action requires the '{$requiredScope}' permission.",
                [
                    'status' => 403,
                    'required_scope' => $requiredScope,
                    'app_id' => $result['app_id'],
                ]
            );
        }

        // Store app context on request for handlers
        $request->set_param('_app_id', $result['app_id']);
        $request->set_param('_app_scopes', $result['scopes']);

        return true;
    }

    public function listPosts(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'post_type' => $request->get_param('post_type') ?? 'post',
            'post_status' => $request->get_param('status') ?? 'publish',
            'posts_per_page' => min((int) ($request->get_param('per_page') ?? 10), 100),
            'paged' => max((int) ($request->get_param('page') ?? 1), 1),
            'orderby' => $request->get_param('orderby') ?? 'date',
            'order' => $request->get_param('order') ?? 'desc',
        ];

        // Enforce published-only for apps with posts:read:published scope
        $scopes = $request->get_param('_app_scopes');
        if (in_array('posts:read:published', $scopes, true) && !in_array('posts:read', $scopes, true)) {
            $args['post_status'] = 'publish';
        }

        $query = new \WP_Query($args);
        $posts = array_map([$this, 'formatPost'], $query->posts);

        $response = new WP_REST_Response($posts, 200);
        $response->header('X-WP-Total', (string) $query->found_posts);
        $response->header('X-WP-TotalPages', (string) $query->max_num_pages);

        return $response;
    }

    public function getPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found.', ['status' => 404]);
        }

        return new WP_REST_Response($this->formatPost($post), 200);
    }

    public function createPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = $request->get_json_params();

        $postData = [
            'post_title' => sanitize_text_field($data['title'] ?? ''),
            'post_content' => wp_kses_post($data['content'] ?? ''),
            'post_status' => $data['status'] ?? 'draft',
            'post_type' => $data['post_type'] ?? 'post',
        ];

        $postId = wp_insert_post($postData, true);

        if (is_wp_error($postId)) {
            return $postId;
        }

        return new WP_REST_Response($this->formatPost(get_post($postId)), 201);
    }

    public function updatePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found.', ['status' => 404]);
        }

        $data = $request->get_json_params();
        $postData = ['ID' => $post->ID];

        if (isset($data['title'])) {
            $postData['post_title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['content'])) {
            $postData['post_content'] = wp_kses_post($data['content']);
        }
        if (isset($data['status'])) {
            $postData['post_status'] = sanitize_key($data['status']);
        }

        $result = wp_update_post($postData, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($this->formatPost(get_post($post->ID)), 200);
    }

    public function deletePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found.', ['status' => 404]);
        }

        $deleted = wp_trash_post($post->ID);

        if (!$deleted) {
            return new WP_Error('delete_failed', 'Could not delete post.', ['status' => 500]);
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $post->ID], 200);
    }

    public function getPostMeta(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found.', ['status' => 404]);
        }

        $appId = $request->get_param('_app_id');
        $prefix = $this->getAppMetaPrefix($appId);

        $allMeta = get_post_meta($post->ID);
        $appMeta = [];

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, $prefix)) {
                $appMeta[$key] = maybe_unserialize($values[0]);
            }
        }

        return new WP_REST_Response($appMeta, 200);
    }

    public function setPostMeta(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request['id']);

        if (!$post) {
            return new WP_Error('not_found', 'Post not found.', ['status' => 404]);
        }

        $key = $request['key'];
        $appId = $request->get_param('_app_id');
        $prefix = $this->getAppMetaPrefix($appId);

        // Enforce prefix
        if (!str_starts_with($key, $prefix)) {
            $key = $prefix . $key;
        }

        $data = $request->get_json_params();
        $value = $data['value'] ?? null;

        update_post_meta($post->ID, $key, $value);

        return new WP_REST_Response(['key' => $key, 'value' => $value], 200);
    }

    private function formatPost(\WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => ['rendered' => $post->post_title],
            'content' => ['rendered' => apply_filters('the_content', $post->post_content)],
            'excerpt' => ['rendered' => $post->post_excerpt],
            'status' => $post->post_status,
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'date' => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'author' => (int) $post->post_author,
            'link' => get_permalink($post),
        ];
    }

    private function getAppMetaPrefix(string $appId): string
    {
        return '_' . str_replace('.', '_', $appId) . '_';
    }
}
