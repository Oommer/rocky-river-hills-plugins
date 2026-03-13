/* RT Pinterest Poster — Admin JS */
(function($) {
    'use strict';

    // Tabs
    $(document).on('click', '.rtpp-tab', function() {
        var tab = $(this).data('tab');
        $('.rtpp-tab').removeClass('active');
        $(this).addClass('active');
        $('.rtpp-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');

        if (tab === 'dashboard') loadStats();
        if (tab === 'products') loadProducts();
        if (tab === 'schedule') loadSchedule();
        if (tab === 'boards') loadBoards();
        if (tab === 'log') loadStats();
    });

    function toast(msg, isError) {
        var $t = $('#rtpp-toast');
        $t.text(msg).removeClass('error');
        if (isError) $t.addClass('error');
        $t.addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3500);
    }

    function ajax(action, data, cb) {
        data = data || {};
        data.action = action;
        data.nonce = rtpp.nonce;
        $.post(rtpp.ajax_url, data, function(res) {
            if (cb) cb(res);
        }).fail(function() {
            toast('Network error', true);
        });
    }

    /*--------------------------------------------------------------
    # Stats / Dashboard
    --------------------------------------------------------------*/
    function loadStats() {
        ajax('rtpp_get_stats', {}, function(res) {
            if (!res.success) return;
            var s = res.data;
            $('#stat-pinned').text(s.total_pinned || 0);
            $('#stat-pending').text(s.pending || 0);
            $('#stat-failed').text(s.total_failed || 0);

            // Log table
            var $tbody = $('#rtpp-log-table tbody');
            $tbody.empty();
            if (!s.recent || !s.recent.length) {
                $tbody.html('<tr><td colspan="4" class="rtpp-muted">No pins yet</td></tr>');
                return;
            }
            $.each(s.recent, function(i, r) {
                var statusClass = r.status === 'success' ? 'success' : (r.status === 'failed' ? 'failed' : 'pending');
                var pinLink = r.pin_url ? '<a href="' + r.pin_url + '" target="_blank">View →</a>' : (r.error_message || '—');
                $tbody.append(
                    '<tr>' +
                    '<td>' + (r.product_name || 'Product #' + r.product_id) + '</td>' +
                    '<td><span class="rtpp-status-badge ' + statusClass + '">' + r.status + '</span></td>' +
                    '<td>' + pinLink + '</td>' +
                    '<td>' + (r.created_at || '') + '</td>' +
                    '</tr>'
                );
            });
        });
    }

    $(document).on('click', '#rtpp-refresh-stats', loadStats);
    $(document).on('click', '#rtpp-refresh-log', loadStats);

    /*--------------------------------------------------------------
    # Products
    --------------------------------------------------------------*/
    function loadProducts() {
        ajax('rtpp_get_products', {}, function(res) {
            if (!res.success) return;
            var $list = $('#rtpp-products-list');
            $list.empty();

            if (!res.data || !res.data.length) {
                $list.html('<p class="rtpp-muted">No products found</p>');
                return;
            }

            var $grid = $('<div class="rtpp-product-grid"></div>');
            $.each(res.data, function(i, p) {
                var badge = '';
                var btn = '';
                if (p.pinned) {
                    badge = '<span class="rtpp-badge pinned">✓ Pinned</span>';
                } else if (p.scheduled) {
                    badge = '<span class="rtpp-badge scheduled">⏳ Scheduled</span>';
                } else {
                    badge = '<span class="rtpp-badge ready">Ready</span>';
                    btn = '<button type="button" class="rtpp-btn pin-btn rtpp-pin-single" data-id="' + p.id + '">📌 Pin Now</button>';
                }

                var img = p.image || '';
                $grid.append(
                    '<div class="rtpp-product-item">' +
                    (img ? '<img src="' + img + '" class="rtpp-product-img" alt="">' : '<div class="rtpp-product-img"></div>') +
                    '<div class="rtpp-product-info">' +
                    '<p class="rtpp-product-name" title="' + $('<span>').text(p.name).html() + '">' + $('<span>').text(p.name).html() + '</p>' +
                    '<p class="rtpp-product-price">$' + parseFloat(p.price || 0).toFixed(2) + '</p>' +
                    '<div class="rtpp-product-status">' + badge + '</div>' +
                    btn +
                    '</div></div>'
                );
            });
            $list.append($grid);
        });
    }

    $(document).on('click', '#rtpp-load-products', loadProducts);

    // Pin single product
    $(document).on('click', '.rtpp-pin-single', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        $btn.text('Pinning...').prop('disabled', true);

        ajax('rtpp_pin_now', { product_id: id }, function(res) {
            if (res.success) {
                toast('Pin created!');
                $btn.closest('.rtpp-product-status').html('<span class="rtpp-badge pinned">✓ Pinned</span>');
                $btn.remove();
            } else {
                toast(res.data || 'Pin failed', true);
                $btn.text('📌 Pin Now').prop('disabled', false);
            }
        });
    });

    // Pin all
    $(document).on('click', '#rtpp-pin-all', function() {
        var $btn = $(this);
        $btn.text('Scheduling...').prop('disabled', true);
        $('#rtpp-quick-status').text('');

        ajax('rtpp_pin_all', {}, function(res) {
            if (res.success) {
                toast(res.data.message || 'All products scheduled!');
                $('#rtpp-quick-status').text('✅ ' + (res.data.message || ''));
                loadStats();
            } else {
                toast(res.data || 'Failed', true);
            }
            $btn.text('📌 Schedule All Products').prop('disabled', false);
        });
    });

    /*--------------------------------------------------------------
    # Schedule
    --------------------------------------------------------------*/
    function loadSchedule() {
        ajax('rtpp_get_schedule', {}, function(res) {
            if (!res.success) return;
            var $list = $('#rtpp-schedule-list');
            $list.empty();

            if (!res.data || !res.data.length) {
                $list.html('<p class="rtpp-muted">No scheduled pins</p>');
                return;
            }

            $.each(res.data, function(i, item) {
                $list.append(
                    '<div class="rtpp-schedule-item">' +
                    '<span>' + (item.product_name || 'Product #' + item.product_id) + '</span>' +
                    '<span class="rtpp-schedule-time">' + item.scheduled_at + '</span>' +
                    '</div>'
                );
            });
        });
    }

    $(document).on('click', '#rtpp-refresh-schedule', loadSchedule);

    $(document).on('click', '#rtpp-clear-schedule', function() {
        if (!confirm('Clear all scheduled pins? This cannot be undone.')) return;
        ajax('rtpp_clear_schedule', {}, function(res) {
            if (res.success) {
                toast('Schedule cleared');
                loadSchedule();
                loadStats();
            }
        });
    });

    /*--------------------------------------------------------------
    # Boards
    --------------------------------------------------------------*/
    function loadBoards() {
        ajax('rtpp_get_boards', {}, function(res) {
            if (!res.success) return;
            var $list = $('#rtpp-boards-list');
            $list.empty();

            if (!res.data || !res.data.length) {
                $list.html('<p class="rtpp-muted">No boards found. Create one below.</p>');
                return;
            }

            var $bl = $('<div class="rtpp-board-list"></div>');
            $.each(res.data, function(i, b) {
                $bl.append(
                    '<div class="rtpp-board-item" data-id="' + b.id + '" data-name="' + $('<span>').text(b.name).html() + '" data-desc="' + $('<span>').text(b.description || '').html() + '" data-url="' + (b.url || '') + '">' +
                    '<div>' +
                    '<span class="rtpp-board-name">' + $('<span>').text(b.name).html() + '</span>' +
                    '<span class="rtpp-board-count"> · ' + (b.pin_count || 0) + ' pins</span>' +
                    (b.url ? ' <a href="' + b.url + '" target="_blank" class="rtpp-board-ext-link" title="View on Pinterest">↗</a>' : '') +
                    '</div>' +
                    '<div style="display:flex;gap:8px;align-items:center;">' +
                    '<button type="button" class="rtpp-btn small secondary rtpp-edit-board" data-id="' + b.id + '">✏️ Edit</button>' +
                    '<button type="button" class="rtpp-btn small secondary rtpp-set-default" data-id="' + b.id + '" data-name="' + $('<span>').text(b.name).html() + '" data-url="' + (b.url || '') + '">Set Default</button>' +
                    '</div>' +
                    '</div>'
                );
            });
            $list.append($bl);
        });
    }

    $(document).on('click', '#rtpp-refresh-boards', loadBoards);

    // Open edit panel
    $(document).on('click', '.rtpp-edit-board', function() {
        var $item = $(this).closest('.rtpp-board-item');
        var id   = $item.data('id');
        var name = $item.data('name');
        var desc = $item.data('desc');

        $('#rtpp-edit-board-id').val(id);
        $('#rtpp-edit-board-name').val(name);
        $('#rtpp-edit-board-desc').val(desc);
        $('#rtpp-edit-board-card').show();
        $('html, body').animate({ scrollTop: $('#rtpp-edit-board-card').offset().top - 40 }, 300);
    });

    // Cancel edit
    $(document).on('click', '#rtpp-cancel-board-edit', function() {
        $('#rtpp-edit-board-card').hide();
    });

    // Save board edit
    $(document).on('click', '#rtpp-save-board-edit', function() {
        var $btn = $(this);
        var id   = $('#rtpp-edit-board-id').val();
        var name = $('#rtpp-edit-board-name').val().trim();
        var desc = $('#rtpp-edit-board-desc').val().trim();

        if (!name) { toast('Board name is required', true); return; }

        $btn.text('Saving...').prop('disabled', true);

        ajax('rtpp_edit_board', { board_id: id, name: name, description: desc }, function(res) {
            $btn.text('Save Changes').prop('disabled', false);
            if (res.success) {
                toast('Board updated!');
                $('#rtpp-edit-board-card').hide();
                loadBoards();
            } else {
                toast(res.data || 'Failed to update board', true);
            }
        });
    });

    // Set default board
    $(document).on('click', '.rtpp-set-default', function() {
        var boardId   = $(this).data('id');
        var boardName = $(this).data('name');
        var boardUrl  = $(this).data('url') || '';

        ajax('rtpp_set_default_board', {
            board_id:   boardId,
            board_name: boardName,
            board_url:  boardUrl,
        }, function(res) {
            if (res.success) {
                toast('Default board set to "' + boardName + '"');
                $('#stat-board').text('Set');
                $('#step-board').addClass('done');
                $('.rtpp-board-item').removeClass('selected');
                $('.rtpp-board-item[data-id="' + boardId + '"]').addClass('selected');

                // Update dashboard board link
                if (boardUrl) {
                    $('#stat-board-link').html('<a href="' + boardUrl + '" target="_blank" style="color:var(--rtpp-brand);text-decoration:none;">View on Pinterest →</a>');
                } else {
                    $('#stat-board-link').empty();
                }
            }
        });
    });

    // Create board
    $(document).on('click', '#rtpp-create-board', function() {
        var name = $('#rtpp-new-board-name').val().trim();
        if (!name) { toast('Enter a board name', true); return; }

        var $btn = $(this);
        $btn.text('Creating...').prop('disabled', true);

        ajax('rtpp_create_board', {
            name: name,
            description: $('#rtpp-new-board-desc').val().trim()
        }, function(res) {
            if (res.success) {
                toast('Board created!');
                $('#rtpp-new-board-name').val('');
                $('#rtpp-new-board-desc').val('');
                loadBoards();
            } else {
                toast(res.data || 'Failed to create board', true);
            }
            $btn.text('Create Board').prop('disabled', false);
        });
    });

    /*--------------------------------------------------------------
    # Save Forms
    --------------------------------------------------------------*/
    $(document).on('submit', '.rtpp-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.rtpp-btn.primary');
        var orig = $btn.text();
        $btn.text('Saving...').prop('disabled', true);

        ajax('rtpp_save_settings', {
            settings: $form.serialize()
        }, function(res) {
            toast(res.success ? 'Settings saved!' : 'Error saving');
            $btn.text(orig).prop('disabled', false);
        });
    });

    /*--------------------------------------------------------------
    # Disconnect
    --------------------------------------------------------------*/
    $(document).on('click', '#rtpp-disconnect', function() {
        if (!confirm('Disconnect your Pinterest account? You can reconnect anytime.')) return;
        ajax('rtpp_disconnect', {}, function(res) {
            if (res.success) {
                toast('Disconnected');
                setTimeout(function() { location.reload(); }, 1000);
            }
        });
    });

    /*--------------------------------------------------------------
    # Reset Data
    --------------------------------------------------------------*/
    $(document).on('click', '#rtpp-reset-data', function() {
        if (!confirm('Reset all pin tracking data? This won\'t delete pins from Pinterest but will allow all products to be re-pinned.')) return;
        ajax('rtpp_reset_pinned', {}, function(res) {
            if (res.success) {
                toast('Pin data reset');
                loadStats();
            }
        });
    });

    /*--------------------------------------------------------------
    # Init
    --------------------------------------------------------------*/
    if (rtpp.connected) {
        loadStats();
        loadBoards();
        loadProducts();
        refreshUser();
    }

    function refreshUser() {
        ajax('rtpp_refresh_user', {}, function(res) {
            if (!res.success || !res.data) return;
            var name = res.data.username || '';
            if (!name && res.data.raw) {
                name = res.data.raw.username || res.data.raw.business_name || '';
            }
            // Update debug display
            if (res.data.raw) {
                $('#rtpp-debug-user').text(JSON.stringify(res.data.raw));
            }
            if (name) {
                $('#rtpp-username').text(name);
                $('#rtpp-username-settings').text(name);
                $('#step-username-display').text(' as @' + name);
            } else {
                $('#rtpp-username').closest('.rtpp-status').html('● Connected');
                $('#rtpp-username-settings').text('your account');
                $('#step-username-display').text('');
            }
        });
    }

    $(document).on('click', '#rtpp-refresh-user-btn', function() {
        $(this).text('Refreshing...').prop('disabled', true);
        refreshUser();
        setTimeout(function() {
            $('#rtpp-refresh-user-btn').text('Refresh Account Info').prop('disabled', false);
            toast('Account info refreshed');
        }, 2000);
    });

    /*--------------------------------------------------------------
    # Sandbox Toggle
    --------------------------------------------------------------*/
    function handleSandboxToggle(sandboxOn, $toggle) {
        $toggle.prop('disabled', true);

        ajax('rtpp_toggle_sandbox', { sandbox_mode: sandboxOn ? 1 : 0 }, function(res) {
            $toggle.prop('disabled', false);

            if (!res.success) {
                toast('Failed to switch mode', true);
                $toggle.prop('checked', !sandboxOn); // revert
                return;
            }

            var onText = '<span style="color:#e67e22;">ON — Pins are sent to the sandbox only. No real pins will be created.</span>';
            var offText = '<span style="color:#27ae60;">OFF — Pins post live to your real Pinterest boards.</span>';

            $('#sandbox-mode-label').html(sandboxOn ? onText : offText);
            $('#sandbox-settings-hint').html(sandboxOn ? onText : offText);

            // Sync both toggles
            $('#rtpp-sandbox-toggle, #rtpp-sandbox-toggle-settings').prop('checked', sandboxOn);

            // Show/hide sandbox header badge
            if (sandboxOn) {
                if (!$('.rtpp-sandbox-badge').length) {
                    $('.rtpp-header > div:last').prepend('<span class="rtpp-sandbox-badge">🧪 SANDBOX MODE</span>');
                }
            } else {
                $('.rtpp-sandbox-badge').remove();
            }

            toast(res.data.message || 'Mode switched');

            // If it disconnected, reload so connect button appears
            if (res.data.disconnected) {
                setTimeout(function() { location.reload(); }, 1500);
            }
        });
    }

    $(document).on('change', '#rtpp-sandbox-toggle', function() {
        handleSandboxToggle($(this).is(':checked'), $(this));
    });

    $(document).on('change', '#rtpp-sandbox-toggle-settings', function() {
        handleSandboxToggle($(this).is(':checked'), $(this));
    });

    function updateSteps() {
        // Step 3: check if there are pins or scheduled items
        ajax('rtpp_get_stats', {}, function(res) {
            if (!res.success) return;
            var s = res.data;
            if (s.total_pinned > 0 || s.pending > 0) {
                $('#step-schedule').addClass('done');
                $('#step-done').addClass('done');
            }
        });
    }

    // Update steps when stats are loaded
    $(document).on('click', '#rtpp-pin-all', function() {
        setTimeout(function() { updateSteps(); }, 2000);
    });

    // Also update steps on initial load
    if (rtpp.connected) {
        updateSteps();
    }

})(jQuery);
