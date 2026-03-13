<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtgs-wrap">
    <div class="rtgs-header">
        <div>
            <h1>🛒 Google Shopping Feed</h1>
            <p class="rtgs-subtitle">Auto-generate a product feed for Google Merchant Center</p>
        </div>
        <span class="rtgs-status <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
            <?php echo !empty($settings['enabled']) ? '● Feed Active' : '○ Feed Off'; ?>
        </span>
    </div>

    <div class="rtgs-tabs">
        <button class="rtgs-tab active" data-tab="dashboard">Dashboard</button>
        <button class="rtgs-tab" data-tab="content">Content</button>
        <button class="rtgs-tab" data-tab="filters">Filters</button>
        <button class="rtgs-tab" data-tab="tracking">UTM & Labels</button>
        <button class="rtgs-tab" data-tab="shipping">Shipping & Tax</button>
        <button class="rtgs-tab" data-tab="settings">Settings</button>
        <button class="rtgs-tab" data-tab="preview">Preview</button>
    </div>

    <!-- ============ DASHBOARD ============ -->
    <div class="rtgs-panel active" id="panel-dashboard">
        <div class="rtgs-stats-grid">
            <div class="rtgs-stat-card accent">
                <div class="rtgs-stat-icon">📦</div>
                <div class="rtgs-stat-num" id="stat-products"><?php echo esc_html($settings['product_count'] ?? 0); ?></div>
                <div class="rtgs-stat-label">Products in Feed</div>
            </div>
            <div class="rtgs-stat-card">
                <div class="rtgs-stat-icon">📄</div>
                <div class="rtgs-stat-num" id="stat-size"><?php echo esc_html($feed_size); ?></div>
                <div class="rtgs-stat-label">Feed Size</div>
            </div>
            <div class="rtgs-stat-card">
                <div class="rtgs-stat-icon">🔄</div>
                <div class="rtgs-stat-num" id="stat-fetches">—</div>
                <div class="rtgs-stat-label">Google Fetches</div>
            </div>
            <div class="rtgs-stat-card">
                <div class="rtgs-stat-icon">🕐</div>
                <div class="rtgs-stat-num rtgs-stat-num-sm" id="stat-generated"><?php echo esc_html($settings['last_generated'] ?: 'Never'); ?></div>
                <div class="rtgs-stat-label">Last Generated</div>
            </div>
        </div>

        <div class="rtgs-card">
            <h3>Feed URL</h3>
            <p class="rtgs-hint" style="margin-bottom:12px;">Submit this URL to Google Merchant Center: Settings → Data Sources → Add product source → Add products from a file → enter this link.</p>
            <div class="rtgs-url-box">
                <code id="rtgs-feed-url"><?php echo esc_url(home_url('/feed/google-shopping/')); ?></code>
                <button type="button" class="rtgs-btn small secondary" id="rtgs-copy-url">Copy</button>
            </div>
            <p class="rtgs-hint" style="margin-top:8px;">Alt URL: <code><?php echo esc_url(home_url('/google-shopping-feed.xml')); ?></code></p>
        </div>

        <div class="rtgs-card">
            <div class="rtgs-card-header">
                <h3>Feed Actions</h3>
            </div>
            <div class="rtgs-actions" style="margin-top:0;">
                <button type="button" class="rtgs-btn primary" id="rtgs-regenerate">🔄 Regenerate Feed Now</button>
                <a href="<?php echo esc_url(home_url('/feed/google-shopping/')); ?>" target="_blank" class="rtgs-btn secondary">View Feed XML →</a>
                <button type="button" class="rtgs-btn secondary" id="rtgs-diagnose">🔍 Diagnose Feed</button>
            </div>
            <p class="rtgs-hint" style="margin-top:12px;" id="rtgs-regen-status"></p>
            <pre id="rtgs-diagnose-output" style="display:none;background:#f8f6f3;border:1px solid #ddd;padding:14px 18px;border-radius:6px;font-size:13px;line-height:1.7;white-space:pre-wrap;margin-top:12px;max-height:500px;overflow-y:auto;"></pre>
        </div>

        <div class="rtgs-card">
            <h3>Quick Setup Guide</h3>
            <div class="rtgs-setup-steps">
                <div class="rtgs-step <?php echo !empty($settings['enabled']) ? 'done' : ''; ?>">
                    <span class="rtgs-step-num">1</span>
                    <div>
                        <strong>Enable the feed</strong>
                        <p>Turn on the feed in Settings tab</p>
                    </div>
                </div>
                <div class="rtgs-step <?php echo $feed_exists ? 'done' : ''; ?>">
                    <span class="rtgs-step-num">2</span>
                    <div>
                        <strong>Generate the feed</strong>
                        <p>Click "Regenerate Feed Now" above</p>
                    </div>
                </div>
                <div class="rtgs-step">
                    <span class="rtgs-step-num">3</span>
                    <div>
                        <strong>Create a Merchant Center account</strong>
                        <p>Go to <a href="https://accounts.google.com/AccountChooser?continue=https://merchants.google.com" target="_blank">merchants.google.com</a> and sign up</p>
                    </div>
                </div>
                <div class="rtgs-step">
                    <span class="rtgs-step-num">4</span>
                    <div>
                        <strong>Submit your feed URL</strong>
                        <p>Settings → Data Sources → Add product source → "Add products from a file" → enter a link to your file → paste the URL above</p>
                    </div>
                </div>
                <div class="rtgs-step">
                    <span class="rtgs-step-num">5</span>
                    <div>
                        <strong>Enable free listings</strong>
                        <p>In Merchant Center: Marketing → Marketing methods → enable Free listings</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ CONTENT ============ -->
    <div class="rtgs-panel" id="panel-content">
        <form id="rtgs-form-content" class="rtgs-form">
            <div class="rtgs-card">
                <h3>Product Content</h3>
                <div class="rtgs-field">
                    <label>Description Source</label>
                    <select name="description_source">
                        <option value="short" <?php selected(($settings['description_source'] ?? 'short'), 'short'); ?>>Short Description (recommended)</option>
                        <option value="full" <?php selected(($settings['description_source'] ?? ''), 'full'); ?>>Full Description</option>
                    </select>
                    <p class="rtgs-hint">Falls back to full description if short is empty</p>
                </div>
                <div class="rtgs-field">
                    <label>Brand Name</label>
                    <input type="text" name="brand" value="<?php echo esc_attr($settings['brand'] ?? ''); ?>">
                    <p class="rtgs-hint">Applied to all products. Usually your store name.</p>
                </div>
                <div class="rtgs-field">
                    <label>Product Condition</label>
                    <select name="condition">
                        <option value="new" <?php selected(($settings['condition'] ?? 'new'), 'new'); ?>>New</option>
                        <option value="refurbished" <?php selected(($settings['condition'] ?? ''), 'refurbished'); ?>>Refurbished</option>
                        <option value="used" <?php selected(($settings['condition'] ?? ''), 'used'); ?>>Used</option>
                    </select>
                </div>
            </div>

            <div class="rtgs-card">
                <h3>Google Product Category</h3>
                <div class="rtgs-field">
                    <label>Default Category</label>
                    <input type="text" name="default_google_category" value="<?php echo esc_attr($settings['default_google_category'] ?? ''); ?>">
                    <p class="rtgs-hint">Google's taxonomy path, e.g. "Home & Garden > Decor" — <a href="https://support.google.com/merchants/answer/6324436" target="_blank">Browse categories</a></p>
                </div>
                <div class="rtgs-field">
                    <label>Category ID (optional)</label>
                    <input type="text" name="google_category_id" value="<?php echo esc_attr($settings['google_category_id'] ?? ''); ?>">
                    <p class="rtgs-hint">Numeric ID from Google's taxonomy. You can use either the path or the ID.</p>
                </div>
            </div>

            <div class="rtgs-card">
                <h3>Product Identifiers</h3>
                <p class="rtgs-hint" style="margin-bottom:16px;">For custom/handmade products without barcodes, set "Identifier Exists" to No. Google will still list them.</p>
                <div class="rtgs-field">
                    <label>Identifier Exists</label>
                    <select name="identifier_exists">
                        <option value="no" <?php selected(($settings['identifier_exists'] ?? 'no'), 'no'); ?>>No (custom/handmade products)</option>
                        <option value="yes" <?php selected(($settings['identifier_exists'] ?? ''), 'yes'); ?>>Yes (has GTIN/UPC/EAN)</option>
                    </select>
                </div>
                <div class="rtgs-field-row">
                    <div class="rtgs-field">
                        <label>GTIN Meta Field (optional)</label>
                        <input type="text" name="gtin_field" value="<?php echo esc_attr($settings['gtin_field'] ?? ''); ?>" placeholder="_gtin">
                        <p class="rtgs-hint">Custom field key storing GTIN/UPC/EAN</p>
                    </div>
                    <div class="rtgs-field">
                        <label>MPN Meta Field (optional)</label>
                        <input type="text" name="mpn_field" value="<?php echo esc_attr($settings['mpn_field'] ?? ''); ?>" placeholder="_mpn">
                        <p class="rtgs-hint">Falls back to SKU if empty</p>
                    </div>
                </div>
            </div>

            <div class="rtgs-actions">
                <button type="submit" class="rtgs-btn primary">Save Content Settings</button>
            </div>
        </form>
    </div>

    <!-- ============ FILTERS ============ -->
    <div class="rtgs-panel" id="panel-filters">
        <form id="rtgs-form-filters" class="rtgs-form">
            <div class="rtgs-card">
                <h3>Product Filters</h3>
                <div class="rtgs-check-row">
                    <label><input type="checkbox" name="exclude_out_of_stock" value="1" <?php checked(!empty($settings['exclude_out_of_stock'])); ?>> Exclude out-of-stock products</label>
                </div>
                <div class="rtgs-check-row">
                    <label><input type="checkbox" name="include_variations" value="1" <?php checked(!empty($settings['include_variations'])); ?>> Include individual variations (variable products)</label>
                </div>
                <div class="rtgs-field">
                    <label>Minimum Price ($)</label>
                    <input type="number" name="min_price" value="<?php echo esc_attr($settings['min_price'] ?? ''); ?>" step="0.01" min="0" placeholder="No minimum">
                    <p class="rtgs-hint">Exclude products below this price</p>
                </div>
                <div class="rtgs-field">
                    <label>Max Products</label>
                    <input type="number" name="max_products" value="<?php echo esc_attr($settings['max_products'] ?? 0); ?>" min="0">
                    <p class="rtgs-hint">0 = include all products</p>
                </div>
                <div class="rtgs-field">
                    <label>Exclude Categories (IDs, comma-separated)</label>
                    <input type="text" name="exclude_categories" value="<?php echo esc_attr($settings['exclude_categories'] ?? ''); ?>" placeholder="e.g. 15,22,48">
                    <p class="rtgs-hint">
                        Your categories:
                        <?php foreach ($categories as $cat): ?>
                            <span class="rtgs-cat-tag"><?php echo esc_html($cat->name); ?> (<?php echo $cat->term_id; ?>)</span>
                        <?php endforeach; ?>
                    </p>
                </div>
            </div>

            <div class="rtgs-card">
                <h3>Availability Mapping</h3>
                <p class="rtgs-hint" style="margin-bottom:16px;">Map WooCommerce stock statuses to Google Shopping values.</p>
                <div class="rtgs-field-row">
                    <div class="rtgs-field">
                        <label>In Stock →</label>
                        <select name="availability_in_stock">
                            <option value="in_stock" <?php selected(($settings['availability_in_stock'] ?? 'in_stock'), 'in_stock'); ?>>in_stock</option>
                            <option value="out_of_stock" <?php selected(($settings['availability_in_stock'] ?? ''), 'out_of_stock'); ?>>out_of_stock</option>
                            <option value="preorder" <?php selected(($settings['availability_in_stock'] ?? ''), 'preorder'); ?>>preorder</option>
                        </select>
                    </div>
                    <div class="rtgs-field">
                        <label>Out of Stock →</label>
                        <select name="availability_out_of_stock">
                            <option value="out_of_stock" <?php selected(($settings['availability_out_of_stock'] ?? 'out_of_stock'), 'out_of_stock'); ?>>out_of_stock</option>
                            <option value="in_stock" <?php selected(($settings['availability_out_of_stock'] ?? ''), 'in_stock'); ?>>in_stock</option>
                        </select>
                    </div>
                    <div class="rtgs-field">
                        <label>Backorder →</label>
                        <select name="availability_backorder">
                            <option value="backorder" <?php selected(($settings['availability_backorder'] ?? 'backorder'), 'backorder'); ?>>backorder</option>
                            <option value="in_stock" <?php selected(($settings['availability_backorder'] ?? ''), 'in_stock'); ?>>in_stock</option>
                            <option value="preorder" <?php selected(($settings['availability_backorder'] ?? ''), 'preorder'); ?>>preorder</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="rtgs-actions">
                <button type="submit" class="rtgs-btn primary">Save Filter Settings</button>
            </div>
        </form>
    </div>

    <!-- ============ UTM & LABELS ============ -->
    <div class="rtgs-panel" id="panel-tracking">
        <form id="rtgs-form-tracking" class="rtgs-form">
            <div class="rtgs-card">
                <h3>UTM Tracking</h3>
                <p class="rtgs-hint" style="margin-bottom:16px;">Added to product URLs so you can track Google Shopping traffic in your analytics.</p>
                <div class="rtgs-field-row">
                    <div class="rtgs-field">
                        <label>utm_source</label>
                        <input type="text" name="utm_source" value="<?php echo esc_attr($settings['utm_source'] ?? 'google'); ?>">
                    </div>
                    <div class="rtgs-field">
                        <label>utm_medium</label>
                        <input type="text" name="utm_medium" value="<?php echo esc_attr($settings['utm_medium'] ?? 'shopping'); ?>">
                    </div>
                    <div class="rtgs-field">
                        <label>utm_campaign</label>
                        <input type="text" name="utm_campaign" value="<?php echo esc_attr($settings['utm_campaign'] ?? 'merchant_center'); ?>">
                    </div>
                </div>
            </div>

            <div class="rtgs-card">
                <h3>Custom Labels</h3>
                <p class="rtgs-hint" style="margin-bottom:16px;">Custom labels help you organize products for bidding in Google Ads. Use: <code>category</code>, <code>price_range</code>, <code>on_sale</code>, <code>tag:TagName</code>, <code>meta:field_name</code>, or a static string.</p>
                <?php for ($i = 0; $i <= 4; $i++): ?>
                <div class="rtgs-field">
                    <label>custom_label_<?php echo $i; ?></label>
                    <input type="text" name="custom_label_<?php echo $i; ?>" value="<?php echo esc_attr($settings["custom_label_{$i}"] ?? ''); ?>" placeholder="e.g. category, price_range, on_sale">
                </div>
                <?php endfor; ?>
            </div>

            <div class="rtgs-actions">
                <button type="submit" class="rtgs-btn primary">Save Tracking Settings</button>
            </div>
        </form>
    </div>

    <!-- ============ SHIPPING & TAX ============ -->
    <div class="rtgs-panel" id="panel-shipping">
        <form id="rtgs-form-shipping" class="rtgs-form">
            <div class="rtgs-card">
                <h3>Shipping Override</h3>
                <p class="rtgs-hint" style="margin-bottom:16px;">Set a flat shipping rate in the feed. Leave price blank to let Google use your Merchant Center shipping settings instead.</p>
                <div class="rtgs-field-row">
                    <div class="rtgs-field">
                        <label>Country</label>
                        <input type="text" name="shipping_country" value="<?php echo esc_attr($settings['shipping_country'] ?? 'US'); ?>" maxlength="2">
                    </div>
                    <div class="rtgs-field">
                        <label>Flat Shipping Price ($)</label>
                        <input type="number" name="shipping_price" value="<?php echo esc_attr($settings['shipping_price'] ?? ''); ?>" step="0.01" min="0" placeholder="Leave blank for MC settings">
                    </div>
                    <div class="rtgs-field">
                        <label>Service Name (optional)</label>
                        <input type="text" name="shipping_label" value="<?php echo esc_attr($settings['shipping_label'] ?? ''); ?>" placeholder="e.g. Standard">
                    </div>
                </div>
            </div>

            <div class="rtgs-card">
                <h3>Tax</h3>
                <div class="rtgs-check-row">
                    <label><input type="checkbox" name="include_tax" value="1" <?php checked(!empty($settings['include_tax'])); ?>> Include tax in product prices</label>
                </div>
                <p class="rtgs-hint">For US sellers, it's recommended to NOT include tax and let Google handle it via Merchant Center settings.</p>
            </div>

            <div class="rtgs-actions">
                <button type="submit" class="rtgs-btn primary">Save Shipping Settings</button>
            </div>
        </form>
    </div>

    <!-- ============ SETTINGS ============ -->
    <div class="rtgs-panel" id="panel-settings">
        <form id="rtgs-form-settings" class="rtgs-form">
            <div class="rtgs-card">
                <h3>General Settings</h3>
                <div class="rtgs-toggle-header" style="margin-bottom:20px;">
                    <span><strong>Enable Feed</strong><br><small class="rtgs-hint">Master switch — disabling returns 404 for the feed URL</small></span>
                    <label class="rtgs-switch"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>><span class="rtgs-slider"></span></label>
                </div>
                <div class="rtgs-field-row">
                    <div class="rtgs-field">
                        <label>Store Name</label>
                        <input type="text" name="store_name" value="<?php echo esc_attr($settings['store_name'] ?? ''); ?>">
                    </div>
                    <div class="rtgs-field">
                        <label>Store URL</label>
                        <input type="url" name="store_url" value="<?php echo esc_attr($settings['store_url'] ?? ''); ?>">
                    </div>
                </div>
                <div class="rtgs-field">
                    <label>Feed Filename</label>
                    <input type="text" name="feed_filename" value="<?php echo esc_attr($settings['feed_filename'] ?? 'google-shopping-feed.xml'); ?>">
                </div>
                <div class="rtgs-field">
                    <label>Auto-Regenerate</label>
                    <select name="auto_regenerate">
                        <option value="hourly" <?php selected(($settings['auto_regenerate'] ?? 'daily'), 'hourly'); ?>>Every Hour</option>
                        <option value="twicedaily" <?php selected(($settings['auto_regenerate'] ?? ''), 'twicedaily'); ?>>Twice Daily</option>
                        <option value="daily" <?php selected(($settings['auto_regenerate'] ?? 'daily'), 'daily'); ?>>Daily (recommended)</option>
                        <option value="manual" <?php selected(($settings['auto_regenerate'] ?? ''), 'manual'); ?>>Manual Only</option>
                    </select>
                    <p class="rtgs-hint">Feed also regenerates automatically 5 minutes after any product is created/updated/deleted.</p>
                </div>
            </div>

            <div class="rtgs-actions">
                <button type="submit" class="rtgs-btn primary">Save Settings</button>
            </div>
        </form>
    </div>

    <!-- ============ PREVIEW ============ -->
    <div class="rtgs-panel" id="panel-preview">
        <div class="rtgs-card">
            <div class="rtgs-card-header">
                <h3>Feed Preview</h3>
                <button type="button" class="rtgs-btn small secondary" id="rtgs-load-preview">Load Preview</button>
            </div>
            <pre class="rtgs-code-preview" id="rtgs-preview-content"><span class="rtgs-muted">Click "Load Preview" to view the feed XML</span></pre>
        </div>
    </div>

    <div class="rtgs-toast" id="rtgs-toast"></div>
</div>
