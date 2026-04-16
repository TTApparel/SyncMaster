<?php

if (!defined('ABSPATH')) {
    exit;
}

function syncmaster_register_admin_menu() {
    add_menu_page(
        __('SyncMaster', 'syncmaster'),
        __('SyncMaster', 'syncmaster'),
        'manage_options',
        'syncmaster_dashboard',
        'syncmaster_render_dashboard',
        'dashicons-update',
        56
    );

    add_submenu_page(
        'syncmaster_dashboard',
        __('Dashboard', 'syncmaster'),
        __('Dashboard', 'syncmaster'),
        'manage_options',
        'syncmaster_dashboard',
        'syncmaster_render_dashboard'
    );

    add_submenu_page(
        'syncmaster_dashboard',
        __('Products', 'syncmaster'),
        __('Products', 'syncmaster'),
        'manage_options',
        'syncmaster_products',
        'syncmaster_render_products'
    );

    add_submenu_page(
        'syncmaster_dashboard',
        __('Sync Logs', 'syncmaster'),
        __('Sync Logs', 'syncmaster'),
        'manage_options',
        'syncmaster_logs',
        'syncmaster_render_logs'
    );

    add_submenu_page(
        'syncmaster_dashboard',
        __('Settings', 'syncmaster'),
        __('Settings', 'syncmaster'),
        'manage_options',
        'syncmaster_settings',
        'syncmaster_render_settings'
    );
}

function syncmaster_render_shell($active = 'dashboard', $content = '') {
    $pages = array(
        'dashboard' => array('label' => __('Dashboard', 'syncmaster'), 'slug' => 'syncmaster_dashboard'),
        'products' => array('label' => __('Products', 'syncmaster'), 'slug' => 'syncmaster_products'),
        'logs' => array('label' => __('Sync Logs', 'syncmaster'), 'slug' => 'syncmaster_logs'),
        'settings' => array('label' => __('Settings', 'syncmaster'), 'slug' => 'syncmaster_settings'),
    );
    ?>
    <div class="wrap syncmaster-admin">
        <div class="syncmaster-header">
            <h1><?php echo esc_html__('SyncMaster', 'syncmaster'); ?></h1>
            <div class="syncmaster-sync-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="syncmaster-sync-selected-form">
                    <?php wp_nonce_field('syncmaster_sync_selected_products'); ?>
                    <input type="hidden" name="action" value="syncmaster_sync_selected_products">
                    <button type="submit" class="button button-primary syncmaster-sync-now">
                        <?php echo esc_html__('Sync Selected Products', 'syncmaster'); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('syncmaster_sync_inventory_variations'); ?>
                    <input type="hidden" name="action" value="syncmaster_sync_inventory_variations">
                    <button type="submit" class="button">
                        <?php echo esc_html__('ReSync Inventory/Variations', 'syncmaster'); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('syncmaster_sync_new_products'); ?>
                    <input type="hidden" name="action" value="syncmaster_sync_new_products">
                    <button type="submit" class="button">
                        <?php echo esc_html__('Sync New Products', 'syncmaster'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php if (!empty($_GET['synced'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('Sync completed. Check the Sync Logs for details.', 'syncmaster'); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($_GET['sync_queued'])) : ?>
            <div class="notice notice-info is-dismissible">
                <p><?php echo esc_html__('Sync queued in background. Refresh Sync Logs for progress.', 'syncmaster'); ?></p>
            </div>
        <?php endif; ?>
        <div class="syncmaster-layout">
            <aside class="syncmaster-sidebar">
                <nav>
                    <ul>
                        <?php foreach ($pages as $key => $page) : ?>
                            <li class="<?php echo $key === $active ? 'active' : ''; ?>">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page['slug'])); ?>">
                                    <?php echo esc_html($page['label']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </aside>
            <main class="syncmaster-content">
                <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </main>
        </div>
    </div>
    <?php
}

function syncmaster_render_dashboard() {
    ob_start();
    ?>
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('Overview', 'syncmaster'); ?></h2>
        <p><?php echo esc_html__('Welcome to SyncMaster. Use the sidebar to manage products, view sync logs, and configure settings.', 'syncmaster'); ?></p>
    </section>
    <div class="syncmaster-grid">
        <section class="syncmaster-card">
            <h3><?php echo esc_html__('Monitored Products', 'syncmaster'); ?></h3>
            <p class="syncmaster-metric"><?php echo esc_html(syncmaster_get_products_count()); ?></p>
        </section>
        <section class="syncmaster-card">
            <h3><?php echo esc_html__('Last Sync', 'syncmaster'); ?></h3>
            <p class="syncmaster-metric"><?php echo esc_html(syncmaster_get_last_sync_time()); ?></p>
        </section>
        <section class="syncmaster-card">
            <h3><?php echo esc_html__('Latest Status', 'syncmaster'); ?></h3>
            <p class="syncmaster-metric"><?php echo esc_html(syncmaster_get_last_sync_status()); ?></p>
        </section>
    </div>
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('API Code Examples', 'syncmaster'); ?></h2>
        <p><?php echo esc_html__('Use these snippets as a starting point for calling the SSActivewear API.', 'syncmaster'); ?></p>
        <div class="syncmaster-code-grid">
            <div class="syncmaster-code">
                <h4><?php echo esc_html__('C# - Download', 'syncmaster'); ?></h4>
                <pre><code>SSActivewear.API.Request Request = new SSActivewear.API.Request();
Request.GET_Categories();</code></pre>
            </div>
            <div class="syncmaster-code">
                <h4><?php echo esc_html__('VB.net - Download', 'syncmaster'); ?></h4>
                <pre><code>Dim Request As New SSActivewear.API.Request
Request.GET_Categories()</code></pre>
            </div>
        </div>
    </section>
    <?php
    $content = ob_get_clean();
    syncmaster_render_shell('dashboard', $content);
}

function syncmaster_render_products() {
    $active_tab = isset($_GET['products_tab']) ? sanitize_key(wp_unslash($_GET['products_tab'])) : 'products';
    if (!in_array($active_tab, array('products', 'categories'), true)) {
        $active_tab = 'products';
    }

    $query = isset($_GET['ss_query']) ? sanitize_text_field(wp_unslash($_GET['ss_query'])) : '';
    $search_results = array();
    if ($query !== '' && $active_tab === 'products') {
        $search_results = syncmaster_ss_search($query);
    }

    $monitored_page = isset($_GET['monitored_page']) ? max(1, (int) $_GET['monitored_page']) : 1;
    $monitored_groups_per_page = 10;
    $monitored_total_pages = 1;
    $monitored = syncmaster_get_monitored_products();
    $margin_settings = syncmaster_get_margin_settings();
    $selected_category_style_map = syncmaster_get_selected_category_style_map();
    $category_index = syncmaster_fetch_ss_categories();
    $category_sync_rules = syncmaster_get_category_sync_rules();
    $woo_categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ));

    ob_start();
    ?>
    <?php if (!empty($_GET['colors_saved'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html__('Color preferences updated.', 'syncmaster'); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['margin_saved'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html__('Margin updated.', 'syncmaster'); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['categories_saved'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html__('Category sync rules updated.', 'syncmaster'); ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['removed']) && (int) $_GET['removed'] > 0) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html(sprintf(__('Removed %d monitored products.', 'syncmaster'), (int) $_GET['removed'])); ?></p>
        </div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper syncmaster-products-tabs">
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'syncmaster_products', 'products_tab' => 'products'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Products', 'syncmaster'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'syncmaster_products', 'products_tab' => 'categories'), admin_url('admin.php'))); ?>" class="nav-tab <?php echo $active_tab === 'categories' ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Categories', 'syncmaster'); ?>
        </a>
    </h2>

    <?php if ($active_tab === 'categories') : ?>
        <section class="syncmaster-card">
            <h2><?php echo esc_html__('Category Sync Rules', 'syncmaster'); ?></h2>
            <p class="syncmaster-muted">
                <?php echo esc_html__('Select S&S categories to sync in bulk. Each selected category can map to an existing WooCommerce category or create a new one during sync.', 'syncmaster'); ?>
            </p>
            <?php if (empty($category_index)) : ?>
                <p><?php echo esc_html__('No categories were returned from the S&S Categories API endpoint.', 'syncmaster'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="syncmaster-category-sync-form">
                    <?php wp_nonce_field('syncmaster_save_categories'); ?>
                    <input type="hidden" name="action" value="syncmaster_save_categories">
                    <p class="syncmaster-category-actions">
                        <button type="button" class="button syncmaster-select-all-categories"><?php echo esc_html__('Enable All', 'syncmaster'); ?></button>
                        <button type="button" class="button syncmaster-clear-all-categories"><?php echo esc_html__('Disable All', 'syncmaster'); ?></button>
                    </p>
                    <table class="widefat striped syncmaster-category-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Sync', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('S&S Category', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('Category ID', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('Styles', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('Destination Type', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('Existing Woo Category', 'syncmaster'); ?></th>
                                <th><?php echo esc_html__('New Woo Category Name', 'syncmaster'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($category_index as $category) : ?>
                                <?php
                                $category_name = sanitize_text_field($category['name'] ?? '');
                                $category_id = sanitize_text_field($category['id'] ?? '');
                                if ($category_name === '' || $category_id === '') {
                                    continue;
                                }
                                $style_count = (int) ($category['style_count'] ?? 0);
                                $saved_rule = $category_sync_rules[$category_id] ?? array();
                                $is_enabled = !empty($saved_rule['enabled']);
                                $mode = $saved_rule['mode'] ?? 'create';
                                $target_term_id = (int) ($saved_rule['target_term_id'] ?? 0);
                                $new_name = $saved_rule['new_name'] ?? $category_name;
                                $row_key = md5($category_id);
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="category_rows[]" value="<?php echo esc_attr($row_key); ?>">
                                        <input type="hidden" name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][source_name]" value="<?php echo esc_attr($category_name); ?>">
                                        <input type="hidden" name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][source_id]" value="<?php echo esc_attr($category_id); ?>">
                                        <label>
                                            <input type="checkbox" name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][enabled]" value="1" <?php checked($is_enabled); ?>>
                                            <?php echo esc_html__('Enable', 'syncmaster'); ?>
                                        </label>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($category_name); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html($category_id); ?></code>
                                    </td>
                                    <td>
                                        <?php echo esc_html($style_count); ?>
                                    </td>
                                    <td>
                                        <select name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][mode]">
                                            <option value="create" <?php selected($mode, 'create'); ?>><?php echo esc_html__('Create/Use by Name', 'syncmaster'); ?></option>
                                            <option value="existing" <?php selected($mode, 'existing'); ?>><?php echo esc_html__('Match Existing', 'syncmaster'); ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][target_term_id]">
                                            <option value="0"><?php echo esc_html__('— Select Category —', 'syncmaster'); ?></option>
                                            <?php if (!is_wp_error($woo_categories) && !empty($woo_categories)) : ?>
                                                <?php foreach ($woo_categories as $woo_category) : ?>
                                                    <option value="<?php echo esc_attr($woo_category->term_id); ?>" <?php selected($target_term_id, (int) $woo_category->term_id); ?>>
                                                        <?php echo esc_html($woo_category->name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="syncmaster_categories[<?php echo esc_attr($row_key); ?>][new_name]" value="<?php echo esc_attr($new_name); ?>" placeholder="<?php echo esc_attr__('Leave blank to use S&S name', 'syncmaster'); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Save Category Rules', 'syncmaster'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </section>
    <?php else : ?>
        <section class="syncmaster-card">
            <h2><?php echo esc_html__('Search Products', 'syncmaster'); ?></h2>
            <form method="get" class="syncmaster-search">
                <input type="hidden" name="page" value="syncmaster_products">
                <input type="hidden" name="products_tab" value="products">
                <input type="search" name="ss_query" value="<?php echo esc_attr($query); ?>" placeholder="<?php echo esc_attr__('Search by name or SKU', 'syncmaster'); ?>">
                <button type="submit" class="button"><?php echo esc_html__('Search', 'syncmaster'); ?></button>
            </form>
            <?php if ($query !== '') : ?>
                <div class="syncmaster-search-results">
                    <h3><?php echo esc_html__('Search Results', 'syncmaster'); ?></h3>
                    <?php if (empty($search_results)) : ?>
                        <p><?php echo esc_html__('No results found.', 'syncmaster'); ?></p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($search_results as $result) : ?>
                                <li class="syncmaster-result">
                                    <div>
                                        <strong><?php echo esc_html($result['name']); ?></strong>
                                        <span class="syncmaster-muted"><?php echo esc_html($result['sku']); ?></span>
                                    </div>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('syncmaster_add_sku'); ?>
                                        <input type="hidden" name="action" value="syncmaster_add_sku">
                                        <input type="hidden" name="sku" value="<?php echo esc_attr($result['sku']); ?>">
                                        <button type="submit" class="button button-primary">
                                            <?php echo esc_html__('Add to Monitored', 'syncmaster'); ?>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="syncmaster-card">
            <h2><?php echo esc_html__('Monitored Products', 'syncmaster'); ?></h2>
            <?php if (empty($monitored)) : ?>
                <p><?php echo esc_html__('No products monitored yet.', 'syncmaster'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="syncmaster-bulk-remove-form" id="syncmaster-bulk-remove-form">
                    <?php wp_nonce_field('syncmaster_bulk_remove_skus'); ?>
                    <input type="hidden" name="action" value="syncmaster_bulk_remove_skus">
                    <button type="submit" class="button button-secondary"><?php echo esc_html__('Remove Checked Products', 'syncmaster'); ?></button>
                </form>
                <?php if ($monitored_total_pages > 1) : ?>
                    <div class="syncmaster-pagination">
                        <span class="syncmaster-muted">
                            <?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'syncmaster'), $monitored_page, $monitored_total_pages)); ?>
                        </span>
                        <div class="syncmaster-pagination-links">
                            <?php if ($monitored_page > 1) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'syncmaster_products', 'products_tab' => 'products', 'monitored_page' => $monitored_page - 1), admin_url('admin.php'))); ?>">
                                    <?php echo esc_html__('Previous', 'syncmaster'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($monitored_page < $monitored_total_pages) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'syncmaster_products', 'products_tab' => 'products', 'monitored_page' => $monitored_page + 1), admin_url('admin.php'))); ?>">
                                    <?php echo esc_html__('Next', 'syncmaster'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php
                $grouped_monitored = array();
                foreach ($monitored as $item) {
                    $sku = sanitize_text_field($item['sku'] ?? '');
                    if ($sku === '') {
                        continue;
                    }
                    $product_id = function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
                    $product = $product_id ? wc_get_product($product_id) : null;
                    $item_title = ($product && method_exists($product, 'get_name')) ? $product->get_name() : $sku;

                    $mapped_names = array();
                    if ($product_id) {
                        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
                        if (!is_wp_error($terms) && is_array($terms)) {
                            $mapped_names = array_values(array_filter(array_map('sanitize_text_field', $terms)));
                        }
                    }
                    if (empty($mapped_names)) {
                        $mapped_names = syncmaster_get_mapped_product_category_names(
                            '',
                            $selected_category_style_map[$sku] ?? array()
                        );
                    }
                    $primary_group = !empty($mapped_names) ? (string) reset($mapped_names) : __('Unmapped Categories', 'syncmaster');
                    if (!isset($grouped_monitored[$primary_group])) {
                        $grouped_monitored[$primary_group] = array();
                    }
                    $grouped_monitored[$primary_group][] = array(
                        'item' => $item,
                        'item_title' => $item_title,
                        'mapped_names' => $mapped_names,
                    );
                }
                ksort($grouped_monitored, SORT_NATURAL | SORT_FLAG_CASE);
                $monitored_total_groups = count($grouped_monitored);
                $monitored_total_pages = max(1, (int) ceil($monitored_total_groups / $monitored_groups_per_page));
                if ($monitored_page > $monitored_total_pages) {
                    $monitored_page = $monitored_total_pages;
                }
                $monitored_group_offset = ($monitored_page - 1) * $monitored_groups_per_page;
                $grouped_monitored = array_slice($grouped_monitored, $monitored_group_offset, $monitored_groups_per_page, true);
                ?>
                <?php foreach ($grouped_monitored as $group_name => $group_items) : ?>
                    <?php $group_id = 'syncmaster-group-' . md5($group_name); ?>
                    <details class="syncmaster-monitored-group" open>
                        <summary>
                            <span class="syncmaster-monitored-group-title">
                                <label>
                                    <input type="checkbox" class="syncmaster-group-toggle" data-target="<?php echo esc_attr($group_id); ?>">
                                    <?php echo esc_html__('Select Group', 'syncmaster'); ?>
                                </label>
                                <strong><?php echo esc_html($group_name); ?></strong>
                                <span class="syncmaster-muted"><?php echo esc_html(sprintf(__('Products: %d', 'syncmaster'), count($group_items))); ?></span>
                            </span>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="syncmaster-group-remove-form">
                                <?php wp_nonce_field('syncmaster_bulk_remove_skus'); ?>
                                <input type="hidden" name="action" value="syncmaster_bulk_remove_skus">
                                <?php foreach ($group_items as $group_item_hidden) : ?>
                                    <input type="hidden" name="skus[]" value="<?php echo esc_attr($group_item_hidden['item']['sku'] ?? ''); ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="button button-link-delete syncmaster-remove"><?php echo esc_html__('Remove Group', 'syncmaster'); ?></button>
                            </form>
                        </summary>
                        <ul class="syncmaster-monitored" id="<?php echo esc_attr($group_id); ?>">
                            <?php foreach ($group_items as $group_item) : ?>
                                <?php $item = $group_item['item']; ?>
                                <?php $item_title = $group_item['item_title']; ?>
                                <?php $mapped_names = $group_item['mapped_names'] ?? array(); ?>
                                <?php $margin_percent = syncmaster_get_margin_percent_for_sku($item['sku'], 50); ?>
                                <?php $panel_id = 'syncmaster-colors-' . esc_attr($item['sku']); ?>
                                <li class="syncmaster-monitored-item">
                                    <div class="syncmaster-monitored-header">
                                        <div class="syncmaster-monitored-info">
                                            <label class="syncmaster-item-toggle">
                                                <input type="checkbox" class="syncmaster-bulk-sku" value="<?php echo esc_attr($item['sku']); ?>">
                                                <?php echo esc_html__('Select', 'syncmaster'); ?>
                                            </label>
                                            <strong><?php echo esc_html($item_title); ?></strong>
                                            <span class="syncmaster-muted">
                                                <?php
                                                $woo_category_label = !empty($mapped_names)
                                                    ? implode(', ', array_map('sanitize_text_field', $mapped_names))
                                                    : __('Unmapped', 'syncmaster');
                                                echo esc_html(sprintf(__('Woo Categories: %s', 'syncmaster'), $woo_category_label));
                                                ?>
                                            </span>
                                            <button class="button-link syncmaster-toggle-colors" type="button" data-target="<?php echo esc_attr($panel_id); ?>" data-sku="<?php echo esc_attr($item['sku']); ?>">
                                                <?php echo esc_html__('View Colors', 'syncmaster'); ?>
                                            </button>
                                        </div>
                                        <div class="syncmaster-monitored-actions">
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('syncmaster_remove_sku'); ?>
                                                <input type="hidden" name="action" value="syncmaster_remove_sku">
                                                <input type="hidden" name="sku" value="<?php echo esc_attr($item['sku']); ?>">
                                                <button type="submit" class="button button-link-delete syncmaster-remove">
                                                    <?php echo esc_html__('Remove', 'syncmaster'); ?>
                                                </button>
                                            </form>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="syncmaster-margin-form">
                                                <?php wp_nonce_field('syncmaster_save_margin'); ?>
                                                <input type="hidden" name="action" value="syncmaster_save_margin">
                                                <input type="hidden" name="sku" value="<?php echo esc_attr($item['sku']); ?>">
                                                <label>
                                                    <span class="syncmaster-muted"><?php echo esc_html__('Margin %', 'syncmaster'); ?></span>
                                                    <input type="number" name="margin_percent" min="0.01" step="0.01" value="<?php echo esc_attr($margin_percent); ?>">
                                                </label>
                                                <button type="submit" class="button">
                                                    <?php echo esc_html__('Save Margin', 'syncmaster'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="syncmaster-color-panel" id="<?php echo esc_attr($panel_id); ?>" data-loaded="0">
                                        <p class="syncmaster-muted"><?php echo esc_html__('Loading colors…', 'syncmaster'); ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
                <?php if ($monitored_total_pages > 1) : ?>
                    <div class="syncmaster-pagination">
                        <span class="syncmaster-muted">
                            <?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'syncmaster'), $monitored_page, $monitored_total_pages)); ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php
    $content = ob_get_clean();
    syncmaster_render_shell('products', $content);
}

function syncmaster_render_logs() {
    $logs = syncmaster_get_logs();

    ob_start();
    ?>
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('Sync Logs', 'syncmaster'); ?></h2>
        <?php if (empty($logs)) : ?>
            <p><?php echo esc_html__('No logs recorded yet.', 'syncmaster'); ?></p>
        <?php else : ?>
            <ul class="syncmaster-logs">
                <?php foreach ($logs as $log) : ?>
                    <?php
                    $context = array();
                    $raw_context_json = '';
                    if (!empty($log['context_json'])) {
                        $raw_context_json = (string) $log['context_json'];
                        $decoded = json_decode($raw_context_json, true);
                        if (is_array($decoded)) {
                            $context = $decoded;
                        }
                    }
                    ?>
                    <li class="syncmaster-log syncmaster-log-<?php echo esc_attr($log['level']); ?>">
                        <div>
                            <strong><?php echo esc_html($log['message']); ?></strong>
                            <div class="syncmaster-muted">
                                <?php echo esc_html($log['log_time']); ?> · <?php echo esc_html(strtoupper($log['level'])); ?>
                            </div>
                            <?php if (!empty($context) || $raw_context_json !== '') : ?>
                                <details class="syncmaster-log-context">
                                    <summary><?php echo esc_html__('Details', 'syncmaster'); ?></summary>
                                    <?php if (!empty($context)) : ?>
                                        <pre><?php echo esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                    <?php else : ?>
                                        <pre><?php echo esc_html($raw_context_json); ?></pre>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </div>
                        <div class="syncmaster-log-counts">
                            <span><?php echo esc_html(sprintf(__('Success: %d', 'syncmaster'), $log['success_count'])); ?></span>
                            <span><?php echo esc_html(sprintf(__('Fail: %d', 'syncmaster'), $log['fail_count'])); ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
    $content = ob_get_clean();
    syncmaster_render_shell('logs', $content);
}

function syncmaster_render_settings() {
    $options = syncmaster_get_settings();
    $api_test = get_transient('syncmaster_last_api_test');

    ob_start();
    ?>
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('Settings', 'syncmaster'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="syncmaster-settings">
            <?php wp_nonce_field('syncmaster_save_settings'); ?>
            <input type="hidden" name="action" value="syncmaster_save_settings">

            <div class="syncmaster-field">
                <label for="ss_username"><?php echo esc_html__('SS Username', 'syncmaster'); ?></label>
                <input type="text" id="ss_username" name="ss_username" value="<?php echo esc_attr($options['ss_username']); ?>">
            </div>
            <div class="syncmaster-field">
                <label for="ss_password"><?php echo esc_html__('SS Password', 'syncmaster'); ?></label>
                <input type="password" id="ss_password" name="ss_password" value="<?php echo esc_attr($options['ss_password']); ?>">
            </div>
            <div class="syncmaster-field">
                <label for="sync_interval_minutes"><?php echo esc_html__('Sync Interval (minutes)', 'syncmaster'); ?></label>
                <input type="number" id="sync_interval_minutes" name="sync_interval_minutes" min="5" value="<?php echo esc_attr($options['sync_interval_minutes']); ?>">
            </div>
            <button type="submit" class="button button-primary">
                <?php echo esc_html__('Save Settings', 'syncmaster'); ?>
            </button>
        </form>
    </section>
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('Test S&S API', 'syncmaster'); ?></h2>
        <p><?php echo esc_html__('Run a server-side request to verify connectivity using the saved SS credentials.', 'syncmaster'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('syncmaster_test_api'); ?>
            <input type="hidden" name="action" value="syncmaster_test_api">
            <button type="submit" class="button">
                <?php echo esc_html__('Run Test', 'syncmaster'); ?>
            </button>
        </form>
        <?php if (!empty($api_test)) : ?>
            <div class="syncmaster-test-result syncmaster-log-<?php echo esc_attr($api_test['status']); ?>">
                <strong><?php echo esc_html($api_test['message']); ?></strong>
                <div class="syncmaster-muted">
                    <?php echo esc_html(sprintf(__('Endpoint: %s', 'syncmaster'), $api_test['endpoint'])); ?>
                </div>
                <div class="syncmaster-muted">
                    <?php echo esc_html(sprintf(__('Status code: %d', 'syncmaster'), $api_test['code'])); ?>
                </div>
                <?php if (!empty($api_test['body'])) : ?>
                    <pre class="syncmaster-test-body"><?php echo esc_html($api_test['body']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    $content = ob_get_clean();
    syncmaster_render_shell('settings', $content);
}
