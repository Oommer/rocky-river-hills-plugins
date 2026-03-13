<?php if (!defined('ABSPATH')) exit; ?>
<div class="rtsm-wrap">
    <div class="rtsm-header">
        <h1>🔖 Schema Markup</h1>
        <p>Enhanced JSON-LD structured data for Google rich results</p>
        <span class="rtsm-badge <?php echo !empty($settings['enabled']) ? 'active' : 'inactive'; ?>">
            <?php echo !empty($settings['enabled']) ? '● Active' : '○ Inactive'; ?>
        </span>
    </div>

    <nav class="rtsm-tabs">
        <a href="#" class="rtsm-tab active" data-tab="overview">Overview</a>
        <a href="#" class="rtsm-tab" data-tab="business">Business Info</a>
        <a href="#" class="rtsm-tab" data-tab="product">Product</a>
        <a href="#" class="rtsm-tab" data-tab="social">Social</a>
        <a href="#" class="rtsm-tab" data-tab="test">Test</a>
    </nav>

    <!-- Overview -->
    <div class="rtsm-panel active" id="panel-overview">
        <div class="rtsm-section">
            <h3>Schema Types</h3>
            <p>Select which structured data types to output on your site.</p>

            <form id="form-overview">
                <div class="rtsm-checks">
                    <label class="rtsm-check">
                        <input type="checkbox" name="enabled" <?php checked(!empty($settings['enabled'])); ?>>
                        <div>
                            <strong>Enable Schema Markup</strong>
                            <span>Master switch for all structured data output</span>
                        </div>
                    </label>

                    <label class="rtsm-check">
                        <input type="checkbox" name="enable_product" <?php checked(!empty($settings['enable_product'])); ?>>
                        <div>
                            <strong>Product Schema</strong>
                            <span>Enhanced product data on single product pages — price, availability, reviews, brand, images, shipping, returns</span>
                        </div>
                    </label>

                    <label class="rtsm-check">
                        <input type="checkbox" name="enable_organization" <?php checked(!empty($settings['enable_organization'])); ?>>
                        <div>
                            <strong>Organization Schema</strong>
                            <span>Business info on all pages — name, logo, contact, social profiles</span>
                        </div>
                    </label>

                    <label class="rtsm-check">
                        <input type="checkbox" name="enable_website" <?php checked(!empty($settings['enable_website'])); ?>>
                        <div>
                            <strong>WebSite Schema</strong>
                            <span>Enables sitelinks search box in Google results (homepage only)</span>
                        </div>
                    </label>

                    <label class="rtsm-check">
                        <input type="checkbox" name="enable_breadcrumbs" <?php checked(!empty($settings['enable_breadcrumbs'])); ?>>
                        <div>
                            <strong>Breadcrumb Schema</strong>
                            <span>Shows breadcrumb navigation trail in Google search results</span>
                        </div>
                    </label>

                    <label class="rtsm-check">
                        <input type="checkbox" name="enable_local_business" <?php checked(!empty($settings['enable_local_business'])); ?>>
                        <div>
                            <strong>Local Business Schema</strong>
                            <span>For local search visibility — shows on homepage and about page</span>
                        </div>
                    </label>
                </div>

                <button type="submit" class="rtsm-btn primary">Save Settings</button>
            </form>
        </div>

        <div class="rtsm-section">
            <h3>What This Plugin Does</h3>
            <div class="rtsm-info">
                <p>This plugin replaces WooCommerce's default product schema with enhanced structured data that Google uses for <strong>rich results</strong> — those eye-catching search listings with prices, star ratings, availability badges, and breadcrumbs.</p>
                <p><strong>Rich results typically improve click-through rates by 20-30%</strong> compared to plain blue links.</p>
                <p>After saving your settings, test your pages with Google's tool:<br>
                <a href="https://search.google.com/test/rich-results" target="_blank">🔍 Google Rich Results Test</a></p>
            </div>
        </div>
    </div>

    <!-- Business Info -->
    <div class="rtsm-panel" id="panel-business">
        <form id="form-business">
            <div class="rtsm-section">
                <h3>Business Information</h3>

                <label>Organization Name</label>
                <input type="text" name="organization_name" value="<?php echo esc_attr($settings['organization_name'] ?? ''); ?>">

                <label>Website URL</label>
                <input type="text" name="organization_url" value="<?php echo esc_attr($settings['organization_url'] ?? ''); ?>">

                <label>Logo URL</label>
                <input type="text" name="organization_logo" value="<?php echo esc_attr($settings['organization_logo'] ?? ''); ?>">
                <p class="hint">Full URL to your logo image (e.g. https://rockyriverhills.com/wp-content/uploads/logo.png)</p>

                <label>Description</label>
                <textarea name="description" rows="3"><?php echo esc_textarea($settings['description'] ?? ''); ?></textarea>

                <label>Email</label>
                <input type="text" name="email" value="<?php echo esc_attr($settings['email'] ?? ''); ?>">

                <label>Phone</label>
                <input type="text" name="phone" value="<?php echo esc_attr($settings['phone'] ?? ''); ?>">
            </div>

            <div class="rtsm-section">
                <h3>Location</h3>
                <div class="rtsm-row3">
                    <div>
                        <label>City</label>
                        <input type="text" name="locality" value="<?php echo esc_attr($settings['locality'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>State</label>
                        <input type="text" name="region" value="<?php echo esc_attr($settings['region'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Country</label>
                        <input type="text" name="country" value="<?php echo esc_attr($settings['country'] ?? 'US'); ?>">
                    </div>
                </div>
                <label>Zip Code</label>
                <input type="text" name="postal_code" value="<?php echo esc_attr($settings['postal_code'] ?? ''); ?>">
            </div>

            <button type="submit" class="rtsm-btn primary">Save Business Info</button>
        </form>
    </div>

    <!-- Product -->
    <div class="rtsm-panel" id="panel-product">
        <form id="form-product">
            <div class="rtsm-section">
                <h3>Product Schema Settings</h3>

                <label>Brand Name</label>
                <input type="text" name="brand" value="<?php echo esc_attr($settings['brand'] ?? ''); ?>">
                <p class="hint">Applied as the brand for all products</p>

                <label>Product Material</label>
                <input type="text" name="product_material" value="<?php echo esc_attr($settings['product_material'] ?? ''); ?>">
                <p class="hint">Material description added to product schema (e.g. "Wood, Acrylic")</p>
            </div>

            <div class="rtsm-section">
                <h3>Included in Product Schema</h3>
                <p class="hint">The following data is automatically pulled from WooCommerce:</p>
                <ul class="rtsm-list">
                    <li>✅ Product name, description, URL</li>
                    <li>✅ All product images (featured + gallery)</li>
                    <li>✅ Price and sale price with date ranges</li>
                    <li>✅ Stock availability status</li>
                    <li>✅ SKU as MPN (manufacturer part number)</li>
                    <li>✅ Brand name</li>
                    <li>✅ Material</li>
                    <li>✅ Weight and dimensions</li>
                    <li>✅ Star ratings and review text</li>
                    <li>✅ Shipping details (handling + transit time)</li>
                    <li>✅ 30-day return policy</li>
                    <li>✅ Condition: New</li>
                    <li>✅ identifier_exists: no (handmade products)</li>
                </ul>
            </div>

            <button type="submit" class="rtsm-btn primary">Save Product Settings</button>
        </form>
    </div>

    <!-- Social -->
    <div class="rtsm-panel" id="panel-social">
        <form id="form-social">
            <div class="rtsm-section">
                <h3>Social Profiles</h3>
                <p class="hint">These are included in Organization schema as "sameAs" links, helping Google connect your social profiles to your business.</p>

                <label>Facebook Page URL</label>
                <input type="text" name="social_facebook" value="<?php echo esc_attr($settings['social_facebook'] ?? ''); ?>" placeholder="https://facebook.com/rockyriverhills">

                <label>Instagram Profile URL</label>
                <input type="text" name="social_instagram" value="<?php echo esc_attr($settings['social_instagram'] ?? ''); ?>" placeholder="https://instagram.com/rockyriverhills">

                <label>Pinterest Profile URL</label>
                <input type="text" name="social_pinterest" value="<?php echo esc_attr($settings['social_pinterest'] ?? ''); ?>" placeholder="https://pinterest.com/rockyriverhills">
            </div>

            <button type="submit" class="rtsm-btn primary">Save Social Profiles</button>
        </form>
    </div>

    <!-- Test -->
    <div class="rtsm-panel" id="panel-test">
        <div class="rtsm-section">
            <h3>Test Schema Output</h3>
            <p>Generate a sample of what your schema looks like for Google.</p>
            <button class="rtsm-btn primary" id="btn-test">🔍 Generate Test Output</button>
            <div id="test-results" style="display:none;"></div>
        </div>

        <div class="rtsm-section">
            <h3>Validate with Google</h3>
            <p>After saving your settings, test your product pages with Google's official tool to make sure everything is valid:</p>
            <a href="https://search.google.com/test/rich-results" target="_blank" class="rtsm-btn">🔗 Google Rich Results Test</a>
            <p class="hint" style="margin-top:12px;">Paste any product URL from your site and Google will show you exactly which rich results your page qualifies for.</p>
        </div>
    </div>
</div>
