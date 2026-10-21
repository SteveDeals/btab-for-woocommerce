=== btab for WooCommerce ===
Contributors: btab
Tags: woocommerce, products, catalogue, dropshipping, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell btab's products from your own WooCommerce shop, on your own domain. Products are copied in and kept fresh.

== Description ==

Your WooCommerce shop sells btab's products as if they were your own. The plugin copies them in as real WooCommerce products — title, price, photos, stock, variants and categories — and keeps them fresh on a schedule. btab holds the goods, prices every line and takes the payment; you keep your domain, your theme and your emails.

**Your shop stays your shop.** A buyer sees no mention of btab: not in an address, an image URL, page text or the page source. Product photos are copied into your own Media Library instead of being linked from btab. Your product and category pages load no plugin files at all; the checkout loads one small script, served from your own domain, which only draws the payment option.

**Your product pages stay ordinary WordPress pages.** Nothing calls btab while a page renders, so pages cache normally and stay fast. Because the products are real WooCommerce products, sorting by price, the price and stock filters, search, sitemaps, related products and structured data all work with no special handling.

= What it does today =

* Imports products as real simple and variable products, with photos, stock, categories and options
* Marks out-of-stock items as out of stock
* Shows an enquiry form instead of Add to cart for items btab sells by enquiry
* Hears about a change from btab the moment it happens, signed, and updates just that product within seconds
* Marks an order paid when btab says it was paid, even if the buyer never came back to your thank-you page, and moves it to Refunded when btab refunds it
* Reconciles nightly as a safety net, and on demand with **Sync now**
* Quotes delivery from btab for the buyer's postcode, and uses that figure instead of your own shipping rates on any cart holding btab products
* Hands the cart to btab at **Place order**, takes the buyer to btab's checkout on your own domain, and brings them back to your thank-you page
* Stops any other payment method taking money for btab products
* Leaves your own WooCommerce emails to do the talking, from your own domain
* Holds btab's price and stock against accidental edits in wp-admin, wherever the edit comes from, with a link to change them in btab

= What it does not do yet =

Said plainly, so you can judge whether it is useful to you now:

* Mixed carts: btab's products and your own goods together get no delivery option and no way to pay, with a line asking for two orders. There is no honest single freight price for the two
* Your own variations on a btab product: btab cannot ship a variation it has never heard of, so one you add is shown but cannot be bought. Delete it yourself if you do not want it on the page
* Tracking numbers are not yet copied back into WooCommerce: btab's order messages do not carry them yet
* Refunds are reflected in the order's status, but the money is refunded by the supplier, not through your gateway
* The plugin does not yet update itself

= What you need =

A btab account with products, and a publishable key from your btab dashboard. WooCommerce 9.0 or newer.

= Privacy =

The plugin talks to btab's Storefront API (`api.btab.app`) from your server, to read products, to price a cart's delivery, and to open a checkout session at Place order. It also exposes one address for btab to send change messages to; every message must be signed with a secret only you and btab hold, and an unsigned one is refused without being read. Nothing calls btab while a product or category page renders. The cart sent to btab carries product ids, options and quantities — never prices and never the buyer's details. The buyer gives their payment details to btab on the checkout page, not to this plugin. When a buyer sends an enquiry, the name, email and message they type are sent to btab so the seller can answer.

== Installation ==

1. In your btab dashboard, go to **Settings → Developers → Storefront keys** and make a **publishable** key. Add your shop's address to the key's allowed sites.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload `btab-woocommerce.zip`.
3. Activate it.
4. Go to **WooCommerce → btab**, paste the key, press **Save**, then **Test connection**.
5. Press **Sync now**.

== Frequently Asked Questions ==

= Can I try it without charging anyone? =

Yes. Make a test key (`btab_pk_test_…`) in the same place. It reads btab's demo store of made-up goods, and its checkout ends in "Place test order" — nothing is charged and nothing is sent. Swap in your live key when you are happy.

= Will my buyers see btab anywhere? =

No, and the project treats any case of it as a bug. Photos are copied into your Media Library, the plugin adds no front-end files, and no id, class or visible string on a buyer page names btab. Your own admin screens do name it, because you are the one connecting the two.

= Can I edit the imported products? =

Treat btab as the source of truth: a resync overwrites the fields it owns, so an edit to a price or stock level in WordPress will be replaced. Later versions lock those fields in wp-admin and point you at btab instead.

= What happens to a product btab stops selling? =

It is set to draft and hidden, never deleted, so your existing orders still point at something real.

= Do I need a store API key? =

No, and you must not use one. A store API key (`btab_live_…`) is not a shop-window key; the plugin refuses it before it is ever sent. Use a publishable key (`btab_pk_…`).

== Screenshots ==

1. The settings screen: API base, publishable key, resync interval, Test connection and Sync now.
2. Imported products on the shop, served from your own domain.
3. An item btab sells by enquiry: an enquiry form in place of Add to cart.
4. A product with options, imported as a WooCommerce variable product.

== Changelog ==

= 0.2.0 =
* Delivery priced by btab, checkout handed to btab on your own domain, live updates, order copy, and btab's numbers held against accidental edits. The first version a shop can actually sell with.
* On update from 0.1.0 the plugin asks for one full resync, so every product gets btab's reference price and stock.

= 0.1.0 =
* First release. Product import: real simple and variable products, photos copied into the Media Library, categories, out-of-stock, enquiry-only, resync on a schedule and a Sync now button.
* Live updates: btab sends a signed message when one of your products changes and your shop updates just that product in seconds. Signature checked over the raw bytes, messages older than five minutes refused, repeats ignored. The scheduled run drops to nightly and becomes the safety net.
* Delivery priced by btab for the cart and postcode, replacing your own rates on carts holding btab products. An item btab cannot price reads as "needs a delivery quote", never as free.
* Checkout handed to btab at Place order, on your own domain, and back to your own thank-you page. No other payment method can take money for btab products.
* Not in this release: mixed carts, the order copy in wp-admin, locked btab fields.

== Upgrade Notice ==

= 0.2.0 =
Import, delivery, checkout, live updates and order copy. One resync runs by itself after the update.
