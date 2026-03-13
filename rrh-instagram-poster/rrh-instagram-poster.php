<?php
/**
 * Plugin Name: RRH Instagram Poster
 * Plugin URI: https://rockyriverhills.com
 * Description: Auto-post to Instagram from WooCommerce. Bulk posting, carousels, auto-post on publish, engagement tracking, content calendar, link-in-bio, caption templates, sale announcements, and content recycling.
 * Version: 3.4.1
 * Author: Rocky River Hills
 * Text Domain: rrh-instagram
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('RRH_IG_VERSION', '3.4.1');
define('RRH_IG_PATH', plugin_dir_path(__FILE__));
define('RRH_IG_URL', plugin_dir_url(__FILE__));

require_once RRH_IG_PATH . 'includes/api.php';
require_once RRH_IG_PATH . 'includes/publisher.php';
require_once RRH_IG_PATH . 'includes/templates.php';
require_once RRH_IG_PATH . 'includes/auto-poster.php';
require_once RRH_IG_PATH . 'includes/insights.php';
require_once RRH_IG_PATH . 'includes/link-in-bio.php';

class RRH_Instagram_Poster {

    private static $instance = null;
    private $api;
    private $publisher;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->api = new RRH_IG_API();
        $this->publisher = new RRH_IG_Publisher($this->api);
        new RRH_IG_Auto_Poster($this);
        RRH_IG_Link_In_Bio::init();

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // WooCommerce product custom field
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_hashtag_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_hashtag_field']);

        // Cron actions
        add_action('rrh_ig_process_queue', [$this, 'process_queue']);
        add_action('rrh_ig_refresh_token_cron', [$this, 'cron_refresh_token']);
        add_action('rrh_ig_sync_insights_cron', [$this, 'cron_sync_insights']);
        add_action('rrh_ig_recycle_content_cron', [$this, 'cron_recycle_content']);
        add_action('rrh_ig_autopilot_cron', [$this, 'cron_autopilot']);

        // All AJAX actions
        $ajax_actions = [
            'test_connection','exchange_token','refresh_token','publish_now','delete_post',
            'retry_post','get_products','save_template','delete_template','get_templates',
            'bulk_queue','sync_insights','calendar_data','reschedule_post','save_category_hashtags',
            'save_product_hashtags','edit_post','autopilot_status','autopilot_run_now','autopilot_debug',
        ];
        foreach ($ajax_actions as $a) {
            add_action("wp_ajax_rrh_ig_{$a}", [$this, "ajax_{$a}"]);
        }

        // Image hosting: uses imgBB external hosting to bypass CDN issues
    }

    public function get_api() { return $this->api; }
    public function get_publisher() { return $this->publisher; }

    // ── Activation ──────────────────────────────────────────

    public static function activate() {
        add_filter('cron_schedules', function($s) {
            $s['every_5_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
            return $s;
        });

        global $wpdb;
        $c = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rrh_ig_posts (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_type varchar(20) NOT NULL DEFAULT 'image',
            caption text NOT NULL,
            media_url text NOT NULL,
            media_urls text DEFAULT NULL,
            media_source varchar(20) NOT NULL DEFAULT 'upload',
            woo_product_id bigint(20) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            scheduled_at datetime DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            ig_media_id varchar(100) DEFAULT NULL,
            ig_permalink varchar(500) DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count int(3) NOT NULL DEFAULT 0,
            is_recycled tinyint(1) NOT NULL DEFAULT 0,
            original_post_id bigint(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY status (status), KEY scheduled_at (scheduled_at)
        ) {$c}");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rrh_ig_templates (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            caption_template text NOT NULL,
            hashtags text DEFAULT NULL,
            category varchar(50) DEFAULT 'general',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$c}");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rrh_ig_insights (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            ig_media_id varchar(100) NOT NULL,
            impressions int DEFAULT 0, reach int DEFAULT 0,
            engagement int DEFAULT 0, likes int DEFAULT 0,
            comments int DEFAULT 0, saves int DEFAULT 0, shares int DEFAULT 0,
            synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY post_id (post_id)
        ) {$c}");

        $crons = [
            'rrh_ig_process_queue' => 'every_5_minutes',
            'rrh_ig_refresh_token_cron' => 'daily',
            'rrh_ig_sync_insights_cron' => 'twicedaily',
            'rrh_ig_recycle_content_cron' => 'daily',
            'rrh_ig_autopilot_cron' => 'hourly',
        ];
        foreach ($crons as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) wp_schedule_event(time(), $recurrence, $hook);
        }

        $defaults = [
            'rrh_ig_auto_post_enabled' => '0', 'rrh_ig_auto_post_template' => '',
            'rrh_ig_sale_announce_enabled' => '0', 'rrh_ig_recycle_enabled' => '0',
            'rrh_ig_recycle_days' => '30', 'rrh_ig_recycle_min_engagement' => '10',
            'rrh_ig_linkinbio_enabled' => '0', 'rrh_ig_category_hashtags' => '{}',
            'rrh_ig_autopilot_enabled' => '0', 'rrh_ig_autopilot_posts_per_day' => '1',
            'rrh_ig_autopilot_post_hour' => '10', 'rrh_ig_autopilot_post_minute' => '0',
            'rrh_ig_autopilot_spacing_hours' => '4', 'rrh_ig_autopilot_cooldown_days' => '60',
            'rrh_ig_autopilot_caption_template' => '',
            'rrh_ig_autopilot_default_hashtags' => '#stadiumcoasters #stadiumart #sportsmemories #gameday #collegegameday #tailgate #homebar #rockyriverhills #football #baseball #basketball #hockey #soccer #homedecor #coasters #drinkware #giftideas #sportsbar #mancave #shopsmall #supportsmallbusiness #handmade #smallbiz',
            'rrh_ig_meta_catalog_id' => '',
            'rrh_ig_product_tagging_enabled' => '0',
            'rrh_ig_fb_page_token' => '',
        ];
        foreach ($defaults as $k => $v) add_option($k, $v);

        // Migrate v1 → v2: add new columns to existing table
        $posts_table = $wpdb->prefix . 'rrh_ig_posts';
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$posts_table}", 0);
        if ($cols && !in_array('media_urls', $cols)) {
            $wpdb->query("ALTER TABLE {$posts_table} ADD COLUMN media_urls text DEFAULT NULL AFTER media_url");
        }
        if ($cols && !in_array('is_recycled', $cols)) {
            $wpdb->query("ALTER TABLE {$posts_table} ADD COLUMN is_recycled tinyint(1) NOT NULL DEFAULT 0 AFTER retry_count");
        }
        if ($cols && !in_array('original_post_id', $cols)) {
            $wpdb->query("ALTER TABLE {$posts_table} ADD COLUMN original_post_id bigint(20) DEFAULT NULL AFTER is_recycled");
        }

        update_option('rrh_ig_db_version', RRH_IG_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate() {
        foreach (['rrh_ig_process_queue','rrh_ig_refresh_token_cron','rrh_ig_sync_insights_cron','rrh_ig_recycle_content_cron','rrh_ig_autopilot_cron'] as $h) {
            wp_clear_scheduled_hook($h);
        }
        flush_rewrite_rules();
    }

    public function add_cron_schedules($s) {
        $s['every_5_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        return $s;
    }

    // ── Admin ───────────────────────────────────────────────

    public function register_admin_menu() {
        add_menu_page('Instagram Poster', 'IG Poster', 'manage_options', 'rrh-instagram', [$this, 'page_composer'], 'dashicons-instagram', 58);
        $pages = [
            ['rrh-instagram', 'Compose', 'page_composer'],
            ['rrh-ig-bulk', 'Bulk Post', 'page_bulk'],
            ['rrh-ig-queue', 'Queue', 'page_queue'],
            ['rrh-ig-calendar', 'Calendar', 'page_calendar'],
            ['rrh-ig-insights', 'Insights', 'page_insights'],
            ['rrh-ig-product-hashtags', 'Product Tags', 'page_product_hashtags'],
            ['rrh-ig-settings', 'Settings', 'page_settings'],
        ];
        foreach ($pages as $p) {
            add_submenu_page('rrh-instagram', $p[1], $p[1], 'manage_options', $p[0], [$this, $p[2]]);
        }
    }

    public function register_settings() {
        // API tab
        register_setting('rrh_ig_api', 'rrh_ig_app_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_app_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_access_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_user_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_imgbb_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_meta_catalog_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('rrh_ig_api', 'rrh_ig_product_tagging_enabled');
        register_setting('rrh_ig_api', 'rrh_ig_fb_page_token', ['sanitize_callback' => 'sanitize_text_field']);
        // Automation tab
        register_setting('rrh_ig_automation', 'rrh_ig_auto_post_enabled');
        register_setting('rrh_ig_automation', 'rrh_ig_auto_post_template');
        register_setting('rrh_ig_automation', 'rrh_ig_sale_announce_enabled');
        register_setting('rrh_ig_automation', 'rrh_ig_recycle_enabled');
        register_setting('rrh_ig_automation', 'rrh_ig_recycle_days');
        register_setting('rrh_ig_automation', 'rrh_ig_recycle_min_engagement');
        // Autopilot settings
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_enabled');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_posts_per_day');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_post_hour');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_post_minute');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_spacing_hours');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_cooldown_days');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_caption_template');
        register_setting('rrh_ig_automation', 'rrh_ig_autopilot_default_hashtags');
        // Link in Bio tab
        register_setting('rrh_ig_linkinbio', 'rrh_ig_linkinbio_enabled');
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rrh-ig') === false && strpos($hook, 'rrh-instagram') === false) return;
        wp_enqueue_media();
        wp_enqueue_style('rrh-ig-admin', RRH_IG_URL . 'assets/admin.css', [], RRH_IG_VERSION);
        wp_enqueue_script('rrh-ig-admin', RRH_IG_URL . 'assets/admin.js', ['jquery'], RRH_IG_VERSION, true);
        wp_localize_script('rrh-ig-admin', 'rrhIG', [
            'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('rrh_ig_nonce'),
        ]);
    }

    public function page_composer() { require RRH_IG_PATH . 'admin/composer.php'; }
    public function page_queue() { require RRH_IG_PATH . 'admin/queue.php'; }
    public function page_settings() { require RRH_IG_PATH . 'admin/settings.php'; }
    public function page_product_hashtags() { require RRH_IG_PATH . 'admin/product-hashtags.php'; }
    public function page_bulk() { require RRH_IG_PATH . 'admin/bulk.php'; }
    public function page_insights() { require RRH_IG_PATH . 'admin/insights.php'; }
    public function page_calendar() { require RRH_IG_PATH . 'admin/calendar.php'; }

    // ── Post CRUD ───────────────────────────────────────────

    public function create_post($data) {
        global $wpdb;
        $insert = [
            'post_type' => $data['post_type'] ?? 'image',
            'caption' => $data['caption'], 'media_url' => $data['media_url'],
            'media_urls' => $data['media_urls'] ?? null,
            'media_source' => $data['media_source'] ?? 'upload',
            'woo_product_id' => $data['woo_product_id'] ?: null,
            'status' => $data['status'] ?? 'queued',
            'scheduled_at' => $data['scheduled_at'] ?: null,
            'is_recycled' => $data['is_recycled'] ?? 0,
            'original_post_id' => $data['original_post_id'] ?? null,
            'created_at' => current_time('mysql'),
        ];
        $result = $wpdb->insert($wpdb->prefix . 'rrh_ig_posts', $insert);
        return $result === false ? new WP_Error('db_error', $wpdb->last_error) : $wpdb->insert_id;
    }

    // ── WooCommerce Product Hashtag Field ──────────────────

    public function add_product_hashtag_field() {
        woocommerce_wp_text_input([
            'id' => '_rrh_ig_hashtags',
            'label' => '📸 Instagram Hashtags',
            'description' => 'City/team hashtags for this product (e.g. #osu #buckeyes #columbus)',
            'desc_tip' => true,
            'placeholder' => '#team #city #nickname',
        ]);
    }

    public function save_product_hashtag_field($post_id) {
        if (isset($_POST['_rrh_ig_hashtags'])) {
            update_post_meta($post_id, '_rrh_ig_hashtags', sanitize_text_field($_POST['_rrh_ig_hashtags']));
        }
    }

    // ── Category Hashtags Helper ────────────────────────────

    public function get_category_hashtags_for_product($product_id) {
        $map = json_decode(get_option('rrh_ig_category_hashtags', '{}'), true);
        if (empty($map)) return '';
        $cat_ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $tags = [];
        foreach ($cat_ids as $cid) { if (!empty($map[$cid])) $tags[] = trim($map[$cid]); }
        return implode(' ', array_unique(explode(' ', implode(' ', $tags))));
    }

    // ── AJAX Handlers ───────────────────────────────────────

    private function check_admin_ajax() {
        check_ajax_referer('rrh_ig_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    }

    public function ajax_test_connection() {
        $this->check_admin_ajax();
        $r = $this->api->get_account_info();
        $r['success'] ? wp_send_json_success($r['data']) : wp_send_json_error($r['error']);
    }

    public function ajax_exchange_token() {
        $this->check_admin_ajax();
        $r = $this->api->exchange_for_long_lived_token();
        $r['success'] ? wp_send_json_success('Token exchanged! Expires: ' . $r['expires']) : wp_send_json_error($r['error']);
    }

    public function ajax_refresh_token() {
        $this->check_admin_ajax();
        $r = $this->api->refresh_token();
        $r['success'] ? wp_send_json_success('Token refreshed! Expires: ' . $r['expires']) : wp_send_json_error($r['error']);
    }

    public function ajax_publish_now() {
        $this->check_admin_ajax();
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            $caption = sanitize_textarea_field(wp_unslash($_POST['caption'] ?? ''));
            $woo_id = intval($_POST['woo_product_id'] ?? 0);

            // Auto-replace template variables if a product is selected
            if ($woo_id && strpos($caption, '{') !== false) {
                $caption = RRH_IG_Publisher::generate_product_caption($woo_id, $caption);
            }

            $post_id = $this->create_post([
                'post_type' => sanitize_text_field($_POST['post_type'] ?? 'image'),
                'caption' => $caption,
                'media_url' => esc_url_raw($_POST['media_url'] ?? ''),
                'media_urls' => isset($_POST['media_urls']) ? sanitize_textarea_field($_POST['media_urls']) : null,
                'media_source' => sanitize_text_field($_POST['media_source'] ?? 'upload'),
                'woo_product_id' => intval($_POST['woo_product_id'] ?? 0),
                'status' => 'queued',
            ]);
            if (is_wp_error($post_id)) wp_send_json_error($post_id->get_error_message());
        }
        $r = $this->publisher->publish_post($post_id);
        $r['success'] ? wp_send_json_success($r) : wp_send_json_error($r['error']);
    }

    public function ajax_delete_post() {
        $this->check_admin_ajax();
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rrh_ig_posts', ['id' => intval($_POST['post_id'])], ['%d']);
        wp_send_json_success('Deleted');
    }

    public function ajax_retry_post() {
        $this->check_admin_ajax();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'rrh_ig_posts',
            ['status' => 'queued', 'retry_count' => 0, 'error_message' => null],
            ['id' => intval($_POST['post_id'])]
        );
        wp_send_json_success('Requeued');
    }

    public function ajax_get_products() {
        check_ajax_referer('rrh_ig_nonce', 'nonce');
        $args = ['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 30, 'orderby' => 'title', 'order' => 'ASC'];
        $search = sanitize_text_field($_GET['search'] ?? '');
        if ($search) $args['s'] = $search;

        $results = [];
        foreach (get_posts($args) as $p) {
            $wc = wc_get_product($p->ID);
            $gallery_ids = $wc->get_gallery_image_ids();
            $gallery = array_filter(array_map([self::class, 'get_proxy_image_url'], $gallery_ids));
            $results[] = [
                'id' => $p->ID, 'title' => $p->post_title,
                'price' => $wc->get_price(), 'sale_price' => $wc->get_sale_price(),
                'image_url' => self::get_proxy_image_url($wc->get_image_id()),
                'gallery_urls' => array_values($gallery),
                'permalink' => get_permalink($p->ID),
                'short_desc' => wp_trim_words($wc->get_short_description(), 20),
                'categories' => wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'names']),
                'product_hashtags' => get_post_meta($p->ID, '_rrh_ig_hashtags', true) ?: '',
                'category_hashtags' => $this->get_category_hashtags_for_product($p->ID),
            ];
        }
        wp_send_json_success($results);
    }

    public function ajax_save_template() {
        $this->check_admin_ajax();
        RRH_IG_Templates::save($_POST);
        wp_send_json_success('Template saved!');
    }

    public function ajax_delete_template() {
        $this->check_admin_ajax();
        RRH_IG_Templates::delete(intval($_POST['template_id']));
        wp_send_json_success('Deleted');
    }

    public function ajax_get_templates() {
        check_ajax_referer('rrh_ig_nonce', 'nonce');
        wp_send_json_success(RRH_IG_Templates::get_all());
    }

    public function ajax_bulk_queue() {
        $this->check_admin_ajax();
        $product_ids = array_map('intval', $_POST['product_ids'] ?? []);
        $template_id = intval($_POST['template_id'] ?? 0);
        $interval = max(1, intval($_POST['interval_hours'] ?? 4));
        $start = sanitize_text_field($_POST['start_date'] ?? '');
        $carousel = !empty($_POST['use_carousel']);

        if (empty($product_ids)) wp_send_json_error('No products selected');

        $tmpl = $template_id ? RRH_IG_Templates::get($template_id) : null;
        $time = $start ? strtotime($start) : time();
        $queued = 0;

        foreach ($product_ids as $pid) {
            $wc = wc_get_product($pid);
            if (!$wc) continue;
            $img = self::get_proxy_image_url($wc->get_image_id());
            if (!$img) continue;

            $caption = RRH_IG_Publisher::generate_product_caption($pid, $tmpl ? $tmpl->caption_template : '');

            // Smart hashtags: template hashtags OR (brand + category + product-specific)
            if ($tmpl && $tmpl->hashtags) {
                $caption .= "\n\n" . $tmpl->hashtags;
            } else {
                $all_tags = ['#stadiumcoasters #handmade #rockyriverhills #shopsmall #supportsmallbusiness'];
                $ch = $this->get_category_hashtags_for_product($pid);
                if ($ch) $all_tags[] = $ch;
                $ph = get_post_meta($pid, '_rrh_ig_hashtags', true);
                if ($ph) $all_tags[] = $ph;
                $combined = array_unique(array_filter(explode(' ', implode(' ', $all_tags)), function($t) {
                    return strpos($t, '#') === 0;
                }));
                if (!empty($combined)) $caption .= "\n\n" . implode(' ', $combined);
            }

            $type = 'image'; $murls = null;
            if ($carousel) {
                $gallery_ids = $wc->get_gallery_image_ids();
                $gallery = array_filter(array_map([self::class, 'get_proxy_image_url'], $gallery_ids));
                if (!empty($gallery)) {
                    $all = array_merge([$img], array_slice($gallery, 0, 9));
                    if (count($all) > 1) { $type = 'carousel'; $murls = json_encode($all); }
                }
            }

            $this->create_post([
                'post_type' => $type, 'caption' => $caption, 'media_url' => $img,
                'media_urls' => $murls, 'media_source' => 'woocommerce',
                'woo_product_id' => $pid, 'status' => 'queued',
                'scheduled_at' => date('Y-m-d H:i:s', $time),
            ]);
            $time += ($interval * HOUR_IN_SECONDS);
            $queued++;
        }
        wp_send_json_success(['queued' => $queued]);
    }

    public function ajax_sync_insights() {
        $this->check_admin_ajax();
        $insights = new RRH_IG_Insights($this->api);
        $synced = $insights->sync_all_published_posts();
        wp_send_json_success(['synced' => $synced]);
    }

    public function ajax_calendar_data() {
        check_ajax_referer('rrh_ig_nonce', 'nonce');
        global $wpdb;
        $t = $wpdb->prefix . 'rrh_ig_posts';
        $m = intval($_GET['month'] ?? date('n'));
        $y = intval($_GET['year'] ?? date('Y'));
        $start = "{$y}-" . str_pad($m, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
        $end = date('Y-m-t 23:59:59', strtotime($start));

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_type, caption, media_url, status, 
                    COALESCE(scheduled_at, created_at) as display_date, published_at, ig_permalink
             FROM {$t} WHERE (scheduled_at BETWEEN %s AND %s) 
                OR (scheduled_at IS NULL AND created_at BETWEEN %s AND %s)
                OR (published_at BETWEEN %s AND %s) ORDER BY display_date ASC",
            $start, $end, $start, $end, $start, $end
        ));
        wp_send_json_success($posts);
    }

    public function ajax_reschedule_post() {
        $this->check_admin_ajax();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'rrh_ig_posts',
            ['scheduled_at' => sanitize_text_field($_POST['new_date'])],
            ['id' => intval($_POST['post_id'])]
        );
        wp_send_json_success('Rescheduled');
    }

    public function ajax_save_category_hashtags() {
        $this->check_admin_ajax();
        $map = [];
        if (isset($_POST['hashtags']) && is_array($_POST['hashtags'])) {
            foreach ($_POST['hashtags'] as $cid => $tags) {
                $map[intval($cid)] = sanitize_textarea_field($tags);
            }
        }
        update_option('rrh_ig_category_hashtags', json_encode($map));
        wp_send_json_success('Saved');
    }

    public function ajax_save_product_hashtags() {
        $this->check_admin_ajax();
        $saved = 0;
        if (isset($_POST['product_hashtags']) && is_array($_POST['product_hashtags'])) {
            foreach ($_POST['product_hashtags'] as $pid => $tags) {
                update_post_meta(intval($pid), '_rrh_ig_hashtags', sanitize_text_field($tags));
                $saved++;
            }
        }
        wp_send_json_success(['saved' => $saved]);
    }

    public function ajax_edit_post() {
        $this->check_admin_ajax();
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';
        $id = intval($_POST['post_id']);
        $caption = sanitize_textarea_field(wp_unslash($_POST['caption']));
        $scheduled_at = !empty($_POST['scheduled_at']) ? sanitize_text_field($_POST['scheduled_at']) : null;

        $update = ['caption' => $caption, 'scheduled_at' => $scheduled_at];
        $wpdb->update($table, $update, ['id' => $id]);

        $display = $scheduled_at ? date('M j, g:i a', strtotime($scheduled_at)) : '—';
        wp_send_json_success(['scheduled_display' => $display]);
    }

    // ── Cron Jobs ───────────────────────────────────────────

    /**
     * Get image URL for admin display (thumbnails in composer, queue, etc.)
     * The publisher builds its own CDN-bypassing URLs at publish time.
     */
    public static function get_proxy_image_url($attachment_id) {
        $id = intval($attachment_id);
        if (!$id) return '';
        return wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_url($id) ?: '';
    }

    public function process_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';

        // Reset any posts stuck in 'publishing' for more than 20 minutes
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='queued', error_message='Reset: was stuck in publishing' 
             WHERE status='publishing' AND created_at < %s",
            date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -20 minutes'))
        ));

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at<=%s) AND retry_count<3
             ORDER BY scheduled_at ASC, created_at ASC LIMIT 1",
            current_time('mysql')
        ));
        foreach ($posts as $p) { $this->publisher->publish_post($p->id); sleep(2); }
    }


    public function cron_refresh_token() {
        // Refresh Instagram token if within 7 days of expiry
        $exp = get_option('rrh_ig_token_expires', '');
        if ($exp && (strtotime($exp) - time()) / DAY_IN_SECONDS < 7) $this->api->refresh_token();

        // Refresh Facebook token if within 7 days of expiry
        $fb_exp = get_option('rrh_ig_fb_token_expires', '');
        if ($fb_exp && (strtotime($fb_exp) - time()) / DAY_IN_SECONDS < 7) {
            $this->api->refresh_fb_token();
        }
        // If no expiry date stored yet but token exists, set one (assumes 60 days from now)
        if (!$fb_exp && get_option('rrh_ig_fb_page_token', '')) {
            update_option('rrh_ig_fb_token_expires', date('Y-m-d H:i:s', time() + 5184000));
        }
    }

    public function cron_sync_insights() {
        (new RRH_IG_Insights($this->api))->sync_all_published_posts();
    }

    public function cron_autopilot() {
        $auto_poster = new RRH_IG_Auto_Poster($this);
        $auto_poster->run_autopilot();
    }

    public function ajax_autopilot_status() {
        $this->check_admin_ajax();
        $auto_poster = new RRH_IG_Auto_Poster($this);
        wp_send_json_success($auto_poster->get_autopilot_status());
    }

    public function ajax_autopilot_run_now() {
        $this->check_admin_ajax();
        $auto_poster = new RRH_IG_Auto_Poster($this);
        $auto_poster->run_autopilot();
        wp_send_json_success([
            'message' => 'Autopilot triggered!',
            'status' => $auto_poster->get_autopilot_status(),
        ]);
    }

    public function ajax_autopilot_debug() {
        $this->check_admin_ajax();

        // 1. Get all templates with their categories
        $templates = RRH_IG_Templates::get_all();
        $template_info = [];
        foreach ($templates as $t) {
            $template_info[] = [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category,
                'category_lower' => strtolower(trim($t->category)),
                'preview' => substr($t->caption_template, 0, 80) . '...',
            ];
        }

        // 2. Get all WooCommerce product categories
        $woo_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $cat_info = [];
        foreach ($woo_cats as $cat) {
            $cat_info[] = [
                'name' => $cat->name,
                'slug' => $cat->slug,
                'name_lower' => strtolower(trim($cat->name)),
                'count' => $cat->count,
            ];
        }

        // 3. Simulate matching for a few sample products
        $auto_poster = new RRH_IG_Auto_Poster($this);
        $sample_matches = [];
        $products = wc_get_products(['status' => 'publish', 'limit' => 10, 'return' => 'ids']);
        foreach ($products as $pid) {
            $p = wc_get_product($pid);
            $terms = wp_get_post_terms($pid, 'product_cat');

            // Walk parent chain (same logic as build_smart_caption)
            $pcat_names = [];
            $pcat_slugs = [];
            foreach ($terms as $term) {
                $pcat_names[] = strtolower(trim($term->name));
                $pcat_slugs[] = strtolower(trim($term->slug));
                $parent_id = $term->parent;
                while ($parent_id > 0) {
                    $parent = get_term($parent_id, 'product_cat');
                    if ($parent && !is_wp_error($parent)) {
                        $pcat_names[] = strtolower(trim($parent->name));
                        $pcat_slugs[] = strtolower(trim($parent->slug));
                        $parent_id = $parent->parent;
                    } else {
                        break;
                    }
                }
            }
            $pcat_names = array_unique($pcat_names);
            $pcat_slugs = array_unique($pcat_slugs);

            $matched_templates = [];
            $general_templates = [];
            foreach ($templates as $tmpl) {
                $tc = strtolower(trim($tmpl->category));
                if ($tc === 'general' || empty($tc)) {
                    $general_templates[] = $tmpl->name;
                } elseif ($auto_poster->category_matches($tc, $pcat_names, $pcat_slugs)) {
                    $matched_templates[] = $tmpl->name . " (cat: {$tmpl->category})";
                }
            }

            $sample_matches[] = [
                'product' => $p->get_name(),
                'product_categories' => array_map(function($t) { return $t->name . " [slug: {$t->slug}]"; }, $terms),
                'full_chain' => array_unique(array_merge($pcat_names, $pcat_slugs)),
                'matched_templates' => $matched_templates,
                'general_fallback' => $general_templates,
                'would_use' => !empty($matched_templates) ? 'CATEGORY MATCH' : (!empty($general_templates) ? 'GENERAL FALLBACK' : 'BUILT-IN DEFAULT'),
            ];
        }

        wp_send_json_success([
            'templates' => $template_info,
            'woo_categories' => $cat_info,
            'sample_matches' => $sample_matches,
        ]);
    }

    public function cron_recycle_content() {
        if (get_option('rrh_ig_recycle_enabled') !== '1') return;
        global $wpdb;
        $t = $wpdb->prefix . 'rrh_ig_posts';
        $it = $wpdb->prefix . 'rrh_ig_insights';
        $days = intval(get_option('rrh_ig_recycle_days', 30));
        $min = intval(get_option('rrh_ig_recycle_min_engagement', 10));

        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, i.engagement FROM {$t} p JOIN {$it} i ON p.id=i.post_id
             WHERE p.status='published' AND p.published_at < DATE_SUB(NOW(), INTERVAL %d DAY)
             AND i.engagement >= %d AND p.id NOT IN (
                SELECT original_post_id FROM {$t} WHERE is_recycled=1 AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             ) ORDER BY i.engagement DESC LIMIT 1",
            $days, $min, $days
        ));

        if ($post) {
            $this->create_post([
                'post_type' => $post->post_type, 'caption' => $post->caption,
                'media_url' => $post->media_url, 'media_urls' => $post->media_urls,
                'media_source' => $post->media_source, 'woo_product_id' => $post->woo_product_id,
                'status' => 'queued', 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day 10:00:00')),
                'is_recycled' => 1, 'original_post_id' => $post->id,
            ]);
        }
    }
}

register_activation_hook(__FILE__, ['RRH_Instagram_Poster', 'activate']);
register_deactivation_hook(__FILE__, ['RRH_Instagram_Poster', 'deactivate']);
add_action('plugins_loaded', ['RRH_Instagram_Poster', 'instance']);
