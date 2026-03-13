/* RT Meta Shopping — Admin JS */
(function($){
    'use strict';

    /* --- Tabs --- */
    $(document).on('click', '.rtms-tab', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.rtms-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtms-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
    });

    /* --- Save forms (content, filters, utm, settings) --- */
    $(document).on('submit', '#form-content, #form-filters, #form-utm, #form-settings', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.rtms-btn.primary');
        var orig = $btn.text();
        $btn.text('Saving…').prop('disabled', true);

        // Merge all forms so settings stay complete
        var all = {};
        $('#form-content, #form-filters, #form-utm, #form-settings').each(function() {
            var parts = $(this).serialize().split('&');
            parts.forEach(function(p) {
                var kv = p.split('=');
                all[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
            });
        });

        // Re-check toggles from all forms
        $('input[type="checkbox"]').each(function() {
            if (!this.checked) {
                all[this.name] = '';
            }
        });

        $.post(rtms.ajax_url, {
            action: 'rtms_save_settings',
            nonce: rtms.nonce,
            settings: $.param(all)
        }, function(r) {
            $btn.text(r.success ? '✓ Saved!' : '✗ Error').prop('disabled', false);
            setTimeout(function(){ $btn.text(orig); }, 2000);
        });
    });

    /* --- Regenerate --- */
    $(document).on('click', '#btn-regenerate', function() {
        var $btn = $(this);
        $btn.text('Generating…').prop('disabled', true);
        $('#feed-result').show().removeClass('success error').html('Working…');

        $.post(rtms.ajax_url, {
            action: 'rtms_regenerate_feed',
            nonce: rtms.nonce
        }, function(r) {
            if (r.success) {
                var d = r.data;
                $('#feed-result').addClass('success').html(
                    d.message + ' — <strong>' + d.product_count + ' products</strong>'
                );
                $('#stat-products').text(d.product_count);
                $('#stat-last').text(d.last_generated);
                refreshStats();
            } else {
                $('#feed-result').addClass('error').text(r.data || 'Failed');
            }
            $btn.text('🔄 Regenerate Feed Now').prop('disabled', false);
        });
    });

    /* --- Diagnose --- */
    $(document).on('click', '#btn-diagnose', function() {
        var $btn = $(this);
        $btn.text('Diagnosing…').prop('disabled', true);
        $('#diagnose-result').show().removeClass('success error').html('Running diagnostics…');

        $.post(rtms.ajax_url, {
            action: 'rtms_diagnose_feed',
            nonce: rtms.nonce
        }, function(r) {
            if (r.success) {
                $('#diagnose-result').addClass('success').html('<pre>' + r.data.report + '</pre>');
            } else {
                $('#diagnose-result').addClass('error').text(r.data || 'Diagnostic failed');
            }
            $btn.text('🔍 Diagnose Feed').prop('disabled', false);
        });
    });

    /* --- Copy URL --- */
    $(document).on('click', '#btn-copy-url', function() {
        var url = $(this).data('url');
        navigator.clipboard.writeText(url).then(function() {
            $('#btn-copy-url').text('✓ Copied!');
            setTimeout(function(){ $('#btn-copy-url').text('📋 Copy Feed URL'); }, 2000);
        });
    });

    /* --- Preview --- */
    $(document).on('click', '#btn-preview', function() {
        var $btn = $(this);
        $btn.text('Loading…').prop('disabled', true);

        $.post(rtms.ajax_url, {
            action: 'rtms_preview_feed',
            nonce: rtms.nonce
        }, function(r) {
            if (r.success) {
                var escaped = r.data.xml.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                $('#preview-content').show().html(escaped);
            } else {
                $('#preview-content').show().text(r.data || 'Could not load preview');
            }
            $btn.text('Load Preview').prop('disabled', false);
        });
    });

    /* --- Refresh Stats --- */
    function refreshStats() {
        $.post(rtms.ajax_url, {
            action: 'rtms_get_stats',
            nonce: rtms.nonce
        }, function(r) {
            if (r.success) {
                var d = r.data;
                $('#stat-products').text(d.product_count);
                $('#stat-size').text(d.feed_size);
                $('#stat-last').text(d.last_generated);
                $('#stat-fetches').text(d.total_fetches);
            }
        });
    }

})(jQuery);
