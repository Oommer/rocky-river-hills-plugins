<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtms-wrap">
    <div class="rtms-header">
        <h1>📱 Meta Shopping Feed</h1>
        <p>Auto-generate a product feed for Facebook & Instagram Shops</p>
        <span class="rtms-badge <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
            <?php echo !empty($settings['enabled']) ? '● Feed Active' : '○ Feed Inactive'; ?>
        </span>
    </div>

    <nav class="rtms-tabs">
        <a href="#dashboard" class="rtms-tab active" data-tab="dashboard">Dashboard</a>
        <a href="#content" class="rtms-tab" data-tab="content">Content</a>
        <a href="#filters" class="rtms-tab" data-tab="filters">Filters</a>
        <a href="#utm" class="rtms-tab" data-tab="utm">UTM & Labels</a>
        <a href="#settings" class="rtms-tab" data-tab="settings">Settings</a>
        <a href="#preview" class="rtms-tab" data-tab="preview">Preview</a>
    </nav>

    <!-- Dashboard -->
    <div class="rtms-panel active" id="panel-dashboard">
        <div class="rtms-cards">
            <div class="rtms-card">
                <h3>Products in Feed</h3>
                <div class="rtms-stat" id="stat-products"><?php echo intval($settings['product_count'] ?? 0); ?></div>
            </div>
            <div class="rtms-card">
                <h3>Feed Size</h3>
                <div class="rtms-stat" id="stat-size"><?php echo esc_html($feed_size); ?></div>
            </div>
            <div class="rtms-card">
                <h3>Last Generated</h3>
                <div class="rtms-stat rtms-stat-sm" id="stat-last"><?php echo esc_html($settings['last_generated'] ?: 'Never'); ?></div>
            </div>
            <div class="rtms-card">
                <h3>Meta Fetches (30d)</h3>
                <div class="rtms-stat" id="stat-fetches"><?php echo intval(get_transient('rtms_fetch_count')); ?></div>
            </div>
        </div>

        <div class="rtms-section">
            <h3>Feed Actions</h3>
            <div class="rtms-actions">
                <button class="rtms-btn primary" id="btn-regenerate">🔄 Regenerate Feed Now</button>
                <button class="rtms-btn" id="btn-diagnose">🔍 Diagnose Feed</button>
                <button class="rtms-btn" id="btn-copy-url" data-url="<?php echo esc_attr(home_url('/feed/meta-shopping/')); ?>">📋 Copy Feed URL</button>
                <a class="rtms-btn" href="<?php echo esc_url(home_url('/feed/meta-shopping/')); ?>" target="_blank">🔗 View Feed XML</a>
            </div>
            <div id="feed-result" class="rtms-result" style="display:none;"></div>
            <div id="diagnose-result" class="rtms-result" style="display:none;"></div>
        </div>

        <div class="rtms-section">
            <h3>Setup Guide</h3>
            <div class="rtms-steps">
                <div class="rtms-step">
                    <span class="step-num">1</span>
                    <div>
                        <strong>Install & Generate</strong>
                        <p>Click "Regenerate Feed Now" above. Your feed URL is:<br>
                        <code id="feed-url"><?php echo esc_html(home_url('/feed/meta-shopping/')); ?></code></p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">2</span>
                    <div>
                        <strong>Open Meta Commerce Manager</strong>
                        <p>Go to <a href="https://business.facebook.com/commerce/" target="_blank">business.facebook.com/commerce</a> and select your business.</p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">3</span>
                    <div>
                        <strong>Create a Catalog</strong>
                        <p>In Commerce Manager, click <strong>+ Add Catalog</strong> → <strong>E-commerce</strong> → name it "Rocky River Hills Products" → click <strong>Create</strong>.</p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">4</span>
                    <div>
                        <strong>Add Product Data Source</strong>
                        <p>Click <strong>Data Sources</strong> in the left sidebar → <strong>Data Feed</strong> → select <strong>Use a URL</strong> → paste your feed URL → set schedule to <strong>Daily</strong> → click <strong>Start Upload</strong>.</p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">5</span>
                    <div>
                        <strong>Set Up Checkout URL</strong>
                        <p>When Meta asks for your checkout URL, enter:<br>
                        <code><?php echo esc_html(home_url('/?meta_checkout=1&products={product_id}&coupon={coupon_code}')); ?></code><br>
                        This plugin automatically handles the URL — it adds products to the WooCommerce cart and redirects customers to your checkout page.</p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">6</span>
                    <div>
                        <strong>Connect Instagram & Submit for Review</strong>
                        <p>In Commerce Manager → <strong>Settings</strong> → <strong>Business Assets</strong> → connect your Instagram Business account and Facebook Page. Then click <strong>Shops</strong> in the left sidebar → <strong>Create a Shop</strong> → choose "Checkout on your website" → select your catalog → <strong>Submit for Review</strong>. Review typically takes 1-3 business days.</p>
                    </div>
                </div>
                <div class="rtms-step">
                    <span class="step-num">7</span>
                    <div>
                        <strong>Tag Products in Posts</strong>
                        <p>Once approved, create a post or reel → tap <strong>Tag Products</strong> → select items from your catalog. You can also use the <strong>Shopping sticker</strong> in Stories. Your Shop tab will appear on your Instagram profile with your full catalog.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="rtms-panel" id="panel-content">
        <form id="form-content">
            <div class="rtms-section">
                <h3>Product Content</h3>

                <label>Description Source</label>
                <select name="description_source">
                    <option value="short" <?php selected(($settings['description_source'] ?? 'short'), 'short'); ?>>Short Description (recommended)</option>
                    <option value="full" <?php selected(($settings['description_source'] ?? ''), 'full'); ?>>Full Description</option>
                </select>
                <p class="hint">Falls back to full description if short is empty</p>

                <label>Brand Name</label>
                <input type="text" name="brand" value="<?php echo esc_attr($settings['brand'] ?? ''); ?>">
                <p class="hint">Applied to all products. Usually your store name.</p>

                <label>Product Condition</label>
                <select name="condition">
                    <option value="new" <?php selected(($settings['condition'] ?? 'new'), 'new'); ?>>New</option>
                    <option value="refurbished" <?php selected(($settings['condition'] ?? ''), 'refurbished'); ?>>Refurbished</option>
                    <option value="used" <?php selected(($settings['condition'] ?? ''), 'used'); ?>>Used</option>
                </select>
            </div>

            <div class="rtms-section">
                <h3>Google Product Category</h3>
                <label>Default Category</label>
                <input type="text" name="default_google_category" value="<?php echo esc_attr($settings['default_google_category'] ?? ''); ?>">
                <p class="hint">Google's taxonomy path, e.g. "Home & Garden > Decor" — <a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt" target="_blank">Browse categories</a></p>

                <label>Category ID (optional)</label>
                <input type="text" name="google_category_id" value="<?php echo esc_attr($settings['google_category_id'] ?? ''); ?>">
                <p class="hint">Numeric ID from Google's taxonomy. Meta accepts Google's category system.</p>
            </div>

            <div class="rtms-section">
                <h3>Product Identifiers</h3>
                <p class="hint">For custom/handmade products without barcodes, set "Identifier Exists" to No. Meta will still list them.</p>
                <label>Identifier Exists</label>
                <select name="identifier_exists">
                    <option value="no" <?php selected(($settings['identifier_exists'] ?? 'no'), 'no'); ?>>No (custom/handmade products)</option>
                    <option value="yes" <?php selected(($settings['identifier_exists'] ?? ''), 'yes'); ?>>Yes</option>
                </select>
            </div>

            <button type="submit" class="rtms-btn primary">Save Content Settings</button>
        </form>
    </div>

    <!-- Filters -->
    <div class="rtms-panel" id="panel-filters">
        <form id="form-filters">
            <div class="rtms-section">
                <h3>Product Filters</h3>

                <label class="rtms-toggle">
                    <input type="checkbox" name="exclude_out_of_stock" <?php checked(!empty($settings['exclude_out_of_stock'])); ?>>
                    Exclude out-of-stock products
                </label>

                <label class="rtms-toggle">
                    <input type="checkbox" name="include_variations" <?php checked(!empty($settings['include_variations'])); ?>>
                    Include individual variations (variable products)
                </label>

                <label>Minimum Price ($)</label>
                <input type="number" name="min_price" value="<?php echo esc_attr($settings['min_price'] ?? ''); ?>" placeholder="No minimum" min="0" step="0.01">
                <p class="hint">Exclude products below this price</p>

                <label>Max Products</label>
                <input type="number" name="max_products" value="<?php echo esc_attr($settings['max_products'] ?? 0); ?>" min="0">
                <p class="hint">0 = include all products</p>

                <label>Exclude Categories (IDs, comma-separated)</label>
                <input type="text" name="exclude_categories" value="<?php echo esc_attr($settings['exclude_categories'] ?? ''); ?>" placeholder="e.g. 15,22,48">
                <div class="rtms-cats">
                    Your categories:
                    <?php foreach ($categories as $cat) : ?>
                        <span class="rtms-cat-tag"><?php echo esc_html($cat->name); ?> (<?php echo $cat->term_id; ?>)</span>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="rtms-btn primary">Save Filter Settings</button>
        </form>
    </div>

    <!-- UTM & Labels -->
    <div class="rtms-panel" id="panel-utm">
        <form id="form-utm">
            <div class="rtms-section">
                <h3>UTM Tracking</h3>
                <p class="hint">Added to product URLs so you can track Meta Shopping traffic in your analytics.</p>
                <div class="rtms-row3">
                    <div>
                        <label>utm_source</label>
                        <input type="text" name="utm_source" value="<?php echo esc_attr($settings['utm_source'] ?? 'facebook'); ?>">
                    </div>
                    <div>
                        <label>utm_medium</label>
                        <input type="text" name="utm_medium" value="<?php echo esc_attr($settings['utm_medium'] ?? 'shopping'); ?>">
                    </div>
                    <div>
                        <label>utm_campaign</label>
                        <input type="text" name="utm_campaign" value="<?php echo esc_attr($settings['utm_campaign'] ?? 'meta_commerce'); ?>">
                    </div>
                </div>
            </div>

            <div class="rtms-section">
                <h3>Custom Labels</h3>
                <p class="hint">Custom labels help organize products for bidding in Meta Ads. Use:
                    <code>category</code>, <code>price_range</code>, <code>on_sale</code>, <code>tag:TagName</code>, <code>meta:field_name</code>, or a static string.</p>

                <?php for ($i = 0; $i <= 4; $i++) : ?>
                <label>custom_label_<?php echo $i; ?></label>
                <input type="text" name="custom_label_<?php echo $i; ?>" value="<?php echo esc_attr($settings["custom_label_{$i}"] ?? ''); ?>" placeholder="e.g. category, price_range, on_sale">
                <?php endfor; ?>
            </div>

            <button type="submit" class="rtms-btn primary">Save UTM & Label Settings</button>
        </form>
    </div>

    <!-- Settings -->
    <div class="rtms-panel" id="panel-settings">
        <form id="form-settings">
            <div class="rtms-section">
                <h3>General Settings</h3>

                <label class="rtms-toggle">
                    Enable Feed
                    <input type="checkbox" name="enabled" <?php checked(!empty($settings['enabled'])); ?>>
                    <p class="hint">Master switch — disabling returns 404 for the feed URL</p>
                </label>

                <div class="rtms-row2">
                    <div>
                        <label>Store Name</label>
                        <input type="text" name="store_name" value="<?php echo esc_attr($settings['store_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Store URL</label>
                        <input type="text" name="store_url" value="<?php echo esc_attr($settings['store_url'] ?? ''); ?>">
                    </div>
                </div>

                <label>Feed Filename</label>
                <input type="text" name="feed_filename" value="<?php echo esc_attr($settings['feed_filename'] ?? 'meta-shopping-feed.xml'); ?>">

                <label>Auto-Regenerate</label>
                <select name="auto_regenerate">
                    <option value="daily" <?php selected(($settings['auto_regenerate'] ?? 'daily'), 'daily'); ?>>Daily (recommended)</option>
                    <option value="twicedaily" <?php selected(($settings['auto_regenerate'] ?? ''), 'twicedaily'); ?>>Twice Daily</option>
                    <option value="hourly" <?php selected(($settings['auto_regenerate'] ?? ''), 'hourly'); ?>>Hourly</option>
                    <option value="manual" <?php selected(($settings['auto_regenerate'] ?? ''), 'manual'); ?>>Manual Only</option>
                </select>
                <p class="hint">Feed also regenerates automatically 5 minutes after any product is created/updated/deleted.</p>
            </div>

            <button type="submit" class="rtms-btn primary">Save Settings</button>
        </form>
    </div>

    <!-- Preview -->
    <div class="rtms-panel" id="panel-preview">
        <div class="rtms-section">
            <h3>Feed Preview</h3>
            <button class="rtms-btn" id="btn-preview">Load Preview</button>
            <pre id="preview-content" class="rtms-preview" style="display:none;"></pre>
        </div>
    </div>
</div>
