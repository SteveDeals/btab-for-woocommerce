<?php
/**
 * The bridge between a WooCommerce cart and a btab cart.
 *
 * ONE btab cart per buyer, kept in the WooCommerce session. The shipping
 * method (PLAN 1.2) and the checkout hand-off (PLAN 1.3) both need the same
 * cart, so building it lives here rather than in either of them.
 *
 * THE PLUGIN NEVER SENDS A PRICE. Lines are product id, variant id and
 * quantity. btab prices them, exactly as it prices its own storefront, so a
 * stale price in WooCommerce is shown but never charged.
 *
 * NOTHING HERE RUNS ON A PRODUCT PAGE. It runs on the cart and the checkout,
 * where the buyer has asked for a delivery price or is paying.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Cart
{
    /** WooCommerce session key holding ['signature' => string, 'id' => string]. */
    const SESSION_KEY = 'btab_remote_cart';

    /** How long a freight answer is reused for the same lines and postcode. */
    const ESTIMATE_TTL = 600;

    /**
     * The btab lines in a package (or in the whole cart), and what else is in it.
     *
     * @param array|null $package a WooCommerce shipping package, or null for WC()->cart
     * @return array{lines:array,btab_items:int,other_items:int}
     */
    public static function read($package = null)
    {
        $contents = array();
        if (is_array($package) && isset($package['contents']) && is_array($package['contents'])) {
            $contents = $package['contents'];
        } elseif ($package === null && function_exists('WC') && WC()->cart) {
            $contents = WC()->cart->get_cart();
        }

        $lines = array();
        $btab_items = 0;
        $other_items = 0;

        foreach ($contents as $item) {
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($quantity < 1) {
                continue;
            }
            $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;

            $btab_product_id = $product_id ? (int) get_post_meta($product_id, BTAB_WC_META_PRODUCT_ID, true) : 0;
            if (!$btab_product_id) {
                $other_items += $quantity;
                continue;
            }

            $line = array('product_id' => $btab_product_id, 'quantity' => $quantity);
            if ($variation_id) {
                $variant_id = (int) get_post_meta($variation_id, '_btab_variant_id', true);
                if ($variant_id) {
                    $line['variant_id'] = $variant_id;
                }
            }
            $lines[] = $line;
            $btab_items += $quantity;
        }

        return array('lines' => $lines, 'btab_items' => $btab_items, 'other_items' => $other_items);
    }

    /**
     * A stable fingerprint of the lines, so the same cart is not rebuilt and
     * the same freight question is not asked twice.
     *
     * @param array $lines
     * @return string
     */
    public static function signature(array $lines)
    {
        $flat = array();
        foreach ($lines as $line) {
            $flat[] = (int) $line['product_id'] . ':' . (isset($line['variant_id']) ? (int) $line['variant_id'] : 0) . ':' . (int) $line['quantity'];
        }
        sort($flat);
        return md5(implode('|', $flat));
    }

    /**
     * The btab cart id for these lines, made or updated as needed.
     *
     * The id is kept in the WooCommerce session so one buyer keeps one btab
     * cart across the cart page, the checkout and a postcode change. When the
     * lines change the stored cart is PATCHed rather than replaced, so the
     * checkout session in PLAN 1.3 points at the cart the buyer has been
     * looking at.
     *
     * @param Btab_Client $client
     * @param array       $lines
     * @return string|null the cart id, or null when btab would not make one
     * @throws Btab_Api_Exception when btab answered with an error
     */
    public static function remote_cart_id(Btab_Client $client, array $lines)
    {
        if (!$lines) {
            return null;
        }
        $signature = self::signature($lines);
        $stored = self::session_get();

        if ($stored && !empty($stored['id'])) {
            if (isset($stored['signature']) && $stored['signature'] === $signature) {
                return $stored['id'];
            }
            try {
                $client->update_cart($stored['id'], $lines);
                self::session_set($signature, $stored['id']);
                return $stored['id'];
            } catch (Btab_Api_Exception $e) {
                // An expired or unknown cart is not an error the buyer should
                // ever see: make a new one. Anything else is a real refusal.
                if (!in_array($e->get_status(), array(404, 410), true)) {
                    throw $e;
                }
            }
        }

        $made = $client->create_cart($lines);
        $id = isset($made['cart']['id']) ? (string) $made['cart']['id'] : '';
        if ($id === '') {
            return null;
        }
        self::session_set($signature, $id);
        return $id;
    }

    /**
     * Freight for these lines to this postcode, as btab's own storefront
     * prices it.
     *
     * Cached for ESTIMATE_TTL against the lines, the country and the postcode.
     * WooCommerce already caches rates per package in the session; this cache
     * is the one that stops a second buyer with the same cart and postcode
     * costing a second API call.
     *
     * @param Btab_Client $client
     * @param array       $lines
     * @param string      $country two letters
     * @param string      $postcode
     * @return array btab's `estimate` object
     * @throws Btab_Api_Exception
     */
    public static function estimate(Btab_Client $client, array $lines, $country, $postcode)
    {
        $country = strtoupper(substr(trim((string) $country), 0, 2));
        $postcode = trim((string) $postcode);
        $cache_key = 'btab_wc_ship_' . md5(self::signature($lines) . '|' . $country . '|' . $postcode);

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $cart_id = self::remote_cart_id($client, $lines);
        if (!$cart_id) {
            return array('available' => false, 'reason' => 'cart_unavailable');
        }
        $answer = $client->shipping_estimate($cart_id, $postcode, $country);
        $estimate = isset($answer['estimate']) && is_array($answer['estimate']) ? $answer['estimate'] : array();

        set_transient($cache_key, $estimate, self::ESTIMATE_TTL);
        return $estimate;
    }

    /** @return array|null */
    private static function session_get()
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        $stored = WC()->session->get(self::SESSION_KEY);
        return is_array($stored) ? $stored : null;
    }

    private static function session_set($signature, $id)
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        WC()->session->set(self::SESSION_KEY, array('signature' => $signature, 'id' => $id));
    }

    /** Forget the btab cart — after an order is placed, or when the cart empties. */
    public static function forget()
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, null);
        }
    }
}
