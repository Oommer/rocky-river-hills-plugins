(function($) {
    'use strict';

    // ==================== UTILITIES ====================

    function showMsg(selector, text, type) {
        var $msg = $(selector);
        $msg.text(text).removeClass('success error').addClass(type).fadeIn();
        setTimeout(function() { $msg.fadeOut(); }, 3000);
    }

    function clearForm() {
        $('#rrh-event-id').val('');
        $('#rrh-event-date').val('');
        $('#rrh-event-name').val('');
        $('#rrh-event-location').val('');
        $('#rrh-event-directions').val('');
        $('#rrh-event-time').val('');
        $('#rrh-form-title').text('Add New Event');
        $('#rrh-event-save').text('Save Event');
        $('#rrh-event-clear').hide();
    }

    // ==================== TABS ====================

    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.rrh-tab-content').hide();
        $('#' + tab).show();
    });

    // ==================== EVENTS CRUD ====================

    $('#rrh-event-save').on('click', function() {
        var data = {
            action: 'rrh_events_save',
            nonce: rrhEvents.nonce,
            id: $('#rrh-event-id').val(),
            event_date: $('#rrh-event-date').val(),
            event_name: $('#rrh-event-name').val(),
            location: $('#rrh-event-location').val(),
            directions_address: $('#rrh-event-directions').val(),
            time_range: $('#rrh-event-time').val()
        };

        if (!data.event_date || !data.event_name || !data.location || !data.time_range) {
            showMsg('#rrh-event-msg', 'All fields except Directions Address are required.', 'error');
            return;
        }

        var $btn = $(this).prop('disabled', true).text('Saving...');

        $.post(rrhEvents.ajax_url, data, function(res) {
            if (res.success) {
                showMsg('#rrh-event-msg', res.data.message, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMsg('#rrh-event-msg', res.data || 'Error saving event.', 'error');
                $btn.prop('disabled', false).text('Save Event');
            }
        }).fail(function() {
            showMsg('#rrh-event-msg', 'Network error.', 'error');
            $btn.prop('disabled', false).text('Save Event');
        });
    });

    $(document).on('click', '.rrh-edit-event', function() {
        var $row = $(this).closest('tr');
        $('#rrh-event-id').val($row.data('id'));
        $('#rrh-event-date').val($row.data('date'));
        $('#rrh-event-name').val($row.data('name'));
        $('#rrh-event-location').val($row.data('location'));
        $('#rrh-event-directions').val($row.data('directions'));
        $('#rrh-event-time').val($row.data('time'));
        $('#rrh-form-title').text('Edit Event');
        $('#rrh-event-save').text('Update Event');
        $('#rrh-event-clear').show();
        $('html, body').animate({ scrollTop: 0 }, 300);
    });

    $('#rrh-event-clear').on('click', function() {
        clearForm();
    });

    $(document).on('click', '.rrh-delete-event', function() {
        var $row = $(this).closest('tr');
        var name = $row.data('name');
        if (!confirm('Delete "' + name + '"?')) return;

        $.post(rrhEvents.ajax_url, {
            action: 'rrh_events_delete',
            nonce: rrhEvents.nonce,
            id: $row.data('id')
        }, function(res) {
            if (res.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
                showMsg('#rrh-event-msg', res.data.message, 'success');
            } else {
                showMsg('#rrh-event-msg', 'Error deleting event.', 'error');
            }
        });
    });

    // ==================== LIVE PREVIEW ====================

    function updatePreview() {
        var $preview = $('#rrh-live-preview');
        if (!$preview.length) return;

        var vals = getStyleValues();

        // Container layout
        $preview.find('.rrh-events-container').css({
            'gap': vals.col_gap + 'px'
        });

        // Card layout
        $preview.find('.rrh-event-card').css({
            'min-width': vals.col_min_width + 'px',
            'max-width': vals.col_max_width + 'px',
            'padding': vals.col_padding + 'px'
        });

        // Typography
        $preview.find('.rrh-event-date').css({
            'font-size': vals.date_size + 'px',
            'color': vals.date_color,
            'font-weight': vals.date_weight
        });
        $preview.find('.rrh-event-name').css({
            'font-size': vals.name_size + 'px',
            'color': vals.name_color,
            'font-weight': vals.name_weight
        });
        $preview.find('.rrh-event-location').css({
            'font-size': vals.location_size + 'px',
            'color': vals.location_color,
            'font-weight': vals.location_weight
        });
        $preview.find('.rrh-event-directions-link').css({
            'color': vals.link_color
        });
        $preview.find('.rrh-event-time').css({
            'font-size': vals.time_size + 'px',
            'color': vals.time_color,
            'font-weight': vals.time_weight
        });
    }

    function getStyleValues() {
        return {
            date_size: $('#rrh-s-date_size').val(),
            date_color: $('#rrh-s-date_color').val(),
            date_weight: $('#rrh-s-date_weight').val(),
            name_size: $('#rrh-s-name_size').val(),
            name_color: $('#rrh-s-name_color').val(),
            name_weight: $('#rrh-s-name_weight').val(),
            location_size: $('#rrh-s-location_size').val(),
            location_color: $('#rrh-s-location_color').val(),
            location_weight: $('#rrh-s-location_weight').val(),
            time_size: $('#rrh-s-time_size').val(),
            time_color: $('#rrh-s-time_color').val(),
            time_weight: $('#rrh-s-time_weight').val(),
            link_color: $('#rrh-s-link_color').val(),
            mobile_date_size: $('#rrh-s-mobile_date_size').val(),
            events_count: $('#rrh-s-events_count').val(),
            col_min_width: $('#rrh-s-col_min_width').val(),
            col_max_width: $('#rrh-s-col_max_width').val(),
            col_gap: $('#rrh-s-col_gap').val(),
            col_padding: $('#rrh-s-col_padding').val()
        };
    }

    // Range slider live update
    $(document).on('input', '.rrh-range-input', function() {
        $(this).siblings('.rrh-range-value').text($(this).val() + 'px');
        updatePreview();
    });

    // Select live update
    $(document).on('change', '.rrh-style-select', function() {
        updatePreview();
    });

    // Color picker init with live preview
    $(document).ready(function() {
        $('.rrh-color-picker').wpColorPicker({
            change: function() {
                setTimeout(updatePreview, 50);
            },
            clear: function() {
                setTimeout(updatePreview, 50);
            }
        });
    });

    // ==================== SAVE STYLES ====================

    $('#rrh-styles-save').on('click', function() {
        var vals = getStyleValues();
        vals.action = 'rrh_events_save_styles';
        vals.nonce = rrhEvents.nonce;

        var $btn = $(this).prop('disabled', true).text('Saving...');

        $.post(rrhEvents.ajax_url, vals, function(res) {
            if (res.success) {
                showMsg('#rrh-styles-msg', res.data.message, 'success');
            } else {
                showMsg('#rrh-styles-msg', 'Error saving styles.', 'error');
            }
            $btn.prop('disabled', false).text('Save Styles');
        }).fail(function() {
            showMsg('#rrh-styles-msg', 'Network error.', 'error');
            $btn.prop('disabled', false).text('Save Styles');
        });
    });

    // ==================== RESET STYLES ====================

    $('#rrh-styles-reset').on('click', function() {
        if (!confirm('Reset all styles to defaults?')) return;

        var d = rrhEvents.defaults;

        // Set range inputs
        $.each(['date_size', 'name_size', 'location_size', 'time_size', 'mobile_date_size', 'col_min_width', 'col_max_width', 'col_gap', 'col_padding'], function(i, key) {
            $('#rrh-s-' + key).val(d[key]);
            $('#rrh-s-' + key).siblings('.rrh-range-value').text(d[key] + 'px');
        });

        // Set selects
        $.each(['date_weight', 'name_weight', 'location_weight', 'time_weight', 'events_count'], function(i, key) {
            $('#rrh-s-' + key).val(d[key]);
        });

        // Set color pickers
        $.each(['date_color', 'name_color', 'location_color', 'time_color', 'link_color'], function(i, key) {
            $('#rrh-s-' + key).wpColorPicker('color', d[key]);
        });

        updatePreview();
        showMsg('#rrh-styles-msg', 'Reset to defaults. Click Save Styles to apply.', 'success');
    });

})(jQuery);
