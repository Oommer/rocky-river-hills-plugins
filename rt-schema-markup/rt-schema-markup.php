<?php
/**
 * Plugin Name: RT Schema Markup
 * Description: Enhanced JSON-LD structured data for WooCommerce products. Adds rich Product schema with brand, reviews, breadcrumbs, and Organization markup for Google rich results.
 * Version: 1.0.0
 * Author: Rocky River Hills
 * Requires Plugins: woocommerce
 * Text Domain: rt-schema-markup
 */

if (!defined('ABSPATH')) exit;

define('RTSM_VERSION', '1.0.0');
define('RTSM_PATH', plugin_dir_path(__FILE__));
define('RTSM_URL', plugin_dir_url(__FILE__));

class RT_Schema_Markup {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Disable WooCommerce default structured data for products to avoid duplicates
        add_action('init', [$this, 'disable_wc_schema']);

        // Output our enhanced schema
        add_action('wp_head', [$this, 'output_schema'], 99);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_rtsm_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_rtsm_test_schema', [$this, 'ajax_test_schema']);
    }

    public function activate() {
        $defaults = [
            'enabled' => 1,
            'brand' => get_bloginfo('name'),
            'organization_name' => get_bloginfo('name'),
            'organization_url' => home_url(),
            'organization_logo' => '',
            'description' => get_bloginfo('description'),
            'locality' => 'Albemarle',
            'region' => 'NC',
            'postal_code' => '',
            'country' => 'US',
            'email' => get_option('admin_email'),
            'phone' => '',
            'social_facebook' => '',
            'social_instagram' => '',
            'social_pinterest' => '',
            'product_material' => 'Wood, Acrylic',
            'enable_breadcrumbs' => 1,
            'enable_product' => 1,
            'enable_organization' => 1,
            'enable_website' => 1,
            'enable_local_business' => 0,
        ];

        if (!get_option('rtsm_settings')) {
            update_option('rtsm_settings', $defaults);
        }
    }

    /*--------------------------------------------------------------
    # Disable WooCommerce Default Product Schema
    --------------------------------------------------------------*/

    public function disable_wc_schema() {
        // Remove WooCommerce's built-in product structured data
        // so we don't end up with duplicate/conflicting schema
        remove_action('wp_footer', ['WC_Structured_Data', 'output_structured_data'], 10);

        // Also try the instance approach
        if (function_exists('WC') && WC()->structured_data) {
            remove_action('woocommerce_single_product_summary', [WC()->structured_data, 'generate_product_data'], 60);
        }

        // Filter approach as backup
        add_filter('woocommerce_structured_data_product', '__return_empty_array');
    }

    /*--------------------------------------------------------------
    # Schema Output
    --------------------------------------------------------------*/

    public function output_schema() {
        $settings = get_option('rtsm_settings', []);
        if (empty($settings['enabled'])) return;

        $schemas = [];

        // Organization — on all pages
        if (!empty($settings['enable_organization'])) {
            $schemas[] = $this->build_organization($settings);
        }

        // WebSite — on homepage
        if (!empty($settings['enable_website']) && is_front_page()) {
            $schemas[] = $this->build_website($settings);
        }

        // Product — on single product pages
        if (!empty($settings['enable_product']) && is_singular('product')) {
            $product_schema = $this->build_product($settings);
            if ($product_schema) $schemas[] = $product_schema;
        }

        // Breadcrumbs — on non-homepage pages
        if (!empty($settings['enable_breadcrumbs']) && !is_front_page()) {
            $schemas[] = $this->build_breadcrumbs();
        }

        // LocalBusiness — on homepage or about page
        if (!empty($settings['enable_local_business']) && (is_front_page() || is_page('about'))) {
            $schemas[] = $this->build_local_business($settings);
        }

        // Output each schema
        foreach ($schemas as $schema) {
            if (!$schema) continue;
            echo "\n<!-- RT Schema Markup -->\n";
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            echo "\n</script>\n";
        }
    }

    /*--------------------------------------------------------------
    # Organization Schema
    --------------------------------------------------------------*/

    private function build_organization($settings) {
        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $settings['organization_name'] ?? get_bloginfo('name'),
            'url' => $settings['organization_url'] ?? home_url(),
        ];

        if (!empty($settings['organization_logo'])) {
            $org['logo'] = $settings['organization_logo'];
        }

        if (!empty($settings['description'])) {
            $org['description'] = $settings['description'];
        }

        if (!empty($settings['email'])) {
            $org['email'] = $settings['email'];
        }

        if (!empty($settings['phone'])) {
            $org['telephone'] = $settings['phone'];
        }

        // Social profiles
        $same_as = [];
        if (!empty($settings['social_facebook'])) $same_as[] = $settings['social_facebook'];
        if (!empty($settings['social_instagram'])) $same_as[] = $settings['social_instagram'];
        if (!empty($settings['social_pinterest'])) $same_as[] = $settings['social_pinterest'];
        if (!empty($same_as)) $org['sameAs'] = $same_as;

        // Address
        if (!empty($settings['locality'])) {
            $org['address'] = [
                '@type' => 'PostalAddress',
                'addressLocality' => $settings['locality'],
                'addressRegion' => $settings['region'] ?? '',
                'addressCountry' => $settings['country'] ?? 'US',
            ];
            if (!empty($settings['postal_code'])) {
                $org['address']['postalCode'] = $settings['postal_code'];
            }
        }

        return $org;
    }

    /*--------------------------------------------------------------
    # WebSite Schema (enables sitelinks search box)
    --------------------------------------------------------------*/

    private function build_website($settings) {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $settings['organization_name'] ?? get_bloginfo('name'),
            'url' => home_url(),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => home_url('/?s={search_term_string}'),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /*--------------------------------------------------------------
    # Product Schema (the big one)
    --------------------------------------------------------------*/

    private function build_product($settings) {
        global $product;

        if (!$product || !is_a($product, 'WC_Product')) {
            $product = wc_get_product(get_the_ID());
        }
        if (!$product) return null;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'url' => $product->get_permalink(),
            'productID' => (string) $product->get_id(),
        ];

        // Description
        $desc = $product->get_short_description();
        if (empty($desc)) $desc = $product->get_description();
        if ($desc) {
            $schema['description'] = wp_strip_all_tags($desc);
        }

        // SKU
        if ($product->get_sku()) {
            $schema['sku'] = $product->get_sku();
            $schema['mpn'] = $product->get_sku();
        }

        // Brand
        $brand = $settings['brand'] ?? get_bloginfo('name');
        $schema['brand'] = [
            '@type' => 'Brand',
            'name' => $brand,
        ];

        // Material
        if (!empty($settings['product_material'])) {
            $schema['material'] = $settings['product_material'];
        }

        // Category (as product type for Google)
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $cat_names = [];
            foreach ($terms as $term) {
                $cat_names[] = $term->name;
            }
            $schema['category'] = implode(', ', $cat_names);
        }

        // Images
        $image_id = $product->get_image_id();
        if ($image_id) {
            $img_url = wp_get_attachment_url($image_id);
            $img_data = wp_get_attachment_image_src($image_id, 'full');
            $schema['image'] = [$img_url];

            // Gallery images
            $gallery = $product->get_gallery_image_ids();
            foreach ($gallery as $gid) {
                $url = wp_get_attachment_url($gid);
                if ($url) $schema['image'][] = $url;
            }
        }

        // Offers
        $offers = [];
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            foreach ($variations as $var) {
                $v = wc_get_product($var['variation_id']);
                if (!$v) continue;
                $offer = $this->build_offer($v, $settings);
                if ($offer) $offers[] = $offer;
            }
        }

        if (empty($offers)) {
            $offer = $this->build_offer($product, $settings);
            if ($offer) $offers[] = $offer;
        }

        if (count($offers) === 1) {
            $schema['offers'] = $offers[0];
        } elseif (count($offers) > 1) {
            $schema['offers'] = [
                '@type' => 'AggregateOffer',
                'lowPrice' => $product->get_variation_price('min'),
                'highPrice' => $product->get_variation_price('max'),
                'priceCurrency' => get_woocommerce_currency(),
                'offerCount' => count($offers),
                'offers' => $offers,
            ];
        }

        // Reviews & Ratings
        $review_count = $product->get_review_count();
        $avg_rating = $product->get_average_rating();

        if ($review_count > 0 && $avg_rating > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float)$avg_rating, 1),
                'reviewCount' => $review_count,
                'bestRating' => '5',
                'worstRating' => '1',
            ];

            // Individual reviews
            $comments = get_comments([
                'post_id' => $product->get_id(),
                'status' => 'approve',
                'type' => 'review',
                'number' => 10,
                'orderby' => 'comment_date',
                'order' => 'DESC',
            ]);

            $reviews = [];
            foreach ($comments as $c) {
                $rating = get_comment_meta($c->comment_ID, 'rating', true);
                if (!$rating) continue;
                $reviews[] = [
                    '@type' => 'Review',
                    'author' => [
                        '@type' => 'Person',
                        'name' => $c->comment_author,
                    ],
                    'datePublished' => date('Y-m-d', strtotime($c->comment_date)),
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => (int) $rating,
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ],
                ];
                if (!empty($c->comment_content)) {
                    $reviews[count($reviews) - 1]['reviewBody'] = wp_strip_all_tags($c->comment_content);
                }
            }

            if (!empty($reviews)) {
                $schema['review'] = $reviews;
            }
        }

        // Weight / dimensions
        $weight = $product->get_weight();
        if ($weight) {
            $wunit = get_option('woocommerce_weight_unit', 'lbs');
            $unit_map = ['lbs' => 'LBR', 'kg' => 'KGM', 'g' => 'GRM', 'oz' => 'ONZ'];
            $schema['weight'] = [
                '@type' => 'QuantitativeValue',
                'value' => $weight,
                'unitCode' => $unit_map[$wunit] ?? 'LBR',
            ];
        }

        $length = $product->get_length();
        $width = $product->get_width();
        $height = $product->get_height();
        if ($length && $width && $height) {
            $dunit = get_option('woocommerce_dimension_unit', 'in');
            $dim_map = ['in' => 'INH', 'cm' => 'CMT', 'mm' => 'MMT', 'm' => 'MTR'];
            $code = $dim_map[$dunit] ?? 'INH';
            $schema['depth'] = ['@type' => 'QuantitativeValue', 'value' => $length, 'unitCode' => $code];
            $schema['width'] = ['@type' => 'QuantitativeValue', 'value' => $width, 'unitCode' => $code];
            $schema['height'] = ['@type' => 'QuantitativeValue', 'value' => $height, 'unitCode' => $code];
        }

        // Identifier exists
        $schema['identifier_exists'] = 'no';

        return $schema;
    }

    private function build_offer($product, $settings) {
        $price = $product->get_price();
        if (!$price) return null;

        $offer = [
            '@type' => 'Offer',
            'url' => $product->get_permalink(),
            'price' => number_format((float)$price, 2, '.', ''),
            'priceCurrency' => get_woocommerce_currency(),
            'priceValidUntil' => date('Y-12-31'),
            'itemCondition' => 'https://schema.org/NewCondition',
        ];

        // Availability
        $status = $product->get_stock_status();
        $avail_map = [
            'instock' => 'https://schema.org/InStock',
            'outofstock' => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/PreOrder',
        ];
        $offer['availability'] = $avail_map[$status] ?? 'https://schema.org/InStock';

        // Seller
        $offer['seller'] = [
            '@type' => 'Organization',
            'name' => $settings['brand'] ?? get_bloginfo('name'),
        ];

        // Shipping (for US)
        $offer['shippingDetails'] = [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => 'US',
            ],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 1,
                    'maxValue' => 3,
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 3,
                    'maxValue' => 7,
                    'unitCode' => 'DAY',
                ],
            ],
        ];

        // Return policy
        $offer['hasMerchantReturnPolicy'] = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'US',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 30,
            'returnMethod' => 'https://schema.org/ReturnByMail',
        ];

        return $offer;
    }

    /*--------------------------------------------------------------
    # Breadcrumbs Schema
    --------------------------------------------------------------*/

    private function build_breadcrumbs() {
        $items = [];
        $pos = 1;

        $items[] = [
            '@type' => 'ListItem',
            'position' => $pos++,
            'name' => 'Home',
            'item' => home_url(),
        ];

        if (is_singular('product')) {
            global $product;
            if (!$product) $product = wc_get_product(get_the_ID());

            // Shop page
            $shop_id = wc_get_page_id('shop');
            if ($shop_id > 0) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $pos++,
                    'name' => 'Shop',
                    'item' => get_permalink($shop_id),
                ];
            }

            // Category
            $terms = get_the_terms(get_the_ID(), 'product_cat');
            if ($terms && !is_wp_error($terms)) {
                // Find deepest category
                $deepest = null;
                $max_depth = -1;
                foreach ($terms as $term) {
                    $depth = 0;
                    $current = $term;
                    while ($current->parent) {
                        $depth++;
                        $current = get_term($current->parent, 'product_cat');
                    }
                    if ($depth > $max_depth) {
                        $max_depth = $depth;
                        $deepest = $term;
                    }
                }

                if ($deepest) {
                    // Build ancestor chain
                    $chain = [];
                    $current = $deepest;
                    while ($current) {
                        array_unshift($chain, $current);
                        $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
                    }
                    foreach ($chain as $cat) {
                        $items[] = [
                            '@type' => 'ListItem',
                            'position' => $pos++,
                            'name' => $cat->name,
                            'item' => get_term_link($cat),
                        ];
                    }
                }
            }

            // Product itself
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => get_the_title(),
            ];
        } elseif (is_product_category()) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => 'Shop',
                'item' => get_permalink(wc_get_page_id('shop')),
            ];
            $term = get_queried_object();
            if ($term) {
                // Ancestors
                $ancestors = get_ancestors($term->term_id, 'product_cat');
                foreach (array_reverse($ancestors) as $anc_id) {
                    $anc = get_term($anc_id, 'product_cat');
                    $items[] = [
                        '@type' => 'ListItem',
                        'position' => $pos++,
                        'name' => $anc->name,
                        'item' => get_term_link($anc),
                    ];
                }
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $pos++,
                    'name' => $term->name,
                ];
            }
        } elseif (is_singular()) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => get_the_title(),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /*--------------------------------------------------------------
    # LocalBusiness Schema (optional)
    --------------------------------------------------------------*/

    private function build_local_business($settings) {
        $biz = [
            '@context' => 'https://schema.org',
            '@type' => 'Store',
            'name' => $settings['organization_name'] ?? get_bloginfo('name'),
            'url' => home_url(),
            'description' => $settings['description'] ?? '',
            'priceRange' => '$$',
        ];

        if (!empty($settings['organization_logo'])) {
            $biz['image'] = $settings['organization_logo'];
        }

        if (!empty($settings['email'])) {
            $biz['email'] = $settings['email'];
        }

        if (!empty($settings['locality'])) {
            $biz['address'] = [
                '@type' => 'PostalAddress',
                'addressLocality' => $settings['locality'],
                'addressRegion' => $settings['region'] ?? '',
                'addressCountry' => $settings['country'] ?? 'US',
            ];
        }

        $same_as = [];
        if (!empty($settings['social_facebook'])) $same_as[] = $settings['social_facebook'];
        if (!empty($settings['social_instagram'])) $same_as[] = $settings['social_instagram'];
        if (!empty($settings['social_pinterest'])) $same_as[] = $settings['social_pinterest'];
        if (!empty($same_as)) $biz['sameAs'] = $same_as;

        return $biz;
    }

    /*--------------------------------------------------------------
    # Admin
    --------------------------------------------------------------*/

    public function admin_menu() {
        add_menu_page('Schema Markup', 'Schema Markup', 'manage_options', 'rt-schema-markup', [$this, 'admin_page'], 'dashicons-media-code', 62);
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_rt-schema-markup') return;
        wp_enqueue_style('rtsm-admin', RTSM_URL . 'admin.css', [], RTSM_VERSION);
        wp_enqueue_script('rtsm-admin', RTSM_URL . 'admin.js', ['jquery'], RTSM_VERSION, true);
        wp_localize_script('rtsm-admin', 'rtsm', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rtsm_nonce'),
        ]);
    }

    public function admin_page() {
        $settings = get_option('rtsm_settings', []);
        include RTSM_PATH . 'admin-page.php';
    }

    public function ajax_save_settings() {
        check_ajax_referer('rtsm_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = [];
        if (!empty($_POST['settings'])) parse_str($_POST['settings'], $data);

        $clean = [];
        foreach ($data as $k => $v) {
            $clean[sanitize_key($k)] = is_string($v) ? sanitize_text_field($v) : $v;
        }

        $toggles = ['enabled', 'enable_breadcrumbs', 'enable_product', 'enable_organization', 'enable_website', 'enable_local_business'];
        foreach ($toggles as $k) { $clean[$k] = isset($clean[$k]) ? 1 : 0; }

        update_option('rtsm_settings', $clean);
        wp_send_json_success('Settings saved');
    }

    public function ajax_test_schema() {
        check_ajax_referer('rtsm_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('rtsm_settings', []);
        $results = [];

        // Test Organization
        if (!empty($settings['enable_organization'])) {
            $org = $this->build_organization($settings);
            $results[] = ['type' => 'Organization', 'valid' => !empty($org['name']), 'data' => $org];
        }

        // Test Product (grab first published product)
        if (!empty($settings['enable_product'])) {
            $args = ['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids'];
            $q = new WP_Query($args);
            if ($q->posts) {
                global $product;
                $product = wc_get_product($q->posts[0]);
                $prod = $this->build_product($settings);
                $valid = !empty($prod['name']) && !empty($prod['offers']);
                $results[] = ['type' => 'Product (sample)', 'valid' => $valid, 'data' => $prod];
            }
        }

        wp_send_json_success(['results' => $results]);
    }
}

RT_Schema_Markup::get_instance();
