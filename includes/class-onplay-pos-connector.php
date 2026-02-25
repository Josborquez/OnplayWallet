<?php
/**
 * OnplayPOS Connector - Handles communication with OnplayPOSv2
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OnplayPOS_Connector' ) ) {

	class OnplayPOS_Connector {

		/**
		 * POS API base URL.
		 *
		 * @var string
		 */
		private $api_url = '';

		/**
		 * POS API key.
		 *
		 * @var string
		 */
		private $api_key = '';

		/**
		 * POS API secret.
		 *
		 * @var string
		 */
		private $api_secret = '';

		/**
		 * Whether POS integration is enabled.
		 *
		 * @var bool
		 */
		private $enabled = false;

		/**
		 * Class constructor.
		 */
		public function __construct() {
			$this->load_settings();
			$this->init_hooks();
		}

		/**
		 * Load POS settings from WordPress options.
		 */
		private function load_settings() {
			$pos_settings     = get_option( '_wallet_settings_pos', array() );
			$this->api_url    = isset( $pos_settings['pos_api_url'] ) ? trailingslashit( $pos_settings['pos_api_url'] ) : '';
			$this->api_key    = isset( $pos_settings['pos_api_key'] ) ? $pos_settings['pos_api_key'] : '';
			$this->api_secret = isset( $pos_settings['pos_api_secret'] ) ? $pos_settings['pos_api_secret'] : '';
			$this->enabled    = isset( $pos_settings['pos_enable'] ) && 'on' === $pos_settings['pos_enable'];
		}

		/**
		 * Initialize hooks.
		 */
		private function init_hooks() {
			if ( ! $this->enabled ) {
				return;
			}
			add_action( 'woo_wallet_transaction_recorded', array( $this, 'sync_transaction_to_pos' ), 10, 4 );
		}

		/**
		 * Check if POS integration is enabled and configured.
		 *
		 * @return bool
		 */
		public function is_active() {
			return $this->enabled && ! empty( $this->api_url ) && ! empty( $this->api_key );
		}

		/**
		 * Get authentication headers for POS API.
		 *
		 * @return array
		 */
		private function get_auth_headers() {
			$timestamp = time();
			$signature = hash_hmac( 'sha256', $this->api_key . $timestamp, $this->api_secret );

			return array(
				'X-API-Key'       => $this->api_key,
				'X-Timestamp'     => $timestamp,
				'X-Signature'     => $signature,
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-Plugin-Source' => 'OnplayWallet/' . ONPLAY_WALLET_VERSION,
			);
		}

		/**
		 * Make a request to the POS API.
		 *
		 * @param string $endpoint API endpoint.
		 * @param string $method   HTTP method.
		 * @param array  $data     Request data.
		 * @return array|WP_Error Response data or WP_Error.
		 */
		public function api_request( $endpoint, $method = 'GET', $data = array() ) {
			if ( ! $this->is_active() ) {
				return new WP_Error( 'onplay_pos_not_configured', __( 'OnplayPOS integration is not configured.', 'woo-wallet' ) );
			}

			$url  = $this->api_url . ltrim( $endpoint, '/' );
			$args = array(
				'method'  => $method,
				'headers' => $this->get_auth_headers(),
				'timeout' => 30,
			);

			if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body'] = wp_json_encode( $data );
			} elseif ( ! empty( $data ) && 'GET' === $method ) {
				$url = add_query_arg( $data, $url );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$this->log( 'API Error: ' . $response->get_error_message(), 'error' );
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( $code >= 400 ) {
				$message = isset( $data['message'] ) ? $data['message'] : "HTTP {$code}";
				$this->log( "API HTTP {$code}: {$message}", 'error' );
				return new WP_Error( 'onplay_pos_api_error', $message, array( 'status' => $code ) );
			}

			return $data;
		}

		/**
		 * Test connectivity with the POS.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			return $this->api_request( 'api/ping' );
		}

		/**
		 * Get balance from POS for a user.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @return array|WP_Error
		 */
		public function get_pos_balance( $identifier ) {
			return $this->api_request( 'api/wallet/balance', 'GET', array( 'identifier' => $identifier ) );
		}

		/**
		 * Send a credit transaction to POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param float  $amount     Amount to credit.
		 * @param string $reference  Transaction reference.
		 * @param array  $meta       Additional meta data.
		 * @return array|WP_Error
		 */
		public function pos_credit( $identifier, $amount, $reference = '', $meta = array() ) {
			return $this->api_request(
				'api/wallet/credit',
				'POST',
				array(
					'identifier' => $identifier,
					'amount'     => floatval( $amount ),
					'reference'  => $reference,
					'source'     => 'woocommerce',
					'currency'   => get_woocommerce_currency(),
					'meta'       => $meta,
				)
			);
		}

		/**
		 * Send a debit transaction to POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param float  $amount     Amount to debit.
		 * @param string $reference  Transaction reference.
		 * @param array  $meta       Additional meta data.
		 * @return array|WP_Error
		 */
		public function pos_debit( $identifier, $amount, $reference = '', $meta = array() ) {
			return $this->api_request(
				'api/wallet/debit',
				'POST',
				array(
					'identifier' => $identifier,
					'amount'     => floatval( $amount ),
					'reference'  => $reference,
					'source'     => 'woocommerce',
					'currency'   => get_woocommerce_currency(),
					'meta'       => $meta,
				)
			);
		}

		/**
		 * Get transaction history from POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param int    $page       Page number.
		 * @param int    $per_page   Items per page.
		 * @return array|WP_Error
		 */
		public function get_pos_transactions( $identifier, $page = 1, $per_page = 20 ) {
			return $this->api_request(
				'api/wallet/transactions',
				'GET',
				array(
					'identifier' => $identifier,
					'page'       => $page,
					'per_page'   => $per_page,
				)
			);
		}

		/**
		 * Validate a POS payment QR code.
		 *
		 * @param string $qr_code  QR code data.
		 * @param float  $amount   Payment amount.
		 * @return array|WP_Error
		 */
		public function validate_qr_payment( $qr_code, $amount ) {
			return $this->api_request(
				'api/wallet/qr-validate',
				'POST',
				array(
					'qr_code'  => sanitize_text_field( $qr_code ),
					'amount'   => floatval( $amount ),
					'source'   => 'woocommerce',
					'currency' => get_woocommerce_currency(),
				)
			);
		}

		/**
		 * Process a POS payment via QR code.
		 *
		 * @param string $qr_code  QR code data.
		 * @param float  $amount   Payment amount.
		 * @param string $reference Transaction reference.
		 * @return array|WP_Error
		 */
		public function process_qr_payment( $qr_code, $amount, $reference = '' ) {
			return $this->api_request(
				'api/wallet/qr-pay',
				'POST',
				array(
					'qr_code'   => sanitize_text_field( $qr_code ),
					'amount'    => floatval( $amount ),
					'reference' => $reference,
					'source'    => 'woocommerce',
					'currency'  => get_woocommerce_currency(),
				)
			);
		}

		/**
		 * Register a WooCommerce customer in the POS system.
		 *
		 * @param int $user_id WordPress user ID.
		 * @return array|WP_Error
		 */
		public function register_customer( $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return new WP_Error( 'invalid_user', __( 'Invalid user.', 'woo-wallet' ) );
			}

			return $this->api_request(
				'api/customers/register',
				'POST',
				array(
					'email'      => $user->user_email,
					'first_name' => $user->first_name,
					'last_name'  => $user->last_name,
					'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
					'source'     => 'woocommerce',
					'external_id' => $user_id,
				)
			);
		}

		/**
		 * Sync local wallet transaction to POS after recording.
		 *
		 * @param int    $transaction_id Transaction ID.
		 * @param int    $user_id        User ID.
		 * @param float  $amount         Amount.
		 * @param string $type           credit or debit.
		 */
		public function sync_transaction_to_pos( $transaction_id, $user_id, $amount, $type ) {
			if ( ! $this->is_active() ) {
				return;
			}

			$pos_settings = get_option( '_wallet_settings_pos', array() );
			$sync_enabled = isset( $pos_settings['pos_auto_sync'] ) && 'on' === $pos_settings['pos_auto_sync'];

			if ( ! $sync_enabled ) {
				return;
			}

			// Avoid infinite loop: don't sync transactions that originated from POS.
			$source = get_wallet_transaction_meta( $transaction_id, '_onplay_source', '' );
			if ( 'pos' === $source ) {
				return;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return;
			}

			$reference = 'WC-TXN-' . $transaction_id;

			$result = null;
			if ( 'credit' === $type ) {
				$result = $this->pos_credit( $user->user_email, $amount, $reference );
			} elseif ( 'debit' === $type ) {
				$result = $this->pos_debit( $user->user_email, $amount, $reference );
			}

			if ( is_wp_error( $result ) ) {
				update_wallet_transaction_meta( $transaction_id, '_pos_sync_status', 'failed', $user_id );
				update_wallet_transaction_meta( $transaction_id, '_pos_sync_error', $result->get_error_message(), $user_id );
				$this->log( "Sync failed for transaction #{$transaction_id}: " . $result->get_error_message(), 'error' );
			} else {
				update_wallet_transaction_meta( $transaction_id, '_pos_sync_status', 'synced', $user_id );
				if ( isset( $result['transaction_id'] ) ) {
					update_wallet_transaction_meta( $transaction_id, '_pos_transaction_id', $result['transaction_id'], $user_id );
				}
			}
		}

		/**
		 * Generate a wallet QR code payload for a user.
		 *
		 * @param int $user_id WordPress user ID.
		 * @return string|WP_Error QR payload or error.
		 */
		public function generate_wallet_qr( $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return new WP_Error( 'invalid_user', __( 'Invalid user.', 'woo-wallet' ) );
			}

			$balance   = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
			$timestamp = time();
			$token     = hash_hmac( 'sha256', $user->user_email . '|' . $timestamp, $this->api_secret ?: wp_salt( 'auth' ) );

			$payload = array(
				'source'    => 'onplay_wallet',
				'email'     => $user->user_email,
				'user_id'   => $user_id,
				'balance'   => $balance,
				'currency'  => get_woocommerce_currency(),
				'timestamp' => $timestamp,
				'token'     => $token,
			);

			return wp_json_encode( $payload );
		}

		/**
		 * Log POS connector messages.
		 *
		 * @param string $message Log message.
		 * @param string $level   Log level (info, error, warning).
		 */
		public function log( $message, $level = 'info' ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				$logger->log( $level, $message, array( 'source' => 'onplay-pos-connector' ) );
			}
		}
	}

}
