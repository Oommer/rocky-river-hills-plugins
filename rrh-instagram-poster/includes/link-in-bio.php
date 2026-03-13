<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_Link_In_Bio {

    public static function init() {
        add_action('init', [__CLASS__, 'register_rewrite']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'render']);
        add_shortcode('rrh_instagram_feed', [__CLASS__, 'shortcode']);
    }

    public static function register_rewrite() {
        add_rewrite_rule('^instagram/?$', 'index.php?rrh_ig_linkinbio=1', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'rrh_ig_linkinbio';
        return $vars;
    }

    public static function render() {
        if (!get_query_var('rrh_ig_linkinbio')) return;
        if (get_option('rrh_ig_linkinbio_enabled') !== '1') {
            wp_redirect(home_url());
            exit;
        }

        $posts = self::get_recent_posts(12);
        self::output_page($posts);
        exit;
    }

    public static function get_recent_posts($limit = 12) {
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, 
                    CASE WHEN p.woo_product_id IS NOT NULL THEN 1 ELSE 0 END as has_product
             FROM {$table} p
             WHERE p.status = 'published'
             ORDER BY p.published_at DESC LIMIT %d", $limit
        ));
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(['count' => 6, 'columns' => 3], $atts);
        $posts = self::get_recent_posts(intval($atts['count']));

        if (empty($posts)) return '<p>No posts yet!</p>';

        $cols = intval($atts['columns']);
        $html = '<div class="rrh-ig-feed" style="display:grid; grid-template-columns:repeat(' . $cols . ',1fr); gap:8px; max-width:800px;">';

        foreach ($posts as $post) {
            $link = $post->ig_permalink ?: '#';
            $product_link = '';
            if ($post->woo_product_id) {
                $product_link = get_permalink($post->woo_product_id);
            }

            $html .= '<div class="rrh-ig-feed-item" style="position:relative; aspect-ratio:1; overflow:hidden; border-radius:4px;">';
            $html .= '<a href="' . esc_url($product_link ?: $link) . '" target="_blank">';
            $html .= '<img src="' . esc_url($post->media_url) . '" style="width:100%; height:100%; object-fit:cover;" alt="" loading="lazy">';
            $html .= '</a></div>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function output_page($posts) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $shop_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : $site_url;
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';

        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($site_name); ?> | Instagram</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #fafafa; color: #333; min-height: 100vh; }
        .lib-container { max-width: 480px; margin: 0 auto; padding: 20px 16px; }
        .lib-header { text-align: center; padding: 24px 0; }
        .lib-logo { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #A2755A; }
        .lib-name { font-size: 20px; font-weight: 700; margin-top: 12px; }
        .lib-tagline { color: #666; font-size: 14px; margin-top: 4px; }
        .lib-links { display: flex; flex-direction: column; gap: 10px; margin: 16px 0; }
        .lib-link { display: block; padding: 14px 20px; background: #A2755A; color: #fff; text-decoration: none; border-radius: 8px; text-align: center; font-weight: 600; font-size: 15px; transition: transform 0.15s, opacity 0.15s; }
        .lib-link:hover { transform: scale(1.02); opacity: 0.9; }
        .lib-link.secondary { background: #6B6B6B; }
        .lib-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; margin-top: 24px; }
        .lib-item { position: relative; aspect-ratio: 1; overflow: hidden; border-radius: 4px; }
        .lib-item img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.2s; }
        .lib-item:hover img { transform: scale(1.05); }
        .lib-item a { display: block; width: 100%; height: 100%; }
        .lib-overlay { position: absolute; bottom: 0; left: 0; right: 0; padding: 8px; background: linear-gradient(transparent, rgba(0,0,0,0.7)); color: #fff; font-size: 11px; font-weight: 600; opacity: 0; transition: opacity 0.2s; }
        .lib-item:hover .lib-overlay { opacity: 1; }
        .lib-footer { text-align: center; padding: 24px 0; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="lib-container">
        <div class="lib-header">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="" class="lib-logo">
            <?php endif; ?>
            <div class="lib-name"><?php echo esc_html($site_name); ?></div>
            <div class="lib-tagline">Stadium-themed products & coasters</div>
        </div>

        <div class="lib-links">
            <a href="<?php echo esc_url($shop_url); ?>" class="lib-link">🛍️ Shop All Products</a>
            <a href="<?php echo esc_url($site_url); ?>" class="lib-link secondary">🏟️ Visit Our Website</a>
        </div>

        <?php if (!empty($posts)): ?>
        <div class="lib-grid">
            <?php foreach ($posts as $post):
                $link = '#';
                $label = '';
                if ($post->woo_product_id) {
                    $link = get_permalink($post->woo_product_id);
                    $label = get_the_title($post->woo_product_id);
                } elseif ($post->ig_permalink) {
                    $link = $post->ig_permalink;
                }
            ?>
            <div class="lib-item">
                <a href="<?php echo esc_url($link); ?>" target="_blank">
                    <img src="<?php echo esc_url($post->media_url); ?>" alt="" loading="lazy">
                    <?php if ($label): ?>
                    <div class="lib-overlay"><?php echo esc_html($label); ?></div>
                    <?php endif; ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="lib-footer">
            &copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>
        </div>
    </div>
</body>
</html><?php
    }
}
