<?php
/**
 * Tells WooCommerce's block checkout that the payment method exists.
 *
 * It carries no payment logic: the block UI is a label, a sentence and a radio
 * button. Everything that matters happens in Btab_WC_Gateway::process_payment
 * on the server, and the block follows the redirect the server returns.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Btab_WC_Gateway_Blocks extends AbstractPaymentMethodType
{
    /** @var string Must match the gateway id: the block looks the gateway up by it. */
    protected $name = Btab_WC_Gateway::ID;

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . Btab_WC_Gateway::ID . '_settings', array());
    }

    public function is_active()
    {
        $gateway = $this->gateway();
        return $gateway ? $gateway->is_available() : false;
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            Btab_WC_Checkout::SCRIPT_HANDLE,
            Btab_WC_Checkout::script_url(),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'),
            BTAB_WC_VERSION,
            true
        );
        return array(Btab_WC_Checkout::SCRIPT_HANDLE);
    }

    /**
     * What the script reads. Nothing here may name btab: it is printed into
     * the page as JSON for the block to pick up.
     */
    public function get_payment_method_data()
    {
        $gateway = $this->gateway();
        return array(
            'title' => $gateway ? $gateway->get_title() : __('Secure checkout', 'btab-woocommerce'),
            'description' => $gateway ? $gateway->get_description() : '',
            'supports' => $gateway ? array_filter($gateway->supports, array($gateway, 'supports')) : array('products'),
        );
    }

    /** @return WC_Payment_Gateway|null */
    private function gateway()
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        return isset($gateways[Btab_WC_Gateway::ID]) ? $gateways[Btab_WC_Gateway::ID] : null;
    }
}
