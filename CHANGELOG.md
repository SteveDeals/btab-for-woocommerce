# Changelog

Every released version, newest first. The same list, in WordPress's format, is
in `readme.txt`, which is what wp-admin and WordPress.org read.

This project uses [semantic versioning](https://semver.org/). Below 1.0 the
shape of things can still change between minor versions; the changelog says so
when it does.

## 0.2.1 — 2026-10-21

### Fixed
- **Two database queries per page load, gone.** Both of the values the plugin
  keeps about its own version were stored in a way that WordPress does not keep
  in memory, so every single page load of every shop without a persistent object
  cache paid a query for each — and one of them was a flag that is normally not
  even there, because WordPress charges the same for looking up an option that
  is absent as one that is present. They are now loaded with WordPress's own
  settings, so the plugin adds no queries at all. The update rewrites them once,
  on the first load after it, and carries over a resync that was still owed.

## 0.2.0 — 2026-10-21

The first version a shop can actually sell with.

### Added
- **The plugin updates itself.** It is not on WordPress.org, so until now a shop
  was never told a new version existed and would have had to re-upload a zip by
  hand. It now appears on the Plugins and Updates screens like any other plugin:
  one click. **btab signs every release, and an update without a valid btab
  signature is not installed** — not merely one that was damaged in transit,
  but one that was swapped for somebody else's. The signature covers both the
  file and the version it claims to be, so an old release cannot be replayed as
  a new one, and the signing key is held offline rather than anywhere a server
  could leak it. The address an update may come from is fixed in the code down
  to the exact file name, and so is the place the manifest is read from,
  because a setting is something a request can reach and this one ships code.
  A refusal always leaves the shop on the version it is running.
- **The plugin remembers which version a shop is running** and runs the steps a
  new version needs. Updating from 0.1.0 asks for **one full resync a minute
  later** — on cron, so nobody is waiting on a page — because btab's reference
  price and stock only started being recorded in this version, and the guard
  below has nothing to compare against without them.

### Fixed
- **A variation you add yourself to a btab product cannot be bought.** btab has
  never heard of it and cannot ship it, so it is shown but not purchasable, and
  wp-admin says so and tells you to delete it yourself. Its price is left
  exactly as you set it — pretending it costs the parent's price would be inventing a
  number nobody quoted.
- **Variations are guarded by their parent.** A variation imported before the
  guard existed, or one a shop owner adds to a btab product by hand, is now
  held to btab's numbers instead of showing the owner's price in the buyer's
  "From $x" range.
- **A variable product's own price is left alone.** WooCommerce derives it from
  the variations; writing one number over it would flatten per-variant prices
  the day btab sends them.
- **A request cannot invent one of btab's reference values.** A `_btab_*` field
  that is not in the database is removed from the object, instead of being
  trusted on the next save.
- **The "that is not what this order says" note is written once**, not once per
  delivery, and the paid note now compares the **currency** as well as the
  amount.

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
- **Your orders stay right even when a buyer never comes back.** btab tells
  your shop when a checkout was paid, so an order whose buyer closed the tab
  instead of returning to your thank-you page is still marked paid, with the
  supplier's order reference on it. A full refund by the supplier moves the
  order to Refunded. wp-admin's own **Refund button is hidden on these orders**:
  pressing it would move money on your gateway, which never took any.
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
