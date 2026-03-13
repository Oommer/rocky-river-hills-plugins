<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

$token_expires = get_option('rrh_ig_token_expires', '');
$has_token = !empty(get_option('rrh_ig_access_token', ''));
$days_left = $token_expires ? max(0, round((strtotime($token_expires) - time()) / DAY_IN_SECONDS)) : '';
$has_woo = class_exists('WooCommerce');
$cat_hashtags = json_decode(get_option('rrh_ig_category_hashtags', '{}'), true);
$templates = RRH_IG_Templates::get_all();
$template_vars = RRH_IG_Templates::get_variables();
?>
<div class="wrap rrh-ig-wrap">
    <h1>⚙️ Instagram Poster Settings <small style="font-size:12px;color:#999;">v<?php echo RRH_IG_VERSION; ?></small></h1>

    <div class="rrh-ig-settings-tabs">
        <a href="#tab-api" class="rrh-ig-tab active">🔑 API</a>
        <a href="#tab-automation" class="rrh-ig-tab">🤖 Automation</a>
        <a href="#tab-templates" class="rrh-ig-tab">📝 Templates</a>
        <a href="#tab-hashtags" class="rrh-ig-tab">🏷️ Hashtags</a>
        <a href="#tab-linkinbio" class="rrh-ig-tab">🔗 Link in Bio</a>
    </div>

    <!-- API Tab -->
    <div id="tab-api" class="rrh-ig-tab-content active">
        <div class="rrh-ig-settings-grid">
            <div class="rrh-ig-card">
                <h2>API Credentials</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('rrh_ig_api'); ?>
                    <table class="form-table">
                        <tr><th>Instagram App ID</th><td><input type="text" name="rrh_ig_app_id" value="<?php echo esc_attr(get_option('rrh_ig_app_id')); ?>" class="regular-text"></td></tr>
                        <tr><th>App Secret</th><td><input type="password" name="rrh_ig_app_secret" value="<?php echo esc_attr(get_option('rrh_ig_app_secret')); ?>" class="regular-text"></td></tr>
                        <tr><th>Access Token</th><td><input type="password" name="rrh_ig_access_token" value="<?php echo esc_attr(get_option('rrh_ig_access_token')); ?>" class="large-text"><p class="description">Paste token, then use Exchange button.</p></td></tr>
                        <tr><th>User ID</th><td><input type="text" name="rrh_ig_user_id" value="<?php echo esc_attr(get_option('rrh_ig_user_id')); ?>" class="regular-text"></td></tr>
                        <tr><th>imgBB API Key</th><td><input type="text" name="rrh_ig_imgbb_api_key" value="<?php echo esc_attr(get_option('rrh_ig_imgbb_api_key')); ?>" class="regular-text"><p class="description">Free at <a href="https://api.imgbb.com/" target="_blank">api.imgbb.com</a> — required for carousel posts. Images are uploaded to imgBB before posting to Instagram.</p></td></tr>
                        <tr><th>Meta Catalog ID</th><td><input type="text" name="rrh_ig_meta_catalog_id" value="<?php echo esc_attr(get_option('rrh_ig_meta_catalog_id')); ?>" class="regular-text"><p class="description">From Commerce Manager → Catalog → Settings. Required for product tagging in posts. Product IDs in your catalog must match WooCommerce product IDs (the RT Meta Shopping Feed plugin does this automatically).</p></td></tr>
                        <tr><th>Product Tagging</th><td><label><input type="checkbox" name="rrh_ig_product_tagging_enabled" value="1" <?php checked(get_option('rrh_ig_product_tagging_enabled'), '1'); ?>> Enable automatic product tagging in Instagram posts</label><p class="description">When enabled, WooCommerce product posts are automatically tagged with the matching product from your Meta catalog, making posts shoppable. Requires a connected catalog in Commerce Manager and Instagram Shopping approval.</p></td></tr>
                        <tr><th>Facebook Page Token</th><td><input type="password" name="rrh_ig_fb_page_token" value="<?php echo esc_attr(get_option('rrh_ig_fb_page_token')); ?>" class="large-text"><p class="description">Required for product tagging. Generate at <a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer</a> → select your Page → add <code>catalog_management</code> permission → generate → extend to permanent. This is separate from the Instagram token above.</p></td></tr>
                    </table>
                    <?php submit_button('Save Credentials'); ?>
                </form>
            </div>

            <div class="rrh-ig-card">
                <h2>Token Management</h2>
                <?php if ($has_token && $token_expires): ?>
                    <div class="rrh-ig-token-status <?php echo $days_left < 7 ? 'warning' : 'good'; ?>">
                        <strong>Token:</strong> <?php echo $days_left > 0 ? "{$days_left} days left (expires " . date('M j, Y', strtotime($token_expires)) . ")" : '<span class="expired">EXPIRED</span>'; ?>
                    </div>
                <?php elseif ($has_token): ?>
                    <div class="rrh-ig-token-status warning"><strong>Token:</strong> Short-lived — exchange below</div>
                <?php else: ?>
                    <div class="rrh-ig-token-status error"><strong>Token:</strong> Not configured</div>
                <?php endif; ?>
                <div class="rrh-ig-token-actions">
                    <button id="rrh-ig-exchange-token" class="button button-primary" <?php echo !$has_token ? 'disabled' : ''; ?>>🔄 Exchange Token</button>
                    <button id="rrh-ig-refresh-token" class="button" <?php echo !$has_token ? 'disabled' : ''; ?>>♻️ Refresh Token</button>
                    <button id="rrh-ig-test-connection" class="button" <?php echo !$has_token ? 'disabled' : ''; ?>>🔌 Test Connection</button>
                </div>
                <div id="rrh-ig-connection-result" class="rrh-ig-result" style="display:none;"></div>
            </div>

            <div class="rrh-ig-card" style="grid-column:1/-1;">
                <h2>Cron Status</h2>
                <table class="widefat">
                    <?php foreach ([
                        ['Queue Processor', 'rrh_ig_process_queue', 'Every 5 min'],
                        ['Token Refresh', 'rrh_ig_refresh_token_cron', 'Daily'],
                        ['Insights Sync', 'rrh_ig_sync_insights_cron', 'Twice daily'],
                        ['Content Recycler', 'rrh_ig_recycle_content_cron', 'Daily'],
                        ['Autopilot', 'rrh_ig_autopilot_cron', 'Hourly'],
                    ] as [$label, $hook, $freq]):
                        $next = wp_next_scheduled($hook);
                    ?>
                    <tr><td><strong><?php echo $label; ?></strong></td><td><?php echo $freq; ?></td>
                    <td><?php echo $next ? 'Next: ' . date('M j, g:i a', $next) : '<span style="color:red;">Not scheduled</span>'; ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Automation Tab -->
    <div id="tab-automation" class="rrh-ig-tab-content" style="display:none;">
        <form method="post" action="options.php">
            <?php settings_fields('rrh_ig_automation'); ?>

            <div class="rrh-ig-card" style="border-left:4px solid #A2755A;">
                <h2>✈️ Autopilot — Daily Product Rotation</h2>
                <p class="description">Automatically pick a product from your catalog every day and post it to Instagram. Cycles through all products, then starts over — just like the Pinterest auto-poster.</p>

                <!-- Live Status Dashboard -->
                <div id="rrh-ig-autopilot-dashboard" style="margin:16px 0; padding:16px; background:#faf5f2; border-radius:8px; display:none;">
                    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:12px;">
                        <div style="text-align:center;"><div style="font-size:24px; font-weight:700; color:#A2755A;" id="ap-stat-total">—</div><div style="font-size:11px; color:#666;">Total Products</div></div>
                        <div style="text-align:center;"><div style="font-size:24px; font-weight:700; color:#333;" id="ap-stat-posted">—</div><div style="font-size:11px; color:#666;">Posted</div></div>
                        <div style="text-align:center;"><div style="font-size:24px; font-weight:700; color:#2271b1;" id="ap-stat-queued">—</div><div style="font-size:11px; color:#666;">Queued</div></div>
                        <div style="text-align:center;"><div style="font-size:24px; font-weight:700; color:#666;" id="ap-stat-rotation">—</div><div style="font-size:11px; color:#666;">Days/Rotation</div></div>
                    </div>
                    <div style="font-size:13px; color:#555; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                        <span>📦 <strong>Next up:</strong> <span id="ap-next-product">—</span> <span id="ap-next-cats" style="color:#888; font-size:11px;"></span></span>
                        <span>🕐 <strong>Last run:</strong> <span id="ap-last-run">—</span></span>
                    </div>
                    <div style="font-size:13px; color:#555; margin-top:6px;">
                        <span>📝 <strong>Last template used:</strong> <span id="ap-last-template" style="color:#A2755A;">—</span></span>
                    </div>

                    <!-- Template Coverage Check -->
                    <div id="ap-template-coverage" style="margin-top:12px; font-size:12px; display:none;"></div>
                    <div style="margin-top:12px;">
                        <button type="button" id="rrh-ig-autopilot-run-now" class="button button-primary" style="background:#A2755A; border-color:#8b6249;">▶️ Run Now</button>
                        <button type="button" id="rrh-ig-autopilot-refresh" class="button">🔄 Refresh Status</button>
                        <button type="button" id="rrh-ig-autopilot-debug" class="button">🔍 Debug Matching</button>
                        <span id="rrh-ig-autopilot-msg" style="margin-left:10px; font-size:13px; color:#00a32a; display:none;"></span>
                    </div>
                    <div id="rrh-ig-autopilot-debug-output" style="display:none; margin-top:12px; padding:12px; background:#fff; border:1px solid #c3c4c7; border-radius:6px; font-size:12px; max-height:400px; overflow-y:auto;"></div>
                    <div id="ap-template-coverage"></div>
                </div>

                <table class="form-table">
                    <tr><th>Enable Autopilot</th><td><label><input type="checkbox" name="rrh_ig_autopilot_enabled" value="1" <?php checked(get_option('rrh_ig_autopilot_enabled'), '1'); ?>> Post products automatically every day</label></td></tr>
                    <tr><th>Posts Per Day</th><td>
                        <select name="rrh_ig_autopilot_posts_per_day">
                            <?php for ($i = 1; $i <= 3; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(get_option('rrh_ig_autopilot_posts_per_day', '1'), $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">1 per day is recommended for Instagram. More than that can reduce reach.</p>
                    </td></tr>
                    <tr><th>Post Time</th><td>
                        <select name="rrh_ig_autopilot_post_hour" style="width:80px;">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                            <option value="<?php echo $h; ?>" <?php selected(get_option('rrh_ig_autopilot_post_hour', '10'), $h); ?>><?php echo date('g A', mktime($h)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <span>:</span>
                        <select name="rrh_ig_autopilot_post_minute" style="width:80px;">
                            <?php foreach ([0, 15, 30, 45] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php selected(get_option('rrh_ig_autopilot_post_minute', '0'), $m); ?>><?php echo sprintf('%02d', $m); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Time of day to post (your WordPress timezone).</p>
                    </td></tr>
                    <tr><th>Spacing (multi-post)</th><td>
                        <input type="number" name="rrh_ig_autopilot_spacing_hours" value="<?php echo esc_attr(get_option('rrh_ig_autopilot_spacing_hours', '4')); ?>" min="2" max="12" style="width:60px;"> hours between posts
                        <p class="description">Only applies when posting more than 1/day.</p>
                    </td></tr>
                    <tr><th>Rotation Cooldown</th><td>
                        <input type="number" name="rrh_ig_autopilot_cooldown_days" value="<?php echo esc_attr(get_option('rrh_ig_autopilot_cooldown_days', '60')); ?>" min="7" max="365" style="width:60px;"> days
                        <p class="description">Don't re-post a product until this many days have passed. With 84 products at 1/day, a full rotation takes ~84 days.</p>
                    </td></tr>
                    <tr><th>Caption Templates</th><td>
                        <div style="padding:12px; background:#f0f6fc; border:1px solid #c3c4c7; border-radius:6px; margin-bottom:8px;">
                            <strong>🎯 Smart Template Selection</strong><br>
                            <span style="font-size:13px; color:#555;">Templates are automatically matched by product category and randomly selected for variety. Coaster products get coaster templates, stadium products get stadium templates.</span>
                        </div>
                        <?php
                        $ap_templates = RRH_IG_Templates::get_all();
                        if (!empty($ap_templates)):
                            $by_cat = [];
                            foreach ($ap_templates as $t) {
                                $cat = $t->category ?: 'general';
                                $by_cat[$cat][] = $t;
                            }
                        ?>
                        <table class="widefat" style="max-width:600px;">
                            <thead><tr><th>Category</th><th>Templates</th></tr></thead>
                            <tbody>
                            <?php foreach ($by_cat as $cat => $tmpls): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst($cat)); ?></strong></td>
                                <td><?php echo esc_html(implode(', ', array_map(function($t) { return $t->name; }, $tmpls))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description" style="margin-top:8px;">Manage templates in the <a href="#tab-templates" onclick="document.querySelector('[href=\'#tab-templates\']').click(); return false;">📝 Templates tab</a>. Set each template's category to match your product categories.</p>
                        <?php else: ?>
                        <p class="description" style="color:#d63638;">⚠️ No templates found! Create templates in the <a href="#tab-templates" onclick="document.querySelector('[href=\'#tab-templates\']').click(); return false;">📝 Templates tab</a> and set their categories (e.g. "coasters", "stadiums") to match your products.</p>
                        <?php endif; ?>
                        <input type="hidden" name="rrh_ig_autopilot_caption_template" value="<?php echo esc_attr(get_option('rrh_ig_autopilot_caption_template')); ?>">
                        <p class="description" style="margin-top:8px;"><em>Fallback: If no templates match, uses the default caption format.</em></p>
                    </td></tr>
                    <tr><th>Default Hashtags</th><td>
                        <textarea name="rrh_ig_autopilot_default_hashtags" rows="3" class="large-text"><?php echo esc_textarea(get_option('rrh_ig_autopilot_default_hashtags', '#stadiumcoasters #stadiumart #sportsmemories #gameday #collegegameday #tailgate #homebar #rockyriverhills #football #baseball #basketball #hockey #soccer #homedecor #coasters #drinkware #giftideas #sportsbar #mancave #shopsmall #supportsmallbusiness #handmade #smallbiz')); ?></textarea>
                        <p class="description">🏷️ <strong>Full Smart Hashtag Stack:</strong> These default tags + category mapping tags (from Hashtags tab) + product-specific city/team tags + template hashtags — all combined and deduplicated automatically. Max 30 per post.</p>
                    </td></tr>
                </table>
            </div>

            <div class="rrh-ig-card">
                <h2>🚀 Auto-Post New Products</h2>
                <p class="description">Automatically queue an Instagram post when a new WooCommerce product is published.</p>
                <table class="form-table">
                    <tr><th>Enable</th><td><label><input type="checkbox" name="rrh_ig_auto_post_enabled" value="1" <?php checked(get_option('rrh_ig_auto_post_enabled'), '1'); ?>> Auto-post new products</label></td></tr>
                    <tr><th>Caption Template</th><td>
                        <div style="padding:10px; background:#f0f6fc; border:1px solid #c3c4c7; border-radius:6px;">
                            🎯 Uses <strong>Smart Template Selection</strong> — automatically picks a random template matched to the product's category. Same behavior as Autopilot.
                        </div>
                        <input type="hidden" name="rrh_ig_auto_post_template" value="<?php echo esc_attr(get_option('rrh_ig_auto_post_template')); ?>">
                    </td></tr>
                </table>
            </div>

            <div class="rrh-ig-card">
                <h2>🔥 Sale Announcements</h2>
                <p class="description">Auto-queue a post when you set a sale price on a product.</p>
                <table class="form-table">
                    <tr><th>Enable</th><td><label><input type="checkbox" name="rrh_ig_sale_announce_enabled" value="1" <?php checked(get_option('rrh_ig_sale_announce_enabled'), '1'); ?>> Auto-announce sales</label></td></tr>
                </table>
            </div>

            <div class="rrh-ig-card">
                <h2>♻️ Content Recycling</h2>
                <p class="description">Automatically re-post your best-performing content after a set period.</p>
                <table class="form-table">
                    <tr><th>Enable</th><td><label><input type="checkbox" name="rrh_ig_recycle_enabled" value="1" <?php checked(get_option('rrh_ig_recycle_enabled'), '1'); ?>> Enable recycling</label></td></tr>
                    <tr><th>Recycle After</th><td><input type="number" name="rrh_ig_recycle_days" value="<?php echo esc_attr(get_option('rrh_ig_recycle_days', 30)); ?>" min="7" max="365" style="width:80px;"> days</td></tr>
                    <tr><th>Min. Engagement</th><td><input type="number" name="rrh_ig_recycle_min_engagement" value="<?php echo esc_attr(get_option('rrh_ig_recycle_min_engagement', 10)); ?>" min="1" style="width:80px;"> total interactions to be eligible</td></tr>
                </table>
            </div>

            <?php submit_button('Save Automation Settings'); ?>
        </form>
    </div>

    <!-- Templates Tab -->
    <div id="tab-templates" class="rrh-ig-tab-content" style="display:none;">
        <div class="rrh-ig-card">
            <h2>📝 Caption Templates</h2>
            <p class="description">Create reusable caption templates. Variables: <?php foreach ($template_vars as $k => $v) echo "<code>{$k}</code>, "; ?></p>

            <div id="rrh-ig-template-form" style="margin:16px 0; padding:16px; background:#f9f9f9; border-radius:6px;">
                <input type="hidden" id="tmpl-id" value="0">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="rrh-ig-field">
                        <label>Template Name</label>
                        <input type="text" id="tmpl-name" class="regular-text" placeholder="e.g. Coasters - Watch Party">
                    </div>
                    <div class="rrh-ig-field">
                        <label>Category <span style="color:#d63638; font-weight:600;">*</span></label>
                        <select id="tmpl-category" style="width:100%;">
                            <option value="general">General (any product)</option>
                            <?php
                            $woo_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                            if (!is_wp_error($woo_cats)):
                                foreach ($woo_cats as $wcat):
                            ?>
                            <option value="<?php echo esc_attr(strtolower($wcat->name)); ?>"><?php echo esc_html($wcat->name); ?> (<?php echo $wcat->count; ?> products)</option>
                            <?php endforeach; endif; ?>
                        </select>
                        <p class="description" style="margin-top:4px;">⚠️ Must match your product category. Coaster templates only go to coaster products.</p>
                    </div>
                </div>
                <div class="rrh-ig-field">
                    <label>Caption Template</label>
                    <textarea id="tmpl-caption" rows="4" class="large-text" placeholder="🏟️ {product_name}&#10;&#10;{description}&#10;&#10;💰 {price}"></textarea>
                </div>
                <div class="rrh-ig-field">
                    <label>Default Hashtags</label>
                    <textarea id="tmpl-hashtags" rows="2" class="large-text" placeholder="#stadiumcoasters #gameday"></textarea>
                </div>
                <button type="button" id="tmpl-save" class="button button-primary">💾 Save Template</button>
                <button type="button" id="tmpl-clear" class="button">Clear</button>
            </div>

            <table class="widefat" id="rrh-ig-templates-table">
                <thead><tr><th>Name</th><th>Category</th><th>Preview</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($templates)): ?>
                    <tr><td colspan="4" style="text-align:center; color:#666;">No templates yet</td></tr>
                <?php else: foreach ($templates as $t):
                    $cat_display = $t->category ?: 'general';
                    $cat_color = ($cat_display === 'general') ? '#666' : '#A2755A';
                ?>
                    <tr data-id="<?php echo $t->id; ?>">
                        <td><strong><?php echo esc_html($t->name); ?></strong></td>
                        <td><span style="background:<?php echo $cat_color; ?>; color:#fff; padding:2px 8px; border-radius:3px; font-size:11px; text-transform:uppercase;"><?php echo esc_html($cat_display); ?></span></td>
                        <td><?php echo esc_html(wp_trim_words($t->caption_template, 15)); ?></td>
                        <td>
                            <button class="button button-small tmpl-edit" data-id="<?php echo $t->id; ?>" data-name="<?php echo esc_attr($t->name); ?>" data-caption="<?php echo esc_attr($t->caption_template); ?>" data-hashtags="<?php echo esc_attr($t->hashtags); ?>" data-category="<?php echo esc_attr($t->category); ?>">✏️</button>
                            <button class="button button-small tmpl-delete" data-id="<?php echo $t->id; ?>">🗑️</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Category Hashtags Tab -->
    <div id="tab-hashtags" class="rrh-ig-tab-content" style="display:none;">
        <div class="rrh-ig-card">
            <h2>🏷️ Category Hashtags</h2>
            <p class="description">Map hashtag sets to WooCommerce product categories. These auto-apply when posting products from each category.</p>

            <?php if ($has_woo):
                $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            ?>
            <table class="widefat" style="margin-top:16px;">
                <thead><tr><th style="width:200px;">Category</th><th>Hashtags</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><strong><?php echo esc_html($cat->name); ?></strong> <span style="color:#999;">(<?php echo $cat->count; ?>)</span></td>
                    <td><input type="text" class="large-text rrh-cat-hashtag" data-cat-id="<?php echo $cat->term_id; ?>"
                              value="<?php echo esc_attr($cat_hashtags[$cat->term_id] ?? ''); ?>"
                              placeholder="#hashtags for this category"></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <button type="button" id="rrh-save-cat-hashtags" class="button button-primary">💾 Save Category Hashtags</button>
            </p>
            <?php else: ?>
                <p>WooCommerce required for category hashtags.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Link in Bio Tab -->
    <div id="tab-linkinbio" class="rrh-ig-tab-content" style="display:none;">
        <form method="post" action="options.php">
            <?php settings_fields('rrh_ig_linkinbio'); ?>
            <div class="rrh-ig-card">
                <h2>🔗 Link in Bio Page</h2>
                <p class="description">Auto-generated landing page showing your recent Instagram posts with links to products. Perfect for your Instagram bio link!</p>
                <table class="form-table">
                    <tr><th>Enable</th><td>
                        <label><input type="checkbox" name="rrh_ig_linkinbio_enabled" value="1" <?php checked(get_option('rrh_ig_linkinbio_enabled'), '1'); ?>> Enable link-in-bio page</label>
                    </td></tr>
                    <tr><th>URL</th><td>
                        <code><?php echo home_url('/instagram/'); ?></code>
                        <p class="description">Add this URL to your Instagram bio. After enabling, visit Settings → Permalinks and click Save to flush rewrite rules.</p>
                    </td></tr>
                </table>
                <p class="description">You can also use the shortcode <code>[rrh_instagram_feed count="6" columns="3"]</code> to embed the feed anywhere on your site.</p>
            </div>
            <?php submit_button('Save'); ?>
        </form>
    </div>
</div>
