<?php
/**
 * Import: btab's catalogue becomes real WooCommerce products.
 *
 * WHY REAL PRODUCTS. Woo's sort-by-price, price and stock filters, Product
 * Collection, sitemaps, search and JSON-LD all read STORED values
 * (wc_product_meta_lookup, _price meta, the stock_status lookup), never a
 * filter. A shell product with a live price filter shows one price and sorts by
 * another, so everything here goes through Woo's own CRUD and is saved.
 *
 * WHAT A BUYER MUST NEVER SEE (Steve's rule 1, PLAN.md G5/G6): btab, anywhere.
 * So photos are COPIED into the Media Library and served from the shop's own
 * /wp-content/uploads/, never hotlinked from images-direct.btab.app; and
 * nothing written into a visible field mentions btab — `seller_name` from the
 * API is "BTAB ECOMMERCE SERVICES PTY LTD" and is deliberately not imported.
 *
 * Field names are from a real response, not inferred: see docs/api-field-notes.md.
 * The price is `retail_price_cents` (there is no `price_cents`), and the product
 * LIST carries no variants — `options`/`variants`/`images` need
 * GET /products/{id}, so a variant-aware import costs one request per product.
 *
 * @package btab-woocommerce
 */

defined('ABSPATH') || exit;

class Btab_WC_Importer
{
    /** Attribute slugs are global in Woo; this prefix keeps btab's out of a shop's own. */
    const ATTR_PREFIX = 'btabopt-';

    /** @var Btab_Client */
    private $client;

    /** @var array counters for the report the owner sees */
    private $stats = array('created' => 0, 'updated' => 0, 'images' => 0, 'skipped' => 0, 'failed' => 0);

    /** @var string[] human-readable problems, shown to the owner */
    private $problems = array();

    public function __construct(Btab_Client $client)
    {
        $this->client = $client;
    }

    public function stats()
    {
        return $this->stats;
    }

    public function problems()
    {
        return $this->problems;
    }

    /**
     * Pull every product and write it into WooCommerce.
     *
     * One page at a time, and one product at a time inside that, so a single
     * bad product cannot lose the whole run — it is counted and named instead.
     *
     * @param int $limit page size
     * @return array stats
     */
    public function run($limit = 24)
    {
        $page = 1;
        $seen = array();

        do {
            try {
                $res = $this->client->products(array('limit' => (int) $limit, 'page' => $page));
            } catch (Btab_Api_Exception $e) {
                $this->problems[] = 'Could not read the catalogue: ' . $e->getMessage();
                break;
            }

            $products = isset($res['products']) && is_array($res['products']) ? $res['products'] : array();
            foreach ($products as $summary) {
                if (empty($summary['id'])) {
                    $this->stats['skipped']++;
                    continue;
                }
                $seen[] = (int) $summary['id'];
                try {
                    $this->import_one((int) $summary['id'], $summary);
                } catch (Exception $e) {
                    $this->stats['failed']++;
                    $this->problems[] = 'Product ' . (int) $summary['id'] . ': ' . $e->getMessage();
                }
            }

            $more = count($products) >= (int) $limit;
            $pagination = isset($res['pagination']) && is_array($res['pagination']) ? $res['pagination'] : array();
            if (isset($pagination['has_more'])) {
                $more = (bool) $pagination['has_more'];
            }
            $page++;
            // A guard, not a limit: a pagination answer that never says "done"
            // must not spin forever on a shop's cron.
            if ($page > 200) {
                $this->problems[] = 'Stopped after 200 pages.';
                break;
            }
        } while ($more);

        if ($seen && !$this->problems) {
            $this->hide_products_btab_no_longer_lists($seen);
        }
        return $this->stats;
    }

    /**
     * One product. The detail call is always made: the list has no variants,
     * no options and no image list, and a variable product needs all three.
     *
     * @param int   $btab_id
     * @param array $summary the list row, used only as a fallback
     */
    public function import_one($btab_id, array $summary = array())
    {
        // The guard now hooks EVERY CRUD save, so the sync has to say "this
        // one is mine". Raised for the whole import of this product, its
        // variations included, and lowered in a finally so a throw cannot
        // leave the guard switched off for the rest of the request.
        Btab_WC_Locked_Fields::$syncing = true;
        try {
            return $this->import_one_inner($btab_id, $summary);
        } finally {
            Btab_WC_Locked_Fields::$syncing = false;
        }
    }

    /** The real work. @see import_one() */
    private function import_one_inner($btab_id, array $summary = array())
    {
        $detail = $this->client->product($btab_id);
        $p = isset($detail['product']) && is_array($detail['product']) ? $detail['product'] : $detail;
        if (empty($p['id'])) {
            $p = $summary;
        }
        if (empty($p['id'])) {
            throw new Exception('btab returned no product.');
        }

        $existing_id = self::find_by_btab_id($btab_id);
        $has_variants = !empty($p['has_variants']) && !empty($p['variants']);

        if ($has_variants) {
            $product = $existing_id ? new WC_Product_Variable($existing_id) : new WC_Product_Variable();
        } else {
            $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
            // A product that gained or lost variants changes type; Woo needs a
            // fresh object of the right class rather than a converted one.
            if ($existing_id && !($product instanceof WC_Product_Simple)) {
                $product = new WC_Product_Simple($existing_id);
            }
        }

        $product->set_name($this->text($p, 'name'));
        $product->set_slug($this->text($p, 'slug'));
        $product->set_description($this->html($p, 'description'));
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');

        // Price: retail_price_cents, in cents. Woo stores a decimal string.
        $cents = isset($p['retail_price_cents']) ? (int) $p['retail_price_cents'] : null;
        if ($cents === null && isset($p['price_range']['min_cents'])) {
            $cents = (int) $p['price_range']['min_cents'];
        }
        if (!$has_variants) {
            if ($cents !== null) {
                $product->set_regular_price($this->money($cents));
            }
            // Sale price is left alone: btab prices every line at checkout, and
            // a sale shown here that checkout does not honour is worse than none.
        }

        // Stock. btab is the only authority; the shop never decrements.
        $in_stock = !isset($p['in_stock']) || !empty($p['in_stock']);
        $product->set_manage_stock(false);
        $product->set_stock_status($in_stock ? 'instock' : 'outofstock');

        // Enquiry-only: btab says it cannot be bought online. Both flags have
        // to be read - the boardroom table is enquiry_only AND
        // available_for_sale, so availability alone says nothing.
        $enquiry_only = !empty($p['enquiry_only']);

        $product->update_meta_data(BTAB_WC_META_PRODUCT_ID, (int) $btab_id);
        $product->update_meta_data(BTAB_WC_META_SYNCED_AT, time());
        $product->update_meta_data('_btab_enquiry_only', $enquiry_only ? 'yes' : 'no');
        // btab's own answers, kept beside the product so wp-admin can put them
        // back when someone edits a field that is not the shop's to edit
        // (class-btab-locked-fields.php). Meta is not buyer-visible, so these
        // keep the _btab_ prefix.
        // THESE ARE THE VALUES THIS IMPORT ACTUALLY USED, not a second reading
        // of the payload. The first version read `retail_price_cents` again and
        // fell back to 0, and read `in_stock` again and fell back to "out of
        // stock" - both the opposite of the lines above. A VARIABLE PRODUCT HAS
        // NO `retail_price_cents` (it has `price_range.min_cents`), so the
        // guard would have stored "0.00", and the first admin save would have
        // set that product to $0.00 and out of stock: the guard causing the
        // exact damage it exists to prevent.
        if ($cents !== null) {
            $product->update_meta_data('_btab_price', $this->money($cents));
        }
        $product->update_meta_data('_btab_stock_status', $in_stock ? 'instock' : 'outofstock');

        // Attributes, before the first save, so variations can attach to them.
        if ($has_variants) {
            $product->set_attributes($this->attributes_from_options($p));
        }

        $category_ids = $this->category_ids($p);
        if ($category_ids) {
            $product->set_category_ids($category_ids);
        }

        $product_id = $product->save();
        if (!$product_id) {
            throw new Exception('WooCommerce refused to save the product.');
        }

        $this->attach_images($product_id, $p);

        if ($has_variants) {
            $this->sync_variations($product_id, $p, $cents);
            // Re-read and save so Woo recalculates the price range it shows.
            $variable = new WC_Product_Variable($product_id);
            $variable->set_stock_status($in_stock ? 'instock' : 'outofstock');
            $variable->save();
            WC_Product_Variable::sync($product_id);
        }

        if ($existing_id) {
            $this->stats['updated']++;
        } else {
            $this->stats['created']++;
        }
        return $product_id;
    }

    // ── options and variations ───────────────────────────────────────────────

    /**
     * btab `options` → Woo product attributes, used for variation.
     * Stored as custom (non-taxonomy) attributes: a shop's own global
     * attributes are not btab's to add to.
     */
    private function attributes_from_options(array $p)
    {
        $out = array();
        $options = isset($p['options']) && is_array($p['options']) ? $p['options'] : array();
        foreach ($options as $i => $opt) {
            $name = isset($opt['name']) ? (string) $opt['name'] : '';
            $values = isset($opt['values']) && is_array($opt['values']) ? $opt['values'] : array();
            if ($name === '' || !$values) {
                continue;
            }
            $attr = new WC_Product_Attribute();
            $attr->set_id(0);
            $attr->set_name($name);
            $attr->set_options(array_values(array_map('strval', $values)));
            $attr->set_position($i);
            $attr->set_visible(true);
            $attr->set_variation(true);
            $out[] = $attr;
        }
        return $out;
    }

    /**
     * One Woo variation per btab variant, matched on the btab variant id so a
     * resync updates rather than duplicates. A variation btab no longer lists
     * is deleted, because a buyer must not be able to pick it.
     */
    private function sync_variations($product_id, array $p, $parent_cents)
    {
        $parent_btab_id = (int) get_post_meta($product_id, BTAB_WC_META_PRODUCT_ID, true);
        $variants = isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : array();
        $existing = array();
        foreach ((new WC_Product_Variable($product_id))->get_children() as $child_id) {
            $vid = get_post_meta($child_id, '_btab_variant_id', true);
            if ($vid !== '') {
                $existing[(string) $vid] = $child_id;
            }
        }

        $kept = array();
        foreach ($variants as $v) {
            if (empty($v['id'])) {
                continue;
            }
            $vid = (string) $v['id'];
            $kept[$vid] = true;
            $variation = isset($existing[$vid]) ? new WC_Product_Variation($existing[$vid]) : new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_status('publish');

            $attrs = array();
            $selected = isset($v['selected_options']) && is_array($v['selected_options']) ? $v['selected_options'] : array();
            foreach ($selected as $sel) {
                if (!isset($sel['name'], $sel['value'])) {
                    continue;
                }
                // Woo keys a custom attribute by its sanitised name.
                $attrs[sanitize_title((string) $sel['name'])] = (string) $sel['value'];
            }
            $variation->set_attributes($attrs);

            // btab sends no per-variant price today, so a variant inherits the
            // product's. The price is a display copy either way; checkout prices it.
            if ($parent_cents !== null) {
                $variation->set_regular_price($this->money($parent_cents));
            }
            $available = !isset($v['available_for_sale']) || !empty($v['available_for_sale']);
            $variation->set_manage_stock(false);
            $variation->set_stock_status($available ? 'instock' : 'outofstock');
            $variation->update_meta_data('_btab_variant_id', $vid);
            // The guard reads these ON THE VARIATION. A variation is saved
            // through its own CRUD path and never sees the parent's meta, so
            // without them every variation was unguarded.
            $variation->update_meta_data(BTAB_WC_META_PRODUCT_ID, $parent_btab_id);
            if ($parent_cents !== null) {
                $variation->update_meta_data('_btab_price', $this->money($parent_cents));
            }
            $variation->update_meta_data('_btab_stock_status', $available ? 'instock' : 'outofstock');
            $variation->save();
        }

        foreach ($existing as $vid => $child_id) {
            if (!isset($kept[$vid])) {
                // Force-delete: a trashed variation still resolves for a buyer.
                wp_delete_post($child_id, true);
            }
        }
    }

    // ── categories ───────────────────────────────────────────────────────────

    /** btab's category becomes a product_cat term, created once, by slug. */
    private function category_ids(array $p)
    {
        $slug = isset($p['category_slug']) ? sanitize_title((string) $p['category_slug']) : '';
        $label = isset($p['category_label']) ? (string) $p['category_label'] : '';
        if ($slug === '' && $label === '') {
            return array();
        }
        if ($slug === '') {
            $slug = sanitize_title($label);
        }
        if ($label === '') {
            $label = ucwords(str_replace('-', ' ', $slug));
        }
        $term = get_term_by('slug', $slug, 'product_cat');
        if (!$term) {
            $made = wp_insert_term($label, 'product_cat', array('slug' => $slug));
            if (is_wp_error($made)) {
                return array();
            }
            return array((int) $made['term_id']);
        }
        return array((int) $term->term_id);
    }

    // ── images ───────────────────────────────────────────────────────────────

    /**
     * COPY every photo into the Media Library. Never a hotlink: btab serves
     * images from images-direct.btab.app, so linking them puts btab in every
     * <img src> on the page, which Steve's rule 1 forbids outright.
     *
     * Each photo is keyed by its btab URL in attachment meta, so a resync
     * recognises one it already has and does not download it again.
     */
    private function attach_images($product_id, array $p)
    {
        $urls = array();
        if (!empty($p['images']) && is_array($p['images'])) {
            $rows = $p['images'];
            usort($rows, function ($a, $b) {
                return ((int) (isset($a['position']) ? $a['position'] : 0)) - ((int) (isset($b['position']) ? $b['position'] : 0));
            });
            foreach ($rows as $row) {
                if (!empty($row['url'])) {
                    $urls[] = array('url' => (string) $row['url'], 'alt' => isset($row['alt']) ? (string) $row['alt'] : '');
                }
            }
        }
        if (!$urls && !empty($p['image_url'])) {
            $urls[] = array('url' => (string) $p['image_url'], 'alt' => $this->text($p, 'name'));
        }
        if (!$urls) {
            return;
        }

        $ids = array();
        foreach ($urls as $img) {
            $attachment_id = $this->sideload($img['url'], $product_id, $img['alt'], $this->text($p, 'name'));
            if ($attachment_id) {
                $ids[] = $attachment_id;
            }
        }
        if (!$ids) {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        $product->set_image_id($ids[0]);
        if (count($ids) > 1) {
            $product->set_gallery_image_ids(array_slice($ids, 1));
        }
        $product->save();
    }

    /**
     * Download one photo into the Media Library, or return the one already
     * imported from the same btab URL.
     *
     * @return int|null attachment id
     */
    private function sideload($url, $product_id, $alt, $product_name)
    {
        $known = $this->find_attachment_by_source($url);
        if ($known) {
            return $known;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            $this->problems[] = 'Could not fetch a photo: ' . $tmp->get_error_message();
            return null;
        }

        // The file name a buyer can see must say nothing about btab, and btab's
        // image URLs carry a token and an s3 path. Name it after the product.
        $ext = $this->extension_for($url, $tmp);
        $base = sanitize_file_name(sanitize_title($product_name !== '' ? $product_name : 'product'));
        $file = array(
            'name' => $base . '-' . substr(md5($url), 0, 8) . '.' . $ext,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file, 0, null, array(
            'post_title' => $product_name,
        ));
        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            $this->problems[] = 'Could not save a photo: ' . $attachment_id->get_error_message();
            return null;
        }

        // Alt text is buyer-visible, so it is the product name, never btab's string.
        $alt_text = $alt !== '' ? $alt : $product_name;
        if (stripos($alt_text, 'btab') !== false) {
            $alt_text = $product_name;
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        update_post_meta($attachment_id, '_btab_source_url', $url);
        $this->stats['images']++;
        return (int) $attachment_id;
    }

    /** The file extension, from the URL or from what was actually downloaded. */
    private function extension_for($url, $tmp_file)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');
        if (in_array($ext, $allowed, true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }
        // btab's URLs end in /format:webp/plain/s3://… so the path often has no
        // usable extension; ask the file itself.
        $type = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp_file) : '';
        $map = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        );
        return isset($map[$type]) ? $map[$type] : 'jpg';
    }

    private function find_attachment_by_source($url)
    {
        $found = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_btab_source_url',
            'meta_value' => $url,
        ));
        return $found ? (int) $found[0] : null;
    }

    // ── housekeeping ─────────────────────────────────────────────────────────

    /**
     * A product btab no longer lists is HIDDEN, not deleted: a shop's order
     * history still points at it, and deleting it would break those orders.
     */
    private function hide_products_btab_no_longer_lists(array $seen_btab_ids)
    {
        $ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => BTAB_WC_META_PRODUCT_ID,
            'meta_compare' => 'EXISTS',
        ));
        foreach ($ids as $id) {
            $btab_id = (int) get_post_meta($id, BTAB_WC_META_PRODUCT_ID, true);
            if ($btab_id && !in_array($btab_id, $seen_btab_ids, true)) {
                $product = wc_get_product($id);
                if ($product) {
                    $product->set_status('draft');
                    $product->set_catalog_visibility('hidden');
                    $product->save();
                }
            }
        }
    }

    /** @return int|null the Woo product id for a btab product id. */
    public static function find_by_btab_id($btab_id)
    {
        $found = get_posts(array(
            'post_type' => array('product'),
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => BTAB_WC_META_PRODUCT_ID,
            'meta_value' => (int) $btab_id,
        ));
        return $found ? (int) $found[0] : null;
    }

    // ── small helpers ────────────────────────────────────────────────────────

    /** Cents → the decimal string Woo stores. */
    private function money($cents)
    {
        return number_format(((int) $cents) / 100, wc_get_price_decimals(), '.', '');
    }

    private function text(array $p, $key)
    {
        return isset($p[$key]) && is_string($p[$key]) ? sanitize_text_field($p[$key]) : '';
    }

    /** Description is HTML from btab; keep the markup a post may carry. */
    private function html(array $p, $key)
    {
        if (empty($p[$key]) || !is_string($p[$key])) {
            return '';
        }
        return wp_kses_post($p[$key]);
    }
}
