/* RT Schema Markup — Admin JS */
(function($){
    'use strict';

    /* Tabs */
    $(document).on('click', '.rtsm-tab', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.rtsm-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtsm-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
    });

    /* Save all forms */
    $(document).on('submit', '#form-overview, #form-business, #form-product, #form-social', function(e) {
        e.preventDefault();
        var $btn = $(this).find('.rtsm-btn.primary');
        var orig = $btn.text();
        $btn.text('Saving…').prop('disabled', true);

        // Merge all forms
        var all = {};
        $('#form-overview, #form-business, #form-product, #form-social').each(function() {
            var parts = $(this).serialize().split('&');
            parts.forEach(function(p) {
                var kv = p.split('=');
                all[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
            });
        });

        // Handle unchecked checkboxes
        $('input[type="checkbox"]').each(function() {
            if (!this.checked) all[this.name] = '';
        });

        $.post(rtsm.ajax_url, {
            action: 'rtsm_save_settings',
            nonce: rtsm.nonce,
            settings: $.param(all)
        }, function(r) {
            $btn.text(r.success ? '✓ Saved!' : '✗ Error').prop('disabled', false);
            setTimeout(function(){ $btn.text(orig); }, 2000);
        });
    });

    /* Test schema */
    $(document).on('click', '#btn-test', function() {
        var $btn = $(this);
        $btn.text('Testing…').prop('disabled', true);
        $('#test-results').show().html('<p>Generating schema…</p>');

        $.post(rtsm.ajax_url, {
            action: 'rtsm_test_schema',
            nonce: rtsm.nonce
        }, function(r) {
            if (r.success && r.data.results) {
                var html = '';
                r.data.results.forEach(function(item) {
                    var status = item.valid ? '<span class="valid">✓ Valid</span>' : '<span class="invalid">✗ Issues</span>';
                    html += '<div class="rtsm-test-item">';
                    html += '<h4>' + item.type + ' — ' + status + '</h4>';
                    html += '<pre>' + JSON.stringify(item.data, null, 2) + '</pre>';
                    html += '</div>';
                });
                $('#test-results').html(html);
            } else {
                $('#test-results').html('<p>Test failed. Make sure WooCommerce is active and you have published products.</p>');
            }
            $btn.text('🔍 Generate Test Output').prop('disabled', false);
        });
    });

})(jQuery);
