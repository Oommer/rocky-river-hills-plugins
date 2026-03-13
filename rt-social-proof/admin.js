/* RT Social Proof — Admin JS */
(function($) {
    'use strict';

    // Tabs
    $(document).on('click', '.rtsp-tab', function() {
        var tab = $(this).data('tab');
        $('.rtsp-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtsp-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
        if (tab === 'dashboard') loadStats();
    });

    function toast(msg, isError) {
        var $t = $('#rtsp-toast');
        $t.text(msg).removeClass('error');
        if (isError) $t.addClass('error');
        $t.addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3000);
    }

    // Save settings
    $(document).on('submit', '.rtsp-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.rtsp-btn.primary');
        var orig = $btn.text();
        $btn.text('Saving...').prop('disabled', true);

        $.post(rtsp_admin.ajax_url, {
            action: 'rtsp_save_settings',
            nonce: rtsp_admin.nonce,
            settings: $form.serialize()
        }, function(res) {
            toast(res.success ? 'Settings saved!' : 'Error saving');
            $btn.text(orig).prop('disabled', false);
        }).fail(function() {
            toast('Network error', true);
            $btn.text(orig).prop('disabled', false);
        });
    });

    // Load stats
    function loadStats() {
        $.post(rtsp_admin.ajax_url, {
            action: 'rtsp_get_stats',
            nonce: rtsp_admin.nonce
        }, function(res) {
            if (!res.success) return;
            var s = res.data;

            $('#stat-impressions').text(s.total_impressions || 0);
            $('#stat-today').text(s.today_impressions || 0);
            $('#stat-real').text(s.total_real || 0);
            $('#stat-total').text(s.total_activities || 0);

            // Activity table
            var html = '';
            var typeLabels = {
                purchase: 'Purchase',
                view: 'Viewing',
                cart_add: 'Cart Add',
                recent_view: 'Recent View'
            };

            if (!s.recent || !s.recent.length) {
                html = '<tr><td colspan="5" class="rtsp-muted">No activity yet</td></tr>';
            } else {
                for (var i = 0; i < s.recent.length; i++) {
                    var r = s.recent[i];
                    var tl = typeLabels[r.activity_type] || r.activity_type;
                    html += '<tr>'
                        + '<td><span class="rtsp-type-badge ' + esc(r.activity_type) + '">' + esc(tl) + '</span></td>'
                        + '<td>' + esc(r.product_name) + '</td>'
                        + '<td>' + esc(r.city) + (r.state ? ', ' + esc(r.state) : '') + '</td>'
                        + '<td>' + (parseInt(r.is_real) ? '✅' : '—') + '</td>'
                        + '<td>' + esc(r.created_at) + '</td>'
                        + '</tr>';
                }
            }
            $('#rtsp-activity-table tbody').html(html);

            // Daily chart
            var chartHtml = '';
            if (s.daily && s.daily.length) {
                var maxVal = 1;
                for (var d = 0; d < s.daily.length; d++) {
                    if (parseInt(s.daily[d].impressions) > maxVal) maxVal = parseInt(s.daily[d].impressions);
                }

                chartHtml = '<div class="rtsp-chart-bar-wrap">';
                // Reverse to show oldest first
                var days = s.daily.slice().reverse();
                for (var d = 0; d < days.length; d++) {
                    var val = parseInt(days[d].impressions);
                    var pct = Math.max((val / maxVal) * 100, 3);
                    var dateStr = days[d].stat_date.slice(5); // MM-DD
                    chartHtml += '<div class="rtsp-chart-col">'
                        + '<span class="rtsp-chart-val">' + val + '</span>'
                        + '<div class="rtsp-chart-bar" style="height:' + pct + '%"></div>'
                        + '<span class="rtsp-chart-label">' + esc(dateStr) + '</span>'
                        + '</div>';
                }
                chartHtml += '</div>';
            } else {
                chartHtml = '<p class="rtsp-muted">No impression data yet</p>';
            }
            $('#rtsp-daily-chart').html(chartHtml);
        });
    }

    function esc(str) {
        if (!str) return '';
        return $('<span>').text(str).html();
    }

    // Refresh
    $(document).on('click', '#rtsp-refresh', function() {
        loadStats();
        toast('Refreshed');
    });

    // Reset stats
    $(document).on('click', '#rtsp-reset-stats', function() {
        if (!confirm('Reset ALL social proof data? This cannot be undone.')) return;
        $.post(rtsp_admin.ajax_url, {
            action: 'rtsp_reset_stats',
            nonce: rtsp_admin.nonce
        }, function(res) {
            if (res.success) {
                toast('All data reset');
                loadStats();
            }
        });
    });

    // Reset to defaults
    $(document).on('click', '#rtsp-reset-defaults', function() {
        if (!confirm('Reset ALL settings to defaults? This will overwrite any customizations.')) return;
        var $btn = $(this);
        $btn.text('Resetting...').prop('disabled', true);
        $.post(rtsp_admin.ajax_url, {
            action: 'rtsp_reset_defaults',
            nonce: rtsp_admin.nonce
        }, function(res) {
            if (res.success) {
                toast('Settings reset! Reloading...');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                toast('Error resetting', true);
                $btn.text('Reset to Defaults').prop('disabled', false);
            }
        }).fail(function() {
            toast('Network error', true);
            $btn.text('Reset to Defaults').prop('disabled', false);
        });
    });

    // Initial
    loadStats();

})(jQuery);
