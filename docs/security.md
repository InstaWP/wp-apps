# Security

Security is a top priority in WPApps. The entire architecture exists because the traditional WordPress plugin model has no security boundaries -- a single vulnerable plugin compromises the entire site. WPApps eliminates this by running apps as external services with scoped access.

## OAuth 2.0 Flow

Apps authenticate via an OAuth 2.0-based flow. No app has access to anything until the site admin explicitly approves its requested permissions.

### Installation Sequence

```
1. Admin provides the manifest URL to the Apps Runtime.
2. Runtime fetches wp-app.json from the app server.
3. Runtime displays a consent screen showing:
   - App identity (name, author, version)
   - Requested permission scopes
   - Privacy declarations (data collected, retention periods)
   - Network access (outbound domains)
4. Admin reviews and approves.
5. Runtime generates an auth code and POSTs it to the app's auth_callback URL:
   POST /auth/callback
   {
     "site_url": "https://example.com",
     "site_id": "a1b2c3d4",
     "auth_code": "{one-time-code}",
     "scopes_granted": ["posts:read", "postmeta:write"]
   }
6. App exchanges the auth code for a token pair:
   POST /wp-json/apps/v1/token
   {
     "app_id": "com.example.my-seo-app",
     "code": "{auth_code}"
   }
7. Runtime returns access_token + refresh_token.
8. App stores tokens securely. Installation complete.
```

Auth codes are single-use and expire after 10 minutes.

## Token Lifecycle

### Access Token

- **Lifetime:** 1 hour (3600 seconds).
- **Format:** 64-character hex string (32 random bytes).
- **Storage:** Runtime stores only the SHA-256 hash. The plaintext token is never persisted on the WordPress side.
- **Usage:** Sent as `Authorization: Bearer {token}` on every API request.
- **On expiry:** SDK automatically refreshes using the refresh token.

### Refresh Token

- **Lifetime:** 90 days (7,776,000 seconds).
- **Rotation:** Every refresh generates a new token pair and invalidates the old refresh token. This limits the damage window if a refresh token is compromised.
- **Storage:** Runtime stores the SHA-256 hash plus an AES-256-CBC encrypted copy (using `wp_salt('auth')` as the key).

### Token Refresh

```
POST /wp-json/apps/v1/token/refresh
{
  "app_id": "com.example.my-seo-app",
  "refresh_token": "{refresh_token}"
}
```

Response:

```json
{
  "access_token": "{new_access_token}",
  "refresh_token": "{new_refresh_token}",
  "expires_in": 3600,
  "token_type": "Bearer",
  "scopes": ["posts:read", "postmeta:write"]
}
```

The old refresh token is deleted immediately (rotation). If a refresh token is used twice, the second attempt fails and the admin should be alerted to potential token theft.

### Token Revocation

The runtime can revoke all tokens for an app at any time:

- When the admin deactivates or uninstalls the app.
- When the admin manually revokes access.
- When the runtime detects suspicious behavior.

All token rows for the app are deleted from the database.

---

## HMAC Webhook Signatures

When WordPress dispatches webhooks to apps (events, filters, surface renders), every request is signed with HMAC-SHA256 to prevent tampering and spoofing.

### Signature Headers

```http
POST /hooks
Host: my-app.example.com
Content-Type: application/json
X-WP-Apps-Signature: sha256={HMAC-SHA256 of body using shared secret}
X-WP-Apps-Site-Id: a1b2c3d4
X-WP-Apps-Timestamp: 1714000000
X-WP-Apps-Hook: save_post
X-WP-Apps-Delivery-Id: {uuid-v4}
```

### Validation

The SDK's `HmacValidator` performs two checks:

1. **Timestamp check:** Rejects requests where the `X-WP-Apps-Timestamp` differs from the current time by more than 5 minutes. This prevents replay attacks.

2. **Signature check:** Computes `sha256=` + HMAC-SHA256 of the raw request body using the shared secret, then compares it to the `X-WP-Apps-Signature` header using `hash_equals()` (constant-time comparison to prevent timing attacks).

```php
// The SDK does this automatically when you call App::setSharedSecret()
$app->setSharedSecret($secret);

// Under the hood:
$expected = 'sha256=' . hash_hmac('sha256', $body, $sharedSecret);
$isValid = hash_equals($expected, $signatureHeader);
```

### Shared Secret

The shared secret is established during the OAuth installation flow. It is unique per app-site pair. The runtime stores it in the `wp_apps_installed` table alongside the app's manifest.

---

## Permission Enforcement

Permissions are enforced at the API gateway level by the runtime's `PermissionEnforcer`. Every incoming API request is checked against the app's granted scopes before reaching any handler.

### Scope Mapping

The `PermissionEnforcer` maps HTTP method + route to a required scope:

| Method + Route | Required Scope |
|---------------|----------------|
| `GET /apps/v1/posts` | `posts:read` |
| `POST /apps/v1/posts` | `posts:write` |
| `PUT /apps/v1/posts/{id}` | `posts:write` |
| `DELETE /apps/v1/posts/{id}` | `posts:delete` |
| `GET /apps/v1/posts/{id}/meta` | `postmeta:read` |
| `PUT /apps/v1/posts/{id}/meta/{key}` | `postmeta:write` |
| `GET /apps/v1/users` | `users:read:basic` |
| `GET /apps/v1/site` | `site:read` |
| `POST /apps/v1/media` | `media:write` |

### Scope Hierarchy

Broader scopes include narrower ones:

```
posts:write       -> posts:read
users:write       -> users:read:full -> users:read:basic
```

An app with `posts:write` does not need to separately request `posts:read`.

### Scope Constraints

Some scopes have constraints that are enforced at query time:

- `posts:read:published` -- the runtime forces `post_status = publish` regardless of what the app requests.
- `postmeta:read` / `postmeta:write` -- the runtime only returns/accepts meta keys with the app's namespace prefix.

---

## Never-Grantable Capabilities

The following capabilities are **never available** to apps, regardless of what they request:

- Direct database queries (`$wpdb`)
- Filesystem access
- PHP code execution (`eval`, `create_function`)
- WordPress core file modification
- Plugin/theme installation or modification
- User password access or modification
- Capability/role modification
- Network/multisite super admin actions
- `wp-config.php` access
- Raw SQL execution
- `wp_options` read/write
- WordPress transients or object cache

These are architectural constraints, not just permission rules. Since apps run as external HTTP services, they physically cannot execute code inside WordPress.

---

## Data Isolation

### What Apps Can Access

- Their own post meta (auto-namespaced with `_{app_id_slug}_` prefix).
- WordPress posts, users, media, taxonomies, comments -- but only through the scoped API, and only if the admin granted those scopes.
- Site info (title, tagline, URL, language) -- with `site:read` scope.

### What Apps Cannot Access

- Other apps' post meta or data.
- WordPress core tables directly.
- `wp-config.php` or the filesystem.
- `wp_options` table (apps store settings in their own database).
- WordPress transients or object cache.
- User passwords, roles, or capabilities.
- Other apps' behavior or data.
- The WordPress PHP runtime.

### Storage Boundaries

Apps store their own data in their own databases. The only WordPress-side storage is post meta, which is:

- Namespaced per app (prefixed with `_{app_id_slug}_`).
- Scoped to specific posts (not global storage).
- Rate-limited (20 writes per post per minute, 50 keys per app per post, 64 KB per value).

---

## Audit Logging

The runtime logs every API call, hook dispatch, and lifecycle event to the `wp_apps_audit_log` table:

```json
{
  "app_id": "com.example.my-seo-app",
  "action_type": "api_call",
  "method": "PUT",
  "endpoint": "/apps/v1/posts/42/meta/_com_example_seo_seo_score",
  "status_code": 200,
  "duration_ms": 45,
  "ip": "203.0.113.42",
  "created_at": "2026-04-12T10:30:00Z"
}
```

Logged action types:

| Action Type | Description |
|-------------|-------------|
| `api_call` | App made an API request to WordPress. |
| `event_webhook` | Runtime dispatched an event webhook to the app. |
| `filter_dispatch` | Runtime dispatched a render-path filter to the app. |
| `lifecycle` | Lifecycle event (install, activate, deactivate, uninstall). |
| `token_exchange` | Auth code exchanged for tokens. |
| `token_refresh` | Access token refreshed. |
| `token_revoke` | Tokens revoked. |

Site admins can view the audit log in **WP Admin > Apps** to see exactly what each app does.

---

## Threat Mitigations

| Threat | Mitigation |
|--------|------------|
| App exfiltrates user data | Scoped permissions + audit logging. Apps only access data the admin explicitly approved. |
| Malicious JS in iframe surfaces | `sandbox` attribute on iframes + CSP `frame-src` / `frame-ancestors` headers. |
| Man-in-the-middle on webhooks | HTTPS required + HMAC-SHA256 signatures + timestamp validation. |
| Resource exhaustion | Rate limits on all operations + timeout enforcement + payload size limits. |
| Compromised access token | 1-hour expiry limits the damage window. |
| Compromised refresh token | Rotation on every use. Second use of a rotated token fails and should trigger an alert. |
| App impersonation | Per-app signing secrets + `X-App-Id` verification. |
| Replay attacks | 5-minute timestamp window on HMAC signatures. |
| Excessive data storage | Rate limits on meta writes + key count limits + value size limits. |
| Malicious manifest | Manifest validation + permission consent screen shown to admin before installation. |

---

## Content Security Policy

For iframe-based admin surfaces, the runtime sets CSP headers:

```http
Content-Security-Policy:
  frame-src https://my-app.example.com;
  frame-ancestors 'self';
```

Iframes use the `sandbox` attribute with minimal permissions:

```html
<iframe
  src="https://my-app.example.com/surfaces/dashboard?site_id=..."
  sandbox="allow-scripts allow-forms allow-same-origin"
></iframe>
```

---

## Health Check Auto-Deactivation

The runtime pings each app's health check endpoint every 5 minutes. If an app fails 3 consecutive health checks, it is automatically deactivated and the site admin is notified. This prevents a misbehaving app from degrading the site.

## Database Schema

The runtime creates four tables for security infrastructure:

| Table | Purpose |
|-------|---------|
| `wp_apps_installed` | Installed apps: manifest, endpoint, shared secret, status, health failures. |
| `wp_apps_tokens` | Token pairs: hashed access/refresh tokens, scopes, expiry. |
| `wp_apps_auth_codes` | OAuth auth codes: hashed code, scopes, expiry, used flag. |
| `wp_apps_audit_log` | Audit trail: every API call, webhook, and lifecycle event. |

All tokens and auth codes are stored as SHA-256 hashes. Plaintext tokens never persist on the WordPress side.
