# SDK Reference

The PHP SDK (`WPApps\SDK`) provides everything needed to build a WPApps app. It handles routing, authentication, webhook validation, and API communication.

Namespace: `WPApps\SDK`

## App

`WPApps\SDK\App` -- the main class. Reads the manifest, registers handlers, and routes incoming requests.

### Constructor

```php
$app = new App(string $manifestPath);
```

Reads and parses `wp-app.json` from the given path. Throws `\RuntimeException` if the file is unreadable or contains invalid JSON.

The constructor also initializes a `TokenStore` at `{manifest_dir}/.wp-apps-tokens/`.

### setSharedSecret()

```php
$app->setSharedSecret(string $secret): self
```

Sets the HMAC shared secret used to validate incoming webhooks from WordPress. This secret is established during the OAuth installation flow. When set, all incoming webhook requests are validated against this secret.

### onEvent() -- Tier 1 (preferred)

```php
$app->onEvent(string $event, callable $handler): self
```

Register a handler for an async event webhook. Events fire when WordPress data changes (post saved, user registered, etc.). They run in the background and **never block page loads**.

This is the preferred integration method. Use it for any logic that reacts to WordPress data changes.

```php
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];
    $post = $req->api->get("/apps/v1/posts/{$postId}");

    // Process the post, write data back via API
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_score", [
        'value' => 85,
    ]);

    return Response::ok();
});
```

Available events: `save_post`, `delete_post`, `transition_post_status`, `add_attachment`, `edit_attachment`, `delete_attachment`, `user_register`, `profile_update`, `delete_user`, `wp_login`, `wp_logout`, `wp_insert_comment`, `edit_comment`, `delete_comment`, `transition_comment_status`, `created_term`, `edited_term`, `delete_term`.

### onBlock() -- Tier 1 (preferred)

```php
$app->onBlock(string $blockName, callable $handler): self
```

Register a handler that renders a block. Blocks are the preferred way to inject frontend UI. The output is cached by the runtime -- subsequent page loads serve from cache with zero app calls.

```php
$app->onBlock('my-app/pricing-table', function (Request $req): Response {
    $html = '<div class="pricing-table">...</div>';
    return Response::block($html);
});
```

The block must be declared in the manifest under `surfaces.blocks`. The admin places it via the block editor.

### onFilter() -- Tier 2 (escape hatch)

```php
$app->onFilter(string $hook, callable $handler): self
```

Register a handler for a render-path filter. **This adds an HTTP round-trip to every page load where the filter fires.** Use only when Tier 1 patterns (events + blocks + post meta) are insufficient.

```php
// DISCOURAGED -- use blocks or post meta instead
$app->onFilter('the_content', function (Request $req): Response {
    $content = $req->args[0] ?? '';
    $modified = $content . '<p>Appended by app</p>';
    return Response::filter($modified);
});
```

The runtime will display a performance warning in the admin if an app subscribes to `the_content`.

### onSurface()

```php
$app->onSurface(string $surfaceId, string $action, callable $handler): self
```

Register a handler for admin surfaces (admin pages, meta boxes, dashboard widgets). The `$action` is typically `"render"` for initial display or `"interaction"` for user actions.

```php
$app->onSurface('seo-dashboard', 'render', function (Request $req): Response {
    return Response::ui([
        ['type' => 'heading', 'text' => 'SEO Dashboard'],
        ['type' => 'paragraph', 'text' => 'Your site SEO overview.'],
        ['type' => 'table', 'headers' => ['Post', 'Score'], 'rows' => [...]],
    ]);
});
```

### onHealth()

```php
$app->onHealth(callable $handler): self
```

Register a custom health check handler. If not set, the SDK returns a default response with `status: "healthy"` and the app version from the manifest.

```php
$app->onHealth(function (): Response {
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
        'db' => 'connected',
    ]);
});
```

The runtime pings `/health` periodically. Three consecutive failures (at 5-minute intervals) trigger automatic deactivation.

### Lifecycle Handlers

```php
$app->onInstall(callable $handler): self
$app->onActivate(callable $handler): self
$app->onDeactivate(callable $handler): self
$app->onUninstall(callable $handler): self
```

Called by the runtime at lifecycle transitions. Use `onInstall` to set up databases or validate requirements. Use `onUninstall` to clean up all app state.

```php
$app->onInstall(function (): void {
    // Create app tables, validate environment
});

$app->onUninstall(function (): void {
    // Drop app tables, delete all app data
});
```

### run()

```php
$app->run(): void
```

Starts the app server. Reads `$_SERVER['REQUEST_URI']` and `$_SERVER['REQUEST_METHOD']` to route requests to the appropriate handler.

Routes:
- `GET {health_check}` -- health check
- `POST {auth_callback}` -- OAuth callback (token exchange)
- `POST {webhook_path}` -- event/filter/action webhooks
- `POST /lifecycle/{event}` -- lifecycle hooks
- `POST /surfaces/{surface}` -- surface render/interaction

This method calls `exit` after sending the response.

---

## Request

`WPApps\SDK\Request` -- immutable data object passed to every handler. Contains the webhook payload and an API client.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$req->deliveryId` | string | Unique delivery ID for this webhook. |
| `$req->hook` | string | WordPress hook name (e.g., `"save_post"`, `"the_content"`). |
| `$req->type` | string | `"event"`, `"filter"`, or `"action"`. |
| `$req->args` | array | Hook arguments. For `save_post`: `[$postId, $post, $update]`. For `the_content`: `[$content]`. |
| `$req->context` | array | Request context: `post_id`, `post_type`, `post_status`, `user_id`, `user_role`, `locale`, `timestamp`, etc. |
| `$req->site` | array | Site info: `{ "url": "https://example.com", "id": "a1b2c3d4" }`. |
| `$req->api` | ApiClient | Pre-configured API client for making calls back to the WordPress site. |
| `$req->surface` | ?string | Surface type for surface requests: `"block"`, `"meta_box"`, etc. |
| `$req->surfaceId` | ?string | Surface identifier (e.g., `"seo-dashboard"`). |
| `$req->action` | ?string | Surface action: `"render"` or `"interaction"`. |
| `$req->interaction` | ?array | Interaction data (component ID, type, form data). |

### Methods

```php
$req->isHook(): bool    // True if this is a hook request (event/filter/action)
$req->isSurface(): bool // True if this is a surface render/interaction request
```

---

## Response

`WPApps\SDK\Response` -- immutable value object returned by handlers. All constructors are static factory methods.

### Response::filter()

```php
Response::filter(mixed $value, string $deliveryId = ''): Response
```

For filter hooks. Returns the modified value back to WordPress.

```php
return Response::filter($content . '<p>Appended</p>');
```

### Response::ok()

```php
Response::ok(string $deliveryId = ''): Response
```

Simple acknowledgment for events and actions.

```php
return Response::ok();
```

### Response::block()

```php
Response::block(string $html): Response
```

For block renders. Returns HTML that the runtime caches and serves on subsequent page loads.

```php
return Response::block('<div class="my-widget">Widget content</div>');
```

### Response::ui()

```php
Response::ui(array $components): Response
```

For admin surface renders. Returns an array of structured UI components that the runtime renders natively in wp-admin.

```php
return Response::ui([
    ['type' => 'heading', 'text' => 'Dashboard'],
    ['type' => 'notice', 'style' => 'success', 'text' => 'All good!'],
    ['type' => 'table', 'headers' => ['Post', 'Score'], 'rows' => [...]],
]);
```

Available component types: `text_field`, `textarea`, `number_field`, `select`, `multi_select`, `checkbox`, `radio_group`, `toggle`, `button`, `link`, `heading`, `paragraph`, `notice`, `progress_bar`, `checklist`, `table`, `tabs`, `card`, `divider`, `image`, `code`, `key_value`, `chart`, `empty_state`, `loading`.

### Response::json()

```php
Response::json(array $data, int $statusCode = 200): Response
```

Generic JSON response. Used for health checks and custom responses.

```php
return Response::json(['status' => 'healthy', 'version' => '1.0.0']);
```

### Response::error()

```php
Response::error(string $code, string $message, string $deliveryId = ''): Response
```

Error response (HTTP 500).

```php
return Response::error('analysis_failed', 'Could not analyze post content');
```

---

## ApiClient

`WPApps\SDK\ApiClient` -- makes authenticated HTTP requests to the WordPress site's `/apps/v1/` API. Created automatically for each request and available as `$req->api`.

### Constructor

```php
$client = new ApiClient(string $siteUrl, string $appId, TokenStore $tokenStore);
```

You rarely construct this directly. The `App` class creates it for each incoming webhook and injects it into the `Request` object.

### HTTP Methods

```php
$client->get(string $path, array $query = []): ?array
$client->post(string $path, array $data = []): ?array
$client->put(string $path, array $data = []): ?array
$client->delete(string $path): ?array
```

All methods return decoded JSON (associative array) or `null` on failure.

```php
// Read posts
$posts = $req->api->get('/apps/v1/posts', ['status' => 'publish', 'per_page' => 20]);

// Read a single post
$post = $req->api->get('/apps/v1/posts/42');

// Create a post
$new = $req->api->post('/apps/v1/posts', [
    'title' => 'My New Post',
    'content' => '<p>Post content here.</p>',
    'status' => 'draft',
]);

// Update post meta
$req->api->put('/apps/v1/posts/42/meta/seo_score', ['value' => 85]);

// Delete a post
$req->api->delete('/apps/v1/posts/42');

// Read site info
$site = $req->api->get('/apps/v1/site');
```

### Auto-Refresh

When a request gets a 401 response, the client automatically attempts to refresh the access token using the stored refresh token. If refresh succeeds, the original request is retried once. This is transparent to the handler code.

### setTokens()

```php
$client->setTokens(string $accessToken, string $refreshToken): void
```

Stores a new token pair. Called internally during the OAuth callback flow. Tokens are persisted to disk via `TokenStore`.

### Request Headers

Every API request includes:

```
Authorization: Bearer {access_token}
X-App-Id: {app_id}
X-Request-Id: {uuid-v4}
Content-Type: application/json
```

---

## Auth\TokenStore

`WPApps\SDK\Auth\TokenStore` -- persists OAuth tokens to disk, one JSON file per WordPress site.

### Constructor

```php
$store = new TokenStore(string $storagePath);
```

Creates the storage directory with `0700` permissions if it does not exist.

### Methods

```php
$store->save(string $siteUrl, array $tokens): void   // Write tokens for a site
$store->load(string $siteUrl): array                  // Read tokens (returns [] if not found)
$store->delete(string $siteUrl): void                 // Delete tokens for a site
```

Tokens are stored as JSON files named by SHA-256 hash of the site URL. File permissions are set to `0600`.

---

## Auth\HmacValidator

`WPApps\SDK\Auth\HmacValidator` -- validates HMAC-SHA256 signatures on incoming webhooks from WordPress.

### Constructor

```php
$validator = new HmacValidator(string $sharedSecret);
```

### validate()

```php
$validator->validate(string $body, string $signature, int $timestamp): bool
```

Validates a webhook request:

1. Rejects requests older than 5 minutes (replay attack prevention).
2. Computes `sha256=` + HMAC-SHA256 of the body using the shared secret.
3. Compares against the `X-WP-Apps-Signature` header using `hash_equals()` (timing-safe).

```php
$signature = $_SERVER['HTTP_X_WP_APPS_SIGNATURE'] ?? '';
$timestamp = (int) ($_SERVER['HTTP_X_WP_APPS_TIMESTAMP'] ?? 0);
$body = file_get_contents('php://input');

$isValid = $validator->validate($body, $signature, $timestamp);
```

When using `App::setSharedSecret()`, HMAC validation happens automatically for all webhooks. You do not need to call `HmacValidator` directly.

---

## Handler Priority

When building an app, prefer handlers in this order:

1. `onEvent()` -- async, zero page-load cost. Use for reacting to data changes.
2. `onBlock()` -- cached, zero page-load cost after first render. Use for frontend UI.
3. `onSurface()` -- admin panels and settings. No frontend cost.
4. `onFilter()` -- escape hatch. Adds latency. Last resort only.
