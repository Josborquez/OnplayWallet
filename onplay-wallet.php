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

/**
 * Safety-net: guarantee the POS webhook route is always registered.
 *
 * Runs at priority 99 so the main class has a chance to register it first.
 * If the route already exists (normal flow), this is a no-op.  If the main
 * class failed for any reason, this ensures the POS can still reach us and
 * we get a useful diagnostic rather than a bare 404.
 */
add_action(
	'rest_api_init',
	function () {
		// If the full controller already registered, nothing to do.
		if ( class_exists( 'OnplayPOS_REST_Controller' ) ) {
			return;
		}

		// Attempt to load the controller one more time.
		$controller_file = __DIR__ . '/includes/api/class-onplay-pos-rest-controller.php';
		if ( file_exists( $controller_file ) ) {
			include_once $controller_file;
			if ( class_exists( 'OnplayPOS_REST_Controller' ) ) {
				$pos_controller = new OnplayPOS_REST_Controller();
				$pos_controller->register_routes();
				return;
			}
		}

		// Last resort: register a minimal webhook endpoint with inline HMAC verification.
		register_rest_route(
			'onplay/v1',
			'/pos/webhook',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => function ( WP_REST_Request $request ) {
						$body  = json_decode( $request->get_body(), true );
						$event = isset( $body['event'] ) ? sanitize_text_field( $body['event'] ) : '';

						error_log( sprintf(
							'OnplayWallet FALLBACK webhook hit: event=%s (full controller unavailable)',
							$event ?: '(empty)'
						) );

						return new WP_REST_Response(
							array(
								'success' => false,
								'message' => 'OnplayPOS controller could not be loaded. Webhook was received but not processed. Check server PHP error log.',
								'event'   => $event,
							),
							503
						);
					},
					'permission_callback' => function ( WP_REST_Request $request ) {
						$pos_settings   = get_option( '_wallet_settings_pos', array() );
						$webhook_secret = isset( $pos_settings['pos_webhook_secret'] ) ? $pos_settings['pos_webhook_secret'] : '';

						if ( empty( $webhook_secret ) ) {
							return new WP_Error( 'onplay_webhook_not_configured', 'Webhook secret not configured.', array( 'status' => 500 ) );
						}

						$signature = $request->get_header( 'X-Onplay-Signature' );
						if ( empty( $signature ) ) {
							return new WP_Error( 'onplay_webhook_no_signature', 'Missing webhook signature.', array( 'status' => 401 ) );
						}

						$body         = $request->get_body();
						$expected_raw = hash_hmac( 'sha256', $body, $webhook_secret );
						$expected_pre = 'sha256=' . $expected_raw;

						if ( ! hash_equals( $expected_raw, $signature ) && ! hash_equals( $expected_pre, $signature ) ) {
							return new WP_Error( 'onplay_webhook_invalid_signature', 'Invalid webhook signature.', array( 'status' => 403 ) );
						}

						return true;
					},
				),
			)
		);

		error_log( 'OnplayWallet: POS controller unavailable — fallback webhook route registered at priority 99.' );
	},
	99
);
