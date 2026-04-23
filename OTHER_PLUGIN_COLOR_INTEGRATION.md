# SyncMaster Color Data Integration Guide (for Other Plugins)

Use this as a quick reference when building a separate plugin that needs to read color values for filtering, swatches, or image display.

## Core Principle

Treat SyncMaster color data as **standard WooCommerce attribute taxonomy data first**, and use SyncMaster-specific metadata only as optional enhancement.

- Primary source of truth for filtering: WooCommerce color attribute taxonomy (typically `pa_color`)
- Optional enrichment: SyncMaster and swatch metadata

## Data Sources to Use

### 1) Primary: WooCommerce color taxonomy terms

SyncMaster assigns color terms to products/variations as regular WooCommerce attributes.

- Taxonomy name:
  - Preferred: `wc_attribute_taxonomy_name('color')`
  - Fallback: `pa_color`
- Recommended APIs:
  - `taxonomy_exists($taxonomy)`
  - `get_terms(...)`
  - `wp_get_object_terms(...)`
  - `WP_Query` with `tax_query`

### 2) Optional: term swatch image metadata

If swatch media exists, term meta may contain:

- Key: `smart-swatches-framework--src`
- Value: URL to swatch image

### 3) Optional: variation-level external image

SyncMaster stores per-variation external image URL in post meta:

- Key: `_syncmaster_external_image_url`
- Use case: product page variation image override, admin variation thumbs

### 4) Optional: per-color front/back/side map on parent product

SyncMaster stores color view images on the parent product post meta:

- Key: `tta_threaddesk_product_postbox`
- Shape: `['colors'][<color-slug>]` with keys such as:
  - `front_image`
  - `back_image`
  - `side_image`
  - fallback URLs

## Recommended Integration Pattern

1. Resolve color taxonomy dynamically.
2. Build filter UI from terms in that taxonomy.
3. Query products with `tax_query` against color term slugs/IDs.
4. Optionally attach swatch/image metadata if present.
5. Treat missing metadata as normal; degrade gracefully.

## Drop-in Helper Class

Place this in your plugin (example path: `includes/class-color-filter-bridge.php`).

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class MyPlugin_Color_Filter_Bridge {
    /**
     * Resolve WooCommerce color taxonomy safely.
     */
    public static function get_color_taxonomy() {
        if (function_exists('wc_attribute_taxonomy_name')) {
            return wc_attribute_taxonomy_name('color'); // usually pa_color
        }
        return 'pa_color';
    }

    /**
     * Get all color terms used for filtering.
     * Returns array rows with term_id/name/slug/count/swatch.
     */
    public static function get_filterable_colors($hide_empty = true) {
        $taxonomy = self::get_color_taxonomy();

        if (!taxonomy_exists($taxonomy)) {
            return array();
        }

        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => (bool) $hide_empty,
        ));

        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $rows = array();
        foreach ($terms as $term) {
            $swatch = get_term_meta($term->term_id, 'smart-swatches-framework--src', true);
            $rows[] = array(
                'term_id' => (int) $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'count'   => (int) $term->count,
                'swatch'  => is_string($swatch) ? esc_url_raw($swatch) : '',
            );
        }

        return $rows;
    }

    /**
     * Build tax_query chunk for product filtering by color slugs.
     */
    public static function build_color_tax_query(array $color_slugs) {
        $taxonomy = self::get_color_taxonomy();

        $color_slugs = array_values(array_filter(array_map('sanitize_title', $color_slugs)));
        if (empty($color_slugs) || !taxonomy_exists($taxonomy)) {
            return array();
        }

        return array(
            'taxonomy' => $taxonomy,
            'field'    => 'slug',
            'terms'    => $color_slugs,
            'operator' => 'IN',
        );
    }

    /**
     * Apply color filter to a WP_Query args array.
     */
    public static function add_color_filter_to_query_args(array $args, array $color_slugs) {
        $clause = self::build_color_tax_query($color_slugs);
        if (empty($clause)) {
            return $args;
        }

        if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
            $args['tax_query'] = array();
        }

        if (count($args['tax_query']) > 0 && !isset($args['tax_query']['relation'])) {
            $args['tax_query']['relation'] = 'AND';
        }

        $args['tax_query'][] = $clause;
        return $args;
    }

    /**
     * Optional: get SyncMaster external image URL for a variation/product.
     */
    public static function get_external_image_url($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return '';
        }
        $url = get_post_meta($product_id, '_syncmaster_external_image_url', true);
        return is_string($url) ? esc_url($url) : '';
    }

    /**
     * Optional: get SyncMaster color view map (front/back/side) for a product.
     */
    public static function get_color_view_map($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return array();
        }

        $postbox = get_post_meta($product_id, 'tta_threaddesk_product_postbox', true);
        if (!is_array($postbox) || empty($postbox['colors']) || !is_array($postbox['colors'])) {
            return array();
        }

        return $postbox['colors'];
    }
}
```

## Example Usage

```php
// 1) Build color filter options for UI.
$colors = MyPlugin_Color_Filter_Bridge::get_filterable_colors(true);

// 2) Read selected colors from request, then filter products.
$selected = isset($_GET['color']) ? (array) $_GET['color'] : array();

$args = array(
    'post_type'      => 'product',
    'posts_per_page' => 24,
);

$args = MyPlugin_Color_Filter_Bridge::add_color_filter_to_query_args($args, $selected);
$query = new WP_Query($args);
```

## Implementation Notes

- Prefer term IDs/slugs over color names for durable filtering.
- Assume optional meta keys may be missing.
- Cache term/meta lookups if this powers heavy archive pages.
- Guard WooCommerce-dependent calls using `function_exists(...)` where needed.
