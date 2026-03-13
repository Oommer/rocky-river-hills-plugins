<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;
if (!class_exists('WooCommerce')) { echo '<div class="wrap"><p>WooCommerce required.</p></div>'; return; }

// Get all products grouped by category
$categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name']);
$uncategorized = [];

$all_products = get_posts([
    'post_type' => 'product', 'post_status' => 'publish',
    'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
]);

// Build category → products map
$cat_products = [];
$product_cats = [];
foreach ($all_products as $p) {
    $cats = wp_get_post_terms($p->ID, 'product_cat', ['fields' => 'ids']);
    $product_cats[$p->ID] = $cats;
    if (empty($cats)) {
        $uncategorized[] = $p;
    } else {
        foreach ($cats as $cid) {
            $cat_products[$cid][] = $p;
        }
    }
}

$filled = 0;
$total = count($all_products);
foreach ($all_products as $p) {
    if (get_post_meta($p->ID, '_rrh_ig_hashtags', true)) $filled++;
}
?>
<div class="wrap rrh-ig-wrap">
    <h1>🏷️ Product Hashtags (Bulk Editor)</h1>
    <p>Add city/team hashtags to each product. These combine with your brand tags and category tags when posting.</p>

    <div style="display:flex; align-items:center; gap:16px; margin-bottom:16px;">
        <div class="rrh-ig-stat-card" style="display:inline-block; padding:12px 20px;">
            <strong><?php echo $filled; ?></strong> / <?php echo $total; ?> products tagged
        </div>
        <input type="text" id="rrh-ph-search" class="regular-text" placeholder="🔍 Filter products..." style="height:36px;">
        <button type="button" id="rrh-ph-save-all" class="button button-primary button-large">💾 Save All</button>
        <span id="rrh-ph-save-result" style="color:#28a745; font-weight:600;"></span>
    </div>

    <?php foreach ($categories as $cat):
        if (empty($cat_products[$cat->term_id])) continue;
        // Deduplicate — only show product under its most specific category
    ?>
    <div class="rrh-ig-card rrh-ph-category-block" data-category="<?php echo esc_attr(strtolower($cat->name)); ?>">
        <h2 style="cursor:pointer;" class="rrh-ph-toggle">
            <?php echo esc_html($cat->name); ?>
            <span style="color:#666; font-weight:normal; font-size:14px;">(<?php echo count($cat_products[$cat->term_id]); ?>)</span>
            <span class="rrh-ph-arrow" style="float:right;">▼</span>
        </h2>
        <table class="widefat rrh-ph-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:50px;"></th>
                    <th style="width:250px;">Product</th>
                    <th>Instagram Hashtags (city/team/nickname)</th>
                    <th style="width:80px;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cat_products[$cat->term_id] as $product):
                $wc = wc_get_product($product->ID);
                $thumb = wp_get_attachment_url($wc->get_image_id());
                $existing = get_post_meta($product->ID, '_rrh_ig_hashtags', true);
            ?>
                <tr class="rrh-ph-row" data-title="<?php echo esc_attr(strtolower($product->post_title)); ?>">
                    <td>
                        <?php if ($thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" alt="" loading="lazy">
                        <?php else: ?>
                        <div style="width:40px; height:40px; background:#eee; border-radius:4px;"></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo esc_html($product->post_title); ?></strong></td>
                    <td>
                        <input type="text"
                               class="large-text rrh-ph-input"
                               data-product-id="<?php echo $product->ID; ?>"
                               value="<?php echo esc_attr($existing); ?>"
                               placeholder="#city #team #nickname"
                               style="font-size:13px;">
                    </td>
                    <td class="rrh-ph-status">
                        <?php if ($existing): ?>
                            <span style="color:#28a745;">✅</span>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:16px;">
        <button type="button" id="rrh-ph-save-all-bottom" class="button button-primary button-large">💾 Save All</button>
        <span id="rrh-ph-save-result-bottom" style="color:#28a745; font-weight:600; margin-left:12px;"></span>
    </div>
</div>

<script>
jQuery(function($) {
    // Toggle categories
    $('.rrh-ph-toggle').on('click', function() {
        $(this).next('table').slideToggle(200);
        var $arrow = $(this).find('.rrh-ph-arrow');
        $arrow.text($arrow.text() === '▼' ? '▲' : '▼');
    });

    // Filter/search
    $('#rrh-ph-search').on('input', function() {
        var q = $(this).val().toLowerCase();
        if (!q) {
            $('.rrh-ph-category-block, .rrh-ph-row').show();
            return;
        }
        $('.rrh-ph-row').each(function() {
            var match = $(this).data('title').indexOf(q) !== -1;
            $(this).toggle(match);
        });
        $('.rrh-ph-category-block').each(function() {
            var catMatch = $(this).data('category').indexOf(q) !== -1;
            var hasVisibleRows = $(this).find('.rrh-ph-row:visible').length > 0;
            $(this).toggle(catMatch || hasVisibleRows);
            if (catMatch) $(this).find('.rrh-ph-row').show();
        });
    });

    // Mark changed
    $('.rrh-ph-input').on('input', function() {
        $(this).css('border-color', '#2271b1');
    });

    // Save all
    $('#rrh-ph-save-all, #rrh-ph-save-all-bottom').on('click', function() {
        var $btn = $(this);
        var data = { product_hashtags: {} };

        $('.rrh-ph-input').each(function() {
            data.product_hashtags[$(this).data('product-id')] = $(this).val();
        });

        data.action = 'rrh_ig_save_product_hashtags';
        data.nonce = rrhIG.nonce;

        $btn.prop('disabled', true).text('Saving...');
        $.post(rrhIG.ajax_url, data, function(r) {
            $btn.prop('disabled', false).text('💾 Save All');
            if (r.success) {
                $('#rrh-ph-save-result, #rrh-ph-save-result-bottom').text('✅ Saved ' + r.data.saved + ' products!').show();
                setTimeout(function() { $('#rrh-ph-save-result, #rrh-ph-save-result-bottom').fadeOut(); }, 3000);

                // Update status indicators
                $('.rrh-ph-input').each(function() {
                    $(this).css('border-color', '');
                    var $status = $(this).closest('tr').find('.rrh-ph-status');
                    $status.html($(this).val() ? '<span style="color:#28a745;">✅</span>' : '<span style="color:#999;">—</span>');
                });
            } else {
                alert('Error: ' + r.data);
            }
        });
    });
});
</script>
