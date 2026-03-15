<?php if (!defined('ABSPATH')) exit; $connected = !empty($settings['connected']); ?>
<div class="rtpp-wrap">
    <div class="rtpp-header">
        <div>
            <h1>📌 Pinterest Auto-Poster</h1>
            <p class="rtpp-subtitle">Automatically pin products to Pinterest</p>
        </div>
        <?php if ($connected): ?>
            <span class="rtpp-status active">● Connected as @<span id="rtpp-username"><?php echo esc_html($settings['user_name'] ?? ''); ?></span></span>
        <?php else: ?>
            <span class="rtpp-status inactive">○ Not Connected</span>
        <?php endif; ?>
    </div>

    <?php if (!$connected): ?>
    <!-- Connect Screen -->
    <div class="rtpp-card rtpp-connect-card">
        <div class="rtpp-connect-inner">
            <div class="rtpp-connect-icon">📌</div>
            <h2>Connect Your Pinterest Account</h2>
            <p>Link your Pinterest Business account to start auto-pinning your products.</p>
            <a href="<?php echo esc_url($this->get_auth_url()); ?>" class="rtpp-btn primary rtpp-btn-lg">Connect Pinterest Account →</a>
            <p class="rtpp-hint" style="margin-top:16px;">You'll be redirected to Pinterest to authorize access. We only request permission to manage your pins and boards.</p>
        </div>
    </div>
    <?php else: ?>

    <div class="rtpp-tabs">
        <button class="rtpp-tab active" data-tab="dashboard">Dashboard</button>
        <button class="rtpp-tab" data-tab="products">Products</button>
        <button class="rtpp-tab" data-tab="schedule">Schedule</button>
        <button class="rtpp-tab" data-tab="boards">Boards</button>
        <button class="rtpp-tab" data-tab="content">Pin Content</button>
        <button class="rtpp-tab" data-tab="settings">Settings</button>
        <button class="rtpp-tab" data-tab="log">Pin Log</button>
    </div>

    <!-- Dashboard -->
    <div class="rtpp-panel active" id="panel-dashboard">
        <div class="rtpp-stats-grid">
            <div class="rtpp-stat-card accent">
                <div class="rtpp-stat-icon">📌</div>
                <div class="rtpp-stat-num" id="stat-pinned">—</div>
                <div class="rtpp-stat-label">Pins Created</div>
            </div>
            <div class="rtpp-stat-card">
                <div class="rtpp-stat-icon">📅</div>
                <div class="rtpp-stat-num" id="stat-pending">—</div>
                <div class="rtpp-stat-label">Scheduled</div>
            </div>
            <div class="rtpp-stat-card">
                <div class="rtpp-stat-icon">❌</div>
                <div class="rtpp-stat-num" id="stat-failed">—</div>
                <div class="rtpp-stat-label">Failed</div>
            </div>
            <div class="rtpp-stat-card">
                <div class="rtpp-stat-icon">🏷️</div>
                <div class="rtpp-stat-num" id="stat-board"><?php echo esc_html($settings['default_board'] ? 'Set' : 'None'); ?></div>
                <div class="rtpp-stat-label">Default Board</div>
            </div>
        </div>

        <div class="rtpp-card">
            <h3>Quick Actions</h3>
            <div class="rtpp-actions" style="margin:0;">
                <button type="button" class="rtpp-btn primary" id="rtpp-pin-all">📌 Schedule All Products</button>
                <button type="button" class="rtpp-btn secondary" id="rtpp-refresh-stats">🔄 Refresh Stats</button>
            </div>
            <p class="rtpp-hint" style="margin-top:12px;" id="rtpp-quick-status"></p>
        </div>

        <div class="rtpp-card">
            <h3>How It Works</h3>
            <div class="rtpp-setup-steps">
                <div class="rtpp-step done" id="step-connect">
                    <span class="rtpp-step-num">1</span>
                    <div><strong>Connect Pinterest</strong><p>✅ Connected<span id="step-username-display"><?php echo $settings['user_name'] ? ' as @' . esc_html($settings['user_name']) : ''; ?></span></p></div>
                </div>
                <div class="rtpp-step <?php echo !empty($settings['default_board']) ? 'done' : ''; ?>" id="step-board">
                    <span class="rtpp-step-num">2</span>
                    <div><strong>Select or create a board</strong><p>Go to the Boards tab and pick your default board</p></div>
                </div>
                <div class="rtpp-step" id="step-schedule">
                    <span class="rtpp-step-num">3</span>
                    <div><strong>Schedule your products</strong><p>Click "Schedule All Products" or pin individually from the Products tab</p></div>
                </div>
                <div class="rtpp-step" id="step-done">
                    <span class="rtpp-step-num">4</span>
                    <div><strong>Sit back</strong><p>The plugin handles posting on a natural schedule (default: 3 pins/day)</p></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Products -->
    <div class="rtpp-panel" id="panel-products">
        <div class="rtpp-card">
            <div class="rtpp-card-header">
                <h3>Your Products</h3>
                <button type="button" class="rtpp-btn small secondary" id="rtpp-load-products">Refresh</button>
            </div>
            <div id="rtpp-products-list">
                <p class="rtpp-muted">Loading products...</p>
            </div>
        </div>
    </div>

    <!-- Schedule -->
    <div class="rtpp-panel" id="panel-schedule">
        <div class="rtpp-card">
            <div class="rtpp-card-header">
                <h3>Upcoming Pins</h3>
                <div>
                    <button type="button" class="rtpp-btn small secondary" id="rtpp-refresh-schedule">Refresh</button>
                    <button type="button" class="rtpp-btn small danger" id="rtpp-clear-schedule">Clear All</button>
                </div>
            </div>
            <div id="rtpp-schedule-list">
                <p class="rtpp-muted">Loading schedule...</p>
            </div>
        </div>
    </div>

    <!-- Boards -->
    <div class="rtpp-panel" id="panel-boards">
        <div class="rtpp-card">
            <h3>Your Pinterest Boards</h3>
            <div id="rtpp-boards-list">
                <p class="rtpp-muted">Loading boards...</p>
            </div>
        </div>

        <div class="rtpp-card">
            <h3>Create New Board</h3>
            <div class="rtpp-field">
                <label>Board Name</label>
                <input type="text" id="rtpp-new-board-name" placeholder="e.g. Stadium Coasters & Wall Art">
            </div>
            <div class="rtpp-field">
                <label>Description (optional)</label>
                <textarea id="rtpp-new-board-desc" rows="3" placeholder="Handcrafted stadium-themed coasters and wall art for sports fans"></textarea>
            </div>
            <button type="button" class="rtpp-btn primary" id="rtpp-create-board">Create Board</button>
        </div>
    </div>

    <!-- Pin Content -->
    <div class="rtpp-panel" id="panel-content">
        <form id="rtpp-form-content" class="rtpp-form">
            <div class="rtpp-card">
                <h3>Pin Description Template</h3>
                <div class="rtpp-field">
                    <label>Template</label>
                    <textarea name="pin_description_template" rows="5"><?php echo esc_textarea($settings['pin_description_template'] ?? ''); ?></textarea>
                    <p class="rtpp-hint">Variables: {product_name}, {short_description}, {price}, {product_url}, {hashtags}, {site_name}</p>
                </div>

                <div class="rtpp-check-row">
                    <label><input type="checkbox" name="include_price" value="1" <?php checked(!empty($settings['include_price'])); ?>> Include price in description</label>
                </div>
            </div>

            <div class="rtpp-card">
                <h3>Hashtags</h3>
                <div class="rtpp-field">
                    <label>Default Hashtags</label>
                    <textarea name="default_hashtags" rows="3"><?php echo esc_textarea($settings['default_hashtags'] ?? ''); ?></textarea>
                    <p class="rtpp-hint">Space-separated hashtags applied to every pin</p>
                </div>
                <div class="rtpp-check-row">
                    <label><input type="checkbox" name="smart_hashtags" value="1" <?php checked(!empty($settings['smart_hashtags'])); ?>> Auto-add smart hashtags from product categories, tags, and sport type</label>
                </div>
            </div>

            <div class="rtpp-actions">
                <button type="submit" class="rtpp-btn primary">Save Content Settings</button>
            </div>
        </form>
    </div>

    <!-- Settings -->
    <div class="rtpp-panel" id="panel-settings">
        <form id="rtpp-form-settings" class="rtpp-form">
            <div class="rtpp-card">
                <h3>Schedule Settings</h3>
                <div class="rtpp-check-row" style="margin-bottom:16px;">
                    <label><input type="checkbox" name="schedule_enabled" value="1" <?php checked(!empty($settings['schedule_enabled'])); ?>> Enable auto-scheduling</label>
                </div>
                <div class="rtpp-field-row">
                    <div class="rtpp-field">
                        <label>Pins Per Day</label>
                        <input type="number" name="pins_per_day" value="<?php echo esc_attr($settings['pins_per_day'] ?? 3); ?>" min="1" max="25">
                        <p class="rtpp-hint">Pinterest recommends 3–5 for best results</p>
                    </div>
                    <div class="rtpp-field">
                        <label>Min Hours Between Pins</label>
                        <input type="number" name="min_hours_between" value="<?php echo esc_attr($settings['min_hours_between'] ?? 3); ?>" min="1" max="12">
                    </div>
                </div>
                <div class="rtpp-field-row">
                    <div class="rtpp-field">
                        <label>Start Hour (24h)</label>
                        <input type="number" name="schedule_start_hour" value="<?php echo esc_attr($settings['schedule_start_hour'] ?? 9); ?>" min="0" max="23">
                    </div>
                    <div class="rtpp-field">
                        <label>End Hour (24h)</label>
                        <input type="number" name="schedule_end_hour" value="<?php echo esc_attr($settings['schedule_end_hour'] ?? 21); ?>" min="1" max="24">
                    </div>
                </div>
            </div>

            <div class="rtpp-card">
                <h3>Automation</h3>
                <div class="rtpp-check-row">
                    <label><input type="checkbox" name="auto_pin_new" value="1" <?php checked(!empty($settings['auto_pin_new'])); ?>> Auto-schedule new products when they're published</label>
                </div>
            </div>

            <div class="rtpp-card">
                <h3>Account</h3>
                <p>Connected as: <strong>@<span id="rtpp-username-settings"><?php echo esc_html($settings['user_name'] ?? ''); ?></span></strong></p>
                <p class="rtpp-hint" style="margin-top:8px;">Pinterest API response: <code id="rtpp-debug-user"><?php echo esc_html($settings['user_data'] ?? 'No data yet — refresh the page'); ?></code></p>
                <div style="margin-top:12px;">
                    <button type="button" class="rtpp-btn secondary small" id="rtpp-refresh-user-btn">Refresh Account Info</button>
                    <button type="button" class="rtpp-btn danger" id="rtpp-disconnect" style="margin-left:8px;">Disconnect Pinterest</button>
                </div>
            </div>

            <div class="rtpp-card">
                <h3>Data Management</h3>
                <p class="rtpp-hint" style="margin-bottom:12px;">Reset pin tracking data — this won't delete pins from Pinterest, just clears the local log so products can be re-pinned.</p>
                <button type="button" class="rtpp-btn danger" id="rtpp-reset-data">Reset All Pin Data</button>
            </div>

            <div class="rtpp-actions">
                <button type="submit" class="rtpp-btn primary">Save Settings</button>
            </div>
        </form>
    </div>

    <!-- Pin Log -->
    <div class="rtpp-panel" id="panel-log">
        <div class="rtpp-card">
            <div class="rtpp-card-header">
                <h3>Pin Log</h3>
                <button type="button" class="rtpp-btn small secondary" id="rtpp-refresh-log">Refresh</button>
            </div>
            <table class="rtpp-table" id="rtpp-log-table">
                <thead><tr><th>Product</th><th>Status</th><th>Pin</th><th>Time</th></tr></thead>
                <tbody><tr><td colspan="4" class="rtpp-muted">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

    <div class="rtpp-toast" id="rtpp-toast"></div>
</div>
