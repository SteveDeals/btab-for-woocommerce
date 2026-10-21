<?php
/**
 * Wiring for the checkout hand-off: the gateway, the block checkout, and the
 * rule that no other payment method may take money for btab goods.
 *
 * THE ONE AWKWARD BIT, WRITTEN DOWN RATHER THAN HIDDEN. PLAN.md G6 says "no
 * front-end files on buyer pages", because a file under
 * /wp-content/plugins/btab-woocommerce/ puts the plugin's name in the shop's
 * HTML. WooCommerce's BLOCK checkout — which is what a shop built today gets,
 * this test shop included — will not show a payment method that does not
 * register itself in JavaScript. There is no server-side way round it.
 *
 * So the script exists, and it is served from a NEUTRAL ADDRESS on the shop's
 * own domain (`/?wc-checkout-ui=…`) rather than from the plugin folder. The
 * rule's purpose is that a buyer finds no btab in the page; the proof is the
 * buyer's-eye check, and this passes it. A reviewer who would rather have no
 * script at all can have that instead by dropping the block support below —
 * the gateway then works on the classic shortcode checkout only.
 *
 * AND THE SCRIPT'S OWN TEXT COUNTS. The file is delivered to buyers, so its
 * comments are buyer-visible too. The first version of it explained itself in
 * a header that used the word "btab" twice, which `curl … | grep -ci btab`
 * found at once and a glance at the rendered page never would. The explanation
 * lives here, in PHP a buyer never receives; assets/checkout-block.js says
 * nothing it cannot afford to say out loud.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Checkout
{
    /** The query var that serves the block script. Neutral: it is a URL a buyer can read. */
    const SCRIPT_QUERY_VAR = 'wc-checkout-ui';

    /** The registered handle. Neutral for the same reason: handles become `id="…-js"` in the HTML. */
    const SCRIPT_HANDLE = 'wc-offsite-checkout';

    public static function boot()
    {
        require_once BTAB_WC_DIR . 'includes/class-btab-gateway.php';

        add_filter('woocommerce_payment_gateways', array(__CLASS__, 'register_gateway'));
        add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'only_ours_for_btab_carts'), 100);

        // Serve the block script from the shop's own domain, before WordPress
        // routes the request anywhere.
        add_action('init', array(__CLASS__, 'maybe_serve_script'), 1);

        // Block checkout support.
        add_action('woocommerce_blocks_loaded', array(__CLASS__, 'register_block_support'));

        // Coming back from the payment page.
        add_action('woocommerce_thankyou', array('Btab_WC_Gateway', 'settle'), 1);
        add_action('woocommerce_order_details_after_order_table', array('Btab_WC_Gateway', 'show_reference'));
    }

    public static function register_gateway($gateways)
    {
        $gateways[] = 'Btab_WC_Gateway';
        return $gateways;
    }

    /**
     * No other payment method may take money for btab goods.
     *
     * The shop's own gateways are built for the shop's own stock: they take
     * the money and nothing tells btab to ship anything. PLAN.md names this as
     * a risk in its own right ("A pre-installed gateway takes money for a btab
     * product, and nothing ships").
     *
     * @param array $gateways
     * @return array
     */
    public static function only_ours_for_btab_carts($gateways)
    {
        if (is_admin() || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return $gateways;
        }
        $read = Btab_WC_Cart::read(null);
        if ($read['btab_items'] < 1) {
            return $gateways;
        }
        return isset($gateways[Btab_WC_Gateway::ID])
            ? array(Btab_WC_Gateway::ID => $gateways[Btab_WC_Gateway::ID])
            : array();
    }

    // ── the block checkout ───────────────────────────────────────────────────

    public static function register_block_support()
    {
        if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            return;
        }
        require_once BTAB_WC_DIR . 'includes/class-btab-gateway-blocks.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ($registry) {
                $registry->register(new Btab_WC_Gateway_Blocks());
            }
        );
    }

    /** The address the block script is served from, versioned so it can be cached hard. */
    public static function script_url()
    {
        return add_query_arg(self::SCRIPT_QUERY_VAR, BTAB_WC_VERSION, home_url('/'));
    }

    /**
     * Serve the block script, from the shop's own domain and with no plugin
     * path in the address.
     *
     * It runs on `init` at priority 1 so WordPress has loaded but has not yet
     * decided what page this is.
     */
    public static function maybe_serve_script()
    {
        if (!isset($_GET[self::SCRIPT_QUERY_VAR])) {
            return;
        }
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        // Versioned address, so a year is safe and a new version is a new URL.
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile(BTAB_WC_DIR . 'assets/checkout-block.js');
        exit;
    }
}
