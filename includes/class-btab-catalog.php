<?php
/**
 * How btab products behave on the shop's own pages.
 *
 * NO PLUGIN CSS OR JS IS EVER LOADED ON A BUYER PAGE (PLAN.md G6). Everything
 * here is server-rendered inside WooCommerce's own markup and classes, so the
 * shop's HTML carries no /wp-content/plugins/btab-woocommerce/ asset for a
 * buyer to notice, and no class or string says btab.
 *
 * Two cases btab's catalogue has that WooCommerce would otherwise get wrong:
 *   SOLD OUT       handled by stock_status at import, so Woo's own "Out of
 *                  stock" and its "hide out of stock" setting both work.
 *   ENQUIRY ONLY   btab says the thing cannot be bought online. Woo has no such
 *                  idea, so the product is made NOT PURCHASABLE and the Add to
 *                  cart button is replaced by a short enquiry form.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Catalog
{
    const META_ENQUIRY = '_btab_enquiry_only';

    /**
     * The form's action and nonce name. Deliberately says nothing about btab:
     * it is rendered into buyer-visible HTML. Post meta above is NOT
     * buyer-visible, so it keeps its _btab_ prefix.
     */
    const ENQUIRY_ACTION = 'product_enquiry';

    public static function boot()
    {
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'not_purchasable_when_enquiry_only'), 10, 2);
        add_filter('woocommerce_variation_is_purchasable', array(__CLASS__, 'not_purchasable_when_enquiry_only'), 10, 2);

        // Replace the add-to-cart area on both the product page and the loop.
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'maybe_enquiry_form'), 29);
        add_filter('woocommerce_loop_add_to_cart_link', array(__CLASS__, 'loop_button'), 10, 2);

        // NEUTRAL ACTION NAME, and it has to stay neutral: the value is
        // rendered into the form as <input name="action" value="...">, which a
        // buyer can read in view-source. PLAN.md G6 forbids "btab" there.
        add_action('admin_post_nopriv_' . self::ENQUIRY_ACTION, array(__CLASS__, 'handle_enquiry'));
        add_action('admin_post_' . self::ENQUIRY_ACTION, array(__CLASS__, 'handle_enquiry'));
    }

    /** @return bool true when btab says this product is enquiry-only. */
    public static function is_enquiry_only($product)
    {
        if (!$product instanceof WC_Product) {
            return false;
        }
        $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        return get_post_meta($id, self::META_ENQUIRY, true) === 'yes';
    }

    public static function not_purchasable_when_enquiry_only($purchasable, $product)
    {
        return self::is_enquiry_only($product) ? false : $purchasable;
    }

    /**
     * On a listing, an enquiry-only product gets a link to its page instead of
     * an Add to cart button — the form belongs on the product page, where there
     * is room for it.
     */
    public static function loop_button($html, $product)
    {
        if (!self::is_enquiry_only($product)) {
            return $html;
        }
        return sprintf(
            '<a href="%s" class="button product_type_simple">%s</a>',
            esc_url($product->get_permalink()),
            esc_html__('Enquire', 'btab-woocommerce')
        );
    }

    /** The form, in Woo's own classes, with no plugin assets. */
    public static function maybe_enquiry_form()
    {
        global $product;
        if (!self::is_enquiry_only($product)) {
            return;
        }
        $sent = isset($_GET['enquiry']) ? sanitize_text_field(wp_unslash($_GET['enquiry'])) : '';
        if ($sent === 'sent') {
            wc_print_notice(__('Thank you — we will be in touch about this item.', 'btab-woocommerce'), 'success');
            return;
        }
        if ($sent === 'failed') {
            wc_print_notice(__('Sorry, that did not send. Please try again.', 'btab-woocommerce'), 'error');
        }
        ?>
        <form class="cart" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <p><?php esc_html_e('This item is sold by enquiry. Tell us what you need and we will come back to you with a price.', 'btab-woocommerce'); ?></p>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ENQUIRY_ACTION); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
            <?php wp_nonce_field(self::ENQUIRY_ACTION); ?>
            <p class="form-row form-row-wide">
                <label for="enquiry-name"><?php esc_html_e('Your name', 'btab-woocommerce'); ?></label>
                <input type="text" id="enquiry-name" name="name" required class="input-text">
            </p>
            <p class="form-row form-row-wide">
                <label for="enquiry-email"><?php esc_html_e('Your email', 'btab-woocommerce'); ?></label>
                <input type="email" id="enquiry-email" name="email" required class="input-text">
            </p>
            <p class="form-row form-row-wide">
                <label for="enquiry-message"><?php esc_html_e('What do you need?', 'btab-woocommerce'); ?></label>
                <textarea id="enquiry-message" name="message" rows="4" class="input-text"></textarea>
            </p>
            <button type="submit" class="single_add_to_cart_button button alt"><?php esc_html_e('Send enquiry', 'btab-woocommerce'); ?></button>
        </form>
        <?php
    }

    /**
     * Hand the enquiry to btab: a one-line cart for the product, then
     * POST /carts/{id}/enquiry. The buyer is sent back to the product page.
     */
    public static function handle_enquiry()
    {
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $back = $product_id ? get_permalink($product_id) : home_url('/');
        if (!$product_id || !wp_verify_nonce(isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '', self::ENQUIRY_ACTION)) {
            wp_safe_redirect(add_query_arg('enquiry', 'failed', $back));
            exit;
        }

        $btab_id = (int) get_post_meta($product_id, BTAB_WC_META_PRODUCT_ID, true);
        $client = btab_wc_client();
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (!$btab_id || !$client || !is_email($email)) {
            wp_safe_redirect(add_query_arg('enquiry', 'failed', $back));
            exit;
        }

        try {
            $cart = $client->create_cart(array(array('product_id' => $btab_id, 'quantity' => 1)));
            $cart_id = isset($cart['cart']['id']) ? $cart['cart']['id'] : null;
            if (!$cart_id) {
                throw new Btab_Api_Exception('No cart id came back.');
            }
            $client->enquiry($cart_id, array(
                'email' => $email,
                'name' => $name,
                'message' => $message,
            ));
        } catch (Btab_Api_Exception $e) {
            // The buyer is told it failed; the detail goes to the shop's log,
            // never to the page, because it may name btab.
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('Enquiry failed: ' . $e->getMessage(), array('source' => 'btab'));
            }
            wp_safe_redirect(add_query_arg('enquiry', 'failed', $back));
            exit;
        }
        wp_safe_redirect(add_query_arg('enquiry', 'sent', $back));
        exit;
    }
}
