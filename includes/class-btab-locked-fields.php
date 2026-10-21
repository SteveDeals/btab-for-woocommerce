<?php
/**
 * btab's numbers are btab's. wp-admin says so, and then makes it true.
 *
 * A shop owner who retypes the price of a btab product changes nothing that
 * matters — btab prices every line again at checkout, so the buyer is never
 * charged the number in WooCommerce — but the shop SHOWS a price nobody will
 * honour until the next change arrives, and sorts and filters by it. That is a
 * quiet lie on a live shop, and the owner has no way to know they caused it.
 *
 * ── ONE HOOK, BECAUSE THERE ARE A DOZEN WAYS TO SAVE A PRODUCT ──────────────
 * The first version of this file hooked the product edit form and the REST
 * insert. Quick Edit, Bulk Edit, the CSV importer, `wp wc product update` and
 * any other plugin's `$product->save()` all went straight past it, and
 * VARIATIONS were never guarded at all — a variation is saved through its own
 * CRUD path, and the readonly script never even reached the variations panel,
 * which loads by ajax after the footer.
 *
 * So the guard hangs on `woocommerce_before_product_object_save` and its
 * variation twin, which every one of those paths goes through. Only a raw
 * `update_post_meta('_price', …)` is left outside, and nothing in WooCommerce
 * writes prices that way.
 *
 * BEFORE the save, not after, for a second reason: WooCommerce's REST applies
 * `meta_data` and saves BEFORE `rest_insert_product` fires, so a request could
 * send `_btab_price: 1.00` and have the guard dutifully "restore" the value
 * the attacker supplied — or send an empty `_btab_product_id` and turn the
 * guard off. At before-save time the database still holds the real values.
 *
 * ── AND THE SYNC HAS TO GET THROUGH ────────────────────────────────────────
 * The importer is the one writer allowed to change these, so it raises
 * `$syncing` around its own saves.
 *
 * ── WHAT IS NOT LOCKED ─────────────────────────────────────────────────────
 * Title, description, categories and photos. A shop owner may well want to
 * write about the goods in their own words, and nothing about that charges
 * anyone the wrong amount. Only the numbers that have to agree with btab.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Locked_Fields
{
    /**
     * Raised by the importer around its own saves. Public because the importer
     * is deliberately the only thing allowed to set it.
     *
     * @var bool
     */
    public static $syncing = false;

    /**
     * Where a shop owner goes to change it for real. The host is
     * `VENDOR_DASHBOARD_URL`'s default in btab-api and the dashboard's own
     * `/products/{id}` route. This is a wp-admin screen, not a buyer page, so
     * naming btab here is right: the owner knows they use it.
     */
    const DASHBOARD = 'https://dashboard.btab.app';

    public static function boot()
    {
        // EVERY save of a product or a variation, however it arrived.
        add_action('woocommerce_before_product_object_save', array(__CLASS__, 'guard'), 999, 1);
        add_action('woocommerce_before_product_variation_object_save', array(__CLASS__, 'guard'), 999, 1);

        // A variation the owner added to a btab product cannot be bought,
        // because btab has never heard of it and cannot fulfil it.
        add_filter('woocommerce_variation_is_purchasable', array(__CLASS__, 'owner_variation_not_purchasable'), 20, 2);

        if (!is_admin()) {
            return;
        }
        add_action('admin_notices', array(__CLASS__, 'owner_variation_notice'));
        add_action('woocommerce_product_options_pricing', array(__CLASS__, 'pricing_note'));
        add_action('woocommerce_product_options_inventory_product_data', array(__CLASS__, 'inventory_note'));
        add_action('admin_footer', array(__CLASS__, 'make_fields_readonly'));
    }

    /** @return bool is this product btab's? */
    public static function is_btab($product_id)
    {
        return (bool) get_post_meta((int) $product_id, BTAB_WC_META_PRODUCT_ID, true);
    }

    /**
     * The id whose reference values apply to this object.
     *
     * A VARIATION FALLS BACK TO ITS PARENT (review L1). Without that, a
     * variation imported before the guard existed stays unguarded until the
     * next resync, and a variation the shop owner ADDS to a btab product is
     * never guarded at all — the importer only ever touches variations that
     * carry `_btab_variant_id`. In both cases the owner's price would show in
     * the buyer's "From $x" range.
     *
     * @param WC_Product $product
     * @return int 0 when the product is not btab's
     */
    private static function reference_id($product)
    {
        $id = (int) $product->get_id();
        if ($id && self::is_btab($id)) {
            return $id;
        }
        $parent = (int) $product->get_parent_id();
        return ($parent && self::is_btab($parent)) ? $parent : 0;
    }

    /** One reference value, from the product or else from its parent. */
    private static function reference_meta($product, $key)
    {
        $value = (string) get_post_meta((int) $product->get_id(), $key, true);
        if ($value !== '') {
            return $value;
        }
        $parent = (int) $product->get_parent_id();
        return $parent ? (string) get_post_meta($parent, $key, true) : '';
    }

    /** The link to this product in btab. */
    public static function edit_link($product_id)
    {
        $id = (int) $product_id;
        $parent = (int) wp_get_post_parent_id($id);
        $btab_id = (int) get_post_meta($id, BTAB_WC_META_PRODUCT_ID, true);
        if (!$btab_id && $parent) {
            $btab_id = (int) get_post_meta($parent, BTAB_WC_META_PRODUCT_ID, true);
        }
        return $btab_id ? self::DASHBOARD . '/products/' . $btab_id : self::DASHBOARD . '/my-products';
    }

    /**
     * Put btab's numbers back, whatever the request said.
     *
     * This is the guard. A readonly input is a courtesy; this is the thing that
     * is actually true.
     *
     * @param WC_Product $product
     */
    public static function guard($product)
    {
        if (self::$syncing || !$product instanceof WC_Product) {
            return;
        }
        $id = (int) $product->get_id();
        // A product being CREATED has no id yet — but a VARIATION being created
        // already knows its parent, and a variation an owner adds by hand to a
        // btab product is exactly the case review L1 is about. So the parent
        // decides, not the presence of an id. A brand-new top-level product has
        // no btab parent and falls out here, which is right: the importer is
        // the only thing that creates btab products, and it runs inside the
        // syncing flag anyway.
        if (!self::reference_id($product)) {
            return;
        }

        // A VARIATION THE OWNER ADDED is not btab's to price. btab has never
        // heard of it, so there is no right number to put back — and pretending
        // it costs the parent's price would be inventing one. It is made
        // unsellable instead (the filter below), and wp-admin says why. Its
        // reference meta is stripped above/below so nothing can be smuggled in.
        if (self::is_owner_variation($product)) {
            self::strip_our_meta($product);
            return;
        }

        // A VARIABLE PARENT HAS NO PRICE OF ITS OWN (review L3). Its `_price`
        // is a summary WooCommerce derives from the variations, and writing one
        // number over it would flatten per-variant prices the day btab starts
        // sending them. The guard holds the variations instead, which it does.
        if (!$product->is_type('variable')) {
            // btab's own answer, or — for a product imported before this
            // existed — whatever is in the database right now, which at least
            // means an edit changes nothing rather than standing until a resync.
            $price = self::reference_meta($product, '_btab_price');
            if ($price === '') {
                $price = (string) get_post_meta($id, '_regular_price', true);
            }
            if ($price !== '') {
                $product->set_regular_price($price);
                $product->set_sale_price('');
                // A cleared sale must not leave its schedule behind, or the
                // price drops by itself on the start date.
                $product->set_date_on_sale_from(null);
                $product->set_date_on_sale_to(null);
                $product->set_price($price);
            }
        }

        $stock = self::reference_meta($product, '_btab_stock_status');
        if ($stock === '') {
            $stock = (string) get_post_meta($id, '_stock_status', true);
        }
        if ($stock !== '') {
            $product->set_stock_status($stock);
        }
        // btab holds the goods and counts them. WooCommerce must not keep its
        // own tally and start refusing orders btab would happily fill.
        $product->set_manage_stock(false);
        $product->set_backorders('no');

        if ($id) {
            self::keep_our_meta($product, $id);
        } else {
            // Being CREATED, so nothing of btab's is stored for it yet and
            // every _btab_* value on the object is the request's invention.
            self::strip_our_meta($product);
        }
    }

    /**
     * A request may not rewrite the values the guard reads.
     *
     * WooCommerce's REST applies `meta_data` to the object before it saves, so
     * without this a request could set `_btab_price` to its own number and have
     * the guard put THAT back — or blank `_btab_product_id` and switch the
     * guard off for that product entirely.
     *
     * @param WC_Product $product
     * @param int        $id
     */
    private static function keep_our_meta($product, $id)
    {
        foreach (array(BTAB_WC_META_PRODUCT_ID, '_btab_price', '_btab_stock_status', '_btab_variant_id', '_btab_enquiry_only') as $key) {
            $stored = get_post_meta($id, $key, true);
            if ($stored === '' || $stored === null) {
                // NOT IN THE DATABASE, so it may not be in the object either
                // (review L2). Skipping here let a REST `meta_data` entry ADD
                // `_btab_price` to a product that has none — and the next save
                // would read that number back and "restore" it.
                if ((string) $product->get_meta($key) !== '') {
                    $product->delete_meta_data($key);
                }
                continue;
            }
            if ((string) $product->get_meta($key) !== (string) $stored) {
                $product->update_meta_data($key, $stored);
            }
        }
    }

    /**
     * A variation under a btab product that btab did not send.
     *
     * @param WC_Product $product
     * @return bool
     */
    private static function is_owner_variation($product)
    {
        if (!$product->is_type('variation')) {
            return false;
        }
        $parent = (int) $product->get_parent_id();
        if (!$parent || !self::is_btab($parent)) {
            return false;
        }
        $id = (int) $product->get_id();
        // btab's own variations carry the variant id it sent. A new object has
        // no id and so no stored meta: it cannot be one of btab's.
        return !$id || !get_post_meta($id, '_btab_variant_id', true);
    }

    /** Take every _btab_* value off an object that has no right to one. */
    private static function strip_our_meta($product)
    {
        foreach (array(BTAB_WC_META_PRODUCT_ID, '_btab_price', '_btab_stock_status', '_btab_variant_id', '_btab_enquiry_only') as $key) {
            if ((string) $product->get_meta($key) !== '') {
                $product->delete_meta_data($key);
            }
        }
    }

    /**
     * It cannot be bought: btab has never heard of it and cannot ship it.
     *
     * @param bool       $purchasable
     * @param WC_Product $variation
     * @return bool
     */
    public static function owner_variation_not_purchasable($purchasable, $variation)
    {
        return self::is_owner_variation($variation) ? false : $purchasable;
    }

    /** One line in wp-admin, so the owner is not left guessing. */
    public static function owner_variation_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }
        global $post;
        if (!$post || !self::is_btab($post->ID)) {
            return;
        }
        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        foreach ($product->get_children() as $child) {
            if (!get_post_meta($child, '_btab_variant_id', true)) {
                echo '<div class="notice notice-warning"><p><strong>'
                    . esc_html__('btab products cannot have your own variations.', 'btab-woocommerce')
                    . '</strong> '
                    . esc_html__('btab has never heard of the ones you added, so it cannot ship them. They are shown but cannot be bought. Delete them yourself if you do not want them on the page.', 'btab-woocommerce')
                    . '</p></div>';
                return;
            }
        }
    }

    public static function pricing_note()
    {
        global $post;
        if (!$post || !self::is_btab($post->ID)) {
            return;
        }
        self::note($post->ID, __('The price comes from btab and is charged by btab. Changing it here would only change what this shop shows.', 'btab-woocommerce'));
    }

    public static function inventory_note()
    {
        global $post;
        if (!$post || !self::is_btab($post->ID)) {
            return;
        }
        self::note($post->ID, __('Stock comes from btab, which holds the goods.', 'btab-woocommerce'));
    }

    private static function note($product_id, $text)
    {
        echo '<p class="form-field"><span class="description">' . esc_html($text) . ' <a href="' . esc_url(self::edit_link($product_id)) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Edit in btab', 'btab-woocommerce') . '</a></span></p>';
    }

    /**
     * Readonly on screen — a courtesy, not the guard.
     *
     * Deliberately readonly and NOT disabled: a disabled input submits nothing,
     * which WooCommerce reads as "cleared".
     *
     * The variations panel is loaded by ajax long after this runs, so the
     * handler watches for them arriving rather than running once.
     */
    public static function make_fields_readonly()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }
        global $post;
        if (!$post || !self::is_btab($post->ID)) {
            return;
        }
        ?>
        <script>
        (function () {
            var NAMES = ['_regular_price', '_sale_price', '_stock', '_stock_status', '_manage_stock', '_backorders',
                         'variable_regular_price', 'variable_sale_price', 'variable_stock', 'variable_stock_status',
                         'variable_manage_stock', 'variable_backorders'];
            function hold(root) {
                NAMES.forEach(function (n) {
                    root.querySelectorAll('[name="' + n + '"], [name^="' + n + '["]').forEach(function (el) {
                        if (el.dataset.held) { return; }
                        el.dataset.held = '1';
                        if (el.type === 'checkbox' || el.tagName === 'SELECT') {
                            // A disabled control sends nothing, which
                            // WooCommerce reads as "cleared". Block the change.
                            el.addEventListener('mousedown', function (e) { e.preventDefault(); });
                            el.addEventListener('keydown', function (e) { e.preventDefault(); });
                            el.style.pointerEvents = 'none';
                            el.style.opacity = '0.6';
                        } else {
                            el.readOnly = true;
                        }
                    });
                });
            }
            hold(document);
            // The variations panel arrives by ajax, after this script has run.
            new MutationObserver(function () { hold(document); })
                .observe(document.body, { childList: true, subtree: true });
        })();
        </script>
        <?php
    }
}
