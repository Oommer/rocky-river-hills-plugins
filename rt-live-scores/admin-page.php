<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtls-wrap">
    <div class="rtls-header">
        <h1>📺 Live Scores Ticker</h1>
        <p>ESPN-style live scores with "Shop Gear" links to your products</p>
        <span class="rtls-badge <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
            <?php echo !empty($settings['enabled']) ? '● Active' : '○ Inactive'; ?>
        </span>
    </div>

    <nav class="rtls-tabs">
        <a href="#" class="rtls-tab active" data-tab="setup">Setup</a>
        <a href="#" class="rtls-tab" data-tab="teams">Team Detection</a>
    </nav>

    <!-- Setup -->
    <div class="rtls-panel active" id="panel-setup">
        <form id="rtls-form">
            <div class="rtls-section">
                <h3>General</h3>
                <label class="rtls-toggle">
                    <input type="checkbox" name="enabled" <?php checked(!empty($settings['enabled'])); ?>>
                    <strong>Enable Live Scores Ticker</strong>
                </label>
                <label class="rtls-toggle">
                    <input type="checkbox" name="show_shop_links" <?php checked(!empty($settings['show_shop_links'])); ?>>
                    <strong>Show 🛒 icons</strong> for teams with matching products
                </label>
            </div>

            <div class="rtls-section">
                <h3>Leagues</h3>
                <p class="rtls-hint">Select which leagues to display. Games are fetched from ESPN's scoreboard API.</p>
                <div class="rtls-league-grid">
                    <?php
                    $all_leagues = [
                        'nfl' => '🏈 NFL', 'ncaaf' => '🏈 College Football',
                        'nba' => '🏀 NBA', 'wnba' => '🏀 WNBA', 'ncaab' => '🏀 College Basketball',
                        'mlb' => '⚾ MLB', 'nhl' => '🏒 NHL', 'mls' => '⚽ MLS',
                    ];
                    foreach ($all_leagues as $k => $v):
                    ?>
                    <label class="rtls-league-check">
                        <input type="checkbox" name="leagues[]" value="<?php echo $k; ?>" <?php echo in_array($k, $leagues) ? 'checked' : ''; ?>>
                        <span><?php echo $v; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rtls-section">
                <h3>Refresh</h3>
                <div class="rtls-field">
                    <label>Auto-refresh interval (seconds)</label>
                    <input type="number" name="refresh_interval" value="<?php echo esc_attr($settings['refresh_interval'] ?? 60); ?>" min="30" max="300" style="max-width:120px;">
                    <p class="rtls-hint">How often scores update. 60s recommended.</p>
                </div>
            </div>

            <div class="rtls-section">
                <h3>Shortcode</h3>
                <code>[rrh_live_scores]</code>
                <p class="rtls-hint">Place in Elementor between your hero and Best Sellers. Use a full-width section with 0 padding. Scores are rendered server-side — no AJAX needed for initial load.</p>
            </div>

            <button type="submit" class="rtls-btn primary">Save Settings</button>
        </form>
    </div>

    <!-- Team Detection -->
    <div class="rtls-panel" id="panel-teams">
        <div class="rtls-section">
            <h3>Team Detection</h3>
            <p class="rtls-hint">Scans your WooCommerce product names and descriptions to figure out which teams you carry. Matching teams get a 🛒 icon in the ticker linking to <code>rockyriverhills.com/?s=[team]&post_type=product</code></p>
            <div class="rtls-scan-area">
                <button type="button" class="rtls-btn primary" id="rtls-auto-scan">🔍 Scan My Products</button>
                <span id="rtls-scan-result" style="margin-left:12px;font-size:13px;color:#777;"></span>
            </div>
            <div id="rtls-team-list" style="margin-top:16px;"></div>
        </div>
    </div>
</div>
