<?php
/**
 * Plugin Name: SyncMaster
 * Description: Admin UI and sync management for SyncMaster.
 * Version: 1.0.0
 * Author: SyncMaster
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SYNCMASTER_VERSION', '1.0.0');
define('SYNCMASTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SYNCMASTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SYNCMASTER_API_URL', 'https://api-ca.ssactivewear.com/v2/products/');
define('SYNCMASTER_STYLES_API_URL', 'https://api-ca.ssactivewear.com/v2/styles');

define('SYNCMASTER_PRODUCTS_TABLE', 'syncmaster_products');
define('SYNCMASTER_LOGS_TABLE', 'syncmaster_logs');

syncmaster_safe_require(SYNCMASTER_PLUGIN_DIR . 'includes/admin-pages.php');
syncmaster_safe_require(SYNCMASTER_PLUGIN_DIR . 'includes/sync-functions.php');

function syncmaster_safe_require($file) {
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    add_action('admin_notices', function () use ($file) {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(__('SyncMaster missing required file: %s', 'syncmaster'), $file))
        );
    });
}

register_activation_hook(__FILE__, 'syncmaster_activate');
register_deactivation_hook(__FILE__, 'syncmaster_deactivate');

function syncmaster_activate() {
    syncmaster_create_tables();
    syncmaster_schedule_cron();
}

function syncmaster_deactivate() {
    wp_clear_scheduled_hook('syncmaster_cron_sync');
}

add_action('admin_menu', 'syncmaster_register_admin_menu');
add_action('admin_enqueue_scripts', 'syncmaster_enqueue_admin_assets');
add_action('admin_post_syncmaster_sync_now', 'syncmaster_handle_sync_now');
add_action('admin_post_syncmaster_save_settings', 'syncmaster_handle_save_settings');
add_action('admin_post_syncmaster_add_sku', 'syncmaster_handle_add_sku');
add_action('admin_post_syncmaster_remove_sku', 'syncmaster_handle_remove_sku');
add_action('admin_post_syncmaster_test_api', 'syncmaster_handle_test_api');
add_action('admin_post_syncmaster_save_colors', 'syncmaster_handle_save_colors');
add_action('admin_post_syncmaster_save_margin', 'syncmaster_handle_save_margin');

add_action('syncmaster_cron_sync', 'syncmaster_run_scheduled_sync');

add_filter('cron_schedules', 'syncmaster_register_cron_schedule');

function syncmaster_register_cron_schedule($schedules) {
    $interval_minutes = (int) get_option('sync_interval_minutes', 60);
    if ($interval_minutes < 5) {
        $interval_minutes = 5;
    }
    $schedules['syncmaster_interval'] = array(
        'interval' => $interval_minutes * 60,
        'display' => sprintf(__('SyncMaster every %d minutes', 'syncmaster'), $interval_minutes),
    );
    return $schedules;
}

function syncmaster_schedule_cron() {
    if (!wp_next_scheduled('syncmaster_cron_sync')) {
        wp_schedule_event(time() + 300, 'syncmaster_interval', 'syncmaster_cron_sync');
    }
}

function syncmaster_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $products_table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
    $logs_table = $wpdb->prefix . SYNCMASTER_LOGS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $products_sql = "CREATE TABLE {$products_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sku VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY sku (sku)
    ) {$charset_collate};";

    $logs_sql = "CREATE TABLE {$logs_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        log_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        success_count INT UNSIGNED DEFAULT 0,
        fail_count INT UNSIGNED DEFAULT 0,
        context_json LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY log_time (log_time)
    ) {$charset_collate};";

    dbDelta($products_sql);
    dbDelta($logs_sql);
}

function syncmaster_enqueue_admin_assets($hook) {
    if (strpos($hook, 'syncmaster') === false) {
        return;
    }

    wp_enqueue_style(
        'syncmaster-admin',
        SYNCMASTER_PLUGIN_URL . 'assets/admin.css',
        array(),
        SYNCMASTER_VERSION
    );

    wp_enqueue_script(
        'syncmaster-admin',
        SYNCMASTER_PLUGIN_URL . 'assets/admin.js',
        array('jquery'),
        SYNCMASTER_VERSION,
        true
    );
}
