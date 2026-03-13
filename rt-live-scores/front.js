/* RT Live Scores — Frontend v2.0 */
(function(){
    'use strict';

    var ticker = document.getElementById('rtls-ticker');
    if (!ticker) return;

    var scroll = ticker.querySelector('.rtls-scroll');
    var track = document.getElementById('rtls-track');
    var clone = ticker.querySelector('.rtls-clone');
    var refresh = (parseInt(ticker.dataset.refresh, 10) || 60) * 1000;

    // Set scroll speed based on content width
    function setSpeed() {
        if (!track) return;
        var w = track.scrollWidth;
        // ~20px per second for comfortable reading speed
        var dur = Math.max(30, w / 20);
        scroll.style.animationDuration = dur + 's';
    }

    setSpeed();

    // Auto-refresh scores
    function refreshScores() {
        fetch(rtls.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=rtls_refresh'
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.data.html) {
                track.innerHTML = res.data.html;
                if (clone) clone.innerHTML = res.data.html;
                setSpeed();
            }
        })
        .catch(function(){});
    }

    setInterval(refreshScores, refresh);
})();
