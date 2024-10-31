<?php

/**
 * Plugin Name:       My-CoolPay - Payment gateway for WooCommerce
 * Plugin URI:        https://www.my-coolpay.com
 * Description:       My-CoolPay - Payment gateway for WooCommerce is a modern plugin that allows you to sell anywhere your customers are. Offer your customers a modern payment solution and let them pay you however they want by Orange Money, MTN Mobile Money, VISA, MasterCard or My-CoolPay Wallet
 * Version:           1.5.0
 * Author:            Digital House International
 * Author URI:        https://digitalhouse-int.com
 * License:           GPL v2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       my-coolpay
 */


/**
 * Try to prevent direct access data leaks
 */
if (!defined('ABSPATH')) exit;


/**
 * Check if WooCommerce is present and active
 **/
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;


/**
 * Add My-CoolPay to WooCommerce available gateways
 * @param array $gateways all available WooCommerce gateways
 * @return array $gateways all WooCommerce gateways + My-Coolpay gateway
 */
function mycoolpay_add_to_woocommerce(array $gateways): array
{
    $gateways[] = 'Mycoolpay_Woocommerce_Gateway';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'mycoolpay_add_to_woocommerce');


/**
 * Called when the plugin loads
 */
function mycoolpay_gateway_init()
{
    class Mycoolpay_Woocommerce_Gateway extends WC_Payment_Gateway
    {
        const ID = 'mycoolpay';
        const API_URL = 'https://my-coolpay.com/api/{public_key}/paylink';
        const SERVER_IP = '15.236.140.89';
        const CURRENCIES = ['XAF', 'EUR'];
        const SUPPORTED_CURRENCIES = ['XAF' => 1, 'XOF' => 1, 'EUR' => 650, 'USD' => 550];

        /**
         * @var string
         */
        private $public_key;
        /**
         * @var string
         */
        private $private_key;
        /**
         * @var string
         */
        private $callback_url;
        /**
         * @var bool
         */
        private $autocomplete_orders;

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {
            // The global ID for this Payment method
            $this->id = self::ID;

            // This basically defines your settings which are then loaded with init_settings()
            $this->init_form_fields();

            // After init_settings() is called, you can get the settings and load them into variables
            $this->init_settings();

            // Boolean. Can be set to true if you want payment fields to show on the checkout
            $this->has_fields = false;

            // Image to be displayed to the user
            $image_url = $this->get_image_url();
            // check if My-CoolPay icon image exists or not
            if (@getimagesize($image_url)) {
                //Show an image on the frontend
                $this->icon = $image_url;
            }

            // Payment method name for order details and emails
            $this->title = "My-CoolPay";

            // Payment method name for admin pages
            $this->method_title = "My-CoolPay - Payment gateway for WooCommerce";

            // The description for this Payment Gateway, shown on the actual Payment options page on the backend
            $this->method_description = __(
                "My-CoolPay - Payment gateway for WooCommerce is a modern plugin that allows you to sell anywhere your customers are.
                Offer your customers a modern payment solution and let them pay you however they want by
                Orange Money, MTN Mobile Money, VISA, MasterCard or My-CoolPay Wallet",
                self::ID
            );

            // Define user set variables
            $this->order_button_text = $this->get_option('order_button_text');
            $this->description = $this->get_option('description');
            $this->public_key = $this->get_option('public_key');
            $this->private_key = $this->get_option('private_key');
            $this->callback_url = $this->get_option('callback_url');
            $this->autocomplete_orders = $this->get_option('autocomplete_orders') === 'yes';

            // Used to perform plugin information updated by the admin
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        /**
         * Generates a callback url only once
         */
        private function generate_callback_url()
        {
            if (!$this->get_option('callback_url')) {
                $this->update_option(
                    'callback_url',
                    get_home_url() . '/wp-json/callback/' . md5(uniqid() . mt_rand())
                );
            }
        }

        /**
         * Image to be displayed to the user
         */
        private function get_image_url(): string
        {
            $payment_methods = $this->get_option('payment_methods');
            $image = 'my_coolpay_operators.png';

            if ($payment_methods === 'mobile')
                $image = 'mcp_momo_om.png';
            else if ($payment_methods === 'credit_card')
                $image = 'mcp_visa_master.png';

            return WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__)) . '/images/' . $image;
        }

        /**
         * Initializes gateway settings form
         */
        public function init_form_fields()
        {
            // Generate a callback url
            $this->generate_callback_url();

            $this->form_fields = apply_filters(
                'mycoolpay_form_fields',
                [
                    'enabled' => [
                        'title' => __('Enable/Disable', self::ID),
                        'type' => 'checkbox',
                        'label' => __('Enable My-Coolpay', self::ID),
                        'description' => __('Check to enable this plugin', self::ID),
                        'default' => 'yes',
                        'desc_tip' => true,
                    ],
                    'callback_url' => [
                        'title' => __('Callback URL', self::ID),
                        'type' => 'hidden',
                        'description' => __('Copy the URL below and paste it in settings of your application in My-CoolPay <strong style="color: #00578A"><pre><code>' . $this->get_option('callback_url') . '</code></pre></strong>', self::ID),
                    ],
                    'description' => [
                        'title' => __('Description', self::ID),
                        'type' => 'textarea',
                        'description' => __('Payment method description, visible by customers on your checkout page', self::ID),
                        'default' => __('Pay safely using Orange Money, MTN Mobile Money, VISA, MasterCard or My-CoolPay Wallet', self::ID),
                        'desc_tip' => true,
                    ],
                    'public_key' => [
                        'title' => __('Public key', self::ID),
                        'type' => 'text',
                        'description' => __('Copy the public key of your application in My-CoolPay and paste here', self::ID),
                        'default' => '',
                        'desc_tip' => true,
                    ],
                    'private_key' => [
                        'title' => __('Private key', self::ID),
                        'type' => 'text',
                        'description' => __('Copy the private key of your application in My-CoolPay and paste here', self::ID),
                        'default' => '',
                        'desc_tip' => true,
                    ],
                    'payment_methods' => [
                        'title' => __('Enabled payment methods', self::ID),
                        'type' => 'select',
                        'description' => __('This will change operators logos displayed on your checkout page', self::ID),
                        'default' => "all",
                        'options' => [
                            "all" => __("All", self::ID),
                            "mobile" => "Mobile Money (OM + MoMo)",
                            "credit_card" => __("Credit Card (VISA + MasterCard)", self::ID),
                        ],
                        'desc_tip' => true,
                    ],
                    'order_button_text' => [
                        'title' => __('Payment button text', self::ID),
                        'type' => 'text',
                        'description' => __('Text of the payment button on which customers click to make the payment', self::ID),
                        'default' => __('Pay with My-CoolPay', self::ID),
                        'desc_tip' => true,
                    ],
                    'currency' => [
                        'title' => __('My-CoolPay currency', self::ID),
                        'type' => 'select',
                        'description' => __('This is the currency that your customers will see on payment page. If you choose Euro, only card payment will be available on payment page', self::ID),
                        'default' => "default",
                        'options' => [
                            "default" => __("Same as WooCommerce", self::ID),
                            "XAF" => "CFA Franc (FCFA)",
                            "EUR" => __("Euro (€)", self::ID),
                        ],
                        'desc_tip' => true,
                    ],
                    'autocomplete_orders' => array(
                        'title' => __('Autocomplete orders', self::ID),
                        'label' => __('Autocomplete orders on payment success', self::ID),
                        'type' => 'checkbox',
                        'description' => __('If enabled, orders statuses will go directly to complete after successful payment', self::ID),
                        'default' => 'no',
                        'desc_tip' => true,
                    ),
                ]);
        }

        public function get_public_key(): string
        {
            return $this->public_key;
        }

        public function get_private_key(): string
        {
            return $this->private_key;
        }

        public function get_callback_url(): string
        {
            return $this->callback_url;
        }

        public function get_autocomplete_orders(): bool
        {
            return $this->autocomplete_orders;
        }

        /**
         * Get order amount and currency for My-CoolPay
         * @throws Exception
         */
        private function get_order_amount_currency(WC_Order $order): array
        {
            $woocommerce_currency = get_woocommerce_currency();
            // Throws an exception when currency is not defined in MYCOOLPAY_CURRENCIES
            if (!isset(self::SUPPORTED_CURRENCIES[$woocommerce_currency]))
                throw new Exception("Currency '$woocommerce_currency' is not currently supported. Please, try using one of the following: " . implode(', ', array_keys(self::SUPPORTED_CURRENCIES)));

            $currency = $this->get_option('currency');
            if (!in_array($currency, self::CURRENCIES))
                $currency = $woocommerce_currency;

            $amount = $order->get_total();
            if ($currency !== $woocommerce_currency)
                $amount *= self::SUPPORTED_CURRENCIES[$woocommerce_currency] / self::SUPPORTED_CURRENCIES[$currency];

            return compact('amount', 'currency');
        }

        /**
         * Checks if billing country is CM and billing phone is a valid Mobile Money phone
         */
        private function is_order_from_cameroon(WC_Order $order): bool
        {
            return $order->get_billing_country() === 'CM'
                && preg_match(
                    '/^((\+|00)?237)?6[5789][0-9]{7}$/',
                    preg_replace('/[^0-9]/', '', $order->get_billing_phone()) // Ignore non numeric
                );
        }

        /**
         * Returns user's locale
         */
        private function get_locale(): string
        {
            return strpos('fr_FR', get_locale()) != false ? 'fr' : 'en';
        }

        /**
         * This function handles the processing of the order, telling WooCommerce
         * what status the order should have and where customers go after it’s used.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            try {
                $order_desc = implode(
                    ', ',
                    array_map(
                        function (WC_Order_Item $item) {
                            return $item->get_name();
                        },
                        $order->get_items()
                    )
                );

                $amount_currency = $this->get_order_amount_currency($order);
                $body = [
                    "transaction_amount" => $amount_currency['amount'],
                    "transaction_currency" => $amount_currency['currency'],
                    "transaction_reason" => substr($order_desc, 0, 255), // Get first 255 chars
                    "app_transaction_ref" => $order->get_order_key(),
                    "customer_name" => $order->get_formatted_billing_full_name(),
                    "customer_email" => $order->get_billing_email(),
                    "customer_lang" => $this->get_locale()
                ];

                if ($this->is_order_from_cameroon($order))
                    $body['customer_phone_number'] = preg_replace('/[^0-9]/', '', $order->get_billing_phone()); // Ignore non numeric

                // Get payment link from My-CoolPay
                $request = wp_remote_post(
                    str_replace('{public_key}', $this->public_key, self::API_URL),
                    ["body" => $body, "sslverify" => false]
                );

                // Get raw response
                $raw_response = wp_remote_retrieve_body($request);

                // Parse response
                $response = json_decode($raw_response, true);

                if (!(isset($response["status"]) && $response["status"] === "success"))
                    throw new Exception($response["message"] ?? "An error has occurred. Please try again later");

                $order->set_transaction_id($response['transaction_ref']);
                $order->add_order_note('My-CoolPay payment initiated with reference: ' . $response['transaction_ref']);

                // Clear cart
                WC()->cart->empty_cart();

                return [
                    'result' => 'success',
                    'redirect' => $response['payment_url']
                ];

            } catch (Exception $ex) {
                $order->add_order_note("My-CoolPay payment init failed with message: " . $ex->getMessage());
                wc_add_notice(__('Payment error : ', 'woothemes') . $ex->getMessage(), 'error');

                if (isset($request)) {
                    mycoolpay_log_data('Request <-----');
                    mycoolpay_log_data($request);
                }
                if (isset($raw_response)) {
                    mycoolpay_log_data('Raw response <-----');
                    mycoolpay_log_data($raw_response);
                }
                if (isset($response)) {
                    mycoolpay_log_data('Response <-----');
                    mycoolpay_log_data($response);
                }

                return;
            }
        }
    }


    /* ============================================ INCLUDE OTHER FILE =========================================== */

    // including the callback
    include 'include/mycoolpay_callback.php';

    // including the order_key in wc admin order list
    include 'include/mycoolpay_hooks.php';

    /*=========================================================================================================== */
}

add_action('plugins_loaded', 'mycoolpay_gateway_init', 0);
