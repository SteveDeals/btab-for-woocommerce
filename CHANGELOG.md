# Changelog

Every released version, newest first. The same list, in WordPress's format, is
in `readme.txt`, which is what wp-admin and WordPress.org read.

This project uses [semantic versioning](https://semver.org/). Below 1.0 the
shape of things can still change between minor versions; the changelog says so
when it does.

## 0.1.0 — 2026-10-21

First release: a shop can import btab's products, quote delivery and take an
order, end to end, with no btab anywhere a buyer can see.

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
- **Delivery is priced by btab**, per cart and postcode, as a WooCommerce
  shipping method. It applies to every destination rather than belonging to a
  shipping zone, and it replaces this shop's own rates on any cart holding btab
  products, so the shop can never charge its own freight for btab's goods.
  Freight is cached for ten minutes per cart and postcode.
- **An item btab cannot price freight for** reads as "needs a delivery quote",
  never as free.
- **Checkout hands the cart to btab**: Place order opens a checkout session and
  sends the buyer to btab's checkout page on **your** domain, then back to your
  own thank-you page. The order is marked paid because btab says it was paid,
  never because the buyer landed on a URL.
- **No other payment method can take money for btab products**, so a
  pre-installed gateway cannot collect for goods nothing will ship.
- Your own WooCommerce emails are sent as usual, from your own domain.
- **Live updates.** btab sends a signed message when one of your products
  changes, and the shop updates **just that product**, in seconds. The
  signature is checked over the raw bytes before anything is read, a message
  more than five minutes old is refused, a repeat is ignored, and a product
  btab stops serving is drafted and hidden rather than deleted. The scheduled
  resync drops to **nightly** and becomes the safety net for a message that
  never arrived, not the way the shop keeps up. Paste the signing secret from
  btab into **WooCommerce → btab → Live updates**; the page shows the address
  to give btab.

### Not in this release
- **Mixed carts.** btab's products and your own goods in one cart get no
  delivery option and no way to pay, with a line asking for two orders. There
  is no honest single freight price for the two together.
- The order copy in wp-admin (paid, refunded, tracking) and locked btab fields.

### Notes for anyone reading the code
- `lib/` is a portable PHP core with no WordPress in it, so another platform
  can reuse it. WordPress's HTTP API is injected into it as a transport.
- A product btab stops listing is set to draft and hidden, never deleted,
  because a shop's existing orders point at it.
- A variation btab stops listing is deleted outright rather than trashed: a
  trashed variation still resolves for a buyer.
