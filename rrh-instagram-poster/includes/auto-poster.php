<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_Auto_Poster {

    private $plugin;
    private $_last_template_hashtags = '';
    private $_last_chosen_template = '';
    private $_last_template_name = '';

    public function __construct($plugin) {
        $this->plugin = $plugin;

        // Auto-post when product is published
        add_action('transition_post_status', [$this, 'on_product_publish'], 10, 3);

        // Sale announcement when sale price is set
        add_action('woocommerce_product_set_sale_price', [$this, 'on_sale_price_set'], 10, 2);
    }

    /**
     * Auto-post when a new product is published
     */
    public function on_product_publish($new_status, $old_status, $post) {
        if (get_option('rrh_ig_auto_post_enabled') !== '1') return;
        if ($post->post_type !== 'product') return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;

        $product = wc_get_product($post->ID);
        if (!$product) return;

        $image_url = RRH_Instagram_Poster::get_proxy_image_url($product->get_image_id());
        if (!$image_url) return;

        // Check if we already auto-posted this product
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rrh_ig_posts WHERE woo_product_id=%d AND media_source='auto'",
            $post->ID
        ));
        if ($exists) return;

        // Build caption using category-matched template (same logic as autopilot)
        $caption = $this->build_smart_caption($post->ID, $product);

        // Build smart hashtags: defaults + category + product-specific + template
        $all_tags = [];

        // Default hashtags from autopilot settings
        $default_hashtags = get_option('rrh_ig_autopilot_default_hashtags', '#stadiumcoasters #stadiumart #sportsmemories #gameday #collegegameday #tailgate #homebar #rockyriverhills #football #baseball #basketball #hockey #soccer #homedecor #coasters #drinkware #giftideas #sportsbar #mancave #shopsmall #supportsmallbusiness #handmade #smallbiz');
        if (!empty($default_hashtags)) $all_tags[] = $default_hashtags;

        $cat_hashtags = $this->plugin->get_category_hashtags_for_product($post->ID);
        if ($cat_hashtags) $all_tags[] = $cat_hashtags;

        $product_hashtags = get_post_meta($post->ID, '_rrh_ig_hashtags', true);
        if ($product_hashtags) $all_tags[] = $product_hashtags;

        if (!empty($this->_last_template_hashtags)) {
            $all_tags[] = $this->_last_template_hashtags;
        }

        // Deduplicate (case-insensitive)
        $raw = explode(' ', implode(' ', $all_tags));
        $unique = [];
        $seen = [];
        foreach ($raw as $tag) {
            $tag = trim($tag);
            if (strpos($tag, '#') !== 0) continue;
            $lower = strtolower($tag);
            if (!isset($seen[$lower])) {
                $seen[$lower] = true;
                $unique[] = $tag;
            }
        }
        $unique = array_slice($unique, 0, 30);
        if (!empty($unique)) $caption .= "\n\n" . implode(' ', $unique);

        // Check for carousel
        $gallery_ids = $product->get_gallery_image_ids();
        $gallery = array_filter(array_map([RRH_Instagram_Poster::class, 'get_proxy_image_url'], $gallery_ids));
        $type = 'image';
        $media_urls = null;
        if (!empty($gallery)) {
            $all = array_merge([$image_url], array_slice($gallery, 0, 9));
            if (count($all) > 1) {
                $type = 'carousel';
                $media_urls = json_encode($all);
            }
        }

        $this->plugin->create_post([
            'post_type' => $type,
            'caption' => $caption,
            'media_url' => $image_url,
            'media_urls' => $media_urls,
            'media_source' => 'auto',
            'woo_product_id' => $post->ID,
            'status' => 'queued',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
        ]);
    }

    /*--------------------------------------------------------------
    # Autopilot — Daily Product Rotation
    --------------------------------------------------------------*/

    /**
     * Main autopilot cron callback. Picks next product(s) and queues them.
     */
    public function run_autopilot() {
        $settings = $this->get_autopilot_settings();
        if (empty($settings['enabled'])) return;

        $posts_per_day = max(1, intval($settings['posts_per_day']));
        $post_hour = intval($settings['post_hour']);
        $post_minute = intval($settings['post_minute'] ?? 0);

        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';

        // How many autopilot posts are already queued (not yet published)?
        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE media_source = 'autopilot' AND status = 'queued'"
        );

        // If we already have 7+ days queued up, don't add more
        $max_queued = $posts_per_day * 7;
        if ($pending_count >= $max_queued) {
            error_log("[RRH IG] Autopilot: already {$pending_count} posts queued (max {$max_queued}). Skipping.");
            return;
        }

        // Find the latest scheduled autopilot date (queued or published)
        $last_scheduled = $wpdb->get_var(
            "SELECT MAX(scheduled_at) FROM {$table} 
             WHERE media_source = 'autopilot' 
             AND status IN ('queued','publishing','published')
             AND scheduled_at IS NOT NULL"
        );

        // Start scheduling from the day after the last scheduled post
        // or from today if nothing is scheduled
        if ($last_scheduled) {
            $next_date = date('Y-m-d', strtotime($last_scheduled . ' +1 day'));
        } else {
            $next_date = current_time('Y-m-d');
        }

        // If next_date is in the past, start from today
        if (strtotime($next_date) < strtotime(current_time('Y-m-d'))) {
            $next_date = current_time('Y-m-d');
        }

        // Also check: does today have a post? If not, queue one for today first.
        $today = current_time('Y-m-d');
        $today_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
             WHERE media_source = 'autopilot' 
             AND scheduled_at BETWEEN %s AND %s 
             AND status IN ('queued','publishing','published')",
            $today . ' 00:00:00', $today . ' 23:59:59'
        ));
        if ($today_count < $posts_per_day && strtotime($next_date) > strtotime($today)) {
            $next_date = $today;
        }

        // Queue up to fill 7 days worth of posts
        $slots_to_fill = $max_queued - $pending_count;
        $queued = 0;

        error_log("[RRH IG] Autopilot bulk queue: pending={$pending_count}, slots={$slots_to_fill}, starting from {$next_date}");

        for ($day_offset = 0; $queued < $slots_to_fill; $day_offset++) {
            $target_date = date('Y-m-d', strtotime($next_date . " +{$day_offset} days"));

            // Check how many are already scheduled for this date
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} 
                 WHERE media_source = 'autopilot' 
                 AND scheduled_at BETWEEN %s AND %s 
                 AND status IN ('queued','publishing','published')",
                $target_date . ' 00:00:00', $target_date . ' 23:59:59'
            ));

            $needed = $posts_per_day - $existing;
            if ($needed <= 0) continue;

            for ($i = 0; $i < $needed && $queued < $slots_to_fill; $i++) {
                $product_id = $this->pick_next_product($settings);
                if (!$product_id) {
                    error_log('[RRH IG] Autopilot: no more products available');
                    return;
                }

                $this->queue_autopilot_post_for_date($product_id, $settings, $target_date, $i);
                $queued++;
            }

            // Safety: don't schedule more than 90 days out
            if ($day_offset > 90) break;
        }

        error_log("[RRH IG] Autopilot: queued {$queued} new posts");
        update_option('rrh_ig_autopilot_last_run', current_time('mysql'));
    }

    /**
     * Pick the next product to post based on rotation logic.
     * Cycles through all products, skips any already queued or recently posted.
     */
    private function pick_next_product($settings) {
        global $wpdb;

        // Get all published WooCommerce products with images
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
        ]);

        if (empty($products)) return null;

        // Exclude anything already queued (pending) or recently published
        $cooldown_days = max(1, intval($settings['cooldown_days']));
        $already_used = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT woo_product_id FROM {$wpdb->prefix}rrh_ig_posts 
             WHERE media_source = 'autopilot' 
             AND woo_product_id IS NOT NULL
             AND (
                 status = 'queued'
                 OR (status IN ('publishing','published') AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY))
             )",
            $cooldown_days
        ));

        $exclude = array_map('intval', $already_used);

        // Filter available products
        $available = array_diff($products, $exclude);

        // If all products used up, start fresh from oldest-posted
        if (empty($available)) {
            $oldest = $wpdb->get_var(
                "SELECT woo_product_id FROM {$wpdb->prefix}rrh_ig_posts 
                 WHERE media_source = 'autopilot' AND woo_product_id IS NOT NULL AND status = 'published'
                 GROUP BY woo_product_id ORDER BY MAX(published_at) ASC LIMIT 1"
            );
            if ($oldest) return intval($oldest);

            // Never been posted? Pick first product
            return !empty($products) ? $products[0] : null;
        }

        // Get the last queued/posted product ID to continue the rotation
        $last_id = $wpdb->get_var(
            "SELECT woo_product_id FROM {$wpdb->prefix}rrh_ig_posts 
             WHERE media_source = 'autopilot' AND woo_product_id IS NOT NULL
             ORDER BY created_at DESC LIMIT 1"
        );

        // Sort available products by ID to maintain consistent order
        sort($available);

        // Find next product after the last one (round-robin)
        if ($last_id) {
            foreach ($available as $pid) {
                if ($pid > intval($last_id)) return $pid;
            }
        }

        // Wrap around to the beginning
        return $available[0];
    }

    /**
     * Queue an autopilot post for a specific product on a specific date.
     */
    private function queue_autopilot_post_for_date($product_id, $settings, $target_date, $offset = 0) {
        $product = wc_get_product($product_id);
        if (!$product) return false;

        $image_url = RRH_Instagram_Poster::get_proxy_image_url($product->get_image_id());
        if (!$image_url) return false;

        // ── Template Selection: category-matched, random pick ──
        $caption = $this->build_smart_caption($product_id, $product);

        // ── Smart Hashtags: brand defaults + category mapping + product-specific ──
        $all_tags = [];

        // 1. Default hashtags from autopilot settings (the full 23-tag set)
        $default_hashtags = $settings['default_hashtags'];
        if (!empty($default_hashtags)) $all_tags[] = $default_hashtags;

        // 2. Category hashtags from WooCommerce category mapping
        $cat_hashtags = $this->plugin->get_category_hashtags_for_product($product_id);
        if ($cat_hashtags) $all_tags[] = $cat_hashtags;

        // 3. Product-specific city/team hashtags
        $product_hashtags = get_post_meta($product_id, '_rrh_ig_hashtags', true);
        if ($product_hashtags) $all_tags[] = $product_hashtags;

        // 4. Template hashtags (if the selected template has its own)
        if (!empty($this->_last_template_hashtags)) {
            $all_tags[] = $this->_last_template_hashtags;
        }

        // Deduplicate hashtags (case-insensitive)
        $raw = explode(' ', implode(' ', $all_tags));
        $unique = [];
        $seen = [];
        foreach ($raw as $tag) {
            $tag = trim($tag);
            if (strpos($tag, '#') !== 0) continue;
            $lower = strtolower($tag);
            if (!isset($seen[$lower])) {
                $seen[$lower] = true;
                $unique[] = $tag;
            }
        }
        // Instagram max 30 hashtags
        $unique = array_slice($unique, 0, 30);
        if (!empty($unique)) {
            $caption .= "\n\n" . implode(' ', $unique);
        }

        // Carousel detection
        $gallery_ids = $product->get_gallery_image_ids();
        $gallery = array_filter(array_map([RRH_Instagram_Poster::class, 'get_proxy_image_url'], $gallery_ids));
        $type = 'image';
        $media_urls = null;
        if (!empty($gallery)) {
            $all_images = array_merge([$image_url], array_slice($gallery, 0, 9));
            if (count($all_images) > 1) {
                $type = 'carousel';
                $media_urls = json_encode($all_images);
            }
        }

        // Calculate scheduled time for the target date
        $hour = intval($settings['post_hour']);
        $minute = intval($settings['post_minute'] ?? 0);

        // Add spacing for multiple posts per day
        $minute += ($offset * intval($settings['spacing_hours'] ?? 4) * 60);
        $extra_hours = floor($minute / 60);
        $minute = $minute % 60;
        $hour += $extra_hours;
        if ($hour > 23) $hour = 23;

        $scheduled = $target_date . sprintf(' %02d:%02d:00', $hour, $minute);

        // If this is today and the time already passed, post in 10 minutes
        if ($target_date === current_time('Y-m-d') && strtotime($scheduled) < strtotime(current_time('mysql'))) {
            $scheduled = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' +10 minutes'));
        }

        $this->plugin->create_post([
            'post_type' => $type,
            'caption' => $caption,
            'media_url' => $image_url,
            'media_urls' => $media_urls,
            'media_source' => 'autopilot',
            'woo_product_id' => $product_id,
            'status' => 'queued',
            'scheduled_at' => $scheduled,
        ]);

        update_option('rrh_ig_autopilot_last_run', current_time('mysql'));
        update_option('rrh_ig_autopilot_last_product', $product_id);
        update_option('rrh_ig_autopilot_last_template', $this->_last_chosen_template ?: 'unknown');

        error_log("[RRH IG] Autopilot: queued product {$product_id} ({$product->get_name()}) for {$scheduled}");

        return true;
    }

    /**
     * Legacy wrapper — queue for today (used by manual "Run Now").
     */
    private function queue_autopilot_post($product_id, $settings, $offset = 0) {
        return $this->queue_autopilot_post_for_date($product_id, $settings, current_time('Y-m-d'), $offset);
    }

    /**
     * Build caption using category-matched template (randomly selected).
     * STRICT RULES — NO CROSS-CONTAMINATION:
     *   - Coaster products → ONLY templates with category matching "coasters"
     *   - Stadium products → ONLY templates with category matching "stadiums"
     *   - If no matching template → built-in default. NEVER another category's template.
     *   - "General" templates ONLY used for products with NO WooCommerce category.
     */
    /**
     * Build caption using category-matched template (randomly selected).
     * STRICT RULES:
     *  - Coaster products ONLY get coaster templates
     *  - Stadium/wall art products ONLY get stadium templates
     *  - Walks UP the WooCommerce category tree to find parent matches
     *  - Never cross-contaminate categories
     */
    private function build_smart_caption($product_id, $product) {
        $this->_last_template_hashtags = '';
        $this->_last_chosen_template = '';

        // Get ALL category names/slugs for this product, INCLUDING parent categories
        $all_cat_names = [];
        $all_cat_slugs = [];
        $cat_terms = wp_get_post_terms($product_id, 'product_cat');
        foreach ($cat_terms as $term) {
            // Add the direct category
            $all_cat_names[] = strtolower(trim($term->name));
            $all_cat_slugs[] = strtolower(trim($term->slug));

            // Walk UP the parent chain
            $parent_id = $term->parent;
            while ($parent_id > 0) {
                $parent = get_term($parent_id, 'product_cat');
                if ($parent && !is_wp_error($parent)) {
                    $all_cat_names[] = strtolower(trim($parent->name));
                    $all_cat_slugs[] = strtolower(trim($parent->slug));
                    $parent_id = $parent->parent;
                } else {
                    break;
                }
            }
        }

        $all_cat_names = array_unique($all_cat_names);
        $all_cat_slugs = array_unique($all_cat_slugs);
        $product_has_category = !empty($all_cat_names);

        // Debug: log category chain for this product
        error_log('[RRH IG] Template match for product ' . $product_id . ': cat_names=[' . implode(', ', $all_cat_names) . '] cat_slugs=[' . implode(', ', $all_cat_slugs) . ']');

        // Get all saved templates
        $all_templates = RRH_IG_Templates::get_all();

        if (empty($all_templates)) {
            $this->_last_chosen_template = '(no templates exist)';
            return RRH_IG_Publisher::generate_product_caption($product_id, '');
        }

        // Separate templates into buckets
        $matched = [];
        $general = [];

        foreach ($all_templates as $tmpl) {
            $tmpl_cat = strtolower(trim($tmpl->category ?? ''));

            if (empty($tmpl_cat) || $tmpl_cat === 'general') {
                $general[] = $tmpl;
                continue;
            }

            if (!$product_has_category) continue;

            // STRICT MATCHING against ALL categories in chain (direct + parents)
            $tmpl_singular = rtrim($tmpl_cat, 's');

            $is_match = false;
            foreach ($all_cat_names as $name) {
                if ($tmpl_cat === $name || $tmpl_singular === rtrim($name, 's')) {
                    $is_match = true;
                    break;
                }
            }
            if (!$is_match) {
                foreach ($all_cat_slugs as $slug) {
                    if ($tmpl_cat === $slug || $tmpl_singular === rtrim($slug, 's')) {
                        $is_match = true;
                        break;
                    }
                }
            }

            if ($is_match) {
                $matched[] = $tmpl;
                error_log('[RRH IG] Template MATCHED: "' . ($tmpl->name ?? '?') . '" (cat: ' . $tmpl_cat . ')');
            } else {
                error_log('[RRH IG] Template NO MATCH: "' . ($tmpl->name ?? '?') . '" (tmpl_cat: ' . $tmpl_cat . ', tmpl_singular: ' . $tmpl_singular . ')');
            }
        }

        // STRICT SELECTION:
        $chosen = null;

        if (!empty($matched)) {
            $chosen = $matched[array_rand($matched)];
        } elseif (!$product_has_category && !empty($general)) {
            $chosen = $general[array_rand($general)];
        }
        // If product HAS categories but no templates match → built-in default
        // NEVER cross-contaminate
        if (empty($matched) && $product_has_category) {
            error_log('[RRH IG] No template matched for product ' . $product_id . ' — using built-in default');
        }

        if ($chosen) {
            error_log('[RRH IG] Using template: "' . ($chosen->name ?? '?') . '"');
            if (!empty($chosen->hashtags)) {
                $this->_last_template_hashtags = $chosen->hashtags;
            }
            $this->_last_chosen_template = $chosen->name . ' [' . ($chosen->category ?: 'general') . ']';
            return RRH_IG_Publisher::generate_product_caption($product_id, $chosen->caption_template);
        }

        $this->_last_chosen_template = '(built-in default — no template matched)';
        return RRH_IG_Publisher::generate_product_caption($product_id, '');
    }

    /**
     * Get autopilot settings with defaults.
     */
    public function get_autopilot_settings() {
        return [
            'enabled' => get_option('rrh_ig_autopilot_enabled', '0') === '1',
            'posts_per_day' => get_option('rrh_ig_autopilot_posts_per_day', '1'),
            'post_hour' => get_option('rrh_ig_autopilot_post_hour', '10'),
            'post_minute' => get_option('rrh_ig_autopilot_post_minute', '0'),
            'spacing_hours' => get_option('rrh_ig_autopilot_spacing_hours', '4'),
            'cooldown_days' => get_option('rrh_ig_autopilot_cooldown_days', '60'),
            'caption_template' => get_option('rrh_ig_autopilot_caption_template', ''),
            'default_hashtags' => get_option('rrh_ig_autopilot_default_hashtags', '#stadiumcoasters #stadiumart #sportsmemories #gameday #collegegameday #tailgate #homebar #rockyriverhills #football #baseball #basketball #hockey #soccer #homedecor #coasters #drinkware #giftideas #sportsbar #mancave #shopsmall #supportsmallbusiness #handmade #smallbiz'),
        ];
    }

    /**
     * Get autopilot status info for the admin UI.
     */
    public function get_autopilot_status() {
        global $wpdb;

        $settings = $this->get_autopilot_settings();

        // Total products
        $all_product_ids = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']);
        $total_products = count($all_product_ids);

        // Products posted via autopilot (unique)
        $posted_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT woo_product_id) FROM {$wpdb->prefix}rrh_ig_posts 
             WHERE media_source = 'autopilot' AND status = 'published'"
        );

        // Currently queued autopilot posts
        $queued_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rrh_ig_posts 
             WHERE media_source = 'autopilot' AND status = 'queued'"
        );

        // Next product in line
        $next_product_id = $this->pick_next_product($settings);
        $next_product_name = '';
        $next_product_cats = '';
        if ($next_product_id) {
            $p = wc_get_product($next_product_id);
            $next_product_name = $p ? $p->get_name() : "Product #{$next_product_id}";
            $cats = wp_get_post_terms($next_product_id, 'product_cat', ['fields' => 'names']);
            $next_product_cats = implode(', ', $cats);
        }

        // Days to complete full rotation
        $ppd = max(1, intval($settings['posts_per_day']));
        $rotation_days = $total_products > 0 ? ceil($total_products / $ppd) : 0;

        // Template coverage by WooCommerce category
        $templates = RRH_IG_Templates::get_all();
        $template_map = [];
        foreach ($templates as $t) {
            $cat = strtolower(trim($t->category)) ?: 'general';
            if (!isset($template_map[$cat])) $template_map[$cat] = [];
            $template_map[$cat][] = $t->name;
        }

        // WooCommerce product category counts
        $woo_cats = [];
        $cat_terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
        if (!is_wp_error($cat_terms)) {
            foreach ($cat_terms as $term) {
                $cat_lower = strtolower($term->name);
                // Check if any template category matches
                $has_match = false;
                foreach (array_keys($template_map) as $tcat) {
                    if ($tcat === 'general' || $tcat === '') continue;
                    if ($this->category_or_parent_matches($tcat, $term)) {
                        $has_match = true;
                        break;
                    }
                }
                $woo_cats[] = [
                    'name' => $term->name,
                    'count' => $term->count,
                    'has_templates' => $has_match,
                ];
            }
        }

        return [
            'enabled' => $settings['enabled'],
            'total_products' => $total_products,
            'posted_count' => $posted_count,
            'queued_count' => $queued_count,
            'next_product' => $next_product_name,
            'next_product_id' => $next_product_id,
            'next_product_cats' => $next_product_cats,
            'last_run' => get_option('rrh_ig_autopilot_last_run', 'Never'),
            'last_product_id' => get_option('rrh_ig_autopilot_last_product', ''),
            'last_template' => get_option('rrh_ig_autopilot_last_template', '—'),
            'rotation_days' => $rotation_days,
            'cooldown_days' => intval($settings['cooldown_days']),
            'posts_per_day' => $ppd,
            'template_map' => $template_map,
            'woo_categories' => $woo_cats,
        ];
    }

    /**
     * STRICT category matching helper.
     * Compares template category against names AND slugs (with singular/plural).
     */
    public function category_matches($tmpl_cat, $cat_names, $cat_slugs) {
        $tmpl_singular = rtrim($tmpl_cat, 's');
        foreach ($cat_names as $name) {
            if ($tmpl_cat === $name || $tmpl_singular === rtrim($name, 's')) return true;
        }
        foreach ($cat_slugs as $slug) {
            if ($tmpl_cat === $slug || $tmpl_singular === rtrim($slug, 's')) return true;
        }
        return false;
    }

    /**
     * Check if a WooCommerce category (or any of its ancestors) matches a template category.
     */
    public function category_or_parent_matches($tmpl_cat, $term) {
        $names = [strtolower(trim($term->name))];
        $slugs = [strtolower(trim($term->slug))];

        // Walk up parent chain
        $parent_id = $term->parent;
        while ($parent_id > 0) {
            $parent = get_term($parent_id, 'product_cat');
            if ($parent && !is_wp_error($parent)) {
                $names[] = strtolower(trim($parent->name));
                $slugs[] = strtolower(trim($parent->slug));
                $parent_id = $parent->parent;
            } else {
                break;
            }
        }

        return $this->category_matches($tmpl_cat, $names, $slugs);
    }

    /**
     * Auto-announce sales
     */
    public function on_sale_price_set($product, $sale_price) {
        if (get_option('rrh_ig_sale_announce_enabled') !== '1') return;
        if (empty($sale_price)) return;

        $product_id = $product->get_id();
        $image_url = RRH_Instagram_Poster::get_proxy_image_url($product->get_image_id());
        if (!$image_url) return;

        // Don't duplicate
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rrh_ig_posts 
             WHERE woo_product_id=%d AND media_source='sale' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $product_id
        ));
        if ($exists) return;

        $regular = strip_tags(wc_price($product->get_regular_price()));
        $sale = strip_tags(wc_price($sale_price));
        $name = $product->get_name();
        $shop_url = get_permalink(wc_get_page_id('shop'));

        $caption = "🔥 SALE ALERT! 🔥\n\n";
        $caption .= "{$name}\n\n";
        $caption .= "Was: {$regular}\n";
        $caption .= "Now: {$sale} 💰\n\n";
        $caption .= "Don't miss out! Shop at {$shop_url}";

        // Smart hashtags
        $all_tags = ['#sale #deals #stadiumcoasters #handmade #rockyriverhills #shopsmall'];
        $cat_hashtags = $this->plugin->get_category_hashtags_for_product($product_id);
        if ($cat_hashtags) $all_tags[] = $cat_hashtags;
        $prod_hashtags = get_post_meta($product_id, '_rrh_ig_hashtags', true);
        if ($prod_hashtags) $all_tags[] = $prod_hashtags;
        $combined = array_unique(array_filter(explode(' ', implode(' ', $all_tags)), function($t) {
            return strpos($t, '#') === 0;
        }));
        $caption .= "\n\n" . implode(' ', $combined);

        $this->plugin->create_post([
            'post_type' => 'image',
            'caption' => $caption,
            'media_url' => $image_url,
            'media_source' => 'sale',
            'woo_product_id' => $product_id,
            'status' => 'queued',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
    }
}
