# WP Apps — TODO

## High Priority

- [ ] **Rate limiter** — Token-bucket per app, enforce on every API call, return `X-RateLimit-*` headers. 1000 reads/hr, 200 writes/hr, 50 deletes/hr per app.
- [ ] **Page output cache** — Full-page cache for logged-out visitors. Serve cached HTML without executing PHP or dispatching to apps. Invalidate on post save, app install/uninstall.
- [ ] **App update detection + re-consent** — Diff manifest scopes on version change. If new scopes added, require admin re-approval before activating the update.
- [ ] **Remaining API endpoints** — Users (`users:read:basic`, `users:read:full`), media (upload, list), comments, taxonomies, site info (`GET /apps/v1/site`).

## Medium Priority

- [ ] **API response cache** — Cache GET responses per app, per endpoint, per query params. 60s default TTL. Auto-invalidate on writes.
- [ ] **Lifecycle hooks to app** — POST `/lifecycle/install`, `/activate`, `/deactivate`, `/uninstall`, `/update` to app on state changes.
- [ ] **Health checker** — WP cron every 5 min, GET `/health` on each active app. Auto-deactivate after 3 consecutive failures. Admin notification.
- [ ] **Audit log viewer** — Table view of `wp_apps_audit_log` in the Apps admin page. Filter by app, action type, date range.
- [ ] **WP-CLI commands** — `wp apps list`, `wp apps install <url>`, `wp apps activate <id>`, `wp apps deactivate <id>`, `wp apps uninstall <id>`, `wp apps status <id>`.
- [ ] **`wp-apps` CLI tool** — `wp-apps init <name> --language php` (scaffold), `wp-apps validate` (manifest check), `wp-apps dev --site <url>` (local dev with tunnel).
- [ ] **Email send endpoint** — `POST /apps/v1/email` with `email:send` scope. Rate limit: 50/hr, 500/day, 10 recipients/email.

## Low Priority

- [ ] **GDPR export/erasure webhooks** — Fire `privacy:export` and `privacy:erase` events to apps on WordPress privacy requests.
- [ ] **Revision API** — `GET /apps/v1/posts/{id}/revisions` with app post meta included per revision.
- [ ] **Structured component renderer** — JSON components → native WP admin HTML for admin pages and meta boxes (non-iframe mode).
- [ ] **Iframe sandbox with JS bridge** — `postMessage` bridge for complex admin UIs. `navigate()`, `showNotice()`, `resize()`, `api.get()`.
- [ ] **Circuit breaker** — Track per-app failure rates. After N consecutive timeouts, stop dispatching for a cooldown period.
- [ ] **Block editor preview improvements** — Better editor experience with loading states, configuration UI in the sidebar.

## Deferred (see DEFERRED.md)

- Custom Post Types
- Search API
- Multisite support
- Cross-app communication
- WooCommerce bridge
- Plugin-to-app migration tool
- Page-level access control (Notion-style)
- App analytics dashboard
