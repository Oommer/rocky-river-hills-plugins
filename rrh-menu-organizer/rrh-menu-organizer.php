<?php
/**
 * Plugin Name: RRH Menu Organizer
 * Plugin URI: https://rockyriverhills.com
 * Description: Drag-and-drop reordering of the entire WordPress admin sidebar. Tools → Menu Organizer.
 * Version: 2.1.0
 * Author: Rocky River Hills
 * Text Domain: rrh-menu-org
 */

if (!defined('ABSPATH')) exit;

class RRH_Menu_Organizer {

    private static $option_key = 'rrh_menu_organizer_full_order';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'reorder_menu'], 9999);
        add_action('admin_menu', [__CLASS__, 'add_settings_page'], 9999);
        add_action('wp_ajax_rrh_menu_save_order', [__CLASS__, 'ajax_save_order']);
        add_action('wp_ajax_rrh_menu_reset_order', [__CLASS__, 'ajax_reset_order']);
    }

    /**
     * Reorder entire admin menu based on saved order.
     */
    public static function reorder_menu() {
        global $menu;
        if (empty($menu)) return;

        $saved_order = get_option(self::$option_key);
        if (!is_array($saved_order) || empty($saved_order)) return;

        // Build slug → menu item lookup
        $by_slug = [];
        $separators = [];
        foreach ($menu as $key => $item) {
            $slug = $item[2] ?? '';
            if (empty($slug) || (empty($item[0]) && strpos($slug, 'separator') !== false)) {
                $separators[] = $item;
                continue;
            }
            $by_slug[$slug] = $item;
        }

        // Rebuild menu in saved order
        $new_menu = [];
        $pos = 1;
        foreach ($saved_order as $slug) {
            if ($slug === '---separator---') {
                // Insert a separator
                $sep = array_shift($separators) ?: ['', 'read', 'separator' . $pos, '', 'wp-menu-separator'];
                $new_menu[$pos] = $sep;
            } elseif (isset($by_slug[$slug])) {
                $new_menu[$pos] = $by_slug[$slug];
                unset($by_slug[$slug]);
            }
            $pos++;
        }

        // Append any menu items not in saved order (new plugins, etc.)
        foreach ($by_slug as $slug => $item) {
            $new_menu[$pos] = $item;
            $pos++;
        }

        $menu = $new_menu;
    }

    /**
     * Add settings page under Tools.
     */
    public static function add_settings_page() {
        add_submenu_page(
            'tools.php',
            'Menu Organizer',
            'Menu Organizer',
            'manage_options',
            'rrh-menu-organizer',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Render the full drag-and-drop page.
     */
    public static function render_page() {
        global $menu;

        // Known custom plugins for highlighting
        $custom_slugs = [
            'rt-traffic-stats', 'rrh-instagram', 'rrh-events', 'rt-email-sequences',
            'rt-social-proof', 'rt-pinterest-poster', 'rt-coupon-engine', 'rt-google-shopping',
        ];

        $nonce = wp_create_nonce('rrh_menu_org');

        // Build items list in current order
        ksort($menu);
        $items = [];
        foreach ($menu as $pos => $item) {
            $slug = $item[2] ?? '';
            $title = strip_tags($item[0] ?? '');

            if (empty($title) && (empty($slug) || strpos($slug, 'separator') !== false)) {
                $items[] = ['slug' => '---separator---', 'title' => '', 'type' => 'separator', 'custom' => false];
                continue;
            }
            if (empty($slug)) continue;

            $items[] = [
                'slug' => $slug,
                'title' => $title,
                'type' => 'item',
                'custom' => in_array($slug, $custom_slugs),
            ];
        }
        ?>
        <div class="wrap">
            <h1>🔧 Menu Organizer</h1>
            <p>Drag and drop to reorder the entire admin sidebar. Your custom plugins are highlighted in green. Changes save automatically.</p>

            <div style="max-width: 550px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h2 style="margin:0;">Sidebar Order</h2>
                    <div style="display:flex; gap:8px;">
                        <button id="rrh-add-sep-btn" class="button">+ Add Separator</button>
                        <button id="rrh-reset-btn" class="button" style="color:#b32d2e;">Reset to Default</button>
                    </div>
                </div>

                <ul id="rrh-sortable" style="list-style:none; margin:0; padding:0;">
                    <?php foreach ($items as $item): ?>
                        <?php if ($item['type'] === 'separator'): ?>
                        <li data-slug="---separator---"
                            style="padding:4px 14px; margin-bottom:4px; background:#f0f0f1; border:1px dashed #c3c4c7;
                                   border-radius:3px; cursor:grab; text-align:center; color:#999; font-size:12px;
                                   display:flex; align-items:center; justify-content:center; gap:8px;">
                            <span style="flex:1; text-align:center;">— separator —</span>
                            <button class="rrh-delete-sep button-link" style="color:#b32d2e; cursor:pointer; font-size:14px;" title="Remove separator">✕</button>
                        </li>
                        <?php else: ?>
                        <li data-slug="<?php echo esc_attr($item['slug']); ?>"
                            style="padding:10px 14px; margin-bottom:4px;
                                   background:<?php echo $item['custom'] ? '#d4edda' : '#fff'; ?>;
                                   border:1px solid <?php echo $item['custom'] ? '#28a745' : '#c3c4c7'; ?>;
                                   border-left:4px solid <?php echo $item['custom'] ? '#28a745' : '#2271b1'; ?>;
                                   border-radius:4px; cursor:grab; display:flex; align-items:center; gap:10px;">
                            <span style="color:#999;">☰</span>
                            <strong style="flex:1;"><?php echo esc_html($item['title']); ?></strong>
                            <code style="font-size:11px; color:#888;"><?php echo esc_html($item['slug']); ?></code>
                        </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>

                <div id="rrh-save-status" style="margin-top:12px; padding:10px; background:#d4edda; border:1px solid #28a745;
                     border-radius:4px; color:#155724; font-weight:600; display:none;">
                    ✓ Saved! Refresh the page to see the sidebar update.
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <script>
        (function() {
            var nonce = '<?php echo $nonce; ?>';
            var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

            var el = document.getElementById('rrh-sortable');
            Sortable.create(el, {
                animation: 150,
                ghostClass: 'rrh-ghost',
                onEnd: function() { saveOrder(); }
            });

            function saveOrder() {
                var slugs = [];
                el.querySelectorAll('li').forEach(function(li) {
                    slugs.push(li.dataset.slug);
                });
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    var status = document.getElementById('rrh-save-status');
                    status.style.display = 'block';
                    setTimeout(function() { status.style.display = 'none'; }, 4000);
                };
                xhr.send('action=rrh_menu_save_order&nonce=' + nonce + '&slugs=' + encodeURIComponent(JSON.stringify(slugs)));
            }

            document.getElementById('rrh-reset-btn').addEventListener('click', function() {
                if (!confirm('Reset sidebar to WordPress default order?')) return;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() { location.reload(); };
                xhr.send('action=rrh_menu_reset_order&nonce=' + nonce);
            });

            // Add separator at bottom (user can then drag it into place)
            document.getElementById('rrh-add-sep-btn').addEventListener('click', function() {
                var li = document.createElement('li');
                li.dataset.slug = '---separator---';
                li.setAttribute('style', 'padding:4px 14px; margin-bottom:4px; background:#f0f0f1; border:1px dashed #c3c4c7; border-radius:3px; cursor:grab; color:#999; font-size:12px; display:flex; align-items:center; justify-content:center; gap:8px;');
                li.innerHTML = '<span style="flex:1; text-align:center;">— separator —</span>' +
                    '<button class="rrh-delete-sep button-link" style="color:#b32d2e; cursor:pointer; font-size:14px;" title="Remove separator">✕</button>';
                el.appendChild(li);
                bindDeleteButtons();
                saveOrder();
            });

            // Delete separator buttons
            function bindDeleteButtons() {
                document.querySelectorAll('.rrh-delete-sep').forEach(function(btn) {
                    btn.onclick = function(e) {
                        e.preventDefault();
                        this.closest('li').remove();
                        saveOrder();
                    };
                });
            }
            bindDeleteButtons();
        })();
        </script>
        <style>
            .rrh-ghost { opacity: 0.4; background: #e8f0fe !important; }
            #rrh-sortable li:active { cursor: grabbing; }
        </style>
        <?php
    }

    /**
     * AJAX: Save full menu order.
     */
    public static function ajax_save_order() {
        check_ajax_referer('rrh_menu_org', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $slugs = json_decode(stripslashes($_POST['slugs'] ?? '[]'), true);
        if (is_array($slugs)) {
            $slugs = array_map('sanitize_text_field', $slugs);
            update_option(self::$option_key, $slugs);
        }
        wp_send_json_success();
    }

    /**
     * AJAX: Reset to default order.
     */
    public static function ajax_reset_order() {
        check_ajax_referer('rrh_menu_org', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        delete_option(self::$option_key);
        wp_send_json_success();
    }
}

RRH_Menu_Organizer::init();
