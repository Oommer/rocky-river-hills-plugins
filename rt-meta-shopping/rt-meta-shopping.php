<?php
/**
 * Plugin Name: RT Meta Shopping Feed
 * Description: Auto-generate a product feed for Meta Commerce Manager (Facebook & Instagram Shops). Supports product catalogs for organic Shopping and paid ads.
 * Version: 1.1.1
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-meta-shopping
 */

if (!defined('ABSPATH')) exit;

define('RTMS_VERSION', '1.1.0');
define('RTMS_PATH', plugin_dir_path(__FILE__));
define('RTMS_URL', plugin_dir_url(__FILE__));

class RT_Meta_Shopping_Feed {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_feed_endpoint']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        // AJAX
        add_action('wp_ajax_rtms_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtms_regenerate_feed', [$this, 'ajax_regenerate_feed']);
        add_action('wp_ajax_rtms_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtms_preview_feed', [$this, 'ajax_preview_feed']);
        add_action('wp_ajax_rtms_diagnose_feed', [$this, 'ajax_diagnose_feed']);

        // Cron
        add_action('rtms_regenerate_feed_cron', [$this, 'generate_feed_file']);

        // Auto-regen on product changes
        add_action('woocommerce_update_product', [$this, 'schedule_regeneration']);
        add_action('woocommerce_new_product', [$this, 'schedule_regeneration']);
        add_action('before_delete_post', [$this, 'schedule_regeneration']);

        // Meta Commerce checkout URL handler
        add_action('template_redirect', [$this, 'handle_meta_checkout']);
    }

    /*--------------------------------------------------------------
    # Activation / Deactivation
    --------------------------------------------------------------*/

    public function activate() {
        $defaults = [
            'enabled' => 1,
            'feed_filename' => 'meta-shopping-feed.xml',
            'auto_regenerate' => 'daily',
            'store_name' => get_bloginfo('name'),
            'store_url' => home_url(),
            'description_source' => 'short',
            'brand' => get_bloginfo('name'),
            'condition' => 'new',
            'default_google_category' => 'Home & Garden > Decor > Wall Art',
            'google_category_id' => '500044',
            'exclude_out_of_stock' => 0,
            'exclude_categories' => '',
            'include_variations' => 0,
            'min_price' => '',
            'max_products' => 0,
            'identifier_exists' => 'no',
            'utm_source' => 'facebook',
            'utm_medium' => 'shopping',
            'utm_campaign' => 'meta_commerce',
            'custom_label_0' => 'category',
            'custom_label_1' => 'price_range',
            'custom_label_2' => '',
            'custom_label_3' => '',
            'custom_label_4' => '',
            'last_generated' => '',
            'product_count' => 0,
        ];

        if (!get_option('rtms_settings')) {
            update_option('rtms_settings', $defaults);
        }

        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-meta-shopping/';
        if (!file_exists($feed_dir)) {
            wp_mkdir_p($feed_dir);
            file_put_contents($feed_dir . 'index.php', '<?php // Silence is golden');
        }

        if (!wp_next_scheduled('rtms_regenerate_feed_cron')) {
            wp_schedule_event(time(), 'daily', 'rtms_regenerate_feed_cron');
        }

        $this->generate_feed_file();
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rtms_regenerate_feed_cron');
        flush_rewrite_rules();
    }

    /*--------------------------------------------------------------
    # Feed Endpoint
    --------------------------------------------------------------*/

    public function register_feed_endpoint() {
        add_feed('meta-shopping', [$this, 'serve_feed']);
        add_rewrite_rule('^meta-shopping-feed\.xml$', 'index.php?feed=meta-shopping', 'top');
    }

    public function serve_feed() {
        $settings = get_option('rtms_settings', []);
        if (empty($settings['enabled'])) { status_header(404); exit; }

        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-meta-shopping/' . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');

        if (!file_exists($feed_file) || (time() - filemtime($feed_file)) > 86400) {
            $this->generate_feed_file();
        }

        if (file_exists($feed_file)) {
            $this->track_fetch();
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Robots-Tag: noindex');
            readfile($feed_file);
        } else {
            status_header(500);
            echo '<?xml version="1.0" encoding="UTF-8"?><e>Feed generation failed</e>';
        }
        exit;
    }

    /*--------------------------------------------------------------
    # Feed Generation
    --------------------------------------------------------------*/

    public function generate_feed_file() {
        $settings = get_option('rtms_settings', []);
        if (empty($settings['enabled'])) return false;

        $xml = $this->build_feed_xml($settings);
        if (!$xml) return false;

        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-meta-shopping/';
        $feed_file = $feed_dir . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');

        wp_mkdir_p($feed_dir);
        $written = file_put_contents($feed_file, $xml);

        if ($written) {
            $settings = get_option('rtms_settings', []);
            $settings['last_generated'] = current_time('mysql');
            update_option('rtms_settings', $settings);
        }

        return (bool) $written;
    }

    private function build_feed_xml($settings) {
        $products = $this->get_products($settings);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . $this->esc($settings['store_name'] ?? get_bloginfo('name')) . '</title>' . "\n";
        $xml .= '<link>' . $this->esc($settings['store_url'] ?? home_url()) . '</link>' . "\n";
        $xml .= '<description>Meta Commerce Product Feed for ' . $this->esc($settings['store_name'] ?? '') . '</description>' . "\n";

        $count = 0;
        foreach ($products as $product) {
            $item_xml = $this->build_product_item($product, $settings);
            if ($item_xml) {
                $xml .= $item_xml;
                $count++;
            }
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        $settings['product_count'] = $count;
        update_option('rtms_settings', $settings);

        return $xml;
    }

    private function build_product_item($product, $settings) {
        if (!$product) return '';
        $status = get_post_status($product->get_id());
        if ($status !== 'publish') return '';

        $id = $product->get_id();
        $title = $product->get_name();
        $link = $product->get_permalink();

        // UTM parameters
        $utm = [];
        if (!empty($settings['utm_source'])) $utm['utm_source'] = $settings['utm_source'];
        if (!empty($settings['utm_medium'])) $utm['utm_medium'] = $settings['utm_medium'];
        if (!empty($settings['utm_campaign'])) $utm['utm_campaign'] = $settings['utm_campaign'];
        if (!empty($utm)) $link = add_query_arg($utm, $link);

        // Description
        $desc = '';
        if (($settings['description_source'] ?? 'short') === 'short') {
            $desc = $product->get_short_description();
        }
        if (empty($desc)) $desc = $product->get_description();
        $desc = wp_strip_all_tags($desc);
        $desc = mb_substr($desc, 0, 5000);
        if (empty($desc)) $desc = $title;

        // Image
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        if (empty($image_url)) return '';

        // Gallery images
        $gallery = $product->get_gallery_image_ids();
        $additional = [];
        foreach (array_slice($gallery, 0, 9) as $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) $additional[] = $url;
        }

        // Price
        $price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        if (empty($price) && $product->is_type('variable')) {
            $price = $product->get_variation_regular_price('min');
            $sale_price = $product->get_variation_sale_price('min');
        }
        if (empty($price)) return '';

        $currency = get_woocommerce_currency();
        $price_str = number_format((float)$price, 2, '.', '') . ' ' . $currency;
        $sale_str = '';
        if ($sale_price && (float)$sale_price < (float)$price) {
            $sale_str = number_format((float)$sale_price, 2, '.', '') . ' ' . $currency;
        }

        // Sale dates
        $sale_start = '';
        $sale_end = '';
        if ($sale_str) {
            $start = $product->get_date_on_sale_from();
            $end = $product->get_date_on_sale_to();
            if ($start) $sale_start = $start->format('Y-m-d\TH:i:sO');
            if ($end) $sale_end = $end->format('Y-m-d\TH:i:sO');
        }

        // Availability — Meta uses: in stock, out of stock, available for order
        $stock_status = $product->get_stock_status();
        $avail_map = [
            'instock' => 'in stock',
            'outofstock' => 'out of stock',
            'onbackorder' => 'available for order',
        ];
        $availability = $avail_map[$stock_status] ?? 'in stock';

        // Quantity
        $quantity = $product->get_stock_quantity();
        $qty_val = ($quantity !== null && $quantity !== '') ? intval($quantity) : ($stock_status === 'instock' ? 999 : 0);

        // Brand & condition
        $brand = $settings['brand'] ?? get_bloginfo('name');
        $condition = $settings['condition'] ?? 'new';

        // Google category (Meta accepts Google's taxonomy)
        $google_category = $this->get_google_category($product, $settings);
        $product_type = $this->get_product_type($product);

        // MPN / SKU
        $mpn = $product->get_sku();

        // Custom labels
        $labels = [];
        for ($i = 0; $i <= 4; $i++) {
            $cfg = $settings["custom_label_{$i}"] ?? '';
            if (empty($cfg)) continue;
            $val = $this->resolve_label($product, $cfg);
            if ($val) $labels[$i] = $val;
        }

        // Build item
        $xml = "<item>\n";
        $xml .= "  <g:id>{$id}</g:id>\n";
        $xml .= "  <g:title>" . $this->esc($title) . "</g:title>\n";
        $xml .= "  <g:description>" . $this->esc($desc) . "</g:description>\n";
        $xml .= "  <g:link>" . $this->esc($link) . "</g:link>\n";
        $xml .= "  <g:image_link>" . $this->esc($image_url) . "</g:image_link>\n";

        foreach ($additional as $img) {
            $xml .= "  <g:additional_image_link>" . $this->esc($img) . "</g:additional_image_link>\n";
        }

        $xml .= "  <g:price>{$price_str}</g:price>\n";
        if ($sale_str) {
            $xml .= "  <g:sale_price>{$sale_str}</g:sale_price>\n";
            if ($sale_start && $sale_end) {
                $xml .= "  <g:sale_price_effective_date>{$sale_start}/{$sale_end}</g:sale_price_effective_date>\n";
            }
        }

        $xml .= "  <g:availability>{$availability}</g:availability>\n";
        $xml .= "  <g:quantity_to_sell_on_facebook>{$qty_val}</g:quantity_to_sell_on_facebook>\n";
        $xml .= "  <g:brand>" . $this->esc($brand) . "</g:brand>\n";
        $xml .= "  <g:condition>{$condition}</g:condition>\n";

        if ($google_category) {
            $xml .= "  <g:google_product_category>" . $this->esc($google_category) . "</g:google_product_category>\n";
        }
        if ($product_type) {
            $xml .= "  <g:product_type>" . $this->esc($product_type) . "</g:product_type>\n";
        }

        if ($mpn) {
            $xml .= "  <g:mpn>" . $this->esc($mpn) . "</g:mpn>\n";
        }

        $id_exists = $settings['identifier_exists'] ?? 'no';
        if ($id_exists === 'no') {
            $xml .= "  <g:identifier_exists>no</g:identifier_exists>\n";
        }

        // Shipping weight
        $weight = $product->get_weight();
        if ($weight) {
            $wunit = get_option('woocommerce_weight_unit', 'lbs');
            $wmap = ['lbs' => 'lb', 'kg' => 'kg', 'g' => 'g', 'oz' => 'oz'];
            $xml .= "  <g:shipping_weight>{$weight} " . ($wmap[$wunit] ?? 'lb') . "</g:shipping_weight>\n";
        }

        // Custom labels
        foreach ($labels as $idx => $val) {
            $xml .= "  <g:custom_label_{$idx}>" . $this->esc($val) . "</g:custom_label_{$idx}>\n";
        }

        $xml .= "</item>\n";
        return $xml;
    }

    /*--------------------------------------------------------------
    # Product Querying
    --------------------------------------------------------------*/

    private function get_products($settings) {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $max = intval($settings['max_products'] ?? 0);
        if ($max > 0) $args['posts_per_page'] = $max;

        if (!empty($settings['exclude_out_of_stock'])) {
            $args['meta_query'][] = [
                'key' => '_stock_status',
                'value' => 'outofstock',
                'compare' => '!=',
            ];
        }

        if (!empty($settings['exclude_categories'])) {
            $cats = array_map('intval', explode(',', $settings['exclude_categories']));
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $cats,
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($settings['min_price'])) {
            $args['meta_query'][] = [
                'key' => '_price',
                'value' => (float) $settings['min_price'],
                'compare' => '>=',
                'type' => 'DECIMAL',
            ];
        }

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            if ($product->is_type('variable') && !empty($settings['include_variations'])) {
                foreach ($product->get_available_variations() as $var) {
                    $v = wc_get_product($var['variation_id']);
                    if ($v) $products[] = $v;
                }
            } else {
                $products[] = $product;
            }
        }

        return $products;
    }

    /*--------------------------------------------------------------
    # Helpers
    --------------------------------------------------------------*/

    private function get_google_category($product, $settings) {
        $override = get_post_meta($product->get_id(), '_rtms_google_category', true);
        if ($override) return $override;
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $mapped = get_term_meta($term->term_id, '_rtms_google_category', true);
                if ($mapped) return $mapped;
            }
        }
        return $settings['default_google_category'] ?? '';
    }

    private function get_product_type($product) {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) return '';
        $cats = [];
        foreach ($terms as $term) {
            $path = [];
            $current = $term;
            while ($current) {
                array_unshift($path, $current->name);
                $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
            }
            $cats[] = implode(' > ', $path);
        }
        usort($cats, function($a, $b) { return strlen($b) - strlen($a); });
        return $cats[0] ?? '';
    }

    private function resolve_label($product, $config) {
        if ($config === 'category') {
            $terms = get_the_terms($product->get_id(), 'product_cat');
            return ($terms && !is_wp_error($terms)) ? $terms[0]->name : '';
        }
        if (strpos($config, 'tag:') === 0) {
            $tag = substr($config, 4);
            $terms = get_the_terms($product->get_id(), 'product_tag');
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    if (strtolower($t->name) === strtolower($tag)) return $t->name;
                }
            }
            return '';
        }
        if (strpos($config, 'meta:') === 0) {
            return get_post_meta($product->get_id(), substr($config, 5), true);
        }
        if ($config === 'price_range') {
            $p = (float) $product->get_price();
            if ($p < 15) return 'Under $15';
            if ($p < 30) return '$15-$30';
            if ($p < 50) return '$30-$50';
            return 'Over $50';
        }
        if ($config === 'on_sale') {
            return $product->is_on_sale() ? 'On Sale' : 'Full Price';
        }
        return $config;
    }

    private function esc($str) {
        return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function schedule_regeneration() {
        $ts = wp_next_scheduled('rtms_regenerate_feed_cron');
        if (!$ts || $ts > time() + 300) {
            wp_schedule_single_event(time() + 300, 'rtms_regenerate_feed_cron');
        }
    }

    /*--------------------------------------------------------------
    # Meta Commerce Checkout URL Handler
    # Parses ?products=ID:QTY,ID:QTY&coupon=CODE
    # Adds products to WooCommerce cart and redirects to checkout
    --------------------------------------------------------------*/

    public function handle_meta_checkout() {
        if (!isset($_GET['meta_checkout'])) return;
        if (!class_exists('WooCommerce')) return;

        // Clear existing cart for a clean Meta checkout experience
        WC()->cart->empty_cart();

        // Parse products — format: "ID:QTY,ID:QTY" or just "ID"
        $products_raw = sanitize_text_field($_GET['products'] ?? '');
        if (!empty($products_raw)) {
            $items = explode(',', $products_raw);
            foreach ($items as $item) {
                $parts = explode(':', trim($item));
                $product_id = intval($parts[0]);
                $quantity = isset($parts[1]) ? intval($parts[1]) : 1;

                if ($product_id > 0 && $quantity > 0) {
                    $product = wc_get_product($product_id);
                    if ($product && $product->is_purchasable()) {
                        WC()->cart->add_to_cart($product_id, $quantity);
                    }
                }
            }
        }

        // Apply coupon if provided
        $coupon = sanitize_text_field($_GET['coupon'] ?? '');
        if (!empty($coupon)) {
            WC()->cart->apply_coupon($coupon);
        }

        // Redirect to WooCommerce checkout
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function track_fetch() {
        $count = (int) get_transient('rtms_fetch_count');
        set_transient('rtms_fetch_count', $count + 1, 30 * DAY_IN_SECONDS);
        $today = current_time('Y-m-d');
        $daily = get_option('rtms_daily_fetches', []);
        $daily[$today] = ($daily[$today] ?? 0) + 1;
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        foreach ($daily as $d => $v) { if ($d < $cutoff) unset($daily[$d]); }
        update_option('rtms_daily_fetches', $daily);
    }

    /*--------------------------------------------------------------
    # Admin
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page('Meta Shopping', 'Meta Shopping', 'manage_options', 'rt-meta-shopping', [$this, 'admin_page'], 'dashicons-facebook-alt', 61);
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-meta-shopping') return;
        wp_enqueue_style('rtms-admin', RTMS_URL . 'admin.css', [], RTMS_VERSION);
        wp_enqueue_script('rtms-admin', RTMS_URL . 'admin.js', ['jquery'], RTMS_VERSION, true);
        wp_localize_script('rtms-admin', 'rtms', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtms_nonce'),
            'feed_url' => home_url('/feed/meta-shopping/'),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtms_settings', []);
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-meta-shopping/' . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');
        $feed_exists = file_exists($feed_file);
        $feed_size = $feed_exists ? size_format(filesize($feed_file)) : '—';
        include RTMS_PATH . 'admin-page.php';
    }

    /*--------------------------------------------------------------
    # AJAX Handlers
    --------------------------------------------------------------*/

    public function ajax_save_settings() {
        check_ajax_referer('rtms_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        if (!empty($_POST['settings'])) parse_str($_POST['settings'], $data);

        $clean = [];
        foreach ($data as $k => $v) {
            $clean[sanitize_key($k)] = is_string($v) ? sanitize_text_field($v) : $v;
        }

        $toggles = ['enabled', 'exclude_out_of_stock', 'include_variations'];
        foreach ($toggles as $k) { $clean[$k] = isset($clean[$k]) ? 1 : 0; }

        $existing = get_option('rtms_settings', []);
        $clean['last_generated'] = $existing['last_generated'] ?? '';
        $clean['product_count'] = $existing['product_count'] ?? 0;

        update_option('rtms_settings', $clean);

        wp_clear_scheduled_hook('rtms_regenerate_feed_cron');
        $interval = $clean['auto_regenerate'] ?? 'daily';
        if ($interval !== 'manual') {
            wp_schedule_event(time(), $interval, 'rtms_regenerate_feed_cron');
        }

        wp_send_json_success('Settings saved');
    }

    public function ajax_regenerate_feed() {
        check_ajax_referer('rtms_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $start = microtime(true);
        $result = $this->generate_feed_file();
        $elapsed = round(microtime(true) - $start, 2);

        if ($result) {
            $settings = get_option('rtms_settings', []);
            wp_send_json_success([
                'message' => "Feed generated in {$elapsed}s",
                'product_count' => $settings['product_count'] ?? 0,
                'last_generated' => $settings['last_generated'] ?? '',
            ]);
        } else {
            wp_send_json_error('Feed generation failed');
        }
    }

    public function ajax_get_stats() {
        check_ajax_referer('rtms_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtms_settings', []);
        $total = wp_count_posts('product')->publish;
        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-meta-shopping/' . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');

        wp_send_json_success([
            'enabled' => !empty($settings['enabled']),
            'product_count' => $settings['product_count'] ?? 0,
            'total_products' => $total,
            'last_generated' => $settings['last_generated'] ?? 'Never',
            'feed_size' => file_exists($feed_file) ? size_format(filesize($feed_file)) : '—',
            'feed_exists' => file_exists($feed_file),
            'total_fetches' => (int) get_transient('rtms_fetch_count'),
            'daily_fetches' => get_option('rtms_daily_fetches', []),
            'feed_url' => home_url('/feed/meta-shopping/'),
        ]);
    }

    public function ajax_preview_feed() {
        check_ajax_referer('rtms_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtms_settings', []);
        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-meta-shopping/' . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');

        if (!file_exists($feed_file)) { wp_send_json_error('Feed not found. Generate it first.'); return; }
        $content = file_get_contents($feed_file);
        if (strlen($content) > 50000) $content = substr($content, 0, 50000) . "\n<!-- Truncated -->";
        wp_send_json_success(['xml' => $content]);
    }

    public function ajax_diagnose_feed() {
        check_ajax_referer('rtms_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtms_settings', []);
        $r = [];
        $r[] = 'Feed enabled: ' . (!empty($settings['enabled']) ? 'YES' : 'NO');
        $r[] = 'WooCommerce active: ' . (class_exists('WooCommerce') ? 'YES' : 'NO');

        $total = wp_count_posts('product');
        $r[] = "Published products: " . ($total->publish ?? 0);

        $args = ['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids'];
        $query = new WP_Query($args);
        $r[] = "WP_Query found: {$query->found_posts} products";

        $skip = ['no_product' => 0, 'no_image' => 0, 'no_price' => 0, 'passed' => 0];
        foreach ($query->posts as $pid) {
            $p = wc_get_product($pid);
            if (!$p) { $skip['no_product']++; continue; }
            $img = $p->get_image_id() ? wp_get_attachment_url($p->get_image_id()) : '';
            if (!$img) { $skip['no_image']++; continue; }
            $price = $p->get_regular_price();
            if (empty($price) && $p->is_type('variable')) $price = $p->get_variation_regular_price('min');
            if (empty($price)) { $skip['no_price']++; continue; }
            $skip['passed']++;
        }

        $r[] = "--- Results ---";
        $r[] = "Would appear in feed: {$skip['passed']}";
        if ($skip['no_image']) $r[] = "Missing image: {$skip['no_image']}";
        if ($skip['no_price']) $r[] = "Missing price: {$skip['no_price']}";

        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-meta-shopping/';
        $feed_file = $feed_dir . ($settings['feed_filename'] ?? 'meta-shopping-feed.xml');
        $r[] = "--- Feed File ---";
        $r[] = "Directory writable: " . (is_writable($feed_dir) ? 'YES' : 'NO');
        $r[] = "File exists: " . (file_exists($feed_file) ? 'YES (' . size_format(filesize($feed_file)) . ')' : 'NO');

        wp_send_json_success(['report' => implode("\n", $r)]);
    }
}

RT_Meta_Shopping_Feed::get_instance();
