# Manifest Reference

Every WPApps app must include a `wp-app.json` file at its root. This is the single source of truth for the app's identity, permissions, hooks, UI surfaces, and privacy declarations.

## Top-Level Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `$schema` | string | No | JSON Schema URL for IDE validation. Use `https://wp-apps.org/schema/v1/manifest.json`. |
| `spec_version` | string | Yes | Spec version this manifest targets. Current: `"0.1.0"`. |
| `app` | object | Yes | App identity. |
| `runtime` | object | Yes | HTTP endpoint configuration. |
| `requires` | object | No | WordPress/PHP version requirements. |
| `permissions` | object | Yes | Scopes and network access declarations. |
| `hooks` | object | No | Event webhook subscriptions. |
| `postmeta` | object | No | Post meta keys the app writes (documentation + auto-rendering). |
| `surfaces` | object | No | UI integration points (blocks, admin pages, meta boxes). |
| `storage` | object | No | Storage configuration (meta prefix). |
| `cron` | object | No | Scheduled background jobs. |
| `privacy` | object | No | GDPR/privacy declarations. |

---

## `app` (required)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Reverse-domain identifier. Must be globally unique. Example: `"com.example.my-seo-app"` |
| `name` | string | Yes | Human-readable name. Max 50 characters. |
| `version` | string | Yes | Semver version string. Example: `"1.0.0"` |
| `description` | string | Yes | One-line description. Max 200 characters. |
| `author` | object | Yes | `{ "name": string, "url"?: string, "email"?: string }` |
| `homepage` | string | No | Public URL for the app. |
| `repository` | string | No | Source code URL. |
| `license` | string | Yes | SPDX license identifier. Example: `"MIT"`, `"GPL-2.0-or-later"` |
| `icon` | string | No | URL to a square icon (PNG, SVG). |
| `screenshots` | string[] | No | URLs to screenshots. |
| `categories` | string[] | No | App store categories. Examples: `"seo"`, `"forms"`, `"analytics"`, `"contact"` |
| `tags` | string[] | No | Freeform tags for search. |

---

## `runtime` (required)

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `endpoint` | string | Yes | -- | Base URL where the app receives requests. |
| `health_check` | string | Yes | -- | Path for health check endpoint. Must return HTTP 200. |
| `auth_callback` | string | Yes | -- | Path to receive OAuth auth callback. |
| `webhook_path` | string | Yes | -- | Path to receive hook dispatches. |
| `timeout_ms` | integer | No | 5000 | Default request timeout in milliseconds. Max: 30000. |
| `max_payload_bytes` | integer | No | 1048576 (1 MB) | Max response payload size. Max: 5242880 (5 MB). |

---

## `requires` (optional)

| Field | Type | Description |
|-------|------|-------------|
| `wp_version` | string | Minimum WordPress version. Example: `">=6.5"` |
| `php_version` | string | Minimum PHP version. Example: `">=8.1"` |
| `apps_runtime_version` | string | Minimum Apps Runtime version. Example: `">=0.1.0"` |
| `rest_api` | boolean | Whether the REST API must be enabled. |
| `plugins` | string[] | Required WordPress plugins. Example: `["woocommerce"]` |
| `extensions` | string[] | Required runtime extensions. |

---

## `permissions` (required)

### `permissions.scopes` (required)

Array of scope strings. Format: `resource:action[:constraint]`

**Available scopes:**

| Scope | Description |
|-------|-------------|
| `posts:read` | Read all posts |
| `posts:read:published` | Read only published posts |
| `posts:write` | Create and update posts |
| `posts:delete` | Trash and permanently delete posts |
| `postmeta:read` | Read post meta (app's namespace only, auto-prefixed) |
| `postmeta:write` | Write post meta (app's namespace only, auto-prefixed) |
| `users:read:basic` | Read display name, email, role |
| `users:read:full` | Read full user profiles |
| `users:write` | Modify user profiles |
| `media:read` | Read media library |
| `media:write` | Upload and modify media |
| `comments:read` | Read comments |
| `comments:write` | Create and moderate comments |
| `taxonomies:read` | Read terms and taxonomies |
| `taxonomies:write` | Create and modify terms |
| `menus:read` | Read navigation menus |
| `menus:write` | Modify navigation menus |
| `site:read` | Read site settings (title, tagline, URL, language) |
| `site:write` | Modify site settings |
| `themes:read` | Read theme info and template structure |
| `plugins:read` | List installed plugins |
| `email:send` | Send emails via `wp_mail` |
| `cron:register` | Register cron jobs |
| `blocks:register` | Register custom blocks |
| `rest:extend` | Register custom REST endpoints under the app's namespace |

Broader scopes include narrower ones. `posts:write` includes `posts:read`. `users:write` includes `users:read:full` includes `users:read:basic`.

### `permissions.network` (optional)

```json
{
  "network": {
    "outbound": ["api.google.com", "api.bing.com"]
  }
}
```

Declares which external domains the app communicates with. Displayed to the admin during installation.

### `permissions.data_retention` (optional)

```json
{
  "data_retention": {
    "user_data": "session",
    "analytics": "30d"
  }
}
```

---

## `hooks` (optional)

### `hooks.events` -- Tier 1 (preferred)

Array of event webhook subscriptions. These fire asynchronously and never block page loads.

```json
{
  "hooks": {
    "events": [
      {
        "event": "save_post",
        "description": "Analyze content and write SEO score to post meta"
      },
      {
        "event": "transition_post_status",
        "description": "Submit sitemap update on publish"
      }
    ]
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `event` | string | Yes | WordPress event name. |
| `description` | string | No | Human-readable explanation shown during install. |
| `priority` | integer | No | Hook priority (default: 10). |

**Available events:** `save_post`, `delete_post`, `transition_post_status`, `add_attachment`, `edit_attachment`, `delete_attachment`, `user_register`, `profile_update`, `delete_user`, `wp_login`, `wp_logout`, `wp_insert_comment`, `edit_comment`, `delete_comment`, `transition_comment_status`, `created_term`, `edited_term`, `delete_term`.

### `hooks.filters` -- Tier 2 (discouraged)

Runtime filter subscriptions. These add latency to page loads. Use only when Tier 1 patterns are insufficient.

```json
{
  "hooks": {
    "filters": [
      {
        "hook": "wp_head",
        "priority": 10
      }
    ]
  }
}
```

Available render-path filters (adds page-load latency): `wp_head`, `wp_footer`, `document_title_parts`.

Filters `the_content`, `the_title`, `the_excerpt` are available but **strongly discouraged**. The runtime displays a performance warning if an app subscribes to `the_content`. Use blocks instead.

---

## `postmeta` (optional)

Declares post meta keys the app writes. Used for documentation and auto-rendering in `wp_head`.

```json
{
  "postmeta": {
    "seo_title": "SEO title override -- rendered in <title> by the runtime",
    "seo_description": "Meta description -- rendered in <meta> by the runtime",
    "seo_score": "SEO score (0-100) -- displayed in admin column",
    "schema_json": "JSON-LD schema markup -- injected into wp_head by the runtime"
  }
}
```

Keys are auto-prefixed with `_{app_id_slug}_` at the API level. The runtime's `MetaRenderer` automatically renders known meta suffixes in `wp_head`:

| Suffix | Rendered as |
|--------|-------------|
| `seo_title` | `<title>` tag override |
| `seo_description` | `<meta name="description">` |
| `schema_json` | `<script type="application/ld+json">` |
| `og_title` | `<meta property="og:title">` |
| `og_description` | `<meta property="og:description">` |
| `og_image` | `<meta property="og:image">` |
| `canonical_url` | `<link rel="canonical">` |
| `robots` | `<meta name="robots">` |

---

## `surfaces` (optional)

### `surfaces.blocks`

```json
{
  "surfaces": {
    "blocks": [
      {
        "name": "my-seo-app/faq-schema",
        "title": "FAQ (with Schema)",
        "category": "widgets",
        "description": "FAQ block with automatic schema.org markup",
        "render_mode": "structured",
        "cache_ttl": 3600
      }
    ]
  }
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | -- | Block name in `namespace/slug` format. |
| `title` | string | Yes | -- | Human-readable title in the block inserter. |
| `category` | string | No | `"widgets"` | Block editor category. |
| `description` | string | No | -- | Block description. |
| `render_mode` | string | No | `"structured"` | `"structured"` or `"iframe"`. |
| `icon` | string | No | `"grid-view"` | Dashicons slug. |
| `cache_ttl` | integer | No | 3600 | Cache duration in seconds. Max: 86400 (24h). |

### `surfaces.admin_pages`

```json
{
  "surfaces": {
    "admin_pages": [
      {
        "slug": "seo-dashboard",
        "title": "SEO Dashboard",
        "menu_location": "tools",
        "icon": "dashicons-chart-area",
        "capability": "manage_options",
        "render_mode": "iframe"
      }
    ]
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | string | Yes | URL slug. |
| `title` | string | Yes | Menu item title. |
| `menu_location` | string | Yes | Where in admin nav: `"tools"`, `"settings"`, `"toplevel"`. |
| `icon` | string | No | Dashicons slug. |
| `capability` | string | No | Required WordPress capability. Default: `"manage_options"`. |
| `parent` | string | No | Parent menu slug (for submenu items). |
| `render_mode` | string | Yes | `"structured"` or `"iframe"`. |

### `surfaces.meta_boxes`

```json
{
  "surfaces": {
    "meta_boxes": [
      {
        "id": "seo-post-settings",
        "title": "SEO Settings",
        "screen": ["post", "page"],
        "context": "side",
        "priority": "high",
        "render_mode": "structured"
      }
    ]
  }
}
```

### `surfaces.dashboard_widgets`

```json
{
  "surfaces": {
    "dashboard_widgets": [
      {
        "id": "seo-overview",
        "title": "SEO Overview",
        "render_mode": "structured"
      }
    ]
  }
}
```

### `surfaces.admin_bar`

```json
{
  "surfaces": {
    "admin_bar": [
      {
        "id": "seo-score",
        "title": "SEO: --/100",
        "parent": null
      }
    ]
  }
}
```

---

## `storage` (optional)

```json
{
  "storage": {
    "postmeta_prefix": "my_seo_"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `postmeta_prefix` | string | Custom prefix for post meta keys. If omitted, derived from `app.id`. |

---

## `cron` (optional)

```json
{
  "cron": {
    "jobs": [
      {
        "name": "daily_seo_audit",
        "schedule": "daily",
        "endpoint": "/cron/daily-audit",
        "timeout_ms": 30000,
        "description": "Run daily SEO audit across all published posts"
      }
    ]
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Job identifier. |
| `schedule` | string | Yes | `"hourly"`, `"twicedaily"`, `"daily"`, `"weekly"`, or a cron expression (e.g., `"0 3 * * 1"`). |
| `endpoint` | string | Yes | Path on the app server to call. |
| `timeout_ms` | integer | No | Job timeout. Default: 30000. |
| `description` | string | No | Human-readable description. |

---

## `privacy` (optional)

```json
{
  "privacy": {
    "data_collected": [
      {"type": "name", "purpose": "Form submission identification", "retention": "90d"},
      {"type": "email", "purpose": "Reply to contact form", "retention": "90d"},
      {"type": "ip_address", "purpose": "Spam prevention", "retention": "30d"}
    ],
    "data_shared_with": ["No third parties"],
    "privacy_policy_url": "https://my-app.example.com/privacy"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `data_collected` | array | Each entry: `{ "type": string, "purpose": string, "retention": string }`. Retention uses duration format: `"30d"`, `"90d"`, `"1y"`, `"session"`. |
| `data_shared_with` | string[] | Third parties data is shared with. |
| `privacy_policy_url` | string | Link to the app's privacy policy. |

The runtime surfaces this information during installation and integrates with WordPress's privacy tools (data export, data erasure).

---

## Example Manifests

### SEO App

```json
{
  "spec_version": "0.1.0",
  "app": {
    "id": "com.example.my-seo-app",
    "name": "My SEO App",
    "version": "1.0.0",
    "description": "Automatic SEO optimization for posts and pages.",
    "author": { "name": "Example Corp", "url": "https://example.com" },
    "license": "GPL-2.0-or-later",
    "categories": ["seo", "content"]
  },
  "runtime": {
    "endpoint": "https://my-seo-app.example.com/wp-app",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks"
  },
  "permissions": {
    "scopes": ["posts:read", "posts:write", "postmeta:read", "postmeta:write", "media:read"],
    "network": { "outbound": ["api.google.com"] }
  },
  "hooks": {
    "events": [
      { "event": "save_post", "description": "Analyze content and generate SEO score" },
      { "event": "transition_post_status", "description": "Submit sitemap update on publish" }
    ]
  },
  "postmeta": {
    "seo_title": "SEO title override",
    "seo_description": "Meta description",
    "seo_score": "SEO score (0-100)",
    "schema_json": "JSON-LD schema markup"
  },
  "surfaces": {
    "meta_boxes": [
      { "id": "seo-post-settings", "title": "SEO Settings", "screen": ["post", "page"], "context": "side", "render_mode": "structured" }
    ],
    "admin_pages": [
      { "slug": "seo-dashboard", "title": "SEO Dashboard", "menu_location": "tools", "render_mode": "iframe" }
    ]
  },
  "cron": {
    "jobs": [
      { "name": "daily_seo_audit", "schedule": "daily", "endpoint": "/cron/daily-audit", "timeout_ms": 30000 }
    ]
  }
}
```

### Contact Form App

```json
{
  "spec_version": "0.1.0",
  "app": {
    "id": "com.wpapps.contact-form",
    "name": "WP Apps Contact Form",
    "version": "1.0.0",
    "description": "A simple contact form that stores submissions and sends email notifications.",
    "author": { "name": "WP Apps Team", "url": "https://wp-apps.org" },
    "license": "MIT",
    "categories": ["forms", "contact"]
  },
  "runtime": {
    "endpoint": "https://contact-form-app.example.com",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks"
  },
  "permissions": {
    "scopes": ["posts:read", "site:read"]
  },
  "surfaces": {
    "blocks": [
      { "name": "wpapps/contact-form", "title": "Contact Form", "category": "widgets", "cache_ttl": 86400 }
    ],
    "admin_pages": [
      { "slug": "contact-submissions", "title": "Contact Submissions", "menu_location": "tools", "render_mode": "iframe" }
    ]
  },
  "privacy": {
    "data_collected": [
      {"type": "name", "purpose": "Form submission identification", "retention": "90d"},
      {"type": "email", "purpose": "Reply to contact form", "retention": "90d"},
      {"type": "ip_address", "purpose": "Spam prevention", "retention": "30d"}
    ],
    "data_shared_with": ["No third parties"]
  }
}
```

### Analytics App

```json
{
  "spec_version": "0.1.0",
  "app": {
    "id": "com.example.simple-analytics",
    "name": "Simple Analytics",
    "version": "1.0.0",
    "description": "Lightweight analytics with a privacy-first approach.",
    "author": { "name": "Analytics Co" },
    "license": "MIT",
    "categories": ["analytics"]
  },
  "runtime": {
    "endpoint": "https://analytics-app.example.com",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks"
  },
  "permissions": {
    "scopes": ["posts:read:published", "site:read"]
  },
  "hooks": {
    "events": [
      { "event": "save_post", "description": "Index new post for analytics tracking" }
    ]
  },
  "surfaces": {
    "admin_pages": [
      { "slug": "analytics-dashboard", "title": "Analytics", "menu_location": "toplevel", "icon": "dashicons-chart-bar", "render_mode": "iframe" }
    ],
    "dashboard_widgets": [
      { "id": "analytics-overview", "title": "Site Analytics", "render_mode": "structured" }
    ]
  },
  "privacy": {
    "data_collected": [
      {"type": "page_views", "purpose": "Analytics", "retention": "1y"},
      {"type": "referrer", "purpose": "Traffic source analysis", "retention": "1y"}
    ],
    "data_shared_with": ["No third parties"],
    "privacy_policy_url": "https://analytics-app.example.com/privacy"
  }
}
```
