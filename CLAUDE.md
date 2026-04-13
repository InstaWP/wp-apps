# WP Apps

Open specification for sandboxed, permission-scoped extensions for WordPress. Apps run as isolated external HTTP services instead of in-process plugins. Follows the proven Shopify app model.

**Repo:** https://github.com/InstaWP/wp-apps
**Site:** https://wp-apps.org
**Version:** 0.0.1

## Project Structure

```
SPEC.md                          — Full specification (v0.1.0 draft)
TODO.md                          — Prioritized implementation tasks
DEFERRED.md                      — Features identified but deferred
index.html                       — Landing page (wp-apps.org)

runtime/                         — Apps Runtime (WordPress plugin/mu-plugin)
  wp-apps-runtime.php            — Plugin bootstrap
  src/Core/
    Plugin.php                   — Singleton, wires everything
    Admin/AdminPage.php          — WP Admin sidebar (Apps menu, install UI, consent screen)
    Auth/                        — OAuthController, TokenManager, SignatureVerifier
    Gateway/                     — Router (/apps/v1/* endpoints), PermissionEnforcer
    Hooks/
      EventDispatcher.php        — Tier 1: async webhooks (preferred)
      RuntimeHookDispatcher.php  — Tier 2: render-path filters (escape hatch)
      Registry.php               — Hook whitelist + timeout config
    Manifest/                    — Parser (fetch + validate + install), Validator
    UI/
      BlockManager.php           — Register blocks + shortcodes, cached rendering
      MetaRenderer.php           — Read app post meta, output in wp_head

sdk/                             — PHP SDK for building apps
  src/
    App.php                      — Developer entry point (onEvent, onBlock, onFilter, run)
    Request.php                  — Incoming request (hook, args, context, api)
    Response.php                 — Factory methods (filter, ok, block, ui, json, error)
    ApiClient.php                — HTTP client for WP API, auto-refresh tokens
    Auth/                        — TokenStore, HmacValidator

sdk/examples/                    — Example apps
  hello-app/                     — Minimal app: event webhook + health (~10 lines)
  reading-time/                  — Data-first loop: event → post meta → block (~50 lines)
  contact-form/                  — Full app: block + form submission + admin panel (~150 lines)

docs/                            — Developer documentation (7 files)
docs/screenshots/                — Screenshots of admin UI, apps, landing page
```

## Key Decisions

- **Namespace**: `WPApps` (not `WordPressApps`) — "WordPress" is trademarked
- **Architecture**: Shopify model — apps are independent HTTP services, not sandboxed code (evaluated and rejected Emdash's V8 isolate approach)
- **Data-first**: Apps write data via API, WordPress renders it. No render-path HTTP calls by default.
- **No wp_options**: Apps store their own data in their own database. WordPress is not a key-value store for apps.
- **No transients**: Apps use their own caching. The runtime has built-in block + page caching.
- **Blocks over filters**: `the_content` filters are a Tier 2 escape hatch. Blocks + shortcodes are the primary frontend integration.
- **Runtime as plugin**: Works as both regular plugin (easy install via wp-admin) and mu-plugin (can't be accidentally deactivated)
- **Domain**: wp-apps.org (registered, pointed to InstaPods pod `wp-apps`)
- **Landing page**: Self-contained HTML, inline CSS/JS, dark editorial aesthetic with Georgia serif + monospace

## Core Principles (in priority order)

1. **Performance first** — Zero runtime cost by default. A site with 20 apps loads at the same speed as zero apps.
2. **Security first** — Apps have no implicit access. Every capability declared and admin-approved.
3. **Data-first integration** — Apps write data via API, WordPress renders it.
4. **Blocks for frontend UI** — Admin places blocks, assets load only where placed. Shortcode fallback for Elementor/Divi/Classic Editor.
5. **Event webhooks for reactivity** — Always async, never block page loads.
6. **Runtime hooks are escape hatches** — `the_content` filters are discouraged. The runtime shows a performance warning.

## The Simple Rule

> If it's a service, it's an app. If it's infrastructure, it's a plugin.

See `docs/apps-vs-plugins.md` for the full guide.

## Deploying Changes

```bash
# Landing page
instapods files upload wp-apps index.html

# Runtime to WordPress test site
rm -rf /tmp/wp-apps-sync && mkdir -p /tmp/wp-apps-sync/wp-content/mu-plugins
cp -r runtime /tmp/wp-apps-sync/wp-content/mu-plugins/wp-apps-runtime
rm -rf /tmp/wp-apps-sync/wp-content/mu-plugins/wp-apps-runtime/{tests,.git,vendor}
instawp sync push wp-apps-dev --path /tmp/wp-apps-sync/wp-content --exclude vendor --exclude tests
instawp exec wp-apps-dev "cd ~/web/wp-apps-dev.instawp.site/public_html/wp-content/mu-plugins/wp-apps-runtime && composer dump-autoload --no-interaction"

# Example apps to InstaPods
instapods files upload <pod-name> sdk/examples/<app>/index.php --remote /home/instapod/app/examples/<app>/index.php
```

## Test Environment

| What | URL |
|------|-----|
| WordPress site | https://wp-apps-dev.instawp.site |
| WP Admin (magic login) | https://wp-apps-dev.instawp.site/wp-login.php?instawp-magic-login=1 |
| WP Admin credentials | `bolesobeze6500` / `PWVyIkZXN1U5l9xJDR4C` |
| Hello App | https://hello-app.nbg1-2.instapods.app |
| Contact Form App | https://contact-form-app.nbg1-2.instapods.app |
| Reading Time App | https://reading-time-app.nbg1-2.instapods.app |
| Landing Page | https://wp-apps.org (pod: `wp-apps`) |
