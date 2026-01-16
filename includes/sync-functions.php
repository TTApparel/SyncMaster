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

function syncmaster_get_size_taxonomy() {
    $taxonomy = function_exists('wc_attribute_taxonomy_name')
        ? wc_attribute_taxonomy_name('size')
        : 'pa_size';
    return $taxonomy;
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
        $slug = sanitize_title($name);
        $term = get_term_by('slug', $slug, $taxonomy);
        if (!$term) {
            $term = get_term_by('name', $name, $taxonomy);
        }
        if (!$term) {
            $term = wp_insert_term($name, $taxonomy, array('slug' => $slug));
        }
        if (!is_wp_error($term) && $term) {
            $term_ids[] = is_array($term) ? (int) $term['term_id'] : (int) $term->term_id;
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

    $slug = sanitize_title($name);
    $term = get_term_by('slug', $slug, $taxonomy);
    if (!$term) {
        $term = get_term_by('name', $name, $taxonomy);
    }
    if (!$term) {
        $term = wp_insert_term($name, $taxonomy, array('slug' => $slug));
    }
    if (is_wp_error($term) || !$term) {
        return '';
    }

    if (is_array($term)) {
        $term_obj = get_term_by('id', (int) $term['term_id'], $taxonomy);
        return $term_obj ? $term_obj->slug : $slug;
    }

    return $term->slug;
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
    }

    if (empty($candidate_names)) {
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

    return syncmaster_resolve_attribute_term_ids($candidate_names, $taxonomy);
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
        $image_url = $color['colorFrontImage'] ?? '';
        $image_url = trim((string) $image_url);
        if ($image_url === '') {
            continue;
        }
        if (strpos($image_url, 'http') !== 0) {
            $image_url = 'https://cdn.ssactivewear.com/' . ltrim($image_url, '/');
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

function syncmaster_round_up_price($price, $increment = 0.25) {
    $increment = (float) $increment;
    if ($increment <= 0) {
        return $price;
    }

    return ceil($price / $increment) * $increment;
}

function syncmaster_sync_variations($product_id, $base_sku, $color_size_map, $color_size_sku_map, $color_size_qty_map, $color_size_price_map, $color_size_image_map, $color_taxonomy, $size_taxonomy, $margin_percent) {
    if (empty($color_size_map)) {
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    foreach ($product->get_children() as $child_id) {
        wp_delete_post($child_id, true);
    }

    foreach ($color_size_map as $color_name => $size_names) {
        $color_slug = syncmaster_get_attribute_term_slug($color_name, $color_taxonomy);
        if ($color_slug === '') {
            continue;
        }
        foreach ($size_names as $size_name) {
            $size_slug = syncmaster_get_attribute_term_slug($size_name, $size_taxonomy);
            if ($size_slug === '') {
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
                    $variation->set_sku(implode('-', $variation_sku_parts));
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
            $variation->save();
        }
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
        $color_size_map = syncmaster_collect_color_size_map($colors, $selected_colors);
        $color_size_sku_map = syncmaster_collect_color_size_sku_map($colors, $selected_colors);
        $color_size_qty_map = syncmaster_collect_color_size_qty_map($colors, $selected_colors);
        $color_size_price_map = syncmaster_collect_color_size_price_map($colors, $selected_colors);
        $size_names = syncmaster_collect_size_names($colors, $selected_colors);
        $size_term_ids = syncmaster_resolve_attribute_term_ids($size_names, $size_taxonomy);
        $is_variable = count($color_term_ids) > 1 || count($size_term_ids) > 1;
        $margin_percent = syncmaster_get_margin_percent_for_sku($sku, 50);
        $color_size_image_map = syncmaster_collect_color_size_image_map($colors, $selected_colors);
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
        syncmaster_apply_size_attributes($product, $size_term_ids, $size_taxonomy, $is_variable);
        $saved_id = $product->save();

        if ($saved_id) {
            syncmaster_assign_color_terms($saved_id, $color_term_ids, $color_taxonomy);
            syncmaster_assign_size_terms($saved_id, $size_term_ids, $size_taxonomy);
            syncmaster_apply_product_brand($saved_id, $product, $mapped['brand']);
            syncmaster_set_product_category($saved_id, $mapped['category']);
            if ($mapped['image'] !== '') {
                syncmaster_set_featured_image($saved_id, $mapped['image']);
            }
            if ($is_variable) {
                syncmaster_sync_variations(
                    $saved_id,
                    $desired_sku,
                    $color_size_map,
                    $color_size_sku_map,
                    $color_size_qty_map,
                    $color_size_price_map,
                    $color_size_image_map,
                    $color_taxonomy,
                    $size_taxonomy,
                    $margin_percent
                );
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
                        'colorName' => syncmaster_extract_scalar($item['colorName'] ?? ($item['ColorName'] ?? '')),
                        'colorFrontImage' => syncmaster_extract_scalar($item['colorFrontImage'] ?? ($item['ColorFrontImage'] ?? '')),
                        'sizeNames' => array(),
                        'sizeSkus' => array(),
                        'sizePrices' => array(),
                        'sizeQtys' => array(),
                    );
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
