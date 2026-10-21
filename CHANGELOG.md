# Changelog

Every released version, newest first. The same list, in WordPress's format, is
in `readme.txt`, which is what wp-admin and WordPress.org read.

This project uses [semantic versioning](https://semver.org/). Below 1.0 the
shape of things can still change between minor versions; the changelog says so
when it does.

## 0.1.0 — 2026-10-21

First release. **Product import only** — delivery and checkout come next, so a
shop cannot yet take an order through this plugin.

### Added
- Products are imported as **real** WooCommerce products, simple and variable,
  through WooCommerce's own CRUD, so sorting by price, the price and stock
  filters, search, sitemaps and structured data all work unchanged.
- **Photos are copied into the Media Library** and served from the shop's own
  domain, never linked from btab's image host.
- Categories, out-of-stock status, and options as WooCommerce variations.
- **Enquiry-only items** show an enquiry form instead of Add to cart, and are
  not purchasable.
- Resync on a schedule (WP-Cron) and on demand with **Sync now**, both behind a
  lock so two runs cannot race.
- Settings screen: API base, publishable key, resync interval, **Test
  connection**.
- A store API key (`btab_live_…`) is refused before it is sent.

### Notes for anyone reading the code
- `lib/` is a portable PHP core with no WordPress in it, so another platform
  can reuse it. WordPress's HTTP API is injected into it as a transport.
- A product btab stops listing is set to draft and hidden, never deleted,
  because a shop's existing orders point at it.
- A variation btab stops listing is deleted outright rather than trashed: a
  trashed variation still resolves for a buyer.
