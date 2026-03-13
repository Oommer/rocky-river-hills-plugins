<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;
?>
<div class="wrap rrh-ig-wrap">
    <h1>📅 Content Calendar</h1>

    <div class="rrh-ig-card">
        <div class="rrh-ig-cal-nav">
            <button type="button" id="rrh-cal-prev" class="button">← Prev</button>
            <h2 id="rrh-cal-title" style="margin:0;"></h2>
            <button type="button" id="rrh-cal-next" class="button">Next →</button>
        </div>

        <div class="rrh-ig-cal-legend">
            <span class="rrh-ig-cal-dot published"></span> Published
            <span class="rrh-ig-cal-dot queued"></span> Queued
            <span class="rrh-ig-cal-dot failed"></span> Failed
        </div>

        <div id="rrh-ig-calendar" class="rrh-ig-calendar"></div>
    </div>
</div>
