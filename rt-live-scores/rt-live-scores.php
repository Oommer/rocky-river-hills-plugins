<?php
/**
 * Plugin Name: RT Live Scores Ticker
 * Description: Live sports scores ticker powered by ESPN data. Matches ESPN.com scoreboard design. Server-side rendered with auto-refresh. Shortcode: [rrh_live_scores]
 * Version: 2.0.0
 * Author: Rocky River Hills
 * Text Domain: rt-live-scores
 */

if (!defined('ABSPATH')) exit;

define('RTLS_VERSION', '2.0.4');
define('RTLS_PATH', plugin_dir_path(__FILE__));
define('RTLS_URL', plugin_dir_url(__FILE__));

class RT_Live_Scores {

    private static $instance = null;

    private $espn_endpoints = [
        'nfl'   => 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard',
        'nba'   => 'https://site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard',
        'mlb'   => 'https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard',
        'nhl'   => 'https://site.api.espn.com/apis/site/v2/sports/hockey/nhl/scoreboard',
        'ncaaf' => 'https://site.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard',
        'ncaab' => 'https://site.api.espn.com/apis/site/v2/sports/basketball/mens-college-basketball/scoreboard',
        'wnba'  => 'https://site.api.espn.com/apis/site/v2/sports/basketball/wnba/scoreboard',
        'mls'   => 'https://site.api.espn.com/apis/site/v2/sports/soccer/usa.1/scoreboard',
    ];

    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_shortcode('rrh_live_scores', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('wp_ajax_rtls_refresh', [$this, 'ajax_refresh']);
        add_action('wp_ajax_nopriv_rtls_refresh', [$this, 'ajax_refresh']);
        add_action('wp_ajax_rtls_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtls_auto_scan', [$this, 'ajax_auto_scan']);
    }

    public function activate() {
        if (!get_option('rtls_settings')) {
            update_option('rtls_settings', [
                'enabled' => 1,
                'leagues' => ['nfl', 'nba', 'mlb', 'nhl'],
                'refresh_interval' => 60,
                'show_shop_links' => 1,
            ]);
        }
    }

    /*--------------------------------------------------------------
    # ESPN Data Fetching
    --------------------------------------------------------------*/

    public function fetch_all_games() {
        $settings = get_option('rtls_settings', []);
        $leagues = $settings['leagues'] ?? ['nfl', 'nba', 'mlb', 'nhl'];
        $all = [];

        foreach ($leagues as $league) {
            if (!isset($this->espn_endpoints[$league])) continue;

            $cache_key = 'rtls_' . $league;
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                $all = array_merge($all, $cached);
                continue;
            }

            $response = wp_remote_get($this->espn_endpoints[$league], [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response)) {
                error_log('[RTLS] Error: ' . $league . ' - ' . $response->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log('[RTLS] HTTP ' . $code . ' for ' . $league);
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['events'])) continue;

            $games = [];
            $has_live = false;

            foreach ($body['events'] as $event) {
                $g = $this->parse_event($event, strtoupper($league));
                if ($g) {
                    $games[] = $g;
                    if ($g['status'] === 'live') $has_live = true;
                }
            }

            set_transient($cache_key, $games, $has_live ? 60 : 300);
            $all = array_merge($all, $games);
        }

        usort($all, function($a, $b) {
            $ord = ['live' => 0, 'pre' => 1, 'post' => 2];
            $diff = ($ord[$a['status']] ?? 1) - ($ord[$b['status']] ?? 1);
            return $diff !== 0 ? $diff : strtotime($a['date']) - strtotime($b['date']);
        });

        // Only show today's games (in the site's timezone)
        $today = current_time('Y-m-d');
        $all = array_filter($all, function($g) use ($today) {
            if (empty($g['date'])) return false;
            $game_date = date('Y-m-d', strtotime($g['date']));
            return $game_date === $today;
        });
        $all = array_values($all);

        return $all;
    }

    private function parse_event($event, $league) {
        $comp = $event['competitions'][0] ?? null;
        if (!$comp) return null;

        $st = $event['status']['type'] ?? [];
        $sn = $st['name'] ?? 'STATUS_SCHEDULED';
        $sd = $st['shortDetail'] ?? '';

        $status = 'pre';
        if (in_array($sn, ['STATUS_IN_PROGRESS','STATUS_HALFTIME','STATUS_END_PERIOD'])) $status = 'live';
        elseif (in_array($sn, ['STATUS_FINAL','STATUS_FINAL_OT','STATUS_POSTPONED','STATUS_CANCELED'])) $status = 'post';

        if ($status === 'live') {
            $clock = $sd ?: 'LIVE';
        } elseif ($status === 'post') {
            $clock = $sd ?: 'Final';
        } else {
            $ts = strtotime($event['date'] ?? '');
            $today = current_time('Y-m-d');
            $gd = $ts ? date('Y-m-d', $ts) : '';
            if ($gd === $today) $clock = date('g:i A', $ts) . ' ET';
            elseif ($gd === date('Y-m-d', strtotime('+1 day'))) $clock = 'Tom ' . date('g:i A', $ts);
            else $clock = date('M j g:iA', $ts);
        }

        $broadcast = '';
        if (!empty($comp['broadcasts'])) {
            foreach ($comp['broadcasts'] as $bc) {
                if (!empty($bc['names'])) { $broadcast = implode('/', $bc['names']); break; }
            }
        }

        $teams = [];
        foreach ($comp['competitors'] as $c) {
            $t = $c['team'] ?? [];
            $teams[] = [
                'abbr'   => $t['abbreviation'] ?? '',
                'name'   => $t['shortDisplayName'] ?? $t['displayName'] ?? '',
                'logo'   => $t['logo'] ?? '',
                'color'  => '#' . ltrim($t['color'] ?? '333', '#'),
                'score'  => $c['score'] ?? '0',
                'record' => !empty($c['records'][0]['summary']) ? $c['records'][0]['summary'] : '',
                'winner' => !empty($c['winner']),
                'home'   => ($c['homeAway'] ?? '') === 'home',
            ];
        }

        if (isset($teams[0], $teams[1]) && $teams[0]['home']) $teams = [$teams[1], $teams[0]];

        return [
            'league' => $league, 'status' => $status, 'clock' => $clock,
            'broadcast' => $broadcast, 'date' => $event['date'] ?? '',
            'away' => $teams[0] ?? null, 'home' => $teams[1] ?? null,
        ];
    }

    /*--------------------------------------------------------------
    # Team Detection
    --------------------------------------------------------------*/

    private function get_known_teams() {
        $c = get_transient('rtls_known_teams');
        if ($c !== false) return $c;
        $t = $this->scan_product_teams();
        set_transient('rtls_known_teams', $t, HOUR_IN_SECONDS);
        return $t;
    }

    private function team_has_products($name) {
        $known = $this->get_known_teams();
        $nl = strtolower($name);
        foreach ($known as $t) {
            $tl = strtolower($t);
            if ($tl === $nl || strpos($nl, $tl) !== false || strpos($tl, $nl) !== false) return $t;
        }
        return false;
    }

    private function scan_product_teams() {
        if (!class_exists('WooCommerce')) return [];
        $strip = ['football','baseball','basketball','hockey','soccer','coasters','coaster','wall art','stadium','college','nfl','nba','mlb','nhl','wnba','mls','set','piece','4-piece','handmade','wooden','custom'];
        $nicks = ['Cardinals','Falcons','Ravens','Bills','Panthers','Bears','Bengals','Browns','Cowboys','Broncos','Lions','Packers','Texans','Colts','Jaguars','Chiefs','Raiders','Chargers','Rams','Dolphins','Vikings','Patriots','Saints','Giants','Jets','Eagles','Steelers','Commanders','49ers','Seahawks','Buccaneers','Titans','Diamondbacks','Braves','Orioles','Red Sox','Cubs','White Sox','Reds','Guardians','Rockies','Tigers','Astros','Royals','Angels','Dodgers','Marlins','Brewers','Twins','Mets','Yankees','Athletics','Phillies','Pirates','Padres','Mariners','Rays','Rangers','Blue Jays','Nationals','Hawks','Celtics','Nets','Hornets','Bulls','Cavaliers','Mavericks','Nuggets','Pistons','Warriors','Rockets','Pacers','Clippers','Lakers','Grizzlies','Heat','Bucks','Timberwolves','Pelicans','Knicks','Thunder','Magic','Suns','Trail Blazers','Kings','Spurs','Raptors','Jazz','Wizards','Ducks','Coyotes','Bruins','Sabres','Flames','Hurricanes','Blackhawks','Avalanche','Blue Jackets','Stars','Red Wings','Oilers','Penguins','Sharks','Kraken','Blues','Lightning','Maple Leafs','Canucks','Golden Knights','Capitals','Aces','Dream','Sky','Sun','Wings','Fever','Sparks','Lynx','Liberty','Mercury','Mystics','Storm','Mountaineers','Tar Heels','Wolverines','Buckeyes','Seminoles','Longhorns','Sooners','Bulldogs','Volunteers','Gators','Crimson Tide','Blue Devils','Fighting Irish','Trojans','Huskies','Wildcats','Hokies','Wolfpack','Gamecocks','Razorbacks','Aggies','Cyclones','Jayhawks','Cornhuskers','Badgers','Hawkeyes','Spartans','Boilermakers','Hoosiers','Nittany Lions','Yellow Jackets','Golden Gophers','Demon Deacons'];
        $pids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
        $teams = []; $seen = [];
        foreach ($pids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            $city = strtolower($p->get_name());
            foreach ($strip as $w) $city = str_ireplace($w, '', $city);
            $city = ucwords(trim(preg_replace('/\s+/', ' ', trim($city, ' -–—'))));
            if (strlen($city) < 3) continue;
            if (!isset($seen[strtolower($city)])) { $seen[strtolower($city)] = 1; $teams[] = $city; }
            $desc = strtolower($p->get_description() . ' ' . $p->get_short_description());
            foreach ($nicks as $nick) {
                if (stripos($desc, strtolower($nick)) !== false) {
                    if (!isset($seen[strtolower($nick)])) { $seen[strtolower($nick)] = 1; $teams[] = $nick; }
                    $combo = $city . ' ' . $nick;
                    if (!isset($seen[strtolower($combo)])) { $seen[strtolower($combo)] = 1; $teams[] = $combo; }
                    break;
                }
            }
        }
        return $teams;
    }

    public function ajax_auto_scan() {
        check_ajax_referer('rtls_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        delete_transient('rtls_known_teams');
        $t = $this->scan_product_teams();
        set_transient('rtls_known_teams', $t, HOUR_IN_SECONDS);
        wp_send_json_success(['found' => count($t), 'teams' => $t]);
    }

    /*--------------------------------------------------------------
    # Build Ticker HTML
    --------------------------------------------------------------*/

    public function build_ticker_html() {
        $settings = get_option('rtls_settings', []);
        $games = $this->fetch_all_games();
        if (empty($games)) return '<div class="rtls-nogames">No games scheduled</div>';

        $show_links = !empty($settings['show_shop_links']);
        $html = '';
        $last_lg = '';

        foreach ($games as $g) {
            if (!$g['away'] || !$g['home']) continue;

            if ($g['league'] !== $last_lg) {
                $html .= '<div class="rtls-sep"><span>' . esc_html($g['league']) . '</span></div>';
                $last_lg = $g['league'];
            }

            $lc = $g['status'] === 'live' ? ' rtls-live' : '';

            // Check if either team has products for shop link
            $shop_match = false;
            if ($show_links) {
                $shop_match = $this->team_has_products($g['away']['name']);
                if (!$shop_match) $shop_match = $this->team_has_products($g['away']['abbr']);
                if (!$shop_match) $shop_match = $this->team_has_products($g['home']['name']);
                if (!$shop_match) $shop_match = $this->team_has_products($g['home']['abbr']);
            }
            if ($shop_match) $lc .= ' rtls-has-shop';

            $html .= '<div class="rtls-g' . $lc . '">';

            // Time + broadcast
            $html .= '<div class="rtls-time">';
            $html .= '<span class="rtls-clk">' . esc_html($g['clock']) . '</span>';
            if ($g['broadcast']) $html .= '<span class="rtls-tv">' . esc_html($g['broadcast']) . '</span>';
            $html .= '</div>';

            // Away
            $html .= $this->tm_html($g['away'], $g['status']);
            // Home
            $html .= $this->tm_html($g['home'], $g['status']);

            // Shop link — separate column
            if ($shop_match) {
                $url = esc_url(add_query_arg(['s' => $shop_match, 'post_type' => 'product'], home_url('/')));
                $html .= '<a href="' . $url . '" class="rtls-shop" title="Shop gear">SHOP</a>';
            }

            $html .= '</div>';
        }

        return $html;
    }

    private function tm_html($tm, $st) {
        $cls = $st === 'post' && !$tm['winner'] ? ' rtls-dim' : '';
        $h = '<div class="rtls-tm' . $cls . '">';
        if ($tm['logo']) $h .= '<img class="rtls-logo" src="' . esc_url($tm['logo']) . '" alt="">';
        $h .= '<span class="rtls-ab">' . esc_html($tm['abbr']) . '</span>';
        if ($tm['record']) $h .= '<span class="rtls-rc">' . esc_html($tm['record']) . '</span>';
        if ($st !== 'pre') $h .= '<span class="rtls-sc">' . esc_html($tm['score']) . '</span>';
        $h .= '</div>';
        return $h;
    }

    /*--------------------------------------------------------------
    # Shortcode — SERVER-SIDE RENDERED
    --------------------------------------------------------------*/

    public function render_shortcode($atts) {
        $settings = get_option('rtls_settings', []);
        if (empty($settings['enabled'])) return '';

        $inner = $this->build_ticker_html();
        $refresh = intval($settings['refresh_interval'] ?? 60);

        return '<div id="rtls-ticker" class="rtls-ticker" data-refresh="' . $refresh . '"><div class="rtls-scroll"><div class="rtls-track" id="rtls-track">' . $inner . '</div><div class="rtls-track rtls-clone" aria-hidden="true">' . $inner . '</div></div></div>';
    }

    /*--------------------------------------------------------------
    # AJAX Refresh
    --------------------------------------------------------------*/

    public function ajax_refresh() {
        $settings = get_option('rtls_settings', []);
        if (empty($settings['enabled'])) { wp_send_json_success(['html' => '']); return; }
        wp_send_json_success(['html' => $this->build_ticker_html()]);
    }

    /*--------------------------------------------------------------
    # Frontend Assets
    --------------------------------------------------------------*/

    public function enqueue_frontend() {
        wp_enqueue_style('rtls-front', RTLS_URL . 'front.css', [], RTLS_VERSION);
        wp_enqueue_script('rtls-front', RTLS_URL . 'front.js', [], RTLS_VERSION, true);
        wp_localize_script('rtls-front', 'rtls', ['ajax_url' => admin_url('admin-ajax.php')]);
    }

    /*--------------------------------------------------------------
    # Admin
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page('Live Scores', 'Live Scores', 'manage_options', 'rt-live-scores', [$this, 'admin_page'], 'dashicons-awards', 63);
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-live-scores') return;
        wp_enqueue_style('rtls-admin', RTLS_URL . 'admin.css', [], RTLS_VERSION);
        wp_enqueue_script('rtls-admin', RTLS_URL . 'admin.js', ['jquery'], RTLS_VERSION, true);
        wp_localize_script('rtls-admin', 'rtls_admin', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('rtls_nonce')]);
    }

    public function admin_page() {
        $settings = get_option('rtls_settings', []);
        $leagues = $settings['leagues'] ?? [];
        include RTLS_PATH . 'admin-page.php';
    }

    public function ajax_save_settings() {
        check_ajax_referer('rtls_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $data = [];
        parse_str($_POST['settings'] ?? '', $data);
        $clean = [
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'leagues' => isset($data['leagues']) ? array_map('sanitize_text_field', (array)$data['leagues']) : [],
            'refresh_interval' => max(30, intval($data['refresh_interval'] ?? 60)),
            'show_shop_links' => !empty($data['show_shop_links']) ? 1 : 0,
        ];
        update_option('rtls_settings', $clean);
        foreach (array_keys($this->espn_endpoints) as $lg) delete_transient('rtls_' . $lg);
        wp_send_json_success('Saved');
    }
}

RT_Live_Scores::get_instance();
