# WP Apps

Open specification for sandboxed, permission-scoped extensions for WordPress. Apps run as isolated external HTTP services instead of in-process plugins. Follows the proven Shopify app model.

## Project Structure

- `wordpress-apps-spec.md` — Full specification (v0.1.0 draft)
- `index.html` — Landing page (self-contained, no external dependencies)
- `runtime/` — Apps Runtime (WordPress plugin/mu-plugin)
- `sdk/` — PHP SDK for building apps
- `sdk/example/` — Hello App (minimal demo)
- `sdk/examples/contact-form/` — Contact Form App (full example)
- `DEFERRED.md` — Features identified but deferred to later versions

## Key Decisions

- **Namespace**: Use `WPApps` (not `WordPressApps`) — "WordPress" is trademarked
- **Domain**: wp-apps.org (registered, pointed to InstaPods pod `wp-apps`)
- **Hosting**: InstaPods static pod at `wp-apps.nbg1-2.instapods.app`, deploy via `instapods files upload wp-apps index.html`
- **Landing page**: Single HTML file, inline CSS/JS, no CDN/frameworks. Dark editorial aesthetic with Georgia serif + monospace
- **Architecture**: Shopify model — apps are independent HTTP services, not sandboxed code. Data-first integration.
- **Runtime**: Works as both regular plugin (easy install) and mu-plugin (production reliability)

## Core Principles (in priority order)

1. **Performance first** — Zero runtime cost by default. Apps add zero overhead to frontend page loads.
2. **Security first** — Apps have no implicit access. Every capability declared and approved.
3. **Data-first integration** — Apps write data via API, WordPress renders it. No render-path HTTP calls.
4. **Blocks for frontend UI** — Admin places blocks, assets load only where placed. No global CSS/JS.
5. **Event webhooks for reactivity** — Always async, never block page loads.
6. **Runtime hooks are escape hatches** — `the_content` filters are discouraged, not the primary pattern.

## Spec Overview

- Apps declare a `wp-app.json` manifest (identity, permissions, events, blocks, surfaces)
- OAuth 2.0 auth flow with scoped, short-lived tokens
- REST API superset under `/apps/v1/` with permission enforcement
- Two-tier integration: Tier 1 (data-first, zero cost) preferred, Tier 2 (runtime hooks) as escape hatch
- Apps store their own data — no wp_options, no transients, no custom tables in WordPress
- Post meta (namespaced) is the only WordPress-side storage, for post-specific data
- Built-in caching layer — eliminates need for caching plugins
- Rate limits on everything — API calls, emails, meta writes, webhooks
- GDPR/Privacy support — data export, erasure, retention declarations
- Reference implementation: plugin (Apps Runtime) + PHP SDK + CLI tool

## Test Environment

- WordPress: `https://wp-apps-dev.instawp.site` (InstaWP cloud instance, PHP 8.2)
- Hello App: `https://hello-app.nbg1-2.instapods.app`
- Contact Form App: `https://contact-form-app.nbg1-2.instapods.app`
- WP Admin: `https://wp-apps-dev.instawp.site/wp-login.php?instawp-magic-login=1`
