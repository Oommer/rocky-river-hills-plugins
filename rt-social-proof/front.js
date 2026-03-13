/* RT Social Proof — Frontend JS */
(function($) {
    'use strict';

    var notifications = [];
    var currentIndex = 0;
    var isActive = true;
    var container;
    var s;

    function init() {
        s = rtsp.settings || {};
        container = document.getElementById('rtsp-container');
        if (!container) return;

        container.className = s.position || 'bottom-left';

        // Apply custom colors via CSS variables
        container.style.setProperty('--rtsp-bg', s.bg_color || '#ffffff');
        container.style.setProperty('--rtsp-text', s.text_color || '#333333');
        container.style.setProperty('--rtsp-accent', s.accent_color || '#A2755A');

        // Fetch notifications
        $.post(rtsp.ajax_url, {
            action: 'rtsp_get_notifications',
            nonce: rtsp.nonce,
            product_id: rtsp.product_id || 0
        }, function(res) {
            if (res.success && res.data.notifications.length) {
                notifications = res.data.notifications;
                setTimeout(showNext, s.initial_delay || 5000);
            }
        });

        // Track product view if on product page
        if (rtsp.product_id) {
            $.post(rtsp.ajax_url, {
                action: 'rtsp_track_view',
                nonce: rtsp.nonce,
                product_id: rtsp.product_id
            });
        }

        // Track add to cart
        $(document.body).on('added_to_cart', function(e, fragments, hash, $btn) {
            var pid = $btn.data('product_id') || 0;
            if (pid) {
                $.post(rtsp.ajax_url, {
                    action: 'rtsp_track_cart',
                    nonce: rtsp.nonce,
                    product_id: pid
                });
            }
        });

        // Pause on hover
        $(container).on('mouseenter', '.rtsp-toast', function() {
            isActive = false;
        }).on('mouseleave', '.rtsp-toast', function() {
            isActive = true;
        });
    }

    function showNext() {
        if (!isActive || !notifications.length) return;

        if (currentIndex >= notifications.length) {
            currentIndex = 0; // Loop
        }

        var n = notifications[currentIndex];
        currentIndex++;

        var toast = buildToast(n);
        container.innerHTML = '';
        container.appendChild(toast);

        // Trigger animation
        var animClass = (s.animation === 'fade') ? 'fade-in' : 'slide-in';
        toast.classList.add(animClass, s.position || 'bottom-left');

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.classList.add('show');
            });
        });

        // Auto-hide after duration
        var hideTimer = setTimeout(function() {
            hideToast(toast, function() {
                setTimeout(showNext, s.delay_between || 12000);
            });
        }, s.display_duration || 5000);

        // Close button
        $(toast).on('click', '.rtsp-toast-close', function(e) {
            e.preventDefault();
            e.stopPropagation();
            clearTimeout(hideTimer);
            hideToast(toast, function() {
                setTimeout(showNext, s.delay_between || 12000);
            });
        });

        // Click to go to product
        $(toast).on('click', function(e) {
            if ($(e.target).closest('.rtsp-toast-close').length) return;
            if (n.product_url) {
                window.location.href = n.product_url;
            }
        });
    }

    function hideToast(toast, callback) {
        toast.classList.remove('show');
        toast.classList.add('hide');
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            if (callback) callback();
        }, 400);
    }

    function buildToast(n) {
        var el = document.createElement('div');
        el.className = 'rtsp-toast';
        el.style.background = s.bg_color || '#ffffff';
        el.style.borderRadius = (s.border_radius || 10) + 'px';

        var html = '';

        // Product image
        if (s.show_image && n.product_image) {
            html += '<img class="rtsp-toast-img" src="' + esc(n.product_image) + '" alt="" loading="lazy" />';
        }

        html += '<div class="rtsp-toast-content">';

        // Activity text
        html += '<p class="rtsp-toast-text" style="color:' + esc(s.text_color || '#333') + '">' + esc(n.text) + '</p>';

        // Product name
        html += '<p class="rtsp-toast-product" style="color:' + esc(s.text_color || '#333') + '">' + esc(n.product_name) + '</p>';

        // Meta line
        html += '<div class="rtsp-toast-meta">';
        html += '<span class="rtsp-toast-dot ' + esc(n.type) + '"></span>';

        if (s.show_time && n.time_ago) {
            html += '<span class="rtsp-toast-time">' + esc(n.time_ago) + '</span>';
        }

        if (n.verified === true) {
            html += '<span class="rtsp-toast-verified" style="color:' + esc(s.accent_color || '#A2755A') + '">'
                + '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 110 16A8 8 0 018 0zm3.41 5.59a.75.75 0 00-1.06-1.06L7 7.88 5.65 6.53a.75.75 0 00-1.06 1.06l1.88 1.88a.75.75 0 001.06 0l3.88-3.88z"/></svg>'
                + 'Verified</span>';
        }
        html += '</div>'; // meta
        html += '</div>'; // content

        // Close button
        if (s.show_close) {
            html += '<button class="rtsp-toast-close" aria-label="Close">&times;</button>';
        }

        el.innerHTML = html;
        return el;
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Initialize when DOM ready
    $(init);

})(jQuery);
