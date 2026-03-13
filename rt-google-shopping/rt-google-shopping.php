<?php
/**
 * Plugin Name: RT Google Shopping Feed
 * Description: Automatically generate a Google Merchant Center product feed from your WooCommerce products. Supports free listings and paid Shopping ads.
 * Version: 1.3.1
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-google-shopping
 */

if (!defined('ABSPATH')) exit;

define('RTGS_VERSION', '1.3.0');
define('RTGS_PATH', plugin_dir_path(__FILE__));
define('RTGS_URL', plugin_dir_url(__FILE__));

class RT_Google_Shopping_Feed {

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

        // AJAX handlers
        add_action('wp_ajax_rtgs_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtgs_regenerate_feed', [$this, 'ajax_regenerate_feed']);
        add_action('wp_ajax_rtgs_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtgs_preview_feed', [$this, 'ajax_preview_feed']);
        add_action('wp_ajax_rtgs_diagnose_feed', [$this, 'ajax_diagnose_feed']);

        // Cron for auto-regeneration
        add_action('rtgs_regenerate_feed_cron', [$this, 'generate_feed_file']);

        // Regenerate on product changes
        add_action('woocommerce_update_product', [$this, 'schedule_regeneration']);
        add_action('woocommerce_new_product', [$this, 'schedule_regeneration']);
        add_action('before_delete_post', [$this, 'schedule_regeneration']);
    }

    /*--------------------------------------------------------------
    # Activation / Deactivation
    --------------------------------------------------------------*/

    public function activate() {
        $defaults = [
            'enabled' => 1,
            'feed_filename' => 'google-shopping-feed.xml',
            'auto_regenerate' => 'daily',
            // Store info
            'store_name' => get_bloginfo('name'),
            'store_url' => home_url(),
            'description_source' => 'short', // short or full
            'brand' => get_bloginfo('name'),
            'condition' => 'new',
            'availability_in_stock' => 'in_stock',
            'availability_out_of_stock' => 'out_of_stock',
            'availability_backorder' => 'backorder',
            // Google category
            'default_google_category' => 'Home & Garden > Decor',
            'google_category_id' => '536',
            // Tax & Shipping
            'include_tax' => 0,
            'shipping_country' => 'US',
            'shipping_price' => '',
            'shipping_label' => '',
            // Filters
            'exclude_out_of_stock' => 0,
            'exclude_categories' => '',
            'include_variations' => 0,
            'min_price' => '',
            'max_products' => 0, // 0 = unlimited
            // Custom labels
            'custom_label_0' => '', // map to category, tag, or static
            'custom_label_1' => '',
            'custom_label_2' => '',
            'custom_label_3' => '',
            'custom_label_4' => '',
            // Identifier settings
            'identifier_exists' => 'no', // yes/no — most handmade/custom products = no
            'gtin_field' => '',
            'mpn_field' => '',
            // UTM tracking
            'utm_source' => 'google',
            'utm_medium' => 'shopping',
            'utm_campaign' => 'merchant_center',
            // Stats
            'last_generated' => '',
            'product_count' => 0,
        ];

        if (!get_option('rtgs_settings')) {
            update_option('rtgs_settings', $defaults);
        }

        // Create upload directory for feed
        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-google-shopping/';
        if (!file_exists($feed_dir)) {
            wp_mkdir_p($feed_dir);
            // Prevent directory listing
            file_put_contents($feed_dir . 'index.php', '<?php // Silence is golden');
        }

        // Schedule cron
        if (!wp_next_scheduled('rtgs_regenerate_feed_cron')) {
            wp_schedule_event(time(), 'daily', 'rtgs_regenerate_feed_cron');
        }

        // Generate initial feed
        $this->generate_feed_file();

        // Flush rewrite for feed endpoint
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rtgs_regenerate_feed_cron');
        flush_rewrite_rules();
    }

    /*--------------------------------------------------------------
    # Feed Endpoint
    --------------------------------------------------------------*/

    public function register_feed_endpoint() {
        add_feed('google-shopping', [$this, 'serve_feed']);

        // Also handle direct file access
        add_rewrite_rule(
            '^google-shopping-feed\.xml$',
            'index.php?feed=google-shopping',
            'top'
        );
    }

    public function serve_feed() {
        $settings = get_option('rtgs_settings', []);
        if (empty($settings['enabled'])) {
            status_header(404);
            exit;
        }

        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-google-shopping/' . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');

        // If file doesn't exist or is old, regenerate
        if (!file_exists($feed_file) || (time() - filemtime($feed_file)) > 86400) {
            $this->generate_feed_file();
        }

        if (file_exists($feed_file)) {
            // Track fetch
            $this->track_fetch();

            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('X-Robots-Tag: noindex');
            readfile($feed_file);
        } else {
            status_header(500);
            echo '<?xml version="1.0" encoding="UTF-8"?><error>Feed generation failed</error>';
        }
        exit;
    }

    /*--------------------------------------------------------------
    # Feed Generation
    --------------------------------------------------------------*/

    public function generate_feed_file() {
        $settings = get_option('rtgs_settings', []);
        if (empty($settings['enabled'])) return false;

        $xml = $this->build_feed_xml($settings);
        if (!$xml) return false;

        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-google-shopping/';
        $feed_file = $feed_dir . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');

        wp_mkdir_p($feed_dir);
        $written = file_put_contents($feed_file, $xml);

        if ($written) {
            // Re-read settings because build_feed_xml() already saved the product_count
            $settings = get_option('rtgs_settings', []);
            $settings['last_generated'] = current_time('mysql');
            update_option('rtgs_settings', $settings);
        }

        return (bool) $written;
    }

    private function build_feed_xml($settings) {
        $products = $this->get_products($settings);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . $this->xml_escape($settings['store_name'] ?? get_bloginfo('name')) . '</title>' . "\n";
        $xml .= '<link>' . $this->xml_escape($settings['store_url'] ?? home_url()) . '</link>' . "\n";
        $xml .= '<description>Google Shopping Feed for ' . $this->xml_escape($settings['store_name'] ?? '') . '</description>' . "\n";

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

        // Update product count
        $settings['product_count'] = $count;
        update_option('rtgs_settings', $settings);

        return $xml;
    }

    private function build_product_item($product, $settings) {
        if (!$product) return '';

        // Skip drafts/trash (shouldn't happen with our query, but safety check)
        $status = get_post_status($product->get_id());
        if ($status !== 'publish') return '';

        $id = $product->get_id();
        $title = $product->get_name();
        $link = $product->get_permalink();

        // Add UTM parameters
        $utm_params = [];
        if (!empty($settings['utm_source'])) $utm_params['utm_source'] = $settings['utm_source'];
        if (!empty($settings['utm_medium'])) $utm_params['utm_medium'] = $settings['utm_medium'];
        if (!empty($settings['utm_campaign'])) $utm_params['utm_campaign'] = $settings['utm_campaign'];
        if (!empty($utm_params)) {
            $link = add_query_arg($utm_params, $link);
        }

        // Description
        $description = '';
        if (($settings['description_source'] ?? 'short') === 'short') {
            $description = $product->get_short_description();
        }
        if (empty($description)) {
            $description = $product->get_description();
        }
        // Strip HTML and limit
        $description = wp_strip_all_tags($description);
        $description = mb_substr($description, 0, 5000);
        if (empty($description)) {
            $description = $title;
        }

        // Image
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        if (empty($image_url)) return ''; // Google requires an image

        // Additional images
        $gallery_ids = $product->get_gallery_image_ids();
        $additional_images = [];
        foreach (array_slice($gallery_ids, 0, 9) as $gid) {
            $url = wp_get_attachment_url($gid);
            if ($url) $additional_images[] = $url;
        }

        // Price
        $price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        if (empty($price) && $product->is_type('variable')) {
            $price = $product->get_variation_regular_price('min');
            $sale_price = $product->get_variation_sale_price('min');
        }
        if (empty($price)) return ''; // Google requires a price

        $currency = get_woocommerce_currency();
        $price_str = number_format((float)$price, 2, '.', '') . ' ' . $currency;
        $sale_price_str = '';
        if ($sale_price && (float)$sale_price < (float)$price) {
            $sale_price_str = number_format((float)$sale_price, 2, '.', '') . ' ' . $currency;
        }

        // Sale dates
        $sale_start = '';
        $sale_end = '';
        if ($sale_price_str) {
            $start = $product->get_date_on_sale_from();
            $end = $product->get_date_on_sale_to();
            if ($start) $sale_start = $start->format('Y-m-d\TH:i:sO');
            if ($end) $sale_end = $end->format('Y-m-d\TH:i:sO');
        }

        // Availability
        $stock_status = $product->get_stock_status();
        $availability_map = [
            'instock' => $settings['availability_in_stock'] ?? 'in_stock',
            'outofstock' => $settings['availability_out_of_stock'] ?? 'out_of_stock',
            'onbackorder' => $settings['availability_backorder'] ?? 'backorder',
        ];
        $availability = $availability_map[$stock_status] ?? 'in_stock';

        // Brand
        $brand = $settings['brand'] ?? get_bloginfo('name');

        // Condition
        $condition = $settings['condition'] ?? 'new';

        // Google product category
        $google_category = $this->get_google_category($product, $settings);

        // Product type from WooCommerce categories
        $product_type = $this->get_product_type($product);

        // Weight
        $weight = $product->get_weight();
        $weight_unit = get_option('woocommerce_weight_unit', 'lbs');
        $weight_map = ['lbs' => 'lb', 'kg' => 'kg', 'g' => 'g', 'oz' => 'oz'];

        // GTIN / MPN
        $gtin = '';
        $mpn = '';
        if (!empty($settings['gtin_field'])) {
            $gtin = get_post_meta($id, $settings['gtin_field'], true);
        }
        if (!empty($settings['mpn_field'])) {
            $mpn = get_post_meta($id, $settings['mpn_field'], true);
        }
        if (empty($mpn)) {
            $mpn = $product->get_sku();
        }

        $identifier_exists = $settings['identifier_exists'] ?? 'no';

        // Custom labels
        $custom_labels = [];
        for ($i = 0; $i <= 4; $i++) {
            $label_config = $settings["custom_label_{$i}"] ?? '';
            if (empty($label_config)) continue;

            $label_value = $this->resolve_custom_label($product, $label_config);
            if ($label_value) {
                $custom_labels[$i] = $label_value;
            }
        }

        // Build item XML
        $xml = "<item>\n";
        $xml .= "  <g:id>{$id}</g:id>\n";
        $xml .= "  <g:title>" . $this->xml_escape($title) . "</g:title>\n";
        $xml .= "  <g:description>" . $this->xml_escape($description) . "</g:description>\n";
        $xml .= "  <g:link>" . $this->xml_escape($link) . "</g:link>\n";
        $xml .= "  <g:image_link>" . $this->xml_escape($image_url) . "</g:image_link>\n";

        foreach ($additional_images as $img) {
            $xml .= "  <g:additional_image_link>" . $this->xml_escape($img) . "</g:additional_image_link>\n";
        }

        $xml .= "  <g:price>{$price_str}</g:price>\n";
        if ($sale_price_str) {
            $xml .= "  <g:sale_price>{$sale_price_str}</g:sale_price>\n";
            if ($sale_start && $sale_end) {
                $xml .= "  <g:sale_price_effective_date>{$sale_start}/{$sale_end}</g:sale_price_effective_date>\n";
            }
        }

        $xml .= "  <g:availability>{$availability}</g:availability>\n";

        // Inventory quantity — Google needs this for inventory data
        $quantity = $product->get_stock_quantity();
        if ($quantity !== null && $quantity !== '') {
            $xml .= "  <g:quantity>" . intval($quantity) . "</g:quantity>\n";
        } else {
            // If stock management is off, report based on status
            $xml .= "  <g:quantity>" . ($stock_status === 'instock' ? '999' : '0') . "</g:quantity>\n";
        }

        $xml .= "  <g:brand>" . $this->xml_escape($brand) . "</g:brand>\n";
        $xml .= "  <g:condition>{$condition}</g:condition>\n";

        if ($google_category) {
            $xml .= "  <g:google_product_category>" . $this->xml_escape($google_category) . "</g:google_product_category>\n";
        }
        if ($product_type) {
            $xml .= "  <g:product_type>" . $this->xml_escape($product_type) . "</g:product_type>\n";
        }

        // Identifiers
        if ($gtin) {
            $xml .= "  <g:gtin>" . $this->xml_escape($gtin) . "</g:gtin>\n";
        }
        if ($mpn) {
            $xml .= "  <g:mpn>" . $this->xml_escape($mpn) . "</g:mpn>\n";
        }
        $xml .= "  <g:identifier_exists>{$identifier_exists}</g:identifier_exists>\n";

        // Shipping weight
        if ($weight) {
            $unit = $weight_map[$weight_unit] ?? 'lb';
            $xml .= "  <g:shipping_weight>{$weight} {$unit}</g:shipping_weight>\n";
        }

        // Shipping label — auto-detect from WooCommerce categories
        $shipping_label = '';
        $product_terms = get_the_terms($product->get_id(), 'product_cat');
        if ($product_terms && !is_wp_error($product_terms)) {
            foreach ($product_terms as $pt) {
                $term_path = strtolower($pt->name);
                // Walk up to parent categories too
                $current = $pt;
                while ($current) {
                    $name_lower = strtolower($current->name);
                    if (strpos($name_lower, 'coaster') !== false) {
                        $shipping_label = 'coasters';
                        break 2;
                    }
                    if (strpos($name_lower, 'wall art') !== false || strpos($name_lower, 'stadium wall') !== false) {
                        $shipping_label = 'stadiums';
                        break 2;
                    }
                    $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
                }
            }
        }
        if ($shipping_label) {
            $xml .= "  <g:shipping_label>{$shipping_label}</g:shipping_label>\n";
        }

        // Shipping override
        if (!empty($settings['shipping_price']) && !empty($settings['shipping_country'])) {
            $xml .= "  <g:shipping>\n";
            $xml .= "    <g:country>{$settings['shipping_country']}</g:country>\n";
            $xml .= "    <g:price>" . number_format((float)$settings['shipping_price'], 2, '.', '') . " {$currency}</g:price>\n";
            if (!empty($settings['shipping_label'])) {
                $xml .= "    <g:service>" . $this->xml_escape($settings['shipping_label']) . "</g:service>\n";
            }
            $xml .= "  </g:shipping>\n";
        }

        // Custom labels
        foreach ($custom_labels as $idx => $val) {
            $xml .= "  <g:custom_label_{$idx}>" . $this->xml_escape($val) . "</g:custom_label_{$idx}>\n";
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

        // Max products
        $max = intval($settings['max_products'] ?? 0);
        if ($max > 0) {
            $args['posts_per_page'] = $max;
        }

        // Exclude out of stock
        if (!empty($settings['exclude_out_of_stock'])) {
            $args['meta_query'][] = [
                'key' => '_stock_status',
                'value' => 'outofstock',
                'compare' => '!=',
            ];
        }

        // Exclude categories
        if (!empty($settings['exclude_categories'])) {
            $exclude_cats = array_map('intval', explode(',', $settings['exclude_categories']));
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $exclude_cats,
                'operator' => 'NOT IN',
            ];
        }

        // Min price
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

        foreach ($query->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            if ($product->is_type('variable') && !empty($settings['include_variations'])) {
                // Add each variation as a separate item
                $variations = $product->get_available_variations();
                foreach ($variations as $var_data) {
                    $variation = wc_get_product($var_data['variation_id']);
                    if ($variation) {
                        $products[] = $variation;
                    }
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
        // Check for per-product category override meta
        $override = get_post_meta($product->get_id(), '_rtgs_google_category', true);
        if ($override) return $override;

        // Check if any WooCommerce category has a mapped Google category
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $mapped = get_term_meta($term->term_id, '_rtgs_google_category', true);
                if ($mapped) return $mapped;
            }
        }

        return $settings['default_google_category'] ?? 'Home & Garden > Decor';
    }

    private function get_product_type($product) {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) return '';

        // Build category hierarchy
        $categories = [];
        foreach ($terms as $term) {
            $path = [];
            $current = $term;
            while ($current) {
                array_unshift($path, $current->name);
                $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
            }
            $categories[] = implode(' > ', $path);
        }

        // Return the deepest/longest path
        usort($categories, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $categories[0] ?? '';
    }

    private function resolve_custom_label($product, $config) {
        // Config can be: "category", "tag:TagName", "meta:_field_name", or a static string
        if ($config === 'category') {
            $terms = get_the_terms($product->get_id(), 'product_cat');
            return ($terms && !is_wp_error($terms)) ? $terms[0]->name : '';
        }

        if (strpos($config, 'tag:') === 0) {
            $tag_name = substr($config, 4);
            $terms = get_the_terms($product->get_id(), 'product_tag');
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    if (strtolower($t->name) === strtolower($tag_name)) return $t->name;
                }
            }
            return '';
        }

        if (strpos($config, 'meta:') === 0) {
            $meta_key = substr($config, 5);
            return get_post_meta($product->get_id(), $meta_key, true);
        }

        if ($config === 'price_range') {
            $price = (float) $product->get_price();
            if ($price < 15) return 'Under $15';
            if ($price < 30) return '$15–$30';
            if ($price < 50) return '$30–$50';
            return 'Over $50';
        }

        if ($config === 'on_sale') {
            return $product->is_on_sale() ? 'On Sale' : 'Full Price';
        }

        // Static value
        return $config;
    }

    private function xml_escape($str) {
        return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function schedule_regeneration() {
        // Debounce: schedule a one-time event 5 minutes from now
        $timestamp = wp_next_scheduled('rtgs_regenerate_feed_cron');
        if (!$timestamp || $timestamp > time() + 300) {
            wp_schedule_single_event(time() + 300, 'rtgs_regenerate_feed_cron');
        }
    }

    private function track_fetch() {
        $count = (int) get_transient('rtgs_fetch_count');
        set_transient('rtgs_fetch_count', $count + 1, 30 * DAY_IN_SECONDS);

        $today = current_time('Y-m-d');
        $daily = get_option('rtgs_daily_fetches', []);
        $daily[$today] = ($daily[$today] ?? 0) + 1;
        // Keep only last 30 days
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        foreach ($daily as $date => $val) {
            if ($date < $cutoff) unset($daily[$date]);
        }
        update_option('rtgs_daily_fetches', $daily);
    }

    /*--------------------------------------------------------------
    # Admin Interface
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page(
            'Google Shopping',
            'Google Shopping',
            'manage_options',
            'rt-google-shopping',
            [$this, 'admin_page'],
            'dashicons-cart',
            60
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-google-shopping') return;
        wp_enqueue_style('rtgs-admin', RTGS_URL . 'admin.css', [], RTGS_VERSION);
        wp_enqueue_script('rtgs-admin', RTGS_URL . 'admin.js', ['jquery'], RTGS_VERSION, true);

        $settings = get_option('rtgs_settings', []);
        wp_localize_script('rtgs-admin', 'rtgs', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtgs_nonce'),
            'feed_url' => home_url('/feed/google-shopping/'),
            'alt_feed_url' => home_url('/google-shopping-feed.xml'),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtgs_settings', []);

        // Get categories for exclusion picker
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-google-shopping/' . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');
        $feed_exists = file_exists($feed_file);
        $feed_size = $feed_exists ? size_format(filesize($feed_file)) : '—';

        include RTGS_PATH . 'admin-page.php';
    }

    /*--------------------------------------------------------------
    # AJAX Handlers
    --------------------------------------------------------------*/

    public function ajax_save_settings() {
        check_ajax_referer('rtgs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        if (!empty($_POST['settings'])) {
            parse_str($_POST['settings'], $data);
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $clean[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        // Toggles
        $toggles = ['enabled', 'exclude_out_of_stock', 'include_variations', 'include_tax'];
        foreach ($toggles as $key) {
            $clean[$key] = isset($clean[$key]) ? 1 : 0;
        }

        // Preserve stats
        $existing = get_option('rtgs_settings', []);
        $clean['last_generated'] = $existing['last_generated'] ?? '';
        $clean['product_count'] = $existing['product_count'] ?? 0;

        update_option('rtgs_settings', $clean);

        // Update cron schedule
        wp_clear_scheduled_hook('rtgs_regenerate_feed_cron');
        $interval = $clean['auto_regenerate'] ?? 'daily';
        if ($interval !== 'manual') {
            wp_schedule_event(time(), $interval, 'rtgs_regenerate_feed_cron');
        }

        wp_send_json_success('Settings saved');
    }

    public function ajax_regenerate_feed() {
        check_ajax_referer('rtgs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $start = microtime(true);
        $result = $this->generate_feed_file();
        $elapsed = round(microtime(true) - $start, 2);

        if ($result) {
            $settings = get_option('rtgs_settings', []);
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
        check_ajax_referer('rtgs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtgs_settings', []);
        $daily = get_option('rtgs_daily_fetches', []);
        $total_fetches = (int) get_transient('rtgs_fetch_count');

        // Product breakdown
        $total_products = wp_count_posts('product')->publish;
        $in_stock = (int) wc_get_loop_prop('total', 0);

        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-google-shopping/' . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');

        wp_send_json_success([
            'enabled' => !empty($settings['enabled']),
            'product_count' => $settings['product_count'] ?? 0,
            'total_products' => $total_products,
            'last_generated' => $settings['last_generated'] ?? 'Never',
            'feed_size' => file_exists($feed_file) ? size_format(filesize($feed_file)) : '—',
            'feed_exists' => file_exists($feed_file),
            'total_fetches' => $total_fetches,
            'daily_fetches' => $daily,
            'feed_url' => home_url('/feed/google-shopping/'),
        ]);
    }

    public function ajax_preview_feed() {
        check_ajax_referer('rtgs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtgs_settings', []);
        $upload_dir = wp_upload_dir();
        $feed_file = $upload_dir['basedir'] . '/rt-google-shopping/' . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');

        if (!file_exists($feed_file)) {
            wp_send_json_error('Feed file not found. Generate it first.');
            return;
        }

        $content = file_get_contents($feed_file);
        // Limit preview size
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n<!-- Truncated for preview -->";
        }

        wp_send_json_success(['xml' => $content]);
    }

    public function ajax_diagnose_feed() {
        check_ajax_referer('rtgs_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtgs_settings', []);
        $report = [];

        // 1. Check if enabled
        $report[] = 'Feed enabled: ' . (!empty($settings['enabled']) ? 'YES' : 'NO ⚠️');

        // 2. Check WooCommerce
        $report[] = 'WooCommerce active: ' . (class_exists('WooCommerce') ? 'YES' : 'NO ⚠️');

        // 3. Count published products
        $total = wp_count_posts('product');
        $published = $total->publish ?? 0;
        $report[] = "Published products (wp_count_posts): {$published}";

        // 4. Run the same WP_Query the feed uses
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        $max = intval($settings['max_products'] ?? 0);
        if ($max > 0) {
            $args['posts_per_page'] = $max;
            $report[] = "Max products filter: {$max}";
        }

        if (!empty($settings['exclude_out_of_stock'])) {
            $args['meta_query'][] = [
                'key' => '_stock_status',
                'value' => 'outofstock',
                'compare' => '!=',
            ];
            $report[] = 'Exclude out of stock: ON';
        }

        if (!empty($settings['exclude_categories'])) {
            $exclude_cats = array_map('intval', explode(',', $settings['exclude_categories']));
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $exclude_cats,
                'operator' => 'NOT IN',
            ];
            $report[] = 'Excluded category IDs: ' . $settings['exclude_categories'];
        }

        if (!empty($settings['min_price'])) {
            $args['meta_query'][] = [
                'key' => '_price',
                'value' => (float) $settings['min_price'],
                'compare' => '>=',
                'type' => 'DECIMAL',
            ];
            $report[] = 'Min price filter: $' . $settings['min_price'];
        }

        $query = new WP_Query($args);
        $found = $query->found_posts;
        $report[] = "WP_Query found: {$found} products";

        // 5. Check each product for why it might be skipped
        $skip_reasons = [
            'no_wc_product' => 0,
            'not_published' => 0,
            'no_image' => 0,
            'no_price' => 0,
            'passed' => 0,
        ];
        $sample_issues = [];

        foreach ($query->posts as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                $skip_reasons['no_wc_product']++;
                continue;
            }

            $status = get_post_status($pid);
            if ($status !== 'publish') {
                $skip_reasons['not_published']++;
                continue;
            }

            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            if (empty($image_url)) {
                $skip_reasons['no_image']++;
                if (count($sample_issues) < 5) {
                    $sample_issues[] = "#{$pid} \"{$product->get_name()}\" — no featured image";
                }
                continue;
            }

            $price = $product->get_regular_price();
            if (empty($price) && $product->is_type('variable')) {
                $price = $product->get_variation_regular_price('min');
            }
            if (empty($price)) {
                $skip_reasons['no_price']++;
                if (count($sample_issues) < 5) {
                    $sample_issues[] = "#{$pid} \"{$product->get_name()}\" — no regular price";
                }
                continue;
            }

            $skip_reasons['passed']++;
        }

        $report[] = '--- Product Check Results ---';
        $report[] = "Would appear in feed: {$skip_reasons['passed']}";
        if ($skip_reasons['no_wc_product']) $report[] = "⚠️ Not a valid WC product: {$skip_reasons['no_wc_product']}";
        if ($skip_reasons['not_published']) $report[] = "⚠️ Not published status: {$skip_reasons['not_published']}";
        if ($skip_reasons['no_image']) $report[] = "⚠️ Missing featured image: {$skip_reasons['no_image']}";
        if ($skip_reasons['no_price']) $report[] = "⚠️ Missing regular price: {$skip_reasons['no_price']}";

        if (!empty($sample_issues)) {
            $report[] = '--- Sample Issues ---';
            foreach ($sample_issues as $issue) {
                $report[] = $issue;
            }
        }

        // 6. Check feed file
        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/rt-google-shopping/';
        $feed_file = $feed_dir . ($settings['feed_filename'] ?? 'google-shopping-feed.xml');
        $report[] = '--- Feed File ---';
        $report[] = 'Feed directory: ' . $feed_dir;
        $report[] = 'Directory exists: ' . (is_dir($feed_dir) ? 'YES' : 'NO ⚠️');
        $report[] = 'Directory writable: ' . (is_writable($feed_dir) ? 'YES' : 'NO ⚠️');
        $report[] = 'Feed file exists: ' . (file_exists($feed_file) ? 'YES (' . size_format(filesize($feed_file)) . ')' : 'NO');

        wp_send_json_success(['report' => implode("\n", $report)]);
    }
}

RT_Google_Shopping_Feed::get_instance();
