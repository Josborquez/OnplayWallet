<?php
/**
 * OnplayPOS REST API controller
 *
 * Handles requests from OnplayPOS to OnplayWallet.
 * Provides endpoints for balance queries, transactions, QR payments, and webhooks.
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OnplayPOS_REST_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'onplay/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'pos';

	/**
	 * Register the routes.
	 */
	public function register_routes() {

		// GET /onplay/v1/pos/balance - Get wallet balance by email or user ID.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/balance',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_balance' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'email'   => array(
							'type'              => 'string',
							'description'       => __( 'Customer email address.', 'woo-wallet' ),
							'sanitize_callback' => 'sanitize_email',
						),
						'user_id' => array(
							'type'              => 'integer',
							'description'       => __( 'WordPress user ID.', 'woo-wallet' ),
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// POST /onplay/v1/pos/credit - Credit a customer wallet from POS.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pos_credit' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'email'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'amount'    => array(
							'required' => true,
							'type'     => 'number',
						),
						'reference' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'note'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
			)
		);

		// POST /onplay/v1/pos/debit - Debit from customer wallet (POS sale).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/debit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pos_debit' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'email'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'amount'    => array(
							'required' => true,
							'type'     => 'number',
						),
						'reference' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'note'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
			)
		);

		// GET /onplay/v1/pos/transactions - Get transaction history.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/transactions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_transactions' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'email'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
					),
				),
			)
		);

		// POST /onplay/v1/pos/qr-pay - Process a QR code payment from POS terminal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/qr-pay',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_qr_payment' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'qr_data'  => array(
							'required' => true,
							'type'     => 'string',
						),
						'amount'   => array(
							'required' => true,
							'type'     => 'number',
						),
						'terminal' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'reference' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
					),
				),
			)
		);

		// GET /onplay/v1/pos/customer - Lookup customer by email or phone.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customer',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'lookup_customer' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
					'args'                => array(
						'email' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'phone' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /onplay/v1/pos/webhook - Receive webhook events from POS.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/webhook',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_webhook' ),
					'permission_callback' => array( $this, 'check_webhook_signature' ),
				),
			)
		);

		// GET /onplay/v1/pos/status - Connection health check.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_pos_permissions' ),
				),
			)
		);
	}

	/**
	 * Check POS API permissions.
	 * Supports WooCommerce API keys and custom API key header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_pos_permissions( $request ) {
		// Check for OnplayPOS API key in header.
		$api_key = $request->get_header( 'X-Onplay-Api-Key' );
		if ( $api_key ) {
			$pos_settings   = get_option( '_wallet_settings_pos', array() );
			$stored_api_key = isset( $pos_settings['pos_api_key'] ) ? $pos_settings['pos_api_key'] : '';
			if ( ! empty( $stored_api_key ) && hash_equals( $stored_api_key, $api_key ) ) {
				return true;
			}
		}

		// Fall back to WooCommerce REST API authentication.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error(
			'onplay_rest_unauthorized',
			__( 'Authentication required. Provide X-Onplay-Api-Key header or WooCommerce API credentials.', 'woo-wallet' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Validate webhook signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_webhook_signature( $request ) {
		$pos_settings   = get_option( '_wallet_settings_pos', array() );
		$webhook_secret = isset( $pos_settings['pos_webhook_secret'] ) ? $pos_settings['pos_webhook_secret'] : '';

		if ( empty( $webhook_secret ) ) {
			return new WP_Error( 'onplay_webhook_not_configured', __( 'Webhook secret not configured.', 'woo-wallet' ), array( 'status' => 500 ) );
		}

		$signature = $request->get_header( 'X-Onplay-Signature' );
		if ( empty( $signature ) ) {
			return new WP_Error( 'onplay_webhook_no_signature', __( 'Missing webhook signature.', 'woo-wallet' ), array( 'status' => 401 ) );
		}

		$body            = $request->get_body();
		$expected_sig    = hash_hmac( 'sha256', $body, $webhook_secret );

		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return new WP_Error( 'onplay_webhook_invalid_signature', __( 'Invalid webhook signature.', 'woo-wallet' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Resolve user from email or user_id parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_User|WP_Error
	 */
	private function resolve_user( $request ) {
		$email   = $request->get_param( 'email' );
		$user_id = $request->get_param( 'user_id' );

		if ( $email ) {
			$user = get_user_by( 'email', $email );
		} elseif ( $user_id ) {
			$user = get_user_by( 'id', $user_id );
		} else {
			return new WP_Error( 'onplay_missing_identifier', __( 'Provide email or user_id.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		if ( ! $user ) {
			return new WP_Error( 'onplay_user_not_found', __( 'Customer not found.', 'woo-wallet' ), array( 'status' => 404 ) );
		}

		return $user;
	}

	/**
	 * GET /onplay/v1/pos/balance
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_balance( $request ) {
		$user = $this->resolve_user( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'user_id'  => $user->ID,
				'email'    => $user->user_email,
				'balance'  => floatval( $balance ),
				'currency' => get_woocommerce_currency(),
				'locked'   => is_wallet_account_locked( $user->ID ),
			),
			200
		);
	}

	/**
	 * POST /onplay/v1/pos/credit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pos_credit( $request ) {
		$user = $this->resolve_user( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( is_wallet_account_locked( $user->ID ) ) {
			return new WP_Error( 'onplay_wallet_locked', __( 'Customer wallet is locked.', 'woo-wallet' ), array( 'status' => 403 ) );
		}

		$amount    = floatval( $request->get_param( 'amount' ) );
		$reference = $request->get_param( 'reference' );
		$note      = $request->get_param( 'note' );

		if ( $amount <= 0 ) {
			return new WP_Error( 'onplay_invalid_amount', __( 'Amount must be greater than zero.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		$details = __( 'Credit from OnplayPOS', 'woo-wallet' );
		if ( $reference ) {
			$details .= ' #' . $reference;
		}
		if ( $note ) {
			$details .= ' - ' . $note;
		}

		$transaction_id = woo_wallet()->wallet->credit( $user->ID, $amount, $details );

		if ( ! $transaction_id ) {
			return new WP_Error( 'onplay_transaction_failed', __( 'Transaction could not be processed.', 'woo-wallet' ), array( 'status' => 500 ) );
		}

		// Mark as POS-originated to prevent sync loop.
		update_wallet_transaction_meta( $transaction_id, '_onplay_source', 'pos', $user->ID );
		if ( $reference ) {
			update_wallet_transaction_meta( $transaction_id, '_pos_reference', $reference, $user->ID );
		}

		$new_balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'type'           => 'credit',
				'amount'         => $amount,
				'new_balance'    => floatval( $new_balance ),
				'currency'       => get_woocommerce_currency(),
				'reference'      => $reference,
			),
			200
		);
	}

	/**
	 * POST /onplay/v1/pos/debit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pos_debit( $request ) {
		$user = $this->resolve_user( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( is_wallet_account_locked( $user->ID ) ) {
			return new WP_Error( 'onplay_wallet_locked', __( 'Customer wallet is locked.', 'woo-wallet' ), array( 'status' => 403 ) );
		}

		$amount    = floatval( $request->get_param( 'amount' ) );
		$reference = $request->get_param( 'reference' );
		$note      = $request->get_param( 'note' );

		if ( $amount <= 0 ) {
			return new WP_Error( 'onplay_invalid_amount', __( 'Amount must be greater than zero.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Check sufficient balance.
		$current_balance = floatval( woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' ) );
		if ( $amount > $current_balance ) {
			return new WP_Error(
				'onplay_insufficient_balance',
				sprintf(
					/* translators: %s: current balance */
					__( 'Insufficient wallet balance. Current balance: %s', 'woo-wallet' ),
					wc_price( $current_balance )
				),
				array(
					'status'          => 400,
					'current_balance' => $current_balance,
					'requested'       => $amount,
				)
			);
		}

		$details = __( 'POS payment via OnplayPOS', 'woo-wallet' );
		if ( $reference ) {
			$details .= ' #' . $reference;
		}
		if ( $note ) {
			$details .= ' - ' . $note;
		}

		$transaction_id = woo_wallet()->wallet->debit( $user->ID, $amount, $details );

		if ( ! $transaction_id ) {
			return new WP_Error( 'onplay_transaction_failed', __( 'Transaction could not be processed.', 'woo-wallet' ), array( 'status' => 500 ) );
		}

		// Mark as POS-originated.
		update_wallet_transaction_meta( $transaction_id, '_onplay_source', 'pos', $user->ID );
		if ( $reference ) {
			update_wallet_transaction_meta( $transaction_id, '_pos_reference', $reference, $user->ID );
		}

		$new_balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'type'           => 'debit',
				'amount'         => $amount,
				'new_balance'    => floatval( $new_balance ),
				'currency'       => get_woocommerce_currency(),
				'reference'      => $reference,
			),
			200
		);
	}

	/**
	 * GET /onplay/v1/pos/transactions
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions( $request ) {
		$user = $this->resolve_user( $request );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = absint( $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		$args = array(
			'user_id' => $user->ID,
			'fields'  => 'all_with_meta',
			'nocache' => true,
			'limit'   => "{$offset}, {$per_page}",
		);

		$transactions = get_wallet_transactions( $args );
		$total        = get_wallet_transactions_count( $user->ID );

		$formatted = array();
		foreach ( $transactions as $txn ) {
			$formatted[] = array(
				'transaction_id' => intval( $txn->transaction_id ),
				'type'           => $txn->type,
				'amount'         => floatval( $txn->amount ),
				'balance'        => floatval( $txn->balance ),
				'currency'       => $txn->currency,
				'details'        => $txn->details,
				'date'           => $txn->date,
				'source'         => get_wallet_transaction_meta( $txn->transaction_id, '_onplay_source', 'woocommerce' ),
				'pos_reference'  => get_wallet_transaction_meta( $txn->transaction_id, '_pos_reference', '' ),
			);
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'transactions' => $formatted,
				'total'        => intval( $total ),
				'page'         => $page,
				'per_page'     => $per_page,
				'total_pages'  => ceil( intval( $total ) / max( 1, $per_page ) ),
			),
			200
		);
	}

	/**
	 * POST /onplay/v1/pos/qr-pay - Process QR code payment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_qr_payment( $request ) {
		$pos_settings = get_option( '_wallet_settings_pos', array() );
		if ( ! isset( $pos_settings['pos_enable_qr'] ) || 'on' !== $pos_settings['pos_enable_qr'] ) {
			return new WP_Error( 'onplay_qr_disabled', __( 'QR payments are not enabled.', 'woo-wallet' ), array( 'status' => 403 ) );
		}

		$qr_data   = $request->get_param( 'qr_data' );
		$amount    = floatval( $request->get_param( 'amount' ) );
		$terminal  = $request->get_param( 'terminal' );
		$reference = $request->get_param( 'reference' );

		// Decode and validate QR payload.
		$payload = json_decode( $qr_data, true );
		if ( ! $payload || ! isset( $payload['source'] ) || 'onplay_wallet' !== $payload['source'] ) {
			return new WP_Error( 'onplay_invalid_qr', __( 'Invalid or unrecognized QR code.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Validate timestamp (QR codes expire after 5 minutes).
		if ( isset( $payload['timestamp'] ) && ( time() - intval( $payload['timestamp'] ) ) > 300 ) {
			return new WP_Error( 'onplay_qr_expired', __( 'QR code has expired. Please generate a new one.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Validate token.
		$api_secret    = isset( $pos_settings['pos_api_secret'] ) ? $pos_settings['pos_api_secret'] : wp_salt( 'auth' );
		$expected_token = hash_hmac( 'sha256', $payload['email'] . '|' . $payload['timestamp'], $api_secret );
		if ( ! isset( $payload['token'] ) || ! hash_equals( $expected_token, $payload['token'] ) ) {
			return new WP_Error( 'onplay_qr_invalid_token', __( 'QR code validation failed.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Resolve user.
		$user = get_user_by( 'email', $payload['email'] );
		if ( ! $user ) {
			return new WP_Error( 'onplay_user_not_found', __( 'Customer not found.', 'woo-wallet' ), array( 'status' => 404 ) );
		}

		if ( is_wallet_account_locked( $user->ID ) ) {
			return new WP_Error( 'onplay_wallet_locked', __( 'Customer wallet is locked.', 'woo-wallet' ), array( 'status' => 403 ) );
		}

		if ( $amount <= 0 ) {
			return new WP_Error( 'onplay_invalid_amount', __( 'Amount must be greater than zero.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Check balance.
		$balance = floatval( woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' ) );
		if ( $amount > $balance ) {
			return new WP_Error(
				'onplay_insufficient_balance',
				__( 'Insufficient wallet balance for this payment.', 'woo-wallet' ),
				array(
					'status'          => 400,
					'current_balance' => $balance,
					'requested'       => $amount,
				)
			);
		}

		$details = __( 'QR payment at POS', 'woo-wallet' );
		if ( $terminal ) {
			$details .= ' (' . $terminal . ')';
		}
		if ( $reference ) {
			$details .= ' #' . $reference;
		}

		$transaction_id = woo_wallet()->wallet->debit( $user->ID, $amount, $details );

		if ( ! $transaction_id ) {
			return new WP_Error( 'onplay_transaction_failed', __( 'Payment could not be processed.', 'woo-wallet' ), array( 'status' => 500 ) );
		}

		update_wallet_transaction_meta( $transaction_id, '_onplay_source', 'pos_qr', $user->ID );
		update_wallet_transaction_meta( $transaction_id, '_pos_terminal', $terminal, $user->ID );
		if ( $reference ) {
			update_wallet_transaction_meta( $transaction_id, '_pos_reference', $reference, $user->ID );
		}

		$new_balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'type'           => 'debit',
				'amount'         => $amount,
				'new_balance'    => floatval( $new_balance ),
				'currency'       => get_woocommerce_currency(),
				'customer_email' => $user->user_email,
				'customer_name'  => $user->display_name,
				'terminal'       => $terminal,
				'reference'      => $reference,
			),
			200
		);
	}

	/**
	 * GET /onplay/v1/pos/customer - Look up customer info.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup_customer( $request ) {
		$email = $request->get_param( 'email' );
		$phone = $request->get_param( 'phone' );

		$user = null;

		if ( $email ) {
			$user = get_user_by( 'email', $email );
		} elseif ( $phone ) {
			// Search by billing phone.
			$users = get_users(
				array(
					'meta_key'   => 'billing_phone',
					'meta_value' => $phone,
					'number'     => 1,
				)
			);
			if ( ! empty( $users ) ) {
				$user = $users[0];
			}
		}

		if ( ! $user ) {
			return new WP_Error( 'onplay_user_not_found', __( 'Customer not found.', 'woo-wallet' ), array( 'status' => 404 ) );
		}

		$balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'user_id'    => $user->ID,
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'phone'      => get_user_meta( $user->ID, 'billing_phone', true ),
				'balance'    => floatval( $balance ),
				'currency'   => get_woocommerce_currency(),
				'locked'     => is_wallet_account_locked( $user->ID ),
				'registered' => $user->user_registered,
			),
			200
		);
	}

	/**
	 * POST /onplay/v1/pos/webhook - Handle POS webhook events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( $request ) {
		$body  = json_decode( $request->get_body(), true );
		$event = isset( $body['event'] ) ? sanitize_text_field( $body['event'] ) : '';

		if ( empty( $event ) ) {
			return new WP_Error( 'onplay_webhook_no_event', __( 'Missing event type.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		$pos_settings    = get_option( '_wallet_settings_pos', array() );
		$sync_direction  = isset( $pos_settings['pos_sync_direction'] ) ? $pos_settings['pos_sync_direction'] : 'both';

		// Only process incoming POS events if sync direction allows it.
		if ( 'wc_to_pos' === $sync_direction ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'POS-to-WC sync is disabled. Event ignored.',
				),
				200
			);
		}

		switch ( $event ) {
			case 'wallet.credit':
				return $this->handle_webhook_credit( $body );

			case 'wallet.debit':
				return $this->handle_webhook_debit( $body );

			case 'customer.created':
				return $this->handle_webhook_customer_created( $body );

			case 'ping':
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'pong',
						'version' => ONPLAY_WALLET_VERSION,
					),
					200
				);

			default:
				do_action( 'onplay_pos_webhook_' . $event, $body );
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Event received.',
					),
					200
				);
		}
	}

	/**
	 * Handle wallet credit webhook from POS.
	 *
	 * @param array $data Webhook data.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_webhook_credit( $data ) {
		$email     = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		$amount    = isset( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
		$reference = isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '';

		if ( ! $email || $amount <= 0 ) {
			return new WP_Error( 'onplay_webhook_invalid_data', __( 'Invalid webhook data.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'onplay_user_not_found', __( 'Customer not found.', 'woo-wallet' ), array( 'status' => 404 ) );
		}

		// Check for duplicate webhook processing.
		if ( $reference ) {
			global $wpdb;
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT tm.transaction_id FROM {$wpdb->base_prefix}woo_wallet_transaction_meta tm WHERE tm.meta_key = '_pos_reference' AND tm.meta_value = %s LIMIT 1",
					$reference
				)
			);
			if ( $existing ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Duplicate webhook - already processed.',
						'transaction_id' => intval( $existing ),
					),
					200
				);
			}
		}

		$details        = __( 'Credit from OnplayPOS', 'woo-wallet' );
		if ( $reference ) {
			$details .= ' #' . $reference;
		}

		$transaction_id = woo_wallet()->wallet->credit( $user->ID, $amount, $details );
		if ( $transaction_id ) {
			update_wallet_transaction_meta( $transaction_id, '_onplay_source', 'pos', $user->ID );
			if ( $reference ) {
				update_wallet_transaction_meta( $transaction_id, '_pos_reference', $reference, $user->ID );
			}
		}

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
			),
			200
		);
	}

	/**
	 * Handle wallet debit webhook from POS.
	 *
	 * @param array $data Webhook data.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_webhook_debit( $data ) {
		$email     = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		$amount    = isset( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
		$reference = isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '';

		if ( ! $email || $amount <= 0 ) {
			return new WP_Error( 'onplay_webhook_invalid_data', __( 'Invalid webhook data.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'onplay_user_not_found', __( 'Customer not found.', 'woo-wallet' ), array( 'status' => 404 ) );
		}

		// Duplicate check.
		if ( $reference ) {
			global $wpdb;
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT tm.transaction_id FROM {$wpdb->base_prefix}woo_wallet_transaction_meta tm WHERE tm.meta_key = '_pos_reference' AND tm.meta_value = %s LIMIT 1",
					$reference
				)
			);
			if ( $existing ) {
				return new WP_REST_Response(
					array(
						'success'        => true,
						'message'        => 'Duplicate webhook - already processed.',
						'transaction_id' => intval( $existing ),
					),
					200
				);
			}
		}

		$details = __( 'POS payment via OnplayPOS', 'woo-wallet' );
		if ( $reference ) {
			$details .= ' #' . $reference;
		}

		$transaction_id = woo_wallet()->wallet->debit( $user->ID, $amount, $details );
		if ( $transaction_id ) {
			update_wallet_transaction_meta( $transaction_id, '_onplay_source', 'pos', $user->ID );
			if ( $reference ) {
				update_wallet_transaction_meta( $transaction_id, '_pos_reference', $reference, $user->ID );
			}
		}

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transaction_id' => $transaction_id,
			),
			200
		);
	}

	/**
	 * Handle customer created webhook from POS.
	 *
	 * @param array $data Webhook data.
	 * @return WP_REST_Response
	 */
	private function handle_webhook_customer_created( $data ) {
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( ! $email ) {
			return new WP_Error( 'onplay_webhook_invalid_data', __( 'Missing customer email.', 'woo-wallet' ), array( 'status' => 400 ) );
		}

		// Check if customer already exists.
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Customer already exists.',
					'user_id' => $user->ID,
				),
				200
			);
		}

		// Create new WooCommerce customer.
		$first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
		$last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
		$phone      = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';
		$password   = wp_generate_password( 12, true );

		$customer = new WC_Customer();
		$customer->set_email( $email );
		$customer->set_username( sanitize_user( $email ) );
		$customer->set_password( $password );
		$customer->set_first_name( $first_name );
		$customer->set_last_name( $last_name );
		$customer->set_billing_first_name( $first_name );
		$customer->set_billing_last_name( $last_name );
		$customer->set_billing_email( $email );
		if ( $phone ) {
			$customer->set_billing_phone( $phone );
		}
		$customer_id = $customer->save();

		if ( ! $customer_id ) {
			return new WP_Error( 'onplay_customer_creation_failed', __( 'Failed to create customer.', 'woo-wallet' ), array( 'status' => 500 ) );
		}

		update_user_meta( $customer_id, '_onplay_pos_customer', true );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Customer created.',
				'user_id' => $customer_id,
			),
			201
		);
	}

	/**
	 * GET /onplay/v1/pos/status - Health check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		$pos_active = woo_wallet()->pos_connector->is_active();

		$response = array(
			'success'            => true,
			'plugin'             => 'OnplayWallet',
			'version'            => ONPLAY_WALLET_VERSION,
			'woocommerce'        => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			'wordpress'          => get_bloginfo( 'version' ),
			'pos_configured'     => $pos_active,
			'currency'           => get_woocommerce_currency(),
			'webhook_url'        => rest_url( $this->namespace . '/' . $this->rest_base . '/webhook' ),
			'timestamp'          => current_time( 'mysql' ),
		);

		// Test POS connection if configured.
		if ( $pos_active ) {
			$test = woo_wallet()->pos_connector->test_connection();
			$response['pos_connection'] = is_wp_error( $test ) ? 'error' : 'ok';
			if ( is_wp_error( $test ) ) {
				$response['pos_error'] = $test->get_error_message();
			}
		}

		return new WP_REST_Response( $response, 200 );
	}
}
