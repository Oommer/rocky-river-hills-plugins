(function($) {
    'use strict';
    
    var currentDays = 7;
    var currentView = 'overview';
    var realtimeInterval = null;
    var mapRefreshInterval = null;
    var viewsChart = null;
    var worldMap = null;
    var mapMarkersLayer = null;
    var mapRecentLayer = null;
    
    var countryFlags = {
        'US': '🇺🇸', 'GB': '🇬🇧', 'CA': '🇨🇦', 'AU': '🇦🇺', 'DE': '🇩🇪',
        'FR': '🇫🇷', 'IT': '🇮🇹', 'ES': '🇪🇸', 'NL': '🇳🇱', 'SE': '🇸🇪',
        'NO': '🇳🇴', 'DK': '🇩🇰', 'FI': '🇫🇮', 'PL': '🇵🇱', 'RU': '🇷🇺',
        'JP': '🇯🇵', 'CN': '🇨🇳', 'IN': '🇮🇳', 'BR': '🇧🇷', 'MX': '🇲🇽',
        'AR': '🇦🇷', 'CL': '🇨🇱', 'CO': '🇨🇴', 'ZA': '🇿🇦', 'KR': '🇰🇷',
        'SG': '🇸🇬', 'MY': '🇲🇾', 'TH': '🇹🇭', 'VN': '🇻🇳', 'PH': '🇵🇭',
        'ID': '🇮🇩', 'NZ': '🇳🇿', 'IE': '🇮🇪', 'CH': '🇨🇭', 'AT': '🇦🇹',
        'BE': '🇧🇪', 'PT': '🇵🇹', 'GR': '🇬🇷', 'CZ': '🇨🇿', 'HU': '🇭🇺',
        'RO': '🇷🇴', 'TR': '🇹🇷', 'IL': '🇮🇱', 'SA': '🇸🇦', 'AE': '🇦🇪',
        'EG': '🇪🇬', 'NG': '🇳🇬', 'KE': '🇰🇪', 'PK': '🇵🇰', 'BD': '🇧🇩'
    };
    
    var deviceIcons = { 'Desktop': '🖥️', 'Mobile': '📱', 'Tablet': '📱', 'Unknown': '💻' };
    var browserIcons = { 'Firefox': '🦊', 'Chrome': '🌐', 'Safari': '🧭', 'Edge': '🌊', 'Internet Explorer': '📊', 'Unknown': '🌐' };
    
    $(document).ready(function() {
        initEventListeners();
        showOverviewView();
    });
    
    function initEventListeners() {
        $('.rt-date-btn').on('click', function() {
            $('.rt-date-btn').removeClass('active');
            $(this).addClass('active');
            currentDays = $(this).data('days');
            if (currentView === 'overview') loadStats();
            else if (currentView === 'insights') { loadStats(); loadBotAnalysis(); }
            else if (currentView === 'map') loadMapData();
        });
        
        $('.rt-view-btn').on('click', function() {
            $('.rt-view-btn').removeClass('active');
            $(this).addClass('active');
            currentView = $(this).data('view');
            if (currentView === 'overview') showOverviewView();
            else if (currentView === 'insights') showInsightsView();
            else if (currentView === 'map') showMapView();
            else if (currentView === 'realtime') showRealtimeView();
        });
        
        $('#rt-purge-btn').on('click', function() {
            if (confirm('⚠️ Are you sure you want to purge ALL traffic data?\n\nThis action cannot be undone.')) {
                if (confirm('This is your last chance — really delete everything?')) {
                    purgeData();
                }
            }
        });
        
        $('#rt-modal-close, #rt-profile-modal').on('click', function(e) {
            if (e.target === this) $('#rt-profile-modal').fadeOut(200);
        });
        
        $(document).on('click', '.rt-visitor-toggle', function() {
            var card = $(this).closest('.rt-visitor-card');
            card.toggleClass('rt-expanded');
            $(this).text(card.hasClass('rt-expanded') ? '▲ Hide pages' : '▼ ' + $(this).data('label'));
        });
        
        $(document).on('click', '.rt-view-profile', function() {
            openVisitorProfile($(this).data('ip'));
        });
    }
    
    function showOverviewView() {
        stopIntervals();
        $('.rt-view').removeClass('active');
        $('#rt-overview-view').addClass('active');
        loadStats();
    }
    
    function showInsightsView() {
        stopIntervals();
        $('.rt-view').removeClass('active');
        $('#rt-insights-view').addClass('active');
        loadStats();
        loadBotAnalysis();
    }
    
    function showMapView() {
        stopIntervals();
        $('.rt-view').removeClass('active');
        $('#rt-map-view').addClass('active');
        setTimeout(function() { initMap(); loadMapData(); }, 100);
        mapRefreshInterval = setInterval(loadMapData, 30000);
    }
    
    function showRealtimeView() {
        stopIntervals();
        $('.rt-view').removeClass('active');
        $('#rt-realtime-view').addClass('active');
        loadRealtime();
        realtimeInterval = setInterval(loadRealtime, 10000);
    }
    
    function stopIntervals() {
        if (realtimeInterval) { clearInterval(realtimeInterval); realtimeInterval = null; }
        if (mapRefreshInterval) { clearInterval(mapRefreshInterval); mapRefreshInterval = null; }
    }
    
    // ========== OVERVIEW ==========
    
    function loadStats() {
        $('#rt-loading').addClass('active');
        $('.rt-view').removeClass('active');
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_get_stats', nonce: rtTrafficAjax.nonce, days: currentDays },
            success: function(response) {
                if (response.success) renderStats(response.data);
                $('#rt-loading').removeClass('active');
                if (currentView === 'overview') $('#rt-overview-view').addClass('active');
                else if (currentView === 'insights') $('#rt-insights-view').addClass('active');
            },
            error: function() { $('#rt-loading').removeClass('active'); alert('Error loading statistics'); }
        });
    }
    
    function renderStats(data) {
        $('#rt-total-views').text(formatNumber(data.total_views));
        $('#rt-unique-visitors').text(formatNumber(data.unique_visitors));
        renderChart(data.daily_stats);
        
        renderDetailedList('#rt-top-pages', data.top_pages, function(item) {
            var path = '';
            try { var u = new URL(item.page_url); path = u.pathname === '/' ? '/' : u.pathname.replace(/\/$/, ''); if (u.search) path += u.search; } catch(e) { path = item.page_url; }
            return { label: item.page_title || 'Untitled', sublabel: path, value: item.views, subvalue: item.unique_views + ' unique' };
        });
        
        renderList('#rt-top-countries', data.top_countries, function(item) {
            return { label: (countryFlags[item.country_code] || '🌍') + ' ' + item.country, value: item.views };
        });
        renderList('#rt-top-cities', data.top_cities, function(item) {
            return { label: item.city + ', ' + item.region, value: item.views };
        });
        
        renderList('#rt-top-referrers', data.top_referrers, function(item) {
            var domain = item.referrer_domain || 'Unknown';
            var icon = '🔗';
            if (domain.indexOf('google') !== -1) icon = '🔍';
            else if (domain.indexOf('bing') !== -1) icon = '🔍';
            else if (domain.indexOf('yahoo') !== -1) icon = '🔍';
            else if (domain.indexOf('duckduckgo') !== -1) icon = '🔍';
            else if (domain.indexOf('facebook') !== -1 || domain.indexOf('fb.com') !== -1) icon = '📘';
            else if (domain.indexOf('twitter') !== -1 || domain.indexOf('t.co') !== -1) icon = '🐦';
            else if (domain.indexOf('instagram') !== -1) icon = '📷';
            else if (domain.indexOf('linkedin') !== -1) icon = '💼';
            else if (domain.indexOf('reddit') !== -1) icon = '🟠';
            else if (domain.indexOf('pinterest') !== -1) icon = '📌';
            else if (domain.indexOf('youtube') !== -1) icon = '▶️';
            return { label: icon + ' ' + domain, value: item.unique_views };
        });
        
        renderList('#rt-devices', data.devices, function(item) {
            return { label: (deviceIcons[item.device] || '💻') + ' ' + item.device, value: item.views };
        });
        renderBrowsers('#rt-browsers', data.browsers);
        
        // Page load times
        renderLoadTimes(data.avg_load_time, data.page_load_times);
    }
    
    function renderLoadTimes(avgMs, pages) {
        // Show overall average
        var avgEl = $('#rt-avg-load-time');
        if (avgMs) {
            var avgColor = avgMs < 1500 ? '#0d7a3f' : avgMs < 3000 ? '#b86e00' : '#d63638';
            avgEl.html('<span style="color:' + avgColor + '; font-weight:700; font-size:18px;">' + formatMs(avgMs) + '</span> <span style="color:#757575; font-size:13px;">avg</span>');
        } else {
            avgEl.html('<span style="color:#999; font-size:13px;">No data yet</span>');
        }
        
        // Show per-page list
        var container = $('#rt-page-load-times').empty();
        if (!pages || !pages.length) {
            container.html('<div class="rt-empty"><div class="rt-empty-icon">⚡</div><div class="rt-empty-text">No load time data yet — data will appear as visitors browse your site</div></div>');
            return;
        }
        
        pages.forEach(function(page) {
            var avg = parseInt(page.avg_load_time);
            var min = parseInt(page.min_load_time);
            var max = parseInt(page.max_load_time);
            var samples = parseInt(page.sample_count);
            var color = avg < 1500 ? '#0d7a3f' : avg < 3000 ? '#b86e00' : '#d63638';
            var bgColor = avg < 1500 ? '#e6f9f0' : avg < 3000 ? '#fff3e0' : '#fde8e8';
            
            var path = page.page_url;
            try { var u = new URL(page.page_url); path = u.pathname === '/' ? '/' : u.pathname.replace(/\/$/, ''); } catch(e) {}
            
            container.append(
                '<div class="rt-list-item rt-list-item-detailed">' +
                    '<div class="rt-list-item-info">' +
                        '<div class="rt-list-item-label">' + (page.page_title || 'Untitled') + '</div>' +
                        '<div class="rt-list-item-sublabel">' + path + '</div>' +
                    '</div>' +
                    '<div class="rt-load-stats">' +
                        '<span class="rt-load-badge" style="background:' + bgColor + '; color:' + color + ';">' + formatMs(avg) + '</span>' +
                        '<span class="rt-load-range">' + formatMs(min) + ' – ' + formatMs(max) + ' (' + samples + ' samples)</span>' +
                    '</div>' +
                '</div>'
            );
        });
    }
    
    function formatMs(ms) {
        if (ms < 1000) return ms + 'ms';
        return (ms / 1000).toFixed(1) + 's';
    }
    
    function renderChart(dailyStats) {
        var ctx = document.getElementById('rt-views-chart');
        if (!ctx) return;
        if (viewsChart) viewsChart.destroy();
        viewsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dailyStats.map(function(s) { return formatDate(s.date); }),
                datasets: [{ label: 'Views', data: dailyStats.map(function(s) { return parseInt(s.views); }),
                    borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.1)',
                    borderWidth: 3, tension: 0.4, fill: true,
                    pointRadius: 4, pointBackgroundColor: '#667eea', pointBorderColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 12, cornerRadius: 6 } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } }
            }
        });
    }
    
    // ========== LIST RENDERERS ==========
    
    function renderList(selector, items, formatFn) {
        var c = $(selector); c.empty();
        if (!items || !items.length) { c.html('<div class="rt-empty"><div class="rt-empty-icon">📊</div><div class="rt-empty-text">No data available</div></div>'); return; }
        items.forEach(function(item) {
            var f = formatFn(item);
            c.append('<div class="rt-list-item"><div class="rt-list-item-label" title="' + f.label + '">' + f.label + '</div><div class="rt-list-item-value"><span class="rt-list-item-badge">' + f.value + '</span></div></div>');
        });
    }
    
    function renderDetailedList(selector, items, formatFn) {
        var c = $(selector); c.empty();
        if (!items || !items.length) { c.html('<div class="rt-empty"><div class="rt-empty-icon">📊</div><div class="rt-empty-text">No data available</div></div>'); return; }
        items.forEach(function(item) {
            var f = formatFn(item);
            var sub = f.sublabel ? '<div class="rt-list-item-sublabel" title="' + f.sublabel + '">' + f.sublabel + '</div>' : '';
            var sv = f.subvalue ? '<div class="rt-list-item-subvalue">' + f.subvalue + '</div>' : '';
            c.append('<div class="rt-list-item rt-list-item-detailed"><div class="rt-list-item-info"><div class="rt-list-item-label" title="' + f.label + '">' + f.label + '</div>' + sub + '</div><div class="rt-list-item-stats"><span class="rt-list-item-badge">' + f.value + '</span>' + sv + '</div></div>');
        });
    }
    
    function renderBrowsers(selector, browsers) {
        var c = $(selector); c.empty();
        if (!browsers || !browsers.length) { c.html('<div class="rt-empty"><div class="rt-empty-icon">🌐</div><div class="rt-empty-text">No data available</div></div>'); return; }
        browsers.forEach(function(b) {
            c.append('<div class="rt-list-item"><div class="rt-list-item-label">' + (browserIcons[b.browser]||'🌐') + ' ' + b.browser + '</div><div class="rt-list-item-value"><span class="rt-list-item-badge">' + b.views + '</span></div></div>');
        });
    }
    
    // ========== WORLD MAP ==========
    
    function initMap() {
        if (worldMap) { worldMap.invalidateSize(); return; }
        worldMap = L.map('rt-world-map', { center: [30, 0], zoom: 2, minZoom: 2, maxZoom: 12 });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OSM &copy; CARTO', subdomains: 'abcd', maxZoom: 19
        }).addTo(worldMap);
        mapMarkersLayer = L.layerGroup().addTo(worldMap);
        mapRecentLayer = L.layerGroup().addTo(worldMap);
    }
    
    function loadMapData() {
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_get_map_data', nonce: rtTrafficAjax.nonce, days: currentDays },
            success: function(r) { if (r.success) renderMapData(r.data); }
        });
    }
    
    function renderMapData(data) {
        if (!worldMap) return;
        mapMarkersLayer.clearLayers();
        var maxV = 1;
        if (data.clusters && data.clusters.length) maxV = Math.max.apply(null, data.clusters.map(function(c) { return parseInt(c.visits); }));
        
        if (data.clusters) data.clusters.forEach(function(cl) {
            var lat = parseFloat(cl.latitude), lng = parseFloat(cl.longitude);
            if (isNaN(lat) || isNaN(lng)) return;
            var v = parseInt(cl.visits), u = parseInt(cl.unique_visitors);
            var r = Math.max(5, Math.min(30, 5 + (v / maxV) * 25));
            var flag = countryFlags[cl.country_code] || '🌍';
            var loc = cl.city ? cl.city + ', ' + cl.region + ', ' + cl.country : cl.country;
            var isRecent = parseInt(cl.is_recent) === 1;
            
            var fillColor = isRecent ? '#00d084' : '#667eea';
            var borderColor = isRecent ? '#00a86b' : '#4a5bd4';
            
            var circle = L.circleMarker([lat, lng], { 
                radius: r, fillColor: fillColor, color: borderColor, 
                weight: isRecent ? 2.5 : 1.5, opacity: 0.9, fillOpacity: isRecent ? 0.6 : 0.5 
            });
            
            var recentLabel = isRecent ? ' <span style="color:#00d084;font-weight:600;">● Active</span>' : '';
            circle.bindPopup(
                '<div class="rt-map-popup">' +
                    '<div class="rt-map-popup-title">' + flag + ' ' + loc + recentLabel + '</div>' +
                    '<div class="rt-map-popup-stat"><strong>' + v + '</strong> views &bull; <strong>' + u + '</strong> unique</div>' +
                    '<div class="rt-map-popup-time">Last: ' + fmtAgo(cl.last_visit) + '</div>' +
                '</div>'
            );
            mapMarkersLayer.addLayer(circle);
        });
    }
    
    // ========== REAL-TIME (Matomo-style) ==========
    
    function loadRealtime() {
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_get_realtime', nonce: rtTrafficAjax.nonce },
            success: function(r) { if (r.success) renderRealtime(r.data); }
        });
    }
    
    function renderRealtime(data) {
        var container = $('#rt-realtime-list');
        container.empty();
        var count = data.count || 0;
        $('#rt-visitor-count').text(count + ' visitor' + (count !== 1 ? 's' : ''));
        
        if (!data.visitors || !data.visitors.length) {
            container.html('<div class="rt-empty"><div class="rt-empty-icon">👥</div><div class="rt-empty-text">No visitors in the last 2 hours</div></div>');
            return;
        }
        
        data.visitors.forEach(function(visitor) {
            var flag = countryFlags[visitor.country_code] || '🌍';
            var deviceIcon = deviceIcons[visitor.device] || '💻';
            var browserIcon = browserIcons[visitor.browser] || '🌐';
            var location = visitor.city ? visitor.city + ', ' + visitor.region : (visitor.country || 'Unknown');
            
            var visitorType = visitor.is_returning 
                ? '<span class="rt-badge rt-badge-returning">↩ Returning</span>' 
                : '<span class="rt-badge rt-badge-new">★ New</span>';
            
            var referrerText = 'Direct Entry';
            if (visitor.session_referrer) {
                try {
                    var refUrl = new URL(visitor.session_referrer);
                    referrerText = refUrl.hostname;
                    if (refUrl.hostname.indexOf('google') !== -1) referrerText = '🔍 Google';
                    else if (refUrl.hostname.indexOf('bing') !== -1) referrerText = '🔍 Bing';
                    else if (refUrl.hostname.indexOf('yahoo') !== -1) referrerText = '🔍 Yahoo';
                    else if (refUrl.hostname.indexOf('duckduckgo') !== -1) referrerText = '🔍 DuckDuckGo';
                    else if (refUrl.hostname.indexOf('facebook') !== -1 || refUrl.hostname.indexOf('fb.com') !== -1) referrerText = '📘 Facebook';
                    else if (refUrl.hostname.indexOf('twitter') !== -1 || refUrl.hostname.indexOf('t.co') !== -1) referrerText = '🐦 Twitter/X';
                    else if (refUrl.hostname.indexOf('instagram') !== -1) referrerText = '📷 Instagram';
                    else if (refUrl.hostname.indexOf('linkedin') !== -1) referrerText = '💼 LinkedIn';
                    else if (refUrl.hostname.indexOf('reddit') !== -1) referrerText = '🟠 Reddit';
                    else if (refUrl.hostname.indexOf('pinterest') !== -1) referrerText = '📌 Pinterest';
                } catch(e) { referrerText = visitor.session_referrer; }
            }
            
            var pagesHtml = '';
            if (visitor.pages && visitor.pages.length) {
                pagesHtml = '<div class="rt-visitor-pages">';
                visitor.pages.forEach(function(page) {
                    var pagePath = page.page_url;
                    try { pagePath = new URL(page.page_url).pathname; } catch(e) {}
                    pagesHtml += '<div class="rt-page-entry">' +
                        '<span class="rt-page-icon">📄</span>' +
                        '<div class="rt-page-info">' +
                            '<div class="rt-page-title">' + (page.page_title || 'Untitled') + '</div>' +
                            '<div class="rt-page-url">' + pagePath + '</div>' +
                        '</div>' +
                        (page.load_time ? '<div class="rt-page-load">' + formatMs(parseInt(page.load_time)) + '</div>' : '') +
                        '<div class="rt-page-time">' + formatTime(page.visit_time) + '</div>' +
                    '</div>';
                });
                pagesHtml += '</div>';
            }
            
            var toggleLabel = visitor.page_count + ' page' + (visitor.page_count !== 1 ? 's' : '') + ' viewed';
            
            container.append(
                '<div class="rt-visitor-card">' +
                    '<div class="rt-visitor-summary">' +
                        '<div class="rt-visitor-main">' +
                            '<div class="rt-visitor-top-row">' +
                                '<span class="rt-visitor-time">' + formatDateTime(visitor.latest_visit) + '</span>' +
                                visitorType +
                            '</div>' +
                            '<div class="rt-visitor-meta">' +
                                '<span class="rt-visitor-meta-item">' + flag + ' ' + location + ', ' + visitor.country + '</span>' +
                                '<span class="rt-visitor-meta-sep">&bull;</span>' +
                                '<span class="rt-visitor-meta-item">' + browserIcon + ' ' + visitor.browser + '</span>' +
                                '<span class="rt-visitor-meta-sep">&bull;</span>' +
                                '<span class="rt-visitor-meta-item">' + visitor.os + '</span>' +
                                '<span class="rt-visitor-meta-sep">&bull;</span>' +
                                '<span class="rt-visitor-meta-item">' + deviceIcon + ' ' + visitor.device + '</span>' +
                            '</div>' +
                            '<div class="rt-visitor-ref">' +
                                '<span class="rt-visitor-ref-label">From:</span> ' + referrerText +
                            '</div>' +
                        '</div>' +
                        '<div class="rt-visitor-actions">' +
                            '<div class="rt-visitor-stat">' + visitor.page_count + ' <small>pages</small></div>' +
                            '<div class="rt-visitor-stat">' + visitor.total_visits + ' <small>visits</small></div>' +
                            '<button class="rt-view-profile" data-ip="' + visitor.ip_hash + '" title="View full visitor profile">👤 Profile</button>' +
                        '</div>' +
                    '</div>' +
                    '<button class="rt-visitor-toggle" data-label="' + toggleLabel + '">▼ ' + toggleLabel + '</button>' +
                    pagesHtml +
                '</div>'
            );
        });
    }
    
    // ========== VISITOR PROFILE MODAL ==========
    
    function openVisitorProfile(ipHash) {
        var modal = $('#rt-profile-modal');
        var content = $('#rt-profile-content');
        content.html('<div style="text-align:center;padding:40px;"><div class="rt-spinner"></div><br>Loading visitor profile...</div>');
        modal.fadeIn(200);
        
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_get_visitor_profile', nonce: rtTrafficAjax.nonce, ip_hash: ipHash },
            success: function(r) {
                if (r.success) renderVisitorProfile(r.data, content);
                else content.html('<div class="rt-empty"><div class="rt-empty-icon">😕</div><div class="rt-empty-text">Could not load visitor profile</div></div>');
            },
            error: function() {
                content.html('<div class="rt-empty"><div class="rt-empty-icon">⚠️</div><div class="rt-empty-text">Error loading profile</div></div>');
            }
        });
    }
    
    function renderVisitorProfile(data, container) {
        var flag = countryFlags[data.country_code] || '🌍';
        var deviceIcon = deviceIcons[data.device] || '💻';
        var browserIcon = browserIcons[data.browser] || '🌐';
        var location = data.city ? data.city + ', ' + data.region + ', ' + data.country : (data.country || 'Unknown');
        
        var html = '<div class="rt-profile">' +
            '<div class="rt-profile-header">' +
                '<div class="rt-profile-avatar">👤</div>' +
                '<div class="rt-profile-info">' +
                    '<div class="rt-profile-id">Visitor ' + data.ip_hash + '</div>' +
                    '<div class="rt-profile-location">' + flag + ' ' + location + '</div>' +
                    '<div class="rt-profile-tech">' + browserIcon + ' ' + data.browser + ' &bull; ' + data.os + ' &bull; ' + deviceIcon + ' ' + data.device + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="rt-profile-summary">' +
                '<div class="rt-profile-stat-card"><div class="rt-profile-stat-value">' + data.total_sessions + '</div><div class="rt-profile-stat-label">Visits</div></div>' +
                '<div class="rt-profile-stat-card"><div class="rt-profile-stat-value">' + data.total_pages + '</div><div class="rt-profile-stat-label">Pages Viewed</div></div>' +
                '<div class="rt-profile-stat-card"><div class="rt-profile-stat-value">' + formatDateShort(data.first_visit) + '</div><div class="rt-profile-stat-label">First Visit</div></div>' +
                '<div class="rt-profile-stat-card"><div class="rt-profile-stat-value">' + formatDateShort(data.last_visit) + '</div><div class="rt-profile-stat-label">Last Visit</div></div>' +
            '</div>' +
            '<h3 class="rt-profile-section-title">Visit History</h3>';
        
        data.sessions.forEach(function(session, index) {
            var sessionNum = data.sessions.length - index;
            var firstPage = session[session.length - 1];
            var lastPage = session[0];
            
            var sessionRef = 'Direct Entry';
            if (firstPage.referrer) {
                try { sessionRef = new URL(firstPage.referrer).hostname; } catch(e) { sessionRef = firstPage.referrer; }
            }
            
            html += '<div class="rt-profile-session">' +
                '<div class="rt-profile-session-header">' +
                    '<span class="rt-profile-session-num">Visit #' + sessionNum + '</span>' +
                    '<span class="rt-profile-session-date">' + formatDateTime(lastPage.visit_time) + '</span>' +
                    '<span class="rt-profile-session-meta">' + session.length + ' page' + (session.length!==1?'s':'') + ' &bull; from ' + sessionRef + '</span>' +
                '</div>' +
                '<div class="rt-profile-session-pages">';
            
            var chrono = session.slice().reverse();
            chrono.forEach(function(page) {
                var pagePath = page.page_url;
                try { pagePath = new URL(page.page_url).pathname; } catch(e) {}
                html += '<div class="rt-profile-page-entry">' +
                    '<span class="rt-profile-page-icon">📄</span>' +
                    '<div class="rt-profile-page-info">' +
                        '<div class="rt-profile-page-title">' + (page.page_title || 'Untitled') + '</div>' +
                        '<div class="rt-profile-page-url">' + pagePath + '</div>' +
                    '</div>' +
                    '<div class="rt-profile-page-time">' + formatTime(page.visit_time) + '</div>' +
                '</div>';
            });
            
            html += '</div></div>';
        });
        
        html += '</div>';
        container.html(html);
    }
    
    // ========== PURGE ==========
    
    function purgeData() {
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_purge_data', nonce: rtTrafficAjax.nonce },
            success: function(r) {
                if (r.success) {
                    if (mapMarkersLayer) mapMarkersLayer.clearLayers();
                    if (mapRecentLayer) mapRecentLayer.clearLayers();
                    if (currentView === 'overview') loadStats();
                    else if (currentView === 'map') loadMapData();
                    else if (currentView === 'realtime') loadRealtime();
                    showNotice('All traffic data has been purged successfully.', 'success');
                } else showNotice('Error purging data.', 'error');
            },
            error: function() { showNotice('Error purging data.', 'error'); }
        });
    }
    
    function showNotice(msg, type) {
        var n = $('<div class="rt-notice rt-notice-' + (type==='success'?'success':'error') + '">' + msg + '</div>');
        $('.rt-traffic-wrap').prepend(n);
        setTimeout(function() { n.fadeOut(400, function() { $(this).remove(); }); }, 4000);
    }
    
    // ========== HELPERS ==========
    
    function formatNumber(num) { return new Intl.NumberFormat().format(num); }
    function formatDate(d) { return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }
    function formatDateShort(d) { return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function formatDateTime(d) {
        var dt = new Date(d);
        return dt.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) + ' - ' + dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }
    function formatTime(d) {
        var dt = new Date(d), diff = Math.floor((new Date() - dt) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
        return dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    function fmtAgo(d) {
        var diff = Math.floor((new Date() - new Date(d)) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) { var m = Math.floor(diff / 60); return m + ' min ago'; }
        if (diff < 86400) { var h = Math.floor(diff / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
        var dy = Math.floor(diff / 86400); return dy + (dy === 1 ? ' day ago' : ' days ago');
    }

    // ========== BOT ANALYSIS (v3.2.0) ==========

    function loadBotAnalysis() {
        $('#rt-bot-analysis').html('<div class="rt-spinner"></div>');
        $.ajax({
            url: rtTrafficAjax.ajax_url, type: 'POST',
            data: { action: 'rt_get_bot_analysis', nonce: rtTrafficAjax.nonce, days: currentDays },
            success: function(r) { if (r.success) renderBotAnalysis(r.data); },
            error: function() { $('#rt-bot-analysis').html('<p style="color:#888;">Could not load bot analysis.</p>'); }
        });
    }

    function renderBotAnalysis(b) {
        if (!b || b.total === 0) {
            $('#rt-bot-analysis').html('<p style="color:#888;">No data yet for this period.</p>');
            return;
        }
        var total    = b.total;
        var botPct   = Math.round((b.no_load_time / total) * 100);
        var humanPct = 100 - botPct;

        var rows = '';
        if (b.suspect_countries && b.suspect_countries.length) {
            b.suspect_countries.forEach(function(c) {
                var pct = c.sessions > 0 ? Math.round((c.bot_signals / c.sessions) * 100) : 0;
                var badge, color;
                if (pct >= 80)      { badge = '🤖 Bot';        color = '#c0392b'; }
                else if (pct >= 40) { badge = '⚠️ Suspicious'; color = '#e67e22'; }
                else                { badge = '✅ Human';       color = '#27ae60'; }
                rows += '<tr>' +
                    '<td style="padding:7px 10px;">' + (countryFlags[c.country_code] || '🌍') + ' ' + c.country + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;">' + parseInt(c.sessions).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;">' + parseInt(c.bot_signals).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;"><span style="font-weight:700;color:' + color + ';">' + badge + ' (' + pct + '%)</span></td>' +
                    '</tr>';
            });
        }

        $('#rt-bot-analysis').html(
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:20px;">' +
                '<div style="background:#fdf3f3;border:1px solid #f5c6cb;border-radius:8px;padding:16px;text-align:center;">' +
                    '<div style="font-size:28px;font-weight:700;color:#c0392b;">' + b.no_load_time.toLocaleString() + '</div>' +
                    '<div style="color:#666;font-size:13px;margin-top:4px;">🤖 Likely Bots</div>' +
                    '<div style="color:#999;font-size:12px;">' + botPct + '% — no load time recorded</div>' +
                '</div>' +
                '<div style="background:#f0fdf4;border:1px solid #b7ebc8;border-radius:8px;padding:16px;text-align:center;">' +
                    '<div style="font-size:28px;font-weight:700;color:#27ae60;">' + b.has_load_time.toLocaleString() + '</div>' +
                    '<div style="color:#666;font-size:13px;margin-top:4px;">✅ Likely Human</div>' +
                    '<div style="color:#999;font-size:12px;">' + humanPct + '% — load time confirmed</div>' +
                '</div>' +
            '</div>' +
            (rows ? '<table style="width:100%;border-collapse:collapse;font-size:13px;">' +
                '<thead><tr style="background:#f0f0f0;">' +
                    '<th style="padding:8px 10px;text-align:left;font-weight:600;">Country</th>' +
                    '<th style="padding:8px 10px;text-align:right;font-weight:600;">Sessions</th>' +
                    '<th style="padding:8px 10px;text-align:right;font-weight:600;">Bot Signals</th>' +
                    '<th style="padding:8px 10px;text-align:right;font-weight:600;">Classification</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>' : '')
        );
    }


})(jQuery);
