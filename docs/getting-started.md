# Getting Started

## What is WPApps

WPApps is a specification for building WordPress extensions as external HTTP services. Instead of running PHP code inside WordPress (like plugins do), apps run on their own server and communicate with WordPress through a REST API protocol.

This means:
- Apps cannot access the WordPress database, filesystem, or runtime.
- Apps get scoped OAuth tokens with explicit permissions.
- Apps write data via API; WordPress renders it.
- A site with 20 apps loads at the same speed as a site with zero apps (when using Tier 1 patterns).

The model is identical to how Shopify apps work.

## Install the Runtime

The Apps Runtime is a WordPress plugin that manages installed apps, enforces permissions, and dispatches events.

### As a regular plugin

Upload `wp-apps-runtime.zip` via **Plugins > Add New > Upload Plugin** in wp-admin, then activate.

### As a must-use plugin (recommended for production)

Copy the runtime to `wp-content/mu-plugins/`:

```bash
cp -r wp-apps-runtime/ /path/to/wordpress/wp-content/mu-plugins/wp-apps-runtime/
```

Must-use mode prevents accidental deactivation. Both modes are functionally identical.

### Requirements

- WordPress 6.5+
- PHP 8.1+
- HTTPS enabled
- REST API accessible (not blocked by security plugins)

## Create Your First App

### 1. Set up the project

```bash
mkdir my-seo-app && cd my-seo-app
composer require wpapps/sdk
```

### 2. Create the manifest

Create `wp-app.json`:

```json
{
  "$schema": "https://wp-apps.org/schema/v1/manifest.json",
  "spec_version": "0.1.0",

  "app": {
    "id": "com.example.my-seo-app",
    "name": "My SEO App",
    "version": "1.0.0",
    "description": "Writes SEO meta tags to posts on save.",
    "author": {
      "name": "Your Name",
      "url": "https://example.com"
    },
    "license": "MIT"
  },

  "runtime": {
    "endpoint": "https://my-seo-app.example.com",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks",
    "timeout_ms": 5000
  },

  "requires": {
    "wp_version": ">=6.5",
    "php_version": ">=8.1",
    "apps_runtime_version": ">=0.1.0"
  },

  "permissions": {
    "scopes": [
      "posts:read",
      "postmeta:write"
    ]
  },

  "hooks": {
    "events": [
      {
        "event": "save_post",
        "description": "Analyze content and write SEO score to post meta"
      }
    ]
  },

  "postmeta": {
    "seo_title": "SEO title override for the <title> tag",
    "seo_score": "SEO score (0-100)"
  }
}
```

### 3. Write the app

Create `index.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Tier 1: async event webhook. Runs in background, never blocks page loads.
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];

    // Fetch the post via the API
    $post = $req->api->get("/apps/v1/posts/{$postId}");
    if (!$post) {
        return Response::ok();
    }

    $content = strip_tags($post['content']['rendered'] ?? '');
    $title = $post['title']['rendered'] ?? '';

    // Your SEO analysis logic
    $score = min(100, strlen($content) > 300 ? 80 : 40);

    // Write results back as post meta (auto-namespaced per app)
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_score", [
        'value' => $score,
    ]);
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_title", [
        'value' => $title . ' | My Site',
    ]);

    return Response::ok();
});

$app->onHealth(function (): Response {
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
    ]);
});

$app->run();
```

### 4. Run locally

```bash
php -S localhost:8888 index.php
```

### 5. Install the app on your WordPress site

1. Go to **WP Admin > Apps**.
2. Click **Install App**.
3. Enter your manifest URL: `https://my-seo-app.example.com/wp-app.json`
4. Review the requested permissions and approve.
5. The runtime sends an auth callback to your app with an auth code.
6. Your app exchanges the code for access + refresh tokens.

## How It Works

When a post is saved on the WordPress site:

1. The Apps Runtime fires an async webhook to your app's `/hooks` endpoint.
2. Your `onEvent('save_post')` handler runs.
3. The handler fetches the post content via `$req->api->get()`.
4. It analyzes the content and writes SEO data back as post meta via `$req->api->put()`.
5. The runtime's `MetaRenderer` reads that meta on the next page load and outputs it in `<head>` -- no HTTP call to your app.

This is the **data-first model**: your app writes data at save time; WordPress renders it at page-load time. Zero runtime cost.

## Minimal Example: Block + Event

This example combines an event webhook (Tier 1) with a block (Tier 1). Both are zero-cost on page loads after the initial render/cache.

`wp-app.json`:

```json
{
  "app": {
    "id": "com.example.word-counter",
    "name": "Word Counter",
    "version": "1.0.0",
    "description": "Counts words on save and displays count as a block.",
    "author": { "name": "Example" },
    "license": "MIT"
  },
  "runtime": {
    "endpoint": "https://word-counter.example.com",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks"
  },
  "permissions": {
    "scopes": ["posts:read", "postmeta:write"]
  },
  "hooks": {
    "events": [
      { "event": "save_post", "description": "Count words and store as meta" }
    ]
  },
  "surfaces": {
    "blocks": [
      {
        "name": "word-counter/display",
        "title": "Word Count",
        "category": "widgets",
        "cache_ttl": 86400
      }
    ]
  }
}
```

`index.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Event: count words on save (async, zero page-load cost)
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];
    $post = $req->api->get("/apps/v1/posts/{$postId}");

    if ($post) {
        $words = str_word_count(strip_tags($post['content']['rendered'] ?? ''));
        $req->api->put("/apps/v1/posts/{$postId}/meta/word_count", [
            'value' => $words,
        ]);
    }

    return Response::ok();
});

// Block: display word count (cached, zero page-load cost after first render)
$app->onBlock('word-counter/display', function (Request $req): Response {
    $postId = $req->context['post_id'] ?? 0;
    $meta = $req->api->get("/apps/v1/posts/{$postId}/meta");
    $count = $meta['word_count'] ?? 'N/A';

    return Response::block(
        "<div class=\"word-count\">Word count: <strong>{$count}</strong></div>"
    );
});

$app->run();
```

The admin places the "Word Count" block in the editor. The block output is cached (TTL 86400 = 24 hours). On save, the event webhook updates the word count. The cache is invalidated automatically when the post is saved.

## Next Steps

- [Manifest Reference](manifest-reference.md) -- all manifest fields.
- [SDK Reference](sdk-reference.md) -- full API for `App`, `Request`, `Response`, `ApiClient`.
- [Integration Model](integration-model.md) -- understand Tier 1 vs Tier 2.
- [Security](security.md) -- OAuth, HMAC, token lifecycle.
