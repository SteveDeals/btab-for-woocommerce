<?php
/**
 * Plugin Name:       btab for WooCommerce
 * Description:       Sell btab products from your own WooCommerce shop. Products are copied in and kept fresh; the buyer checks out on btab.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * WC requires at least: 9.0
 * Author:            btab
 * License:           GPL-2.0-or-later
 * Text Domain:       btab-woocommerce
 *
 * @package btab-woocommerce
 *
 * ── HOW THIS WORKS, in one place ─────────────────────────────────────────────
 * Products are REAL WooCommerce products, copied in and kept fresh. Not shells
 * with a live price filter, and not a custom data store. The reason is that
 * Woo's own sort-by-price, price filters, stock filters, Product Collection,
 * sitemaps, search and JSON-LD all read the stored values (wc_product_meta_lookup,
 * _price meta, the stock_status lookup) and never a filter, so anything else
 * shows one price and sorts by another. Every serious catalogue plugin copies
 * real products for the same reason.
 *
 * NOTHING CALLS btab WHILE A PAGE RENDERS. Product pages stay ordinary cached
 * WordPress pages. btab is called when it TELLS US something changed (a signed
 * event, includes/class-btab-events.php), on the nightly reconcile, on
 * "Sync now", and at "Place order".
 *
 * THE SHOP HEARS, IT DOES NOT WAIT (Steve, 2026-10-21: "If someone updates
 * their store that's the schedule"). A timer means a price change can sit for
 * an hour. The scheduled run is the safety net for a delivery that never
 * arrived, not the mechanism.
 *
 * A PRICE IN WOO IS A CACHED COPY. btab prices every line at checkout, exactly
 * as a btab storefront re-prices a cached page. The plugin never sends a price.
 *
 * Contract: btab-api docs/api/STOREFRONT_API.md @ origin/main
 * de32e9fdc9c2ef514cf3196bf554a5ec6683ef03 (read 2026-10-21).
 */

defined('ABSPATH') || exit;

define('BTAB_WC_VERSION', '0.2.0');
define('BTAB_WC_FILE', __FILE__);
define('BTAB_WC_DIR', plugin_dir_path(__FILE__));
define('BTAB_WC_URL', plugin_dir_url(__FILE__));

/** The option that holds every setting, as one array. */
define('BTAB_WC_OPTION', 'btab_wc_settings');

/** Post meta that marks a product as btab's, so the plugin never touches a shop's own goods. */
define('BTAB_WC_META_PRODUCT_ID', '_btab_product_id');
define('BTAB_WC_META_SYNCED_AT', '_btab_synced_at');

require_once BTAB_WC_DIR . 'lib/class-btab-client.php';
require_once BTAB_WC_DIR . 'includes/class-btab-settings.php';
require_once BTAB_WC_DIR . 'includes/class-btab-importer.php';
require_once BTAB_WC_DIR . 'includes/class-btab-sync.php';
require_once BTAB_WC_DIR . 'includes/class-btab-catalog.php';
require_once BTAB_WC_DIR . 'includes/class-btab-cart.php';
require_once BTAB_WC_DIR . 'includes/class-btab-delivery.php';
require_once BTAB_WC_DIR . 'includes/class-btab-checkout.php';
require_once BTAB_WC_DIR . 'includes/class-btab-events.php';
require_once BTAB_WC_DIR . 'includes/class-btab-orders.php';
require_once BTAB_WC_DIR . 'includes/class-btab-locked-fields.php';
require_once BTAB_WC_DIR . 'includes/class-btab-upgrade.php';

/**
 * WooCommerce has to be active: this plugin has nothing to do without it, and
 * a fatal on a live shop is the worst possible way to say so.
 */
function btab_wc_requirements_met()
{
    return class_exists('WooCommerce');
}

add_action('admin_notices', function () {
    if (btab_wc_requirements_met()) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>btab for WooCommerce</strong> needs WooCommerce to be installed and active.</p></div>';
});

/** The configured client, or null when there is no usable key yet. */
function btab_wc_client()
{
    $s = Btab_WC_Settings::get();
    if (empty($s['api_base']) || empty($s['publishable_key'])) {
        return null;
    }
    $client = new Btab_Client($s['api_base'], $s['publishable_key'], 15, 'btab_wc_wp_transport');
    return $client->key_problem() === null ? $client : null;
}

/**
 * WordPress's own HTTP API as the transport, so a shop's proxy, CA bundle and
 * timeouts apply, and the core library stays free of WordPress.
 */
function btab_wc_wp_transport($method, $url, array $headers, $body, $timeout)
{
    $args = array(
        'method' => $method,
        'headers' => $headers,
        'timeout' => $timeout,
        'redirection' => 0,
    );
    if ($body !== null) {
        $args['body'] = $body;
    }
    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
        return array('status' => 0, 'body' => '', 'error' => $res->get_error_message());
    }
    return array(
        'status' => (int) wp_remote_retrieve_response_code($res),
        'body' => (string) wp_remote_retrieve_body($res),
    );
}

add_action('plugins_loaded', function () {
    if (!btab_wc_requirements_met()) {
        return;
    }
    Btab_WC_Settings::boot();
    Btab_WC_Sync::boot();
    Btab_WC_Catalog::boot();
    Btab_WC_Delivery::boot();
    Btab_WC_Checkout::boot();
    Btab_WC_Events::boot();
    Btab_WC_Orders::boot();
    Btab_WC_Locked_Fields::boot();
    Btab_WC_Upgrade::boot();
});

/**
 * On activation, schedule the resync. On deactivation, unschedule it - a
 * cron event left behind after the plugin is gone fires into nothing forever.
 * Imported products are deliberately NOT deleted: a shop's order history
 * points at them.
 */
register_activation_hook(__FILE__, function () {
    if (btab_wc_requirements_met()) {
        Btab_WC_Sync::reschedule();
    }
});
register_deactivation_hook(__FILE__, function () {
    Btab_WC_Sync::unschedule();
    // The one-off upgrade resync too: a job left pointing at a plugin that is
    // gone fires into nothing for ever.
    wp_clear_scheduled_hook(Btab_WC_Upgrade::RESYNC_HOOK);
});
