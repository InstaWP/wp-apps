# Frequently Asked Questions

## Hosting & Infrastructure

### Does each app need its own server?

No. "Separate runtime" means separate process, not separate server. A typical WordPress site can run 5+ apps on the same $5 VPS.

**Hosting models, from simplest to most sophisticated:**

**Same server as WordPress (cheapest, ~1-5ms latency):**
```
Your VPS ($5/mo)
├── nginx
├── PHP-FPM → WordPress + Apps Runtime
├── PHP on port 8001 → SEO App
├── PHP on port 8002 → Contact Form App
└── Node on port 8003 → Analytics App
```

**Multiple apps in one process:**
```
Single app server on port 8001
├── /seo/*        → SEO App
├── /forms/*      → Contact Form App
└── /analytics/*  → Analytics App
```
Each app has its own manifest pointing to a different base path on the same endpoint.

**Docker containers on the same host:**
```
Your server
├── WordPress (host or container)
├── docker: seo-app (port 8001)
├── docker: forms-app (port 8002)
└── docker: analytics-app (port 8003)
```

**SaaS model (one server, many WordPress sites):**
```
WordPress Site A ──┐
WordPress Site B ──┼──→ your-seo-app.com
WordPress Site C ──┘
```
This is how Shopify apps work — and it's the business model for app developers.

**Serverless:** Each app as a Lambda/Cloud Function/Worker. Pay per invocation.

Apps are lightweight HTTP handlers — the Reading Time example is ~50 lines of PHP. There's no requirement for dedicated infrastructure per app.

### Can apps run on the same server as WordPress?

Yes. The app just needs to be a separate process (not inside the WordPress PHP runtime). A PHP built-in server (`php -S localhost:8001`) or a systemd service works fine. Co-located apps add just 1-5ms latency per event webhook.

### What language can apps be written in?

Any language that can serve HTTP. The reference SDK is PHP, but apps are just HTTP servers that handle JSON requests. Node.js, Python, Go, Ruby, Rust — anything works. You just need to:
1. Serve a `wp-app.json` manifest
2. Handle `/auth/callback` for OAuth
3. Handle `/hooks` for event webhooks
4. Handle `/surfaces/blocks` for block rendering
5. Handle `/health` for health checks

### Do apps need HTTPS?

Yes, for production. HTTPS is required for HMAC webhook signatures to be meaningful. Exception: `localhost` and `127.0.0.1` are allowed over HTTP for local development.

## Performance

### Do apps slow down my site?

No — that's the whole point. The data-first architecture means:

- **Event webhooks** are async fire-and-forget. They never block page loads.
- **Blocks** are rendered once and cached. Subsequent page loads serve cached HTML.
- **Post meta** written by apps is served from WordPress's own cache layer.
- **No CSS/JS** is loaded globally — block assets only load on pages where the block is placed.

A site with 20 apps installed loads at the same speed as a site with zero apps.

### What about the HTTP latency for webhooks?

Event webhooks are async — WordPress fires them and doesn't wait for a response. The app processes the event in the background and writes results back via the API. Page loads are never blocked.

For the rare case where a render-path filter is needed (Tier 2), co-located apps on the same server add 1-5ms. Remote apps add 50-200ms. This is why Tier 2 filters are discouraged — use blocks and post meta instead.

### Do I still need a caching plugin?

The WP Apps Runtime includes built-in caching:
- Block render cache (configurable TTL per block)
- Post meta rendering is served from WordPress's object cache
- Cache invalidation on post save and explicit purge via API

For sites running only WP Apps (no traditional plugins), a separate caching plugin should not be necessary.

## Security

### How is this more secure than plugins?

Plugins run inside WordPress with full access to everything — database, filesystem, other plugins, user passwords. A single vulnerable plugin compromises the entire site.

Apps are external services that interact only through a scoped API:
- No database access (API only, scoped by permission)
- No filesystem access
- No PHP runtime access
- No access to other apps' data
- Every API call is authenticated, rate-limited, and logged
- Admin reviews and approves permissions before installation

### Can a malicious app steal user data?

An app can only access what its declared scopes allow. If an app has `posts:read` scope, it can read posts but not users, not email addresses, not passwords. The admin sees every requested scope on the consent screen before approving.

Even with `users:read:basic`, the app only gets display name and role — never passwords or email addresses (that requires `users:read:full`).

### What happens if an app goes down?

The runtime handles failures gracefully:
- Event webhooks are fire-and-forget — a failed delivery doesn't affect WordPress
- Block renders fall back to cached HTML
- Tier 2 filters fail open — if the app doesn't respond, unmodified content is used
- After 3 consecutive health check failures, the app is auto-deactivated and the admin is notified

### Can apps communicate with each other?

No. Apps are fully isolated by design. One app cannot read another app's post meta, API tokens, or data. If two apps need to share data, they must each go through the WordPress API independently. Cross-app data sharing is planned for a future version with explicit mutual consent.

## Development

### How do I develop locally?

```bash
# 1. Start your app
cd my-app && php -S localhost:8001 index.php

# 2. Install on your local WordPress site
# Go to WP Admin → Apps → Install New
# Enter: http://localhost:8001/wp-app.json
```

For remote WordPress sites, you'll need a tunnel (ngrok, Cloudflare Tunnel, etc.) to expose your local app server. The planned `wp-apps dev` CLI command will automate this.

### How do I test webhooks locally?

During development, you can manually trigger events by sending POST requests to your app's `/hooks` endpoint:

```bash
curl -X POST http://localhost:8001/hooks \
  -H "Content-Type: application/json" \
  -d '{"event":"save_post","type":"event","args":[1],"context":{"post_id":1},"site":{"url":"http://localhost:8080"}}'
```

### Can I migrate an existing plugin to an app?

Yes, incrementally. The common patterns:
- `add_filter('the_content', ...)` → Register a block instead
- `add_action('save_post', ...)` → Register an event webhook
- `get_option()` / `update_option()` → Store in your app's own database
- `$wpdb->query()` → Use the `/apps/v1/` REST API
- `wp_enqueue_script()` → Include in your block's cached HTML

A migration tool (`wp-apps migrate`) is planned but not yet built.

## Comparison

### How does this compare to Shopify apps?

Very similar architecture. Both use OAuth, scoped permissions, webhooks, and embedded UI. Key differences:

| Aspect | Shopify Apps | WP Apps |
|--------|-------------|---------|
| API | REST + GraphQL | REST (GraphQL planned) |
| Webhooks | Event-based only | Events + filters (filters are an escape hatch) |
| UI | App Bridge + Polaris | Blocks + shortcodes + structured JSON + iframe |
| Billing | Shopify handles | App handles |
| Distribution | App Store only | Open (URL install + future marketplace) |
| Hosting | Any | Any (not locked to a platform) |

### How does this compare to Cloudflare's Emdash?

Emdash uses V8 isolates (same tech as Cloudflare Workers) to sandbox plugins server-side. WP Apps uses HTTP-based isolation (same as Shopify).

| Aspect | Emdash | WP Apps |
|--------|--------|---------|
| Isolation | V8 isolates (5ms startup) | HTTP services (any host) |
| Language | JS/TS only | Any language |
| Platform lock-in | Cloudflare only (sandbox mode) | None |
| Maturity | v0.1 preview | v0.0.1 with working implementation |

We chose the Shopify/HTTP model because it's proven at scale, language-agnostic, and keeps apps fully independent.
