<?php

if (!defined('ABSPATH')) {
    exit;
}

function syncmaster_get_settings() {
    return array(
        'ss_username' => get_option('ss_username', ''),
        'ss_password' => get_option('ss_password', ''),
        'sync_interval_minutes' => (int) get_option('sync_interval_minutes', 60),
    );
}

function syncmaster_get_color_selections() {
    $stored = get_option('syncmaster_color_selections', array());
    if (!is_array($stored)) {
        return array();
    }

    $sanitized = array();
    foreach ($stored as $sku => $colors) {
        $sku = sanitize_text_field($sku);
        if ($sku === '') {
            continue;
        }
        $color_list = array();
        if (is_array($colors)) {
            foreach ($colors as $color) {
                $color = sanitize_text_field($color);
                if ($color !== '') {
                    $color_list[] = $color;
                }
            }
        }
        $sanitized[$sku] = array_values(array_unique($color_list));
    }

    return $sanitized;
}

function syncmaster_get_margin_settings() {
    $stored = get_option('syncmaster_margin_settings', array());
    if (!is_array($stored)) {
        return array();
    }

    $sanitized = array();
    foreach ($stored as $sku => $margin) {
        $sku = sanitize_text_field($sku);
        if ($sku === '') {
            continue;
        }
        $margin_value = (float) $margin;
        if ($margin_value <= 0) {
            continue;
        }
        $sanitized[$sku] = $margin_value;
    }

    return $sanitized;
}

function syncmaster_get_margin_percent_for_sku($sku, $default = 50) {
    $sku = sanitize_text_field($sku);
    if ($sku === '') {
        return (float) $default;
    }

    $settings = syncmaster_get_margin_settings();
    if (isset($settings[$sku])) {
        return (float) $settings[$sku];
    }

    if (function_exists('wc_get_product_id_by_sku')) {
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $stored_margin = (float) get_post_meta($product_id, '_syncmaster_margin_percent', true);
            if ($stored_margin > 0) {
                return $stored_margin;
            }
        }
    }

    return (float) $default;
}

function syncmaster_get_ss_api_headers() {
    $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    );

    $username = get_option('ss_username', '');
    $password = get_option('ss_password', '');
    if ($username !== '' && $password !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
    }

    return $headers;
}

function syncmaster_get_category_sync_rules() {
    $stored = get_option('syncmaster_category_sync_rules', array());
    if (!is_array($stored)) {
        return array();
    }

    $rules = array();
    foreach ($stored as $legacy_key => $rule) {
        $legacy_key = sanitize_text_field($legacy_key);
        if ($legacy_key === '' || !is_array($rule)) {
            continue;
        }
        $source_id = sanitize_text_field($rule['source_id'] ?? $legacy_key);
        $source_name = sanitize_text_field($rule['source_name'] ?? $legacy_key);
        if ($source_id === '') {
            continue;
        }

        $mode = ($rule['mode'] ?? '') === 'existing' ? 'existing' : 'create';
        $rules[$source_id] = array(
            'enabled' => !empty($rule['enabled']),
            'mode' => $mode,
            'target_term_id' => (int) ($rule['target_term_id'] ?? 0),
            'new_name' => sanitize_text_field($rule['new_name'] ?? ''),
            'source_name' => $source_name,
            'source_id' => $source_id,
        );
    }

    return $rules;
}

function syncmaster_fetch_ss_categories() {
    $cache_key = 'syncmaster_ss_categories_v2';
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(SYNCMASTER_CATEGORIES_API_URL, array(
        'timeout' => 25,
        'headers' => syncmaster_get_ss_api_headers(),
    ));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return array();
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return array();
    }

    $style_counts = syncmaster_get_style_counts_by_category_id();
    $categories = array();
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = sanitize_text_field(syncmaster_extract_scalar($row['categoryName'] ?? ($row['name'] ?? ($row['CategoryName'] ?? ''))));
        $category_id = sanitize_text_field(syncmaster_extract_scalar($row['categoryID'] ?? ($row['CategoryID'] ?? ($row['id'] ?? ''))));
        if ($name === '' || $category_id === '') {
            continue;
        }

        $categories[] = array(
            'id' => $category_id,
            'name' => $name,
            'style_count' => (int) ($style_counts[$category_id] ?? 0),
        );
    }

    usort($categories, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    set_transient($cache_key, $categories, HOUR_IN_SECONDS);

    return $categories;
}

function syncmaster_extract_style_category_ids($style_row) {
    if (!is_array($style_row)) {
        return array();
    }

    $raw_categories = $style_row['categories'] ?? ($style_row['Categories'] ?? array());
    $ids = array();

    if (is_string($raw_categories)) {
        $parts = preg_split('/[\s,;|]+/', $raw_categories);
        if (is_array($parts)) {
            $ids = $parts;
        }
    } elseif (is_numeric($raw_categories)) {
        $ids[] = (string) $raw_categories;
    } elseif (is_array($raw_categories)) {
        if (isset($raw_categories['string'])) {
            $raw_categories = $raw_categories['string'];
        }
        foreach ($raw_categories as $value) {
            $ids[] = syncmaster_extract_scalar($value);
        }
    } else {
        $ids[] = syncmaster_extract_scalar($raw_categories);
    }

    $normalized = array();
    foreach ($ids as $id) {
        $id = sanitize_text_field((string) $id);
        if ($id !== '') {
            $normalized[] = $id;
        }
    }

    return array_values(array_unique($normalized));
}

function syncmaster_normalize_style_category_rows($decoded_payload) {
    if (!is_array($decoded_payload)) {
        return array();
    }

    if (isset($decoded_payload['Style'])) {
        $decoded_payload = $decoded_payload['Style'];
    } elseif (isset($decoded_payload['ArrayOfStyle']['Style'])) {
        $decoded_payload = $decoded_payload['ArrayOfStyle']['Style'];
    }

    if (!isset($decoded_payload[0])) {
        $decoded_payload = array($decoded_payload);
    }

    $rows = array();
    foreach ($decoded_payload as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function syncmaster_fetch_style_category_index() {
    $cache_key = 'syncmaster_style_category_index_v2';
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $headers = syncmaster_get_ss_api_headers();
    $all_styles = array();
    $page = 1;
    $page_size = 500;
    $max_pages = 200;
    $last_page_signature = '';

    while ($page <= $max_pages) {
        $endpoint = add_query_arg(
            array(
                'fields' => 'categories,styleID',
                'page' => $page,
                'pageSize' => $page_size,
            ),
            SYNCMASTER_STYLES_API_URL
        );
        $response = wp_remote_get($endpoint, array(
            'timeout' => 45,
            'headers' => $headers,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $all_styles = array();
            break;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = syncmaster_parse_api_response($body);
        $chunk = syncmaster_normalize_style_category_rows($decoded);
        if (empty($chunk)) {
            break;
        }

        foreach ($chunk as $row) {
            if (!is_array($row)) {
                continue;
            }
            $style_id = sanitize_text_field(syncmaster_extract_scalar($row['styleID'] ?? ($row['StyleID'] ?? '')));
            if ($style_id === '') {
                continue;
            }
            $category_ids = syncmaster_extract_style_category_ids($row);
            $all_styles[] = array(
                'style_id' => $style_id,
                'category_ids' => $category_ids,
            );
        }

        $first_style = isset($chunk[0]) && is_array($chunk[0]) ? syncmaster_extract_scalar($chunk[0]['styleID'] ?? ($chunk[0]['StyleID'] ?? '')) : '';
        $last_row = end($chunk);
        $last_style = is_array($last_row) ? syncmaster_extract_scalar($last_row['styleID'] ?? ($last_row['StyleID'] ?? '')) : '';
        $current_signature = md5((string) $first_style . '|' . (string) $last_style . '|' . count($chunk));
        if ($page > 1 && $current_signature === $last_page_signature) {
            break;
        }
        $last_page_signature = $current_signature;

        if (count($chunk) < $page_size) {
            break;
        }

        $page++;
    }

    set_transient($cache_key, $all_styles, 15 * MINUTE_IN_SECONDS);
    return $all_styles;
}

function syncmaster_get_style_counts_by_category_id() {
    $styles = syncmaster_fetch_style_category_index();
    if (empty($styles)) {
        return array();
    }

    $counts = array();
    foreach ($styles as $style) {
        if (!is_array($style) || empty($style['category_ids']) || !is_array($style['category_ids'])) {
            continue;
        }
        foreach ($style['category_ids'] as $category_id) {
            $category_id = sanitize_text_field($category_id);
            if ($category_id === '') {
                continue;
            }
            if (!isset($counts[$category_id])) {
                $counts[$category_id] = 0;
            }
            $counts[$category_id]++;
        }
    }

    return $counts;
}

function syncmaster_handle_save_settings() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_save_settings');

    $settings = array(
        'ss_username' => sanitize_text_field(wp_unslash($_POST['ss_username'] ?? '')),
        'ss_password' => sanitize_text_field(wp_unslash($_POST['ss_password'] ?? '')),
        'sync_interval_minutes' => (int) ($_POST['sync_interval_minutes'] ?? 60),
    );

    foreach ($settings as $key => $value) {
        update_option($key, $value);
    }

    syncmaster_reschedule_cron();

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_settings&updated=1'));
    exit;
}

function syncmaster_handle_save_margin() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_save_margin');

    $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
    $margin = isset($_POST['margin_percent']) ? (float) wp_unslash($_POST['margin_percent']) : 0;
    if ($sku === '' || $margin <= 0) {
        wp_safe_redirect(admin_url('admin.php?page=syncmaster_products'));
        exit;
    }

    $settings = syncmaster_get_margin_settings();
    $settings[$sku] = $margin;
    update_option('syncmaster_margin_settings', $settings);
    if (function_exists('wc_get_product_id_by_sku')) {
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            update_post_meta($product_id, '_syncmaster_margin_percent', $margin);
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&margin_saved=1'));
    exit;
}

function syncmaster_handle_save_categories() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_save_categories');

    $posted_rows = $_POST['syncmaster_categories'] ?? array();
    $categories_lookup_by_name = array();
    foreach (syncmaster_fetch_ss_categories() as $category_row) {
        if (!is_array($category_row)) {
            continue;
        }
        $lookup_name = sanitize_text_field($category_row['name'] ?? '');
        $lookup_id = sanitize_text_field($category_row['id'] ?? '');
        if ($lookup_name !== '' && $lookup_id !== '') {
            $categories_lookup_by_name[$lookup_name] = $lookup_id;
        }
    }
    $rules = array();
    if (is_array($posted_rows)) {
        foreach ($posted_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $source_name = sanitize_text_field(wp_unslash($row['source_name'] ?? ''));
            $source_id = sanitize_text_field(wp_unslash($row['source_id'] ?? ''));
            if ($source_id === '' && $source_name !== '' && isset($categories_lookup_by_name[$source_name])) {
                $source_id = $categories_lookup_by_name[$source_name];
            }
            if ($source_name === '' || $source_id === '') {
                continue;
            }

            $mode = sanitize_key(wp_unslash($row['mode'] ?? 'create'));
            if (!in_array($mode, array('create', 'existing'), true)) {
                $mode = 'create';
            }

            $rules[$source_id] = array(
                'enabled' => !empty($row['enabled']),
                'mode' => $mode,
                'target_term_id' => (int) ($row['target_term_id'] ?? 0),
                'new_name' => sanitize_text_field(wp_unslash($row['new_name'] ?? '')),
                'source_name' => $source_name,
                'source_id' => $source_id,
            );
        }
    }

    update_option('syncmaster_category_sync_rules', $rules);
    delete_transient('syncmaster_selected_category_style_ids');
    $category_style_ids = syncmaster_get_selected_category_style_ids();
    if (!empty($category_style_ids)) {
        syncmaster_add_skus_to_monitored_products($category_style_ids);
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&products_tab=categories&categories_saved=1'));
    exit;
}

function syncmaster_add_skus_to_monitored_products($skus) {
    if (!is_array($skus) || empty($skus)) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;

    foreach ($skus as $sku) {
        $sku = sanitize_text_field($sku);
        if ($sku === '') {
            continue;
        }
        $wpdb->replace(
            $table,
            array(
                'sku' => $sku,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s')
        );
    }
}

function syncmaster_reschedule_cron() {
    wp_clear_scheduled_hook('syncmaster_cron_sync');
    syncmaster_schedule_cron();
}

function syncmaster_handle_sync_now() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_sync_now');

    syncmaster_start_background_sync('manual', array('mode' => 'full'));

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_dashboard&sync_queued=1'));
    exit;
}

function syncmaster_handle_sync_selected_products() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_sync_selected_products');
    $posted_skus = $_POST['skus'] ?? array();
    $selected_skus = array();
    if (is_array($posted_skus)) {
        foreach ($posted_skus as $sku_value) {
            $sku = sanitize_text_field(wp_unslash($sku_value));
            if ($sku !== '') {
                $selected_skus[] = $sku;
            }
        }
    }
    $selected_skus = array_values(array_unique($selected_skus));
    if (!empty($selected_skus)) {
        syncmaster_start_background_sync('manual_selected', array(
            'mode' => 'full',
            'explicit_queue' => $selected_skus,
        ));
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_dashboard&sync_queued=1'));
    exit;
}

function syncmaster_handle_sync_inventory_variations() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_sync_inventory_variations');
    syncmaster_start_background_sync('manual_inventory_variations', array('mode' => 'inventory_variations_only'));
    wp_safe_redirect(admin_url('admin.php?page=syncmaster_dashboard&sync_queued=1'));
    exit;
}

function syncmaster_handle_sync_new_products() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_sync_new_products');
    syncmaster_start_background_sync('manual_new_products', array('mode' => 'new_only'));
    wp_safe_redirect(admin_url('admin.php?page=syncmaster_dashboard&sync_queued=1'));
    exit;
}

function syncmaster_run_scheduled_sync() {
    syncmaster_start_background_sync('cron', array('mode' => 'full'));
}

function syncmaster_run_sync_placeholder($source = 'unknown') {
    $started_at = time();
    $results = syncmaster_sync_monitored_products();
    update_option('syncmaster_last_sync_run_ts', $started_at);
    update_option('syncmaster_last_sync_status', sanitize_text_field($results['status']));

    syncmaster_write_log(
        $results['status'],
        $results['message'],
        $results['success'],
        $results['fail'],
        array(
            'source' => sanitize_text_field($source),
            'started_at' => gmdate('c', $started_at),
            'duration' => rand(10, 40),
            'monitored' => $results['monitored'],
            'created' => $results['created'],
            'updated' => $results['updated'],
        )
    );
}

function syncmaster_start_background_sync($source = 'unknown', $options = array()) {
    if (get_option('syncmaster_active_sync_job')) {
        syncmaster_write_log('info', __('Sync request ignored: a sync job is already running.', 'syncmaster'), 0, 0, array(
            'source' => sanitize_text_field($source),
        ));
        return;
    }

    $queue = syncmaster_get_current_sync_queue($options);
    $total = isset($queue['sync_queue']) && is_array($queue['sync_queue']) ? count($queue['sync_queue']) : 0;
    if ($total === 0) {
        syncmaster_write_log('info', __('Sync skipped: no products in the current queue.', 'syncmaster'), 0, 0, array(
            'source' => sanitize_text_field($source),
        ));
        return;
    }

    update_option('syncmaster_active_sync_job', array(
        'source' => sanitize_text_field($source),
        'started_at' => time(),
        'offset' => 0,
        'total' => $total,
        'success' => 0,
        'fail' => 0,
        'created' => 0,
        'updated' => 0,
        'mode' => sanitize_text_field($options['mode'] ?? 'full'),
        'explicit_queue' => isset($options['explicit_queue']) && is_array($options['explicit_queue'])
            ? array_values(array_unique(array_map('sanitize_text_field', $options['explicit_queue'])))
            : array(),
    ), false);

    syncmaster_write_log('info', __('Background sync queued.', 'syncmaster'), 0, 0, array(
        'source' => sanitize_text_field($source),
        'queue_total' => $total,
    ));

    if (!wp_next_scheduled('syncmaster_process_sync_batch')) {
        wp_schedule_single_event(time() + 1, 'syncmaster_process_sync_batch');
    }
}

function syncmaster_process_sync_batch() {
    $job = get_option('syncmaster_active_sync_job', array());
    if (!is_array($job) || empty($job)) {
        return;
    }

    $offset = isset($job['offset']) ? (int) $job['offset'] : 0;
    $total = isset($job['total']) ? (int) $job['total'] : 0;
    $batch_size = 20;

    if ($offset >= $total) {
        delete_option('syncmaster_active_sync_job');
        return;
    }

    $results = syncmaster_sync_monitored_products($batch_size, $offset, array(
        'mode' => sanitize_text_field($job['mode'] ?? 'full'),
        'explicit_queue' => isset($job['explicit_queue']) && is_array($job['explicit_queue']) ? $job['explicit_queue'] : array(),
    ));
    $processed = (int) ($results['processed'] ?? 0);
    if ($processed <= 0) {
        $processed = $batch_size;
    }

    $job['offset'] = min($offset + $processed, $total);
    $job['success'] = (int) ($job['success'] ?? 0) + (int) ($results['success'] ?? 0);
    $job['fail'] = (int) ($job['fail'] ?? 0) + (int) ($results['fail'] ?? 0);
    $job['created'] = (int) ($job['created'] ?? 0) + (int) ($results['created'] ?? 0);
    $job['updated'] = (int) ($job['updated'] ?? 0) + (int) ($results['updated'] ?? 0);
    update_option('syncmaster_active_sync_job', $job, false);

    if ($job['offset'] < $total) {
        wp_schedule_single_event(time() + 2, 'syncmaster_process_sync_batch');
        return;
    }

    delete_option('syncmaster_active_sync_job');
    $started_at = isset($job['started_at']) ? (int) $job['started_at'] : time();
    update_option('syncmaster_last_sync_run_ts', $started_at);
    update_option('syncmaster_last_sync_status', $job['fail'] > 0 ? 'error' : 'success');
    syncmaster_write_log(
        $job['fail'] > 0 ? 'error' : 'success',
        $job['fail'] > 0 ? __('Background sync completed with errors.', 'syncmaster') : __('Background sync completed.', 'syncmaster'),
        (int) $job['success'],
        (int) $job['fail'],
        array(
            'source' => sanitize_text_field($job['source'] ?? 'unknown'),
            'started_at' => gmdate('c', $started_at),
            'queue_total' => $total,
            'created' => (int) $job['created'],
            'updated' => (int) $job['updated'],
        )
    );
}

function syncmaster_get_sync_progress_status() {
    $job = get_option('syncmaster_active_sync_job', array());
    if (!is_array($job) || empty($job)) {
        return array(
            'active' => false,
            'offset' => 0,
            'total' => 0,
            'percent' => 0,
            'success' => 0,
            'fail' => 0,
            'mode' => 'full',
        );
    }

    $offset = max(0, (int) ($job['offset'] ?? 0));
    $total = max(0, (int) ($job['total'] ?? 0));
    $percent = $total > 0 ? min(100, (int) floor(($offset / $total) * 100)) : 0;

    return array(
        'active' => true,
        'offset' => $offset,
        'total' => $total,
        'percent' => $percent,
        'success' => (int) ($job['success'] ?? 0),
        'fail' => (int) ($job['fail'] ?? 0),
        'mode' => sanitize_text_field($job['mode'] ?? 'full'),
    );
}

function syncmaster_handle_sync_progress() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'syncmaster')), 403);
    }

    check_ajax_referer('syncmaster_sync_progress', 'nonce');
    wp_send_json_success(syncmaster_get_sync_progress_status());
}

function syncmaster_maybe_run_interval_sync() {
    if (wp_doing_cron() || wp_doing_ajax()) {
        return;
    }

    $lock_key = 'syncmaster_interval_sync_lock';
    if (get_transient($lock_key)) {
        return;
    }

    $interval_minutes = (int) get_option('sync_interval_minutes', 60);
    if ($interval_minutes < 5) {
        $interval_minutes = 5;
    }

    $interval_seconds = $interval_minutes * 60;
    $last_run = (int) get_option('syncmaster_last_sync_run_ts', 0);
    if ($last_run > 0 && (time() - $last_run) < $interval_seconds) {
        return;
    }

    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);
    syncmaster_write_log('info', __('Interval sync fallback triggered.', 'syncmaster'), 0, 0, array(
        'source' => 'interval_fallback',
        'last_run' => $last_run,
        'interval_seconds' => $interval_seconds,
    ));
    syncmaster_run_sync_placeholder('interval_fallback');
    delete_transient($lock_key);
}

function syncmaster_fetch_ss_product($sku) {
    $username = get_option('ss_username', '');
    $password = get_option('ss_password', '');
    $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    );
    if ($username !== '' && $password !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
    }

    $endpoint = add_query_arg('styleid', rawurlencode($sku), SYNCMASTER_STYLES_API_URL);
    $response = wp_remote_get($endpoint, array(
        'timeout' => 15,
        'headers' => $headers,
    ));

    if (is_wp_error($response)) {
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }

    return is_array($data) ? $data : null;
}

function syncmaster_map_product_data($api_item) {
    $name_parts = array_filter(array(
        $api_item['title'] ?? '',
        $api_item['brandName'] ?? '',
        $api_item['styleName'] ?? '',
    ));
    $brand_name = $api_item['brandName'] ?? '';
    $style_name = $api_item['styleName'] ?? '';
    $sku_base = trim($brand_name . ' ' . $style_name);
    $sku_value = $sku_base !== '' ? $sku_base : ($api_item['sku'] ?? '');

    $mapped = array(
        'name' => implode(' ', $name_parts),
        'sku' => $sku_value,
        'slug' => sanitize_title($sku_value),
        'brand' => $brand_name,
        'description' => $api_item['description'] ?? '',
        'image' => $api_item['styleImage'] ?? '',
        'category' => $api_item['baseCategory'] ?? '',
    );

    return apply_filters('syncmaster_map_product_fields', $mapped, $api_item);
}

function syncmaster_apply_product_brand($product_id, $product, $brand_name) {
    if ($brand_name === '') {
        return;
    }

    if (taxonomy_exists('product_brand')) {
        $term = term_exists($brand_name, 'product_brand');
        if (!$term) {
            $term = wp_insert_term($brand_name, 'product_brand');
        }
        if (!is_wp_error($term)) {
            $term_id = is_array($term) ? $term['term_id'] : $term;
            wp_set_object_terms($product_id, array((int) $term_id), 'product_brand', false);
            return;
        }
    }

    $attributes = $product->get_attributes();
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Brand');
    $attribute->set_options(array($brand_name));
    $attribute->set_visible(true);
    $attribute->set_variation(false);
    $attributes['brand'] = $attribute;
    $product->set_attributes($attributes);
}

function syncmaster_get_color_taxonomy() {
    $taxonomy = function_exists('wc_attribute_taxonomy_name')
        ? wc_attribute_taxonomy_name('color')
        : 'pa_color';
    return $taxonomy;
}

function syncmaster_normalize_ss_image_url($image_url) {
    $image_url = trim((string) $image_url);
    if ($image_url === '') {
        return '';
    }
    if (strpos($image_url, 'http') !== 0) {
        return 'https://cdn.ssactivewear.com/' . ltrim($image_url, '/');
    }
    return $image_url;
}

function syncmaster_get_size_taxonomy() {
    $taxonomy = function_exists('wc_attribute_taxonomy_name')
        ? wc_attribute_taxonomy_name('size')
        : 'pa_size';
    return $taxonomy;
}

function syncmaster_build_color_term_slug($color) {
    $compact_slug_part = static function ($value) {
        $value = sanitize_text_field((string) $value);
        $value = preg_replace('/\s+/', '', $value);
        return sanitize_title($value);
    };

    if (is_string($color)) {
        return $compact_slug_part($color);
    }

    if (!is_array($color)) {
        return '';
    }

    $family = sanitize_text_field(syncmaster_extract_scalar($color['colorFamily'] ?? ($color['ColorFamily'] ?? '')));
    $group = sanitize_text_field(syncmaster_extract_scalar($color['colorGroupName'] ?? ($color['ColorGroupName'] ?? '')));
    $name = sanitize_text_field(syncmaster_extract_scalar($color['colorName'] ?? ($color['ColorName'] ?? '')));
    if ($name === '') {
        return '';
    }

    $parts = array_filter(array($family, $group, $name), 'strlen');
    if (empty($parts)) {
        return '';
    }

    $slug_parts = array();
    foreach ($parts as $part) {
        $slug = $compact_slug_part($part);
        if ($slug !== '') {
            $slug_parts[] = $slug;
        }
    }

    return implode('-', $slug_parts);
}

function syncmaster_get_or_create_attribute_term($name, $taxonomy, $slug = '') {
    if (!taxonomy_exists($taxonomy)) {
        return false;
    }

    $name = sanitize_text_field($name);
    if ($name === '') {
        return false;
    }

    $slug = sanitize_title($slug !== '' ? $slug : $name);
    if ($slug === '') {
        return false;
    }

    $term = get_term_by('slug', $slug, $taxonomy);
    if (!$term) {
        $term = get_term_by('name', $name, $taxonomy);
    }
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy, array('slug' => $slug));
    }
    if (is_wp_error($term) || !$term) {
        return false;
    }

    if (is_array($term)) {
        return get_term_by('id', (int) $term['term_id'], $taxonomy);
    }

    return $term;
}

function syncmaster_resolve_attribute_term_ids($names, $taxonomy) {
    if (!taxonomy_exists($taxonomy)) {
        return array();
    }

    $candidate_names = array();
    foreach ((array) $names as $name) {
        $name = sanitize_text_field($name);
        if ($name !== '') {
            $candidate_names[] = $name;
        }
    }

    $candidate_names = array_values(array_unique($candidate_names));
    if (empty($candidate_names)) {
        return array();
    }

    $term_ids = array();
    foreach ($candidate_names as $name) {
        $term = syncmaster_get_or_create_attribute_term($name, $taxonomy);
        if ($term) {
            $term_ids[] = (int) $term->term_id;
        }
    }

    return array_values(array_unique(array_filter($term_ids)));
}

function syncmaster_get_attribute_term_slug($name, $taxonomy) {
    if (!taxonomy_exists($taxonomy)) {
        return '';
    }

    $name = sanitize_text_field($name);
    if ($name === '') {
        return '';
    }

    $term = syncmaster_get_or_create_attribute_term($name, $taxonomy);
    if (!$term) {
        return '';
    }

    return $term->slug;
}

function syncmaster_collect_color_term_data($colors, $selected_colors = array()) {
    $data = array();
    foreach ((array) $colors as $color) {
        if (!is_array($color)) {
            continue;
        }
        $name = sanitize_text_field(syncmaster_extract_scalar($color['colorName'] ?? ($color['ColorName'] ?? '')));
        if ($name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($name, $selected_colors, true)) {
            continue;
        }

        $slug = syncmaster_build_color_term_slug($color);
        if ($slug === '') {
            $slug = syncmaster_build_color_term_slug($name);
        }
        $data[$name] = array(
            'name' => $name,
            'slug' => $slug,
        );
    }

    if (empty($data) && !empty($selected_colors)) {
        foreach ($selected_colors as $name) {
            $name = sanitize_text_field($name);
            if ($name === '') {
                continue;
            }
            $data[$name] = array(
                'name' => $name,
                'slug' => syncmaster_build_color_term_slug($name),
            );
        }
    }

    return array_values($data);
}

function syncmaster_resolve_color_term_ids($colors, $selected_colors = array()) {
    $taxonomy = syncmaster_get_color_taxonomy();
    if (!taxonomy_exists($taxonomy)) {
        return array();
    }

    $candidate_data = syncmaster_collect_color_term_data($colors, $selected_colors);
    if (empty($candidate_data)) {
        return array();
    }

    $term_ids = array();
    $candidate_names = array();
    foreach ($candidate_data as $color_data) {
        $name = $color_data['name'] ?? '';
        $slug = $color_data['slug'] ?? '';
        if ($name === '' || $slug === '') {
            continue;
        }
        $candidate_names[] = $name;
        $term = syncmaster_get_or_create_attribute_term($name, $taxonomy, $slug);
        if ($term) {
            $term_ids[] = (int) $term->term_id;
        }
    }

    $swatch_map = syncmaster_collect_color_swatch_map($colors);
    if (!empty($swatch_map)) {
        syncmaster_maybe_update_color_swatch_meta(array_values(array_unique($candidate_names)), $taxonomy, $swatch_map);
    }

    return $term_ids;
}

function syncmaster_collect_color_slug_map($colors, $selected_colors = array(), $color_taxonomy = null) {
    $map = array();
    $taxonomy = $color_taxonomy ?: syncmaster_get_color_taxonomy();
    if (!taxonomy_exists($taxonomy)) {
        return $map;
    }

    $candidate_data = syncmaster_collect_color_term_data($colors, $selected_colors);
    foreach ($candidate_data as $color_data) {
        $name = $color_data['name'] ?? '';
        $slug = $color_data['slug'] ?? '';
        if ($name === '' || $slug === '') {
            continue;
        }

        $term = syncmaster_get_or_create_attribute_term($name, $taxonomy, $slug);
        if ($term && !empty($term->slug)) {
            $map[$name] = $term->slug;
        }
    }

    return $map;
}

function syncmaster_apply_color_attributes($product, $term_ids, $taxonomy, $is_variable) {
    if (empty($term_ids)) {
        return;
    }

    $attribute_id = function_exists('wc_attribute_taxonomy_id_by_name')
        ? wc_attribute_taxonomy_id_by_name('color')
        : 0;

    $attributes = $product->get_attributes();
    $attribute = new WC_Product_Attribute();
    $attribute->set_id((int) $attribute_id);
    $attribute->set_name($taxonomy);
    $attribute->set_options(array_map('intval', $term_ids));
    $attribute->set_visible(true);
    $attribute->set_variation($is_variable);
    $attributes[$taxonomy] = $attribute;
    $product->set_attributes($attributes);
}

function syncmaster_apply_size_attributes($product, $term_ids, $taxonomy, $is_variable) {
    if (empty($term_ids)) {
        return;
    }

    $attribute_id = function_exists('wc_attribute_taxonomy_id_by_name')
        ? wc_attribute_taxonomy_id_by_name('size')
        : 0;

    $attributes = $product->get_attributes();
    $attribute = new WC_Product_Attribute();
    $attribute->set_id((int) $attribute_id);
    $attribute->set_name($taxonomy);
    $attribute->set_options(array_map('intval', $term_ids));
    $attribute->set_visible(true);
    $attribute->set_variation($is_variable);
    $attributes[$taxonomy] = $attribute;
    $product->set_attributes($attributes);
}

function syncmaster_assign_color_terms($product_id, $terms_or_colors, $taxonomy = null) {
    if (empty($terms_or_colors)) {
        return;
    }

    $taxonomy = $taxonomy ?: syncmaster_get_color_taxonomy();
    if (!taxonomy_exists($taxonomy)) {
        return;
    }

    $term_ids = array();
    $has_non_numeric = false;
    foreach ((array) $terms_or_colors as $term_id) {
        if (is_numeric($term_id)) {
            $term_ids[] = (int) $term_id;
        } else {
            $has_non_numeric = true;
        }
    }

    if (empty($term_ids) && $has_non_numeric) {
        $term_ids = syncmaster_resolve_color_term_ids((array) $terms_or_colors);
    }

    if (!empty($term_ids)) {
        wp_set_object_terms($product_id, array_map('intval', $term_ids), $taxonomy, false);
    }
}

function syncmaster_assign_size_terms($product_id, $term_ids, $taxonomy) {
    if (empty($term_ids)) {
        return;
    }

    wp_set_object_terms($product_id, array_map('intval', $term_ids), $taxonomy, false);
}

function syncmaster_collect_size_names($colors, $selected_colors = array()) {
    $size_names = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $names = $color['sizeNames'] ?? array();
        foreach ((array) $names as $name) {
            $name = sanitize_text_field($name);
            if ($name !== '') {
                $size_names[] = $name;
            }
        }
    }

    return array_values(array_unique($size_names));
}

function syncmaster_collect_color_size_map($colors, $selected_colors = array()) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $size_names = array();
        foreach ((array) ($color['sizeNames'] ?? array()) as $size_name) {
            $size_name = sanitize_text_field($size_name);
            if ($size_name !== '') {
                $size_names[] = $size_name;
            }
        }
        if (!empty($size_names)) {
            $map[$color_name] = array_values(array_unique($size_names));
        }
    }

    return $map;
}

function syncmaster_collect_color_size_sku_map($colors, $selected_colors = array()) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $size_skus = array();
        $raw_size_skus = $color['sizeSkus'] ?? array();
        if (is_array($raw_size_skus)) {
            foreach ($raw_size_skus as $size_name => $size_sku) {
                $size_name = sanitize_text_field($size_name);
                $size_sku = sanitize_text_field($size_sku);
                if ($size_name !== '' && $size_sku !== '') {
                    $size_skus[$size_name] = $size_sku;
                }
            }
        }
        if (!empty($size_skus)) {
            $map[$color_name] = $size_skus;
        }
    }

    return $map;
}

function syncmaster_collect_color_size_qty_map($colors, $selected_colors = array()) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $size_qtys = array();
        $raw_size_qtys = $color['sizeQtys'] ?? array();
        if (is_array($raw_size_qtys)) {
            foreach ($raw_size_qtys as $size_name => $size_qty) {
                $size_name = sanitize_text_field($size_name);
                $size_qty = (int) $size_qty;
                if ($size_name !== '') {
                    $size_qtys[$size_name] = $size_qty;
                }
            }
        }
        if (!empty($size_qtys)) {
            $map[$color_name] = $size_qtys;
        }
    }

    return $map;
}

function syncmaster_collect_color_size_price_map($colors, $selected_colors = array()) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $size_prices = array();
        $raw_size_prices = $color['sizePrices'] ?? array();
        if (is_array($raw_size_prices)) {
            foreach ($raw_size_prices as $size_name => $size_price) {
                $size_name = sanitize_text_field($size_name);
                $size_price = (float) $size_price;
                if ($size_name !== '') {
                    $size_prices[$size_name] = $size_price;
                }
            }
        }
        if (!empty($size_prices)) {
            $map[$color_name] = $size_prices;
        }
    }

    return $map;
}

function syncmaster_collect_color_size_image_map($colors, $selected_colors = array()) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }
        $image_url = syncmaster_normalize_ss_image_url($color['colorFrontImage'] ?? '');
        if ($image_url === '') {
            continue;
        }
        $raw_size_skus = $color['sizeSkus'] ?? array();
        if (!is_array($raw_size_skus)) {
            continue;
        }
        foreach ($raw_size_skus as $size_sku) {
            $size_sku = sanitize_text_field($size_sku);
            if ($size_sku === '') {
                continue;
            }
            $map[$size_sku] = $image_url;
        }
    }

    return $map;
}

function syncmaster_collect_color_postbox_view_map($colors, $selected_colors = array(), $color_taxonomy = null, $color_slug_map = array()) {
    $map = array();
    $taxonomy = $color_taxonomy ?: syncmaster_get_color_taxonomy();

    foreach ($colors as $color) {
        $color_name = sanitize_text_field($color['colorName'] ?? '');
        if ($color_name === '') {
            continue;
        }
        if (!empty($selected_colors) && !in_array($color_name, $selected_colors, true)) {
            continue;
        }

        $color_slug = $color_slug_map[$color_name] ?? syncmaster_get_attribute_term_slug($color_name, $taxonomy);
        if ($color_slug === '') {
            continue;
        }

        $front_image = syncmaster_normalize_ss_image_url($color['colorFrontImage'] ?? '');
        $back_image = syncmaster_normalize_ss_image_url($color['colorBackImage'] ?? '');
        $side_image = syncmaster_normalize_ss_image_url($color['colorDirectSideImage'] ?? '');

        if ($front_image === '' && $back_image === '' && $side_image === '') {
            continue;
        }

        $map[$color_slug] = array(
            'front' => $front_image,
            'back' => $back_image,
            'side' => $side_image,
        );
    }

    return $map;
}

function syncmaster_update_threaddesk_product_postbox($product_id, $color_postbox_view_map) {
    if (!$product_id || empty($color_postbox_view_map) || !is_array($color_postbox_view_map)) {
        return;
    }

    $existing = get_post_meta($product_id, 'tta_threaddesk_product_postbox', true);
    $postbox = is_array($existing) ? $existing : array();
    $postbox['colors'] = isset($postbox['colors']) && is_array($postbox['colors'])
        ? $postbox['colors']
        : array();

    foreach ($color_postbox_view_map as $color_slug => $views) {
        $color_slug = sanitize_title($color_slug);
        if ($color_slug === '' || !is_array($views)) {
            continue;
        }

        $front_image = esc_url_raw($views['front'] ?? '');
        $back_image = esc_url_raw($views['back'] ?? '');
        $side_image = esc_url_raw($views['side'] ?? '');

        if ($front_image === '' && $back_image === '' && $side_image === '') {
            continue;
        }

        $current = $postbox['colors'][$color_slug] ?? array();
        if (!is_array($current)) {
            $current = array();
        }

        if ($front_image !== '') {
            $current['front_image'] = $front_image;
            $current['front_fallback_url'] = $front_image;
        }
        if ($back_image !== '') {
            $current['back_image'] = $back_image;
            $current['back_fallback_url'] = $back_image;
        }
        if ($side_image !== '') {
            $current['side_image'] = $side_image;
            $current['side_fallback_url'] = $side_image;
        }
        if (empty($current['side_label']) || !in_array($current['side_label'], array('left', 'right'), true)) {
            $current['side_label'] = 'left';
        }

        $postbox['colors'][$color_slug] = $current;
    }

    update_post_meta($product_id, 'tta_threaddesk_product_postbox', $postbox);
}

function syncmaster_collect_color_swatch_map($colors) {
    $map = array();
    foreach ($colors as $color) {
        $color_name = $color['colorName'] ?? '';
        $color_name = sanitize_text_field($color_name);
        if ($color_name === '') {
            continue;
        }
        $image_url = syncmaster_normalize_ss_image_url($color['colorSwatchImage'] ?? '');
        if ($image_url !== '') {
            $map[$color_name] = $image_url;
        }
    }

    return $map;
}

function syncmaster_maybe_update_color_swatch_meta($candidate_names, $taxonomy, $swatch_map) {
    if (empty($candidate_names) || empty($swatch_map)) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ($candidate_names as $name) {
        $name = sanitize_text_field($name);
        if ($name === '') {
            continue;
        }
        $image_url = $swatch_map[$name] ?? '';
        if ($image_url === '') {
            continue;
        }
        $term = get_term_by('name', $name, $taxonomy);
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($name), $taxonomy);
        }
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $term_id = (int) $term->term_id;
        $existing = get_term_meta($term_id, 'smart-swatches-framework--src', true);
        if (!empty($existing)) {
            continue;
        }
        $attachment_id = media_sideload_image($image_url, 0, null, 'id');
        if (is_wp_error($attachment_id)) {
            continue;
        }
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            update_term_meta($term_id, 'smart-swatches-framework--src', esc_url_raw($attachment_url));
        }
    }
}

function syncmaster_calculate_simple_inventory_qty($color_size_qty_map) {
    $total_qty = 0;
    foreach ((array) $color_size_qty_map as $size_qtys) {
        if (!is_array($size_qtys)) {
            continue;
        }
        foreach ($size_qtys as $qty) {
            $total_qty += (int) $qty;
        }
    }

    return max(0, (int) $total_qty);
}

function syncmaster_calculate_simple_sell_price($color_size_price_map, $margin_percent) {
    $customer_price = 0;
    foreach ((array) $color_size_price_map as $size_prices) {
        if (!is_array($size_prices)) {
            continue;
        }
        foreach ($size_prices as $price) {
            $price = (float) $price;
            if ($price > 0) {
                $customer_price = $price;
                break 2;
            }
        }
    }

    if ($customer_price <= 0) {
        return '';
    }

    $margin_multiplier = max(((float) $margin_percent) / 100, 0.01);
    $sell_price = $customer_price / $margin_multiplier;
    $sell_price = syncmaster_round_up_price($sell_price, 0.25);

    return function_exists('wc_format_decimal') ? wc_format_decimal($sell_price) : $sell_price;
}

function syncmaster_round_up_price($price, $increment = 0.25) {
    $increment = (float) $increment;
    if ($increment <= 0) {
        return $price;
    }

    return ceil($price / $increment) * $increment;
}

function syncmaster_sync_variations($product_id, $base_sku, $color_size_map, $color_size_sku_map, $color_size_qty_map, $color_size_price_map, $color_size_image_map, $color_taxonomy, $size_taxonomy, $margin_percent, $color_slug_map = array()) {
    $stats = array(
        'created' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'errors' => array(),
        'attempted' => 0,
    );

    if (empty($color_size_map)) {
        $stats['errors'][] = 'empty_color_size_map';
        return $stats;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        $stats['errors'][] = 'missing_parent_product';
        return $stats;
    }

    if (!$product->is_type('variable')) {
        syncmaster_set_product_type_term($product_id, true);
        clean_post_cache($product_id);
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            $stats['errors'][] = 'parent_not_variable';
            return $stats;
        }
    }

    foreach ($product->get_children() as $child_id) {
        wp_delete_post($child_id, true);
        $stats['deleted']++;
    }

    foreach ($color_size_map as $color_name => $size_names) {
        $color_slug = $color_slug_map[$color_name] ?? syncmaster_get_attribute_term_slug($color_name, $color_taxonomy);
        if ($color_slug === '') {
            $stats['skipped']++;
            $stats['errors'][] = 'missing_color_slug:' . $color_name;
            continue;
        }
        foreach ($size_names as $size_name) {
            $stats['attempted']++;
            $size_slug = syncmaster_get_attribute_term_slug($size_name, $size_taxonomy);
            if ($size_slug === '') {
                $stats['skipped']++;
                $stats['errors'][] = 'missing_size_slug:' . $size_name;
                continue;
            }
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_attributes(array(
                $color_taxonomy => $color_slug,
                $size_taxonomy => $size_slug,
            ));
            $variation_sku = $color_size_sku_map[$color_name][$size_name] ?? '';
            if ($variation_sku !== '') {
                $variation->set_sku($variation_sku);
            } else {
                $variation_sku_parts = array_filter(array(
                    $base_sku,
                    $color_slug,
                    $size_slug,
                ));
                if (!empty($variation_sku_parts)) {
                    $variation_sku = implode('-', $variation_sku_parts);
                    $variation->set_sku($variation_sku);
                }
            }
            if ($variation_sku !== '' && isset($color_size_image_map[$variation_sku])) {
                $variation->update_meta_data('_syncmaster_external_image_url', esc_url_raw($color_size_image_map[$variation_sku]));
            }
            $variation_qty = $color_size_qty_map[$color_name][$size_name] ?? 0;
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity((int) $variation_qty);
            $variation->set_stock_status($variation_qty > 0 ? 'instock' : 'outofstock');
            $customer_price = $color_size_price_map[$color_name][$size_name] ?? 0;
            if ($customer_price > 0) {
                $margin_multiplier = max(((float) $margin_percent) / 100, 0.01);
                $variation_price = $customer_price / $margin_multiplier;
                $variation_price = syncmaster_round_up_price($variation_price, 0.25);
                if (function_exists('wc_format_decimal')) {
                    $variation_price = wc_format_decimal($variation_price);
                }
                $variation->set_regular_price($variation_price);
                $variation->set_price($variation_price);
            }
            $variation->set_status('publish');

            $saved_variation_id = $variation->save();
            if ($saved_variation_id) {
                $stats['created']++;
            } else {
                $stats['skipped']++;
                $stats['errors'][] = 'variation_save_failed:' . $color_name . ':' . $size_name;
            }
        }
    }

    return $stats;
}

function syncmaster_get_selected_category_style_ids() {
    $style_map = syncmaster_get_selected_category_style_map();
    if (empty($style_map)) {
        return array();
    }

    return array_keys($style_map);
}

function syncmaster_get_selected_category_style_map() {
    $cache_key = 'syncmaster_selected_category_style_ids';
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $rules = syncmaster_get_category_sync_rules();
    $available_categories = syncmaster_fetch_ss_categories();
    $name_to_id_map = array();
    foreach ($available_categories as $category_row) {
        if (!is_array($category_row)) {
            continue;
        }
        $name = sanitize_text_field($category_row['name'] ?? '');
        $id = sanitize_text_field($category_row['id'] ?? '');
        if ($name !== '' && $id !== '') {
            $name_to_id_map[$name] = $id;
        }
    }
    $enabled_categories = array();
    foreach ($rules as $source_id => $rule) {
        if (!empty($rule['enabled'])) {
            $normalized_source_id = sanitize_text_field($source_id);
            if (isset($name_to_id_map[$normalized_source_id])) {
                $normalized_source_id = $name_to_id_map[$normalized_source_id];
            }
            $enabled_categories[] = $normalized_source_id;
        }
    }

    if (empty($enabled_categories)) {
        return array();
    }

    $data = syncmaster_fetch_style_category_index();
    if (empty($data)) {
        return array();
    }

    $enabled_lookup = array_fill_keys($enabled_categories, true);
    $style_map = array();
    foreach ($data as $style) {
        if (!is_array($style)) {
            continue;
        }
        $style_id = sanitize_text_field($style['style_id'] ?? '');
        $category_ids = isset($style['category_ids']) && is_array($style['category_ids']) ? $style['category_ids'] : array();
        if ($style_id === '' || empty($category_ids)) {
            continue;
        }
        foreach ($category_ids as $category_id) {
            $category_id = sanitize_text_field($category_id);
            if (isset($enabled_lookup[$category_id])) {
                if (!isset($style_map[$style_id])) {
                    $style_map[$style_id] = array();
                }
                $style_map[$style_id][] = $category_id;
            }
        }
    }

    foreach ($style_map as $style_id => $category_ids) {
        $style_map[$style_id] = array_values(array_unique(array_filter(array_map('sanitize_text_field', $category_ids))));
    }
    set_transient($cache_key, $style_map, 15 * MINUTE_IN_SECONDS);

    return $style_map;
}

function syncmaster_get_mapped_product_category_names($category_name, $category_ids = array()) {
    $rules = syncmaster_get_category_sync_rules();
    $categories = array();

    if (!is_array($category_ids) || empty($category_ids)) {
        return array();
    }

    foreach ($category_ids as $category_id) {
        $category_id = sanitize_text_field($category_id);
        if ($category_id === '') {
            continue;
        }
        $rule = $rules[$category_id] ?? array();
        if (!empty($rule['enabled']) && ($rule['mode'] ?? '') === 'existing' && !empty($rule['target_term_id'])) {
            $term = get_term((int) $rule['target_term_id'], 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = $term->name;
                continue;
            }
        }

        if (!empty($rule['enabled']) && !empty($rule['new_name'])) {
            $categories[] = sanitize_text_field($rule['new_name']);
        }

        $rule = array();
        foreach ($rules as $candidate_rule) {
            $candidate_name = sanitize_text_field($candidate_rule['source_name'] ?? '');
            if ($candidate_name === $raw_category) {
                $rule = $candidate_rule;
                break;
            }
        }
        if (!empty($rule['enabled']) && ($rule['mode'] ?? '') === 'existing' && !empty($rule['target_term_id'])) {
            $term = get_term((int) $rule['target_term_id'], 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = $term->name;
                continue;
            }
        }

        if (!empty($rule['enabled']) && !empty($rule['new_name'])) {
            $categories[] = sanitize_text_field($rule['new_name']);
            continue;
        }

            $categories[] = $raw_category;
    }

    return array_values(array_unique(array_filter($categories)));
}

function syncmaster_set_product_category($product_id, $category_name, $category_ids = array()) {
    $categories = syncmaster_get_mapped_product_category_names($category_name, $category_ids);
    if (empty($categories)) {
        return;
    }

    $term_ids = array();
    foreach ($categories as $category) {
        $term = term_exists($category, 'product_cat');
        if (!$term) {
            $term = wp_insert_term($category, 'product_cat');
        }
        if (is_wp_error($term)) {
            continue;
        }
        $term_id = is_array($term) ? $term['term_id'] : $term;
        if ($term_id) {
            $term_ids[] = (int) $term_id;
        }
    }

    if (!empty($term_ids)) {
        wp_set_object_terms($product_id, $term_ids, 'product_cat', false);
    }
}


function syncmaster_get_object_term_ids($object_id, $taxonomy) {
    if (!$object_id || !taxonomy_exists($taxonomy)) {
        return array();
    }

    $terms = wp_get_object_terms((int) $object_id, $taxonomy, array('fields' => 'ids'));
    if (is_wp_error($terms) || !is_array($terms)) {
        return array();
    }

    return array_values(array_map('intval', $terms));
}

function syncmaster_set_product_type_term($product_id, $is_variable) {
    if (!$product_id || !taxonomy_exists('product_type')) {
        return;
    }

    wp_set_object_terms(
        (int) $product_id,
        $is_variable ? 'variable' : 'simple',
        'product_type',
        false
    );
}

function syncmaster_set_featured_image($product_id, $image_url) {
    $image_url = syncmaster_normalize_ss_image_url($image_url);
    if ($image_url === '') {
        return;
    }

    if (has_post_thumbnail($product_id)) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');
    if (!is_wp_error($attachment_id)) {
        set_post_thumbnail($product_id, $attachment_id);
        update_post_meta($product_id, '_syncmaster_style_image', esc_url_raw($image_url));
    }
}

function syncmaster_get_current_sync_queue($options = array()) {
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $monitored = syncmaster_get_monitored_products();
    $monitored_skus = array();
    foreach ($monitored as $row) {
        $sku = sanitize_text_field($row['sku'] ?? '');
        if ($sku !== '') {
            $monitored_skus[] = $sku;
        }
    }
    $explicit_queue = isset($options['explicit_queue']) && is_array($options['explicit_queue'])
        ? array_values(array_unique(array_map('sanitize_text_field', $options['explicit_queue'])))
        : array();
    $selected_category_style_map = syncmaster_get_selected_category_style_map();
    $category_skus = array_keys($selected_category_style_map);
    if (!empty($explicit_queue)) {
        $sync_queue = $explicit_queue;
    } else {
        $sync_queue = !empty($category_skus)
            ? array_values(array_unique($category_skus))
            : array_values(array_unique($monitored_skus));
    }
    return array(
        'sync_queue' => $sync_queue,
        'selected_category_style_map' => $selected_category_style_map,
        'monitored_skus' => $monitored_skus,
        'category_skus' => $category_skus,
    );
}

function syncmaster_sync_monitored_products($limit = 0, $offset = 0, $options = array()) {
    $mode = sanitize_text_field($options['mode'] ?? 'full');
    $queue_data = syncmaster_get_current_sync_queue($options);
    $sync_queue = $queue_data['sync_queue'];
    $selected_category_style_map = $queue_data['selected_category_style_map'];
    $monitored_skus = $queue_data['monitored_skus'];
    $category_skus = $queue_data['category_skus'];

    if ($limit > 0) {
        $sync_queue = array_slice($sync_queue, max(0, (int) $offset), (int) $limit);
    }
    $monitored_count = count($sync_queue);
    $color_selections = syncmaster_get_color_selections();
    $color_taxonomy = syncmaster_get_color_taxonomy();
    $size_taxonomy = syncmaster_get_size_taxonomy();

    if (!class_exists('WooCommerce')) {
        return array(
            'status' => 'error',
            'message' => __('Sync failed: WooCommerce is not active.', 'syncmaster'),
            'success' => 0,
            'fail' => $monitored_count,
            'monitored' => $monitored_count,
            'created' => 0,
            'updated' => 0,
        );
    }

    $created = 0;
    $updated = 0;
    $fail = 0;

    foreach ($sync_queue as $sku) {
        $api_item = syncmaster_fetch_ss_product($sku);
        if (empty($api_item)) {
            $fail++;
            syncmaster_write_log(
                'error',
                sprintf(__('Sync failed for SKU %s: no API data returned.', 'syncmaster'), $sku),
                0,
                1,
                array('sku' => $sku, 'reason' => 'empty_api_item')
            );
            continue;
        }
        $mapped = syncmaster_map_product_data($api_item);
        $desired_sku = $mapped['sku'] !== '' ? $mapped['sku'] : $sku;
        $product_id_by_monitored_sku = wc_get_product_id_by_sku($sku);
        $product_id_by_desired_sku = $desired_sku !== '' ? wc_get_product_id_by_sku($desired_sku) : 0;
        $product_id = $product_id_by_monitored_sku;
        $product_match_source = $product_id ? 'monitored_sku' : 'new_product';

        if (!$product_id && $product_id_by_desired_sku) {
            $product_id = $product_id_by_desired_sku;
            $product_match_source = 'desired_sku';
        }

        if ($mode === 'new_only' && $product_id) {
            continue;
        }
        if ($mode === 'inventory_variations_only' && !$product_id) {
            continue;
        }

        if ($product_id_by_monitored_sku && $product_id_by_desired_sku && $product_id_by_monitored_sku !== $product_id_by_desired_sku) {
            $fail++;
            syncmaster_write_log(
                'error',
                sprintf(__('Sync failed for SKU %s: monitored SKU and mapped SKU resolve to different products.', 'syncmaster'), $sku),
                0,
                1,
                array(
                    'sku' => $sku,
                    'desired_sku' => $desired_sku,
                    'product_id_by_monitored_sku' => (int) $product_id_by_monitored_sku,
                    'product_id_by_desired_sku' => (int) $product_id_by_desired_sku,
                    'reason' => 'sku_resolution_conflict',
                )
            );
            continue;
        }

        $style_title = trim(($api_item['brandName'] ?? '') . ' ' . ($api_item['styleName'] ?? ''));
        $colors = $style_title !== '' ? syncmaster_get_style_colors($style_title) : array();
        $selected_colors = $color_selections[$sku] ?? array();
        $color_term_ids = syncmaster_resolve_color_term_ids($colors, $selected_colors);
        $existing_color_term_ids = $product_id ? syncmaster_get_object_term_ids($product_id, $color_taxonomy) : array();
        if (!empty($existing_color_term_ids) && !empty($color_term_ids)) {
            $color_term_ids = array_values(array_unique(array_merge($existing_color_term_ids, $color_term_ids)));
        }
        $color_size_map = syncmaster_collect_color_size_map($colors, $selected_colors);
        $color_size_sku_map = syncmaster_collect_color_size_sku_map($colors, $selected_colors);
        $color_size_qty_map = syncmaster_collect_color_size_qty_map($colors, $selected_colors);
        $color_size_price_map = syncmaster_collect_color_size_price_map($colors, $selected_colors);
        $size_names = syncmaster_collect_size_names($colors, $selected_colors);
        $size_term_ids = syncmaster_resolve_attribute_term_ids($size_names, $size_taxonomy);
        $is_variable = count($color_term_ids) > 1 || count($size_term_ids) > 1;
        $has_new_toggled_colors = !empty(array_diff($color_term_ids, $existing_color_term_ids));
        if ($has_new_toggled_colors) {
            $is_variable = true;
        }
        $margin_percent = syncmaster_get_margin_percent_for_sku($sku, 50);
        $color_size_image_map = syncmaster_collect_color_size_image_map($colors, $selected_colors);
        $color_slug_map = syncmaster_collect_color_slug_map($colors, $selected_colors, $color_taxonomy);
        $color_postbox_view_map = syncmaster_collect_color_postbox_view_map($colors, $selected_colors, $color_taxonomy, $color_slug_map);
        if ($product_id) {
            syncmaster_set_product_type_term($product_id, $is_variable);
            $product = $is_variable ? new WC_Product_Variable($product_id) : new WC_Product_Simple($product_id);
        } else {
            $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
        }

        if (!$product) {
            $fail++;
            syncmaster_write_log(
                'error',
                sprintf(__('Sync failed for SKU %s: could not initialize WooCommerce product object.', 'syncmaster'), $sku),
                0,
                1,
                array('sku' => $sku, 'reason' => 'product_init_failed', 'is_variable' => $is_variable, 'desired_sku' => $desired_sku, 'product_match_source' => $product_match_source, 'product_id_by_monitored_sku' => (int) $product_id_by_monitored_sku, 'product_id_by_desired_sku' => (int) $product_id_by_desired_sku)
            );
            continue;
        }

        $product->set_name($mapped['name'] !== '' ? $mapped['name'] : sprintf(__('Synced SKU %s', 'syncmaster'), $sku));
        $existing_id = wc_get_product_id_by_sku($desired_sku);
        if ($existing_id && $existing_id !== $product_id) {
            $fail++;
            syncmaster_write_log(
                'error',
                sprintf(__('Sync failed for SKU %s: mapped SKU %s is already used by product ID %d.', 'syncmaster'), $sku, $desired_sku, (int) $existing_id),
                0,
                1,
                array('sku' => $sku, 'desired_sku' => $desired_sku, 'conflicting_product_id' => (int) $existing_id, 'product_id' => (int) $product_id, 'product_match_source' => $product_match_source, 'product_id_by_monitored_sku' => (int) $product_id_by_monitored_sku, 'product_id_by_desired_sku' => (int) $product_id_by_desired_sku, 'reason' => 'duplicate_sku')
            );
            continue;
        }
        $product->set_sku($desired_sku);
        if (!empty($mapped['slug'])) {
            $product->set_slug($mapped['slug']);
        }
        if ($mapped['description'] !== '') {
            $product->set_description($mapped['description']);
        }
        $product->set_status('publish');
        syncmaster_apply_color_attributes($product, $color_term_ids, $color_taxonomy, $is_variable);
        syncmaster_apply_size_attributes($product, $size_term_ids, $size_taxonomy, $is_variable);
        if (!$is_variable) {
            $simple_qty = syncmaster_calculate_simple_inventory_qty($color_size_qty_map);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($simple_qty);
            $product->set_stock_status($simple_qty > 0 ? 'instock' : 'outofstock');

            $simple_price = syncmaster_calculate_simple_sell_price($color_size_price_map, $margin_percent);
            if ($simple_price !== '') {
                $product->set_regular_price($simple_price);
                $product->set_price($simple_price);
            }
        }
        $saved_id = $product->save();

        $variation_stats = array(
            'created' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => array(),
            'attempted' => 0,
        );

        if ($saved_id) {
            syncmaster_assign_color_terms($saved_id, $color_term_ids, $color_taxonomy);
            syncmaster_assign_size_terms($saved_id, $size_term_ids, $size_taxonomy);
            syncmaster_apply_product_brand($saved_id, $product, $mapped['brand']);
            $selected_category_ids_for_style = $selected_category_style_map[$sku] ?? array();
            syncmaster_set_product_category($saved_id, $mapped['category'], $selected_category_ids_for_style);
            syncmaster_update_threaddesk_product_postbox($saved_id, $color_postbox_view_map);
            if ($mapped['image'] !== '') {
                syncmaster_set_featured_image($saved_id, $mapped['image']);
            }
            if ($is_variable) {
                $variation_stats = syncmaster_sync_variations(
                    $saved_id,
                    $desired_sku,
                    $color_size_map,
                    $color_size_sku_map,
                    $color_size_qty_map,
                    $color_size_price_map,
                    $color_size_image_map,
                    $color_taxonomy,
                    $size_taxonomy,
                    $margin_percent,
                    $color_slug_map
                );
                if (!empty($variation_stats['errors'])) {
                    syncmaster_write_log(
                        'error',
                        sprintf(__('Variation sync warnings for SKU %s.', 'syncmaster'), $sku),
                        (int) ($variation_stats['created'] ?? 0),
                        (int) ($variation_stats['skipped'] ?? 0),
                        array(
                            'sku' => $sku,
                            'product_id' => (int) $saved_id,
                            'variation_stats' => $variation_stats,
                            'selected_colors' => $selected_colors,
                            'color_size_map_keys' => array_keys($color_size_map),
                        )
                    );
                }
            }
        }

        if ($saved_id) {
            if ($product_id) {
                $updated++;
            } else {
                $created++;
            }
            syncmaster_write_log(
                'info',
                sprintf(__('Synced SKU %s successfully.', 'syncmaster'), $sku),
                1,
                0,
                array(
                    'sku' => $sku,
                    'product_id' => (int) $saved_id,
                    'product_type' => $is_variable ? 'variable' : 'simple',
                    'product_match_source' => $product_match_source,
                    'product_id_by_monitored_sku' => (int) $product_id_by_monitored_sku,
                    'product_id_by_desired_sku' => (int) $product_id_by_desired_sku,
                    'selected_colors_count' => count($selected_colors),
                    'resolved_colors_count' => count($color_term_ids),
                    'resolved_sizes_count' => count($size_term_ids),
                    'inventory_qty_total' => syncmaster_calculate_simple_inventory_qty($color_size_qty_map),
                    'variations_target_count' => array_sum(array_map('count', $color_size_map)),
                    'variation_stats' => $variation_stats,
                    'existing_color_term_ids' => $existing_color_term_ids,
                    'resolved_color_term_ids' => $color_term_ids,
                    'selected_colors' => $selected_colors,
                    'color_size_map_keys' => array_keys($color_size_map),
                    'threaddesk_color_views_count' => count($color_postbox_view_map),
                )
            );
        } else {
            $fail++;
            syncmaster_write_log(
                'error',
                sprintf(__('Sync failed for SKU %s: product save returned no ID.', 'syncmaster'), $sku),
                0,
                1,
                array(
                    'sku' => $sku,
                    'desired_sku' => $desired_sku,
                    'product_type' => $is_variable ? 'variable' : 'simple',
                    'product_match_source' => $product_match_source,
                    'product_id_by_monitored_sku' => (int) $product_id_by_monitored_sku,
                    'product_id_by_desired_sku' => (int) $product_id_by_desired_sku,
                    'reason' => 'save_failed',
                    'inventory_qty_total' => syncmaster_calculate_simple_inventory_qty($color_size_qty_map),
                    'existing_color_term_ids' => $existing_color_term_ids,
                    'resolved_color_term_ids' => $color_term_ids,
                    'selected_colors' => $selected_colors,
                    'color_size_map_keys' => array_keys($color_size_map),
                    'variation_stats' => $variation_stats,
                )
            );
        }
    }

    $status = $fail > 0 ? 'error' : 'success';
    $message = $fail > 0
        ? __('Sync completed with errors.', 'syncmaster')
        : __('Sync completed.', 'syncmaster');

    return array(
        'status' => $status,
        'message' => $message,
        'success' => $created + $updated,
        'fail' => $fail,
        'monitored' => $monitored_count,
        'created' => $created,
        'updated' => $updated,
        'processed' => $monitored_count,
        'queue_source_counts' => array(
            'monitored_products' => count($monitored_skus),
            'category_styles' => count($category_skus),
            'active_queue_source' => !empty($category_skus) ? 'categories' : 'monitored_products',
        ),
    );
}

function syncmaster_write_log($level, $message, $success_count = 0, $fail_count = 0, $context = array()) {
    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_LOGS_TABLE;
    $wpdb->insert(
        $table,
        array(
            'log_time' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'success_count' => (int) $success_count,
            'fail_count' => (int) $fail_count,
            'context_json' => wp_json_encode($context),
        ),
        array('%s', '%s', '%s', '%d', '%d', '%s')
    );
}

function syncmaster_get_logs() {
    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_LOGS_TABLE;
    return $wpdb->get_results("SELECT log_time, level, message, success_count, fail_count, context_json FROM {$table} ORDER BY log_time DESC LIMIT 50", ARRAY_A);
}

function syncmaster_get_external_image_url($product) {
    if (!$product || !is_a($product, 'WC_Product')) {
        return '';
    }

    $image_url = get_post_meta($product->get_id(), '_syncmaster_external_image_url', true);
    $image_url = trim((string) $image_url);
    if ($image_url === '') {
        return '';
    }

    return esc_url($image_url);
}

function syncmaster_render_external_product_image($image, $product, $size, $attr, $placeholder) {
    $image_url = syncmaster_get_external_image_url($product);
    if ($image_url === '') {
        return $image;
    }

    $size_data = is_array($size) ? $size : wc_get_image_size($size);
    $width = isset($size_data['width']) ? (int) $size_data['width'] : '';
    $height = isset($size_data['height']) ? (int) $size_data['height'] : '';

    $attributes = array(
        'src' => $image_url,
        'alt' => $product ? esc_attr($product->get_name()) : '',
        'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
    );

    if ($width !== '') {
        $attributes['width'] = $width;
    }
    if ($height !== '') {
        $attributes['height'] = $height;
    }
    if (is_array($attr) && !empty($attr)) {
        $attributes = array_merge($attributes, $attr);
    }

    $html_attributes = array();
    foreach ($attributes as $name => $value) {
        if ($value === '') {
            continue;
        }
        $html_attributes[] = sprintf('%s="%s"', esc_attr($name), esc_attr($value));
    }

    return sprintf('<img %s />', implode(' ', $html_attributes));
}

add_filter('woocommerce_product_get_image', 'syncmaster_render_external_product_image', 10, 5);

function syncmaster_apply_external_variation_image($data, $product, $variation) {
    $image_url = syncmaster_get_external_image_url($variation);
    if ($image_url === '') {
        return $data;
    }

    $image_data = array(
        'src' => $image_url,
        'srcset' => '',
        'sizes' => '',
        'full_src' => $image_url,
        'full_src_w' => '',
        'full_src_h' => '',
        'thumb_src' => $image_url,
        'thumb_src_w' => '',
        'thumb_src_h' => '',
        'alt' => $variation ? $variation->get_name() : '',
        'title' => $variation ? $variation->get_name() : '',
        'caption' => '',
    );

    $data['image'] = array_merge($data['image'], $image_data);
    $data['image_id'] = 0;

    return $data;
}

add_filter('woocommerce_available_variation', 'syncmaster_apply_external_variation_image', 10, 3);

function syncmaster_render_admin_variation_thumb($image, $variation_id = 0, $variation = null) {
    $product = null;
    if ($variation instanceof WC_Product) {
        $product = $variation;
    } elseif ($variation_id) {
        $product = wc_get_product($variation_id);
    }

    $image_url = syncmaster_get_external_image_url($product);
    if ($image_url === '') {
        return $image;
    }

    $alt = $product ? $product->get_name() : '';
    return sprintf(
        '<img src="%s" alt="%s" class="attachment-thumbnail size-thumbnail" width="60" height="60" />',
        esc_url($image_url),
        esc_attr($alt)
    );
}

add_filter('woocommerce_admin_variation_thumb', 'syncmaster_render_admin_variation_thumb', 10, 3);

function syncmaster_get_last_sync_time() {
    $logs = syncmaster_get_logs();
    if (empty($logs)) {
        return __('Never', 'syncmaster');
    }
    return $logs[0]['log_time'];
}

function syncmaster_get_last_sync_status() {
    $logs = syncmaster_get_logs();
    if (empty($logs)) {
        return __('No data', 'syncmaster');
    }
    return ucfirst($logs[0]['level']);
}

function syncmaster_get_monitored_products($limit = 0, $offset = 0) {
    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
    $sql = "SELECT sku FROM {$table} ORDER BY created_at DESC";
    $limit = (int) $limit;
    $offset = max(0, (int) $offset);
    if ($limit > 0) {
        $sql = $wpdb->prepare("SELECT sku FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset);
    }
    return $wpdb->get_results($sql, ARRAY_A);
}

function syncmaster_get_products_count() {
    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

function syncmaster_handle_add_sku() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_add_sku');

    $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
    if ($sku !== '') {
        global $wpdb;
        $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
        $wpdb->replace(
            $table,
            array('sku' => $sku, 'created_at' => current_time('mysql')),
            array('%s', '%s')
        );
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&added=1'));
    exit;
}

function syncmaster_handle_remove_sku() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_remove_sku');

    $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
    if ($sku !== '') {
        global $wpdb;
        $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
        $wpdb->delete($table, array('sku' => $sku), array('%s'));
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&removed=1'));
    exit;
}

function syncmaster_handle_bulk_remove_skus() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_bulk_remove_skus');

    $posted_skus = $_POST['skus'] ?? array();
    if (!is_array($posted_skus) || empty($posted_skus)) {
        wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&removed=0'));
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
    $removed_count = 0;

    foreach ($posted_skus as $sku_value) {
        $sku = sanitize_text_field(wp_unslash($sku_value));
        if ($sku === '') {
            continue;
        }
        $deleted = $wpdb->delete($table, array('sku' => $sku), array('%s'));
        if ($deleted) {
            $removed_count += (int) $deleted;
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&removed=' . $removed_count));
    exit;
}

function syncmaster_handle_save_colors() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_save_colors');

    $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
    if ($sku === '') {
        wp_safe_redirect(admin_url('admin.php?page=syncmaster_products'));
        exit;
    }

    $selected = array();
    $posted_colors = $_POST['syncmaster_colors'] ?? array();
    if (is_array($posted_colors)) {
        foreach ($posted_colors as $color) {
            $color = sanitize_text_field(wp_unslash($color));
            if ($color !== '') {
                $selected[] = $color;
            }
        }
    }

    $selections = syncmaster_get_color_selections();
    $selections[$sku] = array_values(array_unique($selected));
    update_option('syncmaster_color_selections', $selections);

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_products&colors_saved=1'));
    exit;
}

function syncmaster_get_style_summary($style_id) {
    $style_id = sanitize_text_field($style_id);
    $cache_key = 'syncmaster_style_' . $style_id;
    $cached = get_transient($cache_key);
    if ($cached) {
        return $cached;
    }

    $style = syncmaster_fetch_ss_product($style_id);
    $summary = array(
        'title' => $style_id,
        'baseCategory' => __('Unknown', 'syncmaster'),
    );

    if (!empty($style) && is_array($style)) {
        $brand = $style['brandName'] ?? '';
        $style_name = $style['styleName'] ?? '';
        $summary['title'] = trim($brand . ' ' . $style_name);
        $summary['baseCategory'] = $style['baseCategory'] ?? __('Unknown', 'syncmaster');
    }

    set_transient($cache_key, $summary, MINUTE_IN_SECONDS * 10);
    return $summary;
}

function syncmaster_extract_scalar($value) {
    if (is_array($value)) {
        if (isset($value[0])) {
            return syncmaster_extract_scalar($value[0]);
        }
        if (!empty($value)) {
            return syncmaster_extract_scalar(reset($value));
        }
        return '';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
}

function syncmaster_sum_warehouse_qty($item) {
    $warehouses = $item['warehouses'] ?? ($item['Warehouses'] ?? array());
    if (!is_array($warehouses)) {
        return 0;
    }

    $warehouse_rows = $warehouses['warehouse'] ?? ($warehouses['Warehouse'] ?? $warehouses);
    if (!is_array($warehouse_rows)) {
        return 0;
    }

    $rows = isset($warehouse_rows[0]) ? $warehouse_rows : array($warehouse_rows);
    $total = 0;
    foreach ($rows as $warehouse) {
        if (!is_array($warehouse)) {
            continue;
        }
        if (isset($warehouse['qty'])) {
            $total += (int) syncmaster_extract_scalar($warehouse['qty']);
        } elseif (isset($warehouse['Qty'])) {
            $total += (int) syncmaster_extract_scalar($warehouse['Qty']);
        }
    }

    return $total;
}

function syncmaster_parse_api_response($body) {
    $data = json_decode($body, true);
    if (is_array($data)) {
        return $data;
    }

    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        return array();
    }

    $decoded = json_decode(wp_json_encode($xml), true);
    return is_array($decoded) ? $decoded : array();
}

function syncmaster_get_style_colors($style_title) {
    $style_title = sanitize_text_field($style_title);
    $cache_key = 'syncmaster_style_colors_' . md5($style_title);
    $cached = get_transient($cache_key);
    if ($cached) {
        return $cached;
    }

    $username = get_option('ss_username', '');
    $password = get_option('ss_password', '');
    $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    );
    if ($username !== '' && $password !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
    }

    $endpoint = add_query_arg('style', rawurlencode($style_title), SYNCMASTER_API_URL);
    $response = wp_remote_get($endpoint, array(
        'timeout' => 15,
        'headers' => $headers,
    ));

    $colors = array();
    if (!is_wp_error($response)) {
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = syncmaster_parse_api_response($body);
        if ($status === 200 && is_array($data)) {
            $items = $data['Sku'] ?? ($data['sku'] ?? $data);
            if (!is_array($items)) {
                $items = array();
            }
            if (!empty($items) && !isset($items[0])) {
                $items = array($items);
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $color_code = syncmaster_extract_scalar($item['colorCode'] ?? ($item['ColorCode'] ?? ''));
                if ($color_code === '') {
                    continue;
                }
                if (!isset($colors[$color_code])) {
                    $colors[$color_code] = array(
                        'colorCode' => $color_code,
                        'colorFamily' => syncmaster_extract_scalar($item['colorFamily'] ?? ($item['ColorFamily'] ?? '')),
                        'colorGroupName' => syncmaster_extract_scalar($item['colorGroupName'] ?? ($item['ColorGroupName'] ?? '')),
                        'colorName' => syncmaster_extract_scalar($item['colorName'] ?? ($item['ColorName'] ?? '')),
                        'colorFrontImage' => syncmaster_extract_scalar($item['colorFrontImage'] ?? ($item['ColorFrontImage'] ?? '')),
                        'colorBackImage' => syncmaster_extract_scalar($item['colorBackImage'] ?? ($item['ColorBackImage'] ?? '')),
                        'colorDirectSideImage' => syncmaster_extract_scalar($item['colorDirectSideImage'] ?? ($item['ColorDirectSideImage'] ?? '')),
                        'colorSideImage' => syncmaster_extract_scalar($item['colorSideImage'] ?? ($item['ColorSideImage'] ?? '')),
                        'colorSwatchImage' => syncmaster_extract_scalar($item['colorSwatchImage'] ?? ($item['ColorSwatchImage'] ?? '')),
                        'sizeNames' => array(),
                        'sizeSkus' => array(),
                        'sizePrices' => array(),
                        'sizeQtys' => array(),
                    );
                }
                if (empty($colors[$color_code]['colorFrontImage'])) {
                    $colors[$color_code]['colorFrontImage'] = syncmaster_extract_scalar($item['colorFrontImage'] ?? ($item['ColorFrontImage'] ?? ''));
                }
                if (empty($colors[$color_code]['colorBackImage'])) {
                    $colors[$color_code]['colorBackImage'] = syncmaster_extract_scalar($item['colorBackImage'] ?? ($item['ColorBackImage'] ?? ''));
                }
                if (empty($colors[$color_code]['colorDirectSideImage'])) {
                    $colors[$color_code]['colorDirectSideImage'] = syncmaster_extract_scalar($item['colorDirectSideImage'] ?? ($item['ColorDirectSideImage'] ?? ''));
                }
                if (empty($colors[$color_code]['colorSideImage'])) {
                    $colors[$color_code]['colorSideImage'] = syncmaster_extract_scalar($item['colorSideImage'] ?? ($item['ColorSideImage'] ?? ''));
                }
                if (empty($colors[$color_code]['colorFamily'])) {
                    $colors[$color_code]['colorFamily'] = syncmaster_extract_scalar($item['colorFamily'] ?? ($item['ColorFamily'] ?? ''));
                }
                if (empty($colors[$color_code]['colorGroupName'])) {
                    $colors[$color_code]['colorGroupName'] = syncmaster_extract_scalar($item['colorGroupName'] ?? ($item['ColorGroupName'] ?? ''));
                }
                $size_name = sanitize_text_field(syncmaster_extract_scalar($item['sizeName'] ?? ($item['SizeName'] ?? '')));
                if ($size_name !== '') {
                    $colors[$color_code]['sizeNames'][] = $size_name;
                }
                $size_sku = sanitize_text_field(syncmaster_extract_scalar($item['sku'] ?? ($item['Sku'] ?? '')));
                if ($size_name !== '' && $size_sku !== '') {
                    $colors[$color_code]['sizeSkus'][$size_name] = $size_sku;
                }
                $size_price = syncmaster_extract_scalar($item['customerPrice'] ?? ($item['CustomerPrice'] ?? ''));
                if ($size_name !== '' && $size_price !== '') {
                    $colors[$color_code]['sizePrices'][$size_name] = (float) $size_price;
                }
                $size_qty = 0;
                if (isset($item['qty'])) {
                    $size_qty = (int) syncmaster_extract_scalar($item['qty']);
                } elseif (isset($item['Qty'])) {
                    $size_qty = (int) syncmaster_extract_scalar($item['Qty']);
                } else {
                    $size_qty = syncmaster_sum_warehouse_qty($item);
                }
                if ($size_name !== '') {
                    if (!isset($colors[$color_code]['sizeQtys'][$size_name])) {
                        $colors[$color_code]['sizeQtys'][$size_name] = 0;
                    }
                    $colors[$color_code]['sizeQtys'][$size_name] += $size_qty;
                }
            }
        }
    }

    foreach ($colors as $color_code => $color_data) {
        $size_names = $color_data['sizeNames'] ?? array();
        if (!empty($size_names)) {
            $colors[$color_code]['sizeNames'] = array_values(array_unique($size_names));
        }
        $size_skus = $color_data['sizeSkus'] ?? array();
        if (!empty($size_skus)) {
            $colors[$color_code]['sizeSkus'] = array_filter($size_skus, 'strlen');
        }
        $size_prices = $color_data['sizePrices'] ?? array();
        if (!empty($size_prices)) {
            $colors[$color_code]['sizePrices'] = array_filter($size_prices, 'is_numeric');
        }
        $size_qtys = $color_data['sizeQtys'] ?? array();
        if (!empty($size_qtys)) {
            $colors[$color_code]['sizeQtys'] = array_filter($size_qtys, 'is_numeric');
        }
    }

    $colors = array_values($colors);
    set_transient($cache_key, $colors, MINUTE_IN_SECONDS * 10);
    return $colors;
}

function syncmaster_render_colors_panel_markup($sku, $colors) {
    $sku = sanitize_text_field($sku);
    $selections = syncmaster_get_color_selections();
    $has_color_selection = array_key_exists($sku, $selections);
    $selected_colors = $selections[$sku] ?? array();

    ob_start();
    if (empty($colors)) :
        ?>
        <p class="syncmaster-muted"><?php echo esc_html__('No color data found.', 'syncmaster'); ?></p>
        <?php
    else :
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('syncmaster_save_colors'); ?>
            <input type="hidden" name="action" value="syncmaster_save_colors">
            <input type="hidden" name="sku" value="<?php echo esc_attr($sku); ?>">
            <div class="syncmaster-color-grid">
                <?php foreach ($colors as $color) : ?>
                    <?php
                    $color_name = $color['colorName'] ?? '';
                    $image_url = $color['colorFrontImage'] ?? '';
                    if ($image_url !== '' && strpos($image_url, 'http') !== 0) {
                        $image_url = 'https://cdn.ssactivewear.com/' . ltrim($image_url, '/');
                    }
                    $is_checked = !$has_color_selection || in_array($color_name, $selected_colors, true);
                    ?>
                    <label class="syncmaster-color-card">
                        <span class="syncmaster-color-toggle">
                            <input type="checkbox" name="syncmaster_colors[]" value="<?php echo esc_attr($color_name); ?>" <?php checked($is_checked); ?>>
                            <?php echo esc_html__('Include', 'syncmaster'); ?>
                        </span>
                        <?php if ($image_url) : ?>
                            <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($color_name); ?>">
                        <?php endif; ?>
                        <div>
                            <strong><?php echo esc_html($color_name); ?></strong>
                            <span class="syncmaster-muted"><?php echo esc_html($color['colorCode']); ?></span>
                            <?php
                            $size_names = $color['sizeNames'] ?? array();
                            if (!empty($size_names)) :
                                $size_list = implode(', ', array_map('sanitize_text_field', $size_names));
                                ?>
                                <span class="syncmaster-muted"><?php echo esc_html($size_list); ?></span>
                            <?php endif; ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="button syncmaster-save-colors">
                <?php echo esc_html__('Save Color Preferences', 'syncmaster'); ?>
            </button>
        </form>
        <?php
    endif;

    return ob_get_clean();
}

function syncmaster_handle_load_colors_panel() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'syncmaster')), 403);
    }

    check_ajax_referer('syncmaster_load_colors_panel', 'nonce');

    $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
    if ($sku === '') {
        wp_send_json_error(array('message' => __('Missing SKU.', 'syncmaster')), 400);
    }

    $style = syncmaster_get_style_summary($sku);
    $colors = syncmaster_get_style_colors($style['title'] ?? $sku);
    $html = syncmaster_render_colors_panel_markup($sku, $colors);
    wp_send_json_success(array('html' => $html));
}

function syncmaster_ss_search($query) {
    $query = strtolower($query);
    $endpoint = add_query_arg(
        array('search' => $query),
        SYNCMASTER_STYLES_API_URL
    );
    $headers = array();
    $username = get_option('ss_username', '');
    $password = get_option('ss_password', '');
    if ($username !== '' && $password !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
    }
    $response = wp_remote_get($endpoint, array(
        'timeout' => 15,
        'headers' => $headers,
    ));
    if (!is_wp_error($response)) {
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status === 200 && is_array($data)) {
            $results = array();
            foreach ($data as $item) {
                $name_parts = array_filter(array(
                    $item['brandName'] ?? '',
                    $item['styleName'] ?? '',
                ));
                $results[] = array(
                    'name' => implode(' ', $name_parts),
                    'sku' => $item['styleID'] ?? '',
                );
            }
            return array_values(array_filter($results, function ($item) use ($query) {
                return $query === '' || strpos(strtolower($item['name']), $query) !== false || strpos(strtolower($item['sku']), $query) !== false;
            }));
        }
    }

    $fallback = array(
        array('name' => 'Sample Hoodie', 'sku' => 'HD-100'),
        array('name' => 'Classic Tee', 'sku' => 'TS-200'),
        array('name' => 'Canvas Tote', 'sku' => 'BG-300'),
        array('name' => 'Coffee Mug', 'sku' => 'MG-400'),
    );

    return array_values(array_filter($fallback, function ($item) use ($query) {
        return $query === '' || strpos(strtolower($item['name']), $query) !== false || strpos(strtolower($item['sku']), $query) !== false;
    }));
}

function syncmaster_handle_test_api() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_test_api');

    $username = get_option('ss_username', '');
    $password = get_option('ss_password', '');
    $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    );
    if ($username !== '' && $password !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
    }

    $endpoint = trailingslashit(SYNCMASTER_API_URL) . 'B00760004';
    $response = wp_remote_get($endpoint, array(
        'timeout' => 15,
        'headers' => $headers,
    ));

    $result = array(
        'tested_at' => current_time('mysql'),
        'endpoint' => $endpoint,
        'status' => 'error',
        'message' => __('Request failed.', 'syncmaster'),
        'code' => 0,
        'body' => '',
    );

    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result['code'] = $code;
        $result['body'] = substr($body, 0, 500);
        if ($code >= 200 && $code < 300) {
            $result['status'] = 'success';
            $result['message'] = __('API request succeeded.', 'syncmaster');
        } else {
            $result['message'] = sprintf(__('API request returned status %d.', 'syncmaster'), $code);
        }
    } else {
        $result['message'] = $response->get_error_message();
    }

    set_transient('syncmaster_last_api_test', $result, MINUTE_IN_SECONDS * 10);

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_settings&api_tested=1'));
    exit;
}
