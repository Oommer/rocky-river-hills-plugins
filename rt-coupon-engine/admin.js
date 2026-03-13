/* RT Dynamic Coupon Engine — Admin JS */
(function($) {
    'use strict';

    function toast(msg, err) {
        var $t = $('#rtce-toast').text(msg).removeClass('error');
        if (err) $t.addClass('error');
        $t.addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3500);
    }

    function ajax(action, data, cb) {
        data = data || {};
        data.action = action;
        data.nonce = rtce.nonce;
        $.post(rtce.ajax_url, data, cb).fail(function() { toast('Network error', true); });
    }

    // Tabs
    $(document).on('click', '.rtce-tab', function() {
        var tab = $(this).data('tab');
        $('.rtce-tab').removeClass('active').filter('[data-tab="'+tab+'"]').addClass('active');
        $('.rtce-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
        if (tab === 'promos') { loadPromos(); loadStats(); }
        if (tab === 'templates') renderTemplates();
    });

    /*--------------------------------------------------------------
    # Stats
    --------------------------------------------------------------*/
    function loadStats() {
        ajax('rtce_get_stats', {}, function(res) {
            if (!res.success) return;
            var s = res.data;
            $('#stat-active').text(s.active || 0);
            $('#stat-scheduled').text(s.scheduled || 0);
            $('#stat-total').text(s.total || 0);
            $('#stat-uses').text(s.total_uses || 0);
        });
    }

    /*--------------------------------------------------------------
    # Promo List
    --------------------------------------------------------------*/
    function loadPromos() {
        ajax('rtce_get_promos', {}, function(res) {
            if (!res.success) return;
            var $list = $('#rtce-promos-list').empty();

            if (!res.data || !res.data.length) {
                $list.html('<div class="rtce-empty-state"><h3>🎟️ No promotions yet</h3><p>Click "+ New Promotion" or use a template to get started.</p></div>');
                return;
            }

            $.each(res.data, function(i, p) {
                var disc = formatDiscount(p);
                var dates = '';
                if (p.start_date) dates += formatDate(p.start_date);
                if (p.start_date && p.end_date) dates += ' → ';
                if (p.end_date) dates += formatDate(p.end_date);

                $list.append(
                    '<div class="rtce-promo-item" data-id="' + p.id + '">' +
                    '<div class="rtce-promo-left">' +
                    '<p class="rtce-promo-name">' + esc(p.name) + '</p>' +
                    '<div class="rtce-promo-meta">' +
                    (p.coupon_code ? '<span class="rtce-promo-code">' + esc(p.coupon_code) + '</span>' : '') +
                    (dates ? '<span>' + dates + '</span>' : '') +
                    (p.auto_apply == 1 ? '<span>Auto-apply</span>' : '') +
                    '<span>' + (p.times_used || 0) + ' uses</span>' +
                    '</div></div>' +
                    '<div class="rtce-promo-right">' +
                    '<span class="rtce-promo-discount">' + disc + '</span>' +
                    '<span class="rtce-badge ' + p.status + '">' + p.status + '</span>' +
                    '<button class="rtce-btn small secondary rtce-edit-promo" data-id="' + p.id + '">Edit</button>' +
                    (p.status === 'active' ?
                        '<button class="rtce-btn small danger rtce-toggle-promo" data-id="' + p.id + '" data-status="draft">Pause</button>' :
                        (p.status !== 'expired' ? '<button class="rtce-btn small primary rtce-toggle-promo" data-id="' + p.id + '" data-status="active">Activate</button>' : '')
                    ) +
                    '</div></div>'
                );
            });
        });
    }

    function formatDiscount(p) {
        if (p.discount_type === 'percent') return p.discount_value + '%';
        if (p.discount_type === 'fixed_cart' || p.discount_type === 'fixed_product') return '$' + parseFloat(p.discount_value).toFixed(0);
        if (p.discount_type === 'free_shipping') return 'Free Ship';
        return '';
    }

    function formatDate(d) {
        if (!d) return '';
        var dt = new Date(d);
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function esc(s) { return $('<span>').text(s || '').html(); }

    // Toggle promo status
    $(document).on('click', '.rtce-toggle-promo', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var status = $(this).data('status');
        ajax('rtce_toggle_promo', { id: id, status: status }, function(res) {
            if (res.success) {
                toast(status === 'active' ? 'Promo activated!' : 'Promo paused');
                loadPromos();
                loadStats();
            }
        });
    });

    // Edit promo
    $(document).on('click', '.rtce-edit-promo', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        ajax('rtce_get_promo', { id: id }, function(res) {
            if (res.success) openEditor(res.data);
        });
    });

    /*--------------------------------------------------------------
    # Modal / Editor
    --------------------------------------------------------------*/
    function openEditor(data) {
        data = data || {};
        $('#rtce-modal-title').text(data.id ? 'Edit Promotion' : 'New Promotion');
        $('#promo-id').val(data.id || 0);

        // Fill fields
        var fields = ['name','description','discount_type','discount_value','coupon_code',
            'min_cart_total','max_uses','max_uses_per_user','apply_to','product_ids',
            'banner_text','banner_bg_color','banner_text_color'];

        $.each(fields, function(i, f) {
            var $el = $('#promo-' + f);
            if ($el.length) $el.val(data[f] || $el.prop('defaultValue') || '');
        });

        // Dates
        if (data.start_date) $('#promo-start_date').val(data.start_date.replace(' ', 'T').substring(0, 16));
        else $('#promo-start_date').val('');
        if (data.end_date) $('#promo-end_date').val(data.end_date.replace(' ', 'T').substring(0, 16));
        else $('#promo-end_date').val('');

        // Checkboxes
        $('#promo-first_time_only').prop('checked', data.first_time_only == 1);
        $('#promo-free_shipping').prop('checked', data.free_shipping == 1);
        $('#promo-exclude_sale_items').prop('checked', data.exclude_sale_items == 1);
        $('#promo-auto_apply').prop('checked', data.auto_apply == 1);
        $('#promo-show_banner').prop('checked', data.show_banner != 0);

        // Status
        if (data.status) $('#promo-status').val(data.status);
        else $('#promo-status').val('draft');

        // Colors
        $('#promo-banner_bg_color').val(data.banner_bg_color || '#A2755A');
        $('#promo-banner_text_color').val(data.banner_text_color || '#ffffff');

        // Categories
        renderCategoryCheckboxes(data.category_ids || '');

        // Targeting visibility
        toggleTargeting();
        toggleDiscountValue();
        updateBannerPreview();

        $('#rtce-modal').addClass('open');
    }

    function renderCategoryCheckboxes(selectedIds) {
        var ids = selectedIds ? selectedIds.split(',').map(function(s){return s.trim();}) : [];
        var $wrap = $('#promo-category-checkboxes').empty();
        $.each(rtce.categories, function(i, cat) {
            var checked = ids.indexOf(String(cat.id)) > -1 ? ' checked' : '';
            $wrap.append('<label><input type="checkbox" value="' + cat.id + '"' + checked + '> ' + esc(cat.name) + '</label>');
        });
    }

    function toggleTargeting() {
        var val = $('#promo-apply_to').val();
        $('#promo-categories-wrap').toggle(val === 'categories');
        $('#promo-products-wrap').toggle(val === 'products');
    }

    function toggleDiscountValue() {
        var type = $('#promo-discount_type').val();
        $('#promo-value-wrap').toggle(type !== 'free_shipping');
    }

    function updateBannerPreview() {
        var text = $('#promo-banner_text').val() || 'Banner preview...';
        var bg = $('#promo-banner_bg_color').val();
        var color = $('#promo-banner_text_color').val();
        var code = $('#promo-coupon_code').val() || 'CODE';
        var disc = '';
        var type = $('#promo-discount_type').val();
        var val = $('#promo-discount_value').val();
        if (type === 'percent') disc = val + '%';
        else if (type === 'fixed_cart' || type === 'fixed_product') disc = '$' + val;
        else disc = 'Free Shipping';

        text = text.replace('{code}', code).replace('{discount}', disc).replace('{end_date}', '');
        $('#rtce-banner-preview').css({ background: bg, color: color });
        $('#rtce-banner-preview-text').text(text);
    }

    $(document).on('change', '#promo-apply_to', toggleTargeting);
    $(document).on('change', '#promo-discount_type', toggleDiscountValue);
    $(document).on('input change', '#promo-banner_text, #promo-banner_bg_color, #promo-banner_text_color, #promo-coupon_code, #promo-discount_type, #promo-discount_value', updateBannerPreview);

    // New promo
    $(document).on('click', '#rtce-new-promo', function() { openEditor(); });

    // Close modal
    $(document).on('click', '#rtce-modal-close', function() { $('#rtce-modal').removeClass('open'); });
    $(document).on('click', '.rtce-modal-overlay', function(e) {
        if (e.target === this) $(this).removeClass('open');
    });

    // Save promo
    $(document).on('click', '#rtce-save-promo', function() {
        var $btn = $(this).text('Saving...').prop('disabled', true);

        // Gather category IDs
        var catIds = [];
        $('#promo-category-checkboxes input:checked').each(function() { catIds.push($(this).val()); });

        var data = {
            id: $('#promo-id').val(),
            name: $('#promo-name').val(),
            description: $('#promo-description').val(),
            discount_type: $('#promo-discount_type').val(),
            discount_value: $('#promo-discount_value').val(),
            coupon_code: $('#promo-coupon_code').val(),
            start_date: $('#promo-start_date').val() ? $('#promo-start_date').val().replace('T', ' ') + ':00' : '',
            end_date: $('#promo-end_date').val() ? $('#promo-end_date').val().replace('T', ' ') + ':00' : '',
            min_cart_total: $('#promo-min_cart_total').val(),
            max_uses: $('#promo-max_uses').val(),
            max_uses_per_user: $('#promo-max_uses_per_user').val(),
            first_time_only: $('#promo-first_time_only').is(':checked') ? 1 : 0,
            free_shipping: $('#promo-free_shipping').is(':checked') ? 1 : 0,
            exclude_sale_items: $('#promo-exclude_sale_items').is(':checked') ? 1 : 0,
            auto_apply: $('#promo-auto_apply').is(':checked') ? 1 : 0,
            apply_to: $('#promo-apply_to').val(),
            product_ids: $('#promo-product_ids').val(),
            category_ids: catIds.join(','),
            show_banner: $('#promo-show_banner').is(':checked') ? 1 : 0,
            banner_text: $('#promo-banner_text').val(),
            banner_bg_color: $('#promo-banner_bg_color').val(),
            banner_text_color: $('#promo-banner_text_color').val(),
            status: $('#promo-status').val(),
        };

        if (!data.name) {
            toast('Promotion name is required', true);
            $btn.text('Save Promotion').prop('disabled', false);
            return;
        }

        ajax('rtce_save_promo', data, function(res) {
            if (res.success) {
                toast(res.data.message || 'Saved!');
                $('#rtce-modal').removeClass('open');
                loadPromos();
                loadStats();
            } else {
                toast(res.data || 'Error saving', true);
            }
            $btn.text('Save Promotion').prop('disabled', false);
        });
    });

    /*--------------------------------------------------------------
    # Templates
    --------------------------------------------------------------*/
    function renderTemplates() {
        var $grid = $('#rtce-template-grid').empty();
        $.each(rtce.template_labels, function(key, label) {
            var icon = label.substring(0, 2);
            var name = label.substring(2).trim();
            $grid.append(
                '<div class="rtce-template-card" data-template="' + key + '">' +
                '<div class="rtce-template-icon">' + icon + '</div>' +
                '<div class="rtce-template-name">' + name + '</div>' +
                '</div>'
            );
        });
    }

    $(document).on('click', '.rtce-template-card', function() {
        var key = $(this).data('template');
        ajax('rtce_load_template', { template: key }, function(res) {
            if (res.success) {
                openEditor(res.data);
                // Switch to promos tab
                $('.rtce-tab').removeClass('active').filter('[data-tab="promos"]').addClass('active');
                $('.rtce-panel').removeClass('active');
                $('#panel-promos').addClass('active');
            }
        });
    });

    /*--------------------------------------------------------------
    # Settings
    --------------------------------------------------------------*/
    $(document).on('submit', '#rtce-form-settings', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.rtce-btn.primary');
        $btn.text('Saving...').prop('disabled', true);
        ajax('rtce_save_settings', { settings: $(this).serialize() }, function(res) {
            toast(res.success ? 'Settings saved!' : 'Error');
            $btn.text('Save Settings').prop('disabled', false);
        });
    });

    /*--------------------------------------------------------------
    # Init
    --------------------------------------------------------------*/
    loadPromos();
    loadStats();
    renderTemplates();

})(jQuery);
