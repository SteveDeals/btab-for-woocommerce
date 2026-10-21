<?php
/**
 * The payment method: "Place order" hands the cart to btab and sends the
 * buyer to btab's own checkout page on the SHOP'S OWN DOMAIN.
 *
 * WHAT THE BUYER SEES, end to end, and why none of it says btab:
 *   the method id      `secure_checkout` — WooCommerce prints it into the
 *                      checkout as a radio value and an input id, which is
 *                      text in delivered HTML (PLAN.md G6)
 *   the method title   whatever the shop owner sets; "Secure checkout" by default
 *   where they land    checkout.<the shop's own domain>, because the store has
 *                      a custom domain (PLAN.md 1.4). The plugin never builds
 *                      that address: btab returns it as `continue_url`.
 *   coming back        the shop's own thank-you page, carrying the Woo order
 *                      id and key, because we put them in `return_url`
 *   the emails         WooCommerce's own, from the shop's domain (decision 2)
 *
 * THE MONEY IS NEVER OURS TO DECIDE. The plugin sends ids, options and
 * quantities. btab prices the cart, takes the payment and is the seller of
 * record. This file marks the Woo order to match what btab says happened; it
 * never decides that something was paid.
 *
 * NOTHING IS TRUSTED FROM THE RETURN. The buyer comes back through a plain
 * link with no signature, so the return is only a prompt to ASK btab
 * (`GET /checkout_sessions/{id}`). An order is marked paid because btab said
 * `complete`, never because a buyer arrived at a URL.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Gateway extends WC_Payment_Gateway
{
    /** NEUTRAL, and it must stay neutral: it is rendered into the checkout HTML. */
    const ID = 'secure_checkout';

    const META_SESSION = '_btab_checkout_session_id';
    const META_CART = '_btab_cart_id';
    const META_ORDER_NUMBER = '_btab_order_number';

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = false;
        $this->method_title = __('Secure checkout (btab products)', 'btab-woocommerce');
        $this->method_description = __('Carts holding btab products are paid for on btab\'s checkout page, served on your own domain. btab prices the order, takes the payment and is the seller of record; this shop keeps a copy of the order and sends its own emails.', 'btab-woocommerce');
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Secure checkout', 'btab-woocommerce'));
        $this->description = $this->get_option('description', __('You will be taken to our secure payment page to finish your order.', 'btab-woocommerce'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable', 'btab-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Let buyers pay for btab products', 'btab-woocommerce'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('What the buyer sees', 'btab-woocommerce'),
                'type' => 'text',
                'default' => __('Secure checkout', 'btab-woocommerce'),
                'desc_tip' => true,
                'description' => __('The name of the payment option at the checkout. Keep it plain.', 'btab-woocommerce'),
            ),
            'description' => array(
                'title' => __('The line underneath', 'btab-woocommerce'),
                'type' => 'textarea',
                'default' => __('You will be taken to our secure payment page to finish your order.', 'btab-woocommerce'),
                'desc_tip' => true,
                'description' => __('Shown under the payment option. Say that the buyer leaves this page, so nobody is surprised.', 'btab-woocommerce'),
            ),
        );
    }

    /**
     * Offered only for a cart that is entirely btab's.
     *
     * A mixed cart has no rate either (see class-btab-delivery.php), so the
     * checkout is already closed; refusing here as well means the two never
     * disagree about which carts this plugin can handle.
     */
    public function is_available()
    {
        if ($this->enabled !== 'yes' || !btab_wc_client()) {
            return false;
        }
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            // The settings screen and the block checkout both ask before
            // there is a cart. Say yes; the cart check below decides for real.
            return true;
        }
        $read = Btab_WC_Cart::read(null);
        return $read['btab_items'] > 0 && $read['other_items'] === 0;
    }

    /**
     * Make the btab cart, open a checkout session, send the buyer there.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return $this->failed(__('That order could not be found.', 'btab-woocommerce'));
        }

        $client = btab_wc_client();
        if (!$client) {
            Btab_WC_Delivery::log('Checkout refused: no usable publishable key.');
            return $this->failed(__('We cannot take payment just now. Please get in touch.', 'btab-woocommerce'));
        }

        $read = Btab_WC_Cart::read_order($order);
        if ($read['btab_items'] < 1 || $read['other_items'] > 0) {
            return $this->failed(__('These items cannot be paid for together. Please place them as two orders.', 'btab-woocommerce'));
        }

        try {
            $cart_id = Btab_WC_Cart::remote_cart_id($client, $read['lines']);
            if (!$cart_id) {
                throw new Btab_Api_Exception('No cart id came back.');
            }
            // The thank-you page's own URL, which already carries the order id
            // and its key. The API checks the ORIGIN against the key's allowed
            // sites and keeps the rest, so the buyer comes back to the right
            // order without us inventing a token of our own.
            $return_url = $order->get_checkout_order_received_url();

            // A FRESH key each attempt, on purpose. Idempotency is here to stop
            // one attempt becoming two sessions, not to make a second attempt
            // replay the first: a session lasts 30 minutes, and a buyer who
            // comes back to try again needs a new one, not an expired answer.
            $session = $client->create_checkout_session($cart_id, $return_url, wp_generate_uuid4());
        } catch (Btab_Api_Exception $e) {
            Btab_WC_Delivery::log('Checkout session failed: ' . $e->getMessage());
            return $this->failed(__('We could not start the payment. Please try again in a moment.', 'btab-woocommerce'));
        }

        $cs = isset($session['checkout_session']) && is_array($session['checkout_session']) ? $session['checkout_session'] : array();
        $continue = isset($cs['continue_url']) ? (string) $cs['continue_url'] : '';
        $session_id = isset($cs['id']) ? (string) $cs['id'] : '';
        if ($continue === '' || $session_id === '') {
            Btab_WC_Delivery::log('Checkout session had no continue_url: ' . wp_json_encode($session));
            return $this->failed(__('We could not start the payment. Please try again in a moment.', 'btab-woocommerce'));
        }

        $order->update_meta_data(self::META_SESSION, $session_id);
        $order->update_meta_data(self::META_CART, $cart_id);
        $order->add_order_note(__('Sent to the payment page. Waiting to hear that it was paid.', 'btab-woocommerce'));
        $order->save();

        return array('result' => 'success', 'redirect' => $continue);
    }

    /** One plain sentence for the buyer, and no order left half-made. */
    private function failed($message)
    {
        wc_add_notice($message, 'error');
        return array('result' => 'failure', 'messages' => $message);
    }

    // ── coming back ──────────────────────────────────────────────────────────

    /**
     * The buyer is back on the thank-you page. ASK btab what happened.
     *
     * The return carries no proof of payment — it is a plain link — so this
     * never reads anything from the URL beyond which order it is. The answer
     * comes from btab.
     *
     * @param int $order_id
     */
    public static function settle($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== self::ID || $order->is_paid()) {
            return;
        }
        $session_id = (string) $order->get_meta(self::META_SESSION);
        if ($session_id === '') {
            return;
        }
        $client = btab_wc_client();
        if (!$client) {
            return;
        }

        try {
            $answer = $client->get_checkout_session($session_id);
        } catch (Btab_Api_Exception $e) {
            Btab_WC_Delivery::log('Could not read checkout session ' . $session_id . ': ' . $e->getMessage());
            return;
        }

        $cs = isset($answer['checkout_session']) && is_array($answer['checkout_session']) ? $answer['checkout_session'] : array();
        $status = isset($cs['status']) ? (string) $cs['status'] : '';

        if ($status === 'complete') {
            // btab's own order number when it is in the answer. It is not in
            // the session today (the API returns id, status, livemode,
            // return_url, expires_at, completed_at), so this reads it if it
            // appears and falls back to the session id, which is what the
            // shop can quote back to btab either way.
            $number = isset($cs['order_number']) && $cs['order_number'] ? (string) $cs['order_number'] : '';
            if ($number !== '') {
                $order->update_meta_data(self::META_ORDER_NUMBER, $number);
            }
            $order->payment_complete($number !== '' ? $number : $session_id);
            $order->add_order_note(sprintf(
                /* translators: %s: the supplier's order reference */
                __('Paid. Supplier order reference: %s', 'btab-woocommerce'),
                $number !== '' ? $number : $session_id
            ));
            $order->save();
            Btab_WC_Cart::forget();
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            return;
        }

        if ($status === 'expired') {
            $order->update_status('failed', __('The payment page expired before it was paid.', 'btab-woocommerce'));
            return;
        }

        // 'open': the buyer came back without finishing. Leave the order
        // pending so they can pay it, and say so rather than showing a
        // thank-you for an order nobody paid for.
        Btab_WC_Delivery::notice_once(
            __('Your payment has not finished yet. Your order is saved and is waiting for payment.', 'btab-woocommerce'),
            'notice'
        );
    }

    /**
     * The supplier's reference under the order table, for a buyer who needs to
     * quote it. Neutral wording: it is a reference, not a brand.
     *
     * @param WC_Order $order
     */
    public static function show_reference($order)
    {
        if (!$order instanceof WC_Order || $order->get_payment_method() !== self::ID) {
            return;
        }
        $number = (string) $order->get_meta(self::META_ORDER_NUMBER);
        if ($number === '') {
            return;
        }
        echo '<p class="woocommerce-order-reference"><strong>' . esc_html__('Order reference:', 'btab-woocommerce') . '</strong> ' . esc_html($number) . '</p>';
    }
}
