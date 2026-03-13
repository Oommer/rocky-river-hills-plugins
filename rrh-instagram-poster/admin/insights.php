<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

$summary = RRH_IG_Insights::get_summary();
$top_posts = RRH_IG_Insights::get_top_posts(10);
$best_times = RRH_IG_Insights::get_best_times();
$days = ['','Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<div class="wrap rrh-ig-wrap">
    <h1>📊 Instagram Insights</h1>

    <div style="margin-bottom:16px;">
        <button type="button" id="rrh-ig-sync-insights" class="button button-primary">🔄 Sync Insights Now</button>
        <span id="rrh-ig-sync-result" style="margin-left:12px;"></span>
    </div>

    <!-- Summary Cards -->
    <div class="rrh-ig-stats-grid">
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_posts ?? 0); ?></div>
            <div class="stat-label">Posts Tracked</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_impressions ?? 0); ?></div>
            <div class="stat-label">Impressions</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_reach ?? 0); ?></div>
            <div class="stat-label">Reach</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_engagement ?? 0); ?></div>
            <div class="stat-label">Total Engagement</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_likes ?? 0); ?></div>
            <div class="stat-label">❤️ Likes</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_comments ?? 0); ?></div>
            <div class="stat-label">💬 Comments</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo number_format($summary->total_saves ?? 0); ?></div>
            <div class="stat-label">🔖 Saves</div>
        </div>
        <div class="rrh-ig-stat-card">
            <div class="stat-number"><?php echo round($summary->avg_engagement ?? 0, 1); ?></div>
            <div class="stat-label">Avg. Engagement</div>
        </div>
    </div>

    <div class="rrh-ig-insights-grid">
        <!-- Top Posts -->
        <div class="rrh-ig-card">
            <h2>🏆 Top Performing Posts</h2>
            <?php if (empty($top_posts)): ?>
                <p style="color:#666;">No insights data yet. Sync insights after publishing some posts.</p>
            <?php else: ?>
            <table class="widefat">
                <thead><tr><th>Post</th><th>❤️</th><th>💬</th><th>🔖</th><th>📊 Total</th><th>👁️ Reach</th></tr></thead>
                <tbody>
                <?php foreach ($top_posts as $p): ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <img src="<?php echo esc_url($p->media_url); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" alt="" loading="lazy">
                            <span><?php echo esc_html(wp_trim_words($p->caption, 8)); ?></span>
                        </div>
                    </td>
                    <td><?php echo number_format($p->likes); ?></td>
                    <td><?php echo number_format($p->comments); ?></td>
                    <td><?php echo number_format($p->saves); ?></td>
                    <td><strong><?php echo number_format($p->engagement); ?></strong></td>
                    <td><?php echo number_format($p->reach); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Best Times -->
        <div class="rrh-ig-card">
            <h2>⏰ Best Posting Times</h2>
            <p class="description">Based on your average engagement per post by time and day.</p>
            <?php if (empty($best_times)): ?>
                <p style="color:#666;">Need more published posts with insights data.</p>
            <?php else: ?>
            <table class="widefat">
                <thead><tr><th>Day</th><th>Hour</th><th>Avg. Engagement</th><th>Posts</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($best_times, 0, 10) as $bt): ?>
                <tr>
                    <td><?php echo $days[$bt->dow] ?? ''; ?></td>
                    <td><?php echo date('g:i A', strtotime("{$bt->hour}:00")); ?></td>
                    <td><strong><?php echo round($bt->avg_engagement, 1); ?></strong></td>
                    <td><?php echo $bt->post_count; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
