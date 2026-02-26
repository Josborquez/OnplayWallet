<?php
/**
 * OnplayPOS Connector - Handles communication with OnplayPOSv2
 *
 * Supports two modes:
 * 1. SSoT mode: POS is the single source of truth for wallet balance.
 *    Uses /balance, /debit, /credit, /transactions, /customer, /status endpoints.
 * 2. Legacy mode: Outbound sync of local transactions to POS.
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
		 * POS API key (X-Onplay-Api-Key header).
		 *
		 * @var string
		 */
		private $api_key = '';

		/**
		 * POS API secret (legacy HMAC).
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
		 * Whether POS is source of truth.
		 *
		 * @var bool
		 */
		private $is_ssot = false;

		/**
		 * API timeout in seconds.
		 *
		 * @var int
		 */
		private $timeout = 10;

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
			$this->is_ssot    = isset( $pos_settings['pos_is_ssot'] ) && 'on' === $pos_settings['pos_is_ssot'];
			$this->timeout    = isset( $pos_settings['pos_api_timeout'] ) && intval( $pos_settings['pos_api_timeout'] ) > 0
				? intval( $pos_settings['pos_api_timeout'] )
				: 10;
		}

		/**
		 * Initialize hooks.
		 */
		private function init_hooks() {
			if ( ! $this->enabled ) {
				return;
			}
			// Only auto-sync in non-SSoT mode (legacy).
			if ( ! $this->is_ssot ) {
				add_action( 'onplay_wallet_transaction_recorded', array( $this, 'sync_transaction_to_pos' ), 10, 4 );
			}
		}

		/**
		 * Check if POS integration is enabled.
		 *
		 * @return bool
		 */
		public function is_active() {
			return $this->enabled;
		}

		/**
		 * Check if POS is configured as Source of Truth.
		 *
		 * @return bool
		 */
		public function is_ssot_enabled() {
			return $this->enabled && $this->is_ssot;
		}

		/**
		 * Check if outbound API calls (WC → POS) are configured.
		 *
		 * @return bool
		 */
		public function is_outbound_configured() {
			return $this->enabled && ! empty( $this->api_url ) && ! empty( $this->api_key );
		}

		// ──────────────────────────────────────────────────────────────────
		// SSoT API methods (new wallet-connector endpoints)
		// ──────────────────────────────────────────────────────────────────

		/**
		 * Consult balance from POS (SSoT).
		 * GET /balance?email={email}
		 *
		 * @param string $email Customer email.
		 * @return array|WP_Error { email, balance, currency }
		 */
		public function get_balance( $email ) {
			return $this->request( 'GET', 'balance', null, array( 'email' => $email ) );
		}

		/**
		 * Request a debit at the POS (purchase in WooCommerce).
		 * POST /debit
		 *
		 * @param string $email       Customer email.
		 * @param float  $amount      Amount to debit.
		 * @param string $reference   Transaction reference (e.g. WC-ORDER-123).
		 * @param string $description Human-readable description.
		 * @return array|WP_Error { success, transaction_id, balance, currency }
		 */
		public function debit( $email, $amount, $reference, $description ) {
			return $this->request( 'POST', 'debit', array(
				'email'       => $email,
				'amount'      => floatval( $amount ),
				'reference'   => $reference,
				'description' => $description,
			) );
		}

		/**
		 * Request a credit at the POS (refund from WooCommerce).
		 * POST /credit
		 *
		 * @param string $email       Customer email.
		 * @param float  $amount      Amount to credit.
		 * @param string $reference   Transaction reference (e.g. WC-REFUND-456).
		 * @param string $description Human-readable description.
		 * @return array|WP_Error { success, transaction_id, balance, currency }
		 */
		public function credit( $email, $amount, $reference, $description ) {
			return $this->request( 'POST', 'credit', array(
				'email'       => $email,
				'amount'      => floatval( $amount ),
				'reference'   => $reference,
				'description' => $description,
			) );
		}

		/**
		 * Get transaction history from POS.
		 * GET /transactions?email={email}&limit={limit}&page={page}
		 *
		 * @param string $email Customer email.
		 * @param int    $limit Items per page.
		 * @param int    $page  Page number.
		 * @return array|WP_Error { transactions[], total, page, totalPages }
		 */
		public function get_transactions( $email, $limit = 20, $page = 1 ) {
			return $this->request( 'GET', 'transactions', null, array(
				'email' => $email,
				'limit' => $limit,
				'page'  => $page,
			) );
		}

		/**
		 * Look up customer in POS.
		 * GET /customer?email={email}
		 *
		 * @param string $email Customer email.
		 * @return array|WP_Error { email, name, phone, balance, currency, isActive }
		 */
		public function get_customer( $email ) {
			return $this->request( 'GET', 'customer', null, array( 'email' => $email ) );
		}

		/**
		 * Health check / ping.
		 * GET /status
		 *
		 * @return array|WP_Error { status, timestamp, version }
		 */
		public function ping() {
			return $this->request( 'GET', 'status' );
		}

		// ──────────────────────────────────────────────────────────────────
		// Core HTTP transport
		// ──────────────────────────────────────────────────────────────────

		/**
		 * Make a request to the POS wallet-connector API.
		 *
		 * Authentication: X-Onplay-Api-Key header.
		 * Transport: wp_remote_request() with HTTPS.
		 *
		 * @param string     $method   HTTP method (GET, POST).
		 * @param string     $endpoint Endpoint path relative to api_url (e.g. 'balance').
		 * @param array|null $body     Request body for POST (will be JSON encoded).
		 * @param array|null $query    Query parameters for GET.
		 * @return array|WP_Error Decoded response body or WP_Error.
		 */
		private function request( $method, $endpoint, $body = null, $query = null ) {
			if ( ! $this->is_outbound_configured() ) {
				return new WP_Error(
					'pos_not_configured',
					__( 'POS API is not configured. Set POS API URL and API Key in settings.', 'onplay-wallet' )
				);
			}

			$url = trailingslashit( $this->api_url ) . ltrim( $endpoint, '/' );

			if ( $query ) {
				$url = add_query_arg( $query, $url );
			}

			$args = array(
				'method'  => $method,
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'Accept'           => 'application/json',
					'X-Onplay-Api-Key' => $this->api_key,
				),
			);

			if ( $body ) {
				$args['body'] = wp_json_encode( $body );
			}

			$this->log( sprintf( '%s %s', $method, $url ), 'info' );

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$this->log( 'API Error: ' . $response->get_error_message(), 'error' );
				return $response;
			}

			$code         = wp_remote_retrieve_response_code( $response );
			$raw_body     = wp_remote_retrieve_body( $response );
			$decoded_body = json_decode( $raw_body, true );

			if ( $code >= 400 ) {
				$message = isset( $decoded_body['error'] ) ? $decoded_body['error'] : ( isset( $decoded_body['message'] ) ? $decoded_body['message'] : "HTTP {$code}" );
				$this->log( "API HTTP {$code}: {$message}", 'error' );
				return new WP_Error( 'pos_api_error', $message, array( 'status' => $code ) );
			}

			return $decoded_body;
		}

		// ──────────────────────────────────────────────────────────────────
		// Legacy API methods (kept for backward compatibility)
		// ──────────────────────────────────────────────────────────────────

		/**
		 * Legacy: Make a request using old HMAC auth scheme.
		 *
		 * @param string $endpoint API endpoint.
		 * @param string $method   HTTP method.
		 * @param array  $data     Request data.
		 * @return array|WP_Error
		 */
		public function api_request( $endpoint, $method = 'GET', $data = array() ) {
			if ( ! $this->is_outbound_configured() ) {
				return new WP_Error( 'onplay_pos_not_configured', __( 'OnplayPOS outbound API is not configured.', 'onplay-wallet' ) );
			}

			$url  = $this->api_url . ltrim( $endpoint, '/' );
			$args = array(
				'method'  => $method,
				'headers' => $this->get_legacy_auth_headers(),
				'timeout' => $this->timeout,
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
		 * Legacy: Get HMAC authentication headers.
		 *
		 * @return array
		 */
		private function get_legacy_auth_headers() {
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
		 * Test connectivity with the POS.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			if ( $this->is_ssot ) {
				return $this->ping();
			}
			return $this->api_request( 'api/ping' );
		}

		/**
		 * Legacy: Get balance from POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @return array|WP_Error
		 */
		public function get_pos_balance( $identifier ) {
			if ( $this->is_ssot ) {
				return $this->get_balance( $identifier );
			}
			return $this->api_request( 'api/wallet/balance', 'GET', array( 'identifier' => $identifier ) );
		}

		/**
		 * Legacy: Send a credit transaction to POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param float  $amount     Amount to credit.
		 * @param string $reference  Transaction reference.
		 * @param array  $meta       Additional meta data.
		 * @return array|WP_Error
		 */
		public function pos_credit( $identifier, $amount, $reference = '', $meta = array() ) {
			if ( $this->is_ssot ) {
				$description = isset( $meta['description'] ) ? $meta['description'] : '';
				return $this->credit( $identifier, $amount, $reference, $description );
			}
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
		 * Legacy: Send a debit transaction to POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param float  $amount     Amount to debit.
		 * @param string $reference  Transaction reference.
		 * @param array  $meta       Additional meta data.
		 * @return array|WP_Error
		 */
		public function pos_debit( $identifier, $amount, $reference = '', $meta = array() ) {
			if ( $this->is_ssot ) {
				$description = isset( $meta['description'] ) ? $meta['description'] : '';
				return $this->debit( $identifier, $amount, $reference, $description );
			}
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
		 * Legacy: Get transaction history from POS.
		 *
		 * @param string $identifier User email or POS customer ID.
		 * @param int    $page       Page number.
		 * @param int    $per_page   Items per page.
		 * @return array|WP_Error
		 */
		public function get_pos_transactions( $identifier, $page = 1, $per_page = 20 ) {
			if ( $this->is_ssot ) {
				return $this->get_transactions( $identifier, $per_page, $page );
			}
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
		 * @param string $qr_code QR code data.
		 * @param float  $amount  Payment amount.
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
		 * @param string $qr_code   QR code data.
		 * @param float  $amount    Payment amount.
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
				return new WP_Error( 'invalid_user', __( 'Invalid user.', 'onplay-wallet' ) );
			}

			return $this->api_request(
				'api/customers/register',
				'POST',
				array(
					'email'       => $user->user_email,
					'first_name'  => $user->first_name,
					'last_name'   => $user->last_name,
					'phone'       => get_user_meta( $user_id, 'billing_phone', true ),
					'source'      => 'woocommerce',
					'external_id' => $user_id,
				)
			);
		}

		/**
		 * Sync local wallet transaction to POS after recording (legacy mode only).
		 *
		 * @param int    $transaction_id Transaction ID.
		 * @param int    $user_id        User ID.
		 * @param float  $amount         Amount.
		 * @param string $type           credit or debit.
		 */
		public function sync_transaction_to_pos( $transaction_id, $user_id, $amount, $type ) {
			if ( ! $this->is_outbound_configured() ) {
				return;
			}

			$pos_settings = get_option( '_wallet_settings_pos', array() );
			$sync_enabled = isset( $pos_settings['pos_auto_sync'] ) && 'on' === $pos_settings['pos_auto_sync'];

			if ( ! $sync_enabled ) {
				return;
			}

			// Avoid infinite loop: don't sync transactions that originated from POS.
			$source = get_wallet_transaction_meta( $transaction_id, '_onplay_source', '' );
			if ( 'pos' === $source || 'pos_ssot' === $source ) {
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
				return new WP_Error( 'invalid_user', __( 'Invalid user.', 'onplay-wallet' ) );
			}

			$balance   = onplay_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
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
		 * Log POS connector messages via WC_Logger.
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
