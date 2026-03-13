<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;
$has_woo = class_exists('WooCommerce');
?>
<div class="wrap rrh-ig-wrap">
    <h1>📦 Bulk Post Products</h1>

    <?php if (!$has_woo): ?>
    <div class="notice notice-warning"><p>WooCommerce is required for bulk posting.</p></div>
    <?php return; endif; ?>

    <div class="rrh-ig-bulk-grid">
        <!-- Settings Panel -->
        <div class="rrh-ig-card">
            <h2>Bulk Settings</h2>

            <div class="rrh-ig-field">
                <label>Caption Template</label>
                <select id="rrh-bulk-template" class="regular-text">
                    <option value="0">— Default template —</option>
                </select>
            </div>

            <div class="rrh-ig-field">
                <label>Post Interval</label>
                <select id="rrh-bulk-interval" class="regular-text">
                    <option value="1">Every 1 hour</option>
                    <option value="2">Every 2 hours</option>
                    <option value="4" selected>Every 4 hours</option>
                    <option value="8">Every 8 hours</option>
                    <option value="12">Every 12 hours</option>
                    <option value="24">Every 24 hours</option>
                </select>
            </div>

            <div class="rrh-ig-field">
                <label>Start Date/Time <span style="color:#666; font-weight:normal;">(<?php echo esc_html(wp_timezone_string()); ?>)</span></label>
                <input type="datetime-local" id="rrh-bulk-start" class="regular-text">
            </div>

            <div class="rrh-ig-field">
                <label>
                    <input type="checkbox" id="rrh-bulk-carousel" value="1" checked>
                    Use carousel (include gallery images) when available
                </label>
            </div>

            <div class="rrh-ig-field">
                <button type="button" id="rrh-bulk-queue-btn" class="button button-primary button-large" disabled>
                    📋 Queue Selected (<span id="rrh-bulk-count">0</span>) Products
                </button>
            </div>

            <div id="rrh-bulk-result" class="rrh-ig-result" style="display:none;"></div>
        </div>

        <!-- Product Grid -->
        <div class="rrh-ig-card">
            <h2>Select Products</h2>
            <div style="margin-bottom: 12px;">
                <input type="text" id="rrh-bulk-search" class="regular-text" placeholder="Search products...">
                <button type="button" id="rrh-bulk-select-all" class="button">Select All</button>
                <button type="button" id="rrh-bulk-select-none" class="button">Select None</button>
            </div>

            <div id="rrh-bulk-products" class="rrh-ig-product-grid">
                <p style="color:#666; text-align:center; padding:20px;">Loading products...</p>
            </div>
        </div>
    </div>
</div>
