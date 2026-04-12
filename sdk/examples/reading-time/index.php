<?php
/**
 * Reading Time — The simplest possible WP App.
 *
 * Demonstrates the complete data-first loop:
 * 1. Event webhook: save_post → calculate reading time → write to post meta
 * 2. Block: display "X min read" badge (cached, zero page-load cost)
 *
 * That's it. ~50 lines of actual logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// ─── Event: Calculate reading time on post save ─────────────────
// Fires async when a post is saved. Zero impact on page loads.
// Writes results to post meta — WordPress caches and serves it.

$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0] ?? null;
    if (!$postId) {
        return Response::ok();
    }

    $post = $req->api->get("/apps/v1/posts/{$postId}");
    if (!$post) {
        return Response::ok();
    }

    // Strip HTML, count words
    $text = strip_tags($post['content']['rendered'] ?? '');
    $wordCount = str_word_count($text);

    // Average reading speed: 238 words per minute
    $readingTime = max(1, (int) ceil($wordCount / 238));

    // Write to post meta — the runtime serves this from cache on every page load
    $req->api->put("/apps/v1/posts/{$postId}/meta/reading_time", ['value' => $readingTime]);
    $req->api->put("/apps/v1/posts/{$postId}/meta/word_count", ['value' => $wordCount]);

    return Response::ok();
});

// ─── Block: Display reading time badge ──────────────────────────
// Rendered once by the app, cached by the runtime.
// Subsequent page loads serve cached HTML — zero app calls.

$app->onBlock('wpapps/reading-time', function (Request $req): Response {
    $postId = $req->context['post_id'] ?? 0;

    // Read the pre-computed reading time from post meta
    $minutes = 0;
    $words = 0;

    if ($postId) {
        $meta = $req->api->get("/apps/v1/posts/{$postId}/meta");
        if ($meta) {
            // Meta keys are prefixed by the runtime with the app namespace
            foreach ($meta as $key => $value) {
                if (str_ends_with($key, '_reading_time')) $minutes = (int) $value;
                if (str_ends_with($key, '_word_count')) $words = (int) $value;
            }
        }
    }

    if ($minutes === 0) {
        $minutes = 1;
    }

    $label = $minutes === 1 ? '1 min read' : "{$minutes} min read";

    $html = <<<HTML
<div style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#f0f0f1;border-radius:20px;font-size:13px;font-weight:500;color:#50575e;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    {$label}
</div>
HTML;

    return Response::block($html);
});

// ─── Health ─────────────────────────────────────────────────────

$app->onHealth(function (): Response {
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
        'app' => 'Reading Time',
    ]);
});

$app->run();
