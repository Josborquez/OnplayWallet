<?php
/**
 * Wallet actions file.
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Wallet actions.
 *
 * @author subrata
 */
class WOO_Wallet_Actions {

	/**
	 * Actions variable.
	 *
	 * @var array Array of action classes.
	 */
	public $actions;

	/**
	 * Class instance.
	 *
	 * @var WOO_Wallet_Actions The single instance of the class
	 * @since 1.0.0
	 */
	protected static $_instance = null;

	/**
	 * Main WOO_Wallet_Actions Instance.
	 *
	 * Ensures only one instance of WOO_Wallet_Actions is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @return WOO_Wallet_Actions Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Class Constructor
	 */
	public function __construct() {
		$this->load_actions();
		$this->init();
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}
	/**
	 * Init action calss.
	 *
	 * @return void
	 */
	public function init() {
		$load_actions = apply_filters(
			'onplay_wallet_actions',
			array(
				'Action_New_Registration',
				'Action_Product_Review',
				'Action_Daily_Visits',
				'Action_Referrals',
				'Onplay_Wallet_Action_Sell_Content',
			)
		);
		foreach ( $load_actions as $action ) {
			$load_action                       = is_string( $action ) ? new $action() : $action;
			$this->actions[ $load_action->id ] = $load_action;
		}
	}
	/**
	 * Load actions files.
	 *
	 * @return void
	 */
	public function load_actions() {
		require_once ONPLAY_WALLET_ABSPATH . 'includes/actions/class-onplay-wallet-action-new-registration.php';
		require_once ONPLAY_WALLET_ABSPATH . 'includes/actions/class-onplay-wallet-action-product-review.php';
		require_once ONPLAY_WALLET_ABSPATH . 'includes/actions/class-onplay-wallet-action-daily-visits.php';
		require_once ONPLAY_WALLET_ABSPATH . 'includes/actions/class-onplay-wallet-action-referrals.php';
		require_once ONPLAY_WALLET_ABSPATH . 'includes/actions/class-onplay-wallet-action-sell-content.php';
		do_action( 'onplay_wallet_load_actions' );
	}
	/**
	 * Get all available actions.
	 *
	 * @return array
	 */
	public function get_available_actions() {
		$actions = array();
		foreach ( $this->actions as $action ) {
			if ( $action->is_enabled() ) {
				$actions[] = $action;
			}
		}
		return $actions;
	}
	/**
	 * Load scripts for action page.
	 *
	 * @return void
	 */
	public function admin_scripts() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		// Register scripts.
		wp_register_script( 'onplay_wallet_admin_actions', onplay_wallet()->plugin_url() . '/build/admin/actions.js', array( 'jquery' ), ONPLAY_WALLET_PLUGIN_VERSION, true );
		$onplay_wallet_screen_id = sanitize_title( __( 'OnplayWallet', 'onplay-wallet' ) );
		if ( in_array( $screen_id, array( "{$onplay_wallet_screen_id}_page_onplay-wallet-actions" ), true ) ) {
			wp_enqueue_script( 'onplay_wallet_admin_actions' );
		}
	}
}

WOO_Wallet_Actions::instance();
