<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Onplay_Wallet_WCMp' ) ) {

	class Onplay_Wallet_WCMp {
		/**
		 * The single instance of the class.
		 *
		 * @var Onplay_Wallet_WCMp
		 * @since 1.1.10
		 */
		protected static $_instance = null;

		/**
		 * Main instance
		 *
		 * @return class object
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public function __construct() {
			add_filter( 'automatic_payment_method', array( $this, 'add_wallet_payment_method' ) );
			add_filter( 'wcmp_vendor_payment_mode', array( $this, 'add_wallet_vendor_payment_method' ) );
			add_filter( 'wcmp_payment_gateways', array( &$this, 'add_wcmp_wallet_payment_gateway' ) );
		}

		public function add_wallet_payment_method( $payment_methods ) {
			return array_merge( $payment_methods, array( 'onplay_wallet' => __( 'Wallet', 'onplay-wallet' ) ) );
		}

		public function add_wallet_vendor_payment_method( $payment_method ) {
			if ( 'Enable' === get_wcmp_vendor_settings( 'payment_method_onplay_wallet', 'payment' ) ) {
				return array_merge( $payment_method, array( 'onplay_wallet' => __( 'Wallet', 'onplay-wallet' ) ) );
			}
			return $payment_method;
		}

		public function add_wcmp_wallet_payment_gateway( $load_gateways ) {
			$load_gateways[] = 'WCMp_Gateway_Wallet';
			return $load_gateways;
		}

	}

}
Onplay_Wallet_WCMp::instance();
