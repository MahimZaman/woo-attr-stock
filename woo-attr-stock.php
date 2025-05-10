<?php

/**
 * Plugin Name: Woo Attribute Stock Management
 * Description: Stock management application for woocommerce product attributes
 * Version: 1.0
 * Author: Mahim Zaman
 * Author URI: https://www.mahimzaman.com
 */

if (!defined('ABSPATH')) return;

include_once ABSPATH . 'wp-admin/includes/plugin.php';

// check for plugin using plugin name
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    //plugin is activated
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>WooCommerce is required to user Woo Attribute Stock Management Plugin.</p></div>';
    });
    return;
}

define('WASM_PATH', trailingslashit(plugin_dir_path(__FILE__)));
define('WASM_URL', trailingslashit(plugin_dir_url(__FILE__)));

// Hook into add/edit term screens dynamically for all 'pa_' taxonomies
add_action('admin_init', function () {
    $attribute_taxonomies = wc_get_attribute_taxonomies();

    if ($attribute_taxonomies) {
        foreach ($attribute_taxonomies as $attribute) {
            $taxonomy = 'pa_' . $attribute->attribute_name;

            // Add custom column
            add_filter("manage_edit-{$taxonomy}_columns", 'wasm_attribute_add_column');
            add_filter("manage_{$taxonomy}_custom_column", 'wasm_attribute_show_column', 10, 3);

            add_action("{$taxonomy}_add_form_fields", 'wasm_add_number_field', 10);
            add_action("{$taxonomy}_edit_form_fields", 'wasm_edit_number_field', 10, 2);

            add_action("created_{$taxonomy}", 'wasm_save_number_field', 10, 2);
            add_action("edited_{$taxonomy}", 'wasm_save_number_field', 10, 2);
        }
    }
});

add_action('admin_enqueue_scripts', 'wasm_admin_scripts');

function wasm_admin_scripts()
{
    wp_enqueue_style('wasm-admin', WASM_URL . 'assets/admin.css', array(), time(), 'all');
}

// Add column header
function wasm_attribute_add_column($columns)
{
    $columns['wasm_stock_count'] = __('Stock Count', 'woocommerce');
    return $columns;
}

// Show column content
function wasm_attribute_show_column($content, $column_name, $term_id)
{
    if ($column_name === 'wasm_stock_count') {
        $value = get_term_meta($term_id, 'wasm_stock_count', true);
        return esc_html($value);
    }
    return $content;
}

// Display field on Add form
function wasm_add_number_field()
{
?>
    <div class="form-field term-group">
        <label for="wasm_stock_count"><?php _e('Stock Count', 'woocommerce'); ?></label>
        <input type="number" name="wasm_stock_count" id="wasm_stock_count" value="">
    </div>
<?php
}

// Display field on Edit form
function wasm_edit_number_field($term, $taxonomy)
{
    $value = get_term_meta($term->term_id, 'wasm_stock_count', true);
?>
    <tr class="form-field term-group-wrap">
        <th scope="row"><label for="wasm_stock_count"><?php _e('Stock Count', 'woocommerce'); ?></label></th>
        <td>
            <input type="number" name="wasm_stock_count" id="wasm_stock_count" value="<?php echo esc_attr($value); ?>">
        </td>
    </tr>
<?php
}

// Save field value for both create and update
function wasm_save_number_field($term_id, $tt_id)
{
    if (isset($_POST['wasm_stock_count'])) {
        update_term_meta($term_id, 'wasm_stock_count', sanitize_text_field($_POST['wasm_stock_count']));
    }
}

add_action('woocommerce_thankyou', 'wasm_reduce_attribute_custom_number_on_order', 20, 1);

function wasm_reduce_attribute_custom_number_on_order($order_id)
{
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $isStockUpdated = get_post_meta($order_id, 'wasm_stock_updated', true) ? get_post_meta($order_id, 'wasm_stock_updated', true) : false;

    if ($isStockUpdated) return;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $quantity = $item->get_quantity();

        if (!$product || !$product->get_id()) continue;

        $product_id = $product->get_id();
        $attributes = $product->get_attributes();

        foreach ($attributes as $taxonomy => $term_slug) {
            if (strpos($taxonomy, 'pa_') !== 0) continue; // Only process attribute taxonomies

            // Get the term object by slug and taxonomy
            $term = get_term_by('slug', $term_slug, $taxonomy);

            if ($term && !is_wp_error($term)) {
                $current_stock = get_term_meta($term->term_id, 'wasm_stock_count', true);

                // Only update if stock is set (not empty), else skip
                if (strlen($current_stock)) {
                    $new_stock = max(0, intval($current_stock) - $quantity);
                    update_term_meta($term->term_id, 'wasm_stock_count', $new_stock);
                    update_post_meta($order_id, 'wasm_stock_updated', true);
                }
            }
        }
    }
}

add_action('wp_footer', 'wasm_disable_zero_stock_attribute_terms', 999);

function wasm_disable_zero_stock_attribute_terms()
{
    if (!is_product()) return;

    global $product;

    if (!$product || !$product->is_type('variable')) return;

    $attribute_terms_to_disable = [];

    foreach (get_object_taxonomies('product') as $taxonomy) {
        if (strpos($taxonomy, 'pa_') !== 0) continue;

        $terms = wp_get_post_terms($product->get_id(), $taxonomy);
        foreach ($terms as $term) {
            $stock = get_term_meta($term->term_id, 'wasm_stock_count', true);

            if (strlen($stock) && intval($stock) <= 0) {
                $attribute_terms_to_disable[$taxonomy][] = $term->slug;
            }
        }
    }

    if (empty($attribute_terms_to_disable)) return;

    // Output JS
?>
    <script defer>
        function wasmDisableOption() {
            const disabledTerms = <?php echo json_encode($attribute_terms_to_disable); ?>;

            Object.entries(disabledTerms).forEach(([taxonomy, slugs]) => {
                slugs.forEach(slug => {
                    // Disable select dropdown options
                    const select = document.querySelector(`select[name="attribute_${taxonomy}"]`);
                    if (select) {
                        const option = select.querySelector(`option[value="${slug}"]`);
                        if (option) {
                            console.log(option.disabled);
                            option.disabled = true;
                            const prevText = option.text;
                            if (prevText.indexOf('(Out of stock)') > -1) {
                                option.text = prevText;
                            } else {
                                option.text = prevText + ' (Out of stock)'
                            }
                        }
                    }

                    // Disable variation swatches
                    const swatch = document.querySelector(`[data-attribute_name="attribute_${taxonomy}"] [data-value="${slug}"]`);
                    if (swatch) {
                        swatch.classList.add('disabled');
                        swatch.style.pointerEvents = 'none';
                        swatch.style.opacity = 0.4;
                        swatch.title = 'Out of stock';
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {

        });

        jQuery('form.variations_form.cart').on('update_variation_values', function() {

            const disableOptionInterval = setInterval(wasmDisableOption, 1000);

            setTimeout(function() {
                clearInterval(disableOptionInterval);
            }, 5000)
        })
    </script>
    <style>
        .disabled {
            opacity: 0.4 !important;
            pointer-events: none !important;
        }
    </style>
<?php
}

add_action('quick_edit_custom_box', 'wasm_stock_count_quick_edit_field', 10, 3);
add_action('bulk_edit_custom_box', 'wasm_stock_count_quick_edit_field', 10, 3);

function wasm_stock_count_quick_edit_field($column_name, $screen, $taxonomy)
{
    if ($column_name !== 'wasm_stock_count') return;

?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <label>
                <span class="title">Stock Count</span>
                <span class="input-text-wrap">
                    <input type="number" step="1" min="0" name="wasm_stock_count" class="wasm_stock_count" value="">
                </span>
            </label>
        </div>
    </fieldset>
<?php
}

add_action('admin_footer-edit-tags.php', 'wasm_stock_count_quick_edit_js');

function wasm_stock_count_quick_edit_js()
{
    $screen = get_current_screen();
    if (strpos($screen->id, 'edit-pa_') === false) return; // Only run on attribute taxonomy pages
?>
    <script>
        jQuery(function($) {
            $('.editinline').on('click', function() {
                var $row = $(this).closest('tr');
                var customNumber = $row.find('.column-wasm_stock_count').text().trim();

                // Remove (if any label like "Out of stock")
                customNumber = parseInt(customNumber.replace(/\D/g, ''));

                console.log(customNumber)

                $('input.wasm_stock_count').val(isNaN(customNumber) ? '' : customNumber);
            });
        });
    </script>
<?php
}

add_action('admin_footer-edit-tags.php', function () {
    $screen = get_current_screen();
    if (strpos($screen->id, 'edit-pa_') === false) return;
?>
    <script>
        jQuery(function($) {
            const stockAction = '<option value="update_stock_count">Update Stock Count</option>';
            $('select[name="action"], select[name="action2"]').each(function() {
                if (!$(this).find('option[value="update_stock_count"]').length) {
                    $(this).append(stockAction);
                }
            });

            // Add input field
            $('<div id="bulk-stock-count-field" style="display:none; margin-top:10px;">' +
                '<label><strong>Stock Count:</strong> ' +
                '<input type="number" name="bulk_stock_count" min="0" step="1" style="width: 100px;" /></label>' +
                '</div>').insertAfter('.tablenav.top');

            // Show/hide input field based on action
            $('select[name="action"], select[name="action2"]').on('change', function() {
                var show = $('select[name="action"]').val() === 'update_stock_count' || $('select[name="action2"]').val() === 'update_stock_count';
                $('#bulk-stock-count-field').toggle(show);
            });
        });
    </script>
<?php
});

add_action('load-edit-tags.php', function () {
    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'update_stock_count' &&
        !empty($_POST['delete_tags']) &&
        isset($_POST['bulk_stock_count'])
    ) {
        $term_ids = array_map('intval', (array) $_POST['delete_tags']);
        $stock = intval($_POST['bulk_stock_count']);
        foreach ($term_ids as $term_id) {
            update_term_meta($term_id, 'wasm_stock_count', $stock);
        }

        // Redirect to prevent resubmission
        $redirect_url = add_query_arg('bulk_stock_updated', count($term_ids), wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    }
});

add_action('admin_notices', function () {
    if (!empty($_GET['bulk_stock_updated'])) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%d attribute stock(s) updated.</p></div>',
            intval($_GET['bulk_stock_updated'])
        );
    }
});

add_filter('woocommerce_add_to_cart_validation', 'wasm_validate_attribute_stock_count', 20, 5);

function wasm_validate_attribute_stock_count($passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = []) {
    $product = wc_get_product($product_id);
    $attributes = $product->get_attributes();

    foreach ($attributes as $attribute_name => $attribute_obj) {
        if (!$attribute_obj->is_taxonomy()) continue;

        $taxonomy = $attribute_obj->get_name(); // e.g. 'pa_rims'
        $request_key = 'attribute_' . sanitize_title($taxonomy);
        if (!isset($_REQUEST[$request_key])) continue;

        $term_slug = wc_clean(wp_unslash($_REQUEST[$request_key]));
        $term = get_term_by('slug', $term_slug, $taxonomy);
        if (!$term || is_wp_error($term)) continue;

        // Get available stock
        $stock = get_term_meta($term->term_id, 'wasm_stock_count', true);
        if ($stock === '' || !is_numeric($stock)) continue;

        $stock = (int) $stock;

        // 🔍 Check how much of this attribute is already in the cart
        $cart_quantity = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_product_id = $cart_item['product_id'];
            $cart_qty = $cart_item['quantity'];

            $cart_product = wc_get_product($cart_product_id);
            $cart_attrs = $cart_product ? $cart_product->get_attributes() : [];

            // If this product uses the same attribute taxonomy and term
            if (isset($cart_item['variation']) && is_array($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $key => $value) {
                    if ($key === $request_key && $value === $term_slug) {
                        $cart_quantity += $cart_qty;
                    }
                }
            }
        }

        $total_requested = $quantity + $cart_quantity;

        if ($total_requested > $stock) {
            wc_add_notice(sprintf(
                __('Only %d item(s) left for "%s". You already have %d in your cart.', 'woocommerce'),
                $stock,
                $term->name,
                $cart_quantity
            ), 'error');
            return false;
        }
    }

    return $passed;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=product',
        'Attribute Stock Import/Export',
        'Attribute Stock Import/Export',
        'manage_woocommerce',
        'attribute-stock-import-export',
        'wasm_render_attribute_stock_page'
    );
});

function wasm_render_attribute_stock_page() {
    ?>
    <div class="wrap">
        <h1>Attribute Stock Import/Export</h1>

        <h2>Export CSV</h2>
        <form method="post">
            <?php submit_button('Download CSV', 'primary', 'wasm_export_attributes_csv'); ?>
        </form>

        <hr>

        <h2>Import CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="wasm_csv" accept=".csv" required>
            <?php submit_button('Upload and Update Stock'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    if (isset($_POST['wasm_export_attributes_csv'])) {
        $filename = 'attribute-stock-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=' . $filename);
        $output = fopen('php://output', 'w');
        fputcsv($output, ['taxonomy', 'term_id', 'term_name', 'slug', 'stock']);

        $attribute_taxonomies = wc_get_attribute_taxonomies();
        foreach ($attribute_taxonomies as $attr_tax) {
            $taxonomy = 'pa_' . $attr_tax->attribute_name;
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false
            ]);

            foreach ($terms as $term) {
                $stock = get_term_meta($term->term_id, 'wasm_stock_count', true);
                fputcsv($output, [$taxonomy, $term->term_id, $term->name, $term->slug, $stock]);
            }
        }

        fclose($output);
        exit;
    }
});

add_action('admin_init', function () {
    if (!empty($_FILES['wasm_csv']) && current_user_can('manage_woocommerce')) {
        $file = $_FILES['wasm_csv']['tmp_name'];
        if (($handle = fopen($file, 'r')) !== false) {
            $row = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $row++;
                if ($row === 1) continue; // Skip header

                list($taxonomy, $term_id, $name, $slug, $stock) = $data;
                if (taxonomy_exists($taxonomy) && is_numeric($term_id)) {
                    update_term_meta((int)$term_id, 'wasm_stock_count', (int)$stock);
                }
            }
            fclose($handle);
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>Attribute stock updated successfully.</p></div>';
            });
        }
    }
});


