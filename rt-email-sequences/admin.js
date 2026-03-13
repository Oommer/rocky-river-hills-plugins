/* RT Email Sequences — Admin JS */
(function($) {
    'use strict';

    // Tabs
    $(document).on('click', '.rtes-tab', function() {
        var tab = $(this).data('tab');
        $('.rtes-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtes-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');

        // Load stats when switching to dashboard or log
        if (tab === 'dashboard' || tab === 'log') {
            loadStats();
        }
    });

    // Toast notification
    function toast(msg, isError) {
        var $t = $('#rtes-toast');
        $t.text(msg).removeClass('error');
        if (isError) $t.addClass('error');
        $t.addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3000);
    }

    // Save settings
    $(document).on('submit', '.rtes-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.rtes-btn.primary');
        var origText = $btn.text();
        $btn.text('Saving...').prop('disabled', true);

        $.post(rtes.ajax_url, {
            action: 'rtes_save_settings',
            nonce: rtes.nonce,
            settings: $form.serialize()
        }, function(res) {
            if (res.success) {
                toast('Settings saved!');
            } else {
                toast('Error saving settings', true);
            }
            $btn.text(origText).prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text(origText).prop('disabled', false);
        });
    });

    // Test emails
    $(document).on('click', '.rtes-test-btn', function() {
        var type = $(this).data('type');
        var $btn = $(this);
        var origText = $btn.text();

        var email = prompt('Send test email to:', '');
        if (!email) return;

        $btn.text('Sending...').prop('disabled', true);

        $.post(rtes.ajax_url, {
            action: 'rtes_send_test',
            nonce: rtes.nonce,
            type: type,
            email: email
        }, function(res) {
            if (res.success) {
                toast(res.data || 'Test email sent!');
            } else {
                toast(res.data || 'Failed to send', true);
            }
            $btn.text(origText).prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text(origText).prop('disabled', false);
        });
    });

    // Load stats
    function loadStats() {
        $.post(rtes.ajax_url, {
            action: 'rtes_get_stats',
            nonce: rtes.nonce
        }, function(res) {
            if (!res.success) return;
            var s = res.data;

            // Dashboard numbers
            $('#stat-total-sent').text(s.total_sent);
            $('#stat-total-opened').text(s.total_opened);
            $('#stat-abandoned').text(s.abandoned_carts);
            $('#stat-recovered').text(s.recovered_carts);
            $('#stat-revenue').text('$' + parseFloat(s.recovered_revenue || 0).toFixed(2));
            $('#stat-recovery-rate').text((s.recovery_rate || 0) + '%');
            $('#stat-click-rate').text((s.click_rate || 0) + '%');
            $('#stat-at-risk').text('$' + parseFloat(s.abandoned_revenue || 0).toFixed(2));

            // Type stats bars
            var typeHtml = '';
            var typeLabels = {
                'abandoned_cart_1': 'Abandoned #1',
                'abandoned_cart_2': 'Abandoned #2',
                'abandoned_cart_3': 'Abandoned #3',
                'thankyou': 'Thank You',
                'review_request': 'Review Request',
                'crosssell': 'Cross-Sell',
                'welcome': 'Welcome'
            };

            if (Object.keys(s.by_type).length === 0) {
                typeHtml = '<p class="rtes-muted">No emails sent yet</p>';
            } else {
                for (var key in s.by_type) {
                    var t = s.by_type[key];
                    var label = typeLabels[key] || key;
                    typeHtml += '<div class="rtes-type-row">'
                        + '<div class="rtes-type-label">' + label + '</div>'
                        + '<div class="rtes-type-bar-bg"><div class="rtes-type-bar" style="width:' + Math.max(t.rate, 2) + '%"></div></div>'
                        + '<div class="rtes-type-stat">' + t.sent + ' sent · ' + t.rate + '%</div>'
                        + '</div>';
                }
            }
            $('#rtes-type-stats').html(typeHtml);

            // Log table
            var logHtml = '';
            if (!s.recent || s.recent.length === 0) {
                logHtml = '<tr><td colspan="5" class="rtes-muted">No emails sent yet</td></tr>';
            } else {
                for (var i = 0; i < s.recent.length; i++) {
                    var r = s.recent[i];
                    var typeLabel = typeLabels[r.email_type] || r.email_type;
                    logHtml += '<tr>'
                        + '<td>' + escHtml(r.email_to) + '</td>'
                        + '<td><span class="rtes-badge on">' + escHtml(typeLabel) + '</span></td>'
                        + '<td>' + escHtml(r.subject) + '</td>'
                        + '<td>' + escHtml(r.sent_at) + '</td>'
                        + '<td>' + (parseInt(r.opened) ? '👁️ Yes' : '—') + '</td>'
                        + '</tr>';
                }
            }
            $('#rtes-log-table tbody').html(logHtml);
        });
    }

    function escHtml(str) {
        if (!str) return '';
        return $('<span>').text(str).html();
    }

    // Clear log
    $(document).on('click', '#rtes-clear-log', function() {
        if (!confirm('Clear the entire email log?')) return;
        $.post(rtes.ajax_url, {
            action: 'rtes_clear_log',
            nonce: rtes.nonce
        }, function(res) {
            if (res.success) {
                toast('Log cleared');
                loadStats();
            }
        });
    });

    // Reset to defaults
    $(document).on('click', '#rtes-reset-defaults', function() {
        if (!confirm('Reset ALL email settings to defaults? This will overwrite any customizations you have made.')) return;
        var $btn = $(this);
        $btn.text('Resetting...').prop('disabled', true);
        $.post(rtes.ajax_url, {
            action: 'rtes_reset_defaults',
            nonce: rtes.nonce
        }, function(res) {
            if (res.success) {
                toast('Settings reset to defaults! Reloading...');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                toast('Error resetting settings', true);
                $btn.text('Reset to Defaults').prop('disabled', false);
            }
        }).fail(function() {
            toast('Network error', true);
            $btn.text('Reset to Defaults').prop('disabled', false);
        });
    });

    // Refresh log
    $(document).on('click', '#rtes-refresh-log', function() {
        loadStats();
        toast('Refreshed');
    });

    // Initial load
    loadStats();

})(jQuery);
