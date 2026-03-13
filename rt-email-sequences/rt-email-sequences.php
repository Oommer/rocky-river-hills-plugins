<?php
/**
 * Plugin Name: RT Email Sequences
 * Description: Automated email sequences for WooCommerce - abandoned cart recovery, post-purchase follow-ups, review requests, cross-sell recommendations, and more.
 * Version: 1.3.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-email-sequences
 */

if (!defined('ABSPATH')) exit;

define('RTES_VERSION', '1.3.0');
define('RTES_PATH', plugin_dir_path(__FILE__));
define('RTES_URL', plugin_dir_url(__FILE__));

class RT_Email_Sequences {

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

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_rtes_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtes_send_test', [$this, 'ajax_send_test']);
        add_action('wp_ajax_rtes_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtes_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_rtes_reset_stats', [$this, 'ajax_reset_stats']);
        add_action('wp_ajax_rtes_reset_defaults', [$this, 'ajax_reset_defaults']);
        add_action('wp_ajax_nopriv_rtes_unsubscribe', [$this, 'handle_unsubscribe']);
        add_action('wp_ajax_rtes_unsubscribe', [$this, 'handle_unsubscribe']);

        // WooCommerce hooks
        add_action('woocommerce_cart_updated', [$this, 'track_cart']);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_order_placed']);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed']);
        add_action('woocommerce_new_order', [$this, 'clear_abandoned_cart']);

        // Cron hooks
        add_action('rtes_check_abandoned_carts', [$this, 'process_abandoned_carts']);
        add_action('rtes_send_scheduled_emails', [$this, 'process_scheduled_emails']);
        add_action('rtes_daily_cleanup', [$this, 'daily_cleanup']);

        // Unsubscribe endpoint
        add_action('template_redirect', [$this, 'check_unsubscribe_link']);

        // Track email opens
        add_action('template_redirect', [$this, 'track_email_open']);

        // Recovery link handler (restores cart from email click)
        add_action('template_redirect', [$this, 'handle_recovery_link']);

        // Early email capture on checkout (before form submit)
        add_action('wp_ajax_rtes_capture_email', [$this, 'ajax_capture_email']);
        add_action('wp_ajax_nopriv_rtes_capture_email', [$this, 'ajax_capture_email']);
        add_action('wp_footer', [$this, 'email_capture_script']);
    }

    /*--------------------------------------------------------------
    # Default Settings
    --------------------------------------------------------------*/

    public function get_defaults() {
        return [
            'enabled' => 1,
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'brand_color' => '#A2755A',
            'brand_color_secondary' => '#e9e9e9',
            'logo_url' => 'https://rockyriverhills.com/wp-content/uploads/2025/11/cropped-Rocky-River-Hills-Logo-Transparent-Large-Canvas-1.gif',
            // Abandoned cart
            'abandoned_enabled' => 1,
            'abandoned_timeout' => 60,
            'abandoned_email_1_delay' => 1,
            'abandoned_email_1_subject' => 'You left something behind!',
            'abandoned_email_1_heading' => 'Forget Something?',
            'abandoned_email_1_body' => "Hey there!\n\nLooks like you left some items in your cart. No worries - they're still waiting for you.",
            'abandoned_email_1_cta' => 'Complete Your Order',
            'abandoned_email_1_coupon' => '',
            'abandoned_email_2_enabled' => 1,
            'abandoned_email_2_delay' => 24,
            'abandoned_email_2_subject' => 'Your cart is about to expire',
            'abandoned_email_2_heading' => 'Still Thinking It Over?',
            'abandoned_email_2_body' => "We noticed you haven't completed your order yet. Your items are still available, but they won't last forever!",
            'abandoned_email_2_cta' => 'Return to Cart',
            'abandoned_email_2_coupon' => '',
            'abandoned_email_3_enabled' => 0,
            'abandoned_email_3_delay' => 72,
            'abandoned_email_3_subject' => 'Last chance - special offer inside!',
            'abandoned_email_3_heading' => 'A Little Something for You',
            'abandoned_email_3_body' => "We'd hate for you to miss out! Here's an exclusive discount to help you complete your purchase.",
            'abandoned_email_3_cta' => 'Claim Your Discount',
            'abandoned_email_3_coupon' => '',
            // Post-purchase
            'thankyou_enabled' => 1,
            'thankyou_delay' => 0,
            'thankyou_subject' => 'Thanks for your order, {first_name}!',
            'thankyou_heading' => 'Order Confirmed!',
            'thankyou_body' => "Thank you for shopping with us! We're preparing your order and will have it on its way soon.\n\nWe put a lot of care into every piece we create, and we hope you love it as much as we do.",
            // Review request
            'review_enabled' => 1,
            'review_delay_days' => 14,
            'review_subject' => 'How are you liking your new {product_name}?',
            'review_heading' => "We'd Love Your Feedback",
            'review_body' => "Hey {first_name}!\n\nYou've had your {product_name} for a bit now - we'd love to hear what you think! Your review helps other fans find the perfect piece for their space.",
            'review_cta' => 'Leave a Review',
            // Cross-sell
            'crosssell_enabled' => 1,
            'crosssell_delay_days' => 7,
            'crosssell_subject' => 'More picks you might love',
            'crosssell_heading' => 'Curated Just for You',
            'crosssell_body' => "Since you loved your {product_name}, we thought you might like these other pieces from our collection.",
            'crosssell_max_products' => 4,
            // Welcome
            'welcome_enabled' => 1,
            'welcome_subject' => 'Welcome to {site_name}!',
            'welcome_heading' => 'Welcome to the Family!',
            'welcome_body' => "Thanks for creating an account with us! We're thrilled to have you.\n\nBrowse our collection of stadium-inspired coasters and wall art - perfect for your game room, office, or as a gift for the biggest fan you know.",
            'welcome_cta' => 'Shop Now',
            'welcome_coupon' => '',
            // Auto-coupon for abandoned cart recovery
            'auto_coupon_enabled' => 1,
            'auto_coupon_amount' => 10,
            'auto_coupon_type' => 'percent',
            'auto_coupon_expiry_days' => 7,
        ];
    }

    /*--------------------------------------------------------------
    # Activation / Deactivation
    --------------------------------------------------------------*/

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Abandoned carts table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtes_abandoned_carts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            email VARCHAR(200) DEFAULT '',
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10,2) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            recovered TINYINT(1) DEFAULT 0,
            emails_sent INT DEFAULT 0,
            last_email_at DATETIME DEFAULT NULL,
            recovery_token VARCHAR(64) DEFAULT '',
            coupon_code VARCHAR(50) DEFAULT '',
            order_id BIGINT UNSIGNED DEFAULT 0,
            recovered_at DATETIME DEFAULT NULL,
            INDEX idx_session (session_id),
            INDEX idx_email (email),
            INDEX idx_recovered (recovered),
            INDEX idx_recovery_token (recovery_token)
        ) $charset");

        // Migration: add new columns to existing table if upgrading
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}rtes_abandoned_carts", 0);
        if ($cols && !in_array('recovery_token', $cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_abandoned_carts ADD COLUMN recovery_token VARCHAR(64) DEFAULT '' AFTER last_email_at");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_abandoned_carts ADD COLUMN coupon_code VARCHAR(50) DEFAULT '' AFTER recovery_token");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_abandoned_carts ADD COLUMN order_id BIGINT UNSIGNED DEFAULT 0 AFTER coupon_code");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_abandoned_carts ADD COLUMN recovered_at DATETIME DEFAULT NULL AFTER order_id");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_abandoned_carts ADD INDEX idx_recovery_token (recovery_token)");
        }

        // Email log table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtes_email_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_to VARCHAR(200) NOT NULL,
            email_type VARCHAR(50) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            sent_at DATETIME NOT NULL,
            opened TINYINT(1) DEFAULT 0,
            opened_at DATETIME DEFAULT NULL,
            clicked TINYINT(1) DEFAULT 0,
            order_id BIGINT UNSIGNED DEFAULT 0,
            cart_id BIGINT UNSIGNED DEFAULT 0,
            INDEX idx_type (email_type),
            INDEX idx_sent (sent_at)
        ) $charset");

        // Migration: add clicked column if upgrading
        $log_cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}rtes_email_log", 0);
        if ($log_cols && !in_array('clicked', $log_cols)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}rtes_email_log ADD COLUMN clicked TINYINT(1) DEFAULT 0 AFTER opened_at");
        }

        // Scheduled emails table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtes_scheduled_emails (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_to VARCHAR(200) NOT NULL,
            email_type VARCHAR(50) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT 0,
            cart_id BIGINT UNSIGNED DEFAULT 0,
            scheduled_at DATETIME NOT NULL,
            sent TINYINT(1) DEFAULT 0,
            data LONGTEXT DEFAULT '',
            INDEX idx_scheduled (scheduled_at, sent)
        ) $charset");

        // Unsubscribes table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtes_unsubscribes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(200) NOT NULL UNIQUE,
            unsubscribed_at DATETIME NOT NULL,
            INDEX idx_email (email)
        ) $charset");

        // Default settings
        $defaults = $this->get_defaults();

        // Always merge defaults with existing settings (fills in missing keys)
        $existing = get_option('rtes_settings', []);
        if (!is_array($existing)) $existing = [];
        $merged = wp_parse_args($existing, $defaults);
        update_option('rtes_settings', $merged);

        // Schedule cron events
        if (!wp_next_scheduled('rtes_check_abandoned_carts')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'rtes_check_abandoned_carts');
        }
        if (!wp_next_scheduled('rtes_send_scheduled_emails')) {
            wp_schedule_event(time(), 'every_five_minutes', 'rtes_send_scheduled_emails');
        }
        if (!wp_next_scheduled('rtes_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'rtes_daily_cleanup');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rtes_check_abandoned_carts');
        wp_clear_scheduled_hook('rtes_send_scheduled_emails');
        wp_clear_scheduled_hook('rtes_daily_cleanup');
    }

    public function init() {
        // Add custom cron intervals
        add_filter('cron_schedules', function($schedules) {
            $schedules['every_five_minutes'] = [
                'interval' => 300,
                'display' => 'Every 5 Minutes'
            ];
            $schedules['every_fifteen_minutes'] = [
                'interval' => 900,
                'display' => 'Every 15 Minutes'
            ];
            return $schedules;
        });

        // Track new account creation for welcome email
        add_action('user_register', [$this, 'on_user_register']);

        // Self-heal: ensure all default keys exist in settings
        $settings = get_option('rtes_settings', []);
        if (!is_array($settings)) $settings = [];
        $defaults = $this->get_defaults();
        $merged = wp_parse_args($settings, $defaults);
        if ($merged !== $settings) {
            update_option('rtes_settings', $merged);
        }
    }

    /*--------------------------------------------------------------
    # Cart Tracking
    --------------------------------------------------------------*/

    public function track_cart() {
        if (is_admin() || !WC()->cart || WC()->cart->is_empty()) return;

        $session_id = WC()->session ? WC()->session->get_customer_id() : '';
        if (empty($session_id)) return;

        $user_id = get_current_user_id();
        $email = '';

        if ($user_id) {
            $user = get_userdata($user_id);
            $email = $user->user_email;
        } elseif (WC()->session) {
            $customer = WC()->session->get('customer');
            $email = !empty($customer['email']) ? $customer['email'] : '';
        }

        $cart_contents = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            $cart_contents[] = [
                'product_id' => $item['product_id'],
                'variation_id' => $item['variation_id'] ?? 0,
                'name' => $product->get_name(),
                'quantity' => $item['quantity'],
                'price' => $product->get_price(),
                'image' => wp_get_attachment_url($product->get_image_id()),
                'permalink' => $product->get_permalink(),
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rtes_abandoned_carts';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE session_id = %s AND recovered = 0",
            $session_id
        ));

        $data = [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'email' => $email,
            'cart_contents' => wp_json_encode($cart_contents),
            'cart_total' => WC()->cart->get_total('edit'),
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing->id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $data['recovery_token'] = wp_generate_password(32, false);
            $wpdb->insert($table, $data);
        }
    }

    public function clear_abandoned_cart($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        global $wpdb;
        $table = $wpdb->prefix . 'rtes_abandoned_carts';

        // Mark as recovered by email or user_id
        $email = $order->get_billing_email();
        $user_id = $order->get_user_id();

        if ($email) {
            $wpdb->update($table, [
                'recovered' => 1,
                'order_id' => $order_id,
                'recovered_at' => current_time('mysql'),
            ], ['email' => $email, 'recovered' => 0]);
        }
        if ($user_id) {
            $wpdb->update($table, [
                'recovered' => 1,
                'order_id' => $order_id,
                'recovered_at' => current_time('mysql'),
            ], ['user_id' => $user_id, 'recovered' => 0]);
        }
    }

    /*--------------------------------------------------------------
    # Early Email Capture (grabs email before form submit)
    --------------------------------------------------------------*/

    public function ajax_capture_email() {
        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email || !is_email($email)) { wp_send_json_error(); return; }

        $session_id = WC()->session ? WC()->session->get_customer_id() : '';
        if (empty($session_id)) { wp_send_json_error(); return; }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rtes_abandoned_carts',
            ['email' => $email],
            ['session_id' => $session_id, 'recovered' => 0]
        );
        wp_send_json_success();
    }

    public function email_capture_script() {
        if (!is_checkout() || is_order_received_page()) return;
        ?>
        <script>
        (function(){
            var captured = false;
            var field = document.getElementById('billing_email');
            if (!field) return;
            function captureEmail() {
                var val = field.value.trim();
                if (val && val.indexOf('@') > 0 && !captured) {
                    captured = true;
                    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=rtes_capture_email&email=' + encodeURIComponent(val)
                    }).then(function(){ captured = false; });
                }
            }
            field.addEventListener('blur', captureEmail);
            field.addEventListener('change', captureEmail);
        })();
        </script>
        <?php
    }

    /*--------------------------------------------------------------
    # Recovery Link Handler (restores cart from email click)
    --------------------------------------------------------------*/

    public function handle_recovery_link() {
        if (!isset($_GET['rtes_recover']) || !class_exists('WooCommerce')) return;

        global $wpdb;
        $token = sanitize_text_field($_GET['rtes_recover']);
        $table = $wpdb->prefix . 'rtes_abandoned_carts';

        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE recovery_token = %s LIMIT 1",
            $token
        ));

        if (!$cart || !$cart->cart_contents) {
            wp_redirect(wc_get_cart_url());
            exit;
        }

        // Log the click in email log
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}rtes_email_log SET clicked = 1 WHERE cart_id = %d AND clicked = 0 ORDER BY sent_at DESC LIMIT 1",
            $cart->id
        ));

        // Restore cart contents
        WC()->cart->empty_cart();
        $items = json_decode($cart->cart_contents, true);
        if ($items) {
            foreach ($items as $item) {
                $pid = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];
                WC()->cart->add_to_cart($pid, $item['quantity']);
            }
        }

        // Apply coupon if one was generated
        if (!empty($cart->coupon_code)) {
            WC()->cart->apply_coupon($cart->coupon_code);
        }

        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /*--------------------------------------------------------------
    # Auto-Coupon Generation
    --------------------------------------------------------------*/

    private function generate_auto_coupon($cart, $settings) {
        $amount = floatval($settings['auto_coupon_amount'] ?? 10);
        $type = ($settings['auto_coupon_type'] ?? 'percent') === 'percent' ? 'percent' : 'fixed_cart';
        $expiry_days = intval($settings['auto_coupon_expiry_days'] ?? 7);

        $code = 'COMEBACK-' . strtoupper(substr(md5($cart->id . time()), 0, 6));

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_amount($amount);
        $coupon->set_discount_type($type);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_email_restrictions([$cart->email]);
        if ($expiry_days > 0) {
            $coupon->set_date_expires(strtotime("+{$expiry_days} days"));
        }
        $coupon->save();

        // Store the coupon code on the cart record
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rtes_abandoned_carts',
            ['coupon_code' => $code],
            ['id' => $cart->id]
        );

        return $code;
    }

    /*--------------------------------------------------------------
    # Abandoned Cart Processing
    --------------------------------------------------------------*/

    public function process_abandoned_carts() {
        $settings = get_option('rtes_settings', []);
        if (empty($settings['abandoned_enabled'])) return;

        global $wpdb;
        $table = $wpdb->prefix . 'rtes_abandoned_carts';
        $timeout = intval($settings['abandoned_timeout'] ?? 60);

        // Find carts that haven't been updated in X minutes, have an email, and aren't recovered
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$timeout} minutes"));
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE recovered = 0 AND email != '' AND updated_at < %s ORDER BY updated_at ASC LIMIT 50",
            $cutoff
        ));

        foreach ($carts as $cart) {
            if ($this->is_unsubscribed($cart->email)) continue;

            $emails_sent = intval($cart->emails_sent);
            $send_email = false;
            $email_num = 0;

            // Determine which email to send based on how many have been sent
            for ($i = $emails_sent + 1; $i <= 3; $i++) {
                if ($i === 1) {
                    $delay_hours = intval($settings['abandoned_email_1_delay'] ?? 1);
                    $send_email = true;
                    $email_num = 1;
                    break;
                }
                if ($i === 2 && !empty($settings['abandoned_email_2_enabled'])) {
                    $delay_hours = intval($settings['abandoned_email_2_delay'] ?? 24);
                    $send_email = true;
                    $email_num = 2;
                    break;
                }
                if ($i === 3 && !empty($settings['abandoned_email_3_enabled'])) {
                    $delay_hours = intval($settings['abandoned_email_3_delay'] ?? 72);
                    $send_email = true;
                    $email_num = 3;
                    break;
                }
            }

            if (!$send_email) continue;

            // Check if enough time has passed since last email (or since abandonment for first email)
            $reference_time = $cart->last_email_at ? $cart->last_email_at : $cart->updated_at;
            $hours_since = (time() - strtotime($reference_time)) / 3600;

            $delay_hours_key = "abandoned_email_{$email_num}_delay";
            $required_delay = $email_num === 1 
                ? intval($settings[$delay_hours_key] ?? 1)
                : intval($settings[$delay_hours_key] ?? 24) - intval($settings['abandoned_email_' . ($email_num - 1) . '_delay'] ?? 1);

            // For first email, delay from abandonment; for subsequent, delay from last email
            if ($email_num === 1) {
                $required_delay = intval($settings['abandoned_email_1_delay'] ?? 1);
                $hours_since = (time() - strtotime($cart->updated_at)) / 3600;
            } else {
                $required_delay = intval($settings["abandoned_email_{$email_num}_delay"] ?? 24);
                $hours_since_abandon = (time() - strtotime($cart->updated_at)) / 3600;
                if ($hours_since_abandon < $required_delay) continue;
            }

            if ($email_num === 1 && $hours_since < $required_delay) continue;

            // Build and send the email
            $cart_items = json_decode($cart->cart_contents, true);
            $subject = $settings["abandoned_email_{$email_num}_subject"] ?? 'You left something behind!';
            $heading = $settings["abandoned_email_{$email_num}_heading"] ?? '';
            $body = $settings["abandoned_email_{$email_num}_body"] ?? '';
            $cta_text = $settings["abandoned_email_{$email_num}_cta"] ?? 'Complete Your Order';
            $coupon = $settings["abandoned_email_{$email_num}_coupon"] ?? '';

            // Auto-generate coupon if setting is 'auto'
            if ($coupon === 'auto' && !empty($settings['auto_coupon_enabled'])) {
                $coupon = $this->generate_auto_coupon($cart, $settings);
            }

            // Build recovery URL — restores exact cart contents on click
            $recovery_url = wc_get_cart_url();
            if (!empty($cart->recovery_token)) {
                $recovery_url = add_query_arg('rtes_recover', $cart->recovery_token, home_url('/'));
            } elseif ($coupon && $coupon !== 'auto') {
                $recovery_url = add_query_arg('rtes_coupon', $coupon, $recovery_url);
            }

            // Generate recovery token if missing (upgrade path)
            if (empty($cart->recovery_token)) {
                $token = wp_generate_password(32, false);
                $wpdb->update($table, ['recovery_token' => $token], ['id' => $cart->id]);
                $recovery_url = add_query_arg('rtes_recover', $token, home_url('/'));
            }

            $html = $this->build_email_html([
                'heading' => $heading,
                'body' => $body,
                'cta_text' => $cta_text,
                'cta_url' => $recovery_url,
                'cart_items' => $cart_items,
                'cart_total' => $cart->cart_total,
                'coupon' => $coupon,
                'email' => $cart->email,
                'type' => 'abandoned_cart',
                'cart_id' => $cart->id,
            ]);

            $sent = $this->send_email($cart->email, $subject, $html);

            if ($sent) {
                $wpdb->update(
                    $table,
                    [
                        'emails_sent' => $emails_sent + 1,
                        'last_email_at' => current_time('mysql'),
                    ],
                    ['id' => $cart->id]
                );

                $this->log_email($cart->email, 'abandoned_cart_' . $email_num, $subject, 0, $cart->id);
            }
        }
    }

    /*--------------------------------------------------------------
    # Order Event Handlers
    --------------------------------------------------------------*/

    public function on_order_placed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $settings = get_option('rtes_settings', []);
        $email = $order->get_billing_email();

        // Schedule cross-sell email
        if (!empty($settings['crosssell_enabled'])) {
            $delay_days = intval($settings['crosssell_delay_days'] ?? 7);
            $this->schedule_email($email, 'crosssell', $order_id, $delay_days * 24);
        }
    }

    public function on_order_completed($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $settings = get_option('rtes_settings', []);
        $email = $order->get_billing_email();

        // Send thank you email
        if (!empty($settings['thankyou_enabled'])) {
            $delay = intval($settings['thankyou_delay'] ?? 0);
            if ($delay === 0) {
                $this->send_thankyou_email($order);
            } else {
                $this->schedule_email($email, 'thankyou', $order_id, $delay);
            }
        }

        // Schedule review request
        if (!empty($settings['review_enabled'])) {
            $delay_days = intval($settings['review_delay_days'] ?? 14);
            $this->schedule_email($email, 'review_request', $order_id, $delay_days * 24);
        }
    }

    public function on_user_register($user_id) {
        $settings = get_option('rtes_settings', []);
        if (empty($settings['welcome_enabled'])) return;

        $user = get_userdata($user_id);
        if (!$user) return;

        $this->schedule_email($user->user_email, 'welcome', 0, 0);
    }

    /*--------------------------------------------------------------
    # Email Builders
    --------------------------------------------------------------*/

    private function send_thankyou_email($order) {
        $settings = get_option('rtes_settings', []);
        $email = $order->get_billing_email();

        if ($this->is_unsubscribed($email)) return;

        $first_name = $order->get_billing_first_name();
        $items = $order->get_items();
        $product_names = [];
        foreach ($items as $item) {
            $product_names[] = $item->get_name();
        }

        $subject = str_replace(
            ['{first_name}', '{order_number}', '{site_name}'],
            [$first_name, $order->get_order_number(), get_bloginfo('name')],
            $settings['thankyou_subject']
        );

        $body = str_replace(
            ['{first_name}', '{order_number}', '{product_name}'],
            [$first_name, $order->get_order_number(), $product_names[0] ?? ''],
            $settings['thankyou_body']
        );

        $html = $this->build_email_html([
            'heading' => $settings['thankyou_heading'],
            'body' => $body,
            'cta_text' => 'View Your Order',
            'cta_url' => $order->get_view_order_url(),
            'email' => $email,
            'type' => 'thankyou',
        ]);

        $sent = $this->send_email($email, $subject, $html);
        if ($sent) {
            $this->log_email($email, 'thankyou', $subject, $order->get_id());
        }
    }

    private function send_review_request($order_id, $email) {
        $settings = get_option('rtes_settings', []);
        $order = wc_get_order($order_id);
        if (!$order || $this->is_unsubscribed($email)) return;

        $first_name = $order->get_billing_first_name();
        $items = $order->get_items();

        // Get products for review links
        $products = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product) {
                $products[] = [
                    'name' => $product->get_name(),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'review_url' => $product->get_permalink() . '#reviews',
                    'permalink' => $product->get_permalink(),
                ];
            }
        }

        $product_name = $products[0]['name'] ?? 'purchase';

        $subject = str_replace(
            ['{first_name}', '{product_name}', '{site_name}'],
            [$first_name, $product_name, get_bloginfo('name')],
            $settings['review_subject']
        );

        $body = str_replace(
            ['{first_name}', '{product_name}'],
            [$first_name, $product_name],
            $settings['review_body']
        );

        $html = $this->build_email_html([
            'heading' => $settings['review_heading'],
            'body' => $body,
            'cta_text' => $settings['review_cta'],
            'cta_url' => $products[0]['review_url'] ?? get_home_url(),
            'products' => $products,
            'email' => $email,
            'type' => 'review_request',
        ]);

        $sent = $this->send_email($email, $subject, $html);
        if ($sent) {
            $this->log_email($email, 'review_request', $subject, $order_id);
        }
    }

    private function send_crosssell_email($order_id, $email) {
        $settings = get_option('rtes_settings', []);
        $order = wc_get_order($order_id);
        if (!$order || $this->is_unsubscribed($email)) return;

        $first_name = $order->get_billing_first_name();
        $items = $order->get_items();
        $purchased_ids = [];
        $categories = [];

        foreach ($items as $item) {
            $purchased_ids[] = $item->get_product_id();
            $terms = get_the_terms($item->get_product_id(), 'product_cat');
            if ($terms) {
                foreach ($terms as $term) {
                    $categories[$term->term_id] = $term->name;
                }
            }
        }

        // Find related products from same categories
        $max = intval($settings['crosssell_max_products'] ?? 4);
        $related_products = [];

        if (!empty($categories)) {
            $args = [
                'post_type' => 'product',
                'posts_per_page' => $max,
                'post__not_in' => $purchased_ids,
                'tax_query' => [
                    [
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => array_keys($categories),
                    ]
                ],
                'orderby' => 'rand',
            ];

            $query = new WP_Query($args);
            foreach ($query->posts as $post) {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $related_products[] = [
                        'name' => $product->get_name(),
                        'price' => wc_price($product->get_price()),
                        'image' => wp_get_attachment_url($product->get_image_id()),
                        'permalink' => $product->get_permalink(),
                    ];
                }
            }
        }

        if (empty($related_products)) return;

        $product_name = '';
        foreach ($items as $item) {
            $product_name = $item->get_name();
            break;
        }

        $subject = str_replace(
            ['{first_name}', '{product_name}', '{site_name}'],
            [$first_name, $product_name, get_bloginfo('name')],
            $settings['crosssell_subject']
        );

        $body = str_replace(
            ['{first_name}', '{product_name}'],
            [$first_name, $product_name],
            $settings['crosssell_body']
        );

        $html = $this->build_email_html([
            'heading' => $settings['crosssell_heading'],
            'body' => $body,
            'products' => $related_products,
            'cta_text' => 'Shop Now',
            'cta_url' => get_permalink(wc_get_page_id('shop')),
            'email' => $email,
            'type' => 'crosssell',
        ]);

        $sent = $this->send_email($email, $subject, $html);
        if ($sent) {
            $this->log_email($email, 'crosssell', $subject, $order_id);
        }
    }

    private function send_welcome_email($email) {
        $settings = get_option('rtes_settings', []);
        if ($this->is_unsubscribed($email)) return;

        $subject = str_replace('{site_name}', get_bloginfo('name'), $settings['welcome_subject']);
        $body = str_replace('{site_name}', get_bloginfo('name'), $settings['welcome_body']);
        $coupon = $settings['welcome_coupon'] ?? '';

        $shop_url = get_permalink(wc_get_page_id('shop'));
        if ($coupon) {
            $shop_url = add_query_arg('rtes_coupon', $coupon, $shop_url);
        }

        $html = $this->build_email_html([
            'heading' => $settings['welcome_heading'],
            'body' => $body,
            'cta_text' => $settings['welcome_cta'],
            'cta_url' => $shop_url,
            'coupon' => $coupon,
            'email' => $email,
            'type' => 'welcome',
        ]);

        $sent = $this->send_email($email, $subject, $html);
        if ($sent) {
            $this->log_email($email, 'welcome', $subject);
        }
    }

    /*--------------------------------------------------------------
    # Scheduled Email Processing
    --------------------------------------------------------------*/

    private function schedule_email($email, $type, $order_id, $delay_hours) {
        global $wpdb;
        $scheduled_at = date('Y-m-d H:i:s', strtotime("+{$delay_hours} hours"));

        $wpdb->insert($wpdb->prefix . 'rtes_scheduled_emails', [
            'email_to' => $email,
            'email_type' => $type,
            'order_id' => $order_id,
            'scheduled_at' => $scheduled_at,
            'sent' => 0,
        ]);
    }

    public function process_scheduled_emails() {
        global $wpdb;
        $table = $wpdb->prefix . 'rtes_scheduled_emails';
        $now = current_time('mysql');

        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE sent = 0 AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 20",
            $now
        ));

        foreach ($emails as $item) {
            switch ($item->email_type) {
                case 'thankyou':
                    $order = wc_get_order($item->order_id);
                    if ($order) $this->send_thankyou_email($order);
                    break;
                case 'review_request':
                    $this->send_review_request($item->order_id, $item->email_to);
                    break;
                case 'crosssell':
                    $this->send_crosssell_email($item->order_id, $item->email_to);
                    break;
                case 'welcome':
                    $this->send_welcome_email($item->email_to);
                    break;
            }

            $wpdb->update($table, ['sent' => 1], ['id' => $item->id]);
        }
    }


    /*--------------------------------------------------------------
    # Email HTML Template
    --------------------------------------------------------------*/

    private function build_email_html($args) {
        $settings = get_option('rtes_settings', []);
        $brand = $settings['brand_color'] ?? '#A2755A';
        $brand_light = $settings['brand_color_secondary'] ?? '#e9e9e9';
        $site_name = get_bloginfo('name');
        $logo_url = $settings['logo_url'] ?? '';
        $site_url = home_url();
        $unsubscribe_url = add_query_arg([
            'rtes_unsubscribe' => 1,
            'email' => urlencode($args['email'] ?? ''),
            'token' => wp_hash($args['email'] ?? ''),
        ], home_url());

        $font = "Poppins, Helvetica, Arial, sans-serif";

        // Open tracking pixel
        $open_pixel = '';
        if (!empty($args['type'])) {
            $pixel_url = add_query_arg([
                'rtes_open' => 1,
                'email' => urlencode($args['email'] ?? ''),
                'type' => $args['type'],
            ], home_url());
            $open_pixel = "<img src=\"" . esc_url($pixel_url) . "\" width=\"1\" height=\"1\" style=\"display:none;\" alt=\"\" />";
        }

        // -- Cart Items --
        $products_html = '';
        if (!empty($args['cart_items'])) {
            foreach ($args['cart_items'] as $item) {
                $img = !empty($item['image']) ? $item['image'] : '';
                $name = esc_html($item['name']);
                $qty = intval($item['quantity']);
                $price = number_format($item['price'], 2);
                $img_html = '';
                if ($img) {
                    $img_url = esc_url($img);
                    $img_html = "<td width=\"90\" style=\"padding:16px 16px 16px 0;vertical-align:middle;\"><img src=\"{$img_url}\" width=\"90\" height=\"90\" alt=\"{$name}\" style=\"display:block;border-radius:10px;object-fit:cover;border:1px solid #eee;\" /></td>";
                }
                $products_html .= "<tr><td style=\"padding:0;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\"><tr>{$img_html}<td style=\"padding:16px 0;vertical-align:middle;\"><p style=\"margin:0 0 4px;font-size:16px;font-weight:600;color:#2d2d2d;font-family:{$font};\">{$name}</p><p style=\"margin:0;font-size:14px;color:#888;font-family:{$font};\">Qty: {$qty} &middot; <span style=\"color:{$brand};font-weight:600;\">\${$price}</span></p></td></tr></table></td></tr>";
                $products_html .= "<tr><td style=\"padding:0;\"><div style=\"height:1px;background:{$brand_light};\"></div></td></tr>";
            }
            if (!empty($args['cart_total'])) {
                $total = number_format($args['cart_total'], 2);
                $products_html .= "<tr><td style=\"padding:20px 0 8px;text-align:right;\"><span style=\"font-size:13px;color:#888;text-transform:uppercase;letter-spacing:1px;font-family:{$font};\">Total</span><br><span style=\"font-size:24px;font-weight:700;color:#2d2d2d;font-family:{$font};\">\${$total}</span></td></tr>";
            }
        }

        // -- Product Grid (cross-sell / review) --
        if (!empty($args['products'])) {
            $products_html .= "<tr><td style=\"padding:10px 0 0;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\"><tr>";
            $count = 0;
            foreach ($args['products'] as $prod) {
                $img = !empty($prod['image']) ? $prod['image'] : '';
                $pname = esc_html($prod['name']);
                $plink = esc_url($prod['permalink']);
                $img_block = $img
                    ? "<a href=\"{$plink}\" style=\"text-decoration:none;\"><img src=\"" . esc_url($img) . "\" width=\"100%\" height=\"auto\" style=\"display:block;max-height:180px;object-fit:cover;\" alt=\"{$pname}\" /></a>"
                    : "<div style=\"height:120px;background:{$brand_light};\"></div>";
                $price_block = !empty($prod['price']) ? "<p style=\"margin:0;font-size:14px;color:{$brand};font-weight:600;font-family:{$font};\">{$prod['price']}</p>" : '';
                $review_block = !empty($prod['review_url']) ? "<p style=\"margin:8px 0 0;\"><a href=\"" . esc_url($prod['review_url']) . "\" style=\"color:{$brand};font-size:13px;font-weight:600;text-decoration:none;font-family:{$font};\">&#9733; Leave a Review</a></p>" : '';
                $products_html .= "<td width=\"50%\" style=\"padding:8px;text-align:center;vertical-align:top;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#fafafa;border-radius:12px;overflow:hidden;border:1px solid #f0f0f0;\" role=\"presentation\"><tr><td style=\"padding:0;\">{$img_block}</td></tr><tr><td style=\"padding:14px 12px;\"><p style=\"margin:0 0 4px;font-size:14px;font-weight:600;color:#2d2d2d;font-family:{$font};\">{$pname}</p>{$price_block}{$review_block}</td></tr></table></td>";
                $count++;
                if ($count % 2 === 0 && $count < count($args['products'])) {
                    $products_html .= "</tr><tr>";
                }
            }
            $products_html .= "</tr></table></td></tr>";
        }

        // -- Coupon --
        $coupon_html = '';
        if (!empty($args['coupon'])) {
            $coupon_code = esc_html(strtoupper($args['coupon']));
            $coupon_html = "<tr><td style=\"padding:10px 0 20px;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\"><tr><td style=\"background:#fdf8f5;border:2px dashed {$brand};border-radius:12px;padding:24px;text-align:center;\"><p style=\"margin:0 0 6px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:2px;font-family:{$font};\">Your Exclusive Code</p><p style=\"margin:0;font-size:28px;font-weight:700;color:{$brand};letter-spacing:3px;font-family:{$font};\">{$coupon_code}</p><p style=\"margin:8px 0 0;font-size:13px;color:#999;font-family:{$font};\">Applied automatically when you click below</p></td></tr></table></td></tr>";
        }

        // -- Header --
        if ($logo_url) {
            $logo_src = esc_url($logo_url);
            $site_link = esc_url($site_url);
            $site_alt = esc_attr($site_name);
            $header_content = "<a href=\"{$site_link}\" style=\"text-decoration:none;\"><img src=\"{$logo_src}\" height=\"100\" style=\"display:block;margin:0 auto;\" alt=\"{$site_alt}\" /></a>";
        } else {
            $site_link = esc_url($site_url);
            $safe_name = esc_html($site_name);
            $header_content = "<a href=\"{$site_link}\" style=\"text-decoration:none;color:#ffffff;\"><span style=\"font-size:24px;font-weight:700;font-family:{$font};letter-spacing:0.5px;\">{$safe_name}</span></a>";
        }

        $body_html = nl2br(esc_html($args['body'] ?? ''));

        $heading_safe = esc_html($args['heading'] ?? '');
        $preview_text = esc_html(wp_strip_all_tags($args['body'] ?? ''));
        $safe_name = esc_html($site_name);
        $year = date('Y');
        $unsub_link = esc_url($unsubscribe_url);
        $site_link = esc_url($site_url);

        // Products section
        $products_section = '';
        if ($products_html) {
            $products_section = "<tr><td class=\"mobile-pad\" style=\"padding:0 48px;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">{$products_html}</table></td></tr>";
        }

        // Coupon section
        $coupon_section = '';
        if ($coupon_html) {
            $coupon_section = "<tr><td class=\"mobile-pad\" style=\"padding:0 48px;\"><table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">{$coupon_html}</table></td></tr>";
        }

        // CTA section
        $cta_section = '';
        if (!empty($args['cta_text'])) {
            $cta_url = esc_url($args['cta_url'] ?? '#');
            $cta_text = esc_html($args['cta_text']);
            $cta_section = "<tr><td class=\"mobile-pad\" style=\"padding:28px 48px 40px;text-align:center;\"><table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" style=\"margin:0 auto;\"><tr><td style=\"background:{$brand};border-radius:10px;\"><a href=\"{$cta_url}\" style=\"display:inline-block;padding:16px 44px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;font-family:{$font};letter-spacing:0.3px;\">{$cta_text}</a></td></tr></table></td></tr>";
        }

        $html = <<<EMAILHTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$heading_safe}</title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body, table, td { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        a { color: {$brand}; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .fluid { max-width: 100% !important; height: auto !important; }
            .stack-column { display: block !important; width: 100% !important; max-width: 100% !important; }
            .mobile-pad { padding-left: 24px !important; padding-right: 24px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;word-spacing:normal;background-color:#f0eeeb;-webkit-font-smoothing:antialiased;">

<div style="display:none;font-size:1px;color:#f0eeeb;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">{$preview_text}</div>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f0eeeb;">
<tr><td style="padding:40px 16px;" align="center">

<table class="email-container" width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;">

    <!-- Logo -->
    <tr><td style="padding:0 0 24px;text-align:center;">{$header_content}</td></tr>

    <!-- Main Card -->
    <tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.06);">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">

            <!-- Brown accent bar -->
            <tr><td style="background:{$brand};height:4px;font-size:1px;line-height:1px;">&nbsp;</td></tr>

            <!-- Heading -->
            <tr><td class="mobile-pad" style="padding:48px 48px 0;text-align:center;">
                <h1 style="margin:0;font-size:26px;font-weight:700;color:#2d2d2d;font-family:{$font};line-height:1.3;">{$heading_safe}</h1>
            </td></tr>

            <!-- Signature divider: bar - dots - bar -->
            <tr><td style="padding:20px 60px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>
                    <td style="height:2px;background:{$brand};"></td>
                    <td width="8" style="font-size:0;">&nbsp;</td>
                    <td width="6" style="font-size:0;line-height:0;"><table cellpadding="0" cellspacing="0" role="presentation"><tr><td width="6" height="6" style="background:{$brand};border-radius:50%;font-size:0;line-height:0;">&nbsp;</td></tr></table></td>
                    <td width="6" style="font-size:0;">&nbsp;</td>
                    <td width="6" style="font-size:0;line-height:0;"><table cellpadding="0" cellspacing="0" role="presentation"><tr><td width="6" height="6" style="background:{$brand};border-radius:50%;font-size:0;line-height:0;">&nbsp;</td></tr></table></td>
                    <td width="6" style="font-size:0;">&nbsp;</td>
                    <td width="6" style="font-size:0;line-height:0;"><table cellpadding="0" cellspacing="0" role="presentation"><tr><td width="6" height="6" style="background:{$brand};border-radius:50%;font-size:0;line-height:0;">&nbsp;</td></tr></table></td>
                    <td width="8" style="font-size:0;">&nbsp;</td>
                    <td style="height:2px;background:{$brand};"></td>
                </tr></table>
            </td></tr>

            <!-- Body -->
            <tr><td class="mobile-pad" style="padding:8px 48px 32px;">
                <p style="margin:0;font-size:15px;line-height:1.8;color:#555;font-family:{$font};">{$body_html}</p>
            </td></tr>

            {$products_section}
            {$coupon_section}
            {$cta_section}

        </table>
    </td></tr>

    <!-- Footer -->
    <tr><td style="padding:32px 24px 16px;text-align:center;">
        <p style="margin:0 0 6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:{$brand};font-family:{$font};">
            <a href="{$site_link}" style="color:{$brand};text-decoration:none;">{$safe_name}</a>
        </p>
        <p style="margin:0 0 16px;font-size:12px;color:#aaa;font-family:{$font};">
            Handmade stadium coasters &amp; wall art
        </p>
        <p style="margin:0 0 6px;font-size:11px;color:#bbb;font-family:{$font};">
            <a href="{$unsub_link}" style="color:#bbb;text-decoration:underline;">Unsubscribe</a>
            &nbsp;&nbsp;&middot;&nbsp;&nbsp;
            <a href="{$site_link}" style="color:#bbb;text-decoration:underline;">Shop</a>
            &nbsp;&nbsp;&middot;&nbsp;&nbsp;
            <a href="{$site_link}/about" style="color:#bbb;text-decoration:underline;">About</a>
        </p>
        <p style="margin:12px 0 0;font-size:10px;color:#ccc;font-family:{$font};">&copy; {$year} {$safe_name} &nbsp;|&nbsp; Albemarle, NC</p>
    </td></tr>

</table>

</td></tr>
</table>
{$open_pixel}
</body></html>
EMAILHTML;

        return $html;
    }

    /*--------------------------------------------------------------
    # Email Sending
    --------------------------------------------------------------*/

    private function send_email($to, $subject, $html) {
        $settings = get_option('rtes_settings', []);
        $from_name = $settings['from_name'] ?? get_bloginfo('name');
        $from_email = $settings['from_email'] ?? get_option('admin_email');

        $unsub_url = add_query_arg([
            'rtes_unsubscribe' => 1,
            'email' => urlencode($to),
            'token' => wp_hash($to),
        ], home_url());

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$from_name} <{$from_email}>",
            "List-Unsubscribe: <{$unsub_url}>",
            "List-Unsubscribe-Post: List-Unsubscribe=One-Click",
            "X-Mailer: RT-Email-Sequences/" . RTES_VERSION,
        ];

        return wp_mail($to, $subject, $html, $headers);
    }


    private function log_email($email, $type, $subject, $order_id = 0, $cart_id = 0) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rtes_email_log', [
            'email_to' => $email,
            'email_type' => $type,
            'subject' => $subject,
            'sent_at' => current_time('mysql'),
            'order_id' => $order_id,
            'cart_id' => $cart_id,
        ]);
    }

    /*--------------------------------------------------------------
    # Unsubscribe Handling
    --------------------------------------------------------------*/

    private function is_unsubscribed($email) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rtes_unsubscribes WHERE email = %s",
            $email
        ));
    }

    public function check_unsubscribe_link() {
        if (empty($_GET['rtes_unsubscribe'])) return;

        $email = sanitize_email($_GET['email'] ?? '');
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$email || !$token || $token !== wp_hash($email)) {
            wp_die('Invalid unsubscribe link.');
        }

        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'rtes_unsubscribes', [
            'email' => $email,
            'unsubscribed_at' => current_time('mysql'),
        ]);

        $site_name = get_bloginfo('name');
        wp_die(
            "<div style='text-align:center;padding:60px 20px;font-family:sans-serif;'>
                <h2 style='color:#333;'>You've been unsubscribed</h2>
                <p style='color:#666;'>You won't receive any more marketing emails from {$site_name}.</p>
                <p><a href='" . home_url() . "' style='color:#A2755A;'>Return to site</a></p>
            </div>",
            'Unsubscribed',
            ['response' => 200]
        );
    }

    /*--------------------------------------------------------------
    # Email Open Tracking
    --------------------------------------------------------------*/

    public function track_email_open() {
        if (empty($_GET['rtes_open'])) return;

        $email = sanitize_email($_GET['email'] ?? '');
        $type = sanitize_text_field($_GET['type'] ?? '');

        if ($email && $type) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rtes_email_log SET opened = 1, opened_at = %s WHERE email_to = %s AND opened = 0 ORDER BY sent_at DESC LIMIT 1",
                current_time('mysql'),
                $email
            ));
        }

        // Return transparent 1x1 pixel
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    /*--------------------------------------------------------------
    # Auto-apply Coupon
    --------------------------------------------------------------*/

    public function apply_url_coupon() {
        if (!empty($_GET['rtes_coupon']) && WC()->cart) {
            $coupon = sanitize_text_field($_GET['rtes_coupon']);
            if (!WC()->cart->has_discount($coupon)) {
                WC()->cart->apply_coupon($coupon);
            }
        }
    }

    /*--------------------------------------------------------------
    # Daily Cleanup
    --------------------------------------------------------------*/

    public function daily_cleanup() {
        global $wpdb;

        // Remove recovered/old abandoned carts older than 90 days
        $wpdb->query("DELETE FROM {$wpdb->prefix}rtes_abandoned_carts WHERE (recovered = 1 OR emails_sent >= 3) AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

        // Remove sent scheduled emails older than 30 days
        $wpdb->query("DELETE FROM {$wpdb->prefix}rtes_scheduled_emails WHERE sent = 1 AND scheduled_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    /*--------------------------------------------------------------
    # Admin Interface
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page(
            'Email Sequences',
            'Email Sequences',
            'manage_options',
            'rt-email-sequences',
            [$this, 'admin_page'],
            'dashicons-email-alt',
            58
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-email-sequences') return;
        wp_enqueue_style('rtes-admin', RTES_URL . 'admin.css', [], RTES_VERSION);
        wp_enqueue_script('rtes-admin', RTES_URL . 'admin.js', ['jquery'], RTES_VERSION, true);
        wp_localize_script('rtes-admin', 'rtes', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtes_nonce'),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtes_settings', []);
        include RTES_PATH . 'admin-page.php';
    }

    /*--------------------------------------------------------------
    # AJAX Handlers
    --------------------------------------------------------------*/

    public function ajax_save_settings() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        if (!empty($_POST['settings'])) {
            parse_str($_POST['settings'], $data);
            $data = wp_unslash($data);
        }

        // Sanitize all settings
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $clean[sanitize_key($key)] = sanitize_textarea_field($value);
            } else {
                $clean[sanitize_key($key)] = $value;
            }
        }

        // Detect which tab was submitted and only zero out its toggles
        // (unchecked checkboxes don't appear in POST, so we must explicitly set them to 0
        //  but ONLY for the tab that was actually submitted)
        $tab_toggles = [
            'abandoned_email_1_subject' => ['abandoned_enabled', 'abandoned_email_2_enabled', 'abandoned_email_3_enabled'],
            'thankyou_subject' => ['thankyou_enabled'],
            'review_subject' => ['review_enabled'],
            'crosssell_subject' => ['crosssell_enabled'],
            'welcome_subject' => ['welcome_enabled'],
            'from_name' => ['enabled'],
        ];

        foreach ($tab_toggles as $marker => $toggles) {
            if (array_key_exists($marker, $clean)) {
                foreach ($toggles as $key) {
                    $clean[$key] = isset($clean[$key]) ? 1 : 0;
                }
            }
        }

        // Merge with EXISTING settings first (preserves other tabs), then fill gaps from defaults
        $existing = get_option('rtes_settings', []);
        if (!is_array($existing)) $existing = [];
        $defaults = $this->get_defaults();
        $merged = wp_parse_args($clean, $existing);
        $merged = wp_parse_args($merged, $defaults);

        update_option('rtes_settings', $merged);
        wp_send_json_success('Settings saved');
    }

    public function ajax_reset_defaults() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $defaults = $this->get_defaults();
        update_option('rtes_settings', $defaults);
        wp_send_json_success('Settings reset to defaults');
    }

    public function ajax_send_test() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $type = sanitize_text_field($_POST['type'] ?? 'abandoned');
        $email = sanitize_email($_POST['email'] ?? get_option('admin_email'));
        $settings = get_option('rtes_settings', []);

        switch ($type) {
            case 'abandoned':
                $subject = $settings['abandoned_email_1_subject'] ?? 'Test: Abandoned Cart';
                $html = $this->build_email_html([
                    'heading' => $settings['abandoned_email_1_heading'],
                    'body' => $settings['abandoned_email_1_body'],
                    'cta_text' => $settings['abandoned_email_1_cta'],
                    'cta_url' => wc_get_cart_url(),
                    'cart_items' => [
                        ['name' => 'Sample Stadium Coaster', 'quantity' => 2, 'price' => 14.99, 'image' => ''],
                        ['name' => 'Sample Wall Art Print', 'quantity' => 1, 'price' => 29.99, 'image' => ''],
                    ],
                    'cart_total' => 59.97,
                    'coupon' => $settings['abandoned_email_1_coupon'],
                    'email' => $email,
                    'type' => 'test',
                ]);
                break;

            case 'thankyou':
                $subject = str_replace(['{first_name}', '{site_name}'], ['Test User', get_bloginfo('name')], $settings['thankyou_subject']);
                $html = $this->build_email_html([
                    'heading' => $settings['thankyou_heading'],
                    'body' => str_replace('{first_name}', 'Test User', $settings['thankyou_body']),
                    'cta_text' => 'View Your Order',
                    'cta_url' => home_url(),
                    'email' => $email,
                    'type' => 'test',
                ]);
                break;

            case 'review':
                $subject = str_replace(['{first_name}', '{product_name}'], ['Test User', 'Sample Coaster'], $settings['review_subject']);
                $html = $this->build_email_html([
                    'heading' => $settings['review_heading'],
                    'body' => str_replace(['{first_name}', '{product_name}'], ['Test User', 'Sample Coaster'], $settings['review_body']),
                    'cta_text' => $settings['review_cta'],
                    'cta_url' => home_url(),
                    'email' => $email,
                    'type' => 'test',
                ]);
                break;

            case 'crosssell':
                $subject = str_replace(['{first_name}', '{product_name}'], ['Test User', 'Sample Coaster'], $settings['crosssell_subject']);
                $html = $this->build_email_html([
                    'heading' => $settings['crosssell_heading'],
                    'body' => str_replace(['{first_name}', '{product_name}'], ['Test User', 'Sample Coaster'], $settings['crosssell_body']),
                    'products' => [
                        ['name' => 'Stadium Coaster A', 'price' => wc_price(14.99), 'image' => '', 'permalink' => '#'],
                        ['name' => 'Wall Art B', 'price' => wc_price(29.99), 'image' => '', 'permalink' => '#'],
                    ],
                    'cta_text' => 'Shop Now',
                    'cta_url' => home_url(),
                    'email' => $email,
                    'type' => 'test',
                ]);
                break;

            case 'welcome':
                $subject = str_replace('{site_name}', get_bloginfo('name'), $settings['welcome_subject']);
                $html = $this->build_email_html([
                    'heading' => $settings['welcome_heading'],
                    'body' => str_replace('{site_name}', get_bloginfo('name'), $settings['welcome_body']),
                    'cta_text' => $settings['welcome_cta'],
                    'cta_url' => home_url(),
                    'coupon' => $settings['welcome_coupon'],
                    'email' => $email,
                    'type' => 'test',
                ]);
                break;

            default:
                wp_send_json_error('Invalid email type');
                return;
        }

        $sent = $this->send_email($email, '[TEST] ' . $subject, $html);
        wp_send_json_success($sent ? 'Test email sent!' : 'Failed to send test email');
    }

    public function ajax_get_stats() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $log = $wpdb->prefix . 'rtes_email_log';
        $carts = $wpdb->prefix . 'rtes_abandoned_carts';

        $stats = [
            'total_sent' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $log WHERE email_type NOT LIKE 'test%'"),
            'total_opened' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $log WHERE opened = 1 AND email_type NOT LIKE 'test%'"),
            'total_clicked' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $log WHERE clicked = 1 AND email_type NOT LIKE 'test%'"),
            'abandoned_carts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $carts WHERE recovered = 0 AND email != ''"),
            'recovered_carts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $carts WHERE recovered = 1"),
            'recovered_revenue' => (float) $wpdb->get_var("SELECT COALESCE(SUM(cart_total), 0) FROM $carts WHERE recovered = 1"),
            'abandoned_revenue' => (float) $wpdb->get_var("SELECT COALESCE(SUM(cart_total), 0) FROM $carts WHERE recovered = 0 AND email != ''"),
            'recovery_rate' => 0,
            'click_rate' => 0,
            'by_type' => [],
            'recent' => [],
        ];

        $total_trackable = $stats['abandoned_carts'] + $stats['recovered_carts'];
        $stats['recovery_rate'] = $total_trackable > 0 ? round(($stats['recovered_carts'] / $total_trackable) * 100, 1) : 0;
        $stats['click_rate'] = $stats['total_sent'] > 0 ? round(($stats['total_clicked'] / $stats['total_sent']) * 100, 1) : 0;

        // Stats by type
        $types = $wpdb->get_results("SELECT email_type, COUNT(*) as total, SUM(opened) as opens FROM $log WHERE email_type NOT LIKE 'test%' GROUP BY email_type");
        foreach ($types as $t) {
            $stats['by_type'][$t->email_type] = [
                'sent' => (int) $t->total,
                'opened' => (int) $t->opens,
                'rate' => $t->total > 0 ? round(($t->opens / $t->total) * 100, 1) : 0,
            ];
        }

        // Recent emails
        $recent = $wpdb->get_results("SELECT email_to, email_type, subject, sent_at, opened FROM $log ORDER BY sent_at DESC LIMIT 20");
        $stats['recent'] = $recent;

        wp_send_json_success($stats);
    }

    public function ajax_clear_log() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtes_email_log");
        wp_send_json_success('Log cleared');
    }

    public function ajax_reset_stats() {
        check_ajax_referer('rtes_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtes_email_log");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtes_abandoned_carts");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtes_scheduled_emails");
        wp_send_json_success('All stats reset');
    }
}

RT_Email_Sequences::get_instance();
