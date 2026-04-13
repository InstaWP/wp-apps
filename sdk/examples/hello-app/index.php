<?php
/**
 * Hello App — The absolute minimum WP App.
 *
 * This is the "hello world" of WP Apps. It does two things:
 * 1. Responds to health checks
 * 2. Logs when a post is saved (async event, zero page-load cost)
 *
 * Run with: php -S localhost:8001 index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Event: fires async when a post is saved. Never blocks page loads.
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0] ?? 'unknown';
    error_log("[Hello App] Post #{$postId} was saved!");
    return Response::ok();
});

$app->run();
