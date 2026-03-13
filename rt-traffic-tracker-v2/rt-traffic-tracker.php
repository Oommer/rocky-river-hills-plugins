<?php
/**
 * Plugin Name: Real-Time Traffic Tracker
 * Plugin URI: https://rockyriverhills.com
 * Description: Beautiful real-time traffic tracking with geographic data, page visits, referrers, conversion funnels, UTM campaigns, and more
 * Version: 3.2.1
 * Author: Brady Kohler and Claude
 * License: GPL v2 or later
 * Text Domain: rt-traffic-tracker
 */

if (!defined('ABSPATH')) exit;

class RT_Traffic_Tracker {

    private $table_name;
    private $last_visit_id = 0;
    private $version = '3.2.1';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rt_traffic_stats';

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('admin_init', array($this, 'check_db_upgrade'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp', array($this, 'track_visit'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_script'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // AJAX - admin
        add_action('wp_ajax_rt_get_stats',            array($this, 'ajax_get_stats'));
        add_action('wp_ajax_rt_get_realtime',         array($this, 'ajax_get_realtime'));
        add_action('wp_ajax_rt_get_visitor_profile',  array($this, 'ajax_get_visitor_profile'));
        add_action('wp_ajax_rt_get_map_data',         array($this, 'ajax_get_map_data'));
        add_action('wp_ajax_rt_purge_data',           array($this, 'ajax_purge_data'));
        add_action('wp_ajax_rt_purge_countries',      array($this, 'ajax_purge_countries'));
        add_action('wp_ajax_rt_save_country_filter',  array($this, 'ajax_save_country_filter'));
        add_action('wp_ajax_rt_get_insights',         array($this, 'ajax_get_insights'));
        add_action('wp_ajax_rt_export_csv',           array($this, 'ajax_export_csv'));
        add_action('wp_ajax_rt_save_digest_settings', array($this, 'ajax_save_digest_settings'));
        add_action('wp_ajax_rt_send_test_digest',     array($this, 'ajax_send_test_digest'));
        add_action('wp_ajax_rt_get_visitor_flow',     array($this, 'ajax_get_visitor_flow'));
        add_action('wp_ajax_rt_get_bot_analysis',     array($this, 'ajax_get_bot_analysis')); // NEW 3.2.0

        // AJAX - public
        add_action('wp_ajax_rt_record_load_time',        array($this, 'ajax_record_load_time'));
        add_action('wp_ajax_nopriv_rt_record_load_time', array($this, 'ajax_record_load_time'));

        add_action('rt_weekly_email_digest', array($this, 'send_weekly_digest'));
    }

    // =========================================================
    // ACTIVATION / DB
    // =========================================================

    public function activate() {
        $this->create_or_update_table();
        update_option('rt_traffic_tracker_version', $this->version);
        if (!wp_next_scheduled('rt_weekly_email_digest')) {
            wp_schedule_event(strtotime('next monday 9:00am'), 'weekly', 'rt_weekly_email_digest');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rt_weekly_email_digest');
    }

    public function check_db_upgrade() {
        $installed = get_option('rt_traffic_tracker_version', '0');
        if (version_compare($installed, $this->version, '<')) {
            $this->create_or_update_table();
            update_option('rt_traffic_tracker_version', $this->version);
        }
        if (get_option('rt_digest_enabled', '0') === '1' && !wp_next_scheduled('rt_weekly_email_digest')) {
            wp_schedule_event(strtotime('next monday 9:00am'), 'weekly', 'rt_weekly_email_digest');
        }
    }

    private function create_or_update_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            visit_time datetime NOT NULL,
            page_url varchar(500) NOT NULL,
            page_title varchar(255) DEFAULT '',
            referrer varchar(500) DEFAULT '',
            ip_address varchar(45) NOT NULL,
            country varchar(100) DEFAULT '',
            country_code varchar(2) DEFAULT '',
            region varchar(100) DEFAULT '',
            city varchar(100) DEFAULT '',
            latitude decimal(10,6) DEFAULT NULL,
            longitude decimal(10,6) DEFAULT NULL,
            user_agent varchar(500) DEFAULT '',
            browser varchar(50) DEFAULT '',
            device varchar(50) DEFAULT '',
            os varchar(50) DEFAULT '',
            load_time int(11) DEFAULT NULL,
            utm_source varchar(200) DEFAULT '',
            utm_medium varchar(200) DEFAULT '',
            utm_campaign varchar(200) DEFAULT '',
            utm_content varchar(200) DEFAULT '',
            utm_term varchar(200) DEFAULT '',
            search_query varchar(500) DEFAULT '',
            PRIMARY KEY  (id),
            KEY visit_time (visit_time),
            KEY country_code (country_code),
            KEY ip_address (ip_address),
            KEY page_url (page_url(191))
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // =========================================================
    // FRONTEND LOAD TIME SCRIPT
    // =========================================================

    public function enqueue_frontend_script() {
        if (is_admin() || current_user_can('manage_options') || $this->is_bot()) return;
        add_action('wp_footer', array($this, 'output_load_time_script'), 999);
    }

    public function output_load_time_script() {
        if ($this->last_visit_id <= 0) return;
        $ajax_url = admin_url('admin-ajax.php');
        $visit_id = intval($this->last_visit_id);
        $nonce    = wp_create_nonce('rt_load_time_nonce');
        ?>
        <script>
        (function() {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    var loadTime = 0;
                    if (window.performance && window.performance.timing) {
                        var t = window.performance.timing;
                        loadTime = t.loadEventEnd - t.navigationStart;
                    } else if (window.performance && window.performance.getEntriesByType) {
                        var nav = performance.getEntriesByType('navigation');
                        if (nav.length > 0) loadTime = Math.round(nav[0].loadEventEnd);
                    }
                    if (loadTime > 0 && loadTime < 60000) {
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', '<?php echo esc_url($ajax_url); ?>', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.send('action=rt_record_load_time&visit_id=<?php echo $visit_id; ?>&load_time=' + loadTime + '&nonce=<?php echo $nonce; ?>');
                    }
                }, 100);
            });
        })();
        </script>
        <?php
    }

    public function ajax_record_load_time() {
        check_ajax_referer('rt_load_time_nonce', 'nonce');
        global $wpdb;
        $visit_id  = isset($_POST['visit_id'])  ? intval($_POST['visit_id'])  : 0;
        $load_time = isset($_POST['load_time']) ? intval($_POST['load_time']) : 0;
        if ($visit_id > 0 && $load_time > 0 && $load_time < 60000) {
            $wpdb->update($this->table_name, array('load_time' => $load_time), array('id' => $visit_id), array('%d'), array('%d'));
        }
        wp_send_json_success();
    }

    // =========================================================
    // BOT DETECTION
    // =========================================================

    private function is_bot() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $ua_lower   = strtolower($user_agent);

        if (empty($user_agent) || strlen($user_agent) < 20) return true;

        $bot_keywords = array(
            'bot','crawl','spider','slurp','scraper','fetch','monitor',
            'curl','wget','httpie','python','java/','perl','ruby','php/',
            'go-http-client','node-fetch','axios','undici',
            'googlebot','bingbot','yandexbot','baiduspider','duckduckbot',
            'sogou','exabot','ia_archiver','archive.org',
            'semrush','ahrefs','moz.com','majestic','dotbot','rogerbot',
            'screaming frog','deepcrawl','sitebulb','seokicks',
            'facebookexternalhit','twitterbot','linkedinbot','pinterestbot',
            'whatsapp','telegrambot','discordbot','slackbot',
            'uptimerobot','pingdom','statuscake','newrelic','datadog',
            'site24x7','gtmetrix','pagespeed','lighthouse',
            'checkhost','monitor','healthcheck','probe',
            'headlesschrome','phantomjs','selenium','puppeteer','playwright','webdriver','chromedriver',
            'feedfetcher','feedly','newsblur','w3c_validator','validator',
            'nmap','nikto','sqlmap','masscan','zgrab','censys',
            'amazonbot','petalbot','bytespider','gptbot','claudebot',
            'anthropic','chatgpt','cohere-ai','applebot',
            'mj12bot','blexbot','seznambot','ccbot','zoominfobot',
            'ahc/','netcraft','megaindex','buck/','newspaper',
            'postman','insomnia','paw/','httpunit','httrack',
        );
        foreach ($bot_keywords as $kw) {
            if (strpos($ua_lower, $kw) !== false) return true;
        }

        $browser_indicators = array('mozilla/','chrome/','safari/','firefox/','edge/','opera/','msie','trident/','gecko/','webkit/','presto/');
        $looks_like_browser = false;
        foreach ($browser_indicators as $b) {
            if (strpos($ua_lower, $b) !== false) { $looks_like_browser = true; break; }
        }
        if (!$looks_like_browser) return true;

        $ip = $this->get_client_ip();
        $datacenter_ranges = array(
            '3.','13.','18.','34.','35.','44.','46.137.','50.16.','50.17.','50.18.','50.19.',
            '52.','54.','63.','64.252.','72.21.','75.101.','76.223.','99.77.','99.78.',
            '100.20.','100.21.','107.20.','107.21.','107.22.','107.23.',
            '174.129.','176.34.','184.72.','184.73.','204.236.',
            '104.196.','104.197.','104.198.','104.199.',
            '130.211.','146.148.','162.222.','173.255.',
            '13.64.','13.65.','13.66.','13.67.','13.68.','13.69.','13.70.','13.71.',
            '13.72.','13.73.','13.74.','13.75.','13.76.','13.77.','13.78.','13.79.',
            '13.80.','13.81.','13.82.','13.83.','13.84.','13.85.','13.86.','13.87.',
            '13.88.','13.89.','13.90.','13.91.','13.92.','13.93.','13.94.','13.95.',
            '20.','23.96.','23.97.','23.98.','23.99.','23.100.','23.101.','23.102.',
            '40.','51.','65.52.','104.40.','104.41.','104.42.','104.43.','104.44.',
            '104.45.','104.46.','104.47.','104.208.','104.209.','104.210.','104.211.',
            '104.214.','104.215.',
            '104.131.','104.236.','107.170.','128.199.','138.68.','138.197.','139.59.',
            '142.93.','143.110.','143.198.','144.126.','146.190.','147.182.','157.230.',
            '159.65.','159.89.','159.203.','161.35.','162.243.','163.47.','164.90.',
            '164.92.','165.22.','165.227.','167.71.','167.99.','167.172.','174.138.',
            '178.62.','178.128.','188.166.','192.81.','198.199.','198.211.','206.81.',
            '206.189.','209.97.',
            '116.202.','116.203.','128.140.','135.181.','136.243.','138.201.',
            '142.132.','144.76.','148.251.','157.90.','159.69.','162.55.','168.119.',
            '176.9.','178.63.','188.40.','195.201.',
            '51.38.','51.68.','51.75.','51.77.','51.79.','51.81.','51.83.','51.89.','51.91.',
            '51.161.','51.178.','51.195.','51.210.','51.222.',
            '54.36.','54.37.','54.38.','54.39.',
            '91.134.','92.222.','137.74.','139.99.','141.94.','142.4.','144.217.',
            '145.239.','147.135.','148.113.','149.56.','158.69.','164.132.','167.114.',
            '176.31.','178.32.','185.12.','188.165.','192.95.','192.99.','193.70.',
            '195.154.','198.27.','198.50.','198.100.','209.250.',
        );
        foreach ($datacenter_ranges as $range) {
            if (strpos($ip, $range) === 0) return true;
        }

        return false;
    }

    // =========================================================
    // TRACKING
    // =========================================================

    private function get_client_ip() {
        // Cloudflare passes real visitor IP in CF-Connecting-IP — check this first
        $headers = array('HTTP_CF_CONNECTING_IP','HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR');
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    public function track_visit() {
        if (is_admin() || current_user_can('manage_options')) return;
        if (isset($_GET['wc-ajax']) || strpos($_SERVER['REQUEST_URI'], 'wc-ajax') !== false) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false) return;
        if (defined('DOING_CRON') && DOING_CRON) return;

        $uri = $_SERVER['REQUEST_URI'];
        $junk_patterns = array(
            '/.well-known/','/wp-content/litespeed/','/wp-content/cache/',
            '/wp-content/uploads/','/wp-includes/','/wp-admin/',
            '.css','.js','.map','.png','.jpg','.jpeg','.gif','.svg','.webp','.ico',
            '.woff','.woff2','.ttf','.eot','.xml','.txt','.json',
            '/xmlrpc.php','/wp-login.php','/robots.txt','/sitemap','/feed',
            '.env','/.aws','/.git','/.svn','/.htaccess','/.htpasswd',
            '/wp-config','/phpinfo','/composer.','/package.json',
            '/node_modules/','/vendor/','/debug','/backup','/phpmyadmin','/adminer',
        );
        foreach ($junk_patterns as $pattern) {
            if (strpos($uri, $pattern) !== false) return;
        }

        if (is_404() || $this->is_bot()) return;

        global $wpdb;

        $ip           = $this->get_client_ip();
        $user_agent   = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $referrer     = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $browser_data = $this->parse_user_agent($user_agent);
        $geo_data     = $this->get_geo_data($ip);

        if ($this->is_country_excluded($geo_data['country_code'])) return;

        $current_url = home_url($_SERVER['REQUEST_URI']);
        $page_title  = wp_title('', false);
        if (empty($page_title)) $page_title = get_bloginfo('name');

        $insert_data = array(
            'visit_time'   => current_time('mysql'),
            'page_url'     => $current_url,
            'page_title'   => $page_title,
            'referrer'     => $referrer,
            'ip_address'   => $ip,
            'country'      => $geo_data['country'],
            'country_code' => $geo_data['country_code'],
            'region'       => $geo_data['region'],
            'city'         => $geo_data['city'],
            'user_agent'   => $user_agent,
            'browser'      => $browser_data['browser'],
            'device'       => $browser_data['device'],
            'os'           => $browser_data['os'],
            'utm_source'   => isset($_GET['utm_source'])   ? sanitize_text_field($_GET['utm_source'])   : '',
            'utm_medium'   => isset($_GET['utm_medium'])   ? sanitize_text_field($_GET['utm_medium'])   : '',
            'utm_campaign' => isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '',
            'utm_content'  => isset($_GET['utm_content'])  ? sanitize_text_field($_GET['utm_content'])  : '',
            'utm_term'     => isset($_GET['utm_term'])      ? sanitize_text_field($_GET['utm_term'])     : '',
            'search_query' => isset($_GET['s'])             ? sanitize_text_field($_GET['s'])            : '',
        );
        $format = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');

        if ($geo_data['latitude'] !== null && $geo_data['longitude'] !== null) {
            $lat = floatval($geo_data['latitude']);
            $lon = floatval($geo_data['longitude']);
            if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
                $insert_data['latitude']  = number_format($lat, 6, '.', '');
                $insert_data['longitude'] = number_format($lon, 6, '.', '');
                $format[] = '%s'; $format[] = '%s';
            }
        }

        $wpdb->insert($this->table_name, $insert_data, $format);
        $this->last_visit_id = $wpdb->insert_id;
    }

    private function get_geo_data($ip) {
        $default = array('country'=>'','country_code'=>'','region'=>'','city'=>'','latitude'=>null,'longitude'=>null);
        if ($ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) return $default;

        $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=country,countryCode,regionName,city,lat,lon", array('timeout' => 3));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return $default;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || !isset($data['country'])) return $default;

        return array(
            'country'      => $data['country'],
            'country_code' => $data['countryCode'] ?? '',
            'region'       => $data['regionName']  ?? '',
            'city'         => $data['city']        ?? '',
            'latitude'     => isset($data['lat']) ? floatval($data['lat']) : null,
            'longitude'    => isset($data['lon']) ? floatval($data['lon']) : null,
        );
    }

    private function parse_user_agent($user_agent) {
        $browser = 'Unknown'; $device = 'Desktop'; $os = 'Unknown';
        if (preg_match('/Firefox\//i', $user_agent))          $browser = 'Firefox';
        elseif (preg_match('/Chrome\//i', $user_agent))       $browser = 'Chrome';
        elseif (preg_match('/Safari\//i', $user_agent))       $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $user_agent))   $browser = 'Internet Explorer';
        elseif (preg_match('/Edge\//i', $user_agent))         $browser = 'Edge';
        if (preg_match('/mobile/i', $user_agent))             $device = 'Mobile';
        elseif (preg_match('/tablet|ipad/i', $user_agent))    $device = 'Tablet';
        if (preg_match('/windows/i', $user_agent))            $os = 'Windows';
        elseif (preg_match('/macintosh|mac os x/i', $user_agent)) $os = 'macOS';
        elseif (preg_match('/linux/i', $user_agent))          $os = 'Linux';
        elseif (preg_match('/android/i', $user_agent))        $os = 'Android';
        elseif (preg_match('/iphone|ipad/i', $user_agent))    $os = 'iOS';
        return compact('browser', 'device', 'os');
    }

    // =========================================================
    // BOT ANALYSIS — NEW IN 3.2.0
    // =========================================================

    public function ajax_get_bot_analysis() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $days  = isset($_POST['days']) ? intval($_POST['days']) : 7;
        $since = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - ($days * 86400));

        $total        = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time >= %s", $since));
        $no_load_time = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time >= %s AND (load_time IS NULL OR load_time = 0)", $since));
        $has_load_time = $total - $no_load_time;

        // Countries sorted by bot signal (no load time + no referrer)
        $suspect_countries = $wpdb->get_results($wpdb->prepare(
            "SELECT country, country_code,
                    COUNT(*) as sessions,
                    SUM(CASE WHEN (load_time IS NULL OR load_time = 0) AND referrer = '' THEN 1 ELSE 0 END) as bot_signals
             FROM {$this->table_name}
             WHERE visit_time >= %s AND country != '' AND country != 'Unknown' AND country != 'Local'
             GROUP BY country
             ORDER BY bot_signals DESC, sessions DESC
             LIMIT 15", $since
        ), ARRAY_A);

        wp_send_json_success(array(
            'total'             => $total,
            'no_load_time'      => $no_load_time,
            'has_load_time'     => $has_load_time,
            'suspect_countries' => $suspect_countries,
        ));
    }

    // =========================================================
    // AJAX: Overview Stats (unchanged from 3.1.3)
    // =========================================================

    public function ajax_get_stats() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;

        $days      = isset($_POST['days']) ? intval($_POST['days']) : 7;
        $now       = current_time('mysql');
        $date_from = date('Y-m-d H:i:s', strtotime($now) - ($days * 86400));

        $total_views     = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time >= %s", $date_from));
        $unique_visitors = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s", $date_from));
        $daily_stats     = $wpdb->get_results($wpdb->prepare("SELECT DATE(visit_time) as date, COUNT(*) as views FROM {$this->table_name} WHERE visit_time >= %s GROUP BY DATE(visit_time) ORDER BY date ASC", $date_from), ARRAY_A);
        $top_pages       = $wpdb->get_results($wpdb->prepare("SELECT page_title, page_url, COUNT(*) as views, COUNT(DISTINCT ip_address) as unique_views FROM {$this->table_name} WHERE visit_time >= %s GROUP BY page_url ORDER BY views DESC LIMIT 10", $date_from), ARRAY_A);
        $top_countries   = $wpdb->get_results($wpdb->prepare("SELECT country, country_code, COUNT(DISTINCT ip_address) as views FROM {$this->table_name} WHERE visit_time >= %s AND country != '' GROUP BY country ORDER BY views DESC LIMIT 10", $date_from), ARRAY_A);
        $top_cities      = $wpdb->get_results($wpdb->prepare("SELECT city, region, country, COUNT(DISTINCT ip_address) as views FROM {$this->table_name} WHERE visit_time >= %s AND city != '' GROUP BY city, region, country ORDER BY views DESC LIMIT 10", $date_from), ARRAY_A);

        $site_host     = parse_url(home_url(), PHP_URL_HOST);
        $top_referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer,'https://',''),'http://',''),'/',1),'?',1) as referrer_domain, COUNT(*) as views, COUNT(DISTINCT ip_address) as unique_views FROM {$this->table_name} WHERE visit_time >= %s AND referrer != '' AND referrer NOT LIKE %s GROUP BY referrer_domain ORDER BY views DESC LIMIT 15",
            $date_from, '%' . $wpdb->esc_like($site_host) . '%'
        ), ARRAY_A);

        $devices     = $wpdb->get_results($wpdb->prepare("SELECT device, COUNT(DISTINCT ip_address) as views FROM {$this->table_name} WHERE visit_time >= %s GROUP BY device ORDER BY views DESC", $date_from), ARRAY_A);
        $browsers    = $wpdb->get_results($wpdb->prepare("SELECT browser, COUNT(DISTINCT ip_address) as views FROM {$this->table_name} WHERE visit_time >= %s GROUP BY browser ORDER BY views DESC LIMIT 10", $date_from), ARRAY_A);
        $avg_load    = $wpdb->get_var($wpdb->prepare("SELECT ROUND(AVG(load_time)) FROM {$this->table_name} WHERE visit_time >= %s AND load_time IS NOT NULL AND load_time > 0", $date_from));
        $page_loads  = $wpdb->get_results($wpdb->prepare("SELECT page_title, page_url, ROUND(AVG(load_time)) as avg_load_time, ROUND(MIN(load_time)) as min_load_time, ROUND(MAX(load_time)) as max_load_time, COUNT(*) as sample_count FROM {$this->table_name} WHERE visit_time >= %s AND load_time IS NOT NULL AND load_time > 0 GROUP BY page_url ORDER BY avg_load_time DESC LIMIT 15", $date_from), ARRAY_A);

        $returning = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT ip_address FROM {$this->table_name} WHERE ip_address IN (SELECT DISTINCT ip_address FROM {$this->table_name} WHERE visit_time >= %s) GROUP BY ip_address HAVING COUNT(DISTINCT DATE(visit_time)) > 1) as r", $date_from)));
        $new_visitors = intval($unique_visitors) - $returning;

        $exit_pages  = $wpdb->get_results($wpdb->prepare("SELECT t1.page_title, t1.page_url, COUNT(*) as exits FROM {$this->table_name} t1 INNER JOIN (SELECT ip_address, DATE(visit_time) as visit_date, MAX(visit_time) as last_time FROM {$this->table_name} WHERE visit_time >= %s GROUP BY ip_address, DATE(visit_time)) t2 ON t1.ip_address = t2.ip_address AND t1.visit_time = t2.last_time WHERE t1.visit_time >= %s GROUP BY t1.page_url ORDER BY exits DESC LIMIT 10", $date_from, $date_from), ARRAY_A);
        $entry_pages = $wpdb->get_results($wpdb->prepare("SELECT t1.page_title, t1.page_url, COUNT(*) as entries FROM {$this->table_name} t1 INNER JOIN (SELECT ip_address, DATE(visit_time) as visit_date, MIN(visit_time) as first_time FROM {$this->table_name} WHERE visit_time >= %s GROUP BY ip_address, DATE(visit_time)) t2 ON t1.ip_address = t2.ip_address AND t1.visit_time = t2.first_time WHERE t1.visit_time >= %s GROUP BY t1.page_url ORDER BY entries DESC LIMIT 10", $date_from, $date_from), ARRAY_A);
        $peak_hours  = $wpdb->get_results($wpdb->prepare("SELECT DAYOFWEEK(visit_time) as dow, HOUR(visit_time) as hour, COUNT(*) as visits FROM {$this->table_name} WHERE visit_time >= %s GROUP BY DAYOFWEEK(visit_time), HOUR(visit_time) ORDER BY dow, hour", $date_from), ARRAY_A);
        $searches    = $wpdb->get_results($wpdb->prepare("SELECT search_query, COUNT(*) as searches, COUNT(DISTINCT ip_address) as unique_searchers FROM {$this->table_name} WHERE visit_time >= %s AND search_query != '' GROUP BY search_query ORDER BY searches DESC LIMIT 20", $date_from), ARRAY_A);
        $utm         = $wpdb->get_results($wpdb->prepare("SELECT utm_source, utm_medium, utm_campaign, COUNT(*) as visits, COUNT(DISTINCT ip_address) as unique_visitors FROM {$this->table_name} WHERE visit_time >= %s AND utm_source != '' GROUP BY utm_source, utm_medium, utm_campaign ORDER BY visits DESC LIMIT 20", $date_from), ARRAY_A);

        $funnel = array();
        $funnel['shop']      = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s AND (page_url LIKE %s OR page_url LIKE %s)", $date_from, '%/shop%', '%/store%')));
        $funnel['product']   = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s AND page_url LIKE %s", $date_from, '%/product/%')));
        $funnel['cart']      = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s AND page_url LIKE %s", $date_from, '%/cart%')));
        $funnel['checkout']  = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s AND page_url LIKE %s AND page_url NOT LIKE %s", $date_from, '%/checkout%', '%/order-received%')));
        $funnel['completed'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time >= %s AND page_url LIKE %s", $date_from, '%/order-received%')));

        wp_send_json_success(array(
            'total_views'        => $total_views,
            'unique_visitors'    => $unique_visitors,
            'new_visitors'       => $new_visitors,
            'returning_visitors' => $returning,
            'daily_stats'        => $daily_stats,
            'top_pages'          => $top_pages,
            'top_countries'      => $top_countries,
            'top_cities'         => $top_cities,
            'top_referrers'      => $top_referrers,
            'devices'            => $devices,
            'browsers'           => $browsers,
            'avg_load_time'      => $avg_load ? intval($avg_load) : null,
            'page_load_times'    => $page_loads,
            'exit_pages'         => $exit_pages,
            'entry_pages'        => $entry_pages,
            'peak_hours'         => $peak_hours,
            'search_queries'     => $searches,
            'utm_campaigns'      => $utm,
            'funnel'             => $funnel,
        ));
    }

    public function ajax_get_insights() { $this->ajax_get_stats(); }

    // =========================================================
    // AJAX: Real-Time, Profile, Map, Flow (unchanged from 3.1.3)
    // =========================================================

    public function ajax_get_realtime() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $now = current_time('mysql');
        $time_ago = date('Y-m-d H:i:s', strtotime($now) - 7200);
        $recent_ips = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT ip_address FROM {$this->table_name} WHERE visit_time >= %s ORDER BY ip_address", $time_ago));
        if (empty($recent_ips)) { wp_send_json_success(array('visitors'=>array(),'count'=>0)); return; }
        $visitors = array();
        foreach ($recent_ips as $ip) {
            $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE ip_address = %s ORDER BY visit_time DESC LIMIT 1", $ip), ARRAY_A);
            if (!$latest) continue;
            $pages         = $wpdb->get_results($wpdb->prepare("SELECT page_title, page_url, visit_time, referrer, load_time FROM {$this->table_name} WHERE ip_address = %s AND visit_time >= %s ORDER BY visit_time DESC", $ip, $time_ago), ARRAY_A);
            $older         = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE ip_address = %s AND visit_time < %s", $ip, $time_ago));
            $first_visit   = $wpdb->get_var($wpdb->prepare("SELECT MIN(visit_time) FROM {$this->table_name} WHERE ip_address = %s", $ip));
            $total_views   = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE ip_address = %s", $ip));
            $total_visits  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT DATE(visit_time)) FROM {$this->table_name} WHERE ip_address = %s", $ip));
            $session_ref   = '';
            if (!empty($pages)) { $oldest = end($pages); $session_ref = $oldest['referrer']; }
            $visitors[] = array(
                'ip_hash'         => substr(md5($ip), 0, 10),
                'latest_visit'    => $latest['visit_time'],
                'country'         => $latest['country'],
                'country_code'    => $latest['country_code'],
                'city'            => $latest['city'],
                'region'          => $latest['region'],
                'browser'         => $latest['browser'],
                'device'          => $latest['device'],
                'os'              => $latest['os'],
                'is_returning'    => intval($older) > 0,
                'first_visit'     => $first_visit,
                'total_views'     => intval($total_views),
                'total_visits'    => intval($total_visits),
                'session_referrer'=> $session_ref,
                'pages'           => $pages,
                'page_count'      => count($pages),
            );
        }
        usort($visitors, function($a, $b) { return strtotime($b['latest_visit']) - strtotime($a['latest_visit']); });
        wp_send_json_success(array('visitors'=>$visitors,'count'=>count($visitors)));
    }

    public function ajax_get_visitor_profile() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $ip_hash = isset($_POST['ip_hash']) ? sanitize_text_field($_POST['ip_hash']) : '';
        if (empty($ip_hash)) { wp_send_json_error('No visitor specified'); return; }
        $all_ips   = $wpdb->get_col("SELECT DISTINCT ip_address FROM {$this->table_name}");
        $target_ip = null;
        foreach ($all_ips as $ip) { if (substr(md5($ip),0,10)===$ip_hash) { $target_ip=$ip; break; } }
        if (!$target_ip) { wp_send_json_error('Visitor not found'); return; }
        $all_visits = $wpdb->get_results($wpdb->prepare("SELECT page_title,page_url,visit_time,referrer,browser,device,os,country,country_code,city,region FROM {$this->table_name} WHERE ip_address=%s ORDER BY visit_time DESC LIMIT 500", $target_ip), ARRAY_A);
        if (empty($all_visits)) { wp_send_json_error('No visits found'); return; }
        $sessions=[]; $current_session=[]; $last_time=null;
        foreach ($all_visits as $visit) {
            $vt = strtotime($visit['visit_time']);
            if ($last_time!==null && ($last_time-$vt)>1800) { if(!empty($current_session)) $sessions[]=$current_session; $current_session=[]; }
            $current_session[]=$visit; $last_time=$vt;
        }
        if (!empty($current_session)) $sessions[]=$current_session;
        $latest=$all_visits[0]; $first=end($all_visits);
        wp_send_json_success(array(
            'ip_hash'=>$ip_hash,'country'=>$latest['country'],'country_code'=>$latest['country_code'],
            'city'=>$latest['city'],'region'=>$latest['region'],'browser'=>$latest['browser'],
            'device'=>$latest['device'],'os'=>$latest['os'],'first_visit'=>$first['visit_time'],
            'last_visit'=>$latest['visit_time'],'total_pages'=>count($all_visits),
            'total_sessions'=>count($sessions),'sessions'=>$sessions,
        ));
    }

    public function ajax_get_map_data() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $days = isset($_POST['days']) ? intval($_POST['days']) : 7;
        $now  = current_time('mysql');
        $date_from     = date('Y-m-d H:i:s', strtotime($now) - ($days * 86400));
        $two_hours_ago = date('Y-m-d H:i:s', strtotime($now) - 7200);
        $map_clusters  = $wpdb->get_results($wpdb->prepare(
            "SELECT city,region,country,country_code,ROUND(AVG(CASE WHEN latitude!=0 THEN latitude END),4) as latitude,ROUND(AVG(CASE WHEN longitude!=0 THEN longitude END),4) as longitude,COUNT(*) as visits,COUNT(DISTINCT ip_address) as unique_visitors,MAX(visit_time) as last_visit,SUM(CASE WHEN visit_time>=%s THEN 1 ELSE 0 END) as recent_visits,SUBSTRING(MD5(SUBSTRING_INDEX(GROUP_CONCAT(ip_address ORDER BY visit_time DESC),',',1)),1,10) as ip_hash FROM {$this->table_name} WHERE visit_time>=%s AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude!=0 AND longitude!=0 GROUP BY city,region,country ORDER BY visits DESC LIMIT 200",
            $two_hours_ago, $date_from
        ), ARRAY_A);
        foreach ($map_clusters as &$c) $c['is_recent'] = intval($c['recent_visits'])>0 ? 1 : 0;
        wp_send_json_success(array('clusters'=>$map_clusters));
    }

    // =========================================================
    // COUNTRY FILTER
    // =========================================================

    private function is_country_excluded($country_code) {
        if (empty($country_code)) return false;
        $mode      = get_option('rt_traffic_country_mode', 'exclude');
        $countries = get_option('rt_traffic_country_list', '');
        if (empty($countries)) return false;
        $list = array_map('trim', array_map('strtoupper', explode(',', $countries)));
        $code = strtoupper($country_code);
        return $mode === 'only' ? !in_array($code, $list) : in_array($code, $list);
    }

    public function ajax_save_country_filter() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $mode      = isset($_POST['mode'])      ? sanitize_text_field($_POST['mode'])      : 'exclude';
        $countries = isset($_POST['countries']) ? sanitize_text_field($_POST['countries']) : '';
        $list      = array_filter(array_map('trim', array_map('strtoupper', explode(',', $countries))));
        $clean     = implode(', ', $list);
        update_option('rt_traffic_country_mode', $mode);
        update_option('rt_traffic_country_list', $clean);
        wp_send_json_success(array('message'=>'Country filter saved.','countries'=>$clean,'mode'=>$mode));
    }

    public function ajax_purge_countries() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $countries = isset($_POST['countries']) ? sanitize_text_field($_POST['countries']) : '';
        $mode      = isset($_POST['mode'])      ? sanitize_text_field($_POST['mode'])      : 'exclude';
        if (empty($countries)) wp_send_json_error('No countries specified');
        global $wpdb;
        $list = array_filter(array_map('trim', array_map('strtoupper', explode(',', $countries))));
        $ph   = implode(',', array_fill(0, count($list), '%s'));
        $deleted = $mode === 'keep'
            ? $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE UPPER(country_code) NOT IN ($ph)", ...$list))
            : $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE UPPER(country_code) IN ($ph)", ...$list));
        wp_send_json_success(array('message'=>"$deleted records purged.",'deleted'=>$deleted));
    }

    public function ajax_purge_data() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        wp_send_json_success(array('message'=>'All traffic data has been purged.'));
    }

    public function ajax_export_csv() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb;
        $days      = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $now       = current_time('mysql');
        $date_from = date('Y-m-d H:i:s', strtotime($now) - ($days * 86400));
        $results   = $wpdb->get_results($wpdb->prepare("SELECT visit_time,page_title,page_url,referrer,country,city,region,browser,device,os,load_time,utm_source,utm_medium,utm_campaign,search_query FROM {$this->table_name} WHERE visit_time >= %s ORDER BY visit_time DESC", $date_from), ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=traffic-report-'.date('Y-m-d').'.csv');
        header('Pragma: no-cache'); header('Expires: 0');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Date/Time','Page Title','Page URL','Referrer','Country','City','Region','Browser','Device','OS','Load Time (ms)','UTM Source','UTM Medium','UTM Campaign','Search Query'));
        foreach ($results as $row) fputcsv($output, $row);
        fclose($output); exit;
    }

    // =========================================================
    // EMAIL DIGEST
    // =========================================================

    public function ajax_save_digest_settings() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $enabled = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : '0';
        $email   = isset($_POST['email'])   ? sanitize_email($_POST['email'])        : get_option('admin_email');
        update_option('rt_digest_enabled', $enabled);
        update_option('rt_digest_email', $email);
        if ($enabled === '1') { if (!wp_next_scheduled('rt_weekly_email_digest')) wp_schedule_event(strtotime('next monday 9:00am'),'weekly','rt_weekly_email_digest'); }
        else wp_clear_scheduled_hook('rt_weekly_email_digest');
        wp_send_json_success(array('message'=>'Digest settings saved.'));
    }

    public function ajax_send_test_digest() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $result = $this->send_weekly_digest(true);
        $result ? wp_send_json_success(array('message'=>'Test digest sent!')) : wp_send_json_error('Failed to send email.');
    }

    public function send_weekly_digest($force = false) {
        if (!$force && get_option('rt_digest_enabled','0') !== '1') return false;
        global $wpdb;
        $email = get_option('rt_digest_email', get_option('admin_email'));
        $now   = current_time('mysql');
        $week_ago      = date('Y-m-d H:i:s', strtotime($now) - (7*86400));
        $two_weeks_ago = date('Y-m-d H:i:s', strtotime($now) - (14*86400));
        $views      = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time>=%s",$week_ago)));
        $visitors   = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time>=%s",$week_ago)));
        $prev_views = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time>=%s AND visit_time<%s",$two_weeks_ago,$week_ago)));
        $prev_vis   = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time>=%s AND visit_time<%s",$two_weeks_ago,$week_ago)));
        $vc = $prev_views>0 ? round((($views-$prev_views)/$prev_views)*100) : 0;
        $uc = $prev_vis>0   ? round((($visitors-$prev_vis)/$prev_vis)*100)  : 0;
        $top_pages = $wpdb->get_results($wpdb->prepare("SELECT page_title,COUNT(*) as views FROM {$this->table_name} WHERE visit_time>=%s GROUP BY page_url ORDER BY views DESC LIMIT 5",$week_ago),ARRAY_A);
        $site_host = parse_url(home_url(),PHP_URL_HOST);
        $top_refs  = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer,'https://',''),'http://',''),'/',1),'?',1) as domain,COUNT(*) as visits FROM {$this->table_name} WHERE visit_time>=%s AND referrer!='' AND referrer NOT LIKE %s GROUP BY domain ORDER BY visits DESC LIMIT 5",$week_ago,'%'.$wpdb->esc_like($site_host).'%'),ARRAY_A);
        $site_name = get_bloginfo('name');
        $va = $vc>=0?'↑':'↓'; $vc_color = $vc>=0?'#0d7a3f':'#d63638';
        $ua = $uc>=0?'↑':'↓'; $uc_color = $uc>=0?'#0d7a3f':'#d63638';
        $subject = "📊 Weekly Traffic Digest — {$site_name}";
        $body = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'><h1 style='color:#333;border-bottom:2px solid #A2755A;padding-bottom:10px;'>📊 Weekly Traffic Report</h1><p style='color:#666;'>Here's your weekly summary for <strong>{$site_name}</strong></p><table style='width:100%;border-collapse:collapse;margin:20px 0;'><tr><td style='padding:15px;background:#f8f9fa;border-radius:8px;text-align:center;width:50%;'><div style='font-size:32px;font-weight:700;color:#333;'>".number_format($views)."</div><div style='color:#666;'>Page Views</div><div style='color:{$vc_color};font-size:13px;'>{$va} {$vc}% vs last week</div></td><td style='width:10px;'></td><td style='padding:15px;background:#f8f9fa;border-radius:8px;text-align:center;width:50%;'><div style='font-size:32px;font-weight:700;color:#333;'>".number_format($visitors)."</div><div style='color:#666;'>Unique Visitors</div><div style='color:{$uc_color};font-size:13px;'>{$ua} {$uc}% vs last week</div></td></tr></table>";
        if (!empty($top_pages)) { $body.="<h3>🔝 Top Pages</h3><table style='width:100%;border-collapse:collapse;'>"; foreach($top_pages as $p) $body.="<tr><td style='padding:8px;border-bottom:1px solid #eee;'>{$p['page_title']}</td><td style='padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:600;'>{$p['views']}</td></tr>"; $body.="</table>"; }
        if (!empty($top_refs)) { $body.="<h3>🔗 Top Referrers</h3><table style='width:100%;border-collapse:collapse;'>"; foreach($top_refs as $r) $body.="<tr><td style='padding:8px;border-bottom:1px solid #eee;'>{$r['domain']}</td><td style='padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:600;'>{$r['visits']}</td></tr>"; $body.="</table>"; }
        $body .= "<p style='margin-top:30px;text-align:center;'><a href='".admin_url('admin.php?page=rt-traffic-stats')."' style='background:#A2755A;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:600;'>View Full Dashboard</a></p><p style='color:#999;font-size:12px;text-align:center;margin-top:30px;'>Sent by Real-Time Traffic Tracker</p></body></html>";
        return wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    // =========================================================
    // VISITOR FLOW
    // =========================================================

    public function ajax_get_visitor_flow() {
        check_ajax_referer('rt_traffic_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $days      = isset($_POST['days']) ? intval($_POST['days']) : 7;
        $date_from = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - ($days * 86400));
        $visits    = $wpdb->get_results($wpdb->prepare("SELECT ip_address,page_url,page_title,visit_time FROM {$this->table_name} WHERE visit_time>=%s ORDER BY ip_address,visit_time ASC",$date_from),ARRAY_A);
        $transitions=[]; $last_ip=$last_page=$last_time=null; $site_url=home_url();
        foreach ($visits as $v) {
            $path=str_replace($site_url,'',$v['page_url']); $path=preg_replace('/\?.*$/','',$path); if(empty($path))$path='/';
            if(preg_match('#^/product/([^/]+)#',$path,$m)) $path='/product/'.$m[1];
            if(preg_match('#^/product-category/([^/]+)#',$path,$m)) $path='/category/'.$m[1];
            $path=rtrim($path,'/'); if(empty($path))$path='/';
            if($v['ip_address']===$last_ip && $last_page!==null) {
                $gap=strtotime($v['visit_time'])-strtotime($last_time);
                if($gap<=1800 && $last_page!==$path) { $key=$last_page.' → '.$path; if(!isset($transitions[$key])) $transitions[$key]=array('from'=>$last_page,'to'=>$path,'count'=>0); $transitions[$key]['count']++; }
            }
            $last_ip=$v['ip_address']; $last_page=$path; $last_time=$v['visit_time'];
        }
        usort($transitions,function($a,$b){return $b['count']-$a['count'];});
        wp_send_json_success(array('transitions'=>array_slice(array_values($transitions),0,20)));
    }

    // =========================================================
    // DASHBOARD WIDGET
    // =========================================================

    public function add_dashboard_widget() {
        wp_add_dashboard_widget('rt_traffic_dashboard_widget','📊 Traffic Overview — Last 7 Days',array($this,'render_dashboard_widget'));
    }

    public function render_dashboard_widget() {
        global $wpdb;
        $now = current_time('mysql');
        $week_ago      = date('Y-m-d H:i:s', strtotime($now) - (7*86400));
        $two_weeks_ago = date('Y-m-d H:i:s', strtotime($now) - (14*86400));
        $two_hours_ago = date('Y-m-d H:i:s', strtotime($now) - 7200);
        $views      = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time>=%s",$week_ago)));
        $visitors   = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time>=%s",$week_ago)));
        $prev_views = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE visit_time>=%s AND visit_time<%s",$two_weeks_ago,$week_ago)));
        $prev_vis   = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time>=%s AND visit_time<%s",$two_weeks_ago,$week_ago)));
        $vc = $prev_views>0?round((($views-$prev_views)/$prev_views)*100):0;
        $uc = $prev_vis>0?round((($visitors-$prev_vis)/$prev_vis)*100):0;
        $top_pages  = $wpdb->get_results($wpdb->prepare("SELECT page_title,COUNT(*) as views FROM {$this->table_name} WHERE visit_time>=%s GROUP BY page_url ORDER BY views DESC LIMIT 5",$week_ago),ARRAY_A);
        $site_host  = parse_url(home_url(),PHP_URL_HOST);
        $top_refs   = $wpdb->get_results($wpdb->prepare("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer,'https://',''),'http://',''),'/',1),'?',1) as domain,COUNT(*) as visits FROM {$this->table_name} WHERE visit_time>=%s AND referrer!='' AND referrer NOT LIKE %s GROUP BY domain ORDER BY visits DESC LIMIT 5",$week_ago,'%'.$wpdb->esc_like($site_host).'%'),ARRAY_A);
        $active_now = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} WHERE visit_time>=%s",$two_hours_ago)));
        $dashboard_url = admin_url('admin.php?page=rt-traffic-stats');
        $va=$vc>=0?'↑':'↓'; $vc_col=$vc>=0?'#0d7a3f':'#d63638';
        $ua=$uc>=0?'↑':'↓'; $uc_col=$uc>=0?'#0d7a3f':'#d63638';
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
            <div style="text-align:center;padding:14px;background:linear-gradient(135deg,#A2755A,#8B5E3C);border-radius:8px;color:#fff;">
                <div style="font-size:24px;font-weight:700;"><?php echo number_format($views); ?></div>
                <div style="font-size:12px;opacity:0.85;">Page Views</div>
                <div style="font-size:11px;color:<?php echo $vc_col;?>;background:rgba(255,255,255,0.9);border-radius:4px;padding:2px 6px;margin-top:4px;display:inline-block;"><?php echo $va.' '.abs($vc).'%'; ?></div>
            </div>
            <div style="text-align:center;padding:14px;background:linear-gradient(135deg,#C49A7E,#A2755A);border-radius:8px;color:#fff;">
                <div style="font-size:24px;font-weight:700;"><?php echo number_format($visitors); ?></div>
                <div style="font-size:12px;opacity:0.85;">Visitors</div>
                <div style="font-size:11px;color:<?php echo $uc_col;?>;background:rgba(255,255,255,0.9);border-radius:4px;padding:2px 6px;margin-top:4px;display:inline-block;"><?php echo $ua.' '.abs($uc).'%'; ?></div>
            </div>
            <div style="text-align:center;padding:14px;background:linear-gradient(135deg,#B8896E,#8B5E3C);border-radius:8px;color:#fff;">
                <div style="font-size:24px;font-weight:700;"><?php echo $active_now; ?></div>
                <div style="font-size:12px;opacity:0.85;">Active Now</div>
                <div style="font-size:11px;background:rgba(255,255,255,0.9);border-radius:4px;padding:2px 6px;margin-top:4px;display:inline-block;color:#333;">🟢 live</div>
            </div>
        </div>
        <?php if (!empty($top_pages)): ?>
        <div style="margin-bottom:12px;">
            <strong style="font-size:13px;">🔝 Top Pages</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:13px;">
                <?php foreach($top_pages as $p): ?>
                <tr><td style="padding:4px 0;border-bottom:1px solid #f0f0f0;"><?php echo esc_html($p['page_title']?:'Untitled'); ?></td><td style="padding:4px 0;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600;color:#A2755A;"><?php echo $p['views']; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        <?php if (!empty($top_refs)): ?>
        <div style="margin-bottom:12px;">
            <strong style="font-size:13px;">🔗 Top Referrers</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:13px;">
                <?php foreach($top_refs as $r): ?>
                <tr><td style="padding:4px 0;border-bottom:1px solid #f0f0f0;"><?php echo esc_html($r['domain']); ?></td><td style="padding:4px 0;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600;color:#A2755A;"><?php echo $r['visits']; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        <div style="text-align:center;margin-top:14px;">
            <a href="<?php echo esc_url($dashboard_url); ?>" style="display:inline-block;padding:8px 20px;background:#A2755A;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600;">View Full Dashboard →</a>
        </div>
        <?php
    }

    // =========================================================
    // ADMIN PAGE
    // =========================================================

    public function add_admin_menu() {
        add_menu_page('Traffic Stats','Traffic Stats','manage_options','rt-traffic-stats',array($this,'render_admin_page'),'dashicons-chart-area',20);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-traffic-stats') return;
        wp_enqueue_script('chartjs','https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',array(),'4.4.0',true);
        wp_enqueue_style('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',array(),'1.9.4');
        wp_enqueue_script('leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',array(),'1.9.4',true);
        wp_enqueue_style('rt-traffic-admin',plugins_url('assets/admin.css',__FILE__),array('leaflet'),$this->version);
        wp_enqueue_script('rt-traffic-admin',plugins_url('assets/admin.js',__FILE__),array('jquery','leaflet'),$this->version,true);
        wp_localize_script('rt-traffic-admin','rtTrafficAjax',array('ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('rt_traffic_nonce')));
    }

    public function render_admin_page() {
        ?>
        <div class="wrap rt-traffic-wrap" style="background:#e9e9e9;margin-left:-20px;padding:20px 30px;min-height:100vh;">
            <h1><span class="rt-icon">📊</span> Traffic Statistics</h1>

            <div class="rt-header">
                <div class="rt-date-selector">
                    <button class="rt-date-btn active" data-days="7">Last 7 Days</button>
                    <button class="rt-date-btn" data-days="30">Last 30 Days</button>
                    <button class="rt-date-btn" data-days="90">Last 90 Days</button>
                </div>
                <div class="rt-header-right">
                    <div class="rt-view-toggle">
                        <button class="rt-view-btn active" data-view="overview"><span class="dashicons dashicons-chart-line"></span> Overview</button>
                        <button class="rt-view-btn" data-view="insights"><span class="dashicons dashicons-lightbulb"></span> Insights</button>
                        <button class="rt-view-btn" data-view="map"><span class="dashicons dashicons-location-alt"></span> World Map</button>
                        <button class="rt-view-btn" data-view="realtime"><span class="dashicons dashicons-update"></span> Real-Time</button>
                    </div>
                    <button id="rt-purge-btn" class="rt-purge-btn" title="Purge all traffic data"><span class="dashicons dashicons-trash"></span> Purge Data</button>
                    <button id="rt-export-btn" class="rt-purge-btn" title="Export CSV" style="background:#A2755A;color:#fff;border-color:#A2755A;"><span class="dashicons dashicons-download"></span> Export CSV</button>
                    <button id="rt-settings-btn" class="rt-purge-btn" title="Country filter settings" style="background:#A2755A;color:#fff;border-color:#A2755A;"><span class="dashicons dashicons-admin-settings"></span> Settings</button>
                </div>
            </div>

            <div id="rt-loading" class="rt-loading"><div class="rt-spinner"></div>Loading statistics...</div>

            <!-- OVERVIEW -->
            <div id="rt-overview-view" class="rt-view active">
                <div class="rt-stats-grid">
                    <div class="rt-stat-card"><div class="rt-stat-icon">👁️</div><div class="rt-stat-content"><div class="rt-stat-label">Total Views</div><div class="rt-stat-value" id="rt-total-views">0</div></div></div>
                    <div class="rt-stat-card"><div class="rt-stat-icon">👥</div><div class="rt-stat-content"><div class="rt-stat-label">Unique Visitors</div><div class="rt-stat-value" id="rt-unique-visitors">0</div></div></div>
                    <div class="rt-stat-card"><div class="rt-stat-icon">🆕</div><div class="rt-stat-content"><div class="rt-stat-label">New Visitors</div><div class="rt-stat-value" id="rt-new-visitors">0</div></div></div>
                    <div class="rt-stat-card"><div class="rt-stat-icon">↩️</div><div class="rt-stat-content"><div class="rt-stat-label">Returning</div><div class="rt-stat-value" id="rt-returning-visitors">0</div></div></div>
                </div>
                <div class="rt-chart-container"><h2>Views Over Time</h2><canvas id="rt-views-chart"></canvas></div>
                <div class="rt-grid-2">
                    <div class="rt-card"><h2>Most Viewed Pages</h2><div id="rt-top-pages" class="rt-list"></div></div>
                    <div class="rt-card"><h2>Top Referrers</h2><div id="rt-top-referrers" class="rt-list"></div></div>
                </div>
                <div class="rt-grid-3">
                    <div class="rt-card"><h2>Top Countries</h2><div id="rt-top-countries" class="rt-list"></div></div>
                    <div class="rt-card"><h2>Top Cities</h2><div id="rt-top-cities" class="rt-list"></div></div>
                    <div class="rt-card"><h2>Devices</h2><div id="rt-devices" class="rt-list"></div></div>
                </div>
                <div class="rt-card"><h2>Browsers</h2><div id="rt-browsers" class="rt-list-horizontal"></div></div>
                <div class="rt-card"><div class="rt-card-header-flex"><h2>⚡ Page Load Times</h2><div id="rt-avg-load-time" class="rt-load-avg"></div></div><div id="rt-page-load-times" class="rt-list"></div></div>
                <div class="rt-grid-2">
                    <div class="rt-card"><h2>🚪 Exit Pages</h2><div id="rt-exit-pages" class="rt-list"></div></div>
                    <div class="rt-card"><h2>🛬 Entry Pages</h2><div id="rt-entry-pages" class="rt-list"></div></div>
                </div>
                <div class="rt-card"><h2>🔎 Site Searches</h2><div id="rt-search-queries" class="rt-list"></div></div>
            </div>

            <!-- INSIGHTS — includes new Bot Analysis section -->
            <div id="rt-insights-view" class="rt-view">

                <!-- NEW 3.2.0: Bot Analysis -->
                <div class="rt-card">
                    <h2>🤖 Bot Traffic Analysis</h2>
                    <p style="color:#888;margin:0 0 15px;font-size:13px;">Sessions classified by bot likelihood. Real browsers always report a page load time — sessions with no load time recorded are almost certainly bots.</p>
                    <div id="rt-bot-analysis"><div class="rt-spinner"></div></div>
                </div>

                <div class="rt-card">
                    <h2>🛒 WooCommerce Conversion Funnel</h2>
                    <p style="color:#888;margin:0 0 15px;">Tracks unique visitors through your purchase flow</p>
                    <div id="rt-funnel" style="display:flex;align-items:flex-end;gap:4px;min-height:250px;padding:20px 0;"></div>
                </div>

                <div class="rt-card">
                    <h2>🔀 Visitor Flow</h2>
                    <p style="color:#888;margin:0 0 15px;">Most common page-to-page paths visitors take through your site</p>
                    <div id="rt-visitor-flow" style="min-height:200px;"></div>
                </div>

                <div class="rt-card">
                    <h2>🏷️ UTM Campaigns</h2>
                    <p style="color:#888;margin:0 0 15px;">Add <code>?utm_source=facebook&amp;utm_campaign=spring_sale</code> to your links to track campaigns</p>
                    <div id="rt-utm-campaigns" class="rt-list"></div>
                </div>

                <div class="rt-card">
                    <h2>🕐 Peak Hours Heatmap</h2>
                    <p style="color:#888;margin:0 0 15px;">When your visitors are most active</p>
                    <div id="rt-peak-hours" style="overflow-x:auto;"></div>
                </div>

                <div class="rt-grid-2">
                    <div class="rt-card"><h2>👤 New vs Returning</h2><canvas id="rt-visitor-pie" width="250" height="250"></canvas></div>
                    <div class="rt-card">
                        <h2>📧 Weekly Email Digest</h2>
                        <div style="padding:10px 0;">
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:15px;cursor:pointer;">
                                <input type="checkbox" id="rt-digest-enabled" <?php echo get_option('rt_digest_enabled','0')==='1'?'checked':''; ?> style="width:18px;height:18px;">
                                <span style="font-weight:600;">Enable weekly email digest</span>
                            </label>
                            <div style="margin-bottom:15px;">
                                <label style="display:block;font-weight:600;margin-bottom:6px;">Send to:</label>
                                <input type="email" id="rt-digest-email" value="<?php echo esc_attr(get_option('rt_digest_email',get_option('admin_email'))); ?>" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;font-size:14px;box-sizing:border-box;">
                            </div>
                            <div style="display:flex;gap:10px;">
                                <button id="rt-save-digest" style="padding:10px 20px;background:#A2755A;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">💾 Save</button>
                                <button id="rt-test-digest" style="padding:10px 20px;background:#A2755A;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">📨 Send Test Email</button>
                            </div>
                            <div id="rt-digest-status" style="margin-top:10px;display:none;padding:8px;border-radius:6px;font-size:13px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MAP -->
            <div id="rt-map-view" class="rt-view">
                <div class="rt-map-card">
                    <div class="rt-map-header">
                        <h2>🌍 Visitor World Map</h2>
                        <div class="rt-map-legend">
                            <span class="rt-legend-item"><span class="rt-legend-dot rt-legend-dot-cluster"></span> Visitor clusters</span>
                            <span class="rt-legend-item"><span class="rt-legend-dot rt-legend-dot-recent"></span> Active (last 2 hrs)</span>
                        </div>
                    </div>
                    <div id="rt-world-map"></div>
                </div>
            </div>

            <!-- REAL-TIME -->
            <div id="rt-realtime-view" class="rt-view">
                <div class="rt-realtime-header">
                    <h2>Live Visitors</h2>
                    <span class="rt-realtime-subtitle">Last 2 hours</span>
                    <div class="rt-pulse"></div>
                    <div class="rt-realtime-count" id="rt-visitor-count">0 visitors</div>
                </div>
                <div id="rt-realtime-list" class="rt-realtime-list"></div>
            </div>

            <!-- VISITOR PROFILE MODAL -->
            <div id="rt-profile-modal" class="rt-modal-overlay" style="display:none;">
                <div class="rt-modal">
                    <div class="rt-modal-header">
                        <h2>👤 Visitor Profile</h2>
                        <button class="rt-modal-close" id="rt-modal-close">&times;</button>
                    </div>
                    <div class="rt-modal-body" id="rt-profile-content"><div class="rt-spinner"></div>Loading profile...</div>
                </div>
            </div>

            <!-- COUNTRY FILTER MODAL -->
            <div id="rt-country-modal" class="rt-modal-overlay" style="display:none;">
                <div class="rt-modal" style="max-width:520px;">
                    <div class="rt-modal-header">
                        <h2>🌍 Country Filter</h2>
                        <button class="rt-modal-close" id="rt-country-modal-close">&times;</button>
                    </div>
                    <div class="rt-modal-body" style="padding:20px;">
                        <div style="margin-bottom:18px;">
                            <label style="display:block;font-weight:600;margin-bottom:6px;">Filter Mode</label>
                            <select id="rt-country-mode" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;font-size:14px;">
                                <option value="exclude">Exclude these countries (block list)</option>
                                <option value="only">Only track these countries (allow list)</option>
                            </select>
                        </div>
                        <div style="margin-bottom:18px;">
                            <label style="display:block;font-weight:600;margin-bottom:6px;">Country Codes <span style="font-weight:400;color:#888;">(comma-separated, e.g. US, CA, GB)</span></label>
                            <input type="text" id="rt-country-list" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;font-size:14px;box-sizing:border-box;" placeholder="US, CA, GB" value="<?php echo esc_attr(get_option('rt_traffic_country_list','')); ?>">
                        </div>
                        <div style="display:flex;gap:10px;margin-bottom:20px;">
                            <button id="rt-save-filter" style="flex:1;padding:10px;background:#A2755A;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;">💾 Save Filter</button>
                        </div>
                        <hr style="border:none;border-top:1px solid #e0e0e0;margin:20px 0;">
                        <h3 style="margin:0 0 12px 0;font-size:15px;">🧹 Purge Existing Data by Country</h3>
                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-weight:600;margin-bottom:6px;">Countries to purge <span style="font-weight:400;color:#888;">(comma-separated codes)</span></label>
                            <input type="text" id="rt-purge-countries" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;font-size:14px;box-sizing:border-box;" placeholder="VN, BR, BD, CN">
                        </div>
                        <div style="display:flex;gap:10px;">
                            <button id="rt-purge-listed" style="flex:1;padding:10px;background:#8B5E3C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">🗑️ Purge These Countries</button>
                            <button id="rt-purge-keep-listed" style="flex:1;padding:10px;background:#8B5E3C;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">🗑️ Purge Everything EXCEPT These</button>
                        </div>
                        <div id="rt-country-status" style="margin-top:12px;padding:10px;border-radius:6px;display:none;font-size:13px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var savedMode = '<?php echo esc_js(get_option("rt_traffic_country_mode","exclude")); ?>';
            $('#rt-country-mode').val(savedMode);

            $('#rt-settings-btn').on('click', function() { $('#rt-country-modal').fadeIn(200); });
            $('#rt-country-modal-close, #rt-country-modal').on('click', function(e) { if(e.target===this) $('#rt-country-modal').fadeOut(200); });

            $('#rt-export-btn').on('click', function() {
                var days = $('.rt-date-btn.active').data('days') || 30;
                window.location.href = ajaxurl + '?action=rt_export_csv&nonce=' + rtTrafficAjax.nonce + '&days=' + days;
            });

            $('#rt-save-digest').on('click', function() {
                var btn=$(this); btn.prop('disabled',true).text('Saving...');
                $.post(ajaxurl,{action:'rt_save_digest_settings',nonce:rtTrafficAjax.nonce,enabled:$('#rt-digest-enabled').is(':checked')?'1':'0',email:$('#rt-digest-email').val()},function(res){
                    btn.prop('disabled',false).html('💾 Save');
                    var st=$('#rt-digest-status');
                    st.html(res.success?'✅ Digest settings saved!':'❌ Error saving.').css({display:'block',background:res.success?'#d4edda':'#f8d7da',color:res.success?'#155724':'#721c24'});
                });
            });

            $('#rt-test-digest').on('click', function() {
                var btn=$(this); btn.prop('disabled',true).text('Sending...');
                $.post(ajaxurl,{action:'rt_send_test_digest',nonce:rtTrafficAjax.nonce},function(res){
                    btn.prop('disabled',false).html('📨 Send Test Email');
                    var st=$('#rt-digest-status');
                    st.html(res.success?'✅ Test email sent!':'❌ '+(res.data||'Failed to send.')).css({display:'block',background:res.success?'#d4edda':'#f8d7da',color:res.success?'#155724':'#721c24'});
                });
            });

            function showStatus(msg, isError) {
                $('#rt-country-status').html(msg).css({display:'block',background:isError?'#f8d7da':'#d4edda',color:isError?'#721c24':'#155724'});
            }

            $('#rt-save-filter').on('click', function() {
                var btn=$(this); btn.prop('disabled',true).text('Saving...');
                $.post(ajaxurl,{action:'rt_save_country_filter',nonce:rtTrafficAjax.nonce,mode:$('#rt-country-mode').val(),countries:$('#rt-country-list').val()},function(res){
                    btn.prop('disabled',false).html('💾 Save Filter');
                    if(res.success) showStatus('✅ Filter saved! Mode: <strong>'+res.data.mode+'</strong> | Countries: <strong>'+(res.data.countries||'none')+'</strong>',false);
                    else showStatus('❌ Error saving filter.',true);
                });
            });

            $('#rt-purge-listed').on('click', function() {
                var c=$('#rt-purge-countries').val();
                if(!c){showStatus('Enter country codes to purge.',true);return;}
                if(!confirm('Delete ALL traffic data from: '+c+'?'))return;
                var btn=$(this); btn.prop('disabled',true).text('Purging...');
                $.post(ajaxurl,{action:'rt_purge_countries',nonce:rtTrafficAjax.nonce,countries:c,mode:'exclude'},function(res){
                    btn.prop('disabled',false).html('🗑️ Purge These Countries');
                    if(res.success) showStatus('✅ '+res.data.message,false);
                    else showStatus('❌ Error purging data.',true);
                });
            });

            $('#rt-purge-keep-listed').on('click', function() {
                var c=$('#rt-purge-countries').val();
                if(!c){showStatus('Enter country codes to keep.',true);return;}
                if(!confirm('Delete ALL traffic data EXCEPT from: '+c+'? This cannot be undone!'))return;
                var btn=$(this); btn.prop('disabled',true).text('Purging...');
                $.post(ajaxurl,{action:'rt_purge_countries',nonce:rtTrafficAjax.nonce,countries:c,mode:'keep'},function(res){
                    btn.prop('disabled',false).html('🗑️ Purge Everything EXCEPT These');
                    if(res.success) showStatus('✅ '+res.data.message,false);
                    else showStatus('❌ Error purging data.',true);
                });
            });
        });
        </script>
        <?php
    }
}

new RT_Traffic_Tracker();
