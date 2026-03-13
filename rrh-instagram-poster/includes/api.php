<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_API {

    private $base_url = 'https://graph.instagram.com';
    private $fb_base_url = 'https://graph.facebook.com/v21.0';

    private function get_credentials() {
        return [
            'app_id'       => get_option('rrh_ig_app_id', defined('RRH_META_APP_ID') ? RRH_META_APP_ID : ''),
            'app_secret'   => get_option('rrh_ig_app_secret', defined('RRH_META_APP_SECRET') ? RRH_META_APP_SECRET : ''),
            'access_token' => get_option('rrh_ig_access_token', defined('RRH_META_SYSTEM_TOKEN') ? RRH_META_SYSTEM_TOKEN : ''),
            'user_id'      => get_option('rrh_ig_user_id', defined('RRH_INSTAGRAM_USER_ID') ? RRH_INSTAGRAM_USER_ID : ''),
            'fb_token'     => get_option('rrh_ig_fb_page_token', defined('RRH_FACEBOOK_PAGE_TOKEN') ? RRH_FACEBOOK_PAGE_TOKEN : ''),
        ];
    }

    private function request($endpoint, $method = 'GET', $params = []) {
        $creds = $this->get_credentials();
        if (empty($creds['access_token'])) return ['success' => false, 'error' => 'No access token'];

        $url = "{$this->base_url}/{$endpoint}";
        $params['access_token'] = $creds['access_token'];
        $args = ['timeout' => 60];

        if ($method === 'GET') {
            $response = wp_remote_get(add_query_arg($params, $url), $args);
        } else {
            $args['body'] = $params;
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return ['success' => false, 'error' => $body['error']['message'] ?? "HTTP {$code}"];
        }
        return ['success' => true, 'data' => $body];
    }

    /**
     * Make a request to graph.facebook.com using the Facebook User Token.
     * Used for Marketing API calls (product tagging with ads_management).
     */
    private function fb_request($endpoint, $method = 'GET', $params = []) {
        $creds = $this->get_credentials();
        $token = $creds['fb_token'];
        if (empty($token)) return ['success' => false, 'error' => 'No Facebook token configured'];

        $url = "{$this->fb_base_url}/{$endpoint}";
        $params['access_token'] = $token;
        $args = ['timeout' => 60];

        if ($method === 'GET') {
            $response = wp_remote_get(add_query_arg($params, $url), $args);
        } else {
            $args['body'] = $params;
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        error_log("[RRH IG] FB API ({$method} {$endpoint}) ({$code}): {$body_raw}");

        if ($code >= 400) {
            return ['success' => false, 'error' => $body['error']['message'] ?? "HTTP {$code}"];
        }
        return ['success' => true, 'data' => $body];
    }

    public function get_account_info() {
        return $this->request("me", 'GET', [
            'fields' => 'id,username,name,profile_picture_url,followers_count,media_count,account_type',
        ]);
    }

    public function exchange_for_long_lived_token() {
        $creds = $this->get_credentials();
        if (empty($creds['access_token']) || empty($creds['app_secret'])) {
            return ['success' => false, 'error' => 'Token and secret required'];
        }

        $response = wp_remote_get(add_query_arg([
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $creds['app_secret'],
            'access_token' => $creds['access_token'],
        ], "{$this->base_url}/access_token"), ['timeout' => 30]);

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            update_option('rrh_ig_access_token', $body['access_token']);
            $expires = date('Y-m-d H:i:s', time() + ($body['expires_in'] ?? 5184000));
            update_option('rrh_ig_token_expires', $expires);
            return ['success' => true, 'expires' => $expires];
        }
        return ['success' => false, 'error' => $body['error']['message'] ?? 'Exchange failed'];
    }

    public function refresh_token() {
        $creds = $this->get_credentials();
        if (empty($creds['access_token'])) return ['success' => false, 'error' => 'No token'];

        $response = wp_remote_get(add_query_arg([
            'grant_type' => 'ig_refresh_token',
            'access_token' => $creds['access_token'],
        ], "{$this->base_url}/refresh_access_token"), ['timeout' => 30]);

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            update_option('rrh_ig_access_token', $body['access_token']);
            $expires = date('Y-m-d H:i:s', time() + ($body['expires_in'] ?? 5184000));
            update_option('rrh_ig_token_expires', $expires);
            return ['success' => true, 'expires' => $expires];
        }
        return ['success' => false, 'error' => $body['error']['message'] ?? 'Refresh failed'];
    }

    /**
     * Refresh the Facebook User Token before it expires.
     * Uses the fb_exchange_token grant type to get a new long-lived token.
     * Should be called periodically (e.g., weekly via cron).
     */
    public function refresh_fb_token() {
        $creds = $this->get_credentials();
        $fb_token = $creds['fb_token'];
        if (empty($fb_token) || empty($creds['app_id']) || empty($creds['app_secret'])) {
            return ['success' => false, 'error' => 'FB token, app ID, and app secret required'];
        }

        $response = wp_remote_get(add_query_arg([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $creds['app_id'],
            'client_secret' => $creds['app_secret'],
            'fb_exchange_token' => $fb_token,
        ], "{$this->fb_base_url}/oauth/access_token"), ['timeout' => 30]);

        if (is_wp_error($response)) return ['success' => false, 'error' => $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            error_log('[RRH IG] FB token refresh failed: ' . ($body['error']['message'] ?? "HTTP {$code}"));
            return ['success' => false, 'error' => $body['error']['message'] ?? "HTTP {$code}"];
        }

        if (isset($body['access_token'])) {
            update_option('rrh_ig_fb_page_token', $body['access_token']);
            $expires_in = $body['expires_in'] ?? 5184000; // default 60 days
            $expires = date('Y-m-d H:i:s', time() + $expires_in);
            update_option('rrh_ig_fb_token_expires', $expires);
            error_log("[RRH IG] FB token refreshed, new expiry: {$expires}");
            return ['success' => true, 'expires' => $expires];
        }
        return ['success' => false, 'error' => 'No access_token in response'];
    }
    public function create_image_container($image_url, $caption, $product_tags = null) {
        $creds = $this->get_credentials();
        $params = ['image_url' => $image_url, 'caption' => $caption];
        if ($product_tags) {
            $params['product_tags'] = $product_tags;
            return $this->fb_request("{$creds['user_id']}/media", 'POST', $params);
        }
        return $this->request("{$creds['user_id']}/media", 'POST', $params);
    }

    public function create_carousel_item($image_url) {
        $creds = $this->get_credentials();
        return $this->request("{$creds['user_id']}/media", 'POST', ['image_url' => $image_url, 'is_carousel_item' => 'true']);
    }

    public function create_carousel_container($children_ids, $caption, $product_tags = null) {
        $creds = $this->get_credentials();
        $params = [
            'media_type' => 'CAROUSEL', 'children' => implode(',', $children_ids), 'caption' => $caption,
        ];
        if ($product_tags) {
            $params['product_tags'] = $product_tags;
            return $this->fb_request("{$creds['user_id']}/media", 'POST', $params);
        }
        return $this->request("{$creds['user_id']}/media", 'POST', $params);
    }

    public function create_reel_container($video_url, $caption, $cover_url = '', $product_tags = null) {
        $creds = $this->get_credentials();
        $params = ['video_url' => $video_url, 'caption' => $caption, 'media_type' => 'REELS', 'share_to_feed' => 'true'];
        if ($cover_url) $params['cover_url'] = $cover_url;
        if ($product_tags) {
            $params['product_tags'] = $product_tags;
            return $this->fb_request("{$creds['user_id']}/media", 'POST', $params);
        }
        return $this->request("{$creds['user_id']}/media", 'POST', $params);
    }

    public function check_container_status($container_id) {
        return $this->request($container_id, 'GET', ['fields' => 'status_code,status']);
    }

    public function check_container_status_fb($container_id) {
        return $this->fb_request($container_id, 'GET', ['fields' => 'status_code,status']);
    }

    public function publish_container($container_id) {
        $creds = $this->get_credentials();
        return $this->request("{$creds['user_id']}/media_publish", 'POST', ['creation_id' => $container_id]);
    }

    public function publish_container_fb($container_id) {
        $creds = $this->get_credentials();
        return $this->fb_request("{$creds['user_id']}/media_publish", 'POST', ['creation_id' => $container_id]);
    }

    public function get_media_details($media_id) {
        return $this->request($media_id, 'GET', [
            'fields' => 'id,media_type,media_url,permalink,timestamp,caption,like_count,comments_count',
        ]);
    }

    // Insights
    public function get_media_insights($media_id) {
        return $this->request("{$media_id}/insights", 'GET', [
            'metric' => 'impressions,reach,saved,shares,likes,comments,total_interactions',
        ]);
    }

    public function get_publishing_limit() {
        $creds = $this->get_credentials();
        return $this->request("{$creds['user_id']}/content_publishing_limit", 'GET', ['fields' => 'config,quota_usage']);
    }

    /**
     * Add product tags to an already-published media post.
     * Uses the IG Media Product Tags endpoint: POST /{ig-media-id}/product_tags
     * This is separate from the container creation endpoint and is the supported
     * way to add shopping tags via the Graph API.
     */
    public function add_product_tags($media_id, $product_tags_json) {
        $fb_token = get_option('rrh_ig_fb_page_token', '');
        if (empty($fb_token)) {
            return ['success' => false, 'error' => 'No Facebook token configured'];
        }

        $page_id = get_option('rrh_ig_fb_page_id', '110995055209248');

        // Step 1: Get IG Business Account ID via Page
        $ig_id = $this->get_ig_business_account_id($page_id, $fb_token);
        if (!$ig_id) {
            return ['success' => false, 'error' => 'Could not find Instagram Business Account via Page'];
        }

        // Step 2: Get the Graph API media ID (most recent post)
        $lookup_url = "{$this->fb_base_url}/{$ig_id}/media?limit=1&fields=id,media_type&access_token={$fb_token}";
        $lookup = wp_remote_get($lookup_url, ['timeout' => 30]);
        if (is_wp_error($lookup)) {
            return ['success' => false, 'error' => 'Media lookup failed: ' . $lookup->get_error_message()];
        }
        $lookup_body = json_decode(wp_remote_retrieve_body($lookup), true);
        error_log('[RRH IG] Graph API media lookup: ' . wp_remote_retrieve_body($lookup));

        if (empty($lookup_body['data'][0]['id'])) {
            return ['success' => false, 'error' => 'No media found via Graph API'];
        }

        $graph_media_id = $lookup_body['data'][0]['id'];
        $media_type = $lookup_body['data'][0]['media_type'] ?? '';
        error_log("[RRH IG] Graph API media: {$graph_media_id} (type: {$media_type}, publishing ID was: {$media_id})");

        // Step 3: If carousel, get children and tag each one
        if (strtoupper($media_type) === 'CAROUSEL_ALBUM') {
            $children_url = "{$this->fb_base_url}/{$graph_media_id}/children?fields=id,media_type&access_token={$fb_token}";
            $children_resp = wp_remote_get($children_url, ['timeout' => 30]);
            if (is_wp_error($children_resp)) {
                return ['success' => false, 'error' => 'Children lookup failed'];
            }
            $children_body = json_decode(wp_remote_retrieve_body($children_resp), true);
            error_log('[RRH IG] Carousel children: ' . wp_remote_retrieve_body($children_resp));

            if (empty($children_body['data'])) {
                return ['success' => false, 'error' => 'No carousel children found'];
            }

            $any_success = false;
            foreach ($children_body['data'] as $child) {
                $child_id = $child['id'];
                $result = $this->tag_single_media($child_id, $product_tags_json, $fb_token);
                if ($result) $any_success = true;
            }

            return $any_success
                ? ['success' => true, 'data' => ['tagged' => 'carousel_children']]
                : ['success' => false, 'error' => 'Failed to tag any carousel children'];
        }

        // Step 4: Single image/reel — tag directly
        $result = $this->tag_single_media($graph_media_id, $product_tags_json, $fb_token);
        return $result
            ? ['success' => true, 'data' => ['tagged' => $graph_media_id]]
            : ['success' => false, 'error' => 'Failed to tag media ' . $graph_media_id];
    }

    private function get_ig_business_account_id($page_id, $token) {
        $cached = get_transient('rrh_ig_business_account_id');
        if ($cached) return $cached;

        $url = "{$this->fb_base_url}/{$page_id}?fields=instagram_business_account&access_token={$token}";
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) return null;

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['instagram_business_account']['id'])) {
            $ig_id = $body['instagram_business_account']['id'];
            set_transient('rrh_ig_business_account_id', $ig_id, DAY_IN_SECONDS);
            return $ig_id;
        }
        error_log('[RRH IG] Could not find instagram_business_account for page ' . $page_id);
        return null;
    }

    private function tag_single_media($media_id, $product_tags_json, $token) {
        // Add x,y positions if not present (required for photo media)
        $tags = json_decode($product_tags_json, true);
        if (is_array($tags)) {
            foreach ($tags as &$tag) {
                if (!isset($tag['x'])) $tag['x'] = 0.9;
                if (!isset($tag['y'])) $tag['y'] = 0.9;
            }
            $product_tags_json = json_encode($tags);
        }

        $url = "{$this->fb_base_url}/{$media_id}/product_tags";
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'body' => [
                'updated_tags' => $product_tags_json,
                'access_token' => $token,
            ],
        ]);
        if (is_wp_error($response)) {
            error_log("[RRH IG] Tag failed for {$media_id}: " . $response->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        error_log("[RRH IG] Tag media {$media_id} ({$code}): {$body_raw}");
        return $code < 400;
    }

}
