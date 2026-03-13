<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_Publisher {

    private $api;

    public function __construct(RRH_IG_API $api) {
        $this->api = $api;
    }

    public function publish_post($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';
        $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $post_id));

        if (!$post) return ['success' => false, 'error' => 'Post not found'];

        @set_time_limit(300);

        try {
            if ($post->woo_product_id && class_exists('WooCommerce')) {
                $wc = wc_get_product($post->woo_product_id);
                if ($wc) {
                    $all_ids = [];
                    $featured_id = $wc->get_image_id();
                    if ($featured_id) $all_ids[] = $featured_id;

                    $gallery_ids = $wc->get_gallery_image_ids();
                    if (!empty($gallery_ids)) {
                        $all_ids = array_merge($all_ids, array_slice($gallery_ids, 0, 9));
                    }

                    // Upload to external image host — completely bypasses Hostinger CDN
                    $external_urls = $this->upload_images_to_imgbb($all_ids);
                    if (!empty($external_urls)) {
                        if (count($external_urls) > 1) {
                            $post->post_type = 'carousel';
                            $post->media_url = $external_urls[0];
                            $post->media_urls = json_encode($external_urls);
                        } else {
                            $post->post_type = 'image';
                            $post->media_url = $external_urls[0];
                            $post->media_urls = null;
                        }

                        $wpdb->update($table, [
                            'post_type' => $post->post_type,
                            'media_url' => $post->media_url,
                            'media_urls' => $post->media_urls,
                        ], ['id' => $post_id]);
                    }
                }
            }

            $wpdb->update($table, ['status' => 'publishing'], ['id' => $post_id]);

            if ($post->woo_product_id && strpos($post->caption, '{') !== false) {
                $post->caption = self::generate_product_caption($post->woo_product_id, $post->caption);
                $wpdb->update($table, ['caption' => $post->caption], ['id' => $post_id]);
            }

            // Build product tags for Instagram Shopping
            $product_tags = $this->build_product_tags($post);

            // Create container clean (no tags) via graph.instagram.com
            if ($post->post_type === 'carousel' && $post->media_urls) {
                $container_result = $this->create_carousel($post, null);
            } elseif ($post->post_type === 'reel') {
                $container_result = $this->api->create_reel_container($post->media_url, $post->caption, '', null);
            } else {
                $container_result = $this->api->create_image_container($post->media_url, $post->caption, null);
            }

            if (!$container_result['success']) throw new Exception($container_result['error']);
            $container_id = $container_result['data']['id'];

            if (!$this->wait_for_container($container_id, $post->post_type, false)) {
                throw new Exception('Media processing timed out');
            }

            $publish = $this->api->publish_container($container_id);
            if (!$publish['success']) throw new Exception($publish['error']);

            $media_id = $publish['data']['id'];

            // Apply product tags AFTER publishing — tries all token/endpoint combos
            if ($product_tags) {
                $tag_result = $this->api->add_product_tags($media_id, $product_tags);
                if ($tag_result['success']) {
                    error_log("[RRH IG] Product tags applied to media {$media_id}");
                } else {
                    error_log("[RRH IG] Product tag failed on media {$media_id}: " . ($tag_result['error'] ?? 'unknown'));
                }
            }

            $permalink = '';
            try {
                $details = $this->api->get_media_details($media_id);
                if ($details['success']) {
                    $permalink = $details['data']['permalink'] ?? '';
                }
            } catch (Exception $e) {}

            $wpdb->update($table, [
                'status' => 'published', 'published_at' => current_time('mysql'),
                'ig_media_id' => $media_id, 'ig_permalink' => $permalink, 'error_message' => null,
            ], ['id' => $post_id]);

            return ['success' => true, 'media_id' => $media_id, 'permalink' => $permalink];

        } catch (Exception $e) {
            error_log('[RRH IG] PUBLISH ERROR post ' . $post_id . ': ' . $e->getMessage());

            $wpdb->update($table, [
                'status' => $post->retry_count < 2 ? 'queued' : 'failed',
                'error_message' => $e->getMessage(),
                'retry_count' => $post->retry_count + 1,
            ], ['id' => $post_id]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload images to imgBB (free external image hosting).
     * This completely bypasses Hostinger's CDN — Instagram fetches
     * from imgBB's servers, not yours.
     */
    private function upload_images_to_imgbb($attachment_ids) {
        $api_key = get_option('rrh_ig_imgbb_api_key', '');
        if (empty($api_key)) {
            error_log('[RRH IG] No imgBB API key configured — falling back to local URLs');
            return $this->get_local_urls($attachment_ids);
        }

        $urls = [];
        $upload_dir = wp_upload_dir();

        foreach ($attachment_ids as $id) {
            $id = intval($id);
            if (!$id) continue;

            // Get the source file
            $source = null;
            $intermediate = image_get_intermediate_size($id, 'large');
            if ($intermediate && !empty($intermediate['path'])) {
                $candidate = $upload_dir['basedir'] . '/' . $intermediate['path'];
                if (file_exists($candidate)) $source = $candidate;
            }
            if (!$source) $source = get_attached_file($id);
            if (!$source || !file_exists($source)) continue;

            // Encode as base64
            $base64 = base64_encode(file_get_contents($source));

            // Upload to imgBB
            $response = wp_remote_post('https://api.imgbb.com/1/upload', [
                'timeout' => 30,
                'body' => [
                    'key' => $api_key,
                    'image' => $base64,
                    'name' => 'rrh-ig-' . $id . '-' . time(),
                ],
            ]);

            if (is_wp_error($response)) {
                error_log('[RRH IG] imgBB upload failed for ' . $id . ': ' . $response->get_error_message());
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['data']['url'])) {
                // Instagram blocks i.ibb.co domain directly.
                // Proxy through WordPress.com Photon CDN (i0.wp.com) which Instagram trusts.
                $raw_url = $body['data']['url'];
                $proxied = preg_replace('#^https?://#', 'https://i0.wp.com/', $raw_url);
                $urls[] = $proxied;
                error_log('[RRH IG] Image ' . $id . ' → imgBB → proxy: ' . $proxied);
            } else {
                $err = $body['error']['message'] ?? 'Unknown error';
                error_log('[RRH IG] imgBB error for ' . $id . ': ' . $err);
            }

            // Small delay between uploads
            sleep(1);
        }

        return $urls;
    }

    /**
     * Fallback: use local WordPress URLs if no imgBB key configured.
     */
    private function get_local_urls($attachment_ids) {
        $urls = [];
        foreach ($attachment_ids as $id) {
            $url = wp_get_attachment_image_url(intval($id), 'large');
            if (!$url) $url = wp_get_attachment_url(intval($id));
            if ($url) $urls[] = $url;
        }
        return $urls;
    }

    private function create_carousel($post, $product_tags = null) {
        $urls = json_decode($post->media_urls, true);
        if (!$urls || count($urls) < 2) {
            return $this->api->create_image_container($post->media_url, $post->caption);
        }

        error_log('[RRH IG] === Carousel: ' . count($urls) . ' images ===');

        $children = [];
        $failed = [];

        foreach ($urls as $i => $url) {
            $num = $i + 1;
            $success = false;

            // With external hosting, should work first try. 3 retries just in case.
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                error_log("[RRH IG] Img {$num} attempt {$attempt}: " . substr($url, 0, 80));
                $item = $this->api->create_carousel_item($url);

                if ($item['success']) {
                    $child_id = $item['data']['id'];
                    $ready = $this->wait_for_item($child_id);
                    if ($ready === true || $ready === 'timeout') {
                        error_log("[RRH IG] Img {$num} READY");
                        $children[] = $child_id;
                        $success = true;
                        break;
                    }
                    error_log("[RRH IG] Img {$num} processing error");
                } else {
                    error_log("[RRH IG] Img {$num}: " . ($item['error'] ?? 'unknown'));
                }
                sleep(5);
            }

            if (!$success) $failed[] = "Image {$num}";
            if ($success) sleep(2);
        }

        error_log('[RRH IG] === Result: ' . count($children) . '/' . count($urls) . ' ===');

        if (empty($children)) {
            throw new Exception('All images failed: ' . implode('; ', $failed));
        }
        if (count($children) === 1) {
            return $this->api->create_image_container($post->media_url, $post->caption, $product_tags);
        }
        return $this->api->create_carousel_container($children, $post->caption, $product_tags);
    }

    private function wait_for_item($container_id) {
        for ($i = 0; $i < 6; $i++) {
            sleep(5);
            $status = $this->api->check_container_status($container_id);
            if (!$status['success']) continue;
            $code = $status['data']['status_code'] ?? '';
            if ($code === 'FINISHED') return true;
            if ($code === 'ERROR') return 'error';
        }
        return 'timeout';
    }

    private function wait_for_container($container_id, $post_type, $use_fb = false) {
        if ($post_type === 'image') { sleep(3); return true; }
        for ($i = 0; $i < 30; $i++) {
            sleep(10);
            $status = $use_fb
                ? $this->api->check_container_status_fb($container_id)
                : $this->api->check_container_status($container_id);
            if (!$status['success']) continue;
            $code = $status['data']['status_code'] ?? '';
            if ($code === 'FINISHED') return true;
            if ($code === 'ERROR') return false;
        }
        return false;
    }

    /**
     * Build product tags JSON for Instagram Shopping.
     * Uses WooCommerce product ID as the retailer_id (matches Meta Shopping Feed g:id).
     * Returns JSON string or null if tagging is disabled/unavailable.
     */
    private function build_product_tags($post) {
        // Check if product tagging is enabled
        if (!get_option('rrh_ig_product_tagging_enabled')) return null;

        // Need a WooCommerce product ID
        if (empty($post->woo_product_id)) return null;

        // Need a catalog ID
        $catalog_id = get_option('rrh_ig_meta_catalog_id', '');
        if (empty($catalog_id)) {
            error_log('[RRH IG] Product tagging enabled but no Meta Catalog ID configured');
            return null;
        }

        $wc_id = intval($post->woo_product_id);

        // Look up Meta's internal product ID from the catalog
        // The catalog uses our WooCommerce ID as the retailer_id (from our Google Shopping feed g:id)
        $meta_product_id = $this->get_meta_catalog_product_id($catalog_id, $wc_id);

        if (!$meta_product_id) {
            error_log("[RRH IG] Could not find product {$wc_id} in Meta catalog {$catalog_id} — skipping tags");
            return null;
        }

        $tags = [
            [
                'product_id' => $meta_product_id,
            ]
        ];

        $json = json_encode($tags);
        error_log('[RRH IG] Product tags for post ' . $post->id . ': ' . $json);
        return $json;
    }

    /**
     * Look up a product's Meta catalog ID by its retailer_id (WooCommerce product ID).
     * Queries the Facebook Graph API catalog endpoint.
     * Caches results in a transient to avoid repeated API calls.
     */
    private function get_meta_catalog_product_id($catalog_id, $wc_product_id) {
        // Check cache first (24 hour TTL)
        $cache_key = 'rrh_ig_meta_pid_' . $wc_product_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        // Use the Facebook Page Token (separate from Instagram token)
        $access_token = get_option('rrh_ig_fb_page_token', '');
        if (empty($access_token)) {
            error_log('[RRH IG] Product tagging: no Facebook Page Token configured');
            return null;
        }

        // Query Meta catalog for product by retailer_id
        // The retailer_id in our feed is the WooCommerce product ID
        $url = "https://graph.facebook.com/v21.0/{$catalog_id}/products";
        $response = wp_remote_get(add_query_arg([
            'filter' => json_encode(['retailer_id' => ['eq' => (string) $wc_product_id]]),
            'fields' => 'id,retailer_id,name',
            'access_token' => $access_token,
        ], $url), ['timeout' => 15]);

        if (is_wp_error($response)) {
            error_log('[RRH IG] Meta catalog lookup failed: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            $err = $body['error']['message'] ?? "HTTP {$code}";
            error_log("[RRH IG] Meta catalog API error: {$err}");
            return null;
        }

        if (!empty($body['data'][0]['id'])) {
            $meta_id = $body['data'][0]['id'];
            error_log("[RRH IG] Catalog lookup: WC #{$wc_product_id} → Meta {$meta_id} ({$body['data'][0]['name']})");
            // Cache for 24 hours
            set_transient($cache_key, $meta_id, DAY_IN_SECONDS);
            return $meta_id;
        }

        error_log("[RRH IG] Product WC #{$wc_product_id} not found in Meta catalog {$catalog_id}");
        return null;
    }

    public static function generate_product_caption($product_id, $template = '') {
        $product = wc_get_product($product_id);
        if (!$product) return '';
        $vars = [
            '{product_name}' => $product->get_name(),
            '{price}' => strip_tags(wc_price($product->get_price())),
            '{sale_price}' => $product->get_sale_price() ? strip_tags(wc_price($product->get_sale_price())) : '',
            '{regular_price}' => strip_tags(wc_price($product->get_regular_price())),
            '{description}' => wp_trim_words($product->get_short_description(), 30),
            '{url}' => get_permalink($product_id),
            '{shop_url}' => get_permalink(wc_get_page_id('shop')),
            '{categories}' => implode(', ', wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names'])),
        ];
        if (empty($template)) {
            $template = "🏟️ {product_name}\n\n{description}\n\n💰 {price}\n\n🛒 Shop now at {shop_url}\n\n#stadiumcoasters #gameday #sportsdecor #rockyriverhills";
        }
        return str_replace(array_keys($vars), array_values($vars), $template);
    }
}
