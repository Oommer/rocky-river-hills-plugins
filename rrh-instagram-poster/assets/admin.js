(function($) {
'use strict';

// ── Helpers ─────────────────────────────────────
function escapeHtml(t) { if (!t) return ''; var d=document.createElement('div'); d.appendChild(document.createTextNode(t)); return d.innerHTML; }
function ajaxPost(action, data, cb) {
    data.action = 'rrh_ig_' + action;
    data.nonce = rrhIG.nonce;
    $.post(rrhIG.ajax_url, data, cb).fail(function() { alert('Request failed'); });
}

// ── Settings: Tabs ──────────────────────────────
$('.rrh-ig-tab').on('click', function(e) {
    e.preventDefault();
    var target = $(this).attr('href');
    $('.rrh-ig-tab').removeClass('active');
    $(this).addClass('active');
    $('.rrh-ig-tab-content').hide();
    $(target).show();
});

// ── Settings: Token & Connection ────────────────
$('#rrh-ig-test-connection').on('click', function() {
    var $btn = $(this), $r = $('#rrh-ig-connection-result');
    $btn.prop('disabled', true).text('Testing...');
    ajaxPost('test_connection', {}, function(resp) {
        $btn.prop('disabled', false).text('🔌 Test Connection');
        $r.show();
        if (resp.success) {
            var d = resp.data;
            $r.html('<div class="account-info">' + (d.profile_picture_url ? '<img src="'+d.profile_picture_url+'">' : '') +
                '<div><strong>@'+d.username+'</strong><br>'+d.followers_count+' followers · '+d.media_count+' posts</div></div>')
                .removeClass('error').addClass('success');
        } else { $r.html('❌ '+resp.data).removeClass('success').addClass('error'); }
    });
});

$('#rrh-ig-exchange-token').on('click', function() {
    var $btn = $(this); $btn.prop('disabled', true);
    ajaxPost('exchange_token', {}, function(r) { $btn.prop('disabled', false); alert(r.success ? '✅ '+r.data : '❌ '+r.data); if(r.success) location.reload(); });
});

$('#rrh-ig-refresh-token').on('click', function() {
    var $btn = $(this); $btn.prop('disabled', true);
    ajaxPost('refresh_token', {}, function(r) { $btn.prop('disabled', false); alert(r.success ? '✅ '+r.data : '❌ '+r.data); if(r.success) location.reload(); });
});

// ── Settings: Templates ─────────────────────────
$('#tmpl-save').on('click', function() {
    ajaxPost('save_template', {
        template_id: $('#tmpl-id').val(), name: $('#tmpl-name').val(),
        caption_template: $('#tmpl-caption').val(), hashtags: $('#tmpl-hashtags').val(),
        category: $('#tmpl-category').val()
    }, function(r) { if(r.success) location.reload(); else alert(r.data); });
});

$(document).on('click', '.tmpl-edit', function() {
    $('#tmpl-id').val($(this).data('id'));
    $('#tmpl-name').val($(this).data('name'));
    $('#tmpl-caption').val($(this).data('caption'));
    $('#tmpl-hashtags').val($(this).data('hashtags'));
    $('#tmpl-category').val($(this).data('category') || 'general');
    $('html, body').animate({scrollTop: $('#rrh-ig-template-form').offset().top - 50}, 300);
});

$('#tmpl-clear').on('click', function() { $('#tmpl-id').val(0); $('#tmpl-name, #tmpl-caption, #tmpl-hashtags').val(''); $('#tmpl-category').val('general'); });

$(document).on('click', '.tmpl-delete', function() {
    if (!confirm('Delete this template?')) return;
    ajaxPost('delete_template', {template_id: $(this).data('id')}, function(r) { if(r.success) location.reload(); });
});

// ── Settings: Category Hashtags ─────────────────
$('#rrh-save-cat-hashtags').on('click', function() {
    var data = {hashtags: {}};
    $('.rrh-cat-hashtag').each(function() { data.hashtags[$(this).data('cat-id')] = $(this).val(); });
    ajaxPost('save_category_hashtags', data, function(r) { alert(r.success ? '✅ Saved!' : '❌ '+r.data); });
});

// ── Composer: Radio groups ──────────────────────
$('input[type="radio"]').on('change', function() {
    $(this).closest('.rrh-ig-radio-group').find('.rrh-ig-radio').removeClass('active');
    $(this).closest('.rrh-ig-radio').addClass('active');
});

$('input[name="media_source"]').on('change', function() {
    var s = $(this).val();
    $('#rrh-ig-woo-section').toggle(s === 'woocommerce');
    $('#rrh-ig-upload-section').toggle(s === 'upload');
    $('#rrh-ig-url-section').toggle(s === 'url');
});

// ── Composer: Media Upload ──────────────────────
$(document).on('input', '#rrh-ig-media-url-input', function() {
    var url = $(this).val();
    $('#rrh-ig-media-url').val(url);
    updatePreview(url);
});

$(document).on('click', '#rrh-ig-upload-btn', function(e) {
    e.preventDefault();

    var frame = wp.media({
        title: 'Select Media', button: {text: 'Use This'},
        library: {type: 'image'},
        multiple: true
    });

    frame.on('select', function() {
        var sel = frame.state().get('selection');
        var urls = [];
        sel.each(function(a) { urls.push(a.toJSON().url); });
        $('#rrh-ig-media-url').val(urls[0]);
        if (urls.length > 1) {
            $('#rrh-ig-media-urls').val(JSON.stringify(urls));
        }
        var preview = urls.map(function(u) { return '<img src="'+u+'" alt="">'; }).join('');
        $('#rrh-ig-upload-preview').html(preview);
        updatePreview(urls[0]);
    });
    frame.open();
});

// ── Composer: Product Search ────────────────────
var searchTO;
$(document).on('input', '#rrh-ig-product-search', function() {
    var s = $(this).val(), $r = $('#rrh-ig-product-results');
    clearTimeout(searchTO);
    if (s.length < 2) { $r.removeClass('active').empty(); return; }
    searchTO = setTimeout(function() {
        $.get(rrhIG.ajax_url, {action:'rrh_ig_get_products', nonce:rrhIG.nonce, search:s}, function(resp) {
            if (resp.success && resp.data.length) {
                var html = resp.data.map(function(p) {
                    var galleryJson = btoa(JSON.stringify(p.gallery_urls||[]));
                    return '<div class="rrh-ig-dropdown-item" data-id="'+p.id+'" data-title="'+escapeHtml(p.title)+'" data-image="'+p.image_url+'" data-price="'+(p.price||'')+'" data-gallery64="'+galleryJson+'" data-ptags="'+escapeHtml(p.product_hashtags||'')+'" data-ctags="'+escapeHtml(p.category_hashtags||'')+'">'
                        + (p.image_url ? '<img src="'+p.image_url+'">' : '')
                        + '<div class="product-info"><strong>'+escapeHtml(p.title)+'</strong><span>$'+p.price+'</span></div></div>';
                }).join('');
                $r.html(html).addClass('active');
            } else { $r.html('<div style="padding:12px;color:#666;">No products</div>').addClass('active'); }
        });
    }, 300);
});

$(document).on('click', '.rrh-ig-dropdown-item', function() {
    var $i = $(this);
    var gallery = [];
    try { gallery = JSON.parse(atob($i.attr('data-gallery64') || 'W10=')); } catch(e) { gallery = []; }
    $('#rrh-ig-product-id').val($i.attr('data-id'));
    $('#rrh-ig-media-url').val($i.attr('data-image'));
    $('#rrh-ig-product-results').removeClass('active');
    $('#rrh-ig-product-search').val('');

    // Store hashtag data for Smart Hashtags button
    window.rrhProductHashtags = $i.attr('data-ptags') || '';
    window.rrhCategoryHashtags = $i.attr('data-ctags') || '';

    // Store gallery URLs for server-side carousel detection
    if (gallery.length) {
        var all = [$i.attr('data-image')].concat(gallery);
        $('#rrh-ig-media-urls').val(JSON.stringify(all));
    }

    var title = $i.attr('data-title');
    var price = $i.attr('data-price');
    var image = $i.attr('data-image');
    var imgCount = gallery.length + 1;
    $('#rrh-ig-selected-product').html(
        (image ? '<img src="'+image+'">' : '') +
        '<div><strong>'+escapeHtml(title)+'</strong><br>$'+price+
        (gallery.length ? '<br><span style="color:#2271b1;font-size:12px;">🎠 '+imgCount+' images → carousel</span>' : '<br><span style="color:#666;font-size:12px;">📷 Single image</span>')+
        '</div><span class="remove-product">✕</span>'
    ).show();
    updatePreview(image);
});

$(document).on('click', '.remove-product', function() {
    $('#rrh-ig-product-id, #rrh-ig-media-url, #rrh-ig-media-urls').val('');
    $('#rrh-ig-selected-product').hide();
    window.rrhProductHashtags = '';
    window.rrhCategoryHashtags = '';
    updatePreview('');
});

// ── Composer: Caption ───────────────────────────
$(document).on('input', '#rrh-ig-caption', function() {
    var t = $(this).val(), ch = t.length, ht = (t.match(/#\w+/g)||[]).length;
    $('#rrh-ig-char-count').text(ch+' / 2,200').toggleClass('over-limit', ch > 2200);
    $('#rrh-ig-hashtag-count').text(ht+' / 30 hashtags').toggleClass('over-limit', ht > 30);
    var $s = $('#rrh-ig-preview-caption span');
    $s.html(escapeHtml(t).replace(/\n/g,'<br>').replace(/#(\w+)/g,'<span style="color:#00376b">#$1</span>'));
});

$(document).on('click', '#rrh-ig-generate-caption', function() {
    var pid = $('#rrh-ig-product-id').val();
    if (!pid) { alert('Select a product first!'); return; }
    var $sel = $('#rrh-ig-selected-product'), title = $sel.find('strong').text(), price = ($sel.find('div').text().split('$')[1]||'').trim();
    $('#rrh-ig-caption').val('🏟️ '+title+'\n\n💰 $'+price+'\n\n🛒 Shop at rockyriverhills.com\n📦 Free shipping on orders over $100!\n\n#stadiumcoasters #gameday #sportsdecor #rockyriverhills').trigger('input');
});

// ── Smart Hashtags ──────────────────────────────
var brandCoreTags = '#stadiumcoasters #handmade #rockyriverhills #shopsmall #supportsmallbusiness';

$(document).on('click', '#rrh-ig-smart-hashtags', function() {
    var allTags = [];

    // 1. Brand core tags (always)
    allTags.push(brandCoreTags);

    // 2. Category hashtags (from WooCommerce category mapping)
    if (window.rrhCategoryHashtags) allTags.push(window.rrhCategoryHashtags);

    // 3. Product-specific city/team hashtags
    if (window.rrhProductHashtags) allTags.push(window.rrhProductHashtags);

    // Combine, deduplicate
    var combined = allTags.join(' ').split(/\s+/).filter(function(t) { return t.startsWith('#'); });
    var unique = [];
    var seen = {};
    combined.forEach(function(t) {
        var lower = t.toLowerCase();
        if (!seen[lower]) { seen[lower] = true; unique.push(t); }
    });

    var tagString = unique.join(' ');

    // Append to caption
    var $c = $('#rrh-ig-caption'), v = $c.val();
    // Remove existing hashtag block if any (lines that are only hashtags)
    var lines = v.split('\n');
    var cleaned = [];
    var foundHashBlock = false;
    for (var i = lines.length - 1; i >= 0; i--) {
        if (!foundHashBlock && lines[i].trim().match(/^(#\S+\s*)+$/)) {
            continue; // skip trailing hashtag-only lines
        } else if (!foundHashBlock && lines[i].trim() === '') {
            continue; // skip trailing blank lines before hashtags
        } else {
            foundHashBlock = true;
            cleaned.unshift(lines[i]);
        }
    }

    $c.val(cleaned.join('\n') + '\n\n' + tagString).trigger('input');

    // Show preview
    var $preview = $('#rrh-ig-hashtag-preview');
    var parts = ['<strong>Brand:</strong> ' + escapeHtml(brandCoreTags)];
    if (window.rrhCategoryHashtags) parts.push('<strong>Category:</strong> ' + escapeHtml(window.rrhCategoryHashtags));
    if (window.rrhProductHashtags) parts.push('<strong>City/Team:</strong> ' + escapeHtml(window.rrhProductHashtags));
    parts.push('<strong>Total:</strong> ' + unique.length + ' hashtags');
    $preview.html(parts.join('<br>')).show();
});

// ── Composer: Templates ─────────────────────────
(function loadTemplates() {
    $.get(rrhIG.ajax_url, {action:'rrh_ig_get_templates', nonce:rrhIG.nonce}, function(r) {
        if (!r.success || !r.data.length) return;
        var $sel = $('#rrh-ig-template-select, #rrh-bulk-template');
        r.data.forEach(function(t) { $sel.append('<option value="'+t.id+'" data-caption="'+escapeHtml(t.caption_template)+'" data-hashtags="'+escapeHtml(t.hashtags||'')+'">'+escapeHtml(t.name)+'</option>'); });
    });
})();

$(document).on('change', '#rrh-ig-template-select', function() {
    var $opt = $(this).find(':selected');
    if ($opt.val()) {
        var cap = $opt.data('caption') || '', tags = $opt.data('hashtags') || '';
        $('#rrh-ig-caption').val(cap + (tags ? '\n\n'+tags : '')).trigger('input');
    }
});

// ── Composer: Schedule ──────────────────────────
$(document).on('click', '#rrh-ig-btn-schedule-toggle', function() {
    var $s = $('#rrh-ig-schedule-section');
    $s.toggle();
    if ($s.is(':visible')) {
        var now = new Date(); now.setHours(now.getHours()+1); now.setMinutes(0);
        var local = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+'T'+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
        $('#rrh-ig-scheduled-date').val(local);
        $('#rrh-ig-btn-queue').text('📋 Schedule');
        $(this).text('✕ Cancel');
        $('#rrh-ig-publish-action').val('schedule');
    } else {
        $('#rrh-ig-btn-queue').text('📋 Add to Queue');
        $(this).text('⏰ Schedule');
        $('#rrh-ig-publish-action').val('queue');
    }
});

$(document).on('submit', '#rrh-ig-compose-form', function(e) {
    if (!$('#rrh-ig-media-url').val()) { e.preventDefault(); alert('Select media first.'); return false; }
    if (!$('#rrh-ig-caption').val().trim()) { e.preventDefault(); alert('Write a caption.'); return false; }
    var action = $('#rrh-ig-publish-action').val();
    if (action === 'schedule' && !$('#rrh-ig-scheduled-date').val()) { e.preventDefault(); alert('Pick a date/time to schedule.'); return false; }
});

function updatePreview(url) {
    var $p = $('#rrh-ig-preview-image');
    $p.html(url ? '<img src="'+url+'" onerror="this.parentElement.innerHTML=\'<span class=placeholder-text>Failed to load</span>\'">' : '<span class="placeholder-text">Select media to preview</span>');
}

// ── Queue: Actions ──────────────────────────────
$(document).on('click', '.rrh-ig-publish-btn', function() {
    var $b = $(this), id = $b.data('id');
    $b.prop('disabled', true).text('...');
    ajaxPost('publish_now', {post_id:id}, function(r) {
        if(r.success) { location.reload(); }
        else { $b.prop('disabled', false).text('🚀'); }
    });
});

$(document).on('click', '.rrh-ig-retry-btn', function() {
    var $b = $(this), id = $b.data('id');
    ajaxPost('retry_post', {post_id:id}, function(r) { if(r.success) location.reload(); });
});

$(document).on('click', '.rrh-ig-delete-btn', function() {
    var $b = $(this), id = $b.data('id');
    ajaxPost('delete_post', {post_id:id}, function(r) { if(r.success) { $b.closest('tr').next('.rrh-ig-edit-row').remove(); $b.closest('tr').fadeOut(300, function(){$(this).remove();}); }});
});

// Cancel stuck publishing post
$(document).on('click', '.rrh-ig-cancel-btn', function() {
    var $b = $(this), id = $b.data('id');
    if (!confirm('Cancel this publishing post and mark as failed?')) return;
    ajaxPost('retry_post', {post_id:id}, function(r) { if(r.success) location.reload(); });
});

// ── Queue: Edit ─────────────────────────────────
$(document).on('click', '.rrh-ig-edit-btn', function() {
    var id = $(this).data('id');
    var $row = $('#rrh-edit-' + id);
    // Close any other open edit rows
    $('.rrh-ig-edit-row').not($row).slideUp(200);
    $row.slideToggle(200);
});

$(document).on('click', '.rrh-ig-cancel-edit-btn', function() {
    $('#rrh-edit-' + $(this).data('id')).slideUp(200);
});

$(document).on('input', '.rrh-edit-caption', function() {
    $(this).closest('td').find('.rrh-edit-char-count').text($(this).val().length);
});

$(document).on('click', '.rrh-ig-save-edit-btn', function() {
    var $b = $(this), id = $b.data('id');
    var $row = $('#rrh-edit-' + id);
    var caption = $row.find('.rrh-edit-caption').val();
    var schedule = $row.find('.rrh-edit-schedule').val();

    $b.prop('disabled', true).text('Saving...');
    ajaxPost('edit_post', {post_id: id, caption: caption, scheduled_at: schedule}, function(r) {
        $b.prop('disabled', false).text('💾 Save Changes');
        if (r.success) {
            $row.find('.rrh-edit-result').text('✅ Saved!').show();
            setTimeout(function() { $row.find('.rrh-edit-result').fadeOut(); }, 2000);
            // Update the main row caption preview
            var $mainRow = $row.prev('tr');
            var preview = caption.length > 80 ? caption.substring(0, 80) + '...' : caption;
            $mainRow.find('.rrh-ig-caption-preview').text(preview);
            // Update schedule column
            if (r.data.scheduled_display) {
                $mainRow.find('td:eq(4)').text(r.data.scheduled_display);
            }
        } else {
            alert('Error: ' + r.data);
        }
    });
});

// ── Bulk: Product Loading ───────────────────────
(function initBulk() {
    if (!$('#rrh-bulk-products').length) return;

    var now = new Date(); now.setHours(now.getHours()+1); now.setMinutes(0);
    var local = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+'T'+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
    $('#rrh-bulk-start').val(local);

    loadBulkProducts('');

    $('#rrh-bulk-search').on('input', function() {
        clearTimeout(searchTO);
        var s = $(this).val();
        searchTO = setTimeout(function() { loadBulkProducts(s); }, 300);
    });
})();

function loadBulkProducts(search) {
    $.get(rrhIG.ajax_url, {action:'rrh_ig_get_products', nonce:rrhIG.nonce, search:search}, function(r) {
        if (!r.success) return;
        var html = r.data.map(function(p) {
            return '<div class="rrh-ig-product-card" data-id="'+p.id+'">'
                + '<div class="check">✓</div>'
                + (p.image_url ? '<img src="'+p.image_url+'" alt="">' : '<div style="aspect-ratio:1;background:#eee;"></div>')
                + '<div class="title">'+escapeHtml(p.title)+'</div>'
                + '<div class="price">$'+p.price+'</div></div>';
        }).join('');
        $('#rrh-bulk-products').html(html || '<p style="color:#666;text-align:center;">No products found</p>');
    });
}

$(document).on('click', '.rrh-ig-product-card', function() {
    $(this).toggleClass('selected');
    var count = $('.rrh-ig-product-card.selected').length;
    $('#rrh-bulk-count').text(count);
    $('#rrh-bulk-queue-btn').prop('disabled', count === 0);
});

$('#rrh-bulk-select-all').on('click', function() { $('.rrh-ig-product-card').addClass('selected'); var c=$('.rrh-ig-product-card.selected').length; $('#rrh-bulk-count').text(c); $('#rrh-bulk-queue-btn').prop('disabled',!c); });
$('#rrh-bulk-select-none').on('click', function() { $('.rrh-ig-product-card').removeClass('selected'); $('#rrh-bulk-count').text(0); $('#rrh-bulk-queue-btn').prop('disabled', true); });

$('#rrh-bulk-queue-btn').on('click', function() {
    var ids = []; $('.rrh-ig-product-card.selected').each(function() { ids.push($(this).data('id')); });
    if (!ids.length) return;
    if (!confirm('Queue '+ids.length+' products?')) return;

    var $btn = $(this), $r = $('#rrh-bulk-result');
    $btn.prop('disabled', true).text('Queuing...');

    ajaxPost('bulk_queue', {
        product_ids: ids,
        template_id: $('#rrh-bulk-template').val(),
        interval_hours: $('#rrh-bulk-interval').val(),
        start_date: $('#rrh-bulk-start').val(),
        use_carousel: $('#rrh-bulk-carousel').is(':checked') ? 1 : 0
    }, function(r) {
        $btn.prop('disabled', false).text('📋 Queue Selected ('+ids.length+')');
        $r.show();
        if (r.success) { $r.html('✅ '+r.data.queued+' posts queued!').removeClass('error').addClass('success'); }
        else { $r.html('❌ '+r.data).removeClass('success').addClass('error'); }
    });
});

// ── Insights: Sync ──────────────────────────────
$('#rrh-ig-sync-insights').on('click', function() {
    var $btn = $(this), $r = $('#rrh-ig-sync-result');
    $btn.prop('disabled', true).text('Syncing...');
    ajaxPost('sync_insights', {}, function(r) {
        $btn.prop('disabled', false).text('🔄 Sync Insights Now');
        $r.text(r.success ? '✅ Synced '+r.data.synced+' posts' : '❌ '+r.data);
    });
});

// ── Calendar ────────────────────────────────────
(function initCalendar() {
    if (!$('#rrh-ig-calendar').length) return;

    var calMonth = new Date().getMonth();
    var calYear = new Date().getFullYear();
    var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    renderCalendar();

    $('#rrh-cal-prev').on('click', function() { calMonth--; if(calMonth<0){calMonth=11;calYear--;} renderCalendar(); });
    $('#rrh-cal-next').on('click', function() { calMonth++; if(calMonth>11){calMonth=0;calYear++;} renderCalendar(); });

    function renderCalendar() {
        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $('#rrh-cal-title').text(months[calMonth]+' '+calYear);

        $.get(rrhIG.ajax_url, {action:'rrh_ig_calendar_data', nonce:rrhIG.nonce, month:calMonth+1, year:calYear}, function(r) {
            if (!r.success) return;

            var postsByDay = {};
            r.data.forEach(function(p) {
                var d = new Date(p.display_date).getDate();
                if (!postsByDay[d]) postsByDay[d] = [];
                postsByDay[d].push(p);
            });

            var firstDay = new Date(calYear, calMonth, 1).getDay();
            var daysInMonth = new Date(calYear, calMonth+1, 0).getDate();
            var today = new Date();
            var isCurrentMonth = today.getMonth() === calMonth && today.getFullYear() === calYear;

            var html = dayNames.map(function(d) { return '<div class="rrh-ig-cal-header">'+d+'</div>'; }).join('');

            for (var i=0; i<firstDay; i++) html += '<div class="rrh-ig-cal-day empty"></div>';

            for (var d=1; d<=daysInMonth; d++) {
                var isToday = isCurrentMonth && d === today.getDate();
                html += '<div class="rrh-ig-cal-day'+(isToday?' today':'')+'"><div class="day-num">'+d+'</div>';
                if (postsByDay[d]) {
                    postsByDay[d].forEach(function(p) {
                        var icon = p.post_type==='reel'?'🎬':p.post_type==='carousel'?'🎠':'📷';
                        html += '<div class="rrh-ig-cal-post '+p.status+'" title="'+escapeHtml(p.caption.substring(0,50))+'">'+icon+' '+escapeHtml(p.caption.substring(0,15))+'</div>';
                    });
                }
                html += '</div>';
            }

            $('#rrh-ig-calendar').html(html);
        });
    }
})();

// ── Close dropdowns ─────────────────────────────
$(document).on('click', function(e) {
    if (!$(e.target).closest('#rrh-ig-product-search, #rrh-ig-product-results').length) {
        $('#rrh-ig-product-results').removeClass('active');
    }
});

// ── Autopilot Dashboard ─────────────────────────
(function() {
    var $dash = $('#rrh-ig-autopilot-dashboard');
    if (!$dash.length) return;
    $dash.show();

    function loadAutopilotStatus() {
        $.post(rrhIG.ajax_url, { action: 'rrh_ig_autopilot_status', nonce: rrhIG.nonce }, function(res) {
            if (!res.success) return;
            var d = res.data;
            $('#ap-stat-total').text(d.total_products);
            $('#ap-stat-posted').text(d.posted_count);
            $('#ap-stat-queued').text(d.queued_count);
            $('#ap-stat-rotation').text(d.rotation_days + 'd');
            $('#ap-next-product').text(d.next_product || 'None available');
            $('#ap-next-cats').text(d.next_product_cats ? '(' + d.next_product_cats + ')' : '');
            $('#ap-last-run').text(d.last_run || 'Never');
            $('#ap-last-template').text(d.last_template || '—');

            // Template coverage check
            var $cov = $('#ap-template-coverage');
            if (d.woo_categories && d.woo_categories.length) {
                var html = '<strong>Template Coverage:</strong> ';
                var issues = [];
                d.woo_categories.forEach(function(c) {
                    if (c.has_templates) {
                        html += '<span style="color:#00a32a;">✅ ' + c.name + ' (' + c.count + ' products)</span> ';
                    } else {
                        html += '<span style="color:#d63638;">❌ ' + c.name + ' (' + c.count + ' products — NO matching templates!)</span> ';
                        issues.push(c.name);
                    }
                });
                if (d.template_map) {
                    html += '<br><strong>Templates:</strong> ';
                    Object.keys(d.template_map).forEach(function(cat) {
                        html += '<span style="background:#f0f0f1; padding:2px 6px; border-radius:3px; margin:2px;">' + cat + ': ' + d.template_map[cat].join(', ') + '</span> ';
                    });
                }
                if (issues.length) {
                    html += '<br><span style="color:#d63638; font-weight:600;">⚠️ Products in ' + issues.join(', ') + ' will use the built-in default caption (no template matched).</span>';
                }
                $cov.html(html).show();
            }
        });
    }

    loadAutopilotStatus();

    $(document).on('click', '#rrh-ig-autopilot-refresh', function(e) {
        e.preventDefault();
        loadAutopilotStatus();
    });

    $(document).on('click', '#rrh-ig-autopilot-debug', function(e) {
        e.preventDefault();
        var $out = $('#rrh-ig-autopilot-debug-output');
        $out.html('<em>Loading debug info...</em>').show();

        $.post(rrhIG.ajax_url, { action: 'rrh_ig_autopilot_debug', nonce: rrhIG.nonce }, function(res) {
            if (!res.success) { $out.html('<span style="color:red;">Error loading debug data</span>'); return; }
            var d = res.data, html = '';

            // Templates
            html += '<h4 style="margin:0 0 8px;">📝 Saved Templates (category in DB)</h4>';
            html += '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
            html += '<tr style="background:#f0f0f0;"><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Template Name</th><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Category (raw)</th><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Category (lowered)</th></tr>';
            d.templates.forEach(function(t) {
                var cat = t.category || '<em style="color:red;">EMPTY</em>';
                var catL = t.category_lower || '<em style="color:red;">empty</em>';
                html += '<tr><td style="padding:4px 8px;border:1px solid #ddd;">'+t.name+'</td><td style="padding:4px 8px;border:1px solid #ddd;">'+cat+'</td><td style="padding:4px 8px;border:1px solid #ddd;font-family:monospace;">'+catL+'</td></tr>';
            });
            html += '</table>';

            // WooCommerce categories
            html += '<h4 style="margin:0 0 8px;">🏷️ WooCommerce Product Categories</h4>';
            html += '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
            html += '<tr style="background:#f0f0f0;"><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Category Name</th><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Slug</th><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Name (lowered)</th><th style="padding:4px 8px;text-align:left;border:1px solid #ddd;">Products</th></tr>';
            d.woo_categories.forEach(function(c) {
                html += '<tr><td style="padding:4px 8px;border:1px solid #ddd;">'+c.name+'</td><td style="padding:4px 8px;border:1px solid #ddd;font-family:monospace;">'+c.slug+'</td><td style="padding:4px 8px;border:1px solid #ddd;font-family:monospace;">'+c.name_lower+'</td><td style="padding:4px 8px;border:1px solid #ddd;">'+c.count+'</td></tr>';
            });
            html += '</table>';

            // Sample matching
            html += '<h4 style="margin:0 0 8px;">🎯 Sample Product → Template Matching (first 10 products)</h4>';
            d.sample_matches.forEach(function(m) {
                var color = m.would_use === 'CATEGORY MATCH' ? '#00a32a' : (m.would_use === 'GENERAL FALLBACK' ? '#dba617' : '#d63638');
                html += '<div style="padding:8px;margin-bottom:6px;background:#fafafa;border:1px solid #ddd;border-radius:4px;border-left:3px solid '+color+';">';
                html += '<strong>'+m.product+'</strong><br>';
                html += '<span style="color:#666;">Categories: '+m.product_categories.join(', ')+'</span><br>';
                if (m.full_chain && m.full_chain.length) {
                    html += '<span style="color:#888;font-size:12px;">Full chain (incl. parents): '+m.full_chain.join(', ')+'</span><br>';
                }
                if (m.matched_templates.length) {
                    html += '<span style="color:#00a32a;">✅ Matched: '+m.matched_templates.join(', ')+'</span><br>';
                } else {
                    html += '<span style="color:#d63638;">❌ No category match</span><br>';
                }
                html += '<span style="font-weight:600;color:'+color+';">→ '+m.would_use+'</span>';
                html += '</div>';
            });

            $out.html(html);
        });
    });

    $(document).on('click', '#rrh-ig-autopilot-run-now', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true).text('Running...');
        var $msg = $('#rrh-ig-autopilot-msg');

        $.post(rrhIG.ajax_url, { action: 'rrh_ig_autopilot_run_now', nonce: rrhIG.nonce }, function(res) {
            if (res.success) {
                $msg.text('✅ ' + res.data.message).show();
                var d = res.data.status;
                $('#ap-stat-total').text(d.total_products);
                $('#ap-stat-posted').text(d.posted_count);
                $('#ap-stat-queued').text(d.queued_count);
                $('#ap-stat-rotation').text(d.rotation_days + 'd');
                $('#ap-next-product').text(d.next_product || 'None available');
                $('#ap-next-cats').text(d.next_product_cats ? '(' + d.next_product_cats + ')' : '');
                $('#ap-last-run').text(d.last_run || 'Never');
                $('#ap-last-template').text(d.last_template || '—');
            } else {
                $msg.text('❌ Error').css('color','#d63638').show();
            }
            $btn.prop('disabled', false).text('▶️ Run Now');
            setTimeout(function() { $msg.fadeOut(); }, 4000);
        }).fail(function() {
            $msg.text('❌ Network error').css('color','#d63638').show();
            $btn.prop('disabled', false).text('▶️ Run Now');
        });
    });
})();

})(jQuery);
