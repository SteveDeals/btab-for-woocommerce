<?php
/**
 * The shop hears about a change instead of waiting for a clock.
 *
 * Steve, 2026-10-21: "If someone updates their store that's the schedule."
 * A resync on a timer means a price change can sit for an hour before a buyer
 * sees it. btab already sends signed events when a store's catalogue changes
 * (product.created / product.updated / product.deleted / inventory.updated);
 * this is the ear for them. WP-Cron stays as a NIGHTLY RECONCILE — the safety
 * net for a delivery that never arrived, never the mechanism.
 *
 * It is also the ear for what happens to an ORDER after the buyer walks away:
 * `checkout_session.completed`, `order.paid` and `order.refunded`, handled in
 * class-btab-orders.php. That matters most when the buyer never comes back to
 * the thank-you page at all — closed the tab, lost signal, went to make a cup
 * of tea — in which case nothing else would ever mark the order paid.
 *
 * ── THIS ROUTE IS PUBLIC, so read it as an attacker would ────────────────────
 * It is the one place an outsider can reach this plugin. Therefore:
 *   • the SIGNATURE IS CHECKED BEFORE THE BODY IS USED, over the RAW bytes.
 *     Nothing is parsed, looked up or written until it verifies.
 *   • the timestamp must be inside a 300-second window, so a body captured
 *     once cannot be replayed tomorrow.
 *   • comparison is constant-time (hash_equals), so the secret cannot be
 *     guessed a byte at a time by timing the answer.
 *   • an unsigned, wrongly signed or stale request gets a flat 401 and
 *     changes nothing. It is not told which of the three it was.
 *   • the secret is never echoed, never logged, and never sent to a page.
 *
 * ── THE BODY IS A NUDGE, NOT NEWS ────────────────────────────────────────────
 * btab's events are ids-only on purpose, and may be coalesced or arrive out of
 * order. So this never writes what the payload says. It takes the product id,
 * RE-READS that product from the API, and stores what the API answers. An
 * out-of-order delivery is then harmless: both of them read the same current
 * state.
 *
 * ── AND ONLY THAT PRODUCT ────────────────────────────────────────────────────
 * One event touches one product. A full catalogue walk belongs to the nightly
 * reconcile.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Events
{
    /**
     * The REST namespace and route. NEUTRAL, like every other address this
     * plugin puts into the world: WordPress lists every namespace publicly at
     * /wp-json/, so this one is readable by anyone who looks.
     */
    const NAMESPACE_V1 = 'store-sync/v1';
    const ROUTE = '/catalogue';

    /** How far out of time a delivery may be, in seconds. btab signs with the same window. */
    const TOLERANCE = 300;

    /** How long a handled event id is remembered, so a redelivery changes nothing. */
    const SEEN_TTL = DAY_IN_SECONDS;

    public static function boot()
    {
        add_action('rest_api_init', array(__CLASS__, 'register_route'));
    }

    public static function register_route()
    {
        register_rest_route(self::NAMESPACE_V1, self::ROUTE, array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle'),
            // Public by design: the signature is the authentication, and it is
            // checked inside the callback over the raw body. Returning true
            // here is the WordPress way of saying "no cookie or capability
            // check", NOT "anyone may change anything".
            'permission_callback' => '__return_true',
        ));
    }

    /** The address a shop owner copies into btab. */
    public static function receiver_url()
    {
        return rest_url(self::NAMESPACE_V1 . self::ROUTE);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle($request)
    {
        $settings = Btab_WC_Settings::get();
        $secret = isset($settings['webhook_secret']) ? (string) $settings['webhook_secret'] : '';
        if ($secret === '') {
            // Nothing is connected yet. Say no, and say nothing else.
            return new WP_REST_Response(null, 401);
        }

        $raw = $request->get_body();
        if (!self::signature_ok($secret, $raw, (string) $request->get_header('x-btab-signature'))) {
            return new WP_REST_Response(null, 401);
        }

        // Only now is the body worth reading.
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return new WP_REST_Response(null, 400);
        }

        $name = isset($event['event']) ? (string) $event['event'] : '';
        $key = isset($event['event_key']) ? (string) $event['event_key'] : '';
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
        $btab_id = isset($data['product_id']) ? (int) $data['product_id'] : 0;
        $is_order = in_array($name, array('checkout_session.completed', 'order.paid', 'order.refunded'), true);

        // A redelivery, or the same event through two subscriptions, must change
        // nothing the second time. btab's own guide says to key on event_key.
        //
        // MARKED SEEN ONLY ONCE IT HAS WORKED (review LOW 7). Marking it before
        // meant that if apply() threw, this answered 500 so btab retried — and
        // the retry was then answered "repeat" and never applied. The failure
        // was silently permanent, which is the worst shape a retry ladder can
        // have.
        $seen = $key !== '' ? 'btab_wc_evt_' . md5($key) : '';
        if ($seen !== '' && get_transient($seen)) {
            return new WP_REST_Response(array('ok' => true, 'repeat' => true), 200);
        }

        if (!$btab_id && !$is_order) {
            // An event about something this plugin does not mirror. Accepted,
            // so btab does not retry it, and ignored.
            if ($seen !== '') {
                set_transient($seen, 1, self::SEEN_TTL);
            }
            return new WP_REST_Response(array('ok' => true), 200);
        }

        try {
            if ($is_order) {
                Btab_WC_Orders::apply($name, $data);
            } else {
                self::apply($name, $btab_id);
            }
        } catch (Exception $e) {
            // 500 so btab retries on its own ladder. The detail goes to the
            // shop's log; it may name btab, and it must not reach a response
            // body that could be read by whoever sent the request.
            Btab_WC_Delivery::log('Event ' . $name . ' failed: ' . $e->getMessage());
            return new WP_REST_Response(null, 500);
        }

        if ($seen !== '') {
            set_transient($seen, 1, self::SEEN_TTL);
        }
        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Do the change, by re-reading the product rather than believing the event.
     *
     * @param string $name
     * @param int    $btab_id
     * @throws Exception
     */
    private static function apply($name, $btab_id)
    {
        if ($name === 'product.deleted') {
            self::hide($btab_id);
            return;
        }

        $client = btab_wc_client();
        if (!$client) {
            throw new Exception('No usable publishable key.');
        }

        $importer = new Btab_WC_Importer($client);
        try {
            $importer->import_one($btab_id);
        } catch (Btab_Api_Exception $e) {
            // btab no longer serves it to this key: it has been delisted or
            // unpublished. That is a real answer, not a failure, and it is the
            // same outcome as product.deleted.
            if (in_array($e->get_status(), array(403, 404, 410), true)) {
                self::hide($btab_id);
                return;
            }
            throw $e;
        }
    }

    /**
     * Gone from btab: draft and hide, NEVER delete. The shop's existing orders
     * point at this product (the same rule the full sync follows).
     *
     * @param int $btab_id
     */
    private static function hide($btab_id)
    {
        $woo_id = Btab_WC_Importer::find_by_btab_id($btab_id);
        if (!$woo_id) {
            return;
        }
        $product = wc_get_product($woo_id);
        if (!$product) {
            return;
        }
        $product->set_status('draft');
        $product->set_catalog_visibility('hidden');
        $product->save();
    }

    /**
     * btab signs with Stripe's scheme, verbatim:
     *   X-Btab-Signature: t=<unix seconds>, v1=<hex HMAC-SHA256 of "<t>.<raw body>">
     *
     * @param string $secret
     * @param string $raw    the RAW request body, byte for byte
     * @param string $header
     * @return bool
     */
    public static function signature_ok($secret, $raw, $header)
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = '';
        $v1 = '';
        foreach (explode(',', $header) as $part) {
            $bits = explode('=', trim($part), 2);
            if (count($bits) !== 2) {
                continue;
            }
            if ($bits[0] === 't') {
                $t = $bits[1];
            } elseif ($bits[0] === 'v1') {
                $v1 = $bits[1];
            }
        }
        if ($t === '' || $v1 === '' || !ctype_digit($t)) {
            return false;
        }
        // A body captured once must not work tomorrow.
        if (abs(time() - (int) $t) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
        // Constant time: a byte-by-byte compare leaks the secret to anyone
        // patient enough to time the answers.
        return hash_equals($expected, $v1);
    }
}
