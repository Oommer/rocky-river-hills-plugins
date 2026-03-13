/* RT Google Shopping Feed — Admin JS */
(function($) {
    'use strict';

    // Tabs
    $(document).on('click', '.rtgs-tab', function() {
        var tab = $(this).data('tab');
        $('.rtgs-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtgs-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
        if (tab === 'dashboard') loadStats();
    });

    function toast(msg, isError) {
        var $t = $('#rtgs-toast');
        $t.text(msg).removeClass('error');
        if (isError) $t.addClass('error');
        $t.addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3000);
    }

    // Save settings
    $(document).on('submit', '.rtgs-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.rtgs-btn.primary');
        var orig = $btn.text();
        $btn.text('Saving...').prop('disabled', true);

        $.post(rtgs.ajax_url, {
            action: 'rtgs_save_settings',
            nonce: rtgs.nonce,
            settings: $form.serialize()
        }, function(res) {
            toast(res.success ? 'Settings saved!' : 'Error saving');
            $btn.text(orig).prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text(orig).prop('disabled', false);
        });
    });

    // Regenerate feed
    $(document).on('click', '#rtgs-regenerate', function() {
        var $btn = $(this);
        $btn.text('Generating...').prop('disabled', true);
        $('#rtgs-regen-status').text('');

        $.post(rtgs.ajax_url, {
            action: 'rtgs_regenerate_feed',
            nonce: rtgs.nonce
        }, function(res) {
            if (res.success) {
                toast(res.data.message || 'Feed generated!');
                $('#stat-products').text(res.data.product_count || 0);
                $('#stat-generated').text(res.data.last_generated || 'Just now');
                $('#rtgs-regen-status').text('✅ ' + (res.data.message || 'Feed regenerated') + ' — ' + res.data.product_count + ' products');
                loadStats();
            } else {
                toast(res.data || 'Generation failed', true);
                $('#rtgs-regen-status').text('❌ Feed generation failed');
            }
            $btn.text('🔄 Regenerate Feed Now').prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text('🔄 Regenerate Feed Now').prop('disabled', false);
        });
    });

    // Copy URL
    $(document).on('click', '#rtgs-copy-url', function() {
        var url = $('#rtgs-feed-url').text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
                toast('Feed URL copied!');
            });
        } else {
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(url).select();
            document.execCommand('copy');
            $temp.remove();
            toast('Feed URL copied!');
        }
    });

    // Load stats
    function loadStats() {
        $.post(rtgs.ajax_url, {
            action: 'rtgs_get_stats',
            nonce: rtgs.nonce
        }, function(res) {
            if (!res.success) return;
            var s = res.data;
            $('#stat-products').text(s.product_count || 0);
            $('#stat-size').text(s.feed_size || '—');
            $('#stat-fetches').text(s.total_fetches || 0);
            $('#stat-generated').text(s.last_generated || 'Never');
        });
    }

    // Preview feed
    $(document).on('click', '#rtgs-load-preview', function() {
        var $btn = $(this);
        $btn.text('Loading...').prop('disabled', true);

        $.post(rtgs.ajax_url, {
            action: 'rtgs_preview_feed',
            nonce: rtgs.nonce
        }, function(res) {
            if (res.success) {
                // Syntax highlight XML slightly
                var xml = res.data.xml || '';
                xml = xml.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                $('#rtgs-preview-content').html(xml);
            } else {
                $('#rtgs-preview-content').html('<span class="rtgs-muted">' + (res.data || 'No feed found') + '</span>');
            }
            $btn.text('Load Preview').prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text('Load Preview').prop('disabled', false);
        });
    });

    // Initial load
    loadStats();

    // Diagnose feed
    $(document).on('click', '#rtgs-diagnose', function() {
        var $btn = $(this);
        var $output = $('#rtgs-diagnose-output');
        $btn.text('🔍 Running...').prop('disabled', true);
        $output.show().text('Running diagnostics...');

        $.post(rtgs.ajax_url, {
            action: 'rtgs_diagnose_feed',
            nonce: rtgs.nonce
        }, function(res) {
            if (res.success) {
                $output.text(res.data.report);
            } else {
                $output.text('Diagnostic failed: ' + (res.data || 'Unknown error'));
            }
            $btn.text('🔍 Diagnose Feed').prop('disabled', false);
        }).fail(function() {
            $output.text('Network error — could not run diagnostics.');
            $btn.text('🔍 Diagnose Feed').prop('disabled', false);
        });
    });

})(jQuery);
