# Apps vs Plugins — When to Build Which

## The Simple Rule

> **If it's a service, it's an app. If it's infrastructure, it's a plugin.**

A contact form is a service — it receives submissions, stores them, sends notifications. A page builder is infrastructure — it defines how pages are constructed.

## Build an App when:

### The extension is a service
- It has its own backend logic, data, and compute
- It talks to external APIs (payment gateways, email providers, AI services)
- It needs to scale independently from WordPress
- *Examples: forms, SEO, analytics, email marketing, live chat, CRM sync, image optimization*

### Security matters
- It handles sensitive data (payments, PII, API credentials)
- You don't want it reading `wp-config.php` or the user table
- You're building for other people's sites (agency, marketplace, SaaS)

### Performance matters
- It shouldn't slow down page loads (apps add zero runtime cost)
- It does heavy processing (content analysis, image resizing, crawling, AI)
- The site runs 10+ extensions and can't afford each one adding PHP + queries + assets to every page

### Distribution matters
- One app server serving thousands of WordPress sites (SaaS model)
- You want to update backend logic without touching each site
- You want usage analytics, error tracking, and billing across all installs

## Build a Plugin when:

### It modifies WordPress internals
- Custom post types, taxonomies, custom fields — structural data model changes
- Rewrite rules, permalink structures — URL routing
- Core admin modifications — changing how the block editor works
- Theme functionality — template tags, theme options, customizer panels

### It IS the site
- Page builders (Elementor, Divi) — they are the rendering engine
- WooCommerce — it is the store, not an extension to it
- Advanced Custom Fields — it extends the data model at a structural level

### It needs filesystem or database access by design
- Backup plugins that read the entire site
- Caching plugins that write to disk and modify `.htaccess`
- Security plugins that intercept requests at the PHP level

## The Gray Zone

| Extension Type | Plugin? | App? | Best Fit | Why |
|---------------|---------|------|----------|-----|
| Contact form | Works | Natural fit | **App** | Own DB, own submissions, notifications |
| SEO meta tags | Works | Natural fit | **App** | Post meta + events, zero runtime cost |
| Analytics | Works | Natural fit | **App** | Tiny pixel, own data warehouse |
| Email marketing | Works | Natural fit | **App** | Already an external service (Mailchimp, etc.) |
| Image optimization | Partial | Natural fit | **App** | Heavy processing, external anyway |
| Social sharing | Works | Natural fit | **App** | Static block, long cache |
| Live chat | Works | Natural fit | **App** | Just injects a widget script |
| Comment spam filter | Works | Natural fit | **App** | Async event, no page-load cost |
| Custom fields (ACF) | Deep editor coupling | Too coupled | **Plugin** | Structural data model changes |
| Page builder | IS the renderer | Can't work | **Plugin** | Needs sub-ms rendering |
| Caching | Needs filesystem | Contradicts model | **Plugin** | But runtime has built-in caching |
| Security/firewall | Needs PHP-level intercept | Too late over HTTP | **Plugin** |
| Backup | Needs full access | Contradicts model | **Plugin** |
| Social login | Deep auth integration | OAuth is HTTP | **Either** | Depends on depth of integration |
| Redirect manager | Needs rewrite rules | Could use events | **Either** | Simple redirects = app, complex rules = plugin |

## What the App model eliminates

When you build as an app instead of a plugin, these WordPress problems disappear:

| Problem | Plugin | App |
|---------|--------|-----|
| Autoloaded options bloat | Every plugin adds to wp_options | Apps use their own DB |
| CSS/JS on every page | Global enqueue | Blocks load only where placed |
| Database queries per page | Each plugin runs queries | Zero queries (data pre-cached) |
| PHP memory per request | Full codebase loaded | Zero PHP loaded |
| Cron event accumulation | Never cleaned up | Managed by runtime, cleaned on uninstall |
| Plugin update risk | Full code replacement, no review | Manifest scope changes require re-consent |
| Supply chain attacks | Opaque code updates | Scoped tokens limit blast radius |
