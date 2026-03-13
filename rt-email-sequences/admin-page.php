<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtes-wrap">
    <div class="rtes-header">
        <div class="rtes-header-left">
            <h1>📧 Email Sequences</h1>
            <p class="rtes-subtitle">Automated email marketing for your WooCommerce store</p>
        </div>
        <div class="rtes-header-right">
            <span class="rtes-status <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
                <?php echo !empty($settings['enabled']) ? '● Active' : '○ Inactive'; ?>
            </span>
        </div>
    </div>

    <div class="rtes-tabs">
        <button class="rtes-tab active" data-tab="dashboard">Dashboard</button>
        <button class="rtes-tab" data-tab="abandoned">Abandoned Cart</button>
        <button class="rtes-tab" data-tab="post-purchase">Post-Purchase</button>
        <button class="rtes-tab" data-tab="review">Review Request</button>
        <button class="rtes-tab" data-tab="crosssell">Cross-Sell</button>
        <button class="rtes-tab" data-tab="welcome">Welcome Email</button>
        <button class="rtes-tab" data-tab="settings">Settings</button>
        <button class="rtes-tab" data-tab="log">Email Log</button>
    </div>

    <!-- ============ DASHBOARD TAB ============ -->
    <div class="rtes-panel active" id="panel-dashboard">
        <div class="rtes-stats-grid" id="rtes-stats">
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">📤</div>
                <div class="rtes-stat-number" id="stat-total-sent">—</div>
                <div class="rtes-stat-label">Emails Sent</div>
            </div>
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">👁️</div>
                <div class="rtes-stat-number" id="stat-total-opened">—</div>
                <div class="rtes-stat-label">Emails Opened</div>
            </div>
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">🛒</div>
                <div class="rtes-stat-number" id="stat-abandoned">—</div>
                <div class="rtes-stat-label">Abandoned Carts</div>
            </div>
            <div class="rtes-stat-card accent">
                <div class="rtes-stat-icon">✅</div>
                <div class="rtes-stat-number" id="stat-recovered">—</div>
                <div class="rtes-stat-label">Recovered Carts</div>
            </div>
            <div class="rtes-stat-card accent">
                <div class="rtes-stat-icon">💰</div>
                <div class="rtes-stat-number" id="stat-revenue">—</div>
                <div class="rtes-stat-label">Revenue Recovered</div>
            </div>
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">📈</div>
                <div class="rtes-stat-number" id="stat-recovery-rate">—</div>
                <div class="rtes-stat-label">Recovery Rate</div>
            </div>
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">🖱️</div>
                <div class="rtes-stat-number" id="stat-click-rate">—</div>
                <div class="rtes-stat-label">Click Rate</div>
            </div>
            <div class="rtes-stat-card">
                <div class="rtes-stat-icon">⚠️</div>
                <div class="rtes-stat-number" id="stat-at-risk">—</div>
                <div class="rtes-stat-label">Revenue at Risk</div>
            </div>
        </div>

        <div class="rtes-card">
            <h3>Open Rate by Email Type</h3>
            <div id="rtes-type-stats">
                <p class="rtes-muted">Loading stats...</p>
            </div>
        </div>

        <div class="rtes-card">
            <h3>Sequence Status</h3>
            <table class="rtes-table">
                <thead>
                    <tr><th>Sequence</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Abandoned Cart Recovery</td>
                        <td><span class="rtes-badge <?php echo !empty($settings['abandoned_enabled']) ? 'on' : 'off'; ?>"><?php echo !empty($settings['abandoned_enabled']) ? 'Active' : 'Disabled'; ?></span></td>
                    </tr>
                    <tr>
                        <td>Post-Purchase Thank You</td>
                        <td><span class="rtes-badge <?php echo !empty($settings['thankyou_enabled']) ? 'on' : 'off'; ?>"><?php echo !empty($settings['thankyou_enabled']) ? 'Active' : 'Disabled'; ?></span></td>
                    </tr>
                    <tr>
                        <td>Review Request</td>
                        <td><span class="rtes-badge <?php echo !empty($settings['review_enabled']) ? 'on' : 'off'; ?>"><?php echo !empty($settings['review_enabled']) ? 'Active' : 'Disabled'; ?></span></td>
                    </tr>
                    <tr>
                        <td>Cross-Sell</td>
                        <td><span class="rtes-badge <?php echo !empty($settings['crosssell_enabled']) ? 'on' : 'off'; ?>"><?php echo !empty($settings['crosssell_enabled']) ? 'Active' : 'Disabled'; ?></span></td>
                    </tr>
                    <tr>
                        <td>Welcome Email</td>
                        <td><span class="rtes-badge <?php echo !empty($settings['welcome_enabled']) ? 'on' : 'off'; ?>"><?php echo !empty($settings['welcome_enabled']) ? 'Active' : 'Disabled'; ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ ABANDONED CART TAB ============ -->
    <div class="rtes-panel" id="panel-abandoned">
        <form id="rtes-form-abandoned" class="rtes-form">
            <div class="rtes-card">
                <div class="rtes-toggle-header">
                    <h3>Abandoned Cart Recovery</h3>
                    <label class="rtes-switch">
                        <input type="checkbox" name="abandoned_enabled" value="1" <?php checked(!empty($settings['abandoned_enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>
                <p class="rtes-desc">Automatically email customers who add items to their cart but don't complete checkout.</p>

                <div class="rtes-field">
                    <label>Cart Timeout (minutes)</label>
                    <input type="number" name="abandoned_timeout" value="<?php echo esc_attr($settings['abandoned_timeout'] ?? 60); ?>" min="15" max="1440">
                    <p class="rtes-hint">How long after the last cart update before it's considered abandoned</p>
                </div>
            </div>

            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="rtes-card rtes-email-card">
                <div class="rtes-toggle-header">
                    <h3>Email #<?php echo $i; ?><?php echo $i === 1 ? ' (Gentle Reminder)' : ($i === 2 ? ' (Urgency)' : ' (Incentive)'); ?></h3>
                    <?php if ($i > 1): ?>
                    <label class="rtes-switch">
                        <input type="checkbox" name="abandoned_email_<?php echo $i; ?>_enabled" value="1" <?php checked(!empty($settings["abandoned_email_{$i}_enabled"])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                    <?php endif; ?>
                </div>

                <div class="rtes-field-row">
                    <div class="rtes-field">
                        <label>Send After (hours)</label>
                        <input type="number" name="abandoned_email_<?php echo $i; ?>_delay" value="<?php echo esc_attr($settings["abandoned_email_{$i}_delay"] ?? ''); ?>" min="1">
                        <p class="rtes-hint">Hours after cart abandonment</p>
                    </div>
                    <div class="rtes-field">
                        <label>Coupon Code</label>
                        <input type="text" name="abandoned_email_<?php echo $i; ?>_coupon" value="<?php echo esc_attr($settings["abandoned_email_{$i}_coupon"] ?? ''); ?>" placeholder="e.g. COMEBACK10 or type 'auto'">
                        <p class="rtes-hint">Enter a static coupon code, or type <strong>auto</strong> to generate unique one-time-use coupons per customer.</p>
                        <p class="rtes-hint">Must exist in WooCommerce → Coupons</p>
                    </div>
                </div>

                <div class="rtes-field">
                    <label>Subject Line</label>
                    <input type="text" name="abandoned_email_<?php echo $i; ?>_subject" value="<?php echo esc_attr($settings["abandoned_email_{$i}_subject"] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Heading</label>
                    <input type="text" name="abandoned_email_<?php echo $i; ?>_heading" value="<?php echo esc_attr($settings["abandoned_email_{$i}_heading"] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Body Text</label>
                    <textarea name="abandoned_email_<?php echo $i; ?>_body" rows="4"><?php echo esc_textarea($settings["abandoned_email_{$i}_body"] ?? ''); ?></textarea>
                </div>
                <div class="rtes-field">
                    <label>Button Text</label>
                    <input type="text" name="abandoned_email_<?php echo $i; ?>_cta" value="<?php echo esc_attr($settings["abandoned_email_{$i}_cta"] ?? ''); ?>">
                </div>
            </div>
            <?php endfor; ?>

            <div class="rtes-card">
                <h3>🎟️ Auto-Coupon Settings</h3>
                <p class="rtes-hint">When an email's coupon field is set to <strong>auto</strong>, the plugin generates a unique one-time-use coupon locked to the customer's email. Much more effective than static codes!</p>
                <div class="rtes-fields-row">
                    <div class="rtes-field">
                        <label>Discount Amount</label>
                        <input type="number" name="auto_coupon_amount" value="<?php echo esc_attr($settings['auto_coupon_amount'] ?? 10); ?>" min="1" step="1">
                    </div>
                    <div class="rtes-field">
                        <label>Discount Type</label>
                        <select name="auto_coupon_type">
                            <option value="percent" <?php selected(($settings['auto_coupon_type'] ?? 'percent'), 'percent'); ?>>Percentage (%)</option>
                            <option value="fixed" <?php selected(($settings['auto_coupon_type'] ?? ''), 'fixed'); ?>>Fixed Amount ($)</option>
                        </select>
                    </div>
                    <div class="rtes-field">
                        <label>Expires After (days)</label>
                        <input type="number" name="auto_coupon_expiry_days" value="<?php echo esc_attr($settings['auto_coupon_expiry_days'] ?? 7); ?>" min="1">
                    </div>
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Abandoned Cart Settings</button>
                <button type="button" class="rtes-btn secondary rtes-test-btn" data-type="abandoned">Send Test Email</button>
            </div>
        </form>
    </div>

    <!-- ============ POST-PURCHASE TAB ============ -->
    <div class="rtes-panel" id="panel-post-purchase">
        <form id="rtes-form-thankyou" class="rtes-form">
            <div class="rtes-card">
                <div class="rtes-toggle-header">
                    <h3>Post-Purchase Thank You</h3>
                    <label class="rtes-switch">
                        <input type="checkbox" name="thankyou_enabled" value="1" <?php checked(!empty($settings['thankyou_enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>
                <p class="rtes-desc">Send a personalized thank-you email when an order is completed.</p>

                <div class="rtes-field">
                    <label>Delay After Completion (hours)</label>
                    <input type="number" name="thankyou_delay" value="<?php echo esc_attr($settings['thankyou_delay'] ?? 0); ?>" min="0">
                    <p class="rtes-hint">0 = send immediately when order is marked completed</p>
                </div>
                <div class="rtes-field">
                    <label>Subject Line</label>
                    <input type="text" name="thankyou_subject" value="<?php echo esc_attr($settings['thankyou_subject'] ?? ''); ?>">
                    <p class="rtes-hint">Variables: {first_name}, {order_number}, {site_name}</p>
                </div>
                <div class="rtes-field">
                    <label>Heading</label>
                    <input type="text" name="thankyou_heading" value="<?php echo esc_attr($settings['thankyou_heading'] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Body Text</label>
                    <textarea name="thankyou_body" rows="5"><?php echo esc_textarea($settings['thankyou_body'] ?? ''); ?></textarea>
                    <p class="rtes-hint">Variables: {first_name}, {order_number}, {product_name}</p>
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Thank You Settings</button>
                <button type="button" class="rtes-btn secondary rtes-test-btn" data-type="thankyou">Send Test Email</button>
            </div>
        </form>
    </div>

    <!-- ============ REVIEW REQUEST TAB ============ -->
    <div class="rtes-panel" id="panel-review">
        <form id="rtes-form-review" class="rtes-form">
            <div class="rtes-card">
                <div class="rtes-toggle-header">
                    <h3>Review Request</h3>
                    <label class="rtes-switch">
                        <input type="checkbox" name="review_enabled" value="1" <?php checked(!empty($settings['review_enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>
                <p class="rtes-desc">Ask customers to review their purchase after they've had time to enjoy it.</p>

                <div class="rtes-field">
                    <label>Send After (days)</label>
                    <input type="number" name="review_delay_days" value="<?php echo esc_attr($settings['review_delay_days'] ?? 14); ?>" min="1" max="90">
                    <p class="rtes-hint">Days after order completion — give them time to receive and use the product</p>
                </div>
                <div class="rtes-field">
                    <label>Subject Line</label>
                    <input type="text" name="review_subject" value="<?php echo esc_attr($settings['review_subject'] ?? ''); ?>">
                    <p class="rtes-hint">Variables: {first_name}, {product_name}, {site_name}</p>
                </div>
                <div class="rtes-field">
                    <label>Heading</label>
                    <input type="text" name="review_heading" value="<?php echo esc_attr($settings['review_heading'] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Body Text</label>
                    <textarea name="review_body" rows="5"><?php echo esc_textarea($settings['review_body'] ?? ''); ?></textarea>
                </div>
                <div class="rtes-field">
                    <label>Button Text</label>
                    <input type="text" name="review_cta" value="<?php echo esc_attr($settings['review_cta'] ?? ''); ?>">
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Review Settings</button>
                <button type="button" class="rtes-btn secondary rtes-test-btn" data-type="review">Send Test Email</button>
            </div>
        </form>
    </div>

    <!-- ============ CROSS-SELL TAB ============ -->
    <div class="rtes-panel" id="panel-crosssell">
        <form id="rtes-form-crosssell" class="rtes-form">
            <div class="rtes-card">
                <div class="rtes-toggle-header">
                    <h3>Cross-Sell Recommendations</h3>
                    <label class="rtes-switch">
                        <input type="checkbox" name="crosssell_enabled" value="1" <?php checked(!empty($settings['crosssell_enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>
                <p class="rtes-desc">Suggest related products from the same category/team after purchase.</p>

                <div class="rtes-field-row">
                    <div class="rtes-field">
                        <label>Send After (days)</label>
                        <input type="number" name="crosssell_delay_days" value="<?php echo esc_attr($settings['crosssell_delay_days'] ?? 7); ?>" min="1" max="90">
                    </div>
                    <div class="rtes-field">
                        <label>Max Products to Show</label>
                        <input type="number" name="crosssell_max_products" value="<?php echo esc_attr($settings['crosssell_max_products'] ?? 4); ?>" min="2" max="8">
                    </div>
                </div>
                <div class="rtes-field">
                    <label>Subject Line</label>
                    <input type="text" name="crosssell_subject" value="<?php echo esc_attr($settings['crosssell_subject'] ?? ''); ?>">
                    <p class="rtes-hint">Variables: {first_name}, {product_name}, {site_name}</p>
                </div>
                <div class="rtes-field">
                    <label>Heading</label>
                    <input type="text" name="crosssell_heading" value="<?php echo esc_attr($settings['crosssell_heading'] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Body Text</label>
                    <textarea name="crosssell_body" rows="4"><?php echo esc_textarea($settings['crosssell_body'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Cross-Sell Settings</button>
                <button type="button" class="rtes-btn secondary rtes-test-btn" data-type="crosssell">Send Test Email</button>
            </div>
        </form>
    </div>

    <!-- ============ WELCOME EMAIL TAB ============ -->
    <div class="rtes-panel" id="panel-welcome">
        <form id="rtes-form-welcome" class="rtes-form">
            <div class="rtes-card">
                <div class="rtes-toggle-header">
                    <h3>Welcome Email</h3>
                    <label class="rtes-switch">
                        <input type="checkbox" name="welcome_enabled" value="1" <?php checked(!empty($settings['welcome_enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>
                <p class="rtes-desc">Greet new customers when they create an account.</p>

                <div class="rtes-field">
                    <label>Subject Line</label>
                    <input type="text" name="welcome_subject" value="<?php echo esc_attr($settings['welcome_subject'] ?? ''); ?>">
                    <p class="rtes-hint">Variables: {site_name}</p>
                </div>
                <div class="rtes-field">
                    <label>Heading</label>
                    <input type="text" name="welcome_heading" value="<?php echo esc_attr($settings['welcome_heading'] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Body Text</label>
                    <textarea name="welcome_body" rows="5"><?php echo esc_textarea($settings['welcome_body'] ?? ''); ?></textarea>
                </div>
                <div class="rtes-field">
                    <label>Button Text</label>
                    <input type="text" name="welcome_cta" value="<?php echo esc_attr($settings['welcome_cta'] ?? ''); ?>">
                </div>
                <div class="rtes-field">
                    <label>Welcome Coupon Code (optional)</label>
                    <input type="text" name="welcome_coupon" value="<?php echo esc_attr($settings['welcome_coupon'] ?? ''); ?>" placeholder="e.g. WELCOME10">
                    <p class="rtes-hint">Include a discount to incentivize their first purchase</p>
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Welcome Settings</button>
                <button type="button" class="rtes-btn secondary rtes-test-btn" data-type="welcome">Send Test Email</button>
            </div>
        </form>
    </div>

    <!-- ============ SETTINGS TAB ============ -->
    <div class="rtes-panel" id="panel-settings">
        <form id="rtes-form-settings" class="rtes-form">
            <div class="rtes-card">
                <h3>General Settings</h3>

                <div class="rtes-toggle-header" style="margin-bottom:20px;">
                    <span><strong>Enable Email Sequences</strong><br><small class="rtes-hint" style="display:inline;">Master switch for all automated emails</small></span>
                    <label class="rtes-switch">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                        <span class="rtes-slider"></span>
                    </label>
                </div>

                <div class="rtes-field-row">
                    <div class="rtes-field">
                        <label>From Name</label>
                        <input type="text" name="from_name" value="<?php echo esc_attr($settings['from_name'] ?? ''); ?>">
                    </div>
                    <div class="rtes-field">
                        <label>From Email</label>
                        <input type="email" name="from_email" value="<?php echo esc_attr($settings['from_email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="rtes-field-row">
                    <div class="rtes-field">
                        <label>Brand Color (Primary)</label>
                        <input type="color" name="brand_color" value="<?php echo esc_attr($settings['brand_color'] ?? '#A2755A'); ?>">
                    </div>
                    <div class="rtes-field">
                        <label>Brand Color (Secondary)</label>
                        <input type="color" name="brand_color_secondary" value="<?php echo esc_attr($settings['brand_color_secondary'] ?? '#e9e9e9'); ?>">
                    </div>
                </div>

                <div class="rtes-field">
                    <label>Logo URL (optional)</label>
                    <input type="url" name="logo_url" value="<?php echo esc_attr($settings['logo_url'] ?? ''); ?>" placeholder="https://yoursite.com/logo.png">
                    <p class="rtes-hint">Displayed in email header. Leave blank to show site name instead.</p>
                </div>
            </div>

            <div class="rtes-actions">
                <button type="submit" class="rtes-btn primary">Save Settings</button>
                <button type="button" class="rtes-btn small danger" id="rtes-reset-defaults" style="margin-left:12px;">Reset to Defaults</button>
            </div>
        </form>
    </div>

    <!-- ============ EMAIL LOG TAB ============ -->
    <div class="rtes-panel" id="panel-log">
        <div class="rtes-card">
            <div class="rtes-toggle-header">
                <h3>Email Log</h3>
                <div>
                    <button type="button" class="rtes-btn small secondary" id="rtes-refresh-log">Refresh</button>
                    <button type="button" class="rtes-btn small danger" id="rtes-clear-log">Clear Log</button>
                </div>
            </div>
            <table class="rtes-table" id="rtes-log-table">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th>Sent</th>
                        <th>Opened</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="5" class="rtes-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rtes-toast" id="rtes-toast"></div>
</div>
