/* RT Live Scores — Admin v2.0 */
(function($){
    'use strict';

    $(document).on('click', '.rtls-tab', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.rtls-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtls-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
    });

    $(document).on('submit', '#rtls-form', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.rtls-btn.primary');
        $btn.text('Saving…').prop('disabled', true);
        $.post(rtls_admin.ajax_url, {
            action: 'rtls_save_settings',
            nonce: rtls_admin.nonce,
            settings: $(this).serialize()
        }, function(r) {
            $btn.text(r.success ? '✓ Saved!' : '✗ Error').prop('disabled', false);
            setTimeout(function(){ $btn.text('Save Settings'); }, 2000);
        });
    });

    $(document).on('click', '#rtls-auto-scan', function() {
        var $btn = $(this);
        $btn.text('Scanning…').prop('disabled', true);
        $('#rtls-scan-result').text('');
        $.post(rtls_admin.ajax_url, {
            action: 'rtls_auto_scan',
            nonce: rtls_admin.nonce
        }, function(r) {
            if (r.success) {
                $('#rtls-scan-result').text('Found ' + r.data.found + ' team identifiers!');
                var html = '<div class="rtls-team-tags">';
                r.data.teams.forEach(function(t) {
                    html += '<span class="rtls-team-tag">' + $('<span>').text(t).html() + '</span>';
                });
                html += '</div>';
                $('#rtls-team-list').html(html);
            } else {
                $('#rtls-scan-result').text('Scan failed.');
            }
            $btn.text('🔍 Scan My Products').prop('disabled', false);
        });
    });

})(jQuery);
