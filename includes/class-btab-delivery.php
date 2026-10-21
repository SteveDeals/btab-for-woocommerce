<?php
/**
 * Delivery: btab prices the freight, by postcode, and nothing else may.
 *
 * WHY A SHIPPING METHOD AND NOT A FEE. WooCommerce's cart, checkout, order
 * total, tax report and "Calculate shipping" all read shipping RATES. A fee
 * would show the right number on the cart page and the wrong one everywhere
 * else.
 *
 * WHY IT IGNORES SHIPPING ZONES. A WC_Shipping_Method whose `supports` does
 * not include 'shipping-zones' is loaded for EVERY package, whatever the
 * zones say (WC_Shipping::calculate_shipping_for_package). That is what we
 * want: btab's freight is not the shop's to configure, and a shop owner who
 * never adds the method to a zone must not end up charging their own flat
 * rate for btab's goods. The default `$supports` is array('settings'), so
 * this class simply does not opt in.
 *
 * AND WHY THE SHOP'S OWN RATES ARE REMOVED. Having loaded globally is only
 * half of it: the zone's own Flat rate is still in the list. On a package
 * holding btab goods, `woocommerce_package_rates` strips everything that is
 * not ours, so btab's figure is the only one a buyer can pick (PLAN.md risk
 * "A Woo total differs from btab's total").
 *
 * NOTHING HERE IS NAMED btab WHERE A BUYER CAN READ IT. The method id is
 * rendered into the checkout as `value="store_delivery"` and
 * `id="shipping_method_0_store_delivery"`; the label is "Delivery". PLAN.md
 * G6 counts an id in delivered HTML as text a buyer can read.
 *
 * MIXED CARTS ARE REFUSED, NOT GUESSED (PLAN.md "What NOT to build yet").
 * With btab goods and the shop's own goods in one package there is no honest
 * single freight price, so no rate is offered and the buyer is told to order
 * them separately.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Delivery
{
    /**
     * The shipping method id. NEUTRAL ON PURPOSE and it must stay neutral:
     * WooCommerce prints it into the checkout as the radio value and the input
     * id, so a buyer can read it in view-source.
     */
    const METHOD_ID = 'store_delivery';

    public static function boot()
    {
        add_filter('woocommerce_shipping_methods', array(__CLASS__, 'register_method'));
        // Late, so every zone method has already added its rate and can be removed.
        add_filter('woocommerce_package_rates', array(__CLASS__, 'only_btab_rate_on_btab_packages'), 100, 2);
        add_action('woocommerce_order_item_shipping_after_calculate_taxes', array(__CLASS__, 'no_tax_on_our_shipping'), 10, 1);
    }

    public static function register_method($methods)
    {
        require_once BTAB_WC_DIR . 'includes/class-btab-shipping-method.php';
        $methods[self::METHOD_ID] = 'Btab_WC_Shipping_Method';
        return $methods;
    }

    /**
     * On a package holding btab goods, btab's rate is the only rate.
     *
     * @param array $rates
     * @param array $package
     * @return array
     */
    public static function only_btab_rate_on_btab_packages($rates, $package)
    {
        $read = Btab_WC_Cart::read($package);
        if ($read['btab_items'] < 1) {
            return $rates;
        }
        $kept = array();
        foreach ($rates as $key => $rate) {
            if ($rate instanceof WC_Shipping_Rate && $rate->get_method_id() === self::METHOD_ID) {
                $kept[$key] = $rate;
            }
        }
        return $kept;
    }

    /**
     * Keep the ORDER's freight line untaxed, as the cart's already is.
     *
     * THE SHORT VERSION: without this the shop's order said $73.64 where btab
     * charged $71.95. Measured on a real test order, not reasoned about.
     *
     * The long version, because it is not obvious and the next person will
     * hit it. WooCommerce taxes shipping in two separate places. The cart
     * honours `'taxes' => false` on the rate and the method's `tax_status`.
     * The ORDER does neither: WC_Order_Item_Shipping::calculate_taxes()
     * recomputes from the shipping tax class, and the only escape it offers is
     * its own get_tax_status(), which resolves the method by INSTANCE ID —
     * `WC_Shipping_Zones::get_shipping_method( $this->get_instance_id() )`.
     * Our method is zone-less by design (see the top of this file), so its
     * instance id is 0, the lookup returns false, and WooCommerce falls back
     * to "taxable" whatever the method says. The property is not ignored; it
     * is unreachable for a method that is not in a zone.
     *
     * So the order's line is cleared here, on the action WooCommerce fires at
     * the end of that very method, and only for our own rate.
     *
     * @param WC_Order_Item_Shipping $item
     */
    public static function no_tax_on_our_shipping($item)
    {
        if ($item instanceof WC_Order_Item_Shipping && $item->get_method_id() === self::METHOD_ID) {
            $item->set_taxes(false);
        }
    }

    /**
     * Say something once per page load. Shipping is calculated more than once
     * per request (cart totals, then the checkout review), so without this the
     * same sentence appears three times.
     *
     * @param string $message
     * @param string $type
     */
    public static function notice_once($message, $type = 'notice')
    {
        if (!function_exists('wc_add_notice') || !function_exists('wc_has_notice')) {
            return;
        }
        if (!wc_has_notice($message, $type)) {
            wc_add_notice($message, $type);
        }
    }

    /** Problems go to the shop's log, never to the page: they may name btab. */
    public static function log($message)
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, array('source' => 'btab'));
        }
    }
}
