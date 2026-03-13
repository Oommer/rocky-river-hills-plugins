<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

$message = '';
$msg_type = '';

if (isset($_POST['rrh_ig_compose_submit']) && wp_verify_nonce($_POST['_wpnonce'], 'rrh_ig_compose')) {
    $plugin = RRH_Instagram_Poster::instance();
    $data = [
        'post_type' => 'image', // will be auto-detected below
        'caption' => sanitize_textarea_field(wp_unslash($_POST['caption'])),
        'media_url' => esc_url_raw($_POST['media_url']),
        'media_urls' => isset($_POST['media_urls']) ? sanitize_textarea_field($_POST['media_urls']) : null,
        'media_source' => sanitize_text_field($_POST['media_source']),
        'woo_product_id' => intval($_POST['woo_product_id'] ?? 0),
        'status' => 'queued', 'scheduled_at' => '',
    ];

    // Auto-detect carousel: check submitted media_urls first
    if ($data['media_urls']) {
        $urls = json_decode($data['media_urls'], true);
        if (is_array($urls) && count($urls) > 1) {
            $data['post_type'] = 'carousel';
        }
    }
    // If product selected but no media_urls, grab gallery server-side
    elseif ($data['woo_product_id'] && class_exists('WooCommerce')) {
        $wc = wc_get_product($data['woo_product_id']);
        if ($wc) {
            $gallery_ids = $wc->get_gallery_image_ids();
            $gallery = array_filter(array_map([RRH_Instagram_Poster::class, 'get_proxy_image_url'], $gallery_ids));
            if (!empty($gallery)) {
                $featured = RRH_Instagram_Poster::get_proxy_image_url($wc->get_image_id());
                $all = array_merge([$featured], array_values(array_slice($gallery, 0, 9)));
                if (count($all) > 1) {
                    $data['post_type'] = 'carousel';
                    $data['media_url'] = $featured;
                    $data['media_urls'] = json_encode($all);
                }
            }
        }
    }

    if (!empty($_POST['scheduled_date'])) {
        $data['scheduled_at'] = sanitize_text_field($_POST['scheduled_date']);
    }

    if (empty($data['media_url'])) { $message = 'Media required.'; $msg_type = 'error'; }
    elseif (empty($data['caption'])) { $message = 'Caption required.'; $msg_type = 'error'; }
    else {
        // Auto-replace template variables if a product is selected
        if ($data['woo_product_id'] && strpos($data['caption'], '{') !== false) {
            $data['caption'] = RRH_IG_Publisher::generate_product_caption($data['woo_product_id'], $data['caption']);
        }

        $post_id = $plugin->create_post($data);
        if (is_wp_error($post_id)) { $message = $post_id->get_error_message(); $msg_type = 'error'; }
        elseif ($_POST['publish_action'] === 'now') {
            $r = $plugin->get_publisher()->publish_post($post_id);
            if ($r['success']) { $message = 'Published! <a href="'.esc_url($r['permalink']).'" target="_blank">View →</a>'; $msg_type = 'success'; }
            else { $message = 'Failed: ' . esc_html($r['error']); $msg_type = 'error'; }
        } else {
            $message = 'Added to queue!' . ($data['scheduled_at'] ? ' Scheduled for ' . esc_html($data['scheduled_at']) : '');
            $msg_type = 'success';
        }
    }
}

$has_woo = class_exists('WooCommerce');
?>
<div class="wrap rrh-ig-wrap">
    <h1>📸 Compose Instagram Post <small style="font-size:12px;color:#999;">v<?php echo RRH_IG_VERSION; ?></small></h1>

    <?php if ($message): ?>
        <div class="notice notice-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo wp_kses($message, ['a' => ['href' => [], 'target' => []]]); ?></p>
        </div>
    <?php endif; ?>

    <div class="rrh-ig-composer-grid">
        <div class="rrh-ig-card rrh-ig-compose-form">
            <form method="post" id="rrh-ig-compose-form">
                <?php wp_nonce_field('rrh_ig_compose'); ?>

                <!-- Post Type: auto-detected (carousel when gallery exists, image otherwise) -->
                <input type="hidden" name="post_type" value="auto">

                <!-- Media Source -->
                <div class="rrh-ig-field">
                    <label>Media Source</label>
                    <div class="rrh-ig-radio-group">
                        <?php if ($has_woo): ?>
                        <label class="rrh-ig-radio active"><input type="radio" name="media_source" value="woocommerce" checked> 🛍️ Product</label>
                        <?php endif; ?>
                        <label class="rrh-ig-radio <?php echo !$has_woo ? 'active' : ''; ?>"><input type="radio" name="media_source" value="upload" <?php echo !$has_woo ? 'checked' : ''; ?>> 📁 Upload</label>
                        <label class="rrh-ig-radio"><input type="radio" name="media_source" value="url"> 🔗 URL</label>
                    </div>
                </div>

                <!-- WooCommerce Product -->
                <?php if ($has_woo): ?>
                <div class="rrh-ig-field" id="rrh-ig-woo-section">
                    <label>Select Product</label>
                    <input type="text" id="rrh-ig-product-search" class="regular-text" placeholder="Search products...">
                    <input type="hidden" name="woo_product_id" id="rrh-ig-product-id" value="">
                    <div id="rrh-ig-product-results" class="rrh-ig-dropdown"></div>
                    <div id="rrh-ig-selected-product" class="rrh-ig-selected-product" style="display:none;"></div>
                </div>
                <?php endif; ?>

                <!-- Upload -->
                <div class="rrh-ig-field" id="rrh-ig-upload-section" style="<?php echo $has_woo ? 'display:none' : ''; ?>">
                    <label>Select Media</label>
                    <div class="rrh-ig-upload-area">
                        <button type="button" id="rrh-ig-upload-btn" class="button">📂 Choose from Media Library</button>
                        <p class="description" id="rrh-ig-carousel-hint" style="display:none;">💡 For carousel: hold Ctrl/Cmd to select multiple images</p>
                        <div id="rrh-ig-upload-preview" class="rrh-ig-preview"></div>
                    </div>
                </div>

                <!-- URL -->
                <div class="rrh-ig-field" id="rrh-ig-url-section" style="display:none;">
                    <label>Media URL</label>
                    <input type="url" id="rrh-ig-media-url-input" class="large-text" placeholder="https://...">
                </div>

                <input type="hidden" name="media_url" id="rrh-ig-media-url" value="">
                <input type="hidden" name="media_urls" id="rrh-ig-media-urls" value="">

                <!-- Caption Template Selector -->
                <div class="rrh-ig-field">
                    <label>Caption Template</label>
                    <select id="rrh-ig-template-select" class="regular-text">
                        <option value="">— Write custom —</option>
                    </select>
                </div>

                <!-- Caption -->
                <div class="rrh-ig-field">
                    <label for="rrh-ig-caption">Caption</label>
                    <textarea name="caption" id="rrh-ig-caption" rows="6" class="large-text" placeholder="Write your caption..."></textarea>
                    <div class="rrh-ig-caption-meta">
                        <span id="rrh-ig-char-count">0 / 2,200</span>
                        <span id="rrh-ig-hashtag-count">0 / 30 hashtags</span>
                    </div>
                    <?php if ($has_woo): ?>
                    <button type="button" id="rrh-ig-generate-caption" class="button" style="margin-top:8px;">✨ Generate from Product</button>
                    <?php endif; ?>
                </div>

                <!-- Smart Hashtags -->
                <div class="rrh-ig-field">
                    <label>Hashtags</label>
                    <button type="button" id="rrh-ig-smart-hashtags" class="button button-primary" style="margin-bottom:8px;">🏷️ Add Smart Hashtags</button>
                    <p class="description">Combines your brand tags + category tags + product-specific city/team tags. Select a product first for full effect.</p>
                    <div id="rrh-ig-hashtag-preview" style="display:none; margin-top:8px; padding:10px; background:#f0f6fc; border:1px solid #c3c4c7; border-radius:4px; font-size:12px;"></div>
                </div>

                <!-- Publish -->
                <div class="rrh-ig-field rrh-ig-publish-actions">
                    <input type="hidden" name="publish_action" id="rrh-ig-publish-action" value="queue">
                    <div id="rrh-ig-schedule-section" style="display:none;">
                        <label>Schedule For <span style="color:#666; font-weight:normal;">(<?php echo esc_html(wp_timezone_string()); ?>)</span></label>
                        <input type="datetime-local" name="scheduled_date" id="rrh-ig-scheduled-date" class="regular-text">
                    </div>
                    <div class="rrh-ig-button-group">
                        <button type="submit" name="rrh_ig_compose_submit" value="1" class="button button-primary button-large" onclick="document.getElementById('rrh-ig-publish-action').value='now';">🚀 Publish Now</button>
                        <button type="submit" name="rrh_ig_compose_submit" value="1" class="button button-large" id="rrh-ig-btn-queue">📋 Add to Queue</button>
                        <button type="button" class="button button-large" id="rrh-ig-btn-schedule-toggle">⏰ Schedule</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Preview -->
        <div class="rrh-ig-card rrh-ig-preview-card">
            <h2>Preview</h2>
            <div class="rrh-ig-phone-preview">
                <div class="rrh-ig-phone-header"><strong>rockyriverhills</strong></div>
                <div class="rrh-ig-phone-image" id="rrh-ig-preview-image">
                    <span class="placeholder-text">Select media to preview</span>
                </div>
                <div class="rrh-ig-phone-caption" id="rrh-ig-preview-caption">
                    <strong>rockyriverhills</strong> <span>Caption preview...</span>
                </div>
            </div>
        </div>
    </div>
</div>
