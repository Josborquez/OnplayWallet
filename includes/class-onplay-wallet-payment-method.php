<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( class_exists( 'WC_Payment_Gateway' ) ) {

	class Woo_Gateway_Wallet_payment extends WC_Payment_Gateway {

		/**
		 * Class constructor
		 */
		public function __construct() {
			$this->setup_properties();
			$this->supports = array(
				'products',
				'refunds',
				'subscriptions',
				'multiple_subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
			);
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
			// Get settings.
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			/* support for woocommerce subscription plugin */
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

			add_action( 'woocommerce_pre_payment_complete', array( $this, 'woocommerce_pre_payment_complete' ) );
		}

		/**
		 * Setup general properties for the gateway.
		 */
		protected function setup_properties() {
			$this->id                 = 'wallet';
			$this->method_title       = __( 'Wallet', 'onplay-wallet' );
			$this->method_description = __( 'Have your customers pay with wallet.', 'onplay-wallet' );
			$this->has_fields         = false;
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'      => array(
					'title'       => __( 'Enable/Disable', 'onplay-wallet' ),
					'label'       => __( 'Enable wallet payments', 'onplay-wallet' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'yes',
				),
				'title'        => array(
					'title'       => __( 'Title', 'onplay-wallet' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'onplay-wallet' ),
					'default'     => __( 'Wallet payment', 'onplay-wallet' ),
					'desc_tip'    => true,
				),
				'description'  => array(
					'title'       => __( 'Description', 'onplay-wallet' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'onplay-wallet' ),
					'default'     => __( 'Pay with wallet.', 'onplay-wallet' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instructions', 'onplay-wallet' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page.', 'onplay-wallet' ),
					'default'     => __( 'Pay with wallet.', 'onplay-wallet' ),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Is gateway available
		 *
		 * @return boolean
		 */
		public function is_available() {
			if ( is_checkout() ) {
				$connector = onplay_wallet()->pos_connector;

				// When POS is SSoT, use cached balance (updated by webhooks) for availability check.
				if ( $connector->is_ssot_enabled() && $connector->is_outbound_configured() ) {
					$cache_balance = floatval( get_user_meta( get_current_user_id(), '_current_onplay_wallet_balance', true ) );
					$cart_total    = 0;
					if ( WC()->cart ) {
						$cart_total = floatval( WC()->cart->get_total( 'edit' ) );
					}
					$has_balance = ( $cache_balance >= $cart_total && $cart_total > 0 );
					return apply_filters( 'onplay_wallet_payment_is_available', ( parent::is_available() && $has_balance && is_user_logged_in() && ! is_enable_wallet_partial_payment() && ! is_wallet_account_locked() ) );
				}

				return apply_filters( 'onplay_wallet_payment_is_available', ( parent::is_available() && is_full_payment_through_wallet() && is_user_logged_in() && ! is_enable_wallet_partial_payment() && ! is_wallet_account_locked() ) );
			}
			return parent::is_available();
		}
		/**
		 * Display wallet balance as Icon.
		 *
		 * @return string
		 */
		public function get_icon() {
			$connector = onplay_wallet()->pos_connector;
			if ( $connector->is_ssot_enabled() ) {
				$cache_balance = floatval( get_user_meta( get_current_user_id(), '_current_onplay_wallet_balance', true ) );
				$current_balance = wc_price( $cache_balance, onplay_wallet_wc_price_args( get_current_user_id() ) );
			} else {
				$current_balance = onplay_wallet()->wallet->get_wallet_balance( get_current_user_id() );
			}
			/* translators: 1: wallet amount */
			return apply_filters( 'woocommerce_gateway_icon', sprintf( __( ' | Current Balance: <strong>%s</strong>', 'onplay-wallet' ), $current_balance ), $this->id );
		}

		/**
		 * Is $order_id a subscription?
		 *
		 * @param  int $order_id order_id.
		 * @return boolean
		 */
		protected function is_subscription( $order_id ) {
			return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
		}

		/**
		 * Process wallet payment
		 *
		 * @param int $order_id order_id.
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$connector = onplay_wallet()->pos_connector;

			// ── POS SSoT mode: query and debit via POS API ──
			if ( $connector->is_ssot_enabled() && $connector->is_outbound_configured() ) {
				return $this->process_payment_pos_ssot( $order, $connector );
			}

			// ── Legacy mode: local wallet balance ──
			if ( ( $order->get_total( 'edit' ) > onplay_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' ) ) && apply_filters( 'onplay_wallet_disallow_negative_transaction', ( onplay_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' ) <= 0 || $order->get_total( 'edit' ) > onplay_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' ) ), $order->get_total( 'edit' ), onplay_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' ) ) ) {
				/* translators: 1: wallet amount */
				wc_add_notice( __( 'Payment error: ', 'onplay-wallet' ) . sprintf( __( 'Your wallet balance is low. Please add %s to proceed with this transaction.', 'onplay-wallet' ), wc_price( $order->get_total( 'edit' ) - onplay_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' ), onplay_wallet_wc_price_args( $order->get_customer_id() ) ) ), 'error' );
				return;
			}

			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Remove cart.
			WC()->cart->empty_cart();

			// Complete order payment.
			$order->payment_complete();

			// Return thankyou redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		/**
		 * Process payment via POS as Source of Truth.
		 *
		 * 1. Query fresh balance from POS
		 * 2. Validate sufficient funds
		 * 3. Execute debit at POS
		 * 4. Update local cache
		 * 5. Complete order
		 *
		 * @param WC_Order           $order     The WooCommerce order.
		 * @param OnplayPOS_Connector $connector POS connector instance.
		 * @return array
		 */
		private function process_payment_pos_ssot( $order, $connector ) {
			$user   = $order->get_user();
			$email  = $user ? $user->user_email : $order->get_billing_email();
			$amount = floatval( $order->get_total( 'edit' ) );

			// 1. Query fresh balance from POS.
			$balance_response = $connector->get_balance( $email );
			if ( is_wp_error( $balance_response ) ) {
				$connector->log( 'SSoT balance check failed for ' . $email . ': ' . $balance_response->get_error_message(), 'error' );
				wc_add_notice( __( 'Error al verificar saldo. Intenta nuevamente.', 'onplay-wallet' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$pos_balance = floatval( $balance_response['balance'] );

			// 2. Validate sufficient funds.
			if ( $pos_balance < $amount ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: available balance */
						__( 'Saldo insuficiente. Disponible: %s', 'onplay-wallet' ),
						wc_price( $pos_balance )
					),
					'error'
				);
				return array( 'result' => 'failure' );
			}

			// 3. Execute debit at POS.
			$site_name   = sanitize_title( get_bloginfo( 'name' ) );
			$reference   = 'WC-ORDER-' . $order->get_id() . '-' . $site_name;
			$description = sprintf(
				/* translators: 1: site name, 2: order number */
				__( 'Compra en %1$s - Orden #%2$s', 'onplay-wallet' ),
				get_bloginfo( 'name' ),
				$order->get_order_number()
			);

			$debit_response = $connector->debit( $email, $amount, $reference, $description );
			if ( is_wp_error( $debit_response ) ) {
				$connector->log( 'SSoT debit failed for order #' . $order->get_id() . ': ' . $debit_response->get_error_message(), 'error' );
				wc_add_notice( __( 'Error al procesar el pago. Intenta nuevamente.', 'onplay-wallet' ), 'error' );
				return array( 'result' => 'failure' );
			}

			// 4. Update local cache balance.
			if ( $user ) {
				$new_balance = isset( $debit_response['balance'] ) ? floatval( $debit_response['balance'] ) : ( $pos_balance - $amount );
				update_user_meta( $user->ID, '_current_onplay_wallet_balance', $new_balance );
			}

			// 5. Store POS references on the order.
			$order->update_meta_data( '_pos_transaction_id', isset( $debit_response['transaction_id'] ) ? $debit_response['transaction_id'] : '' );
			$order->update_meta_data( '_pos_debit_reference', $reference );
			$order->update_meta_data( '_pos_ssot_payment', true );

			// Reduce stock levels.
			wc_reduce_stock_levels( $order->get_id() );

			// Remove cart.
			WC()->cart->empty_cart();

			// Complete order — skip the woocommerce_pre_payment_complete local debit.
			$order->payment_complete( isset( $debit_response['transaction_id'] ) ? $debit_response['transaction_id'] : '' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: reference */
					__( 'Pago wallet via POS SSoT. Monto: %1$s. Ref: %2$s', 'onplay-wallet' ),
					wc_price( $amount ),
					$reference
				)
			);
			$order->save();

			$connector->log( sprintf( 'SSoT payment completed: Order #%d, Amount: %s, Ref: %s', $order->get_id(), $amount, $reference ), 'info' );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		/**
		 * Debit user wallet on WooCommerce payment complete.
		 *
		 * @param WC_Order $order_id order_id.
		 * @throws Exception WooCommerce expeptions.
		 */
		public function woocommerce_pre_payment_complete( $order_id ) {
			$order = wc_get_order( $order_id );
			// Skip local debit if this order was already debited via POS SSoT.
			if ( $order->get_meta( '_pos_ssot_payment' ) ) {
				return;
			}
			if ( 'wallet' === $order->get_payment_method( 'edit' ) && ! $order->get_transaction_id( 'edit' ) && $order->has_status( apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled' ), $order ) ) ) {
				$wallet_response = onplay_wallet()->wallet->debit( $order->get_customer_id( 'edit' ), $order->get_total( 'edit' ), apply_filters( 'onplay_wallet_order_payment_description', __( 'For order payment #', 'onplay-wallet' ) . $order->get_order_number(), $order ), array( 'for' => 'purchase' ) );
				if ( $wallet_response ) {
					$order->set_transaction_id( $wallet_response );
					do_action( 'onplay_wallet_payment_processed', $order_id, $wallet_response );
					$order->save();
				} else {
					throw new Exception( __( 'Something went wrong with processing payment please try again.', 'onplay-wallet' ) );
				}
			}
		}

		/**
		 * Process a refund if supported.
		 *
		 * @param  int    $order_id Order ID.
		 * @param  float  $amount Refund amount.
		 * @param  string $reason Refund reason.
		 * @return bool|WP_Error
		 * @throws Exception WP_Error Exceptions.
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			$order     = wc_get_order( $order_id );
			$connector = onplay_wallet()->pos_connector;

			// POS SSoT mode: send credit to POS.
			if ( $connector->is_ssot_enabled() && $connector->is_outbound_configured() && $order->get_meta( '_pos_ssot_payment' ) ) {
				$email       = $order->get_billing_email();
				$site_name   = sanitize_title( get_bloginfo( 'name' ) );
				$reference   = 'WC-REFUND-' . $order_id . '-' . $site_name;
				$description = sprintf(
					/* translators: 1: order number, 2: site name */
					__( 'Reembolso orden #%1$s en %2$s', 'onplay-wallet' ),
					$order->get_order_number(),
					get_bloginfo( 'name' )
				);
				if ( $reason ) {
					$description .= ' - ' . $reason;
				}

				$result = $connector->credit( $email, floatval( $amount ), $reference, $description );

				if ( is_wp_error( $result ) ) {
					$connector->log( 'SSoT refund failed for order #' . $order_id . ': ' . $result->get_error_message(), 'error' );
					throw new Exception( __( 'Error al devolver saldo al POS: ', 'onplay-wallet' ) . $result->get_error_message() );
				}

				// Update local cache.
				$user = $order->get_user();
				if ( $user && isset( $result['balance'] ) ) {
					update_user_meta( $user->ID, '_current_onplay_wallet_balance', floatval( $result['balance'] ) );
				}

				$order->add_order_note(
					sprintf(
						/* translators: %s: reference */
						__( 'Crédito devuelto al wallet POS. Ref: %s', 'onplay-wallet' ),
						$reference
					)
				);

				do_action( 'onplay_wallet_order_refunded', $order, $amount, isset( $result['transaction_id'] ) ? $result['transaction_id'] : 0 );
				return true;
			}

			// Legacy mode: local refund.
			$refund_reason  = $reason ? $reason : __( 'Wallet refund #', 'onplay-wallet' ) . $order->get_order_number();
			$transaction_id = onplay_wallet()->wallet->credit( $order->get_customer_id(), $amount, $refund_reason, array( 'currency' => $order->get_currency( 'edit' ) ) );
			if ( ! $transaction_id ) {
				throw new Exception( __( 'Refund not credited to customer', 'onplay-wallet' ) );
			}
			do_action( 'onplay_wallet_order_refunded', $order, $amount, $transaction_id );
			return true;
		}

		/**
		 * Process renewal payment for subscription order
		 *
		 * @param int      $amount_to_charge amount_to_charge.
		 * @param WC_Order $order order.
		 * @return void
		 */
		public function scheduled_subscription_payment( $amount_to_charge, $order ) {
			if ( $order->get_meta( '_wallet_scheduled_subscription_payment_processed' ) ) {
				return;
			}
			$order->payment_complete();
			WOO_Wallet_Helper::update_order_meta_data( $order, '_wallet_scheduled_subscription_payment_processed', true );
		}
	}
}
