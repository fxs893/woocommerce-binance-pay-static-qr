<?php
/**
 * Plugin Name: WooCommerce Binance Pay (Static QR + Auto Listener)
 * Description: Customers pay by scanning your Binance App “Send & Receive → Receive” QR (USDT/USDC). The system only checks Binance Pay transactions and validates memo + amount.
 * Version: 1.2
 * Author: 893
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 * Text Domain: wc-binance-pay
 */

if (!defined('ABSPATH')) { exit; }

define('WC_BINANCE_PAY_PATH', plugin_dir_path(__FILE__));
define('WC_BINANCE_AJAX_ACTION', 'wc_binance_pay_check_v5');

// Unified nonce name/action for both frontend & admin
if (!defined('WC_BINANCE_NONCE_NAME'))   define('WC_BINANCE_NONCE_NAME',   'wc_binance_pay_nonce');
if (!defined('WC_BINANCE_NONCE_ACTION')) define('WC_BINANCE_NONCE_ACTION', 'wc_binance_pay_nonce_action');

// Admin debug window AJAX action (opens JSON in a new tab)
if (!defined('WC_BINANCE_AJAX_TEST'))    define('WC_BINANCE_AJAX_TEST',    'wc_binance_pay_api_test');

/**
 * WooCommerce feature compatibility declarations
 * - HPOS (Custom Order Tables)
 * - Cart & Checkout Blocks
 *
 * Note: Declaring compatibility with Blocks removes the admin warning,
 *       but does NOT automatically make the gateway appear on block checkout
 *       without an additional Blocks integration class.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        // HPOS compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );

        // Cart & Checkout Blocks compatibility (declaration only)
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

// Delayed init (ensure WC classes are loaded)
add_action('plugins_loaded', 'wc_binance_pay_init', 20);
function wc_binance_pay_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    require_once WC_BINANCE_PAY_PATH . 'includes/binance-client.php';
    require_once WC_BINANCE_PAY_PATH . 'includes/class-wc-gateway-binance-static.php';
    require_once WC_BINANCE_PAY_PATH . 'includes/ajax-handler.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Binance_Static';
        return $gateways;
    });
}

// Frontend order self-check (guest & logged-in)
add_action('wp_ajax_nopriv_' . WC_BINANCE_AJAX_ACTION, 'wc_binance_handle_payment_check');
add_action('wp_ajax_' . WC_BINANCE_AJAX_ACTION, 'wc_binance_handle_payment_check');

// Admin settings: open debug window (returns JSON, latest Binance Pay record)
add_action('wp_ajax_' . WC_BINANCE_AJAX_TEST, 'wc_binance_handle_api_test');
