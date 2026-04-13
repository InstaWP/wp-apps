# WP Apps

**Sandboxed, permission-scoped extensions for WordPress.**

Apps run as isolated external HTTP services — zero access to your database, filesystem, or PHP runtime. The Shopify model, for WordPress.

https://github.com/user-attachments/assets/a820f80e-ac0f-4744-8c8e-3d41fb4932a3

## Why

WordPress plugins execute as trusted code inside the PHP runtime. A plugin has full access to the database, filesystem, network, and every other plugin. Plugin vulnerabilities are the #1 attack vector for WordPress sites.

WP Apps fixes this by running extensions as **external services** that communicate through a structured API protocol — with scoped OAuth tokens, granular permissions, and full audit logging.

## How It Works

```
WordPress Site                          App Server
┌──────────────────────┐                ┌──────────────┐
│  Apps Runtime         │   HTTPS/API   │  Your App    │
│  ├─ API Gateway      │◄─────────────►│  ├─ Events   │
│  ├─ Block Manager    │               │  ├─ Blocks   │
│  ├─ Event Webhooks   │               │  └─ Own DB   │
│  ├─ Meta Renderer    │               └──────────────┘
│  └─ Permission       │
│     Enforcement      │
└──────────────────────┘
```

**Data-first:** Apps write data via API, WordPress renders it. Zero HTTP calls during page loads.

**Two-tier integration:**
- **Tier 1 (preferred):** Event webhooks + blocks + post meta = zero runtime cost
- **Tier 2 (escape hatch):** Render-path filters like `the_content` = adds latency, discouraged

## Quick Start

Build a working app in 3 files:

### 1. Install the runtime

[Download the plugin zip](https://github.com/InstaWP/wp-apps/releases) and install via **WP Admin → Plugins → Add New → Upload Plugin**.

### 2. Create your app

**`wp-app.json`** — declare what your app does:

```json
{
  "app": {
    "id": "com.example.my-app",
    "name": "My First App",
    "version": "1.0.0",
    "description": "Calculates reading time for posts.",
    "author": { "name": "Your Name" },
    "license": "MIT"
  },
  "runtime": {
    "endpoint": "http://localhost:8001",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks"
  },
  "permissions": {
    "scopes": ["posts:read", "postmeta:write"]
  },
  "hooks": {
    "events": [
      { "event": "save_post", "description": "Calculate reading time" }
    ]
  },
  "surfaces": {
    "blocks": [
      { "name": "my-app/reading-time", "title": "Reading Time", "cache_ttl": 86400 }
    ]
  }
}
```

**`composer.json`** — require the SDK:

```json
{
  "require": { "instawp/wp-apps-sdk-php": "^0.1" },
  "autoload": { "psr-4": { "MyApp\\": "src/" } }
}
```

**`index.php`** — handle events and render blocks:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Event: fires async when a post is saved. Never blocks page loads.
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];
    $post = $req->api->get("/apps/v1/posts/{$postId}");

    $words = str_word_count(strip_tags($post['content']['rendered'] ?? ''));
    $minutes = max(1, (int) ceil($words / 238));

    // Write to post meta — WordPress caches and serves this
    $req->api->put("/apps/v1/posts/{$postId}/meta/reading_time", [
        'value' => $minutes
    ]);

    return Response::ok();
});

// Block: rendered once, cached for 24hrs. Zero cost on page loads.
$app->onBlock('my-app/reading-time', function (Request $req): Response {
    $postId = $req->context['post_id'] ?? 0;
    $meta = $req->api->get("/apps/v1/posts/{$postId}/meta");

    $minutes = 1;
    foreach ($meta ?? [] as $key => $value) {
        if (str_ends_with($key, '_reading_time')) $minutes = (int) $value;
    }

    return Response::block(
        "<span style='color:#666;font-size:14px;'>📖 {$minutes} min read</span>"
    );
});

$app->run();
```

### 3. Run it

```bash
composer install
php -S localhost:8001 index.php
```

### 4. Install on WordPress

**WP Admin → Apps → Install New** → enter `http://localhost:8001/wp-app.json` → review permissions → approve.

Your app is live. Save a post to trigger the reading time calculation. Add the "Reading Time" block to any page via the block editor (or use `[my-app-reading-time]` shortcode in Elementor/Divi).

## Example Apps

| App | What it demonstrates | Lines |
|-----|---------------------|-------|
| [Reading Time](sdk/examples/reading-time/) | Event → post meta → block (the complete data-first loop) | ~50 |
| [Contact Form](sdk/examples/contact-form/) | Block + form submission + app-side storage + admin panel | ~150 |
| [Hello App](sdk/examples/hello-app/) | Minimal app — event webhook + health check | ~10 |

![Apps admin panel](docs/screenshots/admin-apps-list.png)

## What Apps Can't Do

Apps **cannot** access:
- Database directly (SQL, $wpdb)
- Filesystem (wp-config.php, core files, uploads)
- PHP runtime (eval, globals, other plugins)
- User passwords or session tokens
- wp_options or transients (apps use their own storage)
- Other apps' data

## Documentation

- [Getting Started](docs/getting-started.md)
- [Manifest Reference](docs/manifest-reference.md) (`wp-app.json`)
- [SDK Reference](docs/sdk-reference.md) (PHP)
- [API Reference](docs/api-reference.md) (`/apps/v1/`)
- [Integration Model](docs/integration-model.md) (Tier 1 vs Tier 2)
- [Security](docs/security.md) (OAuth, HMAC, permissions)
- [Apps vs Plugins](docs/apps-vs-plugins.md) (when to build which)
- [FAQ](docs/faq.md) (hosting, performance, security, comparisons)
- [Specification](SPEC.md) (full spec)

## Links

- **Website:** [wp-apps.org](https://wp-apps.org)
- **Spec:** [SPEC.md](SPEC.md)
- **Release:** [v0.0.1](https://github.com/InstaWP/wp-apps/releases/tag/v0.0.1)
- **License:** MIT

Created by [InstaWP](https://instawp.com)
