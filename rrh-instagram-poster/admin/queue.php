<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

global $wpdb;
$table = $wpdb->prefix . 'rrh_ig_posts';
$sf = sanitize_text_field($_GET['status'] ?? 'all');
$pg = max(1, intval($_GET['paged'] ?? 1));
$pp = 20;

$where = $sf !== 'all' ? $wpdb->prepare("WHERE status=%s", $sf) : '';
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
$posts = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$pp} OFFSET " . (($pg-1)*$pp));
$counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", OBJECT_K);
?>
<div class="wrap rrh-ig-wrap">
    <h1>📋 Post Queue <small style="font-size:12px;color:#999;">v<?php echo RRH_IG_VERSION; ?></small></h1>

    <ul class="subsubsub">
        <?php foreach (['all','queued','publishing','published','failed'] as $i => $s):
            $c = $s === 'all' ? $total : ($counts[$s]->count ?? 0);
        ?>
            <li><a href="<?php echo admin_url("admin.php?page=rrh-ig-queue&status={$s}"); ?>" class="<?php echo $sf === $s ? 'current' : ''; ?>">
                <?php echo ucfirst($s); ?> <span class="count">(<?php echo $c; ?>)</span>
            </a><?php echo $i < 4 ? ' |' : ''; ?></li>
        <?php endforeach; ?>
    </ul>
    <br class="clear">

    <?php if (empty($posts)): ?>
        <div class="rrh-ig-card"><p style="text-align:center; padding:40px; color:#666;">No posts. <a href="<?php echo admin_url('admin.php?page=rrh-instagram'); ?>">Compose →</a></p></div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:60px;">Media</th>
                <th>Caption</th>
                <th style="width:80px;">Type</th>
                <th style="width:100px;">Status</th>
                <th style="width:120px;">Scheduled</th>
                <th style="width:120px;">Published</th>
                <th style="width:160px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
            <tr data-post-id="<?php echo $p->id; ?>">
                <td>
                    <?php if ($p->post_type === 'reel'): ?><div class="rrh-ig-thumb-placeholder">🎬</div>
                    <?php elseif ($p->post_type === 'carousel'): ?><div class="rrh-ig-thumb-placeholder">🎠</div>
                    <?php else: ?><img src="<?php echo esc_url($p->media_url); ?>" class="rrh-ig-thumb" alt="" loading="lazy" onerror="this.outerHTML='<div class=rrh-ig-thumb-placeholder>📷</div>'"><?php endif; ?>
                </td>
                <td>
                    <div class="rrh-ig-caption-preview"><?php echo esc_html(wp_trim_words($p->caption, 20)); ?></div>
                    <?php if ($p->is_recycled): ?><span class="rrh-ig-badge">♻️ Recycled</span><?php endif; ?>
                    <?php if ($p->error_message): ?><div class="rrh-ig-error-msg">⚠️ <?php echo esc_html($p->error_message); ?></div><?php endif; ?>
                </td>
                <td><?php $icons = ['image'=>'📷','carousel'=>'🎠','reel'=>'🎬']; echo ($icons[$p->post_type] ?? '') . ' ' . ucfirst($p->post_type); ?></td>
                <td><span class="rrh-ig-status rrh-ig-status-<?php echo $p->status; ?>"><?php
                    $si = ['queued'=>'⏳','publishing'=>'🔄','published'=>'✅','failed'=>'❌'];
                    echo ($si[$p->status] ?? '') . ' ' . ucfirst($p->status);
                ?></span></td>
                <td><?php echo $p->scheduled_at ? esc_html(date('M j, g:i a', strtotime($p->scheduled_at))) : '—'; ?></td>
                <td><?php if ($p->published_at): echo esc_html(date('M j, g:i a', strtotime($p->published_at)));
                    if ($p->ig_permalink): ?><br><a href="<?php echo esc_url($p->ig_permalink); ?>" target="_blank">View →</a><?php endif;
                    else: echo '—'; endif; ?></td>
                <td>
                    <?php if (in_array($p->status, ['queued','failed'])): ?>
                        <button class="button button-small rrh-ig-edit-btn" data-id="<?php echo $p->id; ?>" title="Edit">✏️</button>
                        <button class="button button-small rrh-ig-publish-btn" data-id="<?php echo $p->id; ?>" title="Publish Now">🚀</button>
                    <?php endif; ?>
                    <?php if ($p->status === 'failed'): ?>
                        <button class="button button-small rrh-ig-retry-btn" data-id="<?php echo $p->id; ?>" title="Retry">🔄</button>
                    <?php endif; ?>
                    <?php if ($p->status === 'publishing'): ?>
                        <button class="button button-small rrh-ig-cancel-btn" data-id="<?php echo $p->id; ?>" title="Cancel &amp; Reset">⛔</button>
                    <?php endif; ?>
                        <button class="button button-small rrh-ig-delete-btn" data-id="<?php echo $p->id; ?>" title="Delete">🗑️</button>
                </td>
            </tr>
            <?php if (in_array($p->status, ['queued','failed'])): ?>
            <tr class="rrh-ig-edit-row" id="rrh-edit-<?php echo $p->id; ?>" style="display:none;">
                <td colspan="7" style="padding:16px; background:#f9f9f9;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:900px;">
                        <div>
                            <label><strong>Caption</strong></label>
                            <textarea class="large-text rrh-edit-caption" rows="6" style="margin-top:4px;"><?php echo esc_textarea($p->caption); ?></textarea>
                            <div style="margin-top:4px; font-size:12px; color:#666;">
                                <span class="rrh-edit-char-count"><?php echo strlen($p->caption); ?></span> / 2,200 chars
                            </div>
                        </div>
                        <div>
                            <label><strong>Schedule</strong></label>
                            <input type="datetime-local" class="regular-text rrh-edit-schedule" value="<?php echo $p->scheduled_at ? esc_attr(date('Y-m-d\TH:i', strtotime($p->scheduled_at))) : ''; ?>" style="margin-top:4px; display:block; width:100%;">
                            <p class="description">Leave blank to post on next cron run. Timezone: <?php echo esc_html(wp_timezone_string()); ?></p>

                            <?php if ($p->media_url): ?>
                            <label style="margin-top:12px; display:block;"><strong>Preview</strong></label>
                            <img src="<?php echo esc_url($p->media_url); ?>" style="max-width:200px; border-radius:4px; margin-top:4px;" alt="">
                            <?php if ($p->post_type === 'carousel' && $p->media_urls):
                                $murls = json_decode($p->media_urls, true);
                                if ($murls): ?>
                                <div style="font-size:12px; color:#2271b1; margin-top:4px;">🎠 <?php echo count($murls); ?> images in carousel</div>
                            <?php endif; endif; ?>
                            <?php endif; ?>

                            <div style="margin-top:16px;">
                                <button class="button button-primary rrh-ig-save-edit-btn" data-id="<?php echo $p->id; ?>">💾 Save Changes</button>
                                <button class="button rrh-ig-cancel-edit-btn" data-id="<?php echo $p->id; ?>">Cancel</button>
                                <span class="rrh-edit-result" style="margin-left:8px; color:#28a745; font-weight:600;"></span>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $tp = ceil($total / $pp);
    if ($tp > 1) { echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links(['base'=>add_query_arg('paged','%#%'),'current'=>$pg,'total'=>$tp]) . '</div></div>'; }
    endif; ?>
</div>
