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

function syncmaster_reschedule_cron() {
    wp_clear_scheduled_hook('syncmaster_cron_sync');
    syncmaster_schedule_cron();
}

function syncmaster_handle_sync_now() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'syncmaster'));
    }

    check_admin_referer('syncmaster_sync_now');

    syncmaster_write_log('info', __('Manual sync triggered.', 'syncmaster'), 0, 0, array('source' => 'manual'));
    syncmaster_run_sync_placeholder();

    wp_safe_redirect(admin_url('admin.php?page=syncmaster_dashboard&synced=1'));
    exit;
}

function syncmaster_run_scheduled_sync() {
    syncmaster_write_log('info', __('Scheduled sync triggered.', 'syncmaster'), 0, 0, array('source' => 'cron'));
    syncmaster_run_sync_placeholder();
}

function syncmaster_run_sync_placeholder() {
    $results = syncmaster_sync_monitored_products();
    syncmaster_write_log(
        $results['status'],
        $results['message'],
        $results['success'],
        $results['fail'],
        array(
            'duration' => rand(10, 40),
            'monitored' => $results['monitored'],
            'created' => $results['created'],
            'updated' => $results['updated'],
        )
    );
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

function syncmaster_resolve_color_term_ids($colors, $selected_colors = array()) {
    $taxonomy = syncmaster_get_color_taxonomy();
    if (!taxonomy_exists($taxonomy)) {
        return array();
    }

    $candidate_names = array();
    if (!empty($selected_colors)) {
        foreach ($selected_colors as $color_name) {
            $color_name = sanitize_text_field($color_name);
            if ($color_name !== '') {
                $candidate_names[] = $color_name;
            }
        }
    } else {
        foreach ($colors as $color) {
            $name = is_array($color) ? ($color['colorName'] ?? '') : $color;
            $name = is_string($name) ? sanitize_text_field($name) : '';
            if ($name !== '') {
                $candidate_names[] = $name;
            }
        }
    }

    $candidate_names = array_values(array_unique($candidate_names));
    if (empty($candidate_names)) {
        return array();
    }

    $term_ids = array();
    foreach ($candidate_names as $color_name) {
        $slug = sanitize_title($color_name);
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term) {
            $term = get_term_by('name', $color_name, $taxonomy);
        }
        if (!$term) {
            $term = wp_insert_term($color_name, $taxonomy, array('slug' => $slug));
        }
        if (!is_wp_error($term) && $term) {
            $term_ids[] = is_array($term) ? (int) $term['term_id'] : (int) $term->term_id;
        }
    }

    return array_values(array_unique(array_filter($term_ids)));
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

function syncmaster_set_product_category($product_id, $category_name) {
    if ($category_name === '') {
        return;
    }

    $term = term_exists($category_name, 'product_cat');
    if (!$term) {
        $term = wp_insert_term($category_name, 'product_cat');
    }
    if (is_wp_error($term)) {
        return;
    }
    $term_id = is_array($term) ? $term['term_id'] : $term;
    wp_set_object_terms($product_id, array((int) $term_id), 'product_cat', false);
}

function syncmaster_set_featured_image($product_id, $image_url) {
    if ($image_url === '') {
        return;
    }

    if (strpos($image_url, 'http') !== 0) {
        $image_url = 'https://cdn.ssactivewear.com/' . ltrim($image_url, '/');
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

function syncmaster_sync_monitored_products() {
    $monitored = syncmaster_get_monitored_products();
    $monitored_count = count($monitored);
    $color_selections = syncmaster_get_color_selections();
    $color_taxonomy = syncmaster_get_color_taxonomy();

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

    foreach ($monitored as $item) {
        $sku = $item['sku'];
        $api_item = syncmaster_fetch_ss_product($sku);
        if (empty($api_item)) {
            $fail++;
            continue;
        }
        $mapped = syncmaster_map_product_data($api_item);
        $product_id = wc_get_product_id_by_sku($sku);
        $style_title = trim(($api_item['brandName'] ?? '') . ' ' . ($api_item['styleName'] ?? ''));
        $colors = $style_title !== '' ? syncmaster_get_style_colors($style_title) : array();
        $selected_colors = $color_selections[$sku] ?? array();
        $color_term_ids = syncmaster_resolve_color_term_ids($colors, $selected_colors);
        $is_variable = count($colors) > 1;
        if ($product_id) {
            $product = $is_variable ? new WC_Product_Variable($product_id) : new WC_Product_Simple($product_id);
        } else {
            $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
        }

        if (!$product) {
            $fail++;
            continue;
        }

        $product->set_name($mapped['name'] !== '' ? $mapped['name'] : sprintf(__('Synced SKU %s', 'syncmaster'), $sku));
        $desired_sku = $mapped['sku'] !== '' ? $mapped['sku'] : $sku;
        $existing_id = wc_get_product_id_by_sku($desired_sku);
        if ($existing_id && $existing_id !== $product_id) {
            $fail++;
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
        $saved_id = $product->save();

        if ($saved_id) {
            syncmaster_assign_color_terms($saved_id, $color_term_ids, $color_taxonomy);
            syncmaster_apply_product_brand($saved_id, $product, $mapped['brand']);
            syncmaster_set_product_category($saved_id, $mapped['category']);
            if ($mapped['image'] !== '') {
                syncmaster_set_featured_image($saved_id, $mapped['image']);
            }
        }

        if ($saved_id) {
            if ($product_id) {
                $updated++;
            } else {
                $created++;
            }
        } else {
            $fail++;
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
    return $wpdb->get_results("SELECT log_time, level, message, success_count, fail_count FROM {$table} ORDER BY log_time DESC LIMIT 50", ARRAY_A);
}

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

function syncmaster_get_monitored_products() {
    global $wpdb;
    $table = $wpdb->prefix . SYNCMASTER_PRODUCTS_TABLE;
    return $wpdb->get_results("SELECT sku FROM {$table} ORDER BY created_at DESC", ARRAY_A);
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
        $data = json_decode($body, true);
        if ($status === 200 && is_array($data)) {
            foreach ($data as $item) {
                $color_code = $item['colorCode'] ?? '';
                if ($color_code === '') {
                    continue;
                }
                if (!isset($colors[$color_code])) {
                    $colors[$color_code] = array(
                        'colorCode' => $color_code,
                        'colorName' => $item['colorName'] ?? '',
                        'colorFrontImage' => $item['colorFrontImage'] ?? '',
                        'sizeNames' => array(),
                    );
                }
                $size_name = sanitize_text_field($item['sizeName'] ?? '');
                if ($size_name !== '') {
                    $colors[$color_code]['sizeNames'][] = $size_name;
                }
            }
        }
    }

    foreach ($colors as $color_code => $color_data) {
        $size_names = $color_data['sizeNames'] ?? array();
        if (!empty($size_names)) {
            $colors[$color_code]['sizeNames'] = array_values(array_unique($size_names));
        }
    }

    $colors = array_values($colors);
    set_transient($cache_key, $colors, MINUTE_IN_SECONDS * 10);
    return $colors;
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
