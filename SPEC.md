# WordPress Apps Specification

**Version:** 0.1.0-draft
**Status:** Draft
**Authors:** Vikas Suspended (InstaWP), Contributors Welcome
**Date:** April 2026
**License:** MIT

---

## Abstract

WordPress Apps is an open specification for building sandboxed, permission-scoped extensions for WordPress. Unlike traditional WordPress plugins — which execute arbitrary PHP inside the WordPress runtime with full access to the database, filesystem, and global state — WordPress Apps run as isolated services that interact with WordPress exclusively through a structured API protocol.

This specification defines the manifest format, communication protocol, permission model, hook subscription system, UI integration method, data storage scope, authentication flow, and lifecycle management for WordPress Apps.

The goal is to bring WordPress extension security and reliability to the level that Shopify, Stripe, and Notion have achieved — without abandoning the openness that makes WordPress what it is.

---

## Table of Contents

1. [Motivation](#1-motivation)
2. [Architecture Overview](#2-architecture-overview)
3. [Terminology](#3-terminology)
4. [App Manifest](#4-app-manifest)
5. [Authentication & Authorization](#5-authentication--authorization)
6. [Permissions Model](#6-permissions-model)
7. [WordPress Apps API](#7-wordpress-apps-api)
8. [Hook Subscriptions](#8-hook-subscriptions)
9. [UI Integration](#9-ui-integration)
10. [Data Storage](#10-data-storage)
11. [Background Jobs & Cron](#11-background-jobs--cron)
12. [App Lifecycle](#12-app-lifecycle)
13. [App Distribution](#13-app-distribution)
14. [Host Requirements](#14-host-requirements)
15. [Migration Path for Existing Plugins](#15-migration-path-for-existing-plugins)
16. [Security Considerations](#16-security-considerations)
17. [Reference Implementation](#17-reference-implementation)

---

## 1. Motivation

### The Problem

WordPress plugins execute as trusted code inside the WordPress PHP runtime. A plugin has:

- **Full database access** — can read, write, or drop any table, including `wp_users` and `wp_options`
- **Full filesystem access** — can read `wp-config.php`, modify core files, write to any directory
- **Full network access** — can make arbitrary HTTP requests, exfiltrate data, participate in botnets
- **Full runtime access** — can redefine functions, modify globals, interfere with other plugins, crash the process
- **No resource limits** — can consume unlimited CPU, memory, and disk, degrading the entire site

This model has real consequences:

- **Security**: A single vulnerable plugin compromises the entire site. Plugin vulnerabilities are the #1 attack vector for WordPress sites.
- **Stability**: A poorly-coded plugin can crash the entire site, corrupt the database, or create infinite loops. Site operators managing hundreds of sites (agencies, hosts) live in constant fear of plugin updates.
- **Accountability**: There is no audit trail of what a plugin does. No way to know if a plugin is reading user passwords, sending data to third parties, or mining cryptocurrency.
- **Scalability**: Plugins that run in-process cannot be independently scaled, monitored, or resource-limited.

### The Shopify Model

Shopify solved this problem from day one by never allowing apps to run inside Shopify's runtime:

- Apps are external HTTP services
- They communicate with Shopify via REST/GraphQL APIs
- They get scoped OAuth tokens with explicit permissions
- They render UI in iframes (now App Bridge)
- They have zero access to Shopify's database, filesystem, or runtime

The result: Shopify's app ecosystem is dramatically more secure and stable than WordPress's plugin ecosystem, despite being much larger in transaction volume.

### Our Approach

WordPress Apps brings the Shopify model to WordPress while preserving WordPress's openness:

- **Apps are external services** — they run anywhere (a cloud server, a container, a serverless function, even a separate process on the same machine)
- **Apps communicate via a structured protocol** — a superset of REST API with hook subscriptions
- **Apps get scoped tokens** — they declare required permissions, the site admin approves them
- **Apps render UI through a bridge** — structured responses for admin panels, block editor integration via standard block registration
- **The spec is open** — anyone can implement a WordPress Apps host. InstaWP will be the first, but shared hosting providers, managed hosts, and self-hosters can all adopt it
- **Migration is incremental** — existing plugins can be wrapped as Apps using a compatibility shim

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                  WordPress Site                      │
│                                                      │
│  ┌──────────────┐  ┌─────────────────────────────┐  │
│  │  WP Core     │  │  Apps Runtime (mu-plugin)    │  │
│  │              │  │                               │  │
│  │  ┌────────┐  │  │  ┌─────────────────────────┐ │  │
│  │  │ REST   │◄─┼──┼──│  API Gateway            │ │  │
│  │  │ API    │  │  │  │  - Auth verification     │ │  │
│  │  │        │  │  │  │  - Permission enforcement│ │  │
│  │  └────────┘  │  │  │  - Rate limiting         │ │  │
│  │              │  │  │  - Audit logging          │ │  │
│  │  ┌────────┐  │  │  └──────────┬──────────────┘ │  │
│  │  │ Hooks  │──┼──┼─────────────┤                │  │
│  │  │ System │  │  │  ┌──────────▼──────────────┐ │  │
│  │  └────────┘  │  │  │  Hook Dispatcher         │ │  │
│  │              │  │  │  - Serializes context     │ │  │
│  │              │  │  │  - Calls app endpoints    │ │  │
│  │              │  │  │  - Merges responses       │ │  │
│  │              │  │  │  - Enforces timeouts      │ │  │
│  │              │  │  └─────────────────────────┘ │  │
│  └──────────────┘  └─────────────────────────────┘  │
│                                                      │
└──────────────────────┬──────────────────────────────┘
                       │
          HTTP/HTTPS (structured protocol)
                       │
    ┌──────────────────┼──────────────────────┐
    │                  │                      │
    ▼                  ▼                      ▼
┌─────────┐    ┌─────────────┐    ┌──────────────────┐
│  App A  │    │   App B     │    │     App C        │
│ (Cloud) │    │ (Container) │    │ (Same Server,    │
│         │    │             │    │  Separate Process)│
└─────────┘    └─────────────┘    └──────────────────┘
```

### Key Principles

1. **Zero Trust**: Apps have no implicit access to anything. Every capability must be declared and approved.
2. **API-Only**: Apps interact with WordPress exclusively through the Apps API. No direct database queries, no filesystem access, no PHP runtime sharing.
3. **Declarative Permissions**: Apps declare what they need in a manifest. The site admin sees and approves these permissions before installation.
4. **Structured Communication**: All communication follows a defined protocol with typed requests and responses. No arbitrary PHP execution.
5. **Resource Bounded**: Every app interaction has timeouts, payload limits, and rate limits. An app cannot degrade the host site's performance.
6. **Zero Runtime Cost by Default**: Apps should add zero overhead to frontend page loads. The primary integration model is data-first: apps write data via the API, WordPress renders it. Runtime hooks (filters that fire on every page load) are an opt-in escape hatch, not the default pattern.
7. **Auditable**: Every API call, hook invocation, and data access is logged. Site admins can see exactly what each app does.
8. **Portable**: Apps run anywhere that can serve HTTP. The spec does not mandate a specific hosting environment.
9. **Backwards Compatible**: The spec works alongside traditional plugins. Sites can run a mix of plugins and Apps.

---

## 3. Terminology

| Term | Definition |
|------|------------|
| **App** | An external service that extends WordPress functionality through the Apps protocol |
| **App Host** | The server/runtime where the App runs (cloud, container, serverless, etc.) |
| **Site** | A WordPress installation that has the Apps Runtime installed |
| **Apps Runtime** | The WordPress-side component (mu-plugin) that manages Apps, enforces permissions, and dispatches hooks |
| **Manifest** | A JSON file (`wp-app.json`) declaring the App's identity, permissions, hooks, and capabilities |
| **App Token** | A scoped bearer token issued to the App after installation and authorization |
| **Hook Subscription** | A declaration that the App wants to be notified when a specific WordPress hook fires |
| **App Surface** | A UI integration point where the App can render content (admin panel, block, settings page, etc.) |
| **Scope** | A granular permission unit (e.g., `posts:read`, `users:write`) |

---

## 4. App Manifest

Every WordPress App must include a `wp-app.json` manifest file at its root. This file is the single source of truth for the App's identity, requirements, and capabilities.

### 4.1 Manifest Schema

```json
{
  "$schema": "https://wordpress-apps.org/schema/v1/manifest.json",
  "spec_version": "0.1.0",

  "app": {
    "id": "com.example.my-seo-app",
    "name": "My SEO App",
    "version": "1.0.0",
    "description": "Automatic SEO optimization for posts and pages.",
    "author": {
      "name": "Example Corp",
      "url": "https://example.com",
      "email": "apps@example.com"
    },
    "homepage": "https://example.com/my-seo-app",
    "repository": "https://github.com/example/my-seo-app",
    "license": "GPL-2.0-or-later",
    "icon": "https://example.com/my-seo-app/icon.png",
    "screenshots": [
      "https://example.com/my-seo-app/screenshot-1.png"
    ],
    "categories": ["seo", "content"],
    "tags": ["meta-tags", "sitemap", "schema-markup"]
  },

  "runtime": {
    "endpoint": "https://my-seo-app.example.com/wp-app",
    "health_check": "/health",
    "auth_callback": "/auth/callback",
    "webhook_path": "/hooks",
    "timeout_ms": 5000,
    "max_payload_bytes": 1048576
  },

  "requires": {
    "wp_version": ">=6.5",
    "php_version": ">=8.1",
    "apps_runtime_version": ">=0.1.0",
    "rest_api": true,
    "plugins": ["woocommerce"],
    "extensions": []
  },

  "permissions": {
    "scopes": [
      "posts:read",
      "posts:write",
      "postmeta:read",
      "postmeta:write",
      "media:read",
      "users:read:basic"
    ],
    "network": {
      "outbound": [
        "api.google.com",
        "api.bing.com"
      ]
    },
    "data_retention": {
      "user_data": "session",
      "analytics": "30d"
    }
  },

  "hooks": {
    "events": [
      {
        "event": "save_post",
        "description": "Analyze content, generate SEO score, and write results to post meta"
      },
      {
        "event": "transition_post_status",
        "description": "Submit sitemap update when post is published"
      }
    ]
  },

  "postmeta": {
    "seo_title": "SEO title override — rendered in <title> by the runtime's wp_head handler",
    "seo_description": "Meta description — rendered in <meta> by the runtime",
    "seo_score": "SEO score (0-100) — displayed in admin column",
    "schema_json": "JSON-LD schema markup — injected into wp_head by the runtime"
  },

  "surfaces": {
    "admin_pages": [
      {
        "slug": "seo-dashboard",
        "title": "SEO Dashboard",
        "menu_location": "tools",
        "icon": "dashicons-chart-area",
        "capability": "manage_options",
        "render_mode": "iframe"
      },
      {
        "slug": "seo-settings",
        "title": "SEO Settings",
        "menu_location": "settings",
        "parent": "options-general.php",
        "capability": "manage_options",
        "render_mode": "structured"
      }
    ],
    "meta_boxes": [
      {
        "id": "seo-post-settings",
        "title": "SEO Settings",
        "screen": ["post", "page"],
        "context": "side",
        "priority": "high",
        "render_mode": "structured"
      }
    ],
    "blocks": [
      {
        "name": "my-seo-app/faq-schema",
        "title": "FAQ (with Schema)",
        "category": "widgets",
        "description": "FAQ block with automatic schema.org markup",
        "render_mode": "structured"
      }
    ],
    "dashboard_widgets": [
      {
        "id": "seo-overview",
        "title": "SEO Overview",
        "render_mode": "structured"
      }
    ],
    "admin_bar": [
      {
        "id": "seo-score",
        "title": "SEO: --/100",
        "parent": null
      }
    ]
  },

  "storage": {
    "postmeta_prefix": "my_seo_"
  },

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

### 4.2 Manifest Field Reference

#### `app` (required)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Reverse-domain identifier. Must be globally unique. |
| `name` | string | Yes | Human-readable name (max 50 chars) |
| `version` | string | Yes | Semver version string |
| `description` | string | Yes | One-line description (max 200 chars) |
| `author` | object | Yes | Author name, URL, email |
| `license` | string | Yes | SPDX license identifier |
| `categories` | array | No | App store categories |

#### `runtime` (required)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `endpoint` | string | Yes | Base URL where the App receives requests |
| `health_check` | string | Yes | Path for health check (must return 200) |
| `auth_callback` | string | Yes | Path to receive OAuth callback |
| `webhook_path` | string | Yes | Path to receive hook dispatches |
| `timeout_ms` | integer | No | Default timeout for all requests (max 30000, default 5000) |
| `max_payload_bytes` | integer | No | Max response payload size (max 5MB, default 1MB) |

#### `permissions.scopes` (required)

See [Section 6: Permissions Model](#6-permissions-model) for the full list of available scopes.

#### `hooks` (optional)

See [Section 8: Hook Subscriptions](#8-hook-subscriptions) for the dispatch protocol.

#### `surfaces` (optional)

See [Section 9: UI Integration](#9-ui-integration) for rendering modes.

---

## 5. Authentication & Authorization

WordPress Apps uses an OAuth 2.0-based flow, simplified for the WordPress context.

### 5.1 Installation Flow

```
Site Admin                  WordPress Site              App Server
    │                           │                           │
    │  1. Install App           │                           │
    │  (provides manifest URL)  │                           │
    │ ─────────────────────────►│                           │
    │                           │  2. Fetch wp-app.json     │
    │                           │ ─────────────────────────►│
    │                           │  3. Return manifest       │
    │                           │ ◄─────────────────────────│
    │  4. Show permissions      │                           │
    │     consent screen        │                           │
    │ ◄─────────────────────────│                           │
    │                           │                           │
    │  5. Admin approves        │                           │
    │ ─────────────────────────►│                           │
    │                           │  6. POST /auth/callback   │
    │                           │     {                     │
    │                           │       site_url,           │
    │                           │       site_id,            │
    │                           │       auth_code,          │
    │                           │       scopes_granted      │
    │                           │     }                     │
    │                           │ ─────────────────────────►│
    │                           │                           │
    │                           │  7. App exchanges code    │
    │                           │     for token pair        │
    │                           │     POST /wp-json/apps/   │
    │                           │       v1/token            │
    │                           │ ◄─────────────────────────│
    │                           │                           │
    │                           │  8. Return tokens         │
    │                           │     {                     │
    │                           │       access_token,       │
    │                           │       refresh_token,      │
    │                           │       expires_in,         │
    │                           │       scopes              │
    │                           │     }                     │
    │                           │ ─────────────────────────►│
    │                           │                           │
    │  9. App installed ✓       │                           │
    │ ◄─────────────────────────│                           │
```

### 5.2 Token Structure

**Access Token** (short-lived, 1 hour):
```json
{
  "token_type": "wp_app",
  "app_id": "com.example.my-seo-app",
  "site_id": "a1b2c3d4",
  "scopes": ["posts:read", "posts:write", "postmeta:read"],
  "iat": 1714000000,
  "exp": 1714003600
}
```

**Refresh Token** (long-lived, 90 days, rotated on use):
Used to obtain new access tokens without re-authorization.

### 5.3 Request Authentication

All API requests from the App to WordPress must include:

```http
GET /wp-json/apps/v1/posts?status=publish
Host: example.com
Authorization: Bearer <access_token>
X-App-Id: com.example.my-seo-app
X-Request-Id: uuid-v4
```

### 5.4 Webhook Authentication (WordPress → App)

When WordPress dispatches hooks to the App, requests are signed:

```http
POST /hooks
Host: my-seo-app.example.com
Content-Type: application/json
X-WP-Apps-Signature: sha256=<HMAC-SHA256 of body using shared secret>
X-WP-Apps-Site-Id: a1b2c3d4
X-WP-Apps-Timestamp: 1714000000
X-WP-Apps-Hook: save_post
X-WP-Apps-Delivery-Id: uuid-v4
```

Apps MUST validate the signature and reject requests older than 5 minutes (to prevent replay attacks).

---

## 6. Permissions Model

### 6.1 Scope Format

Scopes follow the pattern: `resource:action[:constraint]`

```
posts:read              — Read all posts
posts:read:published    — Read only published posts
posts:write             — Create and update posts
posts:delete            — Delete posts (trash + permanent)
postmeta:read           — Read post meta (app's own namespace, auto-prefixed)
postmeta:write          — Write post meta (app's own namespace, auto-prefixed)
users:read:basic        — Read user display name, email, role
users:read:full         — Read full user profiles
users:write             — Modify user profiles
media:read              — Read media library
media:write             — Upload and modify media
comments:read           — Read comments
comments:write          — Create and moderate comments
taxonomies:read         — Read terms and taxonomies
taxonomies:write        — Create and modify terms
menus:read              — Read navigation menus
menus:write             — Modify navigation menus
site:read               — Read site settings (title, tagline, etc.)
site:write              — Modify site settings
themes:read             — Read theme info and template structure
plugins:read            — List installed plugins
email:send              — Send emails via wp_mail
cron:register           — Register cron jobs
blocks:register         — Register custom blocks
rest:extend             — Register custom REST endpoints under the app's namespace
```

### 6.2 Scope Hierarchy

Broader scopes include narrower ones:

```
posts:write  →  includes  →  posts:read
users:write  →  includes  →  users:read:full  →  includes  →  users:read:basic
```

### 6.3 Never-Grantable Capabilities

The following capabilities are NEVER available to Apps:

- Direct database queries (`$wpdb`)
- Filesystem access outside app-scoped storage
- PHP code execution (`eval`, `create_function`)
- WordPress core file modification
- Plugin/theme installation or modification
- User password access or modification
- Capability/role modification
- Network/multisite super admin actions
- `wp-config.php` access
- Raw SQL execution

### 6.4 Runtime Enforcement

The Apps Runtime enforces permissions at the API gateway level:

```php
// Pseudocode — Apps Runtime permission check
function handle_app_request($request) {
    $app = authenticate_app($request);
    $scope_required = map_endpoint_to_scope($request->get_route());

    if (!$app->has_scope($scope_required)) {
        return new WP_Error(
            'insufficient_scope',
            "This action requires the '{$scope_required}' permission.",
            ['status' => 403]
        );
    }

    // Apply scope constraints (e.g., options:read:prefix_*)
    $constraints = $app->get_scope_constraints($scope_required);
    $request->set_param('_app_constraints', $constraints);

    return forward_to_handler($request);
}
```

---

## 7. WordPress Apps API

The Apps API is a superset of the WordPress REST API, with additional endpoints and enhanced behavior for Apps.

### 7.1 Base URL

```
https://example.com/wp-json/apps/v1/
```

### 7.2 Core Endpoints

All standard WP REST API resources are available under the `/apps/v1/` namespace, with permission scoping applied automatically. Apps never use `/wp/v2/` directly.

#### Posts

```
GET    /apps/v1/posts                    — List posts (scoped)
GET    /apps/v1/posts/{id}               — Get single post
POST   /apps/v1/posts                    — Create post
PUT    /apps/v1/posts/{id}               — Update post
DELETE /apps/v1/posts/{id}               — Trash/delete post
GET    /apps/v1/posts/{id}/meta          — Get post meta
PUT    /apps/v1/posts/{id}/meta/{key}    — Set post meta
DELETE /apps/v1/posts/{id}/meta/{key}    — Delete post meta
```

#### Users (restricted by scope)

```
GET    /apps/v1/users                    — List users (fields limited by scope)
GET    /apps/v1/users/{id}               — Get single user
GET    /apps/v1/users/me                 — Get current acting user
```

#### Media

```
GET    /apps/v1/media                    — List media
GET    /apps/v1/media/{id}               — Get media item
POST   /apps/v1/media                    — Upload media
```

#### Site Info

```
GET    /apps/v1/site                     — Read site settings (title, tagline, URL, language)
```

#### App-Registered REST Endpoints

Apps with the `rest:extend` scope can register custom endpoints under their namespace:

```
GET/POST/PUT/DELETE  /apps/v1/ext/{app-id}/{custom-path}
```

The app defines the handler for these endpoints; the Apps Runtime proxies requests to the app's endpoint with the original parameters.

### 7.3 Query Filtering

```
GET /apps/v1/posts?status=publish&per_page=20&orderby=date&order=desc
GET /apps/v1/posts?meta_key=my_seo_score&meta_value=90&meta_compare=>=
GET /apps/v1/posts?after=2025-01-01T00:00:00Z&categories=5,12
```

### 7.4 Pagination

Standard WordPress REST API pagination:

```http
X-WP-Total: 42
X-WP-TotalPages: 3
Link: <https://example.com/wp-json/apps/v1/posts?page=2>; rel="next"
```

### 7.5 Rate Limiting

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 847
X-RateLimit-Reset: 1714003600
```

Default limits:
- **Read operations**: 1000 requests / hour
- **Write operations**: 200 requests / hour
- **Bulk operations**: 50 requests / hour
- **Hook responses**: Must return within declared `timeout_ms`

### 7.6 Error Responses

```json
{
  "code": "insufficient_scope",
  "message": "This action requires the 'users:write' permission.",
  "data": {
    "status": 403,
    "required_scope": "users:write",
    "app_id": "com.example.my-seo-app"
  }
}
```

Standard error codes: `invalid_token`, `expired_token`, `insufficient_scope`, `rate_limited`, `payload_too_large`, `timeout`, `invalid_request`, `not_found`, `conflict`, `internal_error`.

---

## 8. Integration Model

Apps integrate with WordPress through two tiers, ordered by preference:

### Tier 1: Data-First (Zero Runtime Cost) — PREFERRED

Apps write data to WordPress via the API. WordPress renders it. No HTTP calls during page loads.

| Pattern | How it works | Example |
|---------|-------------|---------|
| **Post Meta** | App writes data via `PUT /apps/v1/posts/{id}/meta/`. Theme or `wp_head` reads and renders it. | SEO title, meta description, Open Graph tags, content scores |
| **Blocks** | App registers a block in the manifest. Admin places it via block editor. Runtime renders block by calling the app only at save/edit time, caching the output. | Contact form, pricing table, FAQ widget |
| **Event Webhooks** | App subscribes to `save_post`, `user_register`, etc. Runtime fires the webhook asynchronously — no page load cost. | Content analysis on save, sync to external CRM, Slack notification on publish |

**Why this is preferred:** Zero overhead on frontend page loads. A site with 20 apps installed loads at the same speed as a site with zero apps. Data is written once (at edit/save time) and read from WordPress's own cache layer on every page load.

### Tier 2: Runtime Hooks (Escape Hatch) — USE SPARINGLY

For the rare case where an app genuinely needs to modify content at render time, the runtime hook system is available. This adds an HTTP round-trip per subscribed app per page load, so it should be used sparingly and with aggressive caching.

**When to use Tier 2:**
- Injecting dynamic, uncacheable content (user-specific personalization)
- `wp_head` / `wp_footer` injection where post meta isn't sufficient (rare)
- Content transformations that depend on request context (locale, device, etc.)

**When NOT to use Tier 2:**
- Injecting static UI components (use blocks)
- Adding meta tags, schema, OG tags (use post meta + `wp_head` data reader)
- Anything that can be computed at save time and cached (use event webhooks + post meta)

### 8.1 Event Webhooks (Tier 1)

Apps subscribe to WordPress events. The runtime fires webhooks asynchronously after the event occurs. These NEVER block page loads.

**Available events:**

| Event | When it fires |
|-------|--------------|
| `save_post` | Post is created or updated |
| `delete_post` | Post is trashed or deleted |
| `transition_post_status` | Post status changes (draft→publish, etc.) |
| `add_attachment` / `edit_attachment` / `delete_attachment` | Media changes |
| `user_register` / `profile_update` / `delete_user` | User changes |
| `wp_login` / `wp_logout` | Authentication events |
| `wp_insert_comment` / `edit_comment` / `delete_comment` | Comment changes |
| `transition_comment_status` | Comment moderation |
| `created_term` / `edited_term` / `delete_term` | Taxonomy changes |

All event webhooks are **async fire-and-forget**. The app processes the event in the background and uses its API token to write any results back to WordPress (e.g., storing an SEO score as post meta after analyzing a post).

### 8.2 Runtime Hooks (Tier 2)

The hook system allows Apps to participate in WordPress's action and filter pipeline without running code inside the WordPress process. **This adds latency to page loads and should only be used when Tier 1 patterns are insufficient.**

### 8.1 Hook Dispatch Flow

```
WordPress fires hook
        │
        ▼
Apps Runtime intercepts
        │
        ├─ Serialize context to JSON
        │
        ├─ For each subscribed App (sorted by priority):
        │     │
        │     ├─ POST to App's webhook_path
        │     │   {
        │     │     "hook": "the_content",
        │     │     "type": "filter",
        │     │     "args": ["<p>Hello World</p>"],
        │     │     "context": {
        │     │       "post_id": 42,
        │     │       "post_type": "post",
        │     │       "user_id": 1,
        │     │       "request_uri": "/hello-world/"
        │     │     }
        │     │   }
        │     │
        │     ├─ Wait for response (up to timeout_ms)
        │     │
        │     ├─ If filter: merge returned value
        │     │  If action (sync): acknowledge
        │     │  If action (async): fire-and-forget
        │     │
        │     └─ If timeout: skip App, log warning
        │
        └─ Return final value to WordPress
```

### 8.2 Hook Request Format

**WordPress → App:**

```json
{
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "hook": "the_content",
  "type": "filter",
  "priority": 10,
  "args": ["<p>Hello World</p>"],
  "context": {
    "post_id": 42,
    "post_type": "post",
    "post_status": "publish",
    "user_id": 1,
    "user_role": "administrator",
    "is_admin": false,
    "is_rest": false,
    "request_uri": "/hello-world/",
    "locale": "en_US",
    "timestamp": 1714000000
  },
  "site": {
    "url": "https://example.com",
    "id": "a1b2c3d4"
  }
}
```

### 8.3 Hook Response Format

**App → WordPress (for filters):**

```json
{
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "ok",
  "result": "<p>Hello World</p>\n<!-- SEO schema markup -->\n<script type=\"application/ld+json\">...</script>"
}
```

**App → WordPress (for actions):**

```json
{
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "ok"
}
```

**Error response:**

```json
{
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "error",
  "error": {
    "code": "analysis_failed",
    "message": "Could not analyze post content"
  }
}
```

### 8.4 Async Actions

Actions marked `"async": true` in the manifest use fire-and-forget delivery:

- WordPress sends the hook and does NOT wait for a response
- The App processes it in the background
- If the App needs to update WordPress data, it makes API calls using its access token
- Ideal for expensive operations (content analysis, external API calls, notifications)

### 8.5 Hook Performance Rules

| Hook Type | Max Timeout | Behavior on Timeout |
|-----------|-------------|-------------------|
| Filter (render path) | 2000ms | Skip app, use unmodified value |
| Filter (admin) | 5000ms | Skip app, use unmodified value |
| Action (sync) | 5000ms | Log warning, continue |
| Action (async) | N/A | Fire and forget |
| save_post / transition_post_status | 10000ms | Log warning, continue |

### 8.6 Available Runtime Hooks

Not all WordPress hooks are available for runtime subscription. The following are available but should be used sparingly — prefer Tier 1 patterns instead.

**Render-path filters (adds latency to page loads — use only when necessary):**
`wp_head`, `wp_footer`, `document_title_parts`

Note: `the_content`, `the_title`, `the_excerpt`, `body_class`, `post_class` are available but **strongly discouraged**. Use blocks for UI injection and post meta for data injection instead. If an app subscribes to `the_content`, the runtime will display a performance warning in the admin.

**Admin-only filters (no frontend cost):**
`admin_notices`, `dashboard_glance_items`, `rest_pre_dispatch`, `rest_post_dispatch`

**Event webhooks (async, zero page-load cost — preferred):**
See section 8.1 for the full list.

Additional hooks can be exposed by the Apps Runtime through configuration. Plugin-generated hooks (e.g., WooCommerce hooks) can be registered by the host if the corresponding plugin's API bridge is installed.

### 8.7 WooCommerce Hook Extension (example)

When WooCommerce is active, the Apps Runtime can expose additional hooks:

```json
{
  "hooks": {
    "actions": [
      {
        "hook": "woocommerce_new_order",
        "priority": 10,
        "async": true
      },
      {
        "hook": "woocommerce_order_status_changed",
        "priority": 10,
        "async": true
      }
    ]
  }
}
```

This requires a "WooCommerce Bridge" extension for the Apps Runtime.

---

## 9. UI Integration

Apps can render UI in WordPress through two modes: **Structured** and **Iframe**.

### 9.1 Structured Mode (Recommended)

The App returns a JSON UI description using a component schema. The Apps Runtime renders it natively in WordPress admin.

**Request (WordPress → App):**

```json
{
  "surface": "meta_box",
  "surface_id": "seo-post-settings",
  "action": "render",
  "context": {
    "post_id": 42,
    "post_type": "post",
    "screen": "post-edit"
  }
}
```

**Response (App → WordPress):**

```json
{
  "components": [
    {
      "type": "text_field",
      "id": "seo_title",
      "label": "SEO Title",
      "value": "My Blog Post - Example Site",
      "placeholder": "Enter SEO title...",
      "max_length": 60,
      "help": "Recommended: 50-60 characters",
      "validation": {
        "required": true,
        "max_length": 60
      }
    },
    {
      "type": "textarea",
      "id": "seo_description",
      "label": "Meta Description",
      "value": "",
      "placeholder": "Enter meta description...",
      "rows": 3,
      "max_length": 160,
      "help": "Recommended: 120-160 characters"
    },
    {
      "type": "progress_bar",
      "id": "seo_score",
      "label": "SEO Score",
      "value": 72,
      "max": 100,
      "color_thresholds": {
        "red": 40,
        "yellow": 70,
        "green": 85
      }
    },
    {
      "type": "checklist",
      "id": "seo_checks",
      "label": "SEO Checklist",
      "items": [
        {"label": "Title contains focus keyword", "checked": true},
        {"label": "Meta description set", "checked": false},
        {"label": "At least one internal link", "checked": true},
        {"label": "Image alt tags present", "checked": false}
      ]
    },
    {
      "type": "button",
      "id": "analyze",
      "label": "Re-analyze",
      "style": "secondary",
      "action": {
        "type": "app_callback",
        "endpoint": "/analyze",
        "method": "POST",
        "payload": {"post_id": 42}
      }
    }
  ]
}
```

### 9.2 Structured Component Library

| Component | Description |
|-----------|-------------|
| `text_field` | Single-line text input |
| `textarea` | Multi-line text input |
| `number_field` | Numeric input with min/max |
| `select` | Dropdown select |
| `multi_select` | Multi-value select |
| `checkbox` | Boolean toggle |
| `radio_group` | Radio button group |
| `toggle` | On/off switch |
| `button` | Action button (triggers app callback or navigation) |
| `link` | Styled hyperlink |
| `heading` | Section heading (h2-h6) |
| `paragraph` | Text block |
| `notice` | Info/warning/error/success notice |
| `progress_bar` | Progress/score indicator |
| `checklist` | List of checkable items |
| `table` | Data table with rows and columns |
| `tabs` | Tabbed content sections |
| `card` | Grouped content with optional header |
| `divider` | Horizontal separator |
| `image` | Image display (URL-based) |
| `code` | Code block with syntax highlighting |
| `key_value` | Label-value pair display |
| `chart` | Simple chart (bar, line, pie) using data array |
| `empty_state` | Placeholder for no-data states |
| `loading` | Loading indicator |

### 9.3 Component Interactions

When a user interacts with a component (submits a form, clicks a button), the Apps Runtime sends the interaction to the App:

```json
{
  "surface": "meta_box",
  "surface_id": "seo-post-settings",
  "action": "interaction",
  "interaction": {
    "component_id": "analyze",
    "type": "click",
    "form_data": {
      "seo_title": "My Blog Post - Example Site",
      "seo_description": "A comprehensive guide to..."
    }
  },
  "context": {
    "post_id": 42
  }
}
```

The App responds with updated components (partial or full re-render).

### 9.4 Iframe Mode

For complex UIs that need full control, Apps can render in a sandboxed iframe:

```html
<iframe
  src="https://my-seo-app.example.com/surfaces/dashboard?site_id=a1b2c3d4&token=..."
  sandbox="allow-scripts allow-forms allow-same-origin"
  style="width: 100%; height: 600px; border: none;"
></iframe>
```

**WordPress Apps Bridge (JS SDK):**

The iframe communicates with WordPress via `postMessage`:

```javascript
// Inside the App's iframe
import { WordPressAppsBridge } from '@wordpress-apps/bridge';

const bridge = new WordPressAppsBridge();

// Navigate WordPress admin
bridge.navigate('/wp-admin/edit.php');

// Show a WordPress-native notice
bridge.showNotice('success', 'SEO analysis complete!');

// Resize the iframe
bridge.resize({ height: 800 });

// Request data through the bridge (respects scopes)
const posts = await bridge.api.get('/apps/v1/posts', { per_page: 10 });

// Open a WordPress-native modal
bridge.openModal({
  title: 'Select Posts',
  component: 'post-picker',
  onSelect: (posts) => { /* handle selection */ }
});
```

### 9.5 Block Editor Integration

Apps register blocks declaratively in the manifest. The block rendering is handled through structured components:

**Block Render Request (WordPress → App):**

```json
{
  "surface": "block",
  "block_name": "my-seo-app/faq-schema",
  "action": "render",
  "attributes": {
    "questions": [
      {"q": "What is SEO?", "a": "Search engine optimization is..."}
    ]
  },
  "context": {
    "post_id": 42,
    "is_editor": true
  }
}
```

**Block Save (App → WordPress):**

```json
{
  "html": "<div class=\"wp-block-my-seo-app-faq-schema\">...</div>",
  "attributes": { "questions": [...] },
  "schema_json_ld": { "@type": "FAQPage", ... }
}
```

---

## 10. Data Storage

Apps are external services with their own databases. App settings, business data, and internal state live in the app's own storage — not in WordPress.

WordPress is used for two narrow storage purposes:

1. **Post Meta** — when an app needs to attach data to a specific WordPress post (e.g., an SEO score per post)
2. **Site Info** — read-only access to site settings via the `site:read` scope

### 10.1 App-Side Storage (Primary)

Apps manage their own data. The spec does not dictate how — apps can use SQLite, PostgreSQL, Redis, flat files, or any storage their runtime supports. This is the correct place for:

- App configuration and settings
- User submissions, logs, analytics
- Cached data and computed results
- API keys, templates, and internal state

When the admin configures the app (e.g., via a settings surface), the settings are saved to the app's own storage via the app's own endpoints.

### 10.2 Post Meta

With `postmeta:write` scope, apps can attach metadata to WordPress posts. This is the only WordPress-side storage apps use, and only when data is inherently tied to a specific post.

```
PUT /apps/v1/posts/42/meta/seo_score {"value": 85}
```

- Meta keys are automatically namespaced per app (prefixed with `_{app_id_slug}_`) to prevent collisions
- Apps can only read/write their own meta keys
- This is for post-specific data only — not for app settings or general storage

**Example use cases:**
- SEO score per post
- Social media share counts
- Content analysis results
- Translation status flags

### 10.3 What NOT to Store in WordPress

Apps should NOT use WordPress for:
- App configuration (use the app's own database)
- Session data (use the app's own storage)
- Cached responses (use the app's own cache layer)
- User-submitted data like form entries (use the app's own database)
- API keys or secrets (use the app's own secure storage)

---

## 11. Background Jobs & Cron

### 11.1 Scheduled Jobs

Declared in the manifest, executed by the Apps Runtime calling the App's endpoint:

```json
{
  "cron": {
    "jobs": [
      {
        "name": "daily_seo_audit",
        "schedule": "daily",
        "endpoint": "/cron/daily-audit",
        "timeout_ms": 30000
      }
    ]
  }
}
```

Available schedules: `hourly`, `twicedaily`, `daily`, `weekly`, or a cron expression (e.g., `0 3 * * 1` for Monday at 3 AM).

### 11.2 Cron Execution

The Apps Runtime triggers cron jobs via HTTP:

```http
POST /cron/daily-audit
Host: my-seo-app.example.com
X-WP-Apps-Signature: sha256=...
X-WP-Apps-Cron-Job: daily_seo_audit
X-WP-Apps-Site-Id: a1b2c3d4
```

The App processes the job and uses its access token to make any needed API calls back to WordPress.

### 11.3 One-Off Jobs

Apps can request one-off background job execution:

```
POST /apps/v1/jobs
{
  "name": "reindex_all_posts",
  "endpoint": "/jobs/reindex",
  "delay_seconds": 0,
  "timeout_ms": 60000
}
```

The Apps Runtime queues and executes the job.

---

## 12. App Lifecycle

### 12.1 States

```
                    install
  AVAILABLE ──────────────────► INSTALLED
                                    │
                              activate│
                                    ▼
                                 ACTIVE
                                    │
                    ┌───────────────┼───────────────┐
              deactivate            │           update
                    ▼               │               ▼
               INACTIVE             │          UPDATING
                    │               │               │
                    │          uninstall        auto-reactivate
                    ▼               │               │
              UNINSTALLING ◄────────┘               │
                    │                               │
                    ▼               ┌───────────────┘
               REMOVED              │
                                    ▼
                                 ACTIVE
```

### 12.2 Lifecycle Hooks

The Apps Runtime calls the App at each lifecycle transition:

| Event | Endpoint | Description |
|-------|----------|-------------|
| `install` | `POST /lifecycle/install` | App should set up its own state, validate requirements |
| `activate` | `POST /lifecycle/activate` | App becomes active, hooks start firing |
| `deactivate` | `POST /lifecycle/deactivate` | App should clean up temporary state |
| `uninstall` | `POST /lifecycle/uninstall` | App should clean up all state. Runtime drops app tables and options |
| `update` | `POST /lifecycle/update` | New version detected, app should run migrations |

### 12.3 Health Checks

The Apps Runtime periodically checks app health:

```
GET /health
Host: my-seo-app.example.com
```

Expected response:

```json
{
  "status": "healthy",
  "version": "1.0.0",
  "uptime_seconds": 86400
}
```

If health checks fail for 3 consecutive attempts (5-minute intervals), the app is automatically deactivated and the site admin is notified.

---

## 13. App Distribution

### 13.1 Distribution Methods

1. **WordPress Apps Directory** (proposed): A curated directory similar to wordpress.org/plugins, but for Apps. Apps are reviewed for manifest accuracy, permission appropriateness, and security.

2. **Direct URL**: Site admins can install Apps by providing the manifest URL. The Apps Runtime fetches `wp-app.json` and begins the installation flow.

3. **Marketplace Integration**: Hosting providers (like InstaWP) can offer their own App marketplaces with additional curation, billing integration, and one-click deployment.

### 13.2 App Verification

Apps can be verified at multiple levels:

| Level | Badge | Requirements |
|-------|-------|-------------|
| **Unverified** | None | Self-hosted manifest, no review |
| **Verified** | ✓ | Identity verified, manifest reviewed |
| **Certified** | ★ | Full security audit, performance tested, SLA commitment |

### 13.3 App Bundles

Multiple Apps can be bundled for common use cases:

```json
{
  "bundle": {
    "name": "SEO Starter Pack",
    "apps": [
      "com.example.seo-core",
      "com.example.sitemap-generator",
      "com.example.schema-markup"
    ]
  }
}
```

---

## 14. Host Requirements

Any WordPress installation can support WordPress Apps by installing the Apps Runtime. The following are the requirements for a compatible host:

### 14.1 WordPress Requirements

- WordPress 6.5+ (REST API v2, Application Passwords)
- PHP 8.1+
- HTTPS enabled (required for webhook signatures)
- REST API accessible (not blocked by security plugins)
- `wp_cron` or external cron runner active

### 14.2 Apps Runtime (Plugin)

The Apps Runtime is an open-source WordPress plugin that provides:

- App manifest parsing and validation
- OAuth 2.0 token management
- Permission enforcement at the API gateway
- Hook dispatcher (serialization, HTTP dispatch, timeout management)
- UI bridge (structured component renderer, iframe sandbox)
- Post meta manager (namespaced per app)
- Cron job scheduler
- Audit logger
- Admin UI for managing installed Apps

**Installation modes:**

| Mode | Install method | Best for |
|------|---------------|----------|
| **Regular plugin** | Upload zip via wp-admin, or `wp plugin install` | Easy setup, auto-updates, most sites |
| **Must-use plugin** | Copy to `wp-content/mu-plugins/` | Production sites where the runtime must never be accidentally deactivated |

Both modes are functionally identical. The mu-plugin mode is recommended for managed hosting and agencies managing multiple sites, since it prevents accidental deactivation that would break all installed apps.

### 14.3 Performance Considerations

- **Hook dispatch latency**: The HTTP round-trip adds latency. Hosts should ensure low-latency connectivity between WordPress and App servers. Co-located apps (same datacenter) are recommended for filter hooks on render paths.
- **Connection pooling**: The Apps Runtime should maintain persistent HTTP connections to active Apps.
- **Caching**: Filter results can be cached by the Runtime when appropriate (e.g., `wp_head` output for the same post can be cached for the TTL declared by the app).
- **Circuit breaker**: If an App repeatedly times out, the Runtime should circuit-break (stop calling it) and recover gracefully.

---

## 15. Migration Path for Existing Plugins

### 15.1 Plugin-to-App Wrapper

For existing plugins that want to transition to the Apps model, a wrapper tool can generate a compatible App:

```bash
wp apps migrate my-plugin --output ./my-plugin-app/
```

This tool:

1. Scans the plugin's PHP code for WordPress API usage
2. Generates a manifest with required scopes
3. Creates an App server that wraps the plugin's logic
4. Maps `add_filter`/`add_action` calls to hook subscriptions
5. Maps `$wpdb` queries to Storage API calls
6. Maps `get_option`/`update_option` to Options API calls

### 15.2 Compatibility Shim

For plugins that can't be fully migrated, a compatibility shim can run them in a sandboxed PHP process with an API proxy:

```
┌──────────────────────────────────────┐
│  Compatibility Shim (Container)       │
│                                       │
│  ┌─────────────────────────────────┐ │
│  │  Sandboxed PHP Process           │ │
│  │                                   │ │
│  │  ┌───────────┐  ┌─────────────┐ │ │
│  │  │ Plugin    │  │ WP API Proxy│ │ │
│  │  │ (original │──│ (intercepts │ │ │
│  │  │  code)    │  │  all WP     │ │ │
│  │  │           │  │  function   │ │ │
│  │  │           │  │  calls)     │ │ │
│  │  └───────────┘  └──────┬──────┘ │ │
│  │                         │        │ │
│  └─────────────────────────┼────────┘ │
│                             │          │
└─────────────────────────────┼──────────┘
                              │
                    Apps API (HTTP)
                              │
                              ▼
                      WordPress Site
```

### 15.3 Incremental Adoption

Sites can run traditional plugins and Apps side by side. The migration can be incremental:

1. **Phase 1**: Install Apps Runtime, keep all existing plugins
2. **Phase 2**: Install new functionality as Apps
3. **Phase 3**: Migrate high-risk plugins (security, forms, payments) to Apps
4. **Phase 4**: Migrate remaining plugins as App versions become available

---

## 15A. Privacy & GDPR

Apps that collect or process personal data must participate in WordPress's privacy framework.

### 15A.1 Privacy Policy Declaration

Apps declare in their manifest what personal data they collect:

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

This information is displayed on the consent screen during installation.

### 15A.2 Data Export

When a site admin initiates a WordPress personal data export request (`Tools > Export Personal Data`), the runtime notifies each active app via a webhook:

```json
{
  "event": "privacy:export",
  "user_email": "user@example.com",
  "request_id": "abc123"
}
```

The app must respond with any personal data it holds for that email address. The runtime includes this data in the WordPress export file.

### 15A.3 Data Erasure

When a site admin initiates an erasure request (`Tools > Erase Personal Data`), the runtime notifies each active app:

```json
{
  "event": "privacy:erase",
  "user_email": "user@example.com",
  "request_id": "abc123"
}
```

The app must delete all personal data for that email address and confirm completion.

### 15A.4 Retention Enforcement

The runtime tracks the `retention` period declared in the manifest. Apps that declare data retention must honor it. The runtime can periodically remind apps to purge expired data via a `privacy:retention_check` event.

---

## 15B. Revisions

Apps that write post meta can participate in WordPress's revision system.

### 15B.1 Post Meta Revisions

When the runtime saves post meta written by apps, it can optionally store the previous value as a revision. This enables:

- Viewing the history of app-written metadata (e.g., SEO title changes over time)
- Restoring a previous revision restores the app's meta values too

### 15B.2 Revision API

```
GET /apps/v1/posts/{id}/revisions              — List revisions
GET /apps/v1/posts/{id}/revisions/{rev_id}     — Get specific revision (includes app meta)
```

Apps with `posts:read` scope can read revisions. Revision data includes any post meta the app had written at that point in time.

---

## 15C. Caching

The Apps Runtime includes a built-in caching layer. The goal is to eliminate the need for third-party caching plugins — performance should be a platform guarantee, not a plugin responsibility.

### 15C.1 Block Render Cache

When an app's block is rendered, the output is cached by the runtime:

- **Cache key**: `{app_id}:{block_name}:{post_id}:{content_hash}`
- **Invalidation**: Automatic when the post is saved, when the app is updated, or when the app sends an explicit cache-bust via the API
- **TTL**: Configurable per block in the manifest (default: 1 hour, max: 24 hours)

This means an app's block only triggers an HTTP call to the app on the first render (or after invalidation). Subsequent page loads serve from cache with zero app overhead.

```json
{
  "surfaces": {
    "blocks": [
      {
        "name": "my-app/pricing-table",
        "title": "Pricing Table",
        "cache_ttl": 3600
      }
    ]
  }
}
```

### 15C.2 Post Meta Rendering Cache

App-written post meta that the runtime renders in `wp_head` (SEO titles, schema markup, OG tags) is cached as part of the page output cache.

### 15C.3 Page Output Cache

The runtime provides a full-page output cache for logged-out visitors:

- Serves cached HTML for anonymous requests without executing PHP or dispatching to apps
- Invalidation on post save, app install/uninstall, or manual purge
- Cache headers: `Cache-Control`, `ETag`, `Last-Modified`
- Works with CDNs — sets proper `Vary` headers

### 15C.4 API Response Cache

Repeated identical API calls from apps are cached:

- `GET` requests are cacheable (default TTL: 60 seconds)
- Cache is per-app, per-endpoint, per-query-parameter
- Apps can set `Cache-Control: no-cache` to bypass
- Write operations (`POST`, `PUT`, `DELETE`) automatically invalidate related cache entries

### 15C.5 Cache Bust API

Apps can explicitly invalidate their cached content:

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

---

## 15D. Rate Limits

Every interaction between apps and WordPress is rate-limited. Rate limits protect the site from misbehaving or compromised apps.

### 15D.1 API Rate Limits

| Operation | Limit | Window | Scope |
|-----------|-------|--------|-------|
| Read requests (`GET`) | 1,000 | per hour | per app |
| Write requests (`POST`, `PUT`) | 200 | per hour | per app |
| Delete requests (`DELETE`) | 50 | per hour | per app |
| Bulk operations | 20 | per hour | per app |
| Token refresh | 10 | per hour | per app |
| Cache purge | 30 | per hour | per app |

### 15D.2 Email Rate Limits

| Operation | Limit | Window |
|-----------|-------|--------|
| Emails sent (`email:send`) | 50 | per hour |
| Emails sent | 500 | per day |
| Recipients per email | 10 | per email |

### 15D.3 Post Meta Rate Limits

| Operation | Limit | Window |
|-----------|-------|--------|
| Meta writes per post | 20 | per minute |
| Meta keys per app per post | 50 | total |
| Meta value size | 64 KB | per value |

### 15D.4 Webhook Event Rate Limits

| Operation | Limit | Window |
|-----------|-------|--------|
| Event webhook deliveries | 1,000 | per hour |
| Failed delivery retries | 3 | per event |
| Retry backoff | 30s, 300s, 3600s | exponential |

### 15D.5 Runtime Hook Rate Limits (Tier 2)

| Operation | Limit | Window |
|-----------|-------|--------|
| `wp_head` / `wp_footer` dispatches | Unlimited (cached) | — |
| `the_content` dispatches | 100 | per hour (with performance warning) |

### 15D.6 Rate Limit Headers

All API responses include rate limit headers:

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 847
X-RateLimit-Reset: 1714003600
X-RateLimit-Scope: read
```

When a limit is exceeded, the runtime returns `429 Too Many Requests`:

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

## 16. Security Considerations

### 16.1 Threat Model

| Threat | Mitigation |
|--------|------------|
| App exfiltrates user data | Scoped permissions + audit logging |
| App serves malicious JS in iframe | `sandbox` attribute + CSP headers |
| App MITM webhook traffic | HTTPS + HMAC signatures + timestamp validation |
| App exceeds resource limits | Rate limiting + timeout enforcement + payload size limits |
| Compromised app token | Short-lived tokens + refresh rotation + token revocation |
| App impersonates another app | App ID verification + per-app signing secrets |
| Replay attacks | Timestamp-based signature validation (5-min window) |
| App stores excessive data | Row/size limits in manifest + runtime enforcement |
| Malicious manifest | Manifest validation + permission consent screen |

### 16.2 Content Security Policy

For iframe-based surfaces:

```http
Content-Security-Policy:
  frame-src https://my-seo-app.example.com;
  frame-ancestors 'self';
```

### 16.3 Data Isolation Guarantees

- Apps CANNOT access other apps' post meta or data
- Apps CANNOT access WordPress core tables directly
- Apps CANNOT read `wp-config.php` or filesystem
- Apps CANNOT execute arbitrary PHP or SQL
- Apps CANNOT modify other apps' behavior
- Apps CANNOT read/write `wp_options` (apps use their own storage)
- Apps CANNOT use WordPress transients or object cache
- Apps CANNOT modify user roles, capabilities, or passwords
- Apps CANNOT install or modify plugins or themes
- All cross-app communication must go through the Apps API

### 16.4 Audit Log

The Apps Runtime logs every API call and hook dispatch:

```json
{
  "timestamp": "2025-04-12T10:30:00Z",
  "app_id": "com.example.my-seo-app",
  "action": "api_call",
  "method": "PUT",
  "endpoint": "/apps/v1/posts/42/meta/_my_seo_score",
  "status": 200,
  "duration_ms": 45,
  "ip": "203.0.113.42"
}
```

---

## 17. Reference Implementation

### 17.1 Apps Runtime (WordPress Side)

**Repository**: `github.com/InstaWP/wordpress-apps-runtime`
**Type**: Must-use plugin (mu-plugin)
**License**: GPL-2.0-or-later

### 17.2 App SDK (App Developer Side)

Libraries for building Apps in various languages:

| Language | Package | Status |
|----------|---------|--------|
| PHP | `instawp/wordpress-apps-sdk-php` | Reference implementation |
| Node.js | `@wordpress-apps/sdk` | Planned |
| Python | `wordpress-apps-sdk` | Planned |
| Go | `github.com/instawp/wp-apps-sdk-go` | Planned |

### 17.3 CLI Tool

```bash
# Scaffold a new App
wp-apps init my-seo-app --language php

# Validate manifest
wp-apps validate ./wp-app.json

# Run locally with tunnel
wp-apps dev --site https://mysite.com

# Migrate existing plugin
wp-apps migrate my-plugin --output ./my-plugin-app/

# Deploy to InstaWP Apps Platform
wp-apps deploy --platform instawp
```

### 17.4 Example App (PHP)

```php
<?php
// index.php — Minimal WordPress App in PHP (data-first model)

require_once __DIR__ . '/vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// Event: when a post is saved, analyze it and write SEO data to post meta.
// This runs async — zero cost on page loads.
// The runtime reads this meta and renders it in wp_head automatically.
$app->onEvent('save_post', function (Request $req): Response {
    $postId = $req->args[0];

    // Fetch the post content via API
    $post = $req->api->get("/apps/v1/posts/{$postId}");
    $content = $post['content']['rendered'] ?? '';

    // Analyze SEO (app's own logic)
    $score = analyze_seo($content);
    $title = generate_seo_title($post['title']['rendered'] ?? '');

    // Write results to post meta — the runtime renders these in wp_head
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_score", ['value' => $score]);
    $req->api->put("/apps/v1/posts/{$postId}/meta/seo_title", ['value' => $title]);
    $req->api->put("/apps/v1/posts/{$postId}/meta/schema_json", ['value' => json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $title,
    ])]);

    return Response::ok();
});

// Handle surface: meta box render
$app->onSurface('seo-post-settings', 'render', function (Request $req): Response {
    $post_id = $req->context['post_id'];
    $score = $req->api->get("/apps/v1/posts/{$post_id}/meta/_my_seo_score");

    return Response::ui([
        [
            'type' => 'progress_bar',
            'id' => 'seo_score',
            'label' => 'SEO Score',
            'value' => $score['value'] ?? 0,
            'max' => 100,
        ],
        [
            'type' => 'button',
            'id' => 'analyze',
            'label' => 'Re-analyze',
            'style' => 'primary',
            'action' => [
                'type' => 'app_callback',
                'endpoint' => '/analyze',
                'method' => 'POST',
                'payload' => ['post_id' => $post_id],
            ],
        ],
    ]);
});

// Health check
$app->onHealth(function (): Response {
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
    ]);
});

$app->run();
```

---

## Appendix A: Comparison with Traditional Plugins

| Aspect | Traditional Plugin | WordPress App |
|--------|--------------------|---------------|
| Execution | In-process PHP | External HTTP service |
| Database access | Full (`$wpdb`) | Scoped API only |
| Filesystem access | Full | None (except app-scoped storage) |
| Network access | Unrestricted | Declared allowlist |
| Other plugins | Can read/modify | Isolated |
| WordPress core | Can modify | Read-only API |
| Resource limits | None | Enforced by runtime |
| Crash impact | Takes down site | App fails gracefully |
| Audit trail | None | Full audit log |
| Permissions | All or nothing | Granular scopes |
| Updates | Direct file replacement | Versioned, rollback-safe |
| Scalability | Bound to PHP process | Independently scalable |
| Monitoring | wp-admin only | Health checks, metrics |

## Appendix B: Comparison with Shopify Apps

| Aspect | Shopify Apps | WordPress Apps |
|--------|--------------|----------------|
| API | REST + GraphQL | REST (GraphQL planned) |
| Auth | OAuth 2.0 | OAuth 2.0 (compatible) |
| UI | App Bridge + Polaris | Structured Components + Iframe Bridge |
| Webhooks | Webhook subscriptions | Hook subscriptions (richer — includes filters) |
| Storage | Shopify-hosted DB (limited) | App-declared tables + options |
| Billing | Shopify handles | App handles (marketplace integration optional) |
| Distribution | Shopify App Store | Open (directory + direct URL + marketplaces) |
| Hosting | Any | Any (not locked to a platform) |
| Hook system | Event-based only | Event + filter (can modify content in pipeline) |

## Appendix C: Frequently Asked Questions

**Q: Won't HTTP hooks be too slow for content filters?**
A: For co-located apps (same server or datacenter), HTTP adds 1-5ms of latency per hook. For render-path filters like `the_content`, the Apps Runtime supports response caching — if the same post content hasn't changed, the cached filter result is reused. For sites needing sub-millisecond hook performance, traditional plugins remain an option.

**Q: Can Apps communicate with each other?**
A: Not directly. Apps are isolated by design. If App A needs data from App B, it must go through the WordPress Apps API. Cross-app data sharing is planned for a future version of the spec with explicit mutual consent.

**Q: How does this work with multisite?**
A: Each site in a multisite network has its own app installations and tokens. Network-level app management (install once, activate per-site) is planned for a future version.

**Q: What about WP-CLI support?**
A: The Apps Runtime includes WP-CLI commands for managing apps: `wp apps list`, `wp apps install`, `wp apps activate`, `wp apps deactivate`, `wp apps uninstall`, `wp apps status`.

**Q: How are app updates handled?**
A: The Apps Runtime periodically checks the app's manifest URL for version changes. When a new version is detected, the admin is notified. Updates can be auto-applied for certified apps or require manual approval.

---

*This specification is a living document. Contributions and feedback are welcome at [github.com/InstaWP/wordpress-apps-spec](https://github.com/InstaWP/wordpress-apps-spec).*
