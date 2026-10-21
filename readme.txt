=== btab for WooCommerce ===
Contributors: btab
Tags: woocommerce, products, catalogue, dropshipping, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell btab's products from your own WooCommerce shop, on your own domain. Products are copied in and kept fresh.

== Description ==

Your WooCommerce shop sells btab's products as if they were your own. The plugin copies them in as real WooCommerce products — title, price, photos, stock, variants and categories — and keeps them fresh on a schedule. btab holds the goods, prices every line and takes the payment; you keep your domain, your theme and your emails.

**Your shop stays your shop.** A buyer sees no mention of btab: not in an address, an image URL, page text or the page source. Product photos are copied into your own Media Library instead of being linked from btab, and the plugin loads no CSS or JavaScript on a buyer-facing page.

**Your product pages stay ordinary WordPress pages.** Nothing calls btab while a page renders, so pages cache normally and stay fast. Because the products are real WooCommerce products, sorting by price, the price and stock filters, search, sitemaps, related products and structured data all work with no special handling.

= What it does today =

* Imports products as real simple and variable products, with photos, stock, categories and options
* Marks out-of-stock items as out of stock
* Shows an enquiry form instead of Add to cart for items btab sells by enquiry
* Resyncs on a schedule, and on demand with **Sync now**

= What it does not do yet =

Said plainly, so you can judge whether it is useful to you now:

* Delivery is not yet quoted by btab at the cart
* The cart is not yet handed to btab checkout at Place order
* Paid orders and tracking are not yet copied back into WooCommerce
* The plugin does not yet update itself

= What you need =

A btab account with products, and a publishable key from your btab dashboard. WooCommerce 9.0 or newer.

= Privacy =

The plugin talks to btab's Storefront API (`api.btab.app`) from your server to read products and, later, to price carts and open a checkout session. It sends no buyer data while a page renders. When a buyer sends an enquiry, the name, email and message they type are sent to btab so the seller can answer.

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

= 0.1.0 =
* First release. Product import: real simple and variable products, photos copied into the Media Library, categories, out-of-stock, enquiry-only, resync on a schedule and a Sync now button.

== Upgrade Notice ==

= 0.1.0 =
First release. Product import only — delivery and checkout come next.
