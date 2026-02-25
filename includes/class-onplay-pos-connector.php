<?php
/**
 * OnplayPOS Connector - Manages integration between OnplayWallet and OnplayPOS (React)
 *
 * Architecture: OnplayPOS (React frontend at onplaypos.onplaygames.cl) calls
 * the WordPress REST API endpoints exposed by this plugin. This class manages
 * the configuration, API key generation, CORS, and QR code generation.
 *
 * Flow: OnplayPOS (React) → HTTP requests → OnplayWallet (WP REST API)
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OnplayPOS_Connector' ) ) {

	class OnplayPOS_Connector {

		/**
		 * POS origin URL (React app).
		 *
		 * @var string
		 */
		private $pos_url = '';

		/**
		 * Generated API key for POS to authenticate with WordPress.
		 *
		 * @var string
		 */
		private $api_key = '';

		/**
		 * Secret used for QR code token signing and webhook validation.
		 *
		 * @var string
		 */
		private $signing_secret = '';

		/**
		 * Whether POS integration is enabled.
		 *
		 * @var bool
		 */
		private $enabled = false;

		/**
		 * Allowed CORS origins for POS.
		 *
		 * @var array
		 */
		private $allowed_origins = array();

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
			$pos_settings         = get_option( '_wallet_settings_pos', array() );
			$this->pos_url        = isset( $pos_settings['pos_api_url'] ) ? untrailingslashit( $pos_settings['pos_api_url'] ) : '';
			$this->api_key        = get_option( '_onplay_pos_api_key', '' );
			$this->signing_secret = get_option( '_onplay_pos_signing_secret', '' );
			$this->enabled        = isset( $pos_settings['pos_enable'] ) && 'on' === $pos_settings['pos_enable'];

			// Build allowed origins list.
			$this->allowed_origins = array(
				'https://onplaypos.onplaygames.cl',
				'https://onplay.cl',
				'https://www.onplay.cl',
				'https://onplaygames.cl',
				'https://www.onplaygames.cl',
			);
			if ( ! empty( $this->pos_url ) && ! in_array( $this->pos_url, $this->allowed_origins, true ) ) {
				$this->allowed_origins[] = $this->pos_url;
			}
		}

		/**
		 * Initialize hooks.
		 */
		private function init_hooks() {
			// Always add CORS support for POS endpoints, even if not fully enabled.
			add_action( 'rest_api_init', array( $this, 'add_cors_support' ), 5 );
			add_filter( 'rest_pre_serve_request', array( $this, 'handle_cors_preflight' ), 10, 4 );
		}

		/**
		 * Check if POS integration is enabled.
		 *
		 * @return bool
		 */
		public function is_active() {
			return $this->enabled && ! empty( $this->api_key );
		}

		/**
		 * Get the POS URL.
		 *
		 * @return string
		 */
		public function get_pos_url() {
			return $this->pos_url;
		}

		/**
		 * Get the API key (for display - masked).
		 *
		 * @return string
		 */
		public function get_api_key_masked() {
			if ( empty( $this->api_key ) ) {
				return '';
			}
			return str_repeat( '*', max( 0, strlen( $this->api_key ) - 8 ) ) . substr( $this->api_key, -8 );
		}

		/**
		 * Get the full API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			return $this->api_key;
		}

		/**
		 * Get the signing secret.
		 *
		 * @return string
		 */
		public function get_signing_secret() {
			return $this->signing_secret;
		}

		/**
		 * Generate a new API key pair for POS authentication.
		 * The POS (React) uses this key in the X-Onplay-Api-Key header.
		 *
		 * @return array Array with 'api_key' and 'signing_secret'.
		 */
		public function generate_api_credentials() {
			$api_key        = 'onplay_' . bin2hex( random_bytes( 24 ) );
			$signing_secret = bin2hex( random_bytes( 32 ) );

			update_option( '_onplay_pos_api_key', $api_key );
			update_option( '_onplay_pos_signing_secret', $signing_secret );
			update_option( '_onplay_pos_key_generated_at', current_time( 'mysql' ) );

			$this->api_key        = $api_key;
			$this->signing_secret = $signing_secret;

			$this->log( 'New API credentials generated.' );

			return array(
				'api_key'        => $api_key,
				'signing_secret' => $signing_secret,
			);
		}

		/**
		 * Revoke current API credentials.
		 */
		public function revoke_api_credentials() {
			delete_option( '_onplay_pos_api_key' );
			delete_option( '_onplay_pos_signing_secret' );
			delete_option( '_onplay_pos_key_generated_at' );

			$this->api_key        = '';
			$this->signing_secret = '';

			$this->log( 'API credentials revoked.' );
		}

		/**
		 * Validate an incoming API key from POS request.
		 *
		 * @param string $provided_key The key from the request header.
		 * @return bool
		 */
		public function validate_api_key( $provided_key ) {
			if ( empty( $this->api_key ) || empty( $provided_key ) ) {
				return false;
			}
			return hash_equals( $this->api_key, $provided_key );
		}

		/**
		 * Add CORS headers for POS REST API requests.
		 */
		public function add_cors_support() {
			// Only for our onplay endpoints.
			add_filter( 'rest_post_dispatch', array( $this, 'add_cors_headers' ), 10, 3 );
		}

		/**
		 * Add CORS headers to REST API responses for OnplayPOS endpoints.
		 *
		 * @param WP_REST_Response $response Response.
		 * @param WP_REST_Server   $server   Server.
		 * @param WP_REST_Request  $request  Request.
		 * @return WP_REST_Response
		 */
		public function add_cors_headers( $response, $server, $request ) {
			$route = $request->get_route();

			// Only add CORS headers to our endpoints.
			if ( strpos( $route, '/onplay/v1/' ) !== 0 && strpos( $route, '/wp/v2/wallet' ) !== 0 && strpos( $route, '/wc/' ) !== 0 ) {
				return $response;
			}

			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

			if ( ! empty( $origin ) && in_array( $origin, $this->allowed_origins, true ) ) {
				$response->header( 'Access-Control-Allow-Origin', $origin );
				$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
				$response->header( 'Access-Control-Allow-Headers', 'Content-Type, X-Onplay-Api-Key, X-Onplay-Signature, Authorization' );
				$response->header( 'Access-Control-Allow-Credentials', 'true' );
				$response->header( 'Access-Control-Max-Age', '86400' );
			}

			return $response;
		}

		/**
		 * Handle CORS preflight (OPTIONS) requests for POS.
		 *
		 * @param bool             $served  Whether the request was served.
		 * @param WP_HTTP_Response $result  Result.
		 * @param WP_REST_Request  $request Request.
		 * @param WP_REST_Server   $server  Server.
		 * @return bool
		 */
		public function handle_cors_preflight( $served, $result, $request, $server ) {
			if ( 'OPTIONS' !== $request->get_method() ) {
				return $served;
			}

			$route = $request->get_route();
			if ( strpos( $route, '/onplay/v1/' ) !== 0 ) {
				return $served;
			}

			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

			if ( ! empty( $origin ) && in_array( $origin, $this->allowed_origins, true ) ) {
				$response = new WP_REST_Response();
				$response->header( 'Access-Control-Allow-Origin', $origin );
				$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
				$response->header( 'Access-Control-Allow-Headers', 'Content-Type, X-Onplay-Api-Key, X-Onplay-Signature, Authorization' );
				$response->header( 'Access-Control-Allow-Credentials', 'true' );
				$response->header( 'Access-Control-Max-Age', '86400' );
				$response->set_status( 200 );
				$server->send_headers( $response->get_headers() );
				echo '{}';
				return true;
			}

			return $served;
		}

		/**
		 * Generate a wallet QR code payload for a user.
		 * This is displayed in the customer's wallet page.
		 * The POS scans this QR and calls /onplay/v1/pos/qr-pay.
		 *
		 * @param int $user_id WordPress user ID.
		 * @return string|WP_Error QR payload JSON or error.
		 */
		public function generate_wallet_qr( $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return new WP_Error( 'invalid_user', __( 'Invalid user.', 'woo-wallet' ) );
			}

			$balance   = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
			$timestamp = time();
			$secret    = $this->signing_secret ?: wp_salt( 'auth' );
			$token     = hash_hmac( 'sha256', $user->user_email . '|' . $timestamp, $secret );

			$payload = array(
				'source'    => 'onplay_wallet',
				'email'     => $user->user_email,
				'user_id'   => $user_id,
				'balance'   => $balance,
				'currency'  => get_woocommerce_currency(),
				'timestamp' => $timestamp,
				'token'     => $token,
				'site'      => home_url(),
			);

			return wp_json_encode( $payload );
		}

		/**
		 * Validate a QR token.
		 *
		 * @param string $email     User email.
		 * @param int    $timestamp QR generation timestamp.
		 * @param string $token     HMAC token.
		 * @return bool
		 */
		public function validate_qr_token( $email, $timestamp, $token ) {
			$secret        = $this->signing_secret ?: wp_salt( 'auth' );
			$expected_token = hash_hmac( 'sha256', $email . '|' . $timestamp, $secret );
			return hash_equals( $expected_token, $token );
		}

		/**
		 * Get integration status info.
		 *
		 * @return array
		 */
		public function get_status() {
			$key_generated_at = get_option( '_onplay_pos_key_generated_at', '' );

			return array(
				'enabled'          => $this->enabled,
				'active'           => $this->is_active(),
				'pos_url'          => $this->pos_url,
				'has_api_key'      => ! empty( $this->api_key ),
				'api_key_masked'   => $this->get_api_key_masked(),
				'key_generated_at' => $key_generated_at,
				'allowed_origins'  => $this->allowed_origins,
				'wallet_api_base'  => rest_url( 'onplay/v1/pos/' ),
			);
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
