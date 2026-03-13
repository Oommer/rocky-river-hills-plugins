<?php
/**
 * Plugin Name: RT Dynamic Coupon Engine
 * Description: Schedule and automate promotional coupons with seasonal templates, smart rules, and an announcement bar for your WooCommerce store.
 * Version: 1.0.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-coupon-engine
 */

if (!defined('ABSPATH')) exit;

define('RTCE_VERSION', '1.0.0');
define('RTCE_PATH', plugin_dir_path(__FILE__));
define('RTCE_URL', plugin_dir_url(__FILE__));

class RT_Coupon_Engine {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        // AJAX
        add_action('wp_ajax_rtce_save_promo', [$this, 'ajax_save_promo']);
        add_action('wp_ajax_rtce_delete_promo', [$this, 'ajax_delete_promo']);
        add_action('wp_ajax_rtce_toggle_promo', [$this, 'ajax_toggle_promo']);
        add_action('wp_ajax_rtce_get_promos', [$this, 'ajax_get_promos']);
        add_action('wp_ajax_rtce_get_promo', [$this, 'ajax_get_promo']);
        add_action('wp_ajax_rtce_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtce_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtce_load_template', [$this, 'ajax_load_template']);

        // Cron for auto-activate/deactivate
        add_action('rtce_check_promos', [$this, 'check_scheduled_promos']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);

        // Frontend announcement bar
        add_action('wp_head', [$this, 'announcement_bar_css']);
        add_action('wp_body_open', [$this, 'announcement_bar_html']);

        // Auto-apply coupon to cart
        add_action('woocommerce_before_calculate_totals', [$this, 'auto_apply_coupons']);
    }

    /*--------------------------------------------------------------
    # Activation
    --------------------------------------------------------------*/

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtce_promotions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT DEFAULT '',
            status VARCHAR(20) DEFAULT 'draft',
            discount_type VARCHAR(20) DEFAULT 'percent',
            discount_value DECIMAL(10,2) DEFAULT 0,
            coupon_code VARCHAR(50) DEFAULT '',
            wc_coupon_id BIGINT UNSIGNED DEFAULT 0,
            -- Schedule
            start_date DATETIME DEFAULT NULL,
            end_date DATETIME DEFAULT NULL,
            -- Rules
            min_cart_total DECIMAL(10,2) DEFAULT 0,
            max_uses INT DEFAULT 0,
            max_uses_per_user INT DEFAULT 0,
            first_time_only TINYINT(1) DEFAULT 0,
            free_shipping TINYINT(1) DEFAULT 0,
            exclude_sale_items TINYINT(1) DEFAULT 0,
            -- Targeting
            apply_to VARCHAR(20) DEFAULT 'all',
            product_ids TEXT DEFAULT '',
            category_ids TEXT DEFAULT '',
            -- Display
            show_banner TINYINT(1) DEFAULT 1,
            banner_text VARCHAR(500) DEFAULT '',
            banner_bg_color VARCHAR(7) DEFAULT '#A2755A',
            banner_text_color VARCHAR(7) DEFAULT '#ffffff',
            -- Auto-apply
            auto_apply TINYINT(1) DEFAULT 0,
            -- Meta
            times_used INT DEFAULT 0,
            revenue_generated DECIMAL(10,2) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_code (coupon_code)
        ) $charset");

        // Usage tracking
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtce_usage_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            promo_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            discount_amount DECIMAL(10,2) DEFAULT 0,
            order_total DECIMAL(10,2) DEFAULT 0,
            used_at DATETIME NOT NULL,
            INDEX idx_promo (promo_id),
            INDEX idx_order (order_id)
        ) $charset");

        $defaults = [
            'banner_position' => 'top',
            'banner_dismissible' => 1,
            'banner_font_size' => 14,
            'auto_generate_codes' => 1,
            'code_prefix' => 'RRH',
            'track_revenue' => 1,
        ];

        if (!get_option('rtce_settings')) {
            update_option('rtce_settings', $defaults);
        }

        // Cron
        if (!wp_next_scheduled('rtce_check_promos')) {
            wp_schedule_event(time(), 'every_five_minutes', 'rtce_check_promos');
        }
    }

    public function cron_schedules($schedules) {
        $schedules['every_five_minutes'] = [
            'interval' => 300,
            'display' => 'Every 5 Minutes'
        ];
        return $schedules;
    }

    /*--------------------------------------------------------------
    # Promo ↔ WooCommerce Coupon Sync
    --------------------------------------------------------------*/

    private function create_wc_coupon($promo) {
        $coupon = new WC_Coupon();

        $code = $promo['coupon_code'];
        if (empty($code)) {
            $settings = get_option('rtce_settings', []);
            $prefix = $settings['code_prefix'] ?? 'RRH';
            $code = strtoupper($prefix . '-' . wp_generate_password(6, false));
        }

        $coupon->set_code($code);
        $coupon->set_description($promo['name'] . ' — ' . ($promo['description'] ?? ''));

        // Discount type
        switch ($promo['discount_type']) {
            case 'percent':
                $coupon->set_discount_type('percent');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'fixed_cart':
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'fixed_product':
                $coupon->set_discount_type('fixed_product');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'free_shipping':
                $coupon->set_discount_type('percent');
                $coupon->set_amount(0);
                $coupon->set_free_shipping(true);
                break;
        }

        // Free shipping addon
        if (!empty($promo['free_shipping'])) {
            $coupon->set_free_shipping(true);
        }

        // Rules
        if (!empty($promo['min_cart_total'])) {
            $coupon->set_minimum_amount($promo['min_cart_total']);
        }
        if (!empty($promo['max_uses'])) {
            $coupon->set_usage_limit($promo['max_uses']);
        }
        if (!empty($promo['max_uses_per_user'])) {
            $coupon->set_usage_limit_per_user($promo['max_uses_per_user']);
        }
        if (!empty($promo['exclude_sale_items'])) {
            $coupon->set_exclude_sale_items(true);
        }

        // Schedule
        if (!empty($promo['start_date'])) {
            $coupon->set_date_created(strtotime($promo['start_date']));
        }
        if (!empty($promo['end_date'])) {
            $coupon->set_date_expires(strtotime($promo['end_date']));
        }

        // Targeting
        if ($promo['apply_to'] === 'products' && !empty($promo['product_ids'])) {
            $ids = array_map('intval', explode(',', $promo['product_ids']));
            $coupon->set_product_ids($ids);
        }
        if ($promo['apply_to'] === 'categories' && !empty($promo['category_ids'])) {
            $ids = array_map('intval', explode(',', $promo['category_ids']));
            $coupon->set_product_categories($ids);
        }

        // First time only: limit to 1 per user
        if (!empty($promo['first_time_only'])) {
            $coupon->set_usage_limit_per_user(1);
        }

        // Individual use
        $coupon->set_individual_use(true);

        $coupon->save();

        return $coupon;
    }

    private function update_wc_coupon($wc_coupon_id, $promo) {
        $coupon = new WC_Coupon($wc_coupon_id);
        if (!$coupon->get_id()) return $this->create_wc_coupon($promo);

        // Update fields
        switch ($promo['discount_type']) {
            case 'percent':
                $coupon->set_discount_type('percent');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'fixed_cart':
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'fixed_product':
                $coupon->set_discount_type('fixed_product');
                $coupon->set_amount($promo['discount_value']);
                break;
            case 'free_shipping':
                $coupon->set_discount_type('percent');
                $coupon->set_amount(0);
                $coupon->set_free_shipping(true);
                break;
        }

        $coupon->set_free_shipping(!empty($promo['free_shipping']));
        $coupon->set_minimum_amount($promo['min_cart_total'] ?? 0);
        $coupon->set_usage_limit($promo['max_uses'] ?? 0);
        $coupon->set_usage_limit_per_user($promo['max_uses_per_user'] ?? 0);
        $coupon->set_exclude_sale_items(!empty($promo['exclude_sale_items']));

        if (!empty($promo['end_date'])) {
            $coupon->set_date_expires(strtotime($promo['end_date']));
        } else {
            $coupon->set_date_expires(null);
        }

        if ($promo['apply_to'] === 'products' && !empty($promo['product_ids'])) {
            $coupon->set_product_ids(array_map('intval', explode(',', $promo['product_ids'])));
        } else {
            $coupon->set_product_ids([]);
        }
        if ($promo['apply_to'] === 'categories' && !empty($promo['category_ids'])) {
            $coupon->set_product_categories(array_map('intval', explode(',', $promo['category_ids'])));
        } else {
            $coupon->set_product_categories([]);
        }

        $coupon->save();
        return $coupon;
    }

    /*--------------------------------------------------------------
    # Scheduled Promo Checking
    --------------------------------------------------------------*/

    public function check_scheduled_promos() {
        global $wpdb;
        $table = $wpdb->prefix . 'rtce_promotions';
        $now = current_time('mysql');

        // Activate scheduled promos that have started
        $to_activate = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'scheduled' AND start_date <= %s",
            $now
        ));

        foreach ($to_activate as $promo) {
            $wpdb->update($table, ['status' => 'active', 'updated_at' => $now], ['id' => $promo->id]);

            // Enable WC coupon
            if ($promo->wc_coupon_id) {
                wp_update_post(['ID' => $promo->wc_coupon_id, 'post_status' => 'publish']);
            }
        }

        // Expire active promos that have ended
        $to_expire = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'active' AND end_date IS NOT NULL AND end_date <= %s",
            $now
        ));

        foreach ($to_expire as $promo) {
            $wpdb->update($table, ['status' => 'expired', 'updated_at' => $now], ['id' => $promo->id]);

            // Trash WC coupon
            if ($promo->wc_coupon_id) {
                wp_update_post(['ID' => $promo->wc_coupon_id, 'post_status' => 'draft']);
            }
        }
    }

    /*--------------------------------------------------------------
    # Auto-Apply Coupons
    --------------------------------------------------------------*/

    public function auto_apply_coupons($cart) {
        if (is_admin() && !wp_doing_ajax()) return;
        if (did_action('woocommerce_before_calculate_totals') > 1) return;

        global $wpdb;
        $table = $wpdb->prefix . 'rtce_promotions';

        $auto_promos = $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 'active' AND auto_apply = 1"
        );

        foreach ($auto_promos as $promo) {
            if (empty($promo->coupon_code)) continue;
            $code = strtolower($promo->coupon_code);

            // Already applied?
            if ($cart->has_discount($code)) continue;

            // Check min cart (rough check before applying)
            if ($promo->min_cart_total > 0) {
                $subtotal = 0;
                foreach ($cart->get_cart() as $item) {
                    $subtotal += $item['line_subtotal'];
                }
                if ($subtotal < $promo->min_cart_total) continue;
            }

            // First time only check
            if ($promo->first_time_only && is_user_logged_in()) {
                $customer_orders = wc_get_customer_order_count(get_current_user_id());
                if ($customer_orders > 0) continue;
            }

            $cart->apply_coupon($code);
        }
    }

    /*--------------------------------------------------------------
    # Announcement Bar
    --------------------------------------------------------------*/

    public function announcement_bar_css() {
        if (is_admin()) return;

        global $wpdb;
        $active = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rtce_promotions WHERE status = 'active' AND show_banner = 1 LIMIT 1"
        );
        if (empty($active)) return;

        $promo = $active[0];
        $settings = get_option('rtce_settings', []);
        $font_size = intval($settings['banner_font_size'] ?? 14);
        $position = $settings['banner_position'] ?? 'top';
        $dismissible = !empty($settings['banner_dismissible']);
        $bg = esc_attr($promo->banner_bg_color ?: '#A2755A');
        $color = esc_attr($promo->banner_text_color ?: '#ffffff');
        ?>
        <style id="rtce-banner-css">
            .rtce-banner {
                background: <?php echo $bg; ?>;
                color: <?php echo $color; ?>;
                text-align: center;
                padding: 12px 40px 12px 20px;
                font-size: <?php echo $font_size; ?>px;
                font-family: 'Poppins', -apple-system, sans-serif;
                font-weight: 600;
                position: relative;
                z-index: 9999;
                line-height: 1.4;
                letter-spacing: 0.3px;
            }
            .rtce-banner a {
                color: <?php echo $color; ?>;
                text-decoration: underline;
                font-weight: 700;
            }
            .rtce-banner-code {
                display: inline-block;
                background: rgba(255,255,255,0.2);
                padding: 2px 10px;
                border-radius: 4px;
                font-family: monospace;
                font-weight: 700;
                letter-spacing: 1px;
                margin: 0 4px;
            }
            .rtce-banner-close {
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                background: none;
                border: none;
                color: <?php echo $color; ?>;
                font-size: 18px;
                cursor: pointer;
                opacity: 0.7;
                padding: 4px 8px;
                line-height: 1;
            }
            .rtce-banner-close:hover { opacity: 1; }
            .rtce-banner.hidden { display: none; }
        </style>
        <?php
    }

    public function announcement_bar_html() {
        if (is_admin()) return;

        global $wpdb;
        $active = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rtce_promotions WHERE status = 'active' AND show_banner = 1 LIMIT 1"
        );
        if (empty($active)) return;

        $promo = $active[0];
        $settings = get_option('rtce_settings', []);
        $dismissible = !empty($settings['banner_dismissible']);
        $text = $promo->banner_text;

        // Replace variables
        $text = str_replace('{code}', '<span class="rtce-banner-code">' . esc_html($promo->coupon_code) . '</span>', $text);
        $text = str_replace('{discount}', esc_html($this->format_discount($promo)), $text);
        $text = str_replace('{end_date}', esc_html($promo->end_date ? date('M j', strtotime($promo->end_date)) : ''), $text);

        $cookie_key = 'rtce_dismiss_' . $promo->id;
        ?>
        <div class="rtce-banner" id="rtce-promo-banner" data-promo="<?php echo $promo->id; ?>">
            <span><?php echo wp_kses_post($text); ?></span>
            <?php if ($dismissible): ?>
                <button class="rtce-banner-close" onclick="this.parentElement.classList.add('hidden');document.cookie='<?php echo $cookie_key; ?>=1;path=/;max-age=86400';">✕</button>
            <?php endif; ?>
        </div>
        <script>
            (function(){
                if (document.cookie.indexOf('<?php echo $cookie_key; ?>=1') !== -1) {
                    var b = document.getElementById('rtce-promo-banner');
                    if (b) b.classList.add('hidden');
                }
            })();
        </script>
        <?php
    }

    private function format_discount($promo) {
        switch ($promo->discount_type) {
            case 'percent': return $promo->discount_value . '%';
            case 'fixed_cart':
            case 'fixed_product': return '$' . number_format($promo->discount_value, 2);
            case 'free_shipping': return 'Free Shipping';
            default: return '';
        }
    }

    /*--------------------------------------------------------------
    # Promo Templates
    --------------------------------------------------------------*/

    public function get_templates() {
        $year = date('Y');
        $next_year = $year + 1;

        return [
            'black_friday' => [
                'name' => "Black Friday {$year}",
                'description' => 'Black Friday sale with sitewide discount',
                'discount_type' => 'percent',
                'discount_value' => 25,
                'start_date' => "{$year}-11-28 00:00:00",
                'end_date' => "{$year}-11-29 23:59:59",
                'banner_text' => '🖤 BLACK FRIDAY — {discount} off everything! Use code {code} at checkout',
                'banner_bg_color' => '#1a1a1a',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'cyber_monday' => [
                'name' => "Cyber Monday {$year}",
                'description' => 'Cyber Monday online sale',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'start_date' => "{$year}-12-01 00:00:00",
                'end_date' => "{$year}-12-01 23:59:59",
                'banner_text' => '💻 CYBER MONDAY — {discount} off sitewide! Code: {code}',
                'banner_bg_color' => '#0066cc',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'christmas' => [
                'name' => "Holiday Sale {$year}",
                'description' => 'Holiday season promotion',
                'discount_type' => 'percent',
                'discount_value' => 15,
                'start_date' => "{$year}-12-10 00:00:00",
                'end_date' => "{$year}-12-23 23:59:59",
                'banner_text' => '🎄 Holiday Sale! {discount} off all orders — Use code {code}. Order by Dec 20 for Christmas delivery!',
                'banner_bg_color' => '#c41e3a',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'new_year' => [
                'name' => "New Year Sale {$next_year}",
                'description' => 'New year new decor',
                'discount_type' => 'percent',
                'discount_value' => 20,
                'start_date' => "{$next_year}-01-01 00:00:00",
                'end_date' => "{$next_year}-01-07 23:59:59",
                'banner_text' => '🎉 New Year Sale! {discount} off everything — Start {$next_year} with new decor! Code: {code}',
                'banner_bg_color' => '#A2755A',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'valentines' => [
                'name' => "Valentine's Day {$next_year}",
                'description' => 'Gift for the sports fan in your life',
                'discount_type' => 'percent',
                'discount_value' => 15,
                'start_date' => "{$next_year}-02-07 00:00:00",
                'end_date' => "{$next_year}-02-14 23:59:59",
                'banner_text' => "❤️ Valentine's Gifts for Sports Fans — {discount} off with code {code}",
                'banner_bg_color' => '#cc3366',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'march_madness' => [
                'name' => "March Madness Sale {$next_year}",
                'description' => 'College basketball tournament sale',
                'discount_type' => 'percent',
                'discount_value' => 15,
                'start_date' => "{$next_year}-03-18 00:00:00",
                'end_date' => "{$next_year}-04-07 23:59:59",
                'banner_text' => '🏀 March Madness Sale! {discount} off all basketball items — Code: {code}',
                'banner_bg_color' => '#ff6600',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
                'apply_to' => 'categories',
            ],
            'fathers_day' => [
                'name' => "Father's Day Sale {$next_year}",
                'description' => 'The perfect gift for dad',
                'discount_type' => 'percent',
                'discount_value' => 15,
                'start_date' => "{$next_year}-06-08 00:00:00",
                'end_date' => "{$next_year}-06-15 23:59:59",
                'banner_text' => "👔 Father's Day Sale — {discount} off the perfect gift for Dad! Use code {code}",
                'banner_bg_color' => '#2c5f2d',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'football_kickoff' => [
                'name' => "Football Season Kickoff {$next_year}",
                'description' => 'Celebrate the start of football season',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'start_date' => "{$next_year}-08-25 00:00:00",
                'end_date' => "{$next_year}-09-10 23:59:59",
                'banner_text' => '🏈 Football is BACK! {discount} off all football items — Code: {code}',
                'banner_bg_color' => '#2d4a22',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'free_shipping' => [
                'name' => 'Free Shipping Weekend',
                'description' => 'Free shipping on all orders',
                'discount_type' => 'free_shipping',
                'discount_value' => 0,
                'banner_text' => '📦 FREE SHIPPING on all orders this weekend! No code needed.',
                'banner_bg_color' => '#A2755A',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 1,
                'free_shipping' => 1,
                'show_banner' => 1,
            ],
            'welcome' => [
                'name' => 'Welcome Discount',
                'description' => 'First-time customer discount',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'first_time_only' => 1,
                'banner_text' => '👋 First order? Get {discount} off with code {code}!',
                'banner_bg_color' => '#A2755A',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'flash_sale' => [
                'name' => '24-Hour Flash Sale',
                'description' => 'One day only — big discount',
                'discount_type' => 'percent',
                'discount_value' => 30,
                'banner_text' => '⚡ FLASH SALE — {discount} off EVERYTHING for 24 hours only! Code: {code}',
                'banner_bg_color' => '#d63638',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 1,
            ],
            'spend_save' => [
                'name' => 'Spend $75, Save $10',
                'description' => 'Spend more, save more',
                'discount_type' => 'fixed_cart',
                'discount_value' => 10,
                'min_cart_total' => 75,
                'banner_text' => '💰 Spend $75+, save $10! Auto-applied at checkout.',
                'banner_bg_color' => '#A2755A',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 1,
                'show_banner' => 1,
            ],
            'local_market' => [
                'name' => 'Local Market — ' . date('F Y'),
                'description' => 'Market pricing for in-person event customers. $20 off per item.',
                'discount_type' => 'fixed_product',
                'discount_value' => 20,
                'start_date' => date('Y-m-d') . ' 00:00:00',
                'end_date' => date('Y-m-d', strtotime('+30 days')) . ' 23:59:59',
                'banner_text' => '',
                'banner_bg_color' => '#A2755A',
                'banner_text_color' => '#ffffff',
                'auto_apply' => 0,
                'show_banner' => 0,
                'max_uses_per_user' => 0,
            ],
        ];
    }

    /*--------------------------------------------------------------
    # Admin
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page(
            'Coupon Engine',
            'Coupon Engine',
            'manage_options',
            'rt-coupon-engine',
            [$this, 'admin_page'],
            'dashicons-tickets-alt',
            62
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-coupon-engine') return;
        wp_enqueue_style('rtce-admin', RTCE_URL . 'admin.css', [], RTCE_VERSION);
        wp_enqueue_script('rtce-admin', RTCE_URL . 'admin.js', ['jquery'], RTCE_VERSION, true);

        // Get categories
        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $categories = [];
        foreach ($cats as $cat) {
            $categories[] = ['id' => $cat->term_id, 'name' => $cat->name];
        }

        wp_localize_script('rtce-admin', 'rtce', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtce_nonce'),
            'categories' => $categories,
            'templates' => array_keys($this->get_templates()),
            'template_labels' => [
                'black_friday' => '🖤 Black Friday',
                'cyber_monday' => '💻 Cyber Monday',
                'christmas' => '🎄 Holiday Sale',
                'new_year' => '🎉 New Year Sale',
                'valentines' => "❤️ Valentine's Day",
                'march_madness' => '🏀 March Madness',
                'fathers_day' => "👔 Father's Day",
                'football_kickoff' => '🏈 Football Kickoff',
                'free_shipping' => '📦 Free Shipping',
                'welcome' => '👋 Welcome Discount',
                'flash_sale' => '⚡ Flash Sale',
                'spend_save' => '💰 Spend & Save',
                'local_market' => '🏪 Local Market',
            ],
        ]);
    }

    public function admin_page() {
        include RTCE_PATH . 'admin-page.php';
    }

    /*--------------------------------------------------------------
    # AJAX Handlers
    --------------------------------------------------------------*/

    public function ajax_save_promo() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $table = $wpdb->prefix . 'rtce_promotions';

        $id = intval($_POST['id'] ?? 0);
        $now = current_time('mysql');

        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'discount_type' => sanitize_text_field($_POST['discount_type'] ?? 'percent'),
            'discount_value' => floatval($_POST['discount_value'] ?? 0),
            'coupon_code' => strtoupper(sanitize_text_field($_POST['coupon_code'] ?? '')),
            'start_date' => sanitize_text_field($_POST['start_date'] ?? '') ?: null,
            'end_date' => sanitize_text_field($_POST['end_date'] ?? '') ?: null,
            'min_cart_total' => floatval($_POST['min_cart_total'] ?? 0),
            'max_uses' => intval($_POST['max_uses'] ?? 0),
            'max_uses_per_user' => intval($_POST['max_uses_per_user'] ?? 0),
            'first_time_only' => intval($_POST['first_time_only'] ?? 0),
            'free_shipping' => intval($_POST['free_shipping'] ?? 0),
            'exclude_sale_items' => intval($_POST['exclude_sale_items'] ?? 0),
            'apply_to' => sanitize_text_field($_POST['apply_to'] ?? 'all'),
            'product_ids' => sanitize_text_field($_POST['product_ids'] ?? ''),
            'category_ids' => sanitize_text_field($_POST['category_ids'] ?? ''),
            'show_banner' => intval($_POST['show_banner'] ?? 0),
            'banner_text' => sanitize_text_field($_POST['banner_text'] ?? ''),
            'banner_bg_color' => sanitize_hex_color($_POST['banner_bg_color'] ?? '#A2755A'),
            'banner_text_color' => sanitize_hex_color($_POST['banner_text_color'] ?? '#ffffff'),
            'auto_apply' => intval($_POST['auto_apply'] ?? 0),
            'updated_at' => $now,
        ];

        // Determine status
        $status = sanitize_text_field($_POST['status'] ?? 'draft');
        if ($status === 'active' && !empty($data['start_date']) && strtotime($data['start_date']) > time()) {
            $status = 'scheduled';
        }
        $data['status'] = $status;

        if ($id > 0) {
            // Update
            $wpdb->update($table, $data, ['id' => $id]);
            $promo_id = $id;
        } else {
            // Insert
            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
            $promo_id = $wpdb->insert_id;
        }

        // Create/update WC coupon
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $promo_id));
        if ($existing) {
            $promo_arr = (array) $existing;

            if ($existing->wc_coupon_id) {
                $coupon = $this->update_wc_coupon($existing->wc_coupon_id, $promo_arr);
            } else {
                $coupon = $this->create_wc_coupon($promo_arr);
                $wpdb->update($table, [
                    'wc_coupon_id' => $coupon->get_id(),
                    'coupon_code' => $coupon->get_code(),
                ], ['id' => $promo_id]);
            }

            // Set WC coupon status based on promo status
            if ($status === 'active') {
                wp_update_post(['ID' => $coupon->get_id(), 'post_status' => 'publish']);
            } else {
                wp_update_post(['ID' => $coupon->get_id(), 'post_status' => 'draft']);
            }
        }

        wp_send_json_success([
            'id' => $promo_id,
            'message' => $id ? 'Promo updated!' : 'Promo created!',
        ]);
    }

    public function ajax_delete_promo() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Invalid promo');

        // Delete WC coupon
        $promo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rtce_promotions WHERE id = %d", $id));
        if ($promo && $promo->wc_coupon_id) {
            wp_delete_post($promo->wc_coupon_id, true);
        }

        $wpdb->delete($wpdb->prefix . 'rtce_promotions', ['id' => $id]);
        wp_send_json_success('Deleted');
    }

    public function ajax_toggle_promo() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $table = $wpdb->prefix . 'rtce_promotions';
        $id = intval($_POST['id'] ?? 0);
        $new_status = sanitize_text_field($_POST['status'] ?? 'draft');
        if (!$id) wp_send_json_error('Invalid promo');

        $wpdb->update($table, ['status' => $new_status, 'updated_at' => current_time('mysql')], ['id' => $id]);

        // Update WC coupon status
        $promo = $wpdb->get_row($wpdb->prepare("SELECT wc_coupon_id FROM $table WHERE id = %d", $id));
        if ($promo && $promo->wc_coupon_id) {
            $wc_status = ($new_status === 'active') ? 'publish' : 'draft';
            wp_update_post(['ID' => $promo->wc_coupon_id, 'post_status' => $wc_status]);
        }

        wp_send_json_success($new_status);
    }

    public function ajax_get_promos() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $promos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rtce_promotions ORDER BY FIELD(status, 'active', 'scheduled', 'draft', 'expired'), created_at DESC");

        wp_send_json_success($promos);
    }

    public function ajax_get_promo() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $promo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rtce_promotions WHERE id = %d", $id));

        if (!$promo) wp_send_json_error('Not found');
        wp_send_json_success($promo);
    }

    public function ajax_get_stats() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $table = $wpdb->prefix . 'rtce_promotions';

        $stats = [
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active'"),
            'scheduled' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'scheduled'"),
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'total_uses' => (int) $wpdb->get_var("SELECT SUM(times_used) FROM $table"),
            'total_revenue' => (float) $wpdb->get_var("SELECT SUM(revenue_generated) FROM $table"),
        ];

        wp_send_json_success($stats);
    }

    public function ajax_save_settings() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        parse_str($_POST['settings'] ?? '', $data);
        $data['banner_dismissible'] = isset($data['banner_dismissible']) ? 1 : 0;
        $data['auto_generate_codes'] = isset($data['auto_generate_codes']) ? 1 : 0;
        $data['track_revenue'] = isset($data['track_revenue']) ? 1 : 0;

        update_option('rtce_settings', $data);
        wp_send_json_success('Settings saved');
    }

    public function ajax_load_template() {
        check_ajax_referer('rtce_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $key = sanitize_text_field($_POST['template'] ?? '');
        $templates = $this->get_templates();

        if (empty($templates[$key])) wp_send_json_error('Template not found');
        wp_send_json_success($templates[$key]);
    }
}

RT_Coupon_Engine::get_instance();
