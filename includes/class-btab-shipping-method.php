<?php
/**
 * The shipping method itself. See class-btab-delivery.php for why it exists
 * and why it does not use shipping zones.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Shipping_Method extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = Btab_WC_Delivery::METHOD_ID;
        $this->instance_id = absint($instance_id);
        // Admin-side labels. `title` below is the only one a buyer sees.
        $this->method_title = __('Delivery (btab products)', 'btab-woocommerce');
        $this->method_description = __('Freight for btab products, priced by btab for each cart and postcode. It applies to every destination, so it does not belong to a shipping zone, and it replaces this shop\'s own rates on any cart holding btab products.', 'btab-woocommerce');

        // Deliberately NOT 'shipping-zones': that is what makes it global.
        $this->supports = array('settings');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Delivery', 'btab-woocommerce'));

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable', 'btab-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Let btab price delivery for carts holding btab products', 'btab-woocommerce'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('What the buyer sees', 'btab-woocommerce'),
                'type' => 'text',
                'description' => __('The name shown at the cart and the checkout. Keep it plain.', 'btab-woocommerce'),
                'default' => __('Delivery', 'btab-woocommerce'),
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Ask btab what the freight is, and add it as the rate.
     *
     * Every path out of here that offers no rate is deliberate, and each one
     * says why in the code below. "No rate" is never silent money: the buyer
     * either sees a reason or sees no delivery option, and WooCommerce will
     * not let the order through without one.
     *
     * @param array $package
     */
    public function calculate_shipping($package = array())
    {
        $read = Btab_WC_Cart::read($package);

        // Not ours. The shop's own rates stand.
        if ($read['btab_items'] < 1) {
            return;
        }

        // btab goods next to the shop's own goods: PLAN.md keeps mixed carts
        // out of v0, so there is no honest single price. Offer nothing and say
        // why; only btab rates survive the package filter, so the checkout is
        // closed until the buyer splits the order.
        if ($read['other_items'] > 0) {
            Btab_WC_Delivery::notice_once(
                __('Some of these items are delivered separately from the rest. Please place them as two orders.', 'btab-woocommerce'),
                'error'
            );
            return;
        }

        $destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
        $postcode = isset($destination['postcode']) ? trim((string) $destination['postcode']) : '';
        $country = isset($destination['country']) ? (string) $destination['country'] : '';

        // No postcode yet — the buyer has not filled the calculator in. Say
        // nothing: WooCommerce's own "enter your address" prompt covers it.
        if ($postcode === '' || $country === '') {
            return;
        }

        $client = btab_wc_client();
        if (!$client) {
            Btab_WC_Delivery::log('Delivery not priced: no usable publishable key.');
            return;
        }

        try {
            $estimate = Btab_WC_Cart::estimate($client, $read['lines'], $country, $postcode);
        } catch (Btab_Api_Exception $e) {
            Btab_WC_Delivery::log('Shipping estimate failed: ' . $e->getMessage());
            Btab_WC_Delivery::notice_once(
                __('We could not work out delivery just now. Please try again in a moment.', 'btab-woocommerce'),
                'error'
            );
            return;
        }

        if (empty($estimate['available'])) {
            $this->explain_refusal($estimate);
            return;
        }

        $cents = isset($estimate['shipping_cents']) ? (int) $estimate['shipping_cents'] : null;
        if ($cents === null) {
            Btab_WC_Delivery::log('Shipping estimate had no shipping_cents: ' . wp_json_encode($estimate));
            return;
        }

        $this->add_rate(array(
            'id' => $this->id,
            'label' => $this->title,
            'cost' => $cents / 100,
            // btab's figure is what btab charges, tax included. WooCommerce
            // must not add its own shipping tax on top, or the shop's total
            // stops matching the amount the buyer is actually charged.
            'taxes' => false,
            'package' => $package,
        ));
    }

    /**
     * Turn btab's refusal into one plain sentence, or into silence when the
     * buyer has simply not finished the address.
     *
     * @param array $estimate
     */
    private function explain_refusal(array $estimate)
    {
        $reason = isset($estimate['reason']) ? (string) $estimate['reason'] : '';

        // Not an answer about these goods — the address is half filled in.
        if ($reason === 'address_incomplete') {
            return;
        }

        // The one refusal with its own words: btab cannot size the item, so it
        // needs a quote. Showing $0 here would be the bug PLAN.md 2.6 is about.
        if ($reason === 'SHIPPING_VOLUME_UNKNOWN') {
            Btab_WC_Delivery::notice_once(
                __('One of these items needs a delivery quote. Please get in touch and we will price it for you.', 'btab-woocommerce'),
                'notice'
            );
            return;
        }

        if ($reason === 'items_unavailable') {
            Btab_WC_Delivery::notice_once(
                __('Something in your cart is no longer available. Please check the items and try again.', 'btab-woocommerce'),
                'error'
            );
            return;
        }

        // Anything else: the buyer gets one honest sentence, the shop's log
        // gets the detail, because the detail may name btab.
        Btab_WC_Delivery::log('Shipping estimate refused: ' . wp_json_encode($estimate));
        Btab_WC_Delivery::notice_once(
            __('We could not work out delivery to that postcode. Please get in touch.', 'btab-woocommerce'),
            'notice'
        );
    }
}
