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
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('syncmaster_sync_now'); ?>
                <input type="hidden" name="action" value="syncmaster_sync_now">
                <button type="submit" class="button button-primary syncmaster-sync-now">
                    <?php echo esc_html__('Sync Now', 'syncmaster'); ?>
                </button>
            </form>
        </div>
        <?php if (!empty($_GET['synced'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html__('Sync completed. Check the Sync Logs for details.', 'syncmaster'); ?></p>
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
    $query = isset($_GET['ss_query']) ? sanitize_text_field(wp_unslash($_GET['ss_query'])) : '';
    $search_results = array();
    if ($query !== '') {
        $search_results = syncmaster_ss_search($query);
    }
    $monitored = syncmaster_get_monitored_products();
    $color_selections = syncmaster_get_color_selections();
    $margin_settings = syncmaster_get_margin_settings();

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
    <section class="syncmaster-card">
        <h2><?php echo esc_html__('Search Products', 'syncmaster'); ?></h2>
        <form method="get" class="syncmaster-search">
            <input type="hidden" name="page" value="syncmaster_products">
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
            <ul class="syncmaster-monitored">
                <?php foreach ($monitored as $item) : ?>
                    <?php $style = syncmaster_get_style_summary($item['sku']); ?>
                    <?php $colors = syncmaster_get_style_colors($style['title']); ?>
                    <?php $has_color_selection = array_key_exists($item['sku'], $color_selections); ?>
                    <?php $selected_colors = $color_selections[$item['sku']] ?? array(); ?>
                    <?php $margin_percent = $margin_settings[$item['sku']] ?? 50; ?>
                    <?php $panel_id = 'syncmaster-colors-' . esc_attr($item['sku']); ?>
                    <li class="syncmaster-monitored-item">
                        <div class="syncmaster-monitored-header">
                            <div class="syncmaster-monitored-info">
                                <strong><?php echo esc_html($style['title']); ?></strong>
                                <span class="syncmaster-muted">
                                    <?php echo esc_html(sprintf(__('BaseCategory: %s', 'syncmaster'), $style['baseCategory'])); ?>
                                </span>
                                <button class="button-link syncmaster-toggle-colors" type="button" data-target="<?php echo esc_attr($panel_id); ?>">
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
                        <div class="syncmaster-color-panel" id="<?php echo esc_attr($panel_id); ?>">
                            <?php if (empty($colors)) : ?>
                                <p class="syncmaster-muted"><?php echo esc_html__('No color data found.', 'syncmaster'); ?></p>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('syncmaster_save_colors'); ?>
                                    <input type="hidden" name="action" value="syncmaster_save_colors">
                                    <input type="hidden" name="sku" value="<?php echo esc_attr($item['sku']); ?>">
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
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
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
                    <li class="syncmaster-log syncmaster-log-<?php echo esc_attr($log['level']); ?>">
                        <div>
                            <strong><?php echo esc_html($log['message']); ?></strong>
                            <div class="syncmaster-muted">
                                <?php echo esc_html($log['log_time']); ?> · <?php echo esc_html(strtoupper($log['level'])); ?>
                            </div>
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
