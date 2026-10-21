<?php
/**
 * Updating the plugin from btab, instead of by hand.
 *
 * This plugin is not on WordPress.org, so WordPress has nowhere to look for a
 * new version: a shop would never be told one exists, and the owner would
 * re-upload a zip by hand or, far more likely, never update at all. So the
 * plugin tells WordPress where to look, and from then on it updates like any
 * other plugin — a notice on the Plugins screen, one click, done.
 *
 * ── THE THREAT MODEL IS "btab-api IS COMPROMISED" ────────────────────────────
 * This file ships code onto somebody else's server, so the question it has to
 * answer is not "was the download intact" but "did btab really publish this".
 * A checksum that arrives down the same channel as the address it describes
 * answers only the first: whoever can rewrite the address can rewrite the
 * checksum beside it. So:
 *
 *   1. THE MANIFEST ADDRESS IS A CONSTANT, NOT A SETTING. Every other address
 *      in this plugin (`api_base`) is a stored option an admin screen writes,
 *      which means a request can reach it. For prices that is a bad day; for
 *      code it is a remote shell. Override it in wp-config.php, a file no
 *      request can write, or not at all.
 *
 *   2. THE WHOLE PACKAGE ADDRESS IS PINNED, NOT JUST ITS HOST. It must equal,
 *      character for character, the release address for the version being
 *      offered on btab's own public mirror (see expected_package_url). An
 *      allowlist of hosts was not enough: github.com hosts everybody, so a
 *      compromised btab-api could have named an attacker's repository and
 *      supplied that zip's real checksum.
 *
 *   3. btab SIGNS EACH RELEASE, AND AN UNSIGNED ONE IS NOT INSTALLED. Ed25519,
 *      over a statement naming the version AND the hash of the zip, with the
 *      public keys baked into this file and the private key offline — never in
 *      btab-api and never in CI. This is the control that survives btab-api
 *      being compromised, and it survives write access to the mirror too: a
 *      re-uploaded zip has no valid signature. Because the signed statement
 *      names the version, an old-but-genuine release cannot be replayed as a
 *      new one either.
 *
 *   4. VERIFICATION FAILS CLOSED. Once this plugin recognises an update as its
 *      own it either verifies it or returns an error. It never hands the job
 *      back to WordPress, because WordPress would then fetch and unpack the
 *      package with no check at all. See verified_download().
 *
 *   5. EVERYTHING IS https, refused rather than downgraded or warned about.
 *
 * A FAILED UPDATE LEAVES THE SHOP ON THE WORKING VERSION. Every refusal here
 * returns a WP_Error, so WordPress reports "update failed" and keeps what is
 * installed. There is no path that half-installs.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Updater
{
    /**
     * Where the manifest lives. A constant, never an option — see rule 1.
     * wp-config.php may override it for a staging shop; it must still be https.
     */
    const DEFAULT_UPDATE_URL = 'https://api.btab.app/api/public/integrations/woocommerce/update.json';

    /** The one repository btab publishes releases from. Part of rule 2. */
    const RELEASE_REPO = 'SteveDeals/btab-for-woocommerce';

    /** The release asset's name, which is stable across versions by design. */
    const ASSET = 'btab-woocommerce.zip';

    /**
     * btab's release signing keys: base64 of the raw 32-byte Ed25519 public
     * key. Two are allowed so a key can be rotated without a release that no
     * shop can install — publish the new key in version N and sign with it
     * from version N+1.
     *
     * EMPTY MEANS NO UPDATES ARE OFFERED AT ALL (see signing_ready). That is
     * deliberate: no key means nothing can be verified, and offering an update
     * that cannot be installed is worse than offering none.
     */
    const PUBLIC_KEYS = array(
        // Slot 1, generated 2026-10-21. The private half is held offline by
        // btab; it has never been on a build machine and must never be.
        'rU4aERXerqcxOqUO6CRlkcViJiL58DaIICLai0DFN8A=',
        // Slot 2 is free, for the next key. To rotate: publish the new key in
        // a release signed with the OLD one, then sign the release after that
        // with the new one. Shops are never asked to trust a key they received
        // in the same release it first signed.
    );

    /**
     * The first line of every signed statement. Domain separation: a signature
     * btab made for a release can never be replayed as a signature for
     * something else, because nothing else is signed with this prefix.
     */
    const SIGNATURE_CONTEXT = 'btab-woocommerce-release-v1';

    /** The manifest is cached, because WordPress asks for updates very often. */
    const CACHE_KEY = 'btab_wc_update_manifest';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** A failed fetch is also cached, briefly, so a broken network is not hammered. */
    const CACHE_TTL_FAILURE = 15 * MINUTE_IN_SECONDS;

    public static function boot()
    {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 10, 3);
        add_filter('upgrader_pre_download', array(__CLASS__, 'verified_download'), 10, 4);
        add_action('upgrader_process_complete', array(__CLASS__, 'forget_manifest'), 10, 0);
    }

    /** The plugin's own basename, e.g. btab-woocommerce/btab-woocommerce.php. */
    public static function basename()
    {
        return plugin_basename(BTAB_WC_FILE);
    }

    /** The plugin's slug, which is the directory name WordPress installs into. */
    public static function slug()
    {
        return dirname(self::basename());
    }

    /**
     * Can anything be verified at all? Without a baked-in public key, or
     * without libsodium, there is no way to tell a btab release from an
     * attacker's, so nothing is offered. WordPress has shipped sodium_compat
     * since 5.2, so the second check is for a wilfully broken host.
     */
    public static function signing_ready()
    {
        return self::PUBLIC_KEYS !== array() && function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * The manifest address. https is required even for an override: a shop that
     * fat-fingers http:// gets no updates rather than updates anybody on the
     * path can replace.
     */
    public static function update_url()
    {
        $url = defined('BTAB_WC_UPDATE_URL') ? (string) BTAB_WC_UPDATE_URL : self::DEFAULT_UPDATE_URL;
        $url = trim($url);
        if ($url === '' || stripos($url, 'https://') !== 0 || wp_http_validate_url($url) === false) {
            return null;
        }
        return $url;
    }

    /**
     * The ONE address a given version's zip may come from — rule 2. Built here
     * from the version, never taken from the network, so the manifest can only
     * ever agree with it or be refused.
     */
    public static function expected_package_url($version)
    {
        return 'https://github.com/' . self::RELEASE_REPO . '/releases/download/v' . $version . '/' . self::ASSET;
    }

    /**
     * The exact bytes btab signs for a release. Four lines, 0x0A separated, no
     * trailing newline. The version is in here as well as the hash, so a
     * signature cannot be lifted off an older release and reused.
     */
    public static function signed_statement($version, $sha256)
    {
        return self::SIGNATURE_CONTEXT . "\n" . $version . "\n" . strtolower($sha256) . "\n" . self::ASSET;
    }

    /**
     * Did btab sign this exact version and these exact bytes?
     *
     * @param string $version    The version being installed.
     * @param string $sha256     The hash of the file ACTUALLY downloaded.
     * @param string $signature  base64 of the raw 64-byte signature.
     * @return bool
     */
    public static function signature_valid($version, $sha256, $signature)
    {
        if (!self::signing_ready()) {
            return false;
        }
        return self::signature_valid_for_keys($version, $sha256, $signature, self::PUBLIC_KEYS);
    }

    /**
     * The verification itself, against a given set of keys.
     *
     * SPLIT OUT SO THE TEST CAN EXERCISE THIS EXACT CODE rather than a copy of
     * it. btab's private key is offline, so a test can never produce a
     * signature the baked-in key accepts — which left the only thing provable
     * about the real method being that it REFUSES things. A check that refuses
     * everything passes every such test and is still useless. With the keys as
     * an argument, the test signs with a throwaway key and runs this, the
     * shipped verification, down its accepting path.
     *
     * It is not a seam in production: signature_valid() passes PUBLIC_KEYS and
     * nothing else ever calls this, so the trusted keys cannot be substituted
     * by a filter, an option or a request.
     *
     * @param string   $version
     * @param string   $sha256
     * @param string   $signature base64 of the raw 64-byte signature.
     * @param string[] $keys      base64 of raw 32-byte Ed25519 public keys.
     * @return bool
     */
    public static function signature_valid_for_keys($version, $sha256, $signature, array $keys)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $raw = base64_decode((string) $signature, true);
        // Ed25519 signatures are exactly 64 bytes; sodium throws on anything else.
        if ($raw === false || strlen($raw) !== 64) {
            return false;
        }
        $message = self::signed_statement($version, $sha256);
        foreach ($keys as $encoded) {
            $key = base64_decode((string) $encoded, true);
            if ($key === false || strlen($key) !== 32) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($raw, $message, $key)) {
                    return true;
                }
            } catch (Exception $e) {
                continue;
            } catch (Error $e) {
                continue;
            }
        }
        return false;
    }

    /**
     * The published manifest, or null. Cached either way.
     *
     * @param bool $force Ignore the cache.
     * @return array|null
     */
    public static function manifest($force = false)
    {
        // Nothing can be verified, so nothing is published as far as this shop
        // is concerned. Checked before the network call: no point asking.
        if (!self::signing_ready()) {
            return null;
        }
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            // An empty array is a remembered failure, and is not retried yet.
            if (is_array($cached)) {
                return $cached === array() ? null : $cached;
            }
        }

        $url = self::update_url();
        if ($url === null) {
            return null;
        }

        $res = wp_remote_get($url, array(
            'timeout' => 5,
            // A manifest is a few hundred bytes. Without a limit, a hostile or
            // broken answer could be read into memory until the shop falls over.
            'limit_response_size' => 65536,
            'headers' => array('Accept' => 'application/json'),
            'user-agent' => 'btab-woocommerce/' . BTAB_WC_VERSION . '; ' . home_url('/'),
        ));

        $manifest = self::parse($res);
        if ($manifest === null) {
            set_site_transient(self::CACHE_KEY, array(), self::CACHE_TTL_FAILURE);
            return null;
        }
        set_site_transient(self::CACHE_KEY, $manifest, self::CACHE_TTL);
        return $manifest;
    }

    /**
     * Turn a response into a manifest, or null. A manifest missing anything
     * that matters is not half-used: without a checksum there is nothing to
     * compare, and an address that is not the pinned one is not negotiable.
     *
     * @param array|WP_Error $res
     * @return array|null
     */
    private static function parse($res)
    {
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body)) {
            return null;
        }

        $version = isset($body['version']) ? trim((string) $body['version']) : '';
        $package = isset($body['download_url']) ? trim((string) $body['download_url']) : '';
        $sha256 = isset($body['sha256']) ? strtolower(trim((string) $body['sha256'])) : '';

        // A version WordPress cannot order with version_compare is worse than
        // nothing. Also keeps anything odd out of the pinned URL below.
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $version)) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return null;
        }
        // Rule 2: the whole address, compared exactly against the one address
        // this version may come from. Not a host check, not a prefix check.
        if (!hash_equals(self::expected_package_url($version), $package)) {
            return null;
        }

        return array(
            'version' => $version,
            'download_url' => $package,
            'sha256' => $sha256,
            'requires' => isset($body['requires']) ? (string) $body['requires'] : '',
            'requires_php' => isset($body['requires_php']) ? (string) $body['requires_php'] : '',
            'tested' => isset($body['tested']) ? (string) $body['tested'] : '',
            'last_updated' => isset($body['last_updated']) ? (string) $body['last_updated'] : '',
            'homepage' => isset($body['homepage']) && self::is_https($body['homepage']) ? (string) $body['homepage'] : '',
            'sections' => isset($body['sections']) && is_array($body['sections'])
                ? array_map('strval', $body['sections'])
                : array(),
        );
    }

    private static function is_https($url)
    {
        $url = trim((string) $url);
        return $url !== '' && stripos($url, 'https://') === 0 && wp_http_validate_url($url) !== false;
    }

    /** True when the published version is newer than the one running. */
    public static function update_available($manifest)
    {
        return is_array($manifest) && version_compare($manifest['version'], BTAB_WC_VERSION, '>');
    }

    /**
     * Add this plugin to the list of available updates.
     *
     * WordPress hands us the whole transient and expects it back. A failure
     * here must therefore return $value untouched — returning null or a partial
     * object would wipe every OTHER plugin's pending update from the list.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function inject_update($value)
    {
        if (!is_object($value)) {
            return $value;
        }
        $basename = self::basename();
        $manifest = self::manifest();

        if (!self::update_available($manifest)) {
            // Also clear a stale entry: a shop that updated by hand should not
            // keep being told an update is waiting.
            if (isset($value->response) && is_array($value->response)) {
                unset($value->response[$basename]);
            }
            if (isset($value->no_update) && is_array($value->no_update)) {
                $value->no_update[$basename] = self::offer($manifest, false);
            }
            return $value;
        }

        if (!isset($value->response) || !is_array($value->response)) {
            $value->response = array();
        }
        $value->response[$basename] = self::offer($manifest, true);
        return $value;
    }

    /**
     * The object WordPress wants per plugin.
     *
     * `btab_sha256` is carried here on purpose: it is the checksum that came
     * WITH this particular offer, and it is what verified_download() compares
     * against later. Reading a fresh manifest at download time instead would
     * mean an update could be verified against a manifest other than the one
     * that offered it — and, worse, that a manifest which had since become
     * unreachable would leave nothing to verify against at all (rule 4).
     *
     * @return object
     */
    private static function offer($manifest, $is_update)
    {
        $o = new stdClass();
        $o->id = 'btab-woocommerce';
        $o->slug = self::slug();
        $o->plugin = self::basename();
        $o->new_version = $is_update && is_array($manifest) ? $manifest['version'] : BTAB_WC_VERSION;
        $o->url = is_array($manifest) && $manifest['homepage'] !== '' ? $manifest['homepage'] : '';
        $o->package = $is_update && is_array($manifest) ? $manifest['download_url'] : '';
        $o->tested = is_array($manifest) ? $manifest['tested'] : '';
        $o->requires = is_array($manifest) ? $manifest['requires'] : '';
        $o->requires_php = is_array($manifest) ? $manifest['requires_php'] : '';
        $o->icons = array();
        $o->banners = array();
        $o->banners_rtl = array();
        // Ours, not WordPress's. Travels with the offer in the transient.
        $o->btab_sha256 = $is_update && is_array($manifest) ? $manifest['sha256'] : '';
        return $o;
    }

    /**
     * The "View details" modal. WordPress asks every plugin, so anything that
     * is not our slug is handed straight back untouched.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public static function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== self::slug()) {
            return $result;
        }
        $manifest = self::manifest();
        if (!is_array($manifest)) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'btab for WooCommerce';
        $info->slug = self::slug();
        $info->version = $manifest['version'];
        $info->author = 'btab';
        $info->homepage = $manifest['homepage'];
        $info->requires = $manifest['requires'];
        $info->requires_php = $manifest['requires_php'];
        $info->tested = $manifest['tested'];
        $info->last_updated = $manifest['last_updated'];
        $info->download_link = $manifest['download_url'];
        $info->trunk = $manifest['download_url'];
        $info->sections = $manifest['sections'] !== array()
            ? $manifest['sections']
            : array('description' => 'Sell btab products from your own WooCommerce shop.');
        return $info;
    }

    /**
     * The version and checksum this shop was really offered, for the package
     * WordPress is about to fetch — or null if there is no such offer.
     *
     * THE UPDATE TRANSIENT IS THE SOURCE, not a fresh manifest. The address and
     * the checksum entered the transient together, so they cannot be mixed: an
     * attacker who got an address in there and then took the manifest away does
     * not get an unverified install, they get an error (rule 4).
     *
     * A live manifest is consulted only as a fallback for the case where the
     * transient has been cleared between the check and the click. That is safe
     * because the signature, not the manifest, is what finally authorises the
     * install.
     *
     * @param string $package
     * @return array|null  { version, sha256, package }
     */
    private static function trusted_offer($package)
    {
        $basename = self::basename();
        $transient = get_site_transient('update_plugins');
        if (is_object($transient)) {
            foreach (array('response', 'no_update') as $bucket) {
                if (!isset($transient->{$bucket}) || !is_array($transient->{$bucket})) {
                    continue;
                }
                if (!isset($transient->{$bucket}[$basename])) {
                    continue;
                }
                $o = $transient->{$bucket}[$basename];
                if (!is_object($o) || empty($o->package) || empty($o->new_version) || empty($o->btab_sha256)) {
                    continue;
                }
                if (hash_equals((string) $o->package, $package)) {
                    // Newer, here too. inject_update() only ever writes a newer
                    // version into the transient, so this is belt and braces —
                    // but leaving the install path with two different rules
                    // depending on where the offer came from is how a hole gets
                    // reintroduced later by someone reading only one branch.
                    if (!version_compare((string) $o->new_version, BTAB_WC_VERSION, '>')) {
                        return null;
                    }
                    return array(
                        'version' => (string) $o->new_version,
                        'sha256' => strtolower((string) $o->btab_sha256),
                        'package' => (string) $o->package,
                    );
                }
            }
        }

        $manifest = self::manifest();
        if (is_array($manifest) && hash_equals($manifest['download_url'], $package)) {
            // IT MUST BE NEWER, and the signature cannot tell us that.
            //
            // An OLD btab release is genuinely signed: its statement names its
            // own version and its own hash, so the signature verifies
            // perfectly. The signature proves btab published it, never that it
            // is the version a shop should be running. Without this check a
            // compromised btab-api could answer with a real, properly signed
            // 0.1.0 and walk every shop BACKWARDS onto whatever was wrong with
            // it — a downgrade attack carried out entirely with authentic
            // files.
            //
            // update_available() already gates the OFFER this way. This is the
            // same rule on the install path, which is reachable on its own when
            // the transient was cleared between the check and the click.
            if (!version_compare($manifest['version'], BTAB_WC_VERSION, '>')) {
                return null;
            }
            return array(
                'version' => $manifest['version'],
                'sha256' => $manifest['sha256'],
                'package' => $manifest['download_url'],
            );
        }
        return null;
    }

    /** btab's detached signature for a package, fetched from beside it. */
    private static function fetch_signature($package)
    {
        $res = wp_remote_get($package . '.sig', array(
            'timeout' => 10,
            'limit_response_size' => 4096,
            'user-agent' => 'btab-woocommerce/' . BTAB_WC_VERSION,
        ));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return '';
        }
        return trim((string) wp_remote_retrieve_body($res));
    }

    /**
     * Download the package ourselves, verify it, then hand WordPress the local
     * file. Rules 3 and 4 live here.
     *
     * WordPress calls this for EVERY plugin and theme download, so the first
     * job is to decide whether this one is ours. That decision is made from
     * $hook_extra['plugin'], which WP_Upgrader passes on every update path —
     * one click, bulk, `wp plugin update` and background auto-updates all go
     * through WP_Upgrader::download_package() — or from the package address
     * matching an offer of ours.
     *
     * ONCE IT IS OURS, THIS METHOD NEVER RETURNS false. Returning false means
     * "WordPress, you fetch it", and WordPress fetches and unpacks with no
     * check whatsoever. Every failure here is a WP_Error instead, which leaves
     * the shop on the version it is running.
     *
     * @param bool|WP_Error $reply       false to let WordPress download it.
     * @param string        $package     The URL WordPress is about to fetch.
     * @param WP_Upgrader   $upgrader
     * @param array         $hook_extra
     * @return bool|string|WP_Error  A local path, false, or an error.
     */
    public static function verified_download($reply, $package, $upgrader = null, $hook_extra = array())
    {
        // Somebody earlier in the filter chain already handled it.
        if ($reply !== false) {
            return $reply;
        }
        if (!is_string($package) || $package === '') {
            return $reply;
        }

        $named_us = is_array($hook_extra)
            && isset($hook_extra['plugin'])
            && is_string($hook_extra['plugin'])
            && hash_equals(self::basename(), $hook_extra['plugin']);

        $trusted = self::trusted_offer($package);

        // Genuinely somebody else's download: say nothing, change nothing.
        if (!$named_us && $trusted === null) {
            return $reply;
        }

        // ── From here on this is ours, and every exit is a path or an error ──

        if (!self::signing_ready()) {
            return new WP_Error(
                'btab_wc_update_no_key',
                'btab for WooCommerce: this copy of the plugin cannot check that an update really comes from btab, so it was not installed. Please update it by hand from btab.'
            );
        }
        if ($trusted === null) {
            // WordPress is updating us from an address we never offered, or the
            // checksum that came with the offer has been lost. Either way there
            // is nothing to verify against, so nothing is installed.
            return new WP_Error(
                'btab_wc_update_untrusted',
                'btab for WooCommerce: this update did not come with a checksum from btab, so it was not installed. Nothing has changed on your shop.'
            );
        }
        // Rule 2 again, on the address actually being fetched, and against the
        // version that address claims to be.
        if (!hash_equals(self::expected_package_url($trusted['version']), $package)) {
            return new WP_Error(
                'btab_wc_update_host',
                'btab for WooCommerce: the update is not where btab publishes it, so it was not installed.'
            );
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }

        $actual = @hash_file('sha256', $file);
        if (!is_string($actual)) {
            return self::refuse($file, 'btab_wc_update_unreadable', 'the downloaded file could not be read');
        }
        $actual = strtolower($actual);

        // The checksum that came with the offer. Catches a damaged transfer,
        // and a mirror whose asset was replaced since the offer was made.
        if (!hash_equals($trusted['sha256'], $actual)) {
            return self::refuse(
                $file,
                'btab_wc_update_checksum',
                'the downloaded update did not match its checksum'
            );
        }

        // Rule 3, and the only check that survives btab-api being compromised:
        // did btab itself sign THIS version and THESE bytes?
        $signature = self::fetch_signature($package);
        if ($signature === '') {
            return self::refuse(
                $file,
                'btab_wc_update_unsigned',
                'btab\'s signature for this update could not be fetched'
            );
        }
        if (!self::signature_valid($trusted['version'], $actual, $signature)) {
            return self::refuse(
                $file,
                'btab_wc_update_signature',
                'this update is not signed by btab'
            );
        }

        return $file;
    }

    /**
     * Refuse an update and leave nothing behind. A half-trusted zip in the
     * temp directory is one an unrelated bug could still unpack later. A
     * literal path from download_url(), never a variable-rooted delete.
     */
    private static function refuse($file, $code, $because)
    {
        if (is_string($file) && $file !== '' && file_exists($file)) {
            wp_delete_file($file);
        }
        return new WP_Error(
            $code,
            'btab for WooCommerce: ' . $because . ', so it was not installed. '
            . 'Nothing has changed on your shop. Please try again later.'
        );
    }

    /** Drop the cached manifest, so the next check asks btab again. */
    public static function forget_manifest()
    {
        delete_site_transient(self::CACHE_KEY);
    }
}
