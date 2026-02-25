<?php
/**
 * Plugin Name: OnplayWallet
 * Plugin URI: https://github.com/Josborquez/OnplayWallet
 * Description: Wallet digital para WooCommerce con integración al sistema OnplayPOS. Permite pagos parciales, recargas, transferencias, cashback y sincronización con punto de venta.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: Josborquez
 * Author URI: https://github.com/Josborquez
 * Text Domain: woo-wallet
 * Domain Path: /languages/
 * Requires at least: 6.4
 * Tested up to: 6.9.1
 * WC requires at least: 8.0
 * WC tested up to: 10.5.2
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define WOO_WALLET_PLUGIN_FILE.
if ( ! defined( 'WOO_WALLET_PLUGIN_FILE' ) ) {
	define( 'WOO_WALLET_PLUGIN_FILE', __FILE__ );
}

// Define WOO_WALLET_ABSPATH.
if ( ! defined( 'WOO_WALLET_ABSPATH' ) ) {
	define( 'WOO_WALLET_ABSPATH', dirname( WOO_WALLET_PLUGIN_FILE ) . '/' );
}

// Define WOO_WALLET_PLUGIN_VERSION.
if ( ! defined( 'WOO_WALLET_PLUGIN_VERSION' ) ) {
	define( 'WOO_WALLET_PLUGIN_VERSION', '1.0.0' );
}

// Define ONPLAY_WALLET_VERSION.
if ( ! defined( 'ONPLAY_WALLET_VERSION' ) ) {
	define( 'ONPLAY_WALLET_VERSION', '1.0.0' );
}

// include dependencies file.
if ( ! class_exists( 'Woo_Wallet_Dependencies' ) ) {
	include_once __DIR__ . '/includes/class-woo-wallet-dependencies.php';
}

// Include the main class.
if ( ! class_exists( 'Woo_Wallet' ) ) {
	include_once __DIR__ . '/includes/class-woo-wallet.php';
}
/**
 * Returns the main instance of Woo_Wallet.
 *
 * @since  1.1.0
 * @return Woo_Wallet
 */
function woo_wallet() {
	return Woo_Wallet::instance();
}

$GLOBALS['woo_wallet'] = woo_wallet();
