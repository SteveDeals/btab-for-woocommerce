<?php
/**
 * The wp-admin settings page: API base, publishable key, and the test button.
 *
 * WordPress-specific on purpose. The portable half is lib/class-btab-client.php.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Settings
{
    const PAGE = 'btab-wc';

    /** Defaults, merged under whatever is stored, so a new setting never reads as null. */
    public static function defaults()
    {
        return array(
            'api_base' => 'https://api.btab.app/api/storefront/v1',
            'publishable_key' => '',
            'sync_interval' => 'hourly',
        );
    }

    /** @return array the settings, defaults filled in. */
    public static function get()
    {
        $stored = get_option(BTAB_WC_OPTION, array());
        return array_merge(self::defaults(), is_array($stored) ? $stored : array());
    }

    public static function boot()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_post_btab_wc_test', array(__CLASS__, 'handle_test'));
    }

    public static function menu()
    {
        add_submenu_page(
            'woocommerce',
            'btab',
            'btab',
            'manage_woocommerce',
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    public static function register()
    {
        register_setting(self::PAGE, BTAB_WC_OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default' => self::defaults(),
        ));
    }

    /**
     * A key is a credential: it is trimmed, length-checked and stored, never
     * echoed back in full and never written to a log.
     */
    public static function sanitize($input)
    {
        $out = self::get();
        if (!is_array($input)) {
            return $out;
        }
        if (isset($input['api_base'])) {
            $base = trim((string) $input['api_base']);
            // Only https, and only a real URL: a key must never leave over http.
            if ($base === '' || wp_http_validate_url($base) === false || stripos($base, 'https://') !== 0) {
                add_settings_error(self::PAGE, 'api_base', 'The API base must be an https URL.');
            } else {
                $out['api_base'] = untrailingslashit(esc_url_raw($base));
            }
        }
        if (isset($input['publishable_key'])) {
            $key = trim((string) $input['publishable_key']);
            if ($key !== '') {
                $probe = new Btab_Client($out['api_base'], $key);
                $problem = $probe->key_problem();
                if ($problem !== null) {
                    add_settings_error(self::PAGE, 'publishable_key', $problem);
                } else {
                    $out['publishable_key'] = $key;
                }
            }
        }
        if (isset($input['sync_interval'])) {
            $allowed = array('hourly', 'twicedaily', 'daily');
            $out['sync_interval'] = in_array($input['sync_interval'], $allowed, true) ? $input['sync_interval'] : 'hourly';
        }
        return $out;
    }

    /** Show a key as its prefix and last four, so an owner can tell which key it is without it being readable. */
    public static function mask($key)
    {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 12) {
            return str_repeat('•', strlen($key));
        }
        return substr($key, 0, 12) . str_repeat('•', 8) . substr($key, -4);
    }

    public static function render()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You cannot manage this shop.');
        }
        $s = self::get();
        $test_result = get_transient('btab_wc_test_result');
        delete_transient('btab_wc_test_result');
        ?>
        <div class="wrap">
            <h1>btab</h1>
            <p>Your shop shows btab products and the buyer pays on btab. Products are copied in and kept fresh; btab prices every line at checkout.</p>
            <?php settings_errors(self::PAGE); ?>
            <?php if ($test_result) : ?>
                <div class="notice notice-<?php echo esc_attr($test_result['ok'] ? 'success' : 'error'); ?>">
                    <p><?php echo esc_html($test_result['message']); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="btab_api_base">API base</label></th>
                        <td>
                            <input name="<?php echo esc_attr(BTAB_WC_OPTION); ?>[api_base]" id="btab_api_base"
                                   type="url" class="regular-text code"
                                   value="<?php echo esc_attr($s['api_base']); ?>">
                            <p class="description">Leave this as it is unless btab tells you otherwise.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="btab_key">Publishable key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(BTAB_WC_OPTION); ?>[publishable_key]" id="btab_key"
                                   type="password" class="regular-text code" autocomplete="off"
                                   placeholder="<?php echo esc_attr($s['publishable_key'] ? self::mask($s['publishable_key']) : 'btab_pk_live_…'); ?>">
                            <p class="description">
                                From btab: Settings → Developers → Storefront keys. A <code>btab_pk_test_…</code> key
                                reads btab's demo store and never charges. Never paste a store API key
                                (<code>btab_live_…</code>) here.
                                <?php if ($s['publishable_key']) : ?>
                                    <br><strong>Set:</strong> <code><?php echo esc_html(self::mask($s['publishable_key'])); ?></code>
                                    — leave the box empty to keep it.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="btab_interval">Resync</label></th>
                        <td>
                            <select name="<?php echo esc_attr(BTAB_WC_OPTION); ?>[sync_interval]" id="btab_interval">
                                <?php foreach (array('hourly' => 'Every hour', 'twicedaily' => 'Twice a day', 'daily' => 'Once a day') as $v => $label) : ?>
                                    <option value="<?php echo esc_attr($v); ?>" <?php selected($s['sync_interval'], $v); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save'); ?>
            </form>

            <?php
            $sync = get_transient('btab_wc_sync_result');
            delete_transient('btab_wc_sync_result');
            if ($sync) :
                ?>
                <div class="notice notice-<?php echo esc_attr($sync['ok'] ? 'success' : 'error'); ?>">
                    <p><?php echo esc_html($sync['message']); ?></p>
                </div>
            <?php endif; ?>

            <h2>Products</h2>
            <?php
            $last = Btab_WC_Sync::last();
            if ($last && !empty($last['at'])) :
                ?>
                <p>
                    Last sync <?php echo esc_html(human_time_diff((int) $last['at'], time())); ?> ago
                    (<?php echo esc_html($last['source'] === 'manual' ? 'Sync now' : 'on a schedule'); ?>):
                    <?php echo esc_html(Btab_WC_Sync::describe(isset($last['stats']) ? (array) $last['stats'] : array(), isset($last['problems']) ? (array) $last['problems'] : array())); ?>
                </p>
            <?php else : ?>
                <p>No sync has run yet.</p>
            <?php endif; ?>
            <?php $next = wp_next_scheduled(Btab_WC_Sync::HOOK); ?>
            <p class="description">
                <?php if ($next) : ?>
                    Next automatic sync in <?php echo esc_html(human_time_diff(time(), $next)); ?>.
                <?php else : ?>
                    No automatic sync is scheduled.
                <?php endif; ?>
                Photos are copied into this shop's Media Library, so they are served from your own domain.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="btab_wc_sync_now">
                <?php wp_nonce_field('btab_wc_sync_now'); ?>
                <?php submit_button('Sync now', 'primary', 'submit', false); ?>
            </form>

            <h2>Check the connection</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="btab_wc_test">
                <?php wp_nonce_field('btab_wc_test'); ?>
                <?php submit_button('Test connection', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /** Calls GET /site and reports what came back, in the owner's words. */
    public static function handle_test()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You cannot manage this shop.');
        }
        check_admin_referer('btab_wc_test');

        $client = btab_wc_client();
        if (!$client) {
            $s = Btab_WC_Settings::get();
            $probe = new Btab_Client($s['api_base'], $s['publishable_key']);
            $problem = $probe->key_problem();
            set_transient('btab_wc_test_result', array(
                'ok' => false,
                'message' => $problem ? $problem : 'Set the API base and a publishable key first.',
            ), 60);
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
            exit;
        }

        try {
            $site = $client->site();
            $name = '';
            foreach (array('store_name', 'name', 'title') as $k) {
                if (!empty($site[$k]) && is_string($site[$k])) {
                    $name = $site[$k];
                    break;
                }
            }
            $mode = $client->is_test_key() ? ' (test key — btab\'s demo store, nothing is charged)' : '';
            set_transient('btab_wc_test_result', array(
                'ok' => true,
                'message' => 'Connected to btab' . ($name ? ': ' . $name : '') . $mode . '.',
            ), 60);
        } catch (Btab_Api_Exception $e) {
            set_transient('btab_wc_test_result', array(
                'ok' => false,
                'message' => 'btab said: ' . $e->getMessage(),
            ), 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        exit;
    }
}
