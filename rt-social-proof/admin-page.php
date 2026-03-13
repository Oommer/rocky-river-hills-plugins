<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtsp-wrap">
    <div class="rtsp-header">
        <div>
            <h1>📣 Social Proof</h1>
            <p class="rtsp-subtitle">Live activity notifications to boost conversions</p>
        </div>
        <span class="rtsp-status <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
            <?php echo !empty($settings['enabled']) ? '● Live' : '○ Off'; ?>
        </span>
    </div>

    <div class="rtsp-tabs">
        <button class="rtsp-tab active" data-tab="dashboard">Dashboard</button>
        <button class="rtsp-tab" data-tab="notifications">Notification Types</button>
        <button class="rtsp-tab" data-tab="appearance">Appearance</button>
        <button class="rtsp-tab" data-tab="timing">Timing</button>
        <button class="rtsp-tab" data-tab="filler">Filler Data</button>
        <button class="rtsp-tab" data-tab="settings">Settings</button>
    </div>

    <!-- Dashboard -->
    <div class="rtsp-panel active" id="panel-dashboard">
        <div class="rtsp-stats-grid">
            <div class="rtsp-stat-card">
                <div class="rtsp-stat-icon">👁️</div>
                <div class="rtsp-stat-num" id="stat-impressions">—</div>
                <div class="rtsp-stat-label">Total Impressions</div>
            </div>
            <div class="rtsp-stat-card">
                <div class="rtsp-stat-icon">📊</div>
                <div class="rtsp-stat-num" id="stat-today">—</div>
                <div class="rtsp-stat-label">Today</div>
            </div>
            <div class="rtsp-stat-card">
                <div class="rtsp-stat-icon">✅</div>
                <div class="rtsp-stat-num" id="stat-real">—</div>
                <div class="rtsp-stat-label">Real Activities</div>
            </div>
            <div class="rtsp-stat-card">
                <div class="rtsp-stat-icon">🔄</div>
                <div class="rtsp-stat-num" id="stat-total">—</div>
                <div class="rtsp-stat-label">Total Activities</div>
            </div>
        </div>

        <div class="rtsp-card">
            <div class="rtsp-card-header">
                <h3>Recent Activity</h3>
                <button class="rtsp-btn small secondary" id="rtsp-refresh">Refresh</button>
            </div>
            <table class="rtsp-table" id="rtsp-activity-table">
                <thead><tr><th>Type</th><th>Product</th><th>Location</th><th>Real?</th><th>Time</th></tr></thead>
                <tbody><tr><td colspan="5" class="rtsp-muted">Loading...</td></tr></tbody>
            </table>
        </div>

        <div class="rtsp-card">
            <div class="rtsp-card-header">
                <h3>Daily Impressions</h3>
            </div>
            <div id="rtsp-daily-chart"></div>
        </div>
    </div>

    <!-- Notification Types -->
    <div class="rtsp-panel" id="panel-notifications">
        <form id="rtsp-form-notifications" class="rtsp-form">
            <div class="rtsp-card">
                <div class="rtsp-toggle-header">
                    <div>
                        <h3>🛒 Recent Purchases</h3>
                        <p class="rtsp-hint">"Someone in Dallas just purchased..."</p>
                    </div>
                    <label class="rtsp-switch"><input type="checkbox" name="show_purchases" value="1" <?php checked(!empty($settings['show_purchases'])); ?>><span class="rtsp-slider"></span></label>
                </div>
                <div class="rtsp-field">
                    <label>Message Template</label>
                    <input type="text" name="purchase_text" value="<?php echo esc_attr($settings['purchase_text'] ?? ''); ?>">
                    <p class="rtsp-hint">Variables: {city}, {state}, {product}</p>
                </div>
                <div class="rtsp-field">
                    <label>Lookback Period (days)</label>
                    <input type="number" name="purchase_lookback_days" value="<?php echo esc_attr($settings['purchase_lookback_days'] ?? 30); ?>" min="1" max="365">
                    <p class="rtsp-hint">Show real purchases from the last X days</p>
                </div>
            </div>

            <div class="rtsp-card">
                <div class="rtsp-toggle-header">
                    <div>
                        <h3>👀 Currently Viewing</h3>
                        <p class="rtsp-hint">"Someone in Chicago is viewing this right now"</p>
                    </div>
                    <label class="rtsp-switch"><input type="checkbox" name="show_views" value="1" <?php checked(!empty($settings['show_views'])); ?>><span class="rtsp-slider"></span></label>
                </div>
                <div class="rtsp-field">
                    <label>Message Template</label>
                    <input type="text" name="view_text" value="<?php echo esc_attr($settings['view_text'] ?? ''); ?>">
                </div>
                <div class="rtsp-field">
                    <label>View Lookback (minutes)</label>
                    <input type="number" name="view_lookback_minutes" value="<?php echo esc_attr($settings['view_lookback_minutes'] ?? 30); ?>" min="5" max="120">
                </div>
            </div>

            <div class="rtsp-card">
                <div class="rtsp-toggle-header">
                    <div>
                        <h3>🛍️ Added to Cart</h3>
                        <p class="rtsp-hint">"Someone in Phoenix just added this to their cart"</p>
                    </div>
                    <label class="rtsp-switch"><input type="checkbox" name="show_cart_adds" value="1" <?php checked(!empty($settings['show_cart_adds'])); ?>><span class="rtsp-slider"></span></label>
                </div>
                <div class="rtsp-field">
                    <label>Message Template</label>
                    <input type="text" name="cart_text" value="<?php echo esc_attr($settings['cart_text'] ?? ''); ?>">
                </div>
            </div>

            <div class="rtsp-card">
                <div class="rtsp-toggle-header">
                    <div>
                        <h3>🕐 Recently Viewed</h3>
                        <p class="rtsp-hint">"Someone in Seattle recently viewed..."</p>
                    </div>
                    <label class="rtsp-switch"><input type="checkbox" name="show_recent_views" value="1" <?php checked(!empty($settings['show_recent_views'])); ?>><span class="rtsp-slider"></span></label>
                </div>
                <div class="rtsp-field">
                    <label>Message Template</label>
                    <input type="text" name="recent_view_text" value="<?php echo esc_attr($settings['recent_view_text'] ?? ''); ?>">
                </div>
            </div>

            <div class="rtsp-actions">
                <button type="submit" class="rtsp-btn primary">Save Notification Settings</button>
            </div>
        </form>
    </div>

    <!-- Appearance -->
    <div class="rtsp-panel" id="panel-appearance">
        <form id="rtsp-form-appearance" class="rtsp-form">
            <div class="rtsp-card">
                <h3>Colors</h3>
                <div class="rtsp-field-row">
                    <div class="rtsp-field">
                        <label>Background</label>
                        <input type="color" name="bg_color" value="<?php echo esc_attr($settings['bg_color'] ?? '#ffffff'); ?>">
                    </div>
                    <div class="rtsp-field">
                        <label>Text Color</label>
                        <input type="color" name="text_color" value="<?php echo esc_attr($settings['text_color'] ?? '#333333'); ?>">
                    </div>
                    <div class="rtsp-field">
                        <label>Accent Color</label>
                        <input type="color" name="accent_color" value="<?php echo esc_attr($settings['accent_color'] ?? '#A2755A'); ?>">
                    </div>
                </div>
            </div>

            <div class="rtsp-card">
                <h3>Display Options</h3>
                <div class="rtsp-field">
                    <label>Border Radius (px)</label>
                    <input type="number" name="border_radius" value="<?php echo esc_attr($settings['border_radius'] ?? 10); ?>" min="0" max="30">
                </div>
                <div class="rtsp-field">
                    <label>Animation Style</label>
                    <select name="animation">
                        <option value="slide" <?php selected(($settings['animation'] ?? 'slide'), 'slide'); ?>>Slide In</option>
                        <option value="fade" <?php selected(($settings['animation'] ?? ''), 'fade'); ?>>Fade In</option>
                    </select>
                </div>
                <div class="rtsp-check-row">
                    <label><input type="checkbox" name="show_image" value="1" <?php checked(!empty($settings['show_image'])); ?>> Show product image</label>
                </div>
                <div class="rtsp-check-row">
                    <label><input type="checkbox" name="show_time" value="1" <?php checked(!empty($settings['show_time'])); ?>> Show time ago</label>
                </div>
                <div class="rtsp-check-row">
                    <label><input type="checkbox" name="show_close" value="1" <?php checked(!empty($settings['show_close'])); ?>> Show close button</label>
                </div>
            </div>

            <div class="rtsp-actions">
                <button type="submit" class="rtsp-btn primary">Save Appearance</button>
            </div>
        </form>
    </div>

    <!-- Timing -->
    <div class="rtsp-panel" id="panel-timing">
        <form id="rtsp-form-timing" class="rtsp-form">
            <div class="rtsp-card">
                <h3>Display Timing</h3>
                <div class="rtsp-field-row">
                    <div class="rtsp-field">
                        <label>Initial Delay (seconds)</label>
                        <input type="number" name="initial_delay" value="<?php echo esc_attr($settings['initial_delay'] ?? 5); ?>" min="1" max="60">
                        <p class="rtsp-hint">Wait before showing first notification</p>
                    </div>
                    <div class="rtsp-field">
                        <label>Display Duration (seconds)</label>
                        <input type="number" name="display_duration" value="<?php echo esc_attr($settings['display_duration'] ?? 5); ?>" min="2" max="30">
                        <p class="rtsp-hint">How long each notification stays visible</p>
                    </div>
                </div>
                <div class="rtsp-field-row">
                    <div class="rtsp-field">
                        <label>Delay Between (seconds)</label>
                        <input type="number" name="delay_between" value="<?php echo esc_attr($settings['delay_between'] ?? 12); ?>" min="5" max="120">
                        <p class="rtsp-hint">Gap between notifications</p>
                    </div>
                    <div class="rtsp-field">
                        <label>Max Per Page Load</label>
                        <input type="number" name="max_per_page" value="<?php echo esc_attr($settings['max_per_page'] ?? 10); ?>" min="1" max="50">
                        <p class="rtsp-hint">Stop after this many per visitor</p>
                    </div>
                </div>
            </div>

            <div class="rtsp-actions">
                <button type="submit" class="rtsp-btn primary">Save Timing</button>
            </div>
        </form>
    </div>

    <!-- Filler Data -->
    <div class="rtsp-panel" id="panel-filler">
        <form id="rtsp-form-filler" class="rtsp-form">
            <div class="rtsp-card">
                <div class="rtsp-toggle-header">
                    <div>
                        <h3>Filler Notifications</h3>
                        <p class="rtsp-hint">Generate realistic activity notifications using your real products and random cities when real activity is low.</p>
                    </div>
                    <label class="rtsp-switch"><input type="checkbox" name="filler_enabled" value="1" <?php checked(!empty($settings['filler_enabled'])); ?>><span class="rtsp-slider"></span></label>
                </div>

                <div class="rtsp-field">
                    <label>Max Filler Age (hours)</label>
                    <input type="number" name="filler_max_age_hours" value="<?php echo esc_attr($settings['filler_max_age_hours'] ?? 48); ?>" min="1" max="168">
                    <p class="rtsp-hint">Filler purchase timestamps will appear to be within the last X hours</p>
                </div>

                <div class="rtsp-field">
                    <label>Filler Cities</label>
                    <textarea name="filler_cities" rows="12"><?php echo esc_textarea($settings['filler_cities'] ?? ''); ?></textarea>
                    <p class="rtsp-hint">One per line, format: City, ST — used for both filler and tracked view/cart activities</p>
                </div>
            </div>

            <div class="rtsp-actions">
                <button type="submit" class="rtsp-btn primary">Save Filler Settings</button>
            </div>
        </form>
    </div>

    <!-- Settings -->
    <div class="rtsp-panel" id="panel-settings">
        <form id="rtsp-form-settings" class="rtsp-form">
            <div class="rtsp-card">
                <h3>General Settings</h3>
                <div class="rtsp-toggle-header" style="margin-bottom:20px;">
                    <span><strong>Enable Social Proof</strong><br><small class="rtsp-hint">Master switch</small></span>
                    <label class="rtsp-switch"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>><span class="rtsp-slider"></span></label>
                </div>

                <div class="rtsp-field">
                    <label>Position</label>
                    <select name="position">
                        <option value="bottom-left" <?php selected(($settings['position'] ?? 'bottom-left'), 'bottom-left'); ?>>Bottom Left</option>
                        <option value="bottom-right" <?php selected(($settings['position'] ?? ''), 'bottom-right'); ?>>Bottom Right</option>
                    </select>
                </div>

                <div class="rtsp-field">
                    <label>Show On</label>
                    <select name="show_on">
                        <option value="all" <?php selected(($settings['show_on'] ?? 'all'), 'all'); ?>>All Pages</option>
                        <option value="shop" <?php selected(($settings['show_on'] ?? ''), 'shop'); ?>>Shop & Category Pages Only</option>
                        <option value="product" <?php selected(($settings['show_on'] ?? ''), 'product'); ?>>Product Pages Only</option>
                    </select>
                </div>

                <div class="rtsp-check-row">
                    <label><input type="checkbox" name="hide_on_cart" value="1" <?php checked(!empty($settings['hide_on_cart'])); ?>> Hide on Cart page</label>
                </div>
                <div class="rtsp-check-row">
                    <label><input type="checkbox" name="hide_on_checkout" value="1" <?php checked(!empty($settings['hide_on_checkout'])); ?>> Hide on Checkout page</label>
                </div>
            </div>

            <div class="rtsp-card">
                <h3>Data Management</h3>
                <p class="rtsp-hint" style="margin-bottom:16px;">Clear all tracked activity data and impression stats.</p>
                <button type="button" class="rtsp-btn danger" id="rtsp-reset-stats">Reset All Data</button>
            </div>

            <div class="rtsp-actions">
                <button type="submit" class="rtsp-btn primary">Save Settings</button>
                <button type="button" class="rtsp-btn danger" id="rtsp-reset-defaults" style="margin-left:12px;">Reset to Defaults</button>
            </div>
        </form>
    </div>

    <div class="rtsp-toast-msg" id="rtsp-toast"></div>
</div>
