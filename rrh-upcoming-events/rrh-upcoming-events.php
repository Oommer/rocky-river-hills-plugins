<?php
/**
 * Plugin Name: RT Upcoming Events
 * Description: Lightweight upcoming events display for Rocky River Hills. Auto-hides past events, always shows next 3 in chronological order.
 * Version: 1.3.2
 * Author: Rocky River Hills
 */

if (!defined('ABSPATH')) exit;

class RRH_Upcoming_Events {

    private $table_name;
    private $default_styles;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'rrh_events';

        $this->default_styles = [
            'date_size'        => '60',
            'date_color'       => '#333333',
            'date_weight'      => '900',
            'name_size'        => '16',
            'name_color'       => '#333333',
            'name_weight'      => '700',
            'location_size'    => '15',
            'location_color'   => '#333333',
            'location_weight'  => '600',
            'time_size'        => '15',
            'time_color'       => '#333333',
            'time_weight'      => '400',
            'link_color'       => '#A2755A',
            'mobile_date_size' => '42',
            'events_count'     => '3',
            'col_min_width'    => '250',
            'col_max_width'    => '380',
            'col_gap'          => '30',
            'col_padding'      => '20',
        ];

        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
        add_shortcode('rrh_upcoming_events', [$this, 'render_shortcode']);
        add_action('wp_ajax_rrh_events_save', [$this, 'ajax_save']);
        add_action('wp_ajax_rrh_events_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_rrh_events_save_styles', [$this, 'ajax_save_styles']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
    }

    private function get_styles() {
        $saved = get_option('rrh_events_styles', []);
        return wp_parse_args($saved, $this->default_styles);
    }

    public function activate() {
        $this->create_table();
        if (!get_option('rrh_events_styles')) {
            update_option('rrh_events_styles', $this->default_styles);
        }
        update_option('rrh_events_db_version', '1.2.0');
    }

    private function create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_date DATE NOT NULL,
            event_name VARCHAR(255) NOT NULL,
            location VARCHAR(255) NOT NULL,
            directions_address VARCHAR(500) DEFAULT '',
            time_range VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function maybe_upgrade() {
        $db_version = get_option('rrh_events_db_version', '1.0.0');
        if (version_compare($db_version, '1.2.0', '<')) {
            global $wpdb;
            $col = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'directions_address'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN directions_address VARCHAR(500) DEFAULT '' AFTER location");
            }
            update_option('rrh_events_db_version', '1.2.0');
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Upcoming Events',
            'Events',
            'manage_options',
            'rrh-events',
            [$this, 'admin_page'],
            'dashicons-calendar-alt',
            58
        );
    }

    public function admin_styles($hook) {
        if ($hook !== 'toplevel_page_rrh-events') return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('rrh-events-admin', plugin_dir_url(__FILE__) . 'admin.css', [], '1.3.0');
        wp_enqueue_script('rrh-events-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery', 'wp-color-picker'], '1.3.0', true);
        wp_localize_script('rrh-events-admin', 'rrhEvents', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('rrh_events_nonce'),
            'defaults' => $this->default_styles,
        ]);
    }

    public function admin_page() {
        global $wpdb;
        $events = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY event_date ASC");
        $today = current_time('Y-m-d');
        $styles = $this->get_styles();
        ?>
        <div class="wrap rrh-events-wrap">
            <h1>📅 Upcoming Events</h1>
            <p class="description">Manage your market events. Past events auto-hide from your site. Use <code>[rrh_upcoming_events]</code> to display them.</p>

            <h2 class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active" data-tab="events-tab">Events</a>
                <a href="#" class="nav-tab" data-tab="styles-tab">Style Settings</a>
            </h2>

            <!-- ==================== EVENTS TAB ==================== -->
            <div id="events-tab" class="rrh-tab-content" style="display:block;">

                <div class="rrh-events-form-card">
                    <h2 id="rrh-form-title">Add New Event</h2>
                    <input type="hidden" id="rrh-event-id" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="rrh-event-date">Event Date</label></th>
                            <td><input type="date" id="rrh-event-date" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="rrh-event-name">Event Name</label></th>
                            <td><input type="text" id="rrh-event-name" class="regular-text" placeholder="e.g. Gold Rush Arts &amp; Crafts Festival" required>
                            <p class="description">Emojis welcome! Copy/paste from <a href="https://emojipedia.org/" target="_blank">emojipedia.org</a> 🎨🏟️🎉</p></td>
                        </tr>
                        <tr>
                            <th><label for="rrh-event-location">Location</label></th>
                            <td><input type="text" id="rrh-event-location" class="regular-text" placeholder="e.g. Gold Hill, NC" required></td>
                        </tr>
                        <tr>
                            <th><label for="rrh-event-directions">Directions Address</label></th>
                            <td><input type="text" id="rrh-event-directions" class="regular-text" placeholder="e.g. 770 Gold Hill Mine Rd, Gold Hill, NC 28071">
                            <p class="description">Optional. Full street address for Google Maps directions. If filled in, the location becomes a clickable link that opens Maps. 📍</p></td>
                        </tr>
                        <tr>
                            <th><label for="rrh-event-time">Time</label></th>
                            <td><input type="text" id="rrh-event-time" class="regular-text" placeholder="e.g. 10am-5pm" required></td>
                        </tr>
                    </table>
                    <p>
                        <button id="rrh-event-save" class="button button-primary">Save Event</button>
                        <button id="rrh-event-clear" class="button" style="display:none;">Cancel Edit</button>
                    </p>
                    <div id="rrh-event-msg" style="display:none;margin-top:10px;padding:8px 12px;border-radius:4px;"></div>
                </div>

                <div class="rrh-events-list-card">
                    <h2>All Events</h2>
                    <table class="wp-list-table widefat fixed striped" id="rrh-events-table">
                        <thead>
                            <tr>
                                <th style="width:110px;">Date</th>
                                <th>Event Name</th>
                                <th>Location</th>
                                <th style="width:100px;">Time</th>
                                <th style="width:70px;">Maps</th>
                                <th style="width:70px;">Status</th>
                                <th style="width:130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($events)): ?>
                                <tr><td colspan="7" style="text-align:center;color:#999;">No events yet. Add one above!</td></tr>
                            <?php else: ?>
                                <?php foreach ($events as $e):
                                    $is_past = $e->event_date < $today;
                                    $date_obj = new DateTime($e->event_date);
                                    $formatted = $date_obj->format('M j, Y');
                                    $has_dir = !empty($e->directions_address);
                                ?>
                                <tr class="<?php echo $is_past ? 'rrh-past-event' : ''; ?>"
                                    data-id="<?php echo esc_attr($e->id); ?>"
                                    data-date="<?php echo esc_attr($e->event_date); ?>"
                                    data-name="<?php echo esc_attr($e->event_name); ?>"
                                    data-location="<?php echo esc_attr($e->location); ?>"
                                    data-directions="<?php echo esc_attr($e->directions_address); ?>"
                                    data-time="<?php echo esc_attr($e->time_range); ?>">
                                    <td><strong><?php echo esc_html($formatted); ?></strong></td>
                                    <td><?php echo esc_html($e->event_name); ?></td>
                                    <td><?php echo esc_html($e->location); ?></td>
                                    <td><?php echo esc_html($e->time_range); ?></td>
                                    <td>
                                        <?php if ($has_dir): ?>
                                            <span style="color:#00a32a;">📍 Yes</span>
                                        <?php else: ?>
                                            <span style="color:#999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_past): ?>
                                            <span style="color:#999;">Past</span>
                                        <?php else: ?>
                                            <span style="color:#00a32a;font-weight:600;">Upcoming</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="button button-small rrh-edit-event">Edit</button>
                                        <button class="button button-small rrh-delete-event" style="color:#d63638;">Delete</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== STYLES TAB ==================== -->
            <div id="styles-tab" class="rrh-tab-content" style="display:none;">

                <div class="rrh-events-form-card">
                    <h2>🎨 Style Settings</h2>
                    <p class="description">Customize how your events look on the front end. The live preview updates as you adjust settings.</p>

                    <div class="rrh-style-section">
                        <h3>Date (e.g. "Apr 25")</h3>
                        <table class="form-table">
                            <tr>
                                <th>Font Size</th>
                                <td>
                                    <input type="range" id="rrh-s-date_size" min="24" max="120" value="<?php echo esc_attr($styles['date_size']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['date_size']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Color</th>
                                <td><input type="text" id="rrh-s-date_color" value="<?php echo esc_attr($styles['date_color']); ?>" class="rrh-color-picker"></td>
                            </tr>
                            <tr>
                                <th>Weight</th>
                                <td>
                                    <select id="rrh-s-date_weight" class="rrh-style-select">
                                        <option value="400" <?php selected($styles['date_weight'], '400'); ?>>Normal (400)</option>
                                        <option value="600" <?php selected($styles['date_weight'], '600'); ?>>Semi-Bold (600)</option>
                                        <option value="700" <?php selected($styles['date_weight'], '700'); ?>>Bold (700)</option>
                                        <option value="800" <?php selected($styles['date_weight'], '800'); ?>>Extra Bold (800)</option>
                                        <option value="900" <?php selected($styles['date_weight'], '900'); ?>>Black (900)</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>Event Name</h3>
                        <table class="form-table">
                            <tr>
                                <th>Font Size</th>
                                <td>
                                    <input type="range" id="rrh-s-name_size" min="12" max="36" value="<?php echo esc_attr($styles['name_size']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['name_size']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Color</th>
                                <td><input type="text" id="rrh-s-name_color" value="<?php echo esc_attr($styles['name_color']); ?>" class="rrh-color-picker"></td>
                            </tr>
                            <tr>
                                <th>Weight</th>
                                <td>
                                    <select id="rrh-s-name_weight" class="rrh-style-select">
                                        <option value="400" <?php selected($styles['name_weight'], '400'); ?>>Normal (400)</option>
                                        <option value="600" <?php selected($styles['name_weight'], '600'); ?>>Semi-Bold (600)</option>
                                        <option value="700" <?php selected($styles['name_weight'], '700'); ?>>Bold (700)</option>
                                        <option value="800" <?php selected($styles['name_weight'], '800'); ?>>Extra Bold (800)</option>
                                        <option value="900" <?php selected($styles['name_weight'], '900'); ?>>Black (900)</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>Location</h3>
                        <table class="form-table">
                            <tr>
                                <th>Font Size</th>
                                <td>
                                    <input type="range" id="rrh-s-location_size" min="12" max="36" value="<?php echo esc_attr($styles['location_size']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['location_size']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Color</th>
                                <td><input type="text" id="rrh-s-location_color" value="<?php echo esc_attr($styles['location_color']); ?>" class="rrh-color-picker"></td>
                            </tr>
                            <tr>
                                <th>Weight</th>
                                <td>
                                    <select id="rrh-s-location_weight" class="rrh-style-select">
                                        <option value="400" <?php selected($styles['location_weight'], '400'); ?>>Normal (400)</option>
                                        <option value="600" <?php selected($styles['location_weight'], '600'); ?>>Semi-Bold (600)</option>
                                        <option value="700" <?php selected($styles['location_weight'], '700'); ?>>Bold (700)</option>
                                        <option value="800" <?php selected($styles['location_weight'], '800'); ?>>Extra Bold (800)</option>
                                        <option value="900" <?php selected($styles['location_weight'], '900'); ?>>Black (900)</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>Time</h3>
                        <table class="form-table">
                            <tr>
                                <th>Font Size</th>
                                <td>
                                    <input type="range" id="rrh-s-time_size" min="12" max="36" value="<?php echo esc_attr($styles['time_size']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['time_size']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Color</th>
                                <td><input type="text" id="rrh-s-time_color" value="<?php echo esc_attr($styles['time_color']); ?>" class="rrh-color-picker"></td>
                            </tr>
                            <tr>
                                <th>Weight</th>
                                <td>
                                    <select id="rrh-s-time_weight" class="rrh-style-select">
                                        <option value="400" <?php selected($styles['time_weight'], '400'); ?>>Normal (400)</option>
                                        <option value="600" <?php selected($styles['time_weight'], '600'); ?>>Semi-Bold (600)</option>
                                        <option value="700" <?php selected($styles['time_weight'], '700'); ?>>Bold (700)</option>
                                        <option value="800" <?php selected($styles['time_weight'], '800'); ?>>Extra Bold (800)</option>
                                        <option value="900" <?php selected($styles['time_weight'], '900'); ?>>Black (900)</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>Directions Link</h3>
                        <table class="form-table">
                            <tr>
                                <th>Link Color</th>
                                <td><input type="text" id="rrh-s-link_color" value="<?php echo esc_attr($styles['link_color']); ?>" class="rrh-color-picker"></td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>Column Layout</h3>
                        <table class="form-table">
                            <tr>
                                <th>Min Width</th>
                                <td>
                                    <input type="range" id="rrh-s-col_min_width" min="150" max="500" step="10" value="<?php echo esc_attr($styles['col_min_width']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['col_min_width']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Max Width</th>
                                <td>
                                    <input type="range" id="rrh-s-col_max_width" min="200" max="800" step="10" value="<?php echo esc_attr($styles['col_max_width']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['col_max_width']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Gap Between</th>
                                <td>
                                    <input type="range" id="rrh-s-col_gap" min="0" max="80" step="5" value="<?php echo esc_attr($styles['col_gap']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['col_gap']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Inner Padding</th>
                                <td>
                                    <input type="range" id="rrh-s-col_padding" min="0" max="60" step="5" value="<?php echo esc_attr($styles['col_padding']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['col_padding']); ?>px</span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="rrh-style-section">
                        <h3>General</h3>
                        <table class="form-table">
                            <tr>
                                <th>Mobile Date Size</th>
                                <td>
                                    <input type="range" id="rrh-s-mobile_date_size" min="24" max="80" value="<?php echo esc_attr($styles['mobile_date_size']); ?>" class="rrh-range-input">
                                    <span class="rrh-range-value"><?php echo esc_attr($styles['mobile_date_size']); ?>px</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Events to Show</th>
                                <td>
                                    <select id="rrh-s-events_count" class="rrh-style-select">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php selected($styles['events_count'], (string)$i); ?>><?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p>
                        <button id="rrh-styles-save" class="button button-primary">Save Styles</button>
                        <button id="rrh-styles-reset" class="button">Reset to Defaults</button>
                    </p>
                    <div id="rrh-styles-msg" style="display:none;margin-top:10px;padding:8px 12px;border-radius:4px;"></div>
                </div>

                <div class="rrh-events-preview-card">
                    <h2>🔍 Live Preview — Actual Site Width</h2>
                    <p class="description">This preview matches your site's 1140px content area so you can see exactly how it'll look.</p>
                    <div class="rrh-preview-frame">
                        <div id="rrh-live-preview">
                            <?php echo $this->render_shortcode([]); ?>
                        </div>
                        <div class="rrh-preview-rulers">
                            <span>|&mdash; 0px</span>
                            <span>380px &mdash;|</span>
                            <span>760px &mdash;|</span>
                            <span>1140px &mdash;|</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    public function ajax_save() {
        check_ajax_referer('rrh_events_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $data = [
            'event_date'         => sanitize_text_field(wp_unslash($_POST['event_date'])),
            'event_name'         => sanitize_text_field(wp_unslash($_POST['event_name'])),
            'location'           => sanitize_text_field(wp_unslash($_POST['location'])),
            'directions_address' => sanitize_text_field(wp_unslash($_POST['directions_address'] ?? '')),
            'time_range'         => sanitize_text_field(wp_unslash($_POST['time_range'])),
        ];

        if (empty($data['event_date']) || empty($data['event_name']) || empty($data['location']) || empty($data['time_range'])) {
            wp_send_json_error('All fields except Directions Address are required.');
        }

        if ($id > 0) {
            $wpdb->update($this->table_name, $data, ['id' => $id]);
            wp_send_json_success(['message' => 'Event updated!', 'id' => $id]);
        } else {
            $wpdb->insert($this->table_name, $data);
            wp_send_json_success(['message' => 'Event added!', 'id' => $wpdb->insert_id]);
        }
    }

    public function ajax_delete() {
        check_ajax_referer('rrh_events_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $wpdb->delete($this->table_name, ['id' => $id]);
            wp_send_json_success(['message' => 'Event deleted.']);
        }
        wp_send_json_error('Invalid event ID.');
    }

    public function ajax_save_styles() {
        check_ajax_referer('rrh_events_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $styles = [];
        foreach ($this->default_styles as $key => $default) {
            $styles[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? $default));
        }

        update_option('rrh_events_styles', $styles);
        wp_send_json_success(['message' => 'Styles saved!']);
    }

    private function get_maps_url($address) {
        return 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($address);
    }

    /**
     * Render the [rrh_upcoming_events] shortcode.
     */
    public function render_shortcode($atts) {
        $styles = $this->get_styles();
        $atts = shortcode_atts(['count' => $styles['events_count']], $atts);
        $count = intval($atts['count']);

        global $wpdb;
        $today = current_time('Y-m-d');
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE event_date >= %s ORDER BY event_date ASC LIMIT %d",
            $today, $count
        ));

        if (empty($events)) {
            return '<div class="rrh-events-container"><p style="text-align:center;color:#999;font-style:italic;">No upcoming events scheduled. Check back soon!</p></div>';
        }

        $s = $styles;

        $html = '<div class="rrh-events-container">';
        foreach ($events as $e) {
            $date = new DateTime($e->event_date);
            $month_day = $date->format('M j');
            $has_directions = !empty($e->directions_address);

            $html .= '<div class="rrh-event-card">';
            $html .= '<div class="rrh-event-date">' . esc_html($month_day) . '</div>';
            $html .= '<div class="rrh-event-details">';
            $html .= '<div class="rrh-event-name">' . esc_html($e->event_name) . '</div>';

            if ($has_directions) {
                $html .= '<div class="rrh-event-location">';
                $html .= '<a href="#" class="rrh-event-directions-link" data-address="' . esc_attr($e->directions_address) . '" title="Get directions">';
                $html .= '📍 ' . esc_html($e->location);
                $html .= '</a></div>';
            } else {
                $html .= '<div class="rrh-event-location">' . esc_html($e->location) . '</div>';
            }

            $html .= '<div class="rrh-event-time">' . esc_html($e->time_range) . '</div>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        $html .= '<style>
        .rrh-events-container {
            display: flex;
            justify-content: center;
            gap: ' . intval($s['col_gap']) . 'px;
            flex-wrap: wrap;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 0;
        }
        .rrh-event-card {
            flex: 1;
            min-width: ' . intval($s['col_min_width']) . 'px;
            max-width: ' . intval($s['col_max_width']) . 'px;
            text-align: center;
            padding: ' . intval($s['col_padding']) . 'px;
        }
        .rrh-event-date {
            font-family: "Poppins", sans-serif;
            font-size: ' . intval($s['date_size']) . 'px;
            font-weight: ' . intval($s['date_weight']) . ';
            line-height: 1.1;
            color: ' . esc_attr($s['date_color']) . ';
            margin-bottom: 12px;
        }
        .rrh-event-details {
            font-family: "Poppins", sans-serif;
        }
        .rrh-event-name {
            font-size: ' . intval($s['name_size']) . 'px;
            font-weight: ' . intval($s['name_weight']) . ';
            color: ' . esc_attr($s['name_color']) . ';
            margin-bottom: 2px;
        }
        .rrh-event-location {
            font-size: ' . intval($s['location_size']) . 'px;
            font-weight: ' . intval($s['location_weight']) . ';
            color: ' . esc_attr($s['location_color']) . ';
        }
        .rrh-event-directions-link {
            color: ' . esc_attr($s['link_color']) . ';
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .rrh-event-directions-link:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
        .rrh-event-time {
            font-size: ' . intval($s['time_size']) . 'px;
            font-weight: ' . intval($s['time_weight']) . ';
            color: ' . esc_attr($s['time_color']) . ';
        }
        @media (max-width: 768px) {
            .rrh-events-container {
                flex-direction: column;
                align-items: center;
                gap: 20px;
            }
            .rrh-event-date {
                font-size: ' . intval($s['mobile_date_size']) . 'px;
            }
        }
        </style>
        <script>
        (function(){
            document.querySelectorAll(".rrh-event-directions-link[data-address]").forEach(function(link){
                link.addEventListener("click",function(e){
                    e.preventDefault();
                    var addr=encodeURIComponent(this.getAttribute("data-address"));
                    var ua=navigator.userAgent||navigator.vendor;
                    var isApple=/iPad|iPhone|iPod/.test(ua)||(/Macintosh/.test(ua)&&"ontouchend" in document)||(navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1);
                    if(!isApple&&/Macintosh|Mac OS X/.test(ua)){isApple=true;}
                    var url=isApple
                        ?"https://maps.apple.com/?daddr="+addr
                        :"https://www.google.com/maps/dir/?api=1&destination="+addr;
                    window.open(url,"_blank","noopener,noreferrer");
                });
            });
        })();
        </script>';

        return $html;
    }
}

new RRH_Upcoming_Events();
