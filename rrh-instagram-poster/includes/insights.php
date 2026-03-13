<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_Insights {

    private $api;

    public function __construct(RRH_IG_API $api) { $this->api = $api; }

    /**
     * Sync insights for all published posts
     */
    public function sync_all_published_posts() {
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_posts';

        $posts = $wpdb->get_results(
            "SELECT id, ig_media_id FROM {$table} WHERE status='published' AND ig_media_id IS NOT NULL ORDER BY published_at DESC LIMIT 50"
        );

        $synced = 0;
        foreach ($posts as $post) {
            if ($this->sync_post($post->id, $post->ig_media_id)) $synced++;
            usleep(200000); // 200ms delay between API calls
        }
        return $synced;
    }

    /**
     * Sync insights for a single post
     */
    public function sync_post($post_id, $ig_media_id) {
        // First get basic metrics from media endpoint
        $details = $this->api->get_media_details($ig_media_id);
        $likes = 0;
        $comments = 0;

        if ($details['success']) {
            $likes = $details['data']['like_count'] ?? 0;
            $comments = $details['data']['comments_count'] ?? 0;
        }

        // Try to get detailed insights
        $impressions = 0;
        $reach = 0;
        $saves = 0;
        $shares = 0;

        $insights = $this->api->get_media_insights($ig_media_id);
        if ($insights['success'] && isset($insights['data']['data'])) {
            foreach ($insights['data']['data'] as $metric) {
                $name = $metric['name'] ?? '';
                $value = $metric['values'][0]['value'] ?? ($metric['value'] ?? 0);
                switch ($name) {
                    case 'impressions': $impressions = $value; break;
                    case 'reach': $reach = $value; break;
                    case 'saved': $saves = $value; break;
                    case 'shares': $shares = $value; break;
                    case 'likes': $likes = max($likes, $value); break;
                    case 'comments': $comments = max($comments, $value); break;
                    case 'total_interactions':
                        // Fallback engagement metric
                        break;
                }
            }
        }

        $engagement = $likes + $comments + $saves + $shares;

        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_insights';

        // Upsert - update existing or insert new
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id=%d", $post_id
        ));

        $data = [
            'post_id' => $post_id,
            'ig_media_id' => $ig_media_id,
            'impressions' => $impressions,
            'reach' => $reach,
            'engagement' => $engagement,
            'likes' => $likes,
            'comments' => $comments,
            'saves' => $saves,
            'shares' => $shares,
            'synced_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        return true;
    }

    /**
     * Get insights summary stats
     */
    public static function get_summary() {
        global $wpdb;
        $t = $wpdb->prefix . 'rrh_ig_insights';

        return $wpdb->get_row(
            "SELECT COUNT(*) as total_posts,
                    COALESCE(SUM(impressions), 0) as total_impressions,
                    COALESCE(SUM(reach), 0) as total_reach,
                    COALESCE(SUM(engagement), 0) as total_engagement,
                    COALESCE(SUM(likes), 0) as total_likes,
                    COALESCE(SUM(comments), 0) as total_comments,
                    COALESCE(SUM(saves), 0) as total_saves,
                    COALESCE(SUM(shares), 0) as total_shares,
                    COALESCE(AVG(engagement), 0) as avg_engagement
             FROM {$t}"
        );
    }

    /**
     * Get top performing posts
     */
    public static function get_top_posts($limit = 10) {
        global $wpdb;
        $pt = $wpdb->prefix . 'rrh_ig_posts';
        $it = $wpdb->prefix . 'rrh_ig_insights';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, i.impressions, i.reach, i.engagement, i.likes, i.comments, i.saves, i.shares
             FROM {$pt} p JOIN {$it} i ON p.id = i.post_id
             ORDER BY i.engagement DESC LIMIT %d", $limit
        ));
    }

    /**
     * Get best posting times based on engagement data
     */
    public static function get_best_times() {
        global $wpdb;
        $pt = $wpdb->prefix . 'rrh_ig_posts';
        $it = $wpdb->prefix . 'rrh_ig_insights';

        return $wpdb->get_results(
            "SELECT HOUR(p.published_at) as hour, DAYOFWEEK(p.published_at) as dow,
                    AVG(i.engagement) as avg_engagement, COUNT(*) as post_count
             FROM {$pt} p JOIN {$it} i ON p.id = i.post_id
             WHERE p.published_at IS NOT NULL
             GROUP BY hour, dow
             HAVING post_count >= 1
             ORDER BY avg_engagement DESC"
        );
    }
}
