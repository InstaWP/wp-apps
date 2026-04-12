# API Reference

All app-to-WordPress communication goes through the Apps API at `/wp-json/apps/v1/`. Apps never use `/wp/v2/` directly.

## Authentication

Every request must include a Bearer token and app ID:

```http
GET /wp-json/apps/v1/posts?status=publish
Host: example.com
Authorization: Bearer {access_token}
X-App-Id: com.example.my-seo-app
X-Request-Id: {uuid-v4}
Content-Type: application/json
Accept: application/json
```

Access tokens are short-lived (1 hour). When a request returns `401`, the SDK automatically refreshes the token using the refresh token and retries the request once.

---

## Posts

Requires `posts:read` for GET, `posts:write` for POST/PUT, `posts:delete` for DELETE.

### List Posts

```
GET /apps/v1/posts
```

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `publish` | Post status: `publish`, `draft`, `pending`, `private`. |
| `post_type` | string | `post` | Post type. |
| `per_page` | integer | 10 | Results per page. Max: 100. |
| `page` | integer | 1 | Page number. |
| `orderby` | string | `date` | Sort field: `date`, `title`, `modified`. |
| `order` | string | `desc` | Sort direction: `asc`, `desc`. |
| `meta_key` | string | -- | Filter by meta key. |
| `meta_value` | string | -- | Filter by meta value. |
| `meta_compare` | string | `=` | Meta comparison: `=`, `!=`, `>`, `>=`, `<`, `<=`. |
| `after` | string | -- | Posts after this ISO 8601 date. |
| `categories` | string | -- | Comma-separated category IDs. |

**Response:**

```json
[
  {
    "id": 42,
    "title": { "rendered": "My Blog Post" },
    "content": { "rendered": "<p>Post content...</p>" },
    "excerpt": { "rendered": "" },
    "status": "publish",
    "type": "post",
    "slug": "my-blog-post",
    "date": "2026-04-10T12:00:00",
    "modified": "2026-04-10T14:30:00",
    "author": 1,
    "link": "https://example.com/my-blog-post/"
  }
]
```

**Pagination headers:**

```http
X-WP-Total: 42
X-WP-TotalPages: 3
Link: <https://example.com/wp-json/apps/v1/posts?page=2>; rel="next"
```

Apps with `posts:read:published` scope are restricted to `status=publish` regardless of what they request.

### Get Post

```
GET /apps/v1/posts/{id}
```

Returns a single post object. Returns `404` if not found.

### Create Post

```
POST /apps/v1/posts
```

**Request body:**

```json
{
  "title": "My New Post",
  "content": "<p>Post content here.</p>",
  "status": "draft",
  "post_type": "post"
}
```

Returns the created post with HTTP 201.

### Update Post

```
PUT /apps/v1/posts/{id}
```

**Request body (partial updates supported):**

```json
{
  "title": "Updated Title",
  "content": "<p>Updated content.</p>",
  "status": "publish"
}
```

### Delete Post

```
DELETE /apps/v1/posts/{id}
```

Moves the post to trash. Returns:

```json
{ "deleted": true, "id": 42 }
```

---

## Post Meta

Requires `postmeta:read` for GET, `postmeta:write` for PUT/DELETE.

All meta keys are automatically namespaced per app. Writing `seo_score` for app `com.example.seo` stores `_com_example_seo_seo_score` in WordPress. Apps can only read/write their own namespaced keys.

### Get Post Meta

```
GET /apps/v1/posts/{id}/meta
```

Returns all meta keys belonging to this app for the given post:

```json
{
  "_com_example_seo_seo_score": 85,
  "_com_example_seo_seo_title": "Optimized Title"
}
```

### Set Post Meta

```
PUT /apps/v1/posts/{id}/meta/{key}
```

**Request body:**

```json
{ "value": 85 }
```

The `{key}` is auto-prefixed if it does not already start with the app's namespace prefix. You can write either `seo_score` or the full prefixed key.

Returns:

```json
{ "key": "_com_example_seo_seo_score", "value": 85 }
```

### Delete Post Meta

```
DELETE /apps/v1/posts/{id}/meta/{key}
```

---

## Site Info

Requires `site:read`.

```
GET /apps/v1/site
```

Returns site settings:

```json
{
  "name": "My WordPress Site",
  "description": "Just another WordPress site",
  "url": "https://example.com",
  "language": "en_US",
  "timezone": "America/New_York"
}
```

---

## Users

Requires `users:read:basic` or `users:read:full`.

```
GET /apps/v1/users
GET /apps/v1/users/{id}
GET /apps/v1/users/me
```

Fields returned depend on scope:

| Scope | Fields |
|-------|--------|
| `users:read:basic` | `id`, `name`, `email`, `role` |
| `users:read:full` | All profile fields |

---

## Media

Requires `media:read` for GET, `media:write` for POST.

```
GET /apps/v1/media
GET /apps/v1/media/{id}
POST /apps/v1/media          (multipart upload)
```

---

## Cache Purge

Apps can invalidate their cached content.

### Purge Specific Block Cache

```
POST /apps/v1/cache/purge
```

```json
{
  "scope": "block",
  "block_name": "my-app/pricing-table",
  "post_id": 42
}
```

### Purge All App Caches

```
POST /apps/v1/cache/purge
```

```json
{
  "scope": "all"
}
```

Response:

```json
{ "status": "purged", "scope": "block" }
```

---

## Token Exchange

Used during the OAuth installation flow. Not called directly by app code -- the SDK handles this.

### Exchange Auth Code

```
POST /apps/v1/token
```

```json
{
  "app_id": "com.example.my-seo-app",
  "code": "{auth_code}"
}
```

Returns:

```json
{
  "access_token": "{token}",
  "refresh_token": "{token}",
  "expires_in": 3600,
  "token_type": "Bearer",
  "scopes": ["posts:read", "postmeta:write"]
}
```

### Refresh Token

```
POST /apps/v1/token/refresh
```

```json
{
  "app_id": "com.example.my-seo-app",
  "refresh_token": "{refresh_token}"
}
```

Returns a new token pair. The old refresh token is invalidated (rotation).

---

## Revisions

Requires `posts:read`.

```
GET /apps/v1/posts/{id}/revisions
GET /apps/v1/posts/{id}/revisions/{rev_id}
```

Revision data includes app-written post meta values at that point in time.

---

## One-Off Jobs

```
POST /apps/v1/jobs
```

```json
{
  "name": "reindex_all_posts",
  "endpoint": "/jobs/reindex",
  "delay_seconds": 0,
  "timeout_ms": 60000
}
```

The runtime queues the job and calls the app's endpoint.

---

## Rate Limits

Every response includes rate limit headers:

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 847
X-RateLimit-Reset: 1714003600
X-RateLimit-Scope: read
```

### Default Limits

| Operation | Limit | Window |
|-----------|-------|--------|
| Read (`GET`) | 1,000 | per hour |
| Write (`POST`, `PUT`) | 200 | per hour |
| Delete (`DELETE`) | 50 | per hour |
| Bulk operations | 20 | per hour |
| Token refresh | 10 | per hour |
| Cache purge | 30 | per hour |
| Emails (`email:send`) | 50/hour, 500/day | per app |
| Meta writes per post | 20 | per minute |
| Meta keys per app per post | 50 | total |
| Meta value size | 64 KB | per value |
| Event webhook deliveries | 1,000 | per hour |

When a limit is exceeded, the API returns `429 Too Many Requests`:

```json
{
  "code": "rate_limited",
  "message": "Read rate limit exceeded. Resets in 423 seconds.",
  "data": {
    "status": 429,
    "limit": 1000,
    "remaining": 0,
    "reset": 1714003600
  }
}
```

---

## Error Codes

All errors follow this format:

```json
{
  "code": "error_code",
  "message": "Human-readable error message.",
  "data": {
    "status": 403,
    "required_scope": "users:write",
    "app_id": "com.example.my-seo-app"
  }
}
```

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `missing_token` | 401 | No `Authorization: Bearer` header provided. |
| `invalid_token` | 401 | Access token is invalid. |
| `expired_token` | 401 | Access token has expired. Use refresh token. |
| `invalid_refresh_token` | 401 | Refresh token is invalid or expired. |
| `insufficient_scope` | 403 | App does not have the required permission scope. |
| `rate_limited` | 429 | Rate limit exceeded. Check `X-RateLimit-Reset` header. |
| `not_found` | 404 | Requested resource does not exist. |
| `invalid_request` | 400 | Malformed request body or parameters. |
| `invalid_code` | 400 | Auth code is invalid, expired, or already used. |
| `payload_too_large` | 413 | Response exceeds `max_payload_bytes`. |
| `timeout` | 504 | App did not respond within `timeout_ms`. |
| `conflict` | 409 | Resource conflict (e.g., duplicate app ID). |
| `internal_error` | 500 | Unexpected server error. |
| `delete_failed` | 500 | Could not delete the resource. |

---

## SDK Usage Summary

The `ApiClient` in the SDK wraps all of these endpoints:

```php
// In any handler:
$posts = $req->api->get('/apps/v1/posts', ['per_page' => 50]);
$post  = $req->api->get('/apps/v1/posts/42');
$new   = $req->api->post('/apps/v1/posts', ['title' => 'New Post', 'status' => 'draft']);
$req->api->put('/apps/v1/posts/42/meta/seo_score', ['value' => 92]);
$req->api->delete('/apps/v1/posts/42');
$site  = $req->api->get('/apps/v1/site');
$req->api->post('/apps/v1/cache/purge', ['scope' => 'all']);
```

Token refresh and request signing are handled automatically.
