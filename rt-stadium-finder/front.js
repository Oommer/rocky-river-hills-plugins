/* RT Stadium Finder — Frontend JS */
(function($){
    'use strict';

    var state = { sport: '', league: '', filter: 'all', allProducts: [] };

    /* Initialize sports */
    $(document).ready(function() {
        renderSports();
    });

    function renderSports() {
        var html = '';
        $.each(rtsf.sports, function(key, sport) {
            html += '<div class="rtsf-sport-card" data-sport="' + key + '">';
            html += '<span class="rtsf-sport-icon">' + sport.icon + '</span>';
            html += '<span class="rtsf-sport-name">' + sport.name + '</span>';
            html += '</div>';
        });
        $('#rtsf-sports').html(html);
    }

    /* Sport selection */
    $(document).on('click', '.rtsf-sport-card', function() {
        state.sport = $(this).data('sport');
        var sport = rtsf.sports[state.sport];
        if (!sport) return;

        $('#rtsf-sport-name').text(sport.icon + ' ' + sport.name);

        // If only one league, skip straight to results
        var leagues = Object.keys(sport.leagues);
        if (leagues.length === 1) {
            state.league = leagues[0];
            var lg = sport.leagues[state.league];
            $('#rtsf-league-name').text(sport.icon + ' ' + sport.name + ' → ' + lg.name);
            goToStep('results');
            loadProducts();
            return;
        }

        // Render leagues
        var html = '';
        $.each(sport.leagues, function(key, lg) {
            html += '<div class="rtsf-league-card" data-league="' + key + '">' + lg.name + '</div>';
        });
        $('#rtsf-leagues').html(html);
        goToStep('league');
    });

    /* League selection */
    $(document).on('click', '.rtsf-league-card', function() {
        state.league = $(this).data('league');
        var sport = rtsf.sports[state.sport];
        var lg = sport.leagues[state.league];
        $('#rtsf-league-name').text(sport.icon + ' ' + sport.name + ' → ' + lg.name);
        goToStep('results');
        loadProducts();
    });

    /* Back navigation */
    $(document).on('click', '.rtsf-back', function(e) {
        e.preventDefault();
        var target = $(this).data('back');
        goToStep(target);
    });

    /* Step navigation */
    function goToStep(step) {
        $('.rtsf-step').removeClass('active');
        $('#rtsf-step-' + step).addClass('active');

        // Reset search and filter when going back
        if (step !== 'results') {
            $('#rtsf-search').val('');
            state.filter = 'all';
            $('.rtsf-filter').removeClass('active').first().addClass('active');
        }
    }

    /* Load products via AJAX */
    function loadProducts(search) {
        $('#rtsf-products').empty();
        $('#rtsf-empty').hide();
        $('#rtsf-loading').show();
        $('#rtsf-result-count').text('');

        $.post(rtsf.ajax_url, {
            action: 'rtsf_search',
            nonce: rtsf.nonce,
            sport: state.sport,
            league: state.league,
            search: search || '',
        }, function(r) {
            $('#rtsf-loading').hide();
            if (r.success && r.data.products.length) {
                state.allProducts = r.data.products;
                renderProducts(state.allProducts);
            } else {
                state.allProducts = [];
                $('#rtsf-empty').show();
                $('#rtsf-result-count').text('0 products found');
            }
        });
    }

    /* Render products */
    function renderProducts(products) {
        var filtered = products;
        if (state.filter !== 'all') {
            filtered = products.filter(function(p) { return p.type === state.filter; });
        }

        $('#rtsf-result-count').text(filtered.length + ' product' + (filtered.length !== 1 ? 's' : '') + ' found');

        if (!filtered.length) {
            $('#rtsf-products').empty();
            $('#rtsf-empty').show();
            return;
        }
        $('#rtsf-empty').hide();

        var html = '';
        filtered.forEach(function(p) {
            var img = p.image ? '<img class="rtsf-product-img" src="' + p.image + '" alt="' + escHtml(p.name) + '" loading="lazy">' : '<div class="rtsf-product-img"></div>';
            html += '<div class="rtsf-product-card" data-type="' + p.type + '">';
            html += '<a href="' + p.url + '">' + img + '</a>';
            html += '<div class="rtsf-product-info">';
            html += '<div class="rtsf-product-type">' + p.type + '</div>';
            html += '<div class="rtsf-product-name">' + escHtml(p.name) + '</div>';
            html += '<div class="rtsf-product-bottom">';
            html += '<span class="rtsf-product-price">' + p.price + '</span>';
            html += '<a href="' + p.url + '" class="rtsf-product-btn">View</a>';
            html += '</div></div></div>';
        });
        $('#rtsf-products').html(html);
    }

    /* Filter buttons */
    $(document).on('click', '.rtsf-filter', function() {
        $('.rtsf-filter').removeClass('active');
        $(this).addClass('active');
        state.filter = $(this).data('filter');
        renderProducts(state.allProducts);
    });

    /* Search with debounce */
    var searchTimer;
    $(document).on('input', '#rtsf-search', function() {
        var val = $(this).val().trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            if (val.length >= 2 || val.length === 0) {
                loadProducts(val);
            }
        }, 400);
    });

    /* Utility */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
