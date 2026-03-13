<?php
/**
 * Plugin Name: RT Social Proof
 * Description: Live social proof notifications — show recent purchases, product views, add-to-carts, and browsing activity as subtle toast popups.
 * Version: 1.1.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-social-proof
 */

if (!defined('ABSPATH')) exit;

define('RTSP_VERSION', '1.1.0');
define('RTSP_PATH', plugin_dir_path(__FILE__));
define('RTSP_URL', plugin_dir_url(__FILE__));

class RT_Social_Proof {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_rtsp_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtsp_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtsp_reset_stats', [$this, 'ajax_reset_stats']);
        add_action('wp_ajax_rtsp_reset_defaults', [$this, 'ajax_reset_defaults']);

        // Self-heal settings on init
        add_action('init', [$this, 'self_heal_settings']);

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('wp_footer', [$this, 'render_container']);

        // AJAX endpoints for notifications
        add_action('wp_ajax_rtsp_get_notifications', [$this, 'ajax_get_notifications']);
        add_action('wp_ajax_nopriv_rtsp_get_notifications', [$this, 'ajax_get_notifications']);

        // Track activity
        add_action('wp_ajax_rtsp_track_view', [$this, 'ajax_track_view']);
        add_action('wp_ajax_nopriv_rtsp_track_view', [$this, 'ajax_track_view']);
        add_action('wp_ajax_rtsp_track_cart', [$this, 'ajax_track_cart']);
        add_action('wp_ajax_nopriv_rtsp_track_cart', [$this, 'ajax_track_cart']);

        // Track real orders
        add_action('woocommerce_new_order', [$this, 'track_order'], 10, 1);
    }

    /*--------------------------------------------------------------
    # Activation
    --------------------------------------------------------------*/

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Activity tracking table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtsp_activity (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_type VARCHAR(30) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(500) NOT NULL,
            product_url VARCHAR(500) DEFAULT '',
            product_image VARCHAR(500) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            state VARCHAR(100) DEFAULT '',
            country VARCHAR(10) DEFAULT 'US',
            created_at DATETIME NOT NULL,
            is_real TINYINT(1) DEFAULT 1,
            INDEX idx_type (activity_type),
            INDEX idx_created (created_at),
            INDEX idx_real (is_real)
        ) $charset");

        // Stats table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtsp_stats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            impressions INT DEFAULT 0,
            UNIQUE KEY idx_date (stat_date)
        ) $charset");

        // Always merge defaults with existing settings (fills missing keys)
        $defaults = $this->get_defaults();
        $existing = get_option('rtsp_settings', []);
        if (!is_array($existing)) $existing = [];
        $merged = wp_parse_args($existing, $defaults);
        update_option('rtsp_settings', $merged);
    }

    /*--------------------------------------------------------------
    # Track Real Activity
    --------------------------------------------------------------*/

    public function track_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $city = $order->get_billing_city();
        $state = $order->get_billing_state();
        $country = $order->get_billing_country();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $this->save_activity([
                'activity_type' => 'purchase',
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'product_url' => $product->get_permalink(),
                'product_image' => wp_get_attachment_url($product->get_image_id()),
                'city' => $city,
                'state' => $state,
                'country' => $country ?: 'US',
                'is_real' => 1,
            ]);
        }
    }

    public function ajax_track_view() {
        check_ajax_referer('rtsp_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);
        if (!$product_id) wp_send_json_error();

        $product = wc_get_product($product_id);
        if (!$product) wp_send_json_error();

        $location = $this->get_random_filler_city();

        $this->save_activity([
            'activity_type' => 'view',
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'product_url' => $product->get_permalink(),
            'product_image' => wp_get_attachment_url($product->get_image_id()),
            'city' => $location['city'],
            'state' => $location['state'],
            'is_real' => 0,
        ]);

        wp_send_json_success();
    }

    public function ajax_track_cart() {
        check_ajax_referer('rtsp_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);
        if (!$product_id) wp_send_json_error();

        $product = wc_get_product($product_id);
        if (!$product) wp_send_json_error();

        $location = $this->get_random_filler_city();

        $this->save_activity([
            'activity_type' => 'cart_add',
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'product_url' => $product->get_permalink(),
            'product_image' => wp_get_attachment_url($product->get_image_id()),
            'city' => $location['city'],
            'state' => $location['state'],
            'is_real' => 0,
        ]);

        wp_send_json_success();
    }

    private function save_activity($data) {
        global $wpdb;
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'rtsp_activity', $data);
    }

    /*--------------------------------------------------------------
    # Get Notifications
    --------------------------------------------------------------*/

    public function ajax_get_notifications() {
        $settings = get_option('rtsp_settings', []);
        if (empty($settings['enabled'])) {
            wp_send_json_success(['notifications' => []]);
        }

        $notifications = [];

        // 1. Real purchases
        if (!empty($settings['show_purchases'])) {
            $purchases = $this->get_real_purchases($settings);
            $notifications = array_merge($notifications, $purchases);
        }

        // 2. Real + filler views
        if (!empty($settings['show_views'])) {
            $views = $this->get_view_notifications($settings);
            $notifications = array_merge($notifications, $views);
        }

        // 3. Cart adds
        if (!empty($settings['show_cart_adds'])) {
            $carts = $this->get_cart_notifications($settings);
            $notifications = array_merge($notifications, $carts);
        }

        // 4. Recent views
        if (!empty($settings['show_recent_views'])) {
            $recent = $this->get_recent_view_notifications($settings);
            $notifications = array_merge($notifications, $recent);
        }

        // If we don't have enough real data and filler is enabled, generate some
        if (!empty($settings['filler_enabled']) && count($notifications) < 5) {
            $filler = $this->generate_filler_notifications($settings, 5 - count($notifications));
            $notifications = array_merge($notifications, $filler);
        }

        // Shuffle and limit
        shuffle($notifications);
        $max = intval($settings['max_per_page'] ?? 10);
        $notifications = array_slice($notifications, 0, $max);

        // Track impression
        $this->track_impression();

        wp_send_json_success(['notifications' => $notifications]);
    }

    private function get_real_purchases($settings) {
        global $wpdb;
        $lookback = intval($settings['purchase_lookback_days'] ?? 30);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$lookback} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rtsp_activity 
             WHERE activity_type = 'purchase' AND is_real = 1 AND created_at > %s 
             ORDER BY created_at DESC LIMIT 10",
            $cutoff
        ));

        $notifications = [];
        foreach ($results as $r) {
            $time_ago = $this->time_ago($r->created_at, $settings);
            $text = str_replace(
                ['{city}', '{state}', '{product}'],
                [$r->city ?: 'a nearby city', $r->state, $r->product_name],
                $settings['purchase_text'] ?? 'Someone in {city} just purchased'
            );

            $notifications[] = [
                'type' => 'purchase',
                'text' => $text,
                'product_name' => $r->product_name,
                'product_url' => $r->product_url,
                'product_image' => $r->product_image,
                'time_ago' => $time_ago,
                'verified' => true,
            ];
        }
        return $notifications;
    }

    private function get_view_notifications($settings) {
        global $wpdb;
        $lookback = intval($settings['view_lookback_minutes'] ?? 30);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$lookback} minutes"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rtsp_activity 
             WHERE activity_type = 'view' AND created_at > %s 
             ORDER BY created_at DESC LIMIT 5",
            $cutoff
        ));

        $notifications = [];
        foreach ($results as $r) {
            $text = str_replace(
                ['{city}', '{state}', '{product}'],
                [$r->city ?: 'a nearby city', $r->state, $r->product_name],
                $settings['view_text'] ?? 'Someone in {city} is viewing this right now'
            );

            $notifications[] = [
                'type' => 'view',
                'text' => $text,
                'product_name' => $r->product_name,
                'product_url' => $r->product_url,
                'product_image' => $r->product_image,
                'time_ago' => 'Just now',
            ];
        }
        return $notifications;
    }

    private function get_cart_notifications($settings) {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime('-2 hours'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rtsp_activity 
             WHERE activity_type = 'cart_add' AND created_at > %s 
             ORDER BY created_at DESC LIMIT 5",
            $cutoff
        ));

        $notifications = [];
        foreach ($results as $r) {
            $text = str_replace(
                ['{city}', '{state}', '{product}'],
                [$r->city ?: 'a nearby city', $r->state, $r->product_name],
                $settings['cart_text'] ?? 'Someone in {city} just added this to their cart'
            );

            $notifications[] = [
                'type' => 'cart_add',
                'text' => $text,
                'product_name' => $r->product_name,
                'product_url' => $r->product_url,
                'product_image' => $r->product_image,
                'time_ago' => $this->time_ago($r->created_at, $settings),
            ];
        }
        return $notifications;
    }

    private function get_recent_view_notifications($settings) {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime('-4 hours'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT *, MAX(created_at) as latest FROM {$wpdb->prefix}rtsp_activity 
             WHERE activity_type = 'view' AND created_at > %s 
             GROUP BY product_id
             ORDER BY latest DESC LIMIT 5",
            $cutoff
        ));

        $notifications = [];
        foreach ($results as $r) {
            $text = str_replace(
                ['{city}', '{state}', '{product}'],
                [$r->city ?: 'a nearby city', $r->state, $r->product_name],
                $settings['recent_view_text'] ?? 'Someone in {city} recently viewed'
            );

            $notifications[] = [
                'type' => 'recent_view',
                'text' => $text,
                'product_name' => $r->product_name,
                'product_url' => $r->product_url,
                'product_image' => $r->product_image,
                'time_ago' => $this->time_ago($r->created_at, $settings),
            ];
        }
        return $notifications;
    }

    /*--------------------------------------------------------------
    # Filler Notifications
    --------------------------------------------------------------*/

    private function generate_filler_notifications($settings, $count) {
        $products = $this->get_random_products(max($count, 5));
        if (empty($products)) return [];

        $types = [];
        if (!empty($settings['show_purchases'])) $types[] = 'purchase';
        if (!empty($settings['show_views'])) $types[] = 'view';
        if (!empty($settings['show_cart_adds'])) $types[] = 'cart_add';
        if (!empty($settings['show_recent_views'])) $types[] = 'recent_view';
        if (empty($types)) return [];

        $max_age = intval($settings['filler_max_age_hours'] ?? 48);
        $notifications = [];

        for ($i = 0; $i < $count; $i++) {
            $product = $products[array_rand($products)];
            $type = $types[array_rand($types)];
            $location = $this->get_random_filler_city($settings);

            // Random time within max age
            $seconds_ago = rand(60, $max_age * 3600);
            $fake_time = date('Y-m-d H:i:s', time() - $seconds_ago);

            $text_templates = [
                'purchase' => $settings['purchase_text'] ?? 'Someone in {city} just purchased',
                'view' => $settings['view_text'] ?? 'Someone in {city} is viewing this right now',
                'cart_add' => $settings['cart_text'] ?? 'Someone in {city} just added this to their cart',
                'recent_view' => $settings['recent_view_text'] ?? 'Someone in {city} recently viewed',
            ];

            $text = str_replace(
                ['{city}', '{state}', '{product}'],
                [$location['city'], $location['state'], $product['name']],
                $text_templates[$type]
            );

            // Views should appear recent
            if ($type === 'view') {
                $time_ago = rand(0, 1) ? 'Just now' : rand(1, 5) . ' min ago';
            } else {
                $time_ago = $this->time_ago($fake_time, $settings);
            }

            $notifications[] = [
                'type' => $type,
                'text' => $text,
                'product_name' => $product['name'],
                'product_url' => $product['url'],
                'product_image' => $product['image'],
                'time_ago' => $time_ago,
                'verified' => $type === 'purchase' ? false : null,
            ];
        }

        return $notifications;
    }

    private function get_random_products($count) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => $count,
            'orderby' => 'rand',
            'post_status' => 'publish',
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'url' => $product->get_permalink(),
                'image' => wp_get_attachment_url($product->get_image_id()),
            ];
        }

        return $products;
    }

    private function get_random_filler_city($settings = null) {
        if (!$settings) $settings = get_option('rtsp_settings', []);

        $cities_text = $settings['filler_cities'] ?? "Dallas, TX\nChicago, IL\nNew York, NY";
        $lines = array_filter(array_map('trim', explode("\n", $cities_text)));

        if (empty($lines)) return ['city' => 'a nearby city', 'state' => ''];

        $line = $lines[array_rand($lines)];
        $parts = array_map('trim', explode(',', $line));

        return [
            'city' => $parts[0] ?? 'a nearby city',
            'state' => $parts[1] ?? '',
        ];
    }

    /*--------------------------------------------------------------
    # Helpers
    --------------------------------------------------------------*/

    private function time_ago($datetime, $settings = []) {
        $diff = time() - strtotime($datetime);

        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return round($diff / 60) . ' min ago';
        if ($diff < 86400) {
            $hours = round($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }
        $days = round($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }

    private function track_impression() {
        global $wpdb;
        $today = current_time('Y-m-d');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}rtsp_stats (stat_date, impressions) VALUES (%s, 1) ON DUPLICATE KEY UPDATE impressions = impressions + 1",
            $today
        ));
    }

    /*--------------------------------------------------------------
    # Frontend
    --------------------------------------------------------------*/

    public function frontend_assets() {
        if (is_admin()) return;

        $settings = get_option('rtsp_settings', []);
        if (empty($settings['enabled'])) return;

        // Check page restrictions
        if (!empty($settings['hide_on_cart']) && is_cart()) return;
        if (!empty($settings['hide_on_checkout']) && is_checkout()) return;

        $show_on = $settings['show_on'] ?? 'all';
        if ($show_on === 'shop' && !is_shop() && !is_product_category()) return;
        if ($show_on === 'product' && !is_product()) return;

        wp_enqueue_style('rtsp-front', RTSP_URL . 'front.css', [], RTSP_VERSION);
        wp_enqueue_script('rtsp-front', RTSP_URL . 'front.js', ['jquery'], RTSP_VERSION, true);

        $current_product_id = 0;
        if (is_product()) {
            global $post;
            $current_product_id = $post->ID;
        }

        wp_localize_script('rtsp-front', 'rtsp', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtsp_nonce'),
            'product_id' => $current_product_id,
            'settings' => [
                'position' => $settings['position'] ?? 'bottom-left',
                'display_duration' => intval($settings['display_duration'] ?? 5) * 1000,
                'delay_between' => intval($settings['delay_between'] ?? 12) * 1000,
                'initial_delay' => intval($settings['initial_delay'] ?? 5) * 1000,
                'animation' => $settings['animation'] ?? 'slide',
                'show_close' => !empty($settings['show_close']),
                'show_image' => !empty($settings['show_image']),
                'show_time' => !empty($settings['show_time']),
                'bg_color' => $settings['bg_color'] ?? '#ffffff',
                'text_color' => $settings['text_color'] ?? '#333333',
                'accent_color' => $settings['accent_color'] ?? '#A2755A',
                'border_radius' => intval($settings['border_radius'] ?? 10),
            ],
        ]);
    }

    public function render_container() {
        $settings = get_option('rtsp_settings', []);
        if (empty($settings['enabled'])) return;
        if (!empty($settings['hide_on_cart']) && is_cart()) return;
        if (!empty($settings['hide_on_checkout']) && is_checkout()) return;

        echo '<div id="rtsp-container"></div>';
    }

    /*--------------------------------------------------------------
    # Admin
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page(
            'Social Proof',
            'Social Proof',
            'manage_options',
            'rt-social-proof',
            [$this, 'admin_page'],
            'dashicons-megaphone',
            59
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-social-proof') return;
        wp_enqueue_style('rtsp-admin', RTSP_URL . 'admin.css', [], RTSP_VERSION);
        wp_enqueue_script('rtsp-admin', RTSP_URL . 'admin.js', ['jquery'], RTSP_VERSION, true);
        wp_localize_script('rtsp-admin', 'rtsp_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtsp_nonce'),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtsp_settings', []);
        include RTSP_PATH . 'admin-page.php';
    }

    public function ajax_save_settings() {
        check_ajax_referer('rtsp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        if (!empty($_POST['settings'])) {
            parse_str($_POST['settings'], $data);
            $data = wp_unslash($data);
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $clean[sanitize_key($key)] = $key === 'filler_cities' ? sanitize_textarea_field($value) : sanitize_text_field($value);
            } else {
                $clean[sanitize_key($key)] = $value;
            }
        }

        // Only zero out toggles for the tab that was actually submitted
        $tab_toggles = [
            'purchase_text' => ['show_purchases', 'show_views', 'show_cart_adds', 'show_recent_views'],
            'bg_color' => ['show_image', 'show_time', 'show_close'],
            'filler_cities' => ['filler_enabled'],
            'show_on' => ['enabled', 'hide_on_cart', 'hide_on_checkout'],
        ];

        foreach ($tab_toggles as $marker => $toggles) {
            if (array_key_exists($marker, $clean)) {
                foreach ($toggles as $key) {
                    $clean[$key] = isset($clean[$key]) ? 1 : 0;
                }
            }
        }

        // Merge with existing settings first (preserves other tabs), then defaults
        $existing = get_option('rtsp_settings', []);
        if (!is_array($existing)) $existing = [];
        $defaults = $this->get_defaults();
        $merged = wp_parse_args($clean, $existing);
        $merged = wp_parse_args($merged, $defaults);
        update_option('rtsp_settings', $merged);
        wp_send_json_success('Settings saved');
    }

    public function ajax_reset_defaults() {
        check_ajax_referer('rtsp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $defaults = $this->get_defaults();
        update_option('rtsp_settings', $defaults);
        wp_send_json_success('Settings reset to defaults');
    }

    public function ajax_get_stats() {
        check_ajax_referer('rtsp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;

        $stats = [
            'total_real' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rtsp_activity WHERE is_real = 1"),
            'total_activities' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rtsp_activity"),
            'total_impressions' => (int) $wpdb->get_var("SELECT SUM(impressions) FROM {$wpdb->prefix}rtsp_stats"),
            'today_impressions' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT impressions FROM {$wpdb->prefix}rtsp_stats WHERE stat_date = %s",
                current_time('Y-m-d')
            )),
            'by_type' => [],
            'recent' => [],
            'daily' => [],
        ];

        // By type
        $types = $wpdb->get_results("SELECT activity_type, COUNT(*) as total, SUM(is_real) as real_count FROM {$wpdb->prefix}rtsp_activity GROUP BY activity_type");
        foreach ($types as $t) {
            $stats['by_type'][$t->activity_type] = [
                'total' => (int) $t->total,
                'real' => (int) $t->real_count,
            ];
        }

        // Recent activity
        $stats['recent'] = $wpdb->get_results("SELECT activity_type, product_name, city, state, is_real, created_at FROM {$wpdb->prefix}rtsp_activity ORDER BY created_at DESC LIMIT 15");

        // Daily impressions last 7 days
        $stats['daily'] = $wpdb->get_results("SELECT stat_date, impressions FROM {$wpdb->prefix}rtsp_stats ORDER BY stat_date DESC LIMIT 7");

        wp_send_json_success($stats);
    }

    public function ajax_reset_stats() {
        check_ajax_referer('rtsp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtsp_activity");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtsp_stats");
        wp_send_json_success('Stats reset');
    }

    /*--------------------------------------------------------------
    # Default Settings
    --------------------------------------------------------------*/

    public function get_defaults() {
        return [
            'enabled' => 1,
            'show_on' => 'all',
            'hide_on_cart' => 1,
            'hide_on_checkout' => 1,
            'position' => 'bottom-left',
            'display_duration' => 5,
            'delay_between' => 12,
            'initial_delay' => 5,
            'max_per_page' => 10,
            'show_purchases' => 1,
            'show_views' => 1,
            'show_cart_adds' => 1,
            'show_recent_views' => 1,
            'purchase_text' => 'Someone in {city} just purchased',
            'view_text' => 'Someone in {city} is viewing this right now',
            'cart_text' => 'Someone in {city} just added this to their cart',
            'recent_view_text' => 'Someone in {city} recently viewed',
            'time_format' => 'relative',
            'bg_color' => '#ffffff',
            'text_color' => '#333333',
            'accent_color' => '#A2755A',
            'border_radius' => 10,
            'show_image' => 1,
            'show_time' => 1,
            'animation' => 'slide',
            'show_close' => 1,
            'filler_enabled' => 1,
            'filler_cities' => "Dallas, TX\nChicago, IL\nNew York, NY\nLos Angeles, CA\nPhoenix, AZ\nHouston, TX\nPhiladelphia, PA\nSan Antonio, TX\nSan Diego, CA\nDenver, CO\nNashville, TN\nSeattle, WA\nColumbus, OH\nCharlotte, NC\nDetroit, MI\nBoston, MA\nMemphis, TN\nPortland, OR\nAtlanta, GA\nMiami, FL\nMinneapolis, MN\nTampa, FL\nNew Orleans, LA\nCleveland, OH\nPittsburgh, PA\nCincinnati, OH\nKansas City, MO\nMilwaukee, WI\nSt. Louis, MO\nBaltimore, MD",
            'filler_max_age_hours' => 48,
            'purchase_lookback_days' => 30,
            'view_lookback_minutes' => 30,
        ];
    }

    public function self_heal_settings() {
        $settings = get_option('rtsp_settings', []);
        if (!is_array($settings)) $settings = [];
        $defaults = $this->get_defaults();
        $merged = wp_parse_args($settings, $defaults);
        if ($merged !== $settings) {
            update_option('rtsp_settings', $merged);
        }
    }
}

RT_Social_Proof::get_instance();
