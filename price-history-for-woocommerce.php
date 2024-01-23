<?php

/**
 * Plugin Name: Price History for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PHFW_PATH', plugin_dir_url(__FILE__));
function create_price_change_history_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'wc_price_change_history';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        product_id INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        change_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )";

    $wpdb->query($sql);
}

register_activation_hook(__FILE__, 'create_price_change_history_table');

function track_price_change($post_id)
{
    // Check if it's a product post type
    if (get_post_type($post_id) !== 'product') {
        return;
    }

    $product = wc_get_product($post_id);
    $new_price = $product->get_price();

    // Get the old price from the previous revision (if available)
    $old_price = get_post_meta($post_id, '_price', true);

    // If old price is not available, try to get it from the post data
    if (empty($old_price) && isset($_POST['_price'])) {
        $old_price = $_POST['_price'];
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_price_change_history';

    $wpdb->insert(
        $table_name,
        array(
            'product_id' => $post_id,
            'price' => $new_price,
        )
    );
}

add_action('save_post', 'track_price_change');
add_action('before_delete_post', 'track_price_change');

function get_price_change_history($product_id)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'wc_price_change_history';

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_name WHERE product_id = %d", $product_id),
        ARRAY_A
    );

    return $results;
}
// Function to display price change chart
function display_price_change_chart()
{
    global $product;

    if (!$product) {
        return; // Exit if product not available
    }

    $product_id = $product->get_id();
    $price_change_data = get_price_change_history($product_id);

    // Get custom labels from plugin options
    $custom_labels = get_option('price_history_custom_labels', array(
        'y_axis_title' => 'قیمت',
        'x_axis_title' => 'تاریخ',
        'legend_label' => 'تومان',
    ));

    // Enqueue Chart.js library
    wp_enqueue_script('chartjs', PHFW_PATH . 'chart.js', array(), '3.11.0', true);

    // Output the chart container
    $output = '<div style="width: 80%; margin: 20px auto;">
            <canvas id="price-change-chart" width="400" height="200"></canvas>
          </div>';

    // Use JavaScript to populate the chart with data
    $output .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var ctx = document.getElementById("price-change-chart").getContext("2d");
            var priceChangeData = ' . json_encode($price_change_data) . ';

            // Function to format dates using PHP IntlDateFormatter
            function formatDateJalali(gregorianDate) {
                var formatter = new Intl.DateTimeFormat("fa", {
                    year: "numeric",
                    month: "long",
                    day: "numeric",
                    calendar: "persian"
                });

                return formatter.format(new Date(gregorianDate));
            }

            var labels = priceChangeData.map(function(entry) {
                return formatDateJalali(entry.change_date);
            });

            var prices = priceChangeData.map(function(entry) {
                return entry.price;
            });

            var chart = new Chart(ctx, {
                type: "line",
                data: {
                    labels: labels,
                    datasets: [{
                        label: "' . esc_js($custom_labels['legend_label']) . '",
                        backgroundColor: "rgba(75, 192, 192, 0.2)",
                        borderColor: "rgba(75, 192, 192, 1)",
                        borderWidth: 1,
                        data: prices,
                    }],
                },
                options: {
                    scales: {
                        y: {
                            type: "linear",
                            position: "left",
                            title: {
                                display: true,
                                text: "' . esc_js($custom_labels['y_axis_title']) . '",
                                font: {
                                    family: "Yekan Bakh",
                                    weight: "bold"
                                }
                            },
                            ticks: {
                                font: {
                                    family: "Yekan Bakh",
                                    weight: "bold"
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: "' . esc_js($custom_labels['x_axis_title']) . '",
                                font: {
                                    family: "Yekan Bakh",
                                    weight: "bold"
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                font: {
                                    family: "Yekan Bakh",
                                    weight: "bold"
                                }
                            }
                        }
                    }
                },
            });
        });
    </script>';

    return $output;
}

// Register shortcode
add_shortcode('price_change_chart', 'display_price_change_chart');

// Admin Options Section
function price_history_admin_menu()
{
    add_menu_page(
        __('Price History Settings', 'price_history_for_woocommerce'),
        __('Price History', 'price_history_for_woocommerce'),
        'manage_options',
        'price_history_settings',
        'price_history_settings_page'
    );
}

add_action('admin_menu', 'price_history_admin_menu');

function price_history_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['price_history_submit'])) {
        // Save the settings
        $hook_active = isset($_POST['hook_active']) ? 1 : 0;
        $custom_labels = array(
            'y_axis_title' => sanitize_text_field($_POST['y_axis_title']),
            'x_axis_title' => sanitize_text_field($_POST['x_axis_title']),
            'legend_label' => sanitize_text_field($_POST['legend_label']),
        );

        update_option('price_history_hook_active', $hook_active);
        update_option('price_history_custom_labels', $custom_labels);

        echo '<div class="updated"><p>' . esc_html__('Settings saved!', 'price_history_for_woocommerce') . '</p></div>';
    }

    if (isset($_POST['price_history_clear'])) {
        // Clear history
        $clear_type = isset($_POST['clear_type']) ? sanitize_text_field($_POST['clear_type']) : '';

        if ($clear_type === 'all_products') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wc_price_change_history';
            $wpdb->query("TRUNCATE TABLE $table_name");

            echo '<div class="updated"><p>' . esc_html__('Price change history for all products cleared!', 'price_history_for_woocommerce') . '</p></div>';
        } elseif ($clear_type === 'single_product') {
            $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();

            if (!empty($product_ids)) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'wc_price_change_history';
                $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE product_id IN (%s)", implode(',', $product_ids)));

                echo '<div class="updated"><p>' . esc_html__('Price change history for selected products cleared!', 'price_history_for_woocommerce') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html__('No products selected!', 'price_history_for_woocommerce') . '</p></div>';
            }
        }
    }

    $hook_active = get_option('price_history_hook_active', 1);
    $custom_labels = get_option('price_history_custom_labels', array(
        'y_axis_title' => __('قیمت', 'price_history_for_woocommerce'),
        'x_axis_title' => __('تاریخ', 'price_history_for_woocommerce'),
        'legend_label' => __('تومان', 'price_history_for_woocommerce'),
    ));

?>


    <div class="wrap woocommerce">
        <div class="nav-tab-wrapper woo-nav-tab-wrapper">
            <div class="nav-tab " onclick="showTab('settingsTab')"><?= esc_html_e('Price History Settings', 'price_history_for_woocommerce'); ?></div>
            <div class="nav-tab " onclick="showTab('clearHistoryTab')"><?= esc_html_e('Clear Price Change History', 'price_history_for_woocommerce'); ?></div>
        </div>

        <div id="settingsTabContent" class="tab-content">
            <h1><?= esc_html_e('Price History Settings', 'price_history_for_woocommerce'); ?></h1>

            <form method="post" action="">
                <label>
                    <input type="checkbox" name="hook_active" <?php checked($hook_active, 1); ?>>
                    <?= esc_html_e('Enable Price History Chart on Single Product Page', 'price_history_for_woocommerce'); ?>
                </label>
                <br><br>
                <label for="y_axis_title"><?= esc_html_e('Y-Axis Title:', 'price_history_for_woocommerce'); ?></label>
                <input type="text" name="y_axis_title" id="y_axis_title" value="<?= esc_attr($custom_labels['y_axis_title']); ?>" required>
                <br><br>
                <label for="x_axis_title"><?= esc_html_e('X-Axis Title:', 'price_history_for_woocommerce'); ?></label>
                <input type="text" name="x_axis_title" id="x_axis_title" value="<?= esc_attr($custom_labels['x_axis_title']); ?>" required>
                <br><br>
                <label for="legend_label"><?= esc_html_e('Legend Label:', 'price_history_for_woocommerce'); ?></label>
                <input type="text" name="legend_label" id="legend_label" value="<?= esc_attr($custom_labels['legend_label']); ?>" required>
                <br><br>
                <input type="submit" name="price_history_submit" class="button-primary" value="<?= esc_html_e('Save Settings', 'price_history_for_woocommerce'); ?>">
            </form>
        </div>

        <div id="clearHistoryTabContent" class="tab-content" style="display: none;">
            <h1><?= esc_html_e('Clear Price Change History', 'price_history_for_woocommerce'); ?></h1>
            <form method="post" action="">
                <label>
                    <input type="radio" name="clear_type" value="all_products" required>
                    <?= esc_html_e('Clear Price Change History for All Products', 'price_history_for_woocommerce'); ?>
                </label>
                <br>
                <label>
                    <input type="radio" name="clear_type" value="single_product" required>
                    <?= esc_html_e('Clear Price Change History for a Specific Products', 'price_history_for_woocommerce'); ?>
                </label>
                <br>
                <h2><?= esc_html_e('Select your products', 'price_history_for_woocommerce'); ?></h2>
                <select data-security="<?= wp_create_nonce('search-products'); ?>" multiple style="width: 300px;" class="bc-product-search"></select>
                <input type="hidden" name="product_ids[]" id="selected_product_ids" value="">
                <br><br>
                <input type="submit" name="price_history_clear" class="button-primary" value="<?= esc_html_e('Clear History', 'price_history_for_woocommerce'); ?>">
            </form>
        </div>
    </div>

    <script>
        (function($) {
            $(function() {
                $('.bc-product-search').select2({
                    ajax: {
                        url: ajaxurl,
                        data: function(params) {
                            return {
                                term: params.term,
                                action: 'woocommerce_json_search_products_and_variations',
                                security: $(this).attr('data-security'),
                            };
                        },
                        processResults: function(data) {
                            var terms = [];
                            if (data) {
                                $.each(data, function(id, text) {
                                    terms.push({
                                        id: id,
                                        text: text,
                                        value: id
                                    });
                                });
                            }
                            return {
                                results: terms
                            };
                        },
                        cache: true
                    }
                });
                $('input.button-primary').on('click', function() {
                    $('#selected_product_ids').val($('.bc-product-search').val());
                    console.log($('.bc-product-search').val());

                });
            });
        })(jQuery)

        function showTab(tabName) {
            var tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(function(tab) {
                tab.style.display = 'none';
            });

            document.getElementById(tabName + 'Content').style.display = 'block';
        }
    </script>

<?php
}


// Check if the hook should be active before displaying the chart
$hook_active = get_option('price_history_hook_active', 1);

if ($hook_active) {
    add_action('woocommerce_after_single_product', 'display_price_change_chart');
}
