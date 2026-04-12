# WPApps Documentation

WPApps is an open specification for sandboxed WordPress extensions. Apps are external HTTP services -- like Shopify apps -- that communicate with WordPress through a structured API protocol. No code runs inside WordPress. Apps write data via API, WordPress renders it.

## Docs

- [Getting Started](getting-started.md) -- Install the runtime, build your first app, deploy it.
- [Manifest Reference](manifest-reference.md) -- Complete `wp-app.json` schema with all fields.
- [SDK Reference](sdk-reference.md) -- PHP SDK API: `App`, `Request`, `Response`, `ApiClient`, auth classes.
- [Integration Model](integration-model.md) -- Two-tier architecture: Tier 1 (data-first, zero cost) vs Tier 2 (runtime hooks, escape hatch).
- [API Reference](api-reference.md) -- REST API endpoints under `/apps/v1/`, authentication, rate limits, errors.
- [Security](security.md) -- OAuth 2.0, HMAC signatures, token lifecycle, data isolation, audit logging.

## Core Principles

1. **Performance first** -- Apps add zero overhead to frontend page loads by default.
2. **Security first** -- Every capability must be declared and approved. No implicit access.
3. **Data-first integration** -- Apps write data via API, WordPress renders it. No render-path HTTP calls.
4. **Blocks for frontend UI** -- Admin places blocks in the editor. Assets load only where placed.
5. **Event webhooks are async** -- Never block page loads.
6. **Runtime hooks are escape hatches** -- `the_content` filters are discouraged, not the primary pattern.
7. **Apps store their own data** -- No `wp_options`, no transients, no custom WordPress tables. Post meta is the only WordPress-side storage (namespaced, post-specific only).

## Links

- [Full Specification](../wordpress-apps-spec.md)
- [PHP SDK Source](../sdk/src/)
- [Runtime Source](../runtime/src/)
- [Example: Hello App](../sdk/example/)
- [Example: Contact Form App](../sdk/examples/contact-form/)
