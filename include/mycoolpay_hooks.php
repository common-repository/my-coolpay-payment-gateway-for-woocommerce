<?php

/**
 * Adds new columns in woocommerce admin order list
 */
function mycoolpay_add_custom_columns_to_order_list(array $columns): array
{
    $new_columns = [];

    foreach ($columns as $column_key => $column_name) {
        $new_columns[$column_key] = $column_name;

        if ($column_key === 'order_status')
            $new_columns['order_key'] = "Reference";
    }

    return $new_columns;
}

add_filter('manage_edit-shop_order_columns', 'mycoolpay_add_custom_columns_to_order_list', 20);


/**
 * Adds content in previously added columns
 */
function mycoolpay_add_content_to_custom_columns(string $column)
{
    global $post;

    if ($column === 'order_key')
        echo wc_get_order($post->ID)->get_order_key();
}

add_action('manage_shop_order_posts_custom_column', 'mycoolpay_add_content_to_custom_columns');


/**
 * Make custom columns searchable in the admin order list by adding them to woocommerce search fields
 */
function mycoolpay_add_custom_columns_to_meta_keys($meta_keys)
{
    $meta_keys[] = '_order_key';
    return $meta_keys;
}

add_filter('woocommerce_shop_order_search_fields', 'mycoolpay_add_custom_columns_to_meta_keys', 10, 1);


/**
 * Update order status on successful payment
 */
function mycoolpay_payment_complete($order_id)
{
    $mycoolpay = new Mycoolpay_Woocommerce_Gateway();
    $order = wc_get_order($order_id);
    if ($order->get_payment_method() === $mycoolpay::ID) {
        $status = $mycoolpay->get_autocomplete_orders() || !$order->needs_processing() ? 'completed' : 'processing';
        $order->update_status($status);
    }
}

add_action('woocommerce_payment_complete', 'mycoolpay_payment_complete');
