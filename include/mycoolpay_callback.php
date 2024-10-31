<?php

/**
 * Used to define the callback url route and to call the appropriate function
 */
function mycoolpay_register_callback_endpoint()
{
    $mycoolpay = new Mycoolpay_Woocommerce_Gateway();

    // defines the route
    register_rest_route(
        'callback',
        explode('callback/', $mycoolpay->get_callback_url())[1], // Get route from full callback url
        [
            'methods' => 'POST',
            'callback' => 'mycoolpay_handle_callback',
            'permission_callback' => '__return_true'
        ]
    );
}

add_action('rest_api_init', 'mycoolpay_register_callback_endpoint');


/**
 * Change a order status after the payment has been performed
 */
function mycoolpay_handle_callback(WP_REST_Request $request): WP_REST_Response
{
    // Check the client ip address
    if (!in_array(Mycoolpay_Woocommerce_Gateway::SERVER_IP, [
        $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']
    ]))
        return new WP_REST_Response("Unknown IP address", 403);

    $request_body = $request->get_body();

    // Get data according to content type
    if ($request->get_content_type()['value'] === 'application/json')
        $data = json_decode($request_body, true);
    else
        parse_str($request_body, $data);

    // Log data
    mycoolpay_log_data($data);

    $mycoolpay = new Mycoolpay_Woocommerce_Gateway();

    // Check public key
    if ($data['application'] !== $mycoolpay->get_public_key())
        return new WP_REST_Response("Unknown application", 403);

    // Check My-CoolPay signature
    if (!mycoolpay_check_signature($mycoolpay, $data))
        return new WP_REST_Response("Bad signature", 403);

    // gets a order by the order key
    $order = wc_get_order(wc_get_order_id_by_order_key($data["app_transaction_ref"]));
    if (!$order)
        return new WP_REST_Response("Order not found", 404);

    $transaction_message = $data["transaction_message"];
    $transaction_status = $data["transaction_status"];

    if ($transaction_status === 'SUCCESS') {

        $order->payment_complete();
        $order->add_order_note('Payment was successful on My-CoolPay');

        return new WP_REST_Response("Order completed");

    } else if ($transaction_status === 'CANCELED') {

        $order->update_status('cancelled', $transaction_message);

        return new WP_REST_Response("Order cancelled");

    } else if ($transaction_status === 'FAILED') {

        $order->update_status('failed', $transaction_message);

        return new WP_REST_Response("Order failed");
    }

    return new WP_REST_Response("Unknown transaction_status '$transaction_status'", 400);
}

/**
 * Checks the signature of the request
 */
function mycoolpay_check_signature(Mycoolpay_Woocommerce_Gateway $gateway, array $data): bool
{
    return $data["signature"] === md5(
            $data["transaction_ref"] . $data["transaction_type"] . $data["transaction_amount"]
            . $data["transaction_currency"] . $data["transaction_operator"] . $gateway->get_private_key()
        );
}


/**
 * Print received data to the log file
 */
function mycoolpay_log_data($data)
{
    // add data in the wordpress log file 
    if (!function_exists('write_log')) {
        function write_log($log)
        {
            if (WP_DEBUG)
                error_log(is_array($log) || is_object($log) ? print_r($log, true) : $log);
        }
    }
    write_log($data);
}
