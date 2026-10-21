<?php
/**
 * btab Storefront API client — plain PHP, no outside libraries, no WordPress.
 *
 * This file is the PORTABLE CORE. PrestaShop (and anything else) reuses it as
 * is, so it must not touch a WordPress function, a Woo class, a global or a
 * superglobal. Everything platform-specific lives in ../includes/.
 *
 * The contract is btab-api docs/api/STOREFRONT_API.md, read from
 * origin/main @ de32e9fdc9c2ef514cf3196bf554a5ec6683ef03 (2026-10-21):
 *   base    https://api.btab.app/api/storefront/v1
 *   auth    X-Btab-Storefront-Key: btab_pk_live_… (or btab_pk_test_… )
 *   never   a store API key (btab_live_…) — the API refuses one in page code
 *
 * Prices are NEVER sent. btab prices every line the way its own checkout does,
 * so the plugin sends product ids, options and quantities only. A price in a
 * Woo product is a cached copy for display, sort and filters; the cart and the
 * checkout session are priced by btab.
 *
 * @package btab-woocommerce
 */

if (!defined('BTAB_LIB')) {
    define('BTAB_LIB', '1');
}

/**
 * A thin, explicit HTTP client. It returns decoded arrays and throws
 * Btab_Api_Exception on anything that is not a 2xx with a JSON body, so a
 * caller never has to guess whether it got data.
 */
class Btab_Client
{
    /** @var string Base URL with no trailing slash. */
    private $base;

    /** @var string The publishable key. */
    private $key;

    /** @var int Seconds. btab is called from the server, never while a page renders. */
    private $timeout;

    /** @var callable|null Injected transport, for tests and for WP's own HTTP API. */
    private $transport;

    /**
     * @param string        $base      e.g. https://api.btab.app/api/storefront/v1
     * @param string        $key       btab_pk_live_… or btab_pk_test_…
     * @param int           $timeout   seconds
     * @param callable|null $transport function(string $method, string $url, array $headers, ?string $body, int $timeout): array
     *                                 returning ['status' => int, 'body' => string]. Null = cURL.
     */
    public function __construct($base, $key, $timeout = 15, $transport = null)
    {
        $this->base = rtrim((string) $base, '/');
        $this->key = (string) $key;
        $this->timeout = (int) $timeout;
        $this->transport = $transport;
    }

    /** Is this a test key? A test key reads btab's demo store and never charges. */
    public function is_test_key()
    {
        return strpos($this->key, 'btab_pk_test_') === 0;
    }

    /**
     * A publishable key is the only kind allowed here. A store API key in page
     * code is the mistake the API refuses, so refuse it before the request:
     * a clear local error beats a 401 the shop owner cannot read.
     *
     * @return string|null a problem to show the shop owner, or null when it looks right
     */
    public function key_problem()
    {
        if ($this->key === '') {
            return 'No publishable key set.';
        }
        if (strpos($this->key, 'btab_pk_') !== 0) {
            if (strpos($this->key, 'btab_live_') === 0 || strpos($this->key, 'btab_test_') === 0) {
                return 'That is a store API key (btab_live_… / btab_test_…). It must never go in a shop. Use a PUBLISHABLE key (btab_pk_…).';
            }
            return 'That does not look like a btab publishable key (btab_pk_live_… or btab_pk_test_…).';
        }
        return null;
    }

    // ── the shop window ──────────────────────────────────────────────────────

    /** GET /site — the store's public settings. */
    public function site()
    {
        return $this->request('GET', '/site');
    }

    /**
     * GET /products — one page of the product list.
     *
     * @param array $query e.g. ['limit' => 24, 'page' => 2]
     */
    public function products(array $query = array())
    {
        return $this->request('GET', '/products' . $this->query_string($query));
    }

    /** GET /products/{id} — one product, with its options. */
    public function product($id)
    {
        return $this->request('GET', '/products/' . rawurlencode((string) $id));
    }

    // ── carts ────────────────────────────────────────────────────────────────

    /**
     * POST /carts — ids, options and quantities only; btab prices the lines.
     *
     * @param array       $items           [['product_id'=>1,'variant_id'=>2,'quantity'=>1], …]
     * @param string|null $idempotency_key a UUID; a retry with the same key and body returns the first answer
     */
    public function create_cart(array $items, $idempotency_key = null)
    {
        $headers = array();
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        return $this->request('POST', '/carts', array('items' => $items), $headers);
    }

    /** GET /carts/{id} — prices the lines again. */
    public function get_cart($cart_id)
    {
        return $this->request('GET', '/carts/' . rawurlencode((string) $cart_id));
    }

    /** PATCH /carts/{id} — replaces the lines. */
    public function update_cart($cart_id, array $items)
    {
        return $this->request('PATCH', '/carts/' . rawurlencode((string) $cart_id), array('items' => $items));
    }

    /**
     * POST /carts/{id}/shipping-estimate — freight by postcode.
     *
     * The answer is `{ estimate: { available, … } }`. When `available` is
     * false, `reason` says why and `refused` marks the ones checkout itself
     * refuses on — `SHIPPING_VOLUME_UNKNOWN` is "needs a quote", not free.
     *
     * @param string $cart_id
     * @param string $postal_code
     * @param string $country two letters; btab defaults to AU
     */
    public function shipping_estimate($cart_id, $postal_code, $country = 'AU')
    {
        $body = array('postal_code' => (string) $postal_code);
        $country = strtoupper(substr(trim((string) $country), 0, 2));
        if ($country !== '') {
            $body['country'] = $country;
        }
        return $this->request(
            'POST',
            '/carts/' . rawurlencode((string) $cart_id) . '/shipping-estimate',
            $body
        );
    }

    /** POST /carts/{id}/enquiry — the path for enquiry-only goods, which cannot be bought online. */
    public function enquiry($cart_id, array $fields)
    {
        return $this->request('POST', '/carts/' . rawurlencode((string) $cart_id) . '/enquiry', $fields);
    }

    // ── checkout ─────────────────────────────────────────────────────────────

    /**
     * POST /checkout_sessions — hand the cart to btab and get the URL to send
     * the buyer to. `continue_url` works for 30 minutes; `return_url` must be
     * on one of the key's allowed sites, so the shop's own thank-you page has
     * to be registered with the key.
     */
    public function create_checkout_session($cart_id, $return_url, $idempotency_key = null)
    {
        $headers = array();
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        return $this->request('POST', '/checkout_sessions', array(
            'cart_id' => (string) $cart_id,
            'return_url' => (string) $return_url,
        ), $headers);
    }

    /** GET /checkout_sessions/{id} — open | complete | expired. */
    public function get_checkout_session($session_id)
    {
        return $this->request('GET', '/checkout_sessions/' . rawurlencode((string) $session_id));
    }

    // ── plumbing ─────────────────────────────────────────────────────────────

    private function query_string(array $query)
    {
        if (!$query) {
            return '';
        }
        return '?' . http_build_query($query);
    }

    /**
     * @param string     $method
     * @param string     $path    starts with /
     * @param array|null $body    encoded as JSON when present
     * @param array      $headers extra headers
     * @return array the decoded JSON body
     * @throws Btab_Api_Exception on a transport failure, a non-2xx, or a body that is not JSON
     */
    public function request($method, $path, $body = null, array $headers = array())
    {
        $url = $this->base . $path;
        $payload = $body === null ? null : json_encode($body);

        $all = array(
            'X-Btab-Storefront-Key' => $this->key,
            'Accept' => 'application/json',
            // Named so btab can see which client is calling, as a storefront does.
            'User-Agent' => 'btab-woocommerce/' . (defined('BTAB_WC_VERSION') ? BTAB_WC_VERSION : '0'),
        );
        if ($payload !== null) {
            $all['Content-Type'] = 'application/json';
        }
        foreach ($headers as $k => $v) {
            $all[$k] = $v;
        }

        $transport = $this->transport ? $this->transport : array($this, 'curl_transport');
        $res = call_user_func($transport, $method, $url, $all, $payload, $this->timeout);

        $status = isset($res['status']) ? (int) $res['status'] : 0;
        $raw = isset($res['body']) ? (string) $res['body'] : '';

        if ($status === 0) {
            throw new Btab_Api_Exception('Could not reach btab: ' . (isset($res['error']) ? $res['error'] : 'no response'), 0, $raw);
        }

        $decoded = $raw === '' ? array() : json_decode($raw, true);
        if ($raw !== '' && $decoded === null) {
            throw new Btab_Api_Exception('btab answered something that is not JSON (HTTP ' . $status . ').', $status, $raw);
        }
        if ($status < 200 || $status >= 300) {
            // The API's own error shape, so the shop owner sees btab's words.
            $msg = 'btab refused the request (HTTP ' . $status . ').';
            if (is_array($decoded)) {
                if (isset($decoded['error']) && is_string($decoded['error'])) {
                    $msg = $decoded['error'];
                } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                    $msg = $decoded['message'];
                }
            }
            throw new Btab_Api_Exception($msg, $status, $raw);
        }
        return is_array($decoded) ? $decoded : array();
    }

    /** The default transport. Kept separate so a platform can inject its own. */
    public function curl_transport($method, $url, array $headers, $body, $timeout)
    {
        if (!function_exists('curl_init')) {
            return array('status' => 0, 'body' => '', 'error' => 'cURL is not available in this PHP');
        }
        $flat = array();
        foreach ($headers as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return array('status' => 0, 'body' => '', 'error' => $err);
        }
        return array('status' => $status, 'body' => $out);
    }
}

/** Anything the API did not answer cleanly. Carries the HTTP status and the raw body. */
class Btab_Api_Exception extends Exception
{
    /** @var int */
    private $status;
    /** @var string */
    private $raw;

    public function __construct($message, $status = 0, $raw = '')
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->raw = (string) $raw;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_raw()
    {
        return $this->raw;
    }
}
