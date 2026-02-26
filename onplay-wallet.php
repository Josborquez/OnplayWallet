<?php
/**
 * Plugin Name: OnplayWallet
 * Plugin URI: https://github.com/Josborquez/OnplayWallet
 * Description: Wallet digital para WooCommerce con integración al sistema OnplayPOS. Permite pagos parciales, recargas, transferencias, cashback y sincronización con punto de venta.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: Josborquez
 * Author URI: https://github.com/Josborquez
 * Text Domain: onplay-wallet
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

// Define ONPLAY_WALLET_PLUGIN_FILE.
if ( ! defined( 'ONPLAY_WALLET_PLUGIN_FILE' ) ) {
	define( 'ONPLAY_WALLET_PLUGIN_FILE', __FILE__ );
}

// Define ONPLAY_WALLET_ABSPATH.
if ( ! defined( 'ONPLAY_WALLET_ABSPATH' ) ) {
	define( 'ONPLAY_WALLET_ABSPATH', dirname( ONPLAY_WALLET_PLUGIN_FILE ) . '/' );
}

// Define ONPLAY_WALLET_PLUGIN_VERSION.
if ( ! defined( 'ONPLAY_WALLET_PLUGIN_VERSION' ) ) {
	define( 'ONPLAY_WALLET_PLUGIN_VERSION', '1.0.0' );
}

// Define ONPLAY_WALLET_VERSION.
if ( ! defined( 'ONPLAY_WALLET_VERSION' ) ) {
	define( 'ONPLAY_WALLET_VERSION', '1.0.0' );
}

// include dependencies file.
if ( ! class_exists( 'Onplay_Wallet_Dependencies' ) ) {
	include_once __DIR__ . '/includes/class-onplay-wallet-dependencies.php';
}

// Include the main class.
if ( ! class_exists( 'Onplay_Wallet' ) ) {
	include_once __DIR__ . '/includes/class-onplay-wallet.php';
}
/**
 * Returns the main instance of Onplay_Wallet.
 *
 * @since  1.1.0
 * @return Onplay_Wallet
 */
function onplay_wallet() {
	return Onplay_Wallet::instance();
}

$GLOBALS['onplay_wallet'] = onplay_wallet();
