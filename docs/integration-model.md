# Integration Model

WPApps uses a two-tier integration model. Tier 1 is preferred and should cover most use cases. Tier 2 is an escape hatch for the rare cases where Tier 1 is insufficient.

## Tier 1: Data-First (Zero Runtime Cost) -- PREFERRED

Apps write data to WordPress via the API. WordPress renders it. No HTTP calls to the app during page loads.

A site with 20 Tier 1 apps loads at the same speed as a site with zero apps.

### Three Tier 1 Patterns

| Pattern | When the app is called | Page-load cost | Use case |
|---------|----------------------|----------------|----------|
| **Event Webhooks** | Async, after WordPress data changes | Zero | Analyze content on save, sync to CRM, send Slack notifications |
| **Blocks** | Once on first render, then cached | Zero (after cache) | Contact forms, pricing tables, FAQ widgets |
| **Post Meta** | Never (app writes via API, runtime reads from DB) | Zero | SEO titles, meta descriptions, schema markup, OG tags |

### Event Webhooks

Apps subscribe to WordPress events in the manifest. The runtime fires webhooks asynchronously after the event occurs. These are always fire-and-forget -- the WordPress response is sent to the browser before the webhook is dispatched.

```php
// App receives this webhook async after a post is saved
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];
    $post = $req->api->get("/apps/v1/posts/{$postId}");

    // Analyze, compute, store results as post meta
    $score = analyze_seo($post['content']['rendered']);
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_score", ['value' => $score]);

    return Response::ok();
});
```

The runtime dispatches events with `blocking: false` and a 0.5s timeout. The app processes the event in its own time and writes results back via the API.

**Available events:** `save_post`, `delete_post`, `transition_post_status`, `add_attachment`, `edit_attachment`, `delete_attachment`, `user_register`, `profile_update`, `delete_user`, `wp_login`, `wp_logout`, `wp_insert_comment`, `edit_comment`, `delete_comment`, `transition_comment_status`, `created_term`, `edited_term`, `delete_term`.

### Blocks

Apps register blocks in the manifest. The admin places them in the block editor. The runtime renders the block by calling the app, then caches the output. Subsequent page loads serve cached HTML with zero app calls.

```php
$app->onBlock('my-app/pricing-table', function (Request $req): Response {
    $config = $req->context;
    $html = '<div class="pricing-table">...</div>';
    return Response::block($html);
});
```

Manifest:

```json
{
  "surfaces": {
    "blocks": [
      {
        "name": "my-app/pricing-table",
        "title": "Pricing Table",
        "category": "widgets",
        "cache_ttl": 3600
      }
    ]
  }
}
```

**Cache behavior:**

- Cache key: `{app_id}:{block_name}:{post_id}:{attributes_hash}:v{version}`
- Default TTL: 3600 seconds (1 hour). Max: 86400 (24 hours).
- Automatic invalidation when the post is saved (runtime parses post content for app blocks and deletes their transients).
- Automatic invalidation when the app is updated (version bump approach).
- Manual invalidation via the cache purge API (see below).

**Cache purge API:**

```
POST /apps/v1/cache/purge
{
  "scope": "block",
  "block_name": "my-app/pricing-table",
  "post_id": 42
}
```

Or purge all cached content for the app:

```
POST /apps/v1/cache/purge
{
  "scope": "all"
}
```

### Post Meta + Auto-Rendering

Apps write metadata to posts via the API. The runtime's `MetaRenderer` reads this meta from the WordPress database and renders appropriate HTML tags in `wp_head`. No HTTP call to the app.

```php
// In a save_post event handler:
$req->api->put("/apps/v1/posts/{$postId}/meta/seo_title", [
    'value' => 'My Optimized Title',
]);
$req->api->put("/apps/v1/posts/{$postId}/meta/seo_description", [
    'value' => 'A concise description for search engines.',
]);
$req->api->put("/apps/v1/posts/{$postId}/meta/schema_json", [
    'value' => json_encode(['@type' => 'Article', '@context' => 'https://schema.org']),
]);
```

The runtime auto-renders these meta suffixes in `wp_head`:

| Meta suffix | Rendered HTML |
|-------------|---------------|
| `seo_title` | Overrides `<title>` via `document_title_parts` filter |
| `seo_description` | `<meta name="description" content="...">` |
| `schema_json` | `<script type="application/ld+json">...</script>` |
| `og_title` | `<meta property="og:title" content="...">` |
| `og_description` | `<meta property="og:description" content="...">` |
| `og_image` | `<meta property="og:image" content="...">` |
| `canonical_url` | `<link rel="canonical" href="...">` |
| `robots` | `<meta name="robots" content="...">` |

Meta keys are auto-namespaced per app. If app ID is `com.example.seo`, writing `seo_title` stores `_com_example_seo_seo_title` in WordPress. The `MetaRenderer` scans for any meta key starting with `_` that ends with a known suffix and renders it.

First app to write a given suffix wins (e.g., if two apps both write `seo_title`, the first one rendered takes precedence).

---

## Tier 2: Runtime Hooks (Escape Hatch) -- USE SPARINGLY

For cases where an app must modify content at render time, the runtime hook system allows subscribing to WordPress filters. This adds an HTTP round-trip per subscribed app per page load.

### When to Use Tier 2

- User-specific personalization that cannot be cached.
- Content transformations that depend on request context (locale, device, geolocation).
- `wp_head` / `wp_footer` injection where post meta is insufficient (rare).

### When NOT to Use Tier 2

| Need | Use this instead |
|------|-----------------|
| Add meta tags, schema, OG tags | Post meta + auto-rendering (Tier 1) |
| Display a UI component on the frontend | Block (Tier 1) |
| Analyze content when a post is saved | Event webhook (Tier 1) |
| Inject static HTML into pages | Block (Tier 1) |

### Runtime Filter Example

```php
// ESCAPE HATCH -- adds latency to every page load
$app->onFilter('wp_head', function (Request $req): Response {
    // Only use this if post meta auto-rendering is insufficient
    $html = '<meta name="custom-dynamic-tag" content="...">';
    return Response::filter($html);
});
```

### Timeout Rules

| Hook context | Max timeout | On timeout |
|-------------|-------------|------------|
| Render-path filter (frontend) | 2000 ms | Skip app, use unmodified value |
| Admin filter | 5000 ms | Skip app, use unmodified value |
| Sync action | 5000 ms | Log warning, continue |
| `save_post` / `transition_post_status` | 10000 ms | Log warning, continue |
| Async action | N/A | Fire and forget |

### Performance Warning

If an app subscribes to `the_content`, `the_title`, or `the_excerpt`, the runtime displays a performance warning in the admin panel. These filters fire on every page load for every post and add HTTP latency each time.

---

## Migration: From Filters to Tier 1

### Before (Tier 2 -- adds page-load latency)

```php
// Old: filter that runs on every page load
$app->onFilter('the_content', function (Request $req): Response {
    $content = $req->args[0];
    $postId = $req->context['post_id'];

    // Compute word count on every page load (wasteful)
    $wordCount = str_word_count(strip_tags($content));
    $badge = "<div class='word-count'>Words: {$wordCount}</div>";

    return Response::filter($content . $badge);
});
```

Problems:
- HTTP round-trip on every page load.
- Recomputes word count every time (it only changes on save).
- If the app is slow or down, the page degrades.

### After (Tier 1 -- zero page-load cost)

```php
// Step 1: Compute on save (event webhook, async)
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];
    $post = $req->api->get("/apps/v1/posts/{$postId}");
    $wordCount = str_word_count(strip_tags($post['content']['rendered'] ?? ''));

    $req->api->put("/apps/v1/posts/{$postId}/meta/word_count", [
        'value' => $wordCount,
    ]);

    return Response::ok();
});

// Step 2: Display via block (cached)
$app->onBlock('my-app/word-count', function (Request $req): Response {
    $postId = $req->context['post_id'] ?? 0;
    $meta = $req->api->get("/apps/v1/posts/{$postId}/meta");
    $count = $meta['word_count'] ?? 'N/A';

    return Response::block("<div class='word-count'>Words: <strong>{$count}</strong></div>");
});
```

Result:
- Word count is computed once at save time (async, no page-load cost).
- Block HTML is cached. Subsequent loads serve from cache.
- If the app is down, cached content still serves.

---

## Performance Comparison

| Metric | Tier 1 (data-first) | Tier 2 (runtime hooks) |
|--------|---------------------|----------------------|
| Page-load HTTP calls to app | 0 | 1 per subscribed filter per app |
| Added latency per page load | 0 ms | 50-2000 ms per app |
| Behavior when app is down | No impact (cached/stored data serves) | Filter skipped, unmodified content |
| Scale with number of apps | Constant (zero) | Linear (each app adds latency) |
| Cache friendliness | Full page cache works | Must vary cache by filter output |
| Compute efficiency | Once at save time | Recomputed on every request |

### Decision Flowchart

```
Does the data change on every request?
  YES -> Does it depend on the specific user/request context?
           YES -> Tier 2 (runtime filter) -- but consider client-side JS instead
           NO  -> Probably cacheable. Tier 1 with short TTL.
  NO  -> Tier 1 (event webhook + post meta or block)
```

---

## Block Caching Deep Dive

The runtime's `BlockManager` handles the full cache lifecycle:

1. **First render**: Runtime calls the app's `/surfaces/blocks` endpoint. App returns HTML via `Response::block()`. Runtime stores the HTML as a WordPress transient.

2. **Subsequent page loads**: Runtime finds the transient. Serves cached HTML directly. No HTTP call to the app.

3. **Invalidation on post save**: The `save_post` action triggers `BlockManager::invalidateForPost()`. It parses the post content for app blocks and deletes their transients.

4. **Invalidation on app update**: The runtime bumps the app's block version counter, which changes all cache keys for that app.

5. **Manual invalidation**: Apps can call `POST /apps/v1/cache/purge` to bust specific block caches or all caches.

Cache keys include the post ID, block name, attributes hash, and version counter, so the same block with different attributes or on different posts gets separate cache entries.
