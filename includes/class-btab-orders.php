<?php
/**
 * What happens to an order after the buyer walks away.
 *
 * THE HOLE THIS FILLS. The hand-off marks a Woo order paid when the buyer
 * comes back to the thank-you page and the plugin asks btab how it went. A
 * buyer who closes the tab, loses signal, or simply wanders off never comes
 * back — and nothing else would ever mark that order paid. The shop would show
 * a pending order for goods btab is already shipping.
 *
 * So btab's own events are the source of truth, and the return is only the
 * fast path:
 *   checkout_session.completed  the order was paid. Found by SESSION ID, which
 *                               the hand-off stored on the Woo order, and it
 *                               carries btab's order number
 *   order.paid                  the same truth from the order's side, for an
 *                               order whose number we already know
 *   order.refunded              btab refunded it in full
 *
 * BTAB IS WHERE A REFUND HAPPENS, so wp-admin's own Refund button is hidden on
 * a btab order: pressing it would move money on the shop's gateway, which took
 * none, while btab's books say otherwise.
 *
 * TRACKING IS HERE NOW (feature woo-plugin-tracking-back). btab sends it on
 * two topics — `order.shipped` with every parcel known at that moment, and
 * `order.parcel_shipped` once per parcel on every ship — and both land in
 * mark_parcels_once() below, which is also where the reason for the second
 * topic is written down.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Orders
{
    /** Seconds after which a lock is assumed abandoned and broken. */
    const LOCK_LIFE = 60;

    /** Set once the shop has been told btab's total disagrees with this order. */
    const META_WARNED = '_btab_total_warned';

    /** What a once-only write did, so the caller can decide whether to retry. */
    const DONE = 'done';
    const ALREADY = 'already';
    const BUSY = 'busy';

    public static function boot()
    {
        add_filter('woocommerce_admin_order_should_render_refunds', array(__CLASS__, 'no_refund_button'), 10, 3);
        add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'admin_note'));
        // The one buyer-facing thing this class renders: the parcels. This hook
        // reaches My account -> Orders, the thank-you page AND the block order
        // confirmation, so one registration covers classic and block themes.
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'show_parcels'));
    }

    /**
     * Act on an order event from btab.
     *
     * @param string $name
     * @param array  $data the event's `data`
     * @throws Exception
     */
    public static function apply($name, array $data)
    {
        if ($name === 'checkout_session.completed') {
            $session = isset($data['checkout_session']) && is_array($data['checkout_session']) ? $data['checkout_session'] : array();
            $session_id = isset($session['id']) ? (string) $session['id'] : '';
            $number = isset($session['order_number']) && $session['order_number'] ? (string) $session['order_number'] : '';
            $order = self::by_meta(Btab_WC_Gateway::META_SESSION, $session_id);
            if (!$order) {
                // Another shop's session, or one this shop never made. Nothing
                // to do, and not an error: btab must not retry it.
                return;
            }
            self::demand(self::mark_paid_once($order->get_id(), $number !== '' ? $number : $session_id, $number, $data));
            return;
        }

        $o = isset($data['order']) && is_array($data['order']) ? $data['order'] : array();
        $number = isset($o['order_number']) ? (string) $o['order_number'] : '';
        if ($number === '') {
            return;
        }
        $order = self::by_meta(Btab_WC_Gateway::META_ORDER_NUMBER, $number);
        if (!$order) {
            // An order this shop did not place. btab stores sell through more
            // than one shop window, so this is ordinary, not a failure.
            return;
        }

        if ($name === 'order.paid') {
            // `order.paid` can only find an order by btab's order number, and
            // that number is written when the order becomes paid — so this
            // event always arrives at an order that is already paid. The
            // amount check runs for exactly that case, inside the lock.
            self::demand(self::mark_paid_once($order->get_id(), $number, $number, $data));
            return;
        }

        if ($name === 'order.shipped') {
            // Every parcel btab knew about when the order reached 'shipped'.
            // This event cannot fire again, so a parcel that goes later arrives
            // only on order.parcel_shipped below.
            $parcels = isset($o['parcels']) && is_array($o['parcels']) ? $o['parcels'] : array();
            if ($parcels) {
                self::demand(self::mark_parcels_once($order->get_id(), $parcels));
            }
            return;
        }

        if ($name === 'order.parcel_shipped') {
            // ONE parcel, and the only event that reaches a shop when the order
            // was already marked shipped by hand.
            $parcel = isset($data['parcel']) && is_array($data['parcel']) ? $data['parcel'] : array();
            if ($parcel) {
                self::demand(self::mark_parcels_once($order->get_id(), array($parcel)));
            }
            return;
        }

        if ($name === 'order.refunded') {
            // A FULL refund only, and btab says which it is (review M3). The
            // payload carries payment_status; acting without reading it would
            // put a partially-refunded order's whole sale in the Refunded
            // column and make the shop's books disagree with btab's.
            $status = isset($o['payment_status']) ? (string) $o['payment_status'] : '';
            if ($status !== 'refunded') {
                // Only say "part" when btab actually said part (review LOW 6).
                // An unknown or empty status is not a partial refund; it is a
                // thing we do not understand, and the note should say so.
                $order->add_order_note(
                    $status === 'partially_refunded'
                        ? __('The supplier refunded part of this order. The order is left as it is; check the amount with them.', 'btab-woocommerce')
                        : sprintf(
                            /* translators: %s: the payment status the supplier reported */
                            __('The supplier sent a refund message this shop does not understand (it reports "%s"). The order is left as it is; check it with them.', 'btab-woocommerce'),
                            $status !== '' ? $status : __('no status', 'btab-woocommerce')
                        )
                );
                $order->save();
                return;
            }
            self::demand(self::mark_refunded_once($order->get_id(), $o));
        }
    }

    /**
     * Mark an order paid, exactly once, however many requests arrive together.
     *
     * WHY A LOCK AND NOT AN `is_paid()` CHECK (review M2). btab fires
     * `checkout_session.completed` at the moment it redirects the buyer, so
     * the webhook and the buyer's own return land at the same instant. Both
     * read the order, both see it unpaid, and both call `payment_complete()`:
     * two emails to the buyer, two to the shop, two notes, and a race on the
     * stock reduction. A check only protects requests that run one after the
     * other; this is the case where they do not.
     *
     * `add_option()` is the mutex: option_name is UNIQUE, so exactly one
     * caller's INSERT succeeds and every other gets false. A lock older than
     * its life is broken on purpose, so a request that died mid-payment cannot
     * wedge an order shut for ever.
     *
     * @param int    $order_id
     * @param string $reference what to record as the transaction
     * @param string $number    btab's order number, when it is known
     * @param array  $data      the event's data, for the amount check
     */
    public static function mark_paid_once($order_id, $reference, $number = '', array $data = array())
    {
        $order_id = (int) $order_id;
        $token = self::acquire($order_id);
        if ($token === null) {
            return self::BUSY;
        }
        try {
            // Re-read INSIDE the lock: the request that got here first may
            // have finished between our check and our turn.
            $order = wc_get_order($order_id);
            if (!$order) {
                return self::ALREADY;
            }
            if ($order->is_paid() || $order->get_status() === 'refunded') {
                // THE AMOUNT CHECK BELONGS IN HERE (review, round 2). Outside
                // the lock, two deliveries of the same already-paid order each
                // wrote the mismatch note, so the shop saw it twice.
                self::check_amount($order, isset($data['order']) && is_array($data['order']) ? $data['order'] : array());
                return self::ALREADY;
            }
            if ($number !== '') {
                $order->update_meta_data(Btab_WC_Gateway::META_ORDER_NUMBER, $number);
            }
            $order->payment_complete($reference);
            $note = self::paid_note($order, $reference, $data);
            if (self::mismatch($order, isset($data['order']) && is_array($data['order']) ? $data['order'] : array()) !== null) {
                // The paid note already carries the warning, so a later event
                // must not say it again.
                $order->update_meta_data(self::META_WARNED, 'yes');
            }
            $order->add_order_note($note);
            $order->save();
            return self::DONE;
        } finally {
            self::release($order_id, $token);
        }
    }

    /**
     * Refunded in full, once, under the same lock as paying (review M2).
     *
     * Without the lock, two deliveries of the same `order.refunded` — the same
     * event through two subscriptions, which the seen-check cannot stop on its
     * own because it is a read and not a claim — both see a status that is not
     * `refunded` and both call `update_status('refunded')`. Each run fires
     * core's `wc_order_fully_refunded`, each sees `total - total_refunded =
     * total`, and each writes a SECOND full-total refund record. The shop's
     * reports then show the sale refunded twice.
     *
     * @param int   $order_id
     * @param array $o the event's order object
     * @return string DONE | ALREADY | BUSY
     */
    public static function mark_refunded_once($order_id, array $o = array())
    {
        $order_id = (int) $order_id;
        $token = self::acquire($order_id);
        if ($token === null) {
            return self::BUSY;
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_status() === 'refunded') {
                return self::ALREADY;
            }
            self::mark_refunded($order, $o);
            return self::DONE;
        } finally {
            self::release($order_id, $token);
        }
    }

    /**
     * The lock, and why it is not `add_option()` (review M1).
     *
     * `add_option()` IS NOT A MUTEX. It runs `INSERT … ON DUPLICATE KEY UPDATE`
     * and reports success on any non-zero affected-rows — and the `get_option()`
     * that used to precede it had already put the key in WordPress's
     * `notoptions` cache, so `add_option` skipped its own existence check and
     * went straight to the upsert. Two requests a second apart therefore BOTH
     * won: the second's UPDATE changed the stored time, MySQL reported rows
     * affected, and both callers believed they held it.
     *
     * The test that "proved" the old lock passed for the wrong reason: both
     * writes carried the same `time()`, so the UPDATE changed nothing and MySQL
     * reported 0 rows. A test that only passes when the clock cooperates is not
     * a test of a lock.
     *
     * `INSERT IGNORE` on the UNIQUE `option_name` is the real thing: exactly one
     * INSERT affects a row, every other affects none, whatever the values are.
     *
     * @param int $order_id
     * @return string|null the token we hold, or null when someone else holds it
     */
    private static function acquire($order_id)
    {
        global $wpdb;
        $name = self::lock_name($order_id);

        // Break a lock whose holder died — but ONLY the exact stale row (review
        // LOW 4). Deleting by name alone lets two waiters delete each other's
        // fresh lock and both proceed.
        $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (is_string($held) && $held !== '') {
            $at = (int) strtok($held, ':');
            if ($at && (time() - $at) > self::LOCK_LIFE) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held));
            }
        }

        $token = time() . ':' . wp_generate_password(12, false, false);
        // autoload off: a lock is read by one request, never by every page load.
        $rows = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $name,
            $token
        ));
        // WordPress remembers that this option did not exist; clear that, or a
        // later get_option() would answer from cache rather than the row.
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');

        return ((int) $rows === 1) ? $token : null;
    }

    /** Release OUR lock, never someone else's. */
    private static function release($order_id, $token)
    {
        global $wpdb;
        $name = self::lock_name($order_id);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function lock_name($order_id)
    {
        return 'btab_wc_pay_lock_' . (int) $order_id;
    }

    /**
     * A webhook that lost the lock must NOT be answered 200 (review LOW 3).
     *
     * The loser used to return quietly, so the handler marked the event seen.
     * If the winner then threw or died, that delivery was consumed and the
     * order stayed unpaid with nothing left to retry it. Throwing makes the
     * handler answer 500, btab retries on its own ladder, and the retry finds
     * either a paid order or a free lock.
     *
     * @param string $what DONE | ALREADY | BUSY
     * @throws Exception
     */
    private static function demand($what)
    {
        if ($what === self::BUSY) {
            throw new Exception('Another request holds this order; let btab retry.');
        }
    }

    /**
     * Does btab's figure match the order's? A note only when it does not.
     *
     * btab prices the order, so its total is the one the buyer paid. A
     * difference means the shop is about to ship against a number nobody paid.
     *
     * @param WC_Order $order
     * @param array    $o btab's order object
     */
    private static function check_amount($order, array $o)
    {
        $warning = self::mismatch($order, $o);
        if ($warning === null) {
            return;
        }
        // ONCE PER ORDER. The lock stops two requests writing it at the same
        // moment, but not the same warning arriving again later: btab retries a
        // delivery, and `order.paid` follows `checkout_session.completed` for
        // the same order. A shop owner seeing the same alarming sentence three
        // times learns to scroll past it.
        if ($order->get_meta(self::META_WARNED) === 'yes') {
            return;
        }
        $order->update_meta_data(self::META_WARNED, 'yes');
        $order->add_order_note($warning);
        $order->save();
    }

    /**
     * One comparison, used by the paid note and by the already-paid check, so
     * the two cannot drift apart — and so BOTH compare the currency, which the
     * paid note did not.
     *
     * @param WC_Order $order
     * @param array    $o btab's order object
     * @return string|null the sentence to write, or null when they agree
     */
    private static function mismatch($order, array $o)
    {
        $cents = isset($o['total_cents']) ? (int) $o['total_cents'] : null;
        if ($cents === null) {
            return null;
        }
        $theirs = $cents / 100;
        $currency = isset($o['currency']) ? strtoupper((string) $o['currency']) : '';
        $same_money = abs($theirs - (float) $order->get_total()) < 0.01;
        $same_currency = $currency === '' || $currency === strtoupper((string) $order->get_currency());
        if ($same_money && $same_currency) {
            return null;
        }
        return sprintf(
            /* translators: 1: the supplier's amount, 2: the supplier's currency, 3: this order's total, 4: this order's currency */
            __('The supplier took %1$s %2$s, but this order says %3$s %4$s. They price the order, so their figure is what the buyer paid — check this order before you ship anything.', 'btab-woocommerce'),
            number_format($theirs, 2),
            $currency !== '' ? $currency : $order->get_currency(),
            number_format((float) $order->get_total(), 2),
            $order->get_currency()
        );
    }

    /**
     * The paid note, with btab's total in it WHEN THERE IS ONE.
     *
     * `checkout_session.completed` carries `{ id, status, livemode,
     * order_number, completed_at }` and no amount, and the thank-you page has
     * none either, so those two paths write the plain note and do not pretend
     * otherwise. `order.paid` is the event that carries money, and it is
     * handled by check_amount() above, which runs on an order that is already
     * paid — the only state in which that event can find one.
     *
     * @param WC_Order $order
     * @param string   $reference
     * @param array    $data
     * @return string
     */
    private static function paid_note($order, $reference, array $data)
    {
        $o = isset($data['order']) && is_array($data['order']) ? $data['order'] : array();
        $note = sprintf(
            /* translators: %s: the supplier's order reference */
            __('Paid, confirmed by the supplier. Reference: %s', 'btab-woocommerce'),
            $reference
        );
        $cents = isset($o['total_cents']) ? (int) $o['total_cents'] : null;
        if ($cents === null) {
            return $note;
        }
        $currency = isset($o['currency']) && $o['currency'] !== '' ? strtoupper((string) $o['currency']) : (string) $order->get_currency();
        $note .= ' ' . sprintf(
            /* translators: 1: amount, 2: currency */
            __('They took %1$s %2$s.', 'btab-woocommerce'),
            number_format($cents / 100, 2),
            $currency
        );
        // ONE comparison, shared with check_amount(), so the two cannot drift
        // and so this one compares the CURRENCY too — it did not before.
        $warning = self::mismatch($order, $o);
        return $warning === null ? $note : $note . ' ' . $warning;
    }

    /**
     * Refunded in full, by btab.
     *
     * WHAT ACTUALLY HAPPENS, corrected (review LOW 4). An earlier comment here
     * claimed no WooCommerce refund record is created. That was wrong:
     * `update_status('refunded')` fires core's `wc_order_fully_refunded`, which
     * calls `wc_create_refund()` for the whole total. No money moves — the
     * shop's gateway is never asked — but the record exists, and core also
     * writes its own note telling the shop owner to "issue a refund through
     * your payment gateway", which is the opposite of what they should do. So
     * our note goes on afterwards and says plainly that it is already done.
     *
     * @param WC_Order $order
     * @param array    $o the event's order object
     */
    private static function mark_refunded($order, array $o = array())
    {
        if ($order->get_status() === 'refunded') {
            return;
        }
        $order->update_status('refunded', __('Refunded in full by the supplier.', 'btab-woocommerce'));
        $cents = isset($o['total_cents']) ? (int) $o['total_cents'] : null;
        $order->add_order_note(
            __('The supplier has already refunded the buyer. Do NOT refund through this shop\'s payment gateway — it never took the money.', 'btab-woocommerce')
            . ($cents === null ? '' : ' ' . sprintf(
                /* translators: %s: the amount refunded */
                __('They refunded %s.', 'btab-woocommerce'),
                number_format($cents / 100, 2)
            ))
        );
        $order->save();
    }

    /**
     * The Refund button, hidden on a btab order.
     *
     * WooCommerce passes ($render, $order_id, $order) — the ORDER IS THIRD,
     * and the second argument is an int. Read from the installed WooCommerce
     * (admin/meta-boxes/views/html-order-items.php), not from the filter's
     * name: taking the second argument as the order would have left this
     * silently never firing, and the button is about money.
     *
     * @param bool          $render
     * @param int           $order_id
     * @param WC_Order|null $order
     * @return bool
     */
    public static function no_refund_button($render, $order_id = 0, $order = null)
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if ($order instanceof WC_Order && $order->get_payment_method() === Btab_WC_Gateway::ID) {
            return false;
        }
        return $render;
    }

    /**
     * TRACKING, AS THE BUYER'S OWN SHOP SHOWS IT.
     *
     * btab tells a shop a parcel went on two different topics, and a shop needs
     * both (feature woo-plugin-tracking-back):
     *
     *   order.shipped          the order reached 'shipped'. Carries data.order.parcels[],
     *                          every parcel known at that moment. Fires AT MOST ONCE
     *                          per order and can never fire again.
     *   order.parcel_shipped   ONE parcel went. Fires every time a part ships,
     *                          including when the order was already marked shipped —
     *                          which is the case order.shipped cannot cover, because a
     *                          vendor can mark a whole order shipped by hand while parts
     *                          are still to go.
     *
     * So the same parcel arrives TWICE, on two events with two different
     * event_keys. The transient in class-btab-events.php dedupes an event, not a
     * parcel, so it cannot help here — by design. Hence the ledger below.
     *
     * WHERE IT IS STORED, AND WHY NOT WooCommerce's OWN SHAPE. The paid
     * Shipment Tracking extension reads `_wc_shipment_tracking_items`, and
     * WooCommerce CORE reads that key nowhere at all — checked against the core
     * source, zero references. Writing only that shape would therefore show the
     * buyer NOTHING in every shop without the extension, which is most of them.
     * So this plugin owns `_btab_parcels` and renders it, and MIRRORS into the
     * extension's array as a courtesy for shops that do have it. Two further
     * reasons it could not be the store even if it rendered: its own tracking_id
     * is md5(provider-number . microtime()), which is non-deterministic and so
     * useless as an idempotency key; and every consumer renders tracking_number
     * as a code, so a parcel with a carrier and no number would read as
     * dispatched-with-a-blank, which is the one thing this must never do.
     *
     * @var string
     */
    const META_PARCELS = '_btab_parcels';

    /** The extension's array. Written as a mirror, never read as the truth. */
    const META_WC_TRACKING = '_wc_shipment_tracking_items';

    /**
     * Record parcels on an order, exactly once each, however many requests
     * arrive together.
     *
     * THE LOCK IS NOT OPTIONAL HERE, and the reason is specific to one meta key
     * holding a list. order.shipped and order.parcel_shipped can land for one
     * order at the same instant. Without the lock both read `_btab_parcels`,
     * both add their own parcel to the array they read, and the second
     * update_meta_data() silently drops the first — one parcel's tracking gone,
     * no error anywhere. This is the same race mark_paid_once() was built for,
     * so it reuses the same acquire()/release().
     *
     * @param int   $order_id
     * @param array $parcels  btab's parcel shapes, each with at least an id
     * @return string DONE, ALREADY or BUSY
     */
    public static function mark_parcels_once($order_id, array $parcels)
    {
        $order_id = (int) $order_id;
        $token = self::acquire($order_id);
        if ($token === null) {
            return self::BUSY;
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== Btab_WC_Gateway::ID) {
                // Not a btab order. A shop's own order is never touched.
                return self::ALREADY;
            }

            $known = $order->get_meta(self::META_PARCELS);
            $known = is_array($known) ? $known : array();
            $added = array();

            foreach ($parcels as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $pid = isset($p['id']) ? (string) $p['id'] : '';
                if ($pid === '' || isset($known[$pid])) {
                    // No id means nothing to key on, so it cannot be deduped and
                    // must not be stored. Already known means a repeat: the whole
                    // point of the ledger.
                    continue;
                }
                $carrier = isset($p['carrier']) && $p['carrier'] !== '' ? (string) $p['carrier'] : null;
                // null, never '': "no number yet" and "an empty number" are
                // different statements and only one of them is true.
                //
                // AND A LINK CAN ARRIVE WITHOUT A NUMBER. btab's cleanShipment()
                // lets a vendor ship with a tracking link alone ("a tracking
                // number, a tracking link, or the booked delivery date"), so a
                // parcel whose only tracking is an https page is a real shape.
                // Gating the link on the number would throw away the only
                // tracking btab sent — see show_parcels() and admin_note().
                $number = isset($p['tracking_number']) && $p['tracking_number'] !== '' ? (string) $p['tracking_number'] : null;
                $url = isset($p['tracking_url']) && $p['tracking_url'] !== '' ? (string) $p['tracking_url'] : null;
                if ($url !== null && stripos($url, 'https://') !== 0) {
                    // A tracking link is shown to a buyer. Anything but https is
                    // dropped rather than rendered.
                    $url = null;
                }
                $shipped = isset($p['shipped_at']) && $p['shipped_at'] !== '' ? strtotime((string) $p['shipped_at']) : false;

                $known[$pid] = array(
                    'carrier' => $carrier,
                    'number' => $number,
                    'url' => $url,
                    'shipped_at' => $shipped ? (int) $shipped : null,
                    // Filled by the mirror below when there is a number to mirror.
                    'mirror_id' => '',
                );
                $added[$pid] = $known[$pid];
            }

            if (!$added) {
                return self::ALREADY;
            }

            $known = self::mirror_parcels($order, $known, $added);
            $order->update_meta_data(self::META_PARCELS, $known);
            self::note_parcels($order, $added);
            // HPOS-safe: update_meta_data + save writes whichever store
            // WooCommerce is using, where a direct post write would miss it.
            $order->save();
            return self::DONE;
        } finally {
            self::release($order_id, $token);
        }
    }

    /**
     * Mirror the parcels that have a number into the Shipment Tracking array.
     *
     * A COURTESY COPY, not the store. Only parcels WITH a tracking number go:
     * every consumer of that array renders tracking_number as a code, so a
     * no-number parcel there would read as dispatched-with-a-blank.
     *
     * ONLY THE EXTENSION'S OWN SIX KEYS go in each item. Its CSV export iterates
     * the item generically and prints every key it finds, name and all, so an
     * extra `btab_part_id` field would be printed by third-party consumers. The
     * part-id link lives in `_btab_parcels` instead, as `mirror_id`.
     *
     * tracking_id is DELIBERATELY deterministic — md5('parcel-' . part id) —
     * where the extension itself uses microtime. Nothing requires a particular
     * derivation (its own REST docs call the field opaque), and deterministic
     * means a redelivery recomputes the same id, so find-or-skip works instead
     * of appending a duplicate.
     *
     * `wc_st_add_tracking_number()` is NOT used, despite being the documented
     * helper: it appends unconditionally with a microtime id, so a redelivery
     * duplicates. We own the array and read-modify-write it.
     *
     * @param WC_Order $order
     * @param array    $known  the full ledger, by part id
     * @param array    $added  the parcels added this time, by part id
     * @return array the ledger with mirror_id filled in
     */
    private static function mirror_parcels($order, array $known, array $added)
    {
        $items = $order->get_meta(self::META_WC_TRACKING);
        $items = is_array($items) ? $items : array();
        $have = array();
        foreach ($items as $it) {
            if (is_array($it) && isset($it['tracking_id'])) {
                $have[(string) $it['tracking_id']] = true;
            }
        }

        $changed = false;
        foreach ($added as $pid => $p) {
            if ($p['number'] === null) {
                // NOT MIRRORED, deliberately, even when there is a link: the
                // extension's array has no field for "a link and no number", and
                // every consumer renders tracking_number as a code, so it would
                // read as dispatched-with-a-blank. Such a parcel keeps
                // mirror_id '' and is therefore always rendered by show_parcels()
                // — including in shops that DO have the extension, which never
                // received it. No gap, no double render.
                continue;
            }
            $tid = md5('parcel-' . $pid);
            $known[$pid]['mirror_id'] = $tid;
            if (isset($have[$tid])) {
                continue;
            }
            $items[] = array(
                // Empty on purpose: when custom_tracking_provider is set the
                // extension echoes it verbatim as the carrier and uses
                // custom_tracking_link as given, with no provider-slug matching.
                // That is how the carrier reads as the shop's own text.
                'tracking_provider' => '',
                'custom_tracking_provider' => $p['carrier'] !== null ? $p['carrier'] : '',
                'custom_tracking_link' => $p['url'] !== null ? $p['url'] : '',
                'tracking_number' => $p['number'],
                // A UNIX INT in storage, even though the REST API documents
                // YYYY-MM-DD. Writing the array ourselves, we write the int.
                'date_shipped' => $p['shipped_at'] !== null ? $p['shipped_at'] : time(),
                'tracking_id' => $tid,
            );
            $changed = true;
        }

        if ($changed) {
            $order->update_meta_data(self::META_WC_TRACKING, $items);
        }
        return $known;
    }

    /**
     * One PRIVATE note per parcel — the audit trail, matching the paid and
     * refunded notes. Private, not a customer note: the buyer already sees the
     * parcels rendered on the order, and a second copy by email is noise.
     *
     * @param WC_Order $order
     * @param array    $added
     */
    private static function note_parcels($order, array $added)
    {
        foreach ($added as $p) {
            if ($p['number'] !== null && $p['carrier'] !== null) {
                $order->add_order_note(sprintf(
                    /* translators: 1: carrier name, 2: tracking number */
                    __('A parcel for this order was dispatched with %1$s, tracking %2$s.', 'btab-woocommerce'),
                    $p['carrier'],
                    $p['number']
                ));
            } elseif ($p['carrier'] !== null) {
                $order->add_order_note(sprintf(
                    /* translators: %s: carrier name */
                    __('A parcel for this order was dispatched with %s. No tracking number was given.', 'btab-woocommerce'),
                    $p['carrier']
                ));
            } else {
                $order->add_order_note(__('A parcel for this order was dispatched. No carrier or tracking number was given.', 'btab-woocommerce'));
            }
        }
    }

    /**
     * The parcels, where the buyer looks: My account -> Orders, the thank-you
     * page, and the block order confirmation.
     *
     * ONE HOOK COVERS ALL THREE. woocommerce_order_details_after_order_table
     * fires from templates/order/order-details.php (reached by
     * woocommerce_view_order on the My Account page) and from the block
     * OrderConfirmation\Totals. It is the hook this plugin already uses for the
     * order-reference line, so classic and block themes are both covered by one
     * registration.
     *
     * NOTHING IS RENDERED WHEN THERE ARE NO PARCELS, and that is the rule rather
     * than an omission: absence is what reads as "not dispatched". An empty
     * heading would read as dispatched-with-nothing, the same way an unpriced
     * delivery must never read as free.
     *
     * NO "btab" ANYWHERE HERE. A buyer bought from this shop; the carrier and
     * the number are the shop's own text.
     *
     * @param WC_Order $order
     */
    public static function show_parcels($order)
    {
        if (!$order instanceof WC_Order || $order->get_payment_method() !== Btab_WC_Gateway::ID) {
            return;
        }
        $parcels = $order->get_meta(self::META_PARCELS);
        if (!is_array($parcels) || !$parcels) {
            return;
        }

        // When the Shipment Tracking extension is active it renders the mirrored
        // parcels itself, so only the ones never mirrored (the no-number ones,
        // which it never received) are ours to show. No duplication, no gap.
        $reader = class_exists('WC_Shipment_Tracking') || function_exists('wc_st_add_tracking_number');
        $show = array();
        foreach ($parcels as $p) {
            if (!is_array($p)) {
                continue;
            }
            if ($reader && isset($p['mirror_id']) && $p['mirror_id'] !== '') {
                continue;
            }
            $show[] = $p;
        }
        if (!$show) {
            return;
        }

        // The class name follows show_reference's woocommerce-* convention, and it
        // matters: a 'btab-' class is the word btab in the page SOURCE, which is
        // exactly what the buyer's-eye rule forbids. The test greps for it.
        echo '<section class="woocommerce-order-dispatch"><h2>' . esc_html__('Dispatch', 'btab-woocommerce') . '</h2><ul>';
        foreach ($show as $p) {
            $carrier = isset($p['carrier']) && $p['carrier'] !== null ? (string) $p['carrier'] : '';
            $number = isset($p['number']) && $p['number'] !== null ? (string) $p['number'] : '';
            $url = isset($p['url']) && $p['url'] !== null ? (string) $p['url'] : '';
            echo '<li>';
            if ($number !== '') {
                $shown = $url !== ''
                    ? '<a href="' . esc_url($url) . '" rel="nofollow noopener">' . esc_html($number) . '</a>'
                    : '<code>' . esc_html($number) . '</code>';
                if ($carrier !== '') {
                    /* translators: 1: carrier name, 2: the tracking number, possibly a link */
                    printf(esc_html__('Sent with %1$s. Tracking: %2$s', 'btab-woocommerce'), esc_html($carrier), $shown);
                } else {
                    /* translators: %s: the tracking number, possibly a link */
                    printf(esc_html__('Tracking: %s', 'btab-woocommerce'), $shown);
                }
            } elseif ($url !== '') {
                // A LINK WITH NO NUMBER. Some carriers issue a tracking page and
                // no number, and btab accepts that ship, so this is the only
                // tracking there is: showing "no tracking number yet" and
                // dropping the link would hide it entirely.
                $link = '<a href="' . esc_url($url) . '" rel="nofollow noopener">'
                    . esc_html__('Track this parcel', 'btab-woocommerce') . '</a>';
                if ($carrier !== '') {
                    /* translators: 1: carrier name, 2: a "Track this parcel" link */
                    printf(esc_html__('Sent with %1$s. %2$s', 'btab-woocommerce'), esc_html($carrier), $link);
                } else {
                    // phpcs:ignore WordPress.Security.EscapeOutput -- built above
                    echo $link;
                }
            } elseif ($carrier !== '') {
                // NEVER an empty code element: a carrier with no number is
                // dispatched-without-a-number, which is a different fact.
                printf(
                    /* translators: %s: carrier name */
                    esc_html__('Sent with %s. The carrier has not given a tracking number yet.', 'btab-woocommerce'),
                    esc_html($carrier)
                );
            } else {
                echo esc_html__('Dispatched. No tracking number yet.', 'btab-woocommerce');
            }
            echo '</li>';
        }
        echo '</ul></section>';
    }

    /** A line in wp-admin saying where this order really lives. */
    public static function admin_note($order)
    {
        if (!$order instanceof WC_Order || $order->get_payment_method() !== Btab_WC_Gateway::ID) {
            return;
        }
        $number = (string) $order->get_meta(Btab_WC_Gateway::META_ORDER_NUMBER);
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Supplier order', 'btab-woocommerce') . ':</strong> '
            . ($number !== '' ? '<code>' . esc_html($number) . '</code>' : esc_html__('not confirmed yet', 'btab-woocommerce'))
            . '<br><span class="description">'
            . esc_html__('The supplier took the payment and ships the goods. Refund it with them, not here.', 'btab-woocommerce')
            . '</span></p>';

        // The parcels, for the shop owner. Same source as the buyer's view, so
        // the two cannot disagree.
        $parcels = $order->get_meta(self::META_PARCELS);
        if (is_array($parcels) && $parcels) {
            echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Parcels', 'btab-woocommerce') . ':</strong><br>';
            foreach ($parcels as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $carrier = isset($p['carrier']) && $p['carrier'] !== null ? (string) $p['carrier'] : '';
                $number = isset($p['number']) && $p['number'] !== null ? (string) $p['number'] : '';
                $purl = isset($p['url']) && $p['url'] !== null ? (string) $p['url'] : '';
                if ($number !== '') {
                    $shown = '<code>' . esc_html($number) . '</code>';
                } elseif ($purl !== '') {
                    // Same reason as the buyer view: a link-only ship is the only
                    // tracking btab sent, so the shop owner must see it too.
                    $shown = '<a href="' . esc_url($purl) . '" rel="nofollow noopener">'
                        . esc_html__('tracking page', 'btab-woocommerce') . '</a>';
                } else {
                    $shown = esc_html__('no tracking number yet', 'btab-woocommerce');
                }
                echo esc_html($carrier !== '' ? $carrier : __('carrier not given', 'btab-woocommerce'))
                    . ' — ' . $shown . '<br>';
            }
            echo '</p>';
        }
    }

    /**
     * One order by one meta value. HPOS-safe: wc_get_orders() reads whichever
     * store WooCommerce is using, where a direct post query would read the one
     * it is not.
     *
     * @param string $meta_key
     * @param string $value
     * @return WC_Order|null
     */
    private static function by_meta($meta_key, $value)
    {
        if ($value === '') {
            return null;
        }
        $orders = wc_get_orders(array(
            'limit' => 5,
            'status' => 'any',
            'meta_key' => $meta_key,
            'meta_value' => $value,
            'meta_compare' => '=',
        ));
        // CHECK WHAT CAME BACK (review LOW 6). If a datastore ever ignores the
        // meta filter, `limit` alone hands us an arbitrary order — and this one
        // gets marked paid or refunded. The value is confirmed here, against
        // every value of that key, and so is the payment method.
        foreach ((array) $orders as $order) {
            if (!$order instanceof WC_Order || $order->get_payment_method() !== Btab_WC_Gateway::ID) {
                continue;
            }
            foreach ((array) $order->get_meta($meta_key, false) as $meta) {
                $stored = is_object($meta) && isset($meta->value) ? (string) $meta->value : (string) $meta;
                if ($stored === $value) {
                    return $order;
                }
            }
        }
        return null;
    }
}
