<?php
/**
 * Plugin Name: RT Pinterest Auto-Poster
 * Description: Automatically pin your WooCommerce products to Pinterest with smart descriptions, hashtags, and scheduling.
 * Version: 1.5.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-pinterest-poster
 */

if (!defined('ABSPATH')) exit;

define('RTPP_VERSION', '1.5.0');
define('RTPP_PATH', plugin_dir_path(__FILE__));
define('RTPP_URL', plugin_dir_url(__FILE__));
define('RTPP_APP_ID', '1547820');
define('RTPP_APP_SECRET', 'eb20b523c51c2623099a830c9c5f5eb850e7db3b');
define('RTPP_API_BASE', 'https://api.pinterest.com/v5');

class RT_Pinterest_Poster {

    private static $instance = null;
    
    private $defaults = [
        'access_token' => '',
        'refresh_token' => '',
        'token_expires' => 0,
        'connected' => 0,
        'user_name' => '',
        'default_board' => '',
        'auto_pin_new' => 1,
        'pin_description_template' => "{product_name} — {short_description}\n\nShop now at {product_url}\n\n{hashtags}",
        'default_hashtags' => '#stadiumcoasters #sportsart #wallart #mancave #sportsroom #handmade #homedecor #sportsfan #giftideas #collegefootball',
        'smart_hashtags' => 1,
        'include_price' => 1,
        'link_to_product' => 1,
        'schedule_enabled' => 1,
        'pins_per_day' => 3,
        'schedule_start_hour' => 9,
        'schedule_end_hour' => 21,
        'min_hours_between' => 3,
        'repin_enabled' => 0,
        'repin_interval_days' => 30,
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('init', [$this, 'register_oauth_endpoint']);
        add_action('template_redirect', [$this, 'handle_oauth_callback']);
        add_action('admin_init', [$this, 'maybe_upgrade']);

        // AJAX handlers
        add_action('wp_ajax_rtpp_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtpp_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_rtpp_get_boards', [$this, 'ajax_get_boards']);
        add_action('wp_ajax_rtpp_create_board', [$this, 'ajax_create_board']);
        add_action('wp_ajax_rtpp_set_default_board', [$this, 'ajax_set_default_board']);
        add_action('wp_ajax_rtpp_pin_now', [$this, 'ajax_pin_now']);
        add_action('wp_ajax_rtpp_pin_all', [$this, 'ajax_pin_all']);
        add_action('wp_ajax_rtpp_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtpp_get_products', [$this, 'ajax_get_products']);
        add_action('wp_ajax_rtpp_get_schedule', [$this, 'ajax_get_schedule']);
        add_action('wp_ajax_rtpp_clear_schedule', [$this, 'ajax_clear_schedule']);
        add_action('wp_ajax_rtpp_reset_pinned', [$this, 'ajax_reset_pinned']);
        add_action('wp_ajax_rtpp_refresh_user', [$this, 'ajax_refresh_user']);

        // Cron
        add_action('rtpp_process_scheduled_pins', [$this, 'process_scheduled_pins']);
        add_action('rtpp_auto_pin_new_products', [$this, 'auto_pin_new_products']);

        // Auto-pin on new product
        add_action('woocommerce_new_product', [$this, 'on_new_product'], 20, 1);

        // Custom cron intervals
        add_filter('cron_schedules', [$this, 'cron_schedules']);
    }

    public function maybe_upgrade() {
        $stored_version = get_option('rtpp_version', '0');
        if (version_compare($stored_version, RTPP_VERSION, '<')) {
            $existing = get_option('rtpp_settings', []);
            $merged = array_merge($this->defaults, $existing);
            
            // Restore critical defaults if they got wiped
            $restore_if_empty = ['pin_description_template', 'default_hashtags'];
            foreach ($restore_if_empty as $key) {
                if (empty($merged[$key])) {
                    $merged[$key] = $this->defaults[$key];
                }
            }
            
            update_option('rtpp_settings', $merged);
            update_option('rtpp_version', RTPP_VERSION);
        }
    }

    /*--------------------------------------------------------------
    # Activation / Deactivation
    --------------------------------------------------------------*/

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Pin log table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtpp_pin_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            board_id VARCHAR(100) NOT NULL,
            pin_id VARCHAR(100) DEFAULT \'\',
            pin_url VARCHAR(500) DEFAULT \'\',
            status VARCHAR(20) DEFAULT \'pending\',
            error_message TEXT DEFAULT \'\',
            retry_count TINYINT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            pinned_at DATETIME DEFAULT NULL,
            INDEX idx_product (product_id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) $charset");

        // Add retry_count to existing installs
        $wpdb->query("ALTER TABLE {$wpdb->prefix}rtpp_pin_log ADD COLUMN IF NOT EXISTS retry_count TINYINT UNSIGNED DEFAULT 0");

        // Scheduled pins table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rtpp_scheduled_pins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            board_id VARCHAR(100) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            processed TINYINT(1) DEFAULT 0,
            retry_count TINYINT UNSIGNED DEFAULT 0,
            INDEX idx_scheduled (scheduled_at, processed)
        ) $charset");

        // Add retry_count to existing installs
        $wpdb->query("ALTER TABLE {$wpdb->prefix}rtpp_scheduled_pins ADD COLUMN IF NOT EXISTS retry_count TINYINT UNSIGNED DEFAULT 0");

        $defaults = $this->defaults;

        // Merge defaults with existing — existing values take priority
        $existing = get_option('rtpp_settings', []);
        $merged = array_merge($defaults, $existing);
        update_option('rtpp_settings', $merged);

        // Schedule cron
        if (!wp_next_scheduled('rtpp_process_scheduled_pins')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'rtpp_process_scheduled_pins');
        }

        // Register endpoint and flush rewrite rules
        $this->register_oauth_endpoint();
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rtpp_process_scheduled_pins');
        wp_clear_scheduled_hook('rtpp_auto_pin_new_products');
        flush_rewrite_rules();
    }

    public function cron_schedules($schedules) {
        $schedules['every_fifteen_minutes'] = [
            'interval' => 900,
            'display' => 'Every 15 Minutes'
        ];
        return $schedules;
    }

    /*--------------------------------------------------------------
    # OAuth 2.0 Flow
    --------------------------------------------------------------*/

    private function get_redirect_uri() {
        return home_url('/pinterest-oauth-callback/');
    }

    public function get_auth_url() {
        $params = [
            'client_id' => RTPP_APP_ID,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => 'boards:read,boards:write,pins:read,pins:write',
            'state' => wp_create_nonce('rtpp_oauth'),
        ];

        return 'https://www.pinterest.com/oauth/?' . http_build_query($params);
    }

    public function register_oauth_endpoint() {
        add_rewrite_rule(
            '^pinterest-oauth-callback/?$',
            'index.php?rtpp_oauth_callback=1',
            'top'
        );
        add_rewrite_tag('%rtpp_oauth_callback%', '1');
    }

    public function handle_oauth_callback() {
        // Check for the clean URL endpoint
        if (!get_query_var('rtpp_oauth_callback')) return;

        if (empty($_GET['code'])) {
            wp_redirect(admin_url('admin.php?page=rt-pinterest-poster&error=nocode'));
            exit;
        }

        // Verify state
        if (!wp_verify_nonce($_GET['state'] ?? '', 'rtpp_oauth')) {
            wp_die('Invalid OAuth state. Please try again.');
        }

        $code = sanitize_text_field($_GET['code']);
        $token_data = $this->exchange_code_for_token($code);

        if ($token_data && !empty($token_data['access_token'])) {
            $settings = get_option('rtpp_settings', []);
            $settings['access_token'] = $token_data['access_token'];
            $settings['refresh_token'] = $token_data['refresh_token'] ?? '';
            $settings['token_expires'] = time() + ($token_data['expires_in'] ?? 2592000);
            $settings['connected'] = 1;

            // Get user info
            $user = $this->api_get('/user_account');
            if ($user) {
                $settings['user_name'] = $user['username'] ?? $user['business_name'] ?? $user['profile_image'] ?? '';
                if (empty($settings['user_name']) && !empty($user['website_url'])) {
                    $settings['user_name'] = parse_url($user['website_url'], PHP_URL_HOST);
                }
                // Store full user data for debugging
                $settings['user_data'] = wp_json_encode($user);
            }

            update_option('rtpp_settings', $settings);

            wp_redirect(admin_url('admin.php?page=rt-pinterest-poster&connected=1'));
            exit;
        } else {
            wp_redirect(admin_url('admin.php?page=rt-pinterest-poster&error=oauth'));
            exit;
        }
    }

    private function exchange_code_for_token($code) {
        $response = wp_remote_post(RTPP_API_BASE . '/oauth/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(RTPP_APP_ID . ':' . RTPP_APP_SECRET),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->get_redirect_uri(),
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('RTPP OAuth Error: ' . $response->get_error_message());
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function refresh_access_token() {
        $settings = get_option('rtpp_settings', []);
        if (empty($settings['refresh_token'])) return false;

        $response = wp_remote_post(RTPP_API_BASE . '/oauth/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(RTPP_APP_ID . ':' . RTPP_APP_SECRET),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $settings['refresh_token'],
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['access_token'])) {
            $settings['access_token'] = $data['access_token'];
            $settings['refresh_token'] = $data['refresh_token'] ?? $settings['refresh_token'];
            $settings['token_expires'] = time() + ($data['expires_in'] ?? 2592000);
            update_option('rtpp_settings', $settings);
            return true;
        }

        return false;
    }

    /*--------------------------------------------------------------
    # Pinterest API Methods
    --------------------------------------------------------------*/

    private function get_access_token() {
        $settings = get_option('rtpp_settings', []);
        if (empty($settings['access_token'])) return false;

        // Refresh if expired or expiring soon (within 1 hour)
        if (time() > ($settings['token_expires'] - 3600)) {
            $this->refresh_access_token();
            $settings = get_option('rtpp_settings', []);
        }

        return $settings['access_token'] ?? false;
    }

    private function api_get($endpoint, $params = []) {
        $token = $this->get_access_token();
        if (!$token) return false;

        $url = RTPP_API_BASE . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('RTPP API GET Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            error_log('RTPP API Error ' . $code . ': ' . wp_remote_retrieve_body($response));
            return false;
        }

        return $body;
    }

    private function api_post($endpoint, $data = []) {
        $token = $this->get_access_token();
        if (!$token) return false;

        $response = wp_remote_post(RTPP_API_BASE . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('RTPP API POST Error: ' . $response->get_error_message());
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            error_log('RTPP API Error ' . $code . ': ' . wp_remote_retrieve_body($response));
            return ['error' => $body['message'] ?? 'API error ' . $code, 'code' => $code];
        }

        return $body;
    }

    /*--------------------------------------------------------------
    # Board Management
    --------------------------------------------------------------*/

    public function get_boards() {
        $boards = $this->api_get('/boards', ['page_size' => 100]);
        if (!$boards || empty($boards['items'])) return [];

        $result = [];
        foreach ($boards['items'] as $board) {
            $result[] = [
                'id' => $board['id'],
                'name' => $board['name'],
                'description' => $board['description'] ?? '',
                'pin_count' => $board['pin_count'] ?? 0,
                'url' => 'https://pinterest.com/pin/' . $board['id'],
            ];
        }

        return $result;
    }

    public function create_board($name, $description = '') {
        return $this->api_post('/boards', [
            'name' => $name,
            'description' => $description,
            'privacy' => 'PUBLIC',
        ]);
    }

    /*--------------------------------------------------------------
    # Pin Creation
    --------------------------------------------------------------*/

    public function create_pin($product_id, $board_id, $retry_count = 0) {
        $product = wc_get_product($product_id);
        if (!$product) return ['error' => 'Product not found'];

        $settings = get_option('rtpp_settings', []);

        // Build description
        $description = $this->build_pin_description($product, $settings);

        // Get image
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        if (empty($image_url)) return ['error' => 'No product image'];

        // Product URL
        $link = $product->get_permalink();
        $link = add_query_arg([
            'utm_source' => 'pinterest',
            'utm_medium' => 'pin',
            'utm_campaign' => 'auto_poster',
        ], $link);

        // Build pin data
        $pin_data = [
            'board_id' => $board_id,
            'title' => mb_substr($product->get_name(), 0, 100),
            'description' => mb_substr($description, 0, 500),
            'link' => $link,
            'media_source' => [
                'source_type' => 'image_url',
                'url' => $image_url,
            ],
        ];

        // Include alt text
        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if ($alt_text) {
            $pin_data['alt_text'] = mb_substr($alt_text, 0, 500);
        }

        $result = $this->api_post('/pins', $pin_data);

        // Log the result
        $this->log_pin($product_id, $board_id, $result, $retry_count);

        return $result;
    }

    private function build_pin_description($product, $settings) {
        $template = $settings['pin_description_template'] ?? "{product_name}\n\n{hashtags}";

        // Build smart hashtags
        $hashtags = $this->build_hashtags($product, $settings);

        // Price string
        $price = '';
        if (!empty($settings['include_price'])) {
            $sale = $product->get_sale_price();
            $regular = $product->get_regular_price();
            if ($sale) {
                $price = 'Now $' . number_format((float)$sale, 2) . ' (was $' . number_format((float)$regular, 2) . ')';
            } elseif ($regular) {
                $price = '$' . number_format((float)$regular, 2);
            }
        }

        // Short description
        $short_desc = wp_strip_all_tags($product->get_short_description());
        $short_desc = mb_substr($short_desc, 0, 200);

        // Replacements
        $replacements = [
            '{product_name}' => $product->get_name(),
            '{short_description}' => $short_desc,
            '{price}' => $price,
            '{product_url}' => $product->get_permalink(),
            '{hashtags}' => $hashtags,
            '{site_name}' => get_bloginfo('name'),
        ];

        $description = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Clean up double newlines
        $description = preg_replace("/\n{3,}/", "\n\n", $description);

        return trim($description);
    }

    private function build_hashtags($product, $settings) {
        $tags = [];

        // Default hashtags
        $defaults = $settings['default_hashtags'] ?? '';
        if ($defaults) {
            $tags = array_map('trim', explode(' ', $defaults));
        }

        // Smart hashtags from categories and tags
        if (!empty($settings['smart_hashtags'])) {
            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    $tag = '#' . preg_replace('/[^a-zA-Z0-9]/', '', strtolower($cat->name));
                    if (!in_array($tag, $tags) && strlen($tag) > 2) {
                        $tags[] = $tag;
                    }
                }
            }

            $product_tags = get_the_terms($product->get_id(), 'product_tag');
            if ($product_tags && !is_wp_error($product_tags)) {
                foreach ($product_tags as $pt) {
                    $tag = '#' . preg_replace('/[^a-zA-Z0-9]/', '', strtolower($pt->name));
                    if (!in_array($tag, $tags) && strlen($tag) > 2) {
                        $tags[] = $tag;
                    }
                }
            }

            // Add sport-specific hashtags based on product name
            $name_lower = strtolower($product->get_name());
            $sport_tags = [
                'football' => ['#football', '#nfl', '#collegefootball', '#gameday'],
                'baseball' => ['#baseball', '#mlb', '#ballpark'],
                'basketball' => ['#basketball', '#nba', '#marchmadness'],
                'hockey' => ['#hockey', '#nhl'],
                'soccer' => ['#soccer', '#mls'],
                'coaster' => ['#coasters', '#drinkcoasters', '#barware'],
                'wall art' => ['#wallart', '#walldecor', '#artprint'],
            ];

            foreach ($sport_tags as $keyword => $keyword_tags) {
                if (strpos($name_lower, $keyword) !== false) {
                    foreach ($keyword_tags as $kt) {
                        if (!in_array($kt, $tags)) {
                            $tags[] = $kt;
                        }
                    }
                }
            }
        }

        // Limit to 20 hashtags (Pinterest best practice)
        $tags = array_slice(array_unique($tags), 0, 20);

        return implode(' ', $tags);
    }

    /*--------------------------------------------------------------
    # Scheduling
    --------------------------------------------------------------*/

    public function schedule_product_pin($product_id, $board_id = '') {
        $settings = get_option('rtpp_settings', []);
        if (empty($board_id)) $board_id = $settings['default_board'] ?? '';
        if (empty($board_id)) return false;

        // Check if already pinned or scheduled
        if ($this->is_product_pinned($product_id, $board_id) || $this->is_product_scheduled($product_id, $board_id)) {
            return false;
        }

        // Find next available slot
        $next_slot = $this->get_next_schedule_slot($settings);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rtpp_scheduled_pins', [
            'product_id' => $product_id,
            'board_id' => $board_id,
            'scheduled_at' => $next_slot,
            'processed' => 0,
        ]);

        return $next_slot;
    }

    private function get_next_schedule_slot($settings) {
        global $wpdb;
        $table = $wpdb->prefix . 'rtpp_scheduled_pins';

        $pins_per_day = intval($settings['pins_per_day'] ?? 3);
        $start_hour = intval($settings['schedule_start_hour'] ?? 9);
        $end_hour = intval($settings['schedule_end_hour'] ?? 21);
        $min_gap = intval($settings['min_hours_between'] ?? 3);

        // Get the last scheduled time
        $last = $wpdb->get_var("SELECT MAX(scheduled_at) FROM $table WHERE processed = 0");

        if ($last) {
            $last_time = strtotime($last);
            $next = $last_time + ($min_gap * 3600);
        } else {
            $next = time();
        }

        // Ensure within allowed hours
        $hour = (int) date('G', $next);
        if ($hour < $start_hour) {
            $next = strtotime(date('Y-m-d', $next) . " {$start_hour}:00:00");
        } elseif ($hour >= $end_hour) {
            // Move to next day
            $next = strtotime(date('Y-m-d', $next + 86400) . " {$start_hour}:00:00");
        }

        // Add some randomness (±30 minutes)
        $next += rand(-1800, 1800);

        return date('Y-m-d H:i:s', $next);
    }

    public function process_scheduled_pins() {
        $settings = get_option('rtpp_settings', []);
        if (empty($settings['connected']) || empty($settings['schedule_enabled'])) return;

        global $wpdb;
        $table = $wpdb->prefix . 'rtpp_scheduled_pins';
        $now = current_time('mysql');

        $pins = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE processed = 0 AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 3",
            $now
        ));

        foreach ($pins as $pin) {
            $retry_count = isset($pin->retry_count) ? (int) $pin->retry_count : 0;
            $result = $this->create_pin($pin->product_id, $pin->board_id, $retry_count);
            $wpdb->update($table, ['processed' => 1], ['id' => $pin->id]);

            // Small delay between pins to be respectful to API
            if (count($pins) > 1) sleep(2);
        }
    }

    public function on_new_product($product_id) {
        $settings = get_option('rtpp_settings', []);
        if (empty($settings['auto_pin_new']) || empty($settings['connected'])) return;

        $this->schedule_product_pin($product_id);
    }

    /*--------------------------------------------------------------
    # Helpers
    --------------------------------------------------------------*/

    private function is_product_pinned($product_id, $board_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rtpp_pin_log WHERE product_id = %d AND board_id = %s AND status = 'success'",
            $product_id, $board_id
        ));
    }

    private function is_product_scheduled($product_id, $board_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rtpp_scheduled_pins WHERE product_id = %d AND board_id = %s AND processed = 0",
            $product_id, $board_id
        ));
    }

    /**
     * Errors that are transient (Pinterest-side timeouts, crawl failures).
     * These are worth retrying automatically.
     */
    private function is_retryable_error($error_message) {
        $retryable = [
            'Unable to reach the URL',
            'timeout',
            'timed out',
            'temporarily unavailable',
            'rate limit',
            'server error',
            '500',
            '503',
            '429',
        ];
        foreach ($retryable as $phrase) {
            if (stripos($error_message, $phrase) !== false) return true;
        }
        return false;
    }

    private function log_pin($product_id, $board_id, $result, $retry_count = 0) {
        global $wpdb;

        $data = [
            'product_id'  => $product_id,
            'board_id'    => $board_id,
            'retry_count' => $retry_count,
            'created_at'  => current_time('mysql'),
        ];

        if (!empty($result['error'])) {
            $error_msg = $result['error'];

            // If retryable and under the limit, re-schedule instead of failing
            if ($this->is_retryable_error($error_msg) && $retry_count < 3) {
                $data['status']        = 'retrying';
                $data['error_message'] = $error_msg;
                $wpdb->insert($wpdb->prefix . 'rtpp_pin_log', $data);

                // Re-queue with a 30-minute delay per attempt
                $retry_at = date('Y-m-d H:i:s', time() + (1800 * ($retry_count + 1)));
                $wpdb->insert($wpdb->prefix . 'rtpp_scheduled_pins', [
                    'product_id'   => $product_id,
                    'board_id'     => $board_id,
                    'scheduled_at' => $retry_at,
                    'processed'    => 0,
                    'retry_count'  => $retry_count + 1,
                ]);
                return;
            }

            // Non-retryable or exhausted retries — mark permanently failed
            $data['status']        = 'failed';
            $data['error_message'] = $retry_count >= 3
                ? $error_msg . ' (failed after ' . $retry_count . ' retries)'
                : $error_msg;

        } elseif (!empty($result['id'])) {
            $data['status']    = 'success';
            $data['pin_id']    = $result['id'];
            $data['pin_url']   = 'https://pinterest.com/pin/' . $result['id'];
            $data['pinned_at'] = current_time('mysql');
        } else {
            $data['status']        = 'unknown';
            $data['error_message'] = wp_json_encode($result);
        }

        $wpdb->insert($wpdb->prefix . 'rtpp_pin_log', $data);
    }

    /*--------------------------------------------------------------
    # Admin Interface
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page(
            'Pinterest Poster',
            'Pinterest Poster',
            'manage_options',
            'rt-pinterest-poster',
            [$this, 'admin_page'],
            'dashicons-pinterest',
            61
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-pinterest-poster') return;
        wp_enqueue_style('rtpp-admin', RTPP_URL . 'admin.css', [], RTPP_VERSION);
        wp_enqueue_script('rtpp-admin', RTPP_URL . 'admin.js', ['jquery'], RTPP_VERSION, true);

        $settings = get_option('rtpp_settings', []);
        wp_localize_script('rtpp-admin', 'rtpp', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtpp_nonce'),
            'connected' => !empty($settings['connected']),
            'auth_url' => $this->get_auth_url(),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtpp_settings', []);
        include RTPP_PATH . 'admin-page.php';
    }

    /*--------------------------------------------------------------
    # AJAX Handlers
    --------------------------------------------------------------*/

    public function ajax_save_settings() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        parse_str($_POST['settings'] ?? '', $data);

        $existing = get_option('rtpp_settings', []);

        // Merge new data into existing (preserves anything not in the form)
        $merged = array_merge($existing, $data);

        // Clean toggles — only set to 0 if the form actually contains a related field
        // Content tab toggles (present when pin_description_template field exists)
        $content_toggles = ['smart_hashtags', 'include_price'];
        // Settings tab toggles (present when pins_per_day field exists)
        $settings_toggles = ['auto_pin_new', 'schedule_enabled', 'repin_enabled', 'link_to_product'];

        if (isset($data['pin_description_template']) || isset($data['default_hashtags'])) {
            foreach ($content_toggles as $key) {
                $merged[$key] = isset($data[$key]) ? 1 : 0;
            }
        }
        if (isset($data['pins_per_day']) || isset($data['min_hours_between'])) {
            foreach ($settings_toggles as $key) {
                $merged[$key] = isset($data[$key]) ? 1 : 0;
            }
        }

        // Sanitize non-sensitive string fields
        $skip_sanitize = ['access_token', 'refresh_token', 'pin_description_template', 'default_hashtags', 'user_data'];
        foreach ($merged as $key => $val) {
            if (is_string($val) && !in_array($key, $skip_sanitize)) {
                $merged[$key] = sanitize_text_field($val);
            }
        }
        if (isset($data['pin_description_template'])) {
            $merged['pin_description_template'] = sanitize_textarea_field($data['pin_description_template']);
        }
        if (isset($data['default_hashtags'])) {
            $merged['default_hashtags'] = sanitize_textarea_field($data['default_hashtags']);
        }

        update_option('rtpp_settings', $merged);
        wp_send_json_success('Settings saved');
    }

    public function ajax_disconnect() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtpp_settings', []);
        $settings['access_token'] = '';
        $settings['refresh_token'] = '';
        $settings['token_expires'] = 0;
        $settings['connected'] = 0;
        $settings['user_name'] = '';
        update_option('rtpp_settings', $settings);

        wp_send_json_success('Disconnected');
    }

    public function ajax_get_boards() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $boards = $this->get_boards();
        wp_send_json_success($boards);
    }

    public function ajax_create_board() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $name = sanitize_text_field($_POST['name'] ?? '');
        $desc = sanitize_text_field($_POST['description'] ?? '');
        if (empty($name)) wp_send_json_error('Board name required');

        $result = $this->create_board($name, $desc);
        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }
        wp_send_json_success($result);
    }

    public function ajax_set_default_board() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $board_id = sanitize_text_field($_POST['board_id'] ?? '');
        if (empty($board_id)) wp_send_json_error('No board ID');

        $settings = get_option('rtpp_settings', []);
        $settings['default_board'] = $board_id;
        update_option('rtpp_settings', $settings);

        wp_send_json_success('Default board updated');
    }

    public function ajax_pin_now() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $product_id = intval($_POST['product_id'] ?? 0);
        $board_id = sanitize_text_field($_POST['board_id'] ?? '');
        $settings = get_option('rtpp_settings', []);

        if (!$product_id) wp_send_json_error('No product selected');
        if (empty($board_id)) $board_id = $settings['default_board'] ?? '';
        if (empty($board_id)) wp_send_json_error('No board selected');

        $result = $this->create_pin($product_id, $board_id);

        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success([
            'pin_id' => $result['id'] ?? '',
            'message' => 'Pin created successfully!',
        ]);
    }

    public function ajax_pin_all() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtpp_settings', []);
        $board_id = sanitize_text_field($_POST['board_id'] ?? '') ?: ($settings['default_board'] ?? '');
        if (empty($board_id)) wp_send_json_error('No board selected');

        // Get all published products
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $scheduled = 0;
        $skipped = 0;

        foreach ($products as $product_id) {
            $result = $this->schedule_product_pin($product_id, $board_id);
            if ($result) {
                $scheduled++;
            } else {
                $skipped++;
            }
        }

        wp_send_json_success([
            'scheduled' => $scheduled,
            'skipped' => $skipped,
            'message' => "{$scheduled} products scheduled for pinning. {$skipped} skipped (already pinned or scheduled).",
        ]);
    }

    public function ajax_get_stats() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $log = $wpdb->prefix . 'rtpp_pin_log';
        $sched = $wpdb->prefix . 'rtpp_scheduled_pins';

        $stats = [
            'total_pinned' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $log WHERE status = 'success'"),
            'total_failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $log WHERE status = 'failed'"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $sched WHERE processed = 0"),
            'recent' => $wpdb->get_results("SELECT l.*, p.post_title as product_name FROM $log l LEFT JOIN {$wpdb->posts} p ON l.product_id = p.ID ORDER BY l.created_at DESC LIMIT 20"),
        ];

        wp_send_json_success($stats);
    }

    public function ajax_get_products() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $settings = get_option('rtpp_settings', []);
        $board_id = $settings['default_board'] ?? '';

        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        $result = [];
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $pinned = $board_id ? $this->is_product_pinned($post->ID, $board_id) : false;
            $scheduled = $board_id ? $this->is_product_scheduled($post->ID, $board_id) : false;

            $result[] = [
                'id' => $post->ID,
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'image' => wp_get_attachment_url($product->get_image_id()),
                'pinned' => $pinned,
                'scheduled' => $scheduled,
            ];
        }

        wp_send_json_success($result);
    }

    public function ajax_get_schedule() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $items = $wpdb->get_results(
            "SELECT s.*, p.post_title as product_name 
             FROM {$wpdb->prefix}rtpp_scheduled_pins s 
             LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID 
             WHERE s.processed = 0 
             ORDER BY s.scheduled_at ASC LIMIT 50"
        );

        wp_send_json_success($items);
    }

    public function ajax_clear_schedule() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}rtpp_scheduled_pins WHERE processed = 0");
        wp_send_json_success('Schedule cleared');
    }

    public function ajax_reset_pinned() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtpp_pin_log");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rtpp_scheduled_pins");
        wp_send_json_success('All pin data reset');
    }

    public function ajax_refresh_user() {
        check_ajax_referer('rtpp_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $user = $this->api_get('/user_account');
        $settings = get_option('rtpp_settings', []);

        if ($user) {
            $settings['user_name'] = $user['username'] ?? $user['business_name'] ?? '';
            $settings['user_data'] = wp_json_encode($user);
            update_option('rtpp_settings', $settings);
            wp_send_json_success([
                'username' => $settings['user_name'],
                'raw' => $user,
            ]);
        } else {
            wp_send_json_error('Could not fetch user info');
        }
    }
}

RT_Pinterest_Poster::get_instance();
