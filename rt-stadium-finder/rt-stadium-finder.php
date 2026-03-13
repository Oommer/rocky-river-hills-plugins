<?php
/**
 * Plugin Name: RT Stadium Finder
 * Description: Interactive Stadium Finder — fans pick their sport, league, and team to discover matching coasters and wall art. Use shortcode [rrh_stadium_finder].
 * Version: 1.0.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-stadium-finder
 */

if (!defined('ABSPATH')) exit;

define('RTSF_VERSION', '1.0.0');
define('RTSF_PATH', plugin_dir_path(__FILE__));
define('RTSF_URL', plugin_dir_url(__FILE__));

class RT_Stadium_Finder {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('rrh_stadium_finder', [$this, 'render_shortcode']);
        add_action('wp_ajax_rtsf_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_rtsf_search', [$this, 'ajax_search']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        global $post;
        if ($post && has_shortcode($post->post_content, 'rrh_stadium_finder')) {
            wp_enqueue_style('rtsf-front', RTSF_URL . 'front.css', [], RTSF_VERSION);
            wp_enqueue_script('rtsf-front', RTSF_URL . 'front.js', ['jquery'], RTSF_VERSION, true);
            wp_localize_script('rtsf-front', 'rtsf', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rtsf_nonce'),
                'sports' => $this->get_sport_data(),
            ]);
        }
    }

    /*--------------------------------------------------------------
    # Sport / League / Team Data
    --------------------------------------------------------------*/

    private function get_sport_data() {
        // Map sports to their category structure
        return [
            'football' => [
                'name' => 'Football',
                'icon' => '🏈',
                'leagues' => [
                    'nfl' => ['name' => 'NFL', 'coaster_cat' => 'cnfl', 'wall_cat' => 'nfl'],
                    'college' => ['name' => 'College', 'coaster_cat' => 'ccollegefb', 'wall_cat' => 'collegefb'],
                ],
            ],
            'baseball' => [
                'name' => 'Baseball',
                'icon' => '⚾',
                'leagues' => [
                    'mlb' => ['name' => 'MLB', 'coaster_cat' => 'cmlb', 'wall_cat' => 'mlb'],
                    'college' => ['name' => 'College', 'coaster_cat' => 'ccollegebb', 'wall_cat' => 'collegebb'],
                ],
            ],
            'basketball' => [
                'name' => 'Basketball',
                'icon' => '🏀',
                'leagues' => [
                    'nba' => ['name' => 'NBA', 'coaster_cat' => 'cnba', 'wall_cat' => 'nba'],
                    'wnba' => ['name' => 'WNBA', 'coaster_cat' => 'cwnba', 'wall_cat' => 'wnba'],
                    'college' => ['name' => 'College', 'coaster_cat' => 'ccollegebball', 'wall_cat' => 'collegebball'],
                ],
            ],
            'hockey' => [
                'name' => 'Hockey',
                'icon' => '🏒',
                'leagues' => [
                    'nhl' => ['name' => 'NHL', 'coaster_cat' => 'cnhl', 'wall_cat' => 'nhl'],
                ],
            ],
            'soccer' => [
                'name' => 'Soccer',
                'icon' => '⚽',
                'leagues' => [
                    'all' => ['name' => 'All Soccer', 'coaster_cat' => 'csoccer', 'wall_cat' => 'soccer'],
                ],
            ],
        ];
    }

    /*--------------------------------------------------------------
    # AJAX Product Search
    --------------------------------------------------------------*/

    public function ajax_search() {
        check_ajax_referer('rtsf_nonce', 'nonce');

        $sport = sanitize_text_field($_POST['sport'] ?? '');
        $league = sanitize_text_field($_POST['league'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $sports = $this->get_sport_data();
        if (empty($sports[$sport]['leagues'][$league])) {
            wp_send_json_error('Invalid selection');
            return;
        }

        $league_data = $sports[$sport]['leagues'][$league];
        $cat_slugs = [$league_data['coaster_cat'], $league_data['wall_cat']];

        // Get category IDs from slugs
        $cat_ids = [];
        foreach ($cat_slugs as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term) $cat_ids[] = $term->term_id;
        }

        if (empty($cat_ids)) {
            wp_send_json_success(['products' => [], 'message' => 'No products found for this selection.']);
            return;
        }

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $cat_ids,
                    'include_children' => true,
                ],
            ],
        ];

        // Filter by team name if provided
        if ($search) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $image = wp_get_attachment_image_url($product->get_image_id(), 'medium');

            // Determine type
            $terms = get_the_terms($post->ID, 'product_cat');
            $type = 'Product';
            if ($terms) {
                foreach ($terms as $t) {
                    $name = strtolower($t->name);
                    if (strpos($name, 'coaster') !== false) { $type = 'Coasters'; break; }
                    if (strpos($name, 'wall art') !== false || strpos($name, 'stadium') !== false) { $type = 'Wall Art'; break; }
                }
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => strip_tags(wc_price($product->get_price())),
                'price_raw' => $product->get_price(),
                'url' => $product->get_permalink(),
                'image' => $image ?: '',
                'type' => $type,
            ];
        }

        // Sort: Wall Art first, then Coasters
        usort($products, function($a, $b) {
            $order = ['Wall Art' => 0, 'Coasters' => 1, 'Product' => 2];
            return ($order[$a['type']] ?? 2) - ($order[$b['type']] ?? 2);
        });

        wp_send_json_success(['products' => $products]);
    }

    /*--------------------------------------------------------------
    # Shortcode Render
    --------------------------------------------------------------*/

    public function render_shortcode($atts) {
        ob_start();
        ?>
        <div id="rtsf-finder" class="rtsf-finder">
            <div class="rtsf-hero">
                <h2 class="rtsf-title">Find Your Stadium</h2>
                <p class="rtsf-subtitle">Pick your sport and team to discover handcrafted coasters & wall art</p>
            </div>

            <!-- Step 1: Sport -->
            <div class="rtsf-step active" id="rtsf-step-sport">
                <div class="rtsf-step-label">Choose Your Sport</div>
                <div class="rtsf-sport-grid" id="rtsf-sports"></div>
            </div>

            <!-- Step 2: League -->
            <div class="rtsf-step" id="rtsf-step-league">
                <div class="rtsf-breadcrumb">
                    <a href="#" class="rtsf-back" data-back="sport">← Back</a>
                    <span id="rtsf-sport-name"></span>
                </div>
                <div class="rtsf-step-label">Choose Your League</div>
                <div class="rtsf-league-grid" id="rtsf-leagues"></div>
            </div>

            <!-- Step 3: Results -->
            <div class="rtsf-step" id="rtsf-step-results">
                <div class="rtsf-breadcrumb">
                    <a href="#" class="rtsf-back" data-back="league">← Back</a>
                    <span id="rtsf-league-name"></span>
                </div>
                <div class="rtsf-search-bar">
                    <input type="text" id="rtsf-search" placeholder="Search for a team name…">
                </div>
                <div class="rtsf-results-header">
                    <span id="rtsf-result-count"></span>
                    <div class="rtsf-filter-btns">
                        <button class="rtsf-filter active" data-filter="all">All</button>
                        <button class="rtsf-filter" data-filter="Wall Art">Wall Art</button>
                        <button class="rtsf-filter" data-filter="Coasters">Coasters</button>
                    </div>
                </div>
                <div class="rtsf-product-grid" id="rtsf-products"></div>
                <div class="rtsf-loading" id="rtsf-loading" style="display:none;">
                    <div class="rtsf-spinner"></div>
                    <span>Finding your gear…</span>
                </div>
                <div class="rtsf-empty" id="rtsf-empty" style="display:none;">
                    No products found. Try a different search or league!
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

RT_Stadium_Finder::get_instance();
