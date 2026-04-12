<?php
/**
 * Hello App — A minimal WP Apps example.
 *
 * Run with: php -S localhost:8888 index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Filter: append a greeting to post content
$app->onFilter('the_content', function (Request $req): Response {
    $content = $req->args[0] ?? '';
    $postTitle = $req->context['post_title'] ?? 'this post';

    $greeting = '<div style="margin-top:2em;padding:1em;border:2px solid #00ff88;background:#0a2a1a;color:#00ff88;font-family:monospace;">'
        . '👋 Hello from <strong>WP Apps</strong>! This content was modified by an external app.'
        . '</div>';

    return Response::filter($content . $greeting);
});

// Action: log word count when a post is saved (async)
$app->onAction('save_post', function (Request $req): Response {
    $postId = $req->args[0] ?? null;

    if ($postId) {
        // Fetch the post via API
        $post = $req->api->get("/apps/v1/posts/{$postId}");

        if ($post) {
            $content = strip_tags($post['content']['rendered'] ?? '');
            $wordCount = str_word_count($content);

            // Store word count as post meta
            $req->api->put("/apps/v1/posts/{$postId}/meta/word_count", [
                'value' => $wordCount,
            ]);
        }
    }

    return Response::ok();
});

// Health check
$app->onHealth(function (): Response {
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
        'app' => 'Hello App',
    ]);
});

$app->run();
