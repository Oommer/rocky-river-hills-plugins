<?php
if (!defined('ABSPATH')) exit;

class RRH_IG_Templates {

    public static function get_all() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rrh_ig_templates ORDER BY name ASC");
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rrh_ig_templates WHERE id=%d", $id));
    }

    public static function save($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'rrh_ig_templates';
        $row = [
            'name' => sanitize_text_field(wp_unslash($data['name'])),
            'caption_template' => sanitize_textarea_field(wp_unslash($data['caption_template'])),
            'hashtags' => sanitize_textarea_field(wp_unslash($data['hashtags'] ?? '')),
            'category' => sanitize_text_field(wp_unslash($data['category'] ?? 'general')),
        ];

        $id = intval($data['template_id'] ?? 0);
        if ($id) {
            $wpdb->update($table, $row, ['id' => $id]);
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert($table, $row);
            $id = $wpdb->insert_id;
        }
        return $id;
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rrh_ig_templates', ['id' => $id], ['%d']);
    }

    /**
     * Available template variables
     */
    public static function get_variables() {
        return [
            '{product_name}' => 'Product title',
            '{price}' => 'Current price',
            '{sale_price}' => 'Sale price (if on sale)',
            '{regular_price}' => 'Regular price',
            '{description}' => 'Short description (30 words)',
            '{url}' => 'Product URL',
            '{shop_url}' => 'Shop page URL',
            '{categories}' => 'Product categories',
        ];
    }
}
