# WP Apps — Deferred Features

Items we've identified as needed but are deferring to later versions of the spec.

## Custom Post Types
- Should apps be able to register CPTs?
- CPTs are structural (they define the data model), not behavioral
- Risk: CPT proliferation bloats the admin and database
- Decision: Defer. CPTs belong in themes or core config, not in apps.
- Revisit when: We have real-world app use cases that can't be solved with post meta on existing post types.

## Search API
- Apps need to query WordPress content with full-text search
- Current API only supports basic WP_Query parameters
- A dedicated search endpoint (`GET /apps/v1/search`) with relevance scoring
- Consider: Should apps even search WordPress, or should they sync content to their own search index (like Algolia)?

## Multisite / Network Support
- Each site in a multisite network has its own app installations and tokens
- Network-level app management: install once, activate per-site
- Network admin should see all apps across all sites
- Per-site vs network-wide scopes

## Cross-App Communication
- Apps are isolated by design — they can't see each other's data
- Future: explicit mutual consent for data sharing between apps
- Use case: SEO app reading analytics data from an analytics app

## WooCommerce Bridge
- Expose WooCommerce hooks as event webhooks (woocommerce_new_order, etc.)
- WooCommerce-specific scopes (orders:read, products:write, etc.)
- Requires WooCommerce to be active + a bridge extension for the runtime

## Plugin-to-App Migration Tool
- `wp-apps migrate my-plugin --output ./my-plugin-app/`
- Scans PHP for WordPress API usage, generates manifest
- Maps add_filter/add_action to event webhooks
- Maps $wpdb queries to API calls
- Ambitious — defer until the spec is stable

## App Update Re-Consent
- When an app updates and requests new scopes, the admin should re-approve
- Runtime should diff old vs new manifest scopes on version change
- Currently: apps update silently without scope review

## Notion-Style Page-Level Access Control
- Instead of "this app can read all posts," allow "this app can only see posts it's been explicitly shared with"
- More granular than scope-level control
- Complex UX — how does the admin "share" a post with an app?

## Health Check Dashboard
- Site Health screen integration showing all apps' health status
- Network-wide health dashboard for multisite
- Historical uptime tracking per app

## App Analytics
- How often is each app's block rendered?
- How many API calls per app per day?
- Hook dispatch latency percentiles per app
- Admin dashboard for monitoring app performance
