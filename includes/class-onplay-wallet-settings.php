<?php
/**
 * OnplayWallet settings
 *
 * @package OnplayWallet
 */

if ( ! class_exists( 'Onplay_Wallet_Settings' ) ) :
	/**
	 * Plugin settings page class
	 */
	class Onplay_Wallet_Settings {

		/**
		 * Settings api object
		 *
		 * @var Onplay_Wallet_Settings_API
		 */
		private $settings_api;

		/**
		 * Class constructor
		 *
		 * @param Onplay_Wallet_Settings_API $settings_api settings_api.
		 */
		public function __construct( $settings_api ) {
			$this->settings_api = $settings_api;
			add_action( 'admin_init', array( $this, 'plugin_settings_page_init' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		}

		/**
		 * WC wallet menu
		 */
		public function admin_menu() {
			add_submenu_page( 'onplay-wallet', __( 'Settings', 'onplay-wallet' ), __( 'Settings', 'onplay-wallet' ), get_wallet_user_capability(), 'onplay-wallet-settings', array( $this, 'plugin_page' ) );
		}

		/**
		 * Admin init
		 */
		public function plugin_settings_page_init() {
			// set the settings.
			$this->settings_api->set_sections( $this->get_settings_sections() );
			foreach ( $this->get_settings_sections() as $section ) {
				if ( method_exists( $this, "update_option_{$section['id']}_callback" ) ) {
					add_action( "update_option_{$section['id']}", array( $this, "update_option_{$section['id']}_callback" ), 10, 3 );
				}
			}
			$this->settings_api->set_fields( $this->get_settings_fields() );
			// initialize settings.
			$this->settings_api->admin_init();
		}

		/**
		 * Enqueue scripts and styles
		 */
		public function admin_enqueue_scripts() {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';
			wp_register_script( 'onplay-wallet-admin-settings', onplay_wallet()->plugin_url() . '/build/admin/settings.js', array( 'jquery' ), ONPLAY_WALLET_PLUGIN_VERSION, true );
			$onplay_wallet_settings_screen_id = sanitize_title( __( 'OnplayWallet', 'onplay-wallet' ) );
			if ( in_array( $screen_id, array( "{$onplay_wallet_settings_screen_id}_page_onplay-wallet-settings" ) ) ) {
				wp_enqueue_style( 'dashicons' );
				wp_enqueue_style( 'wp-color-picker' );
				wp_enqueue_style( 'onplay_wallet_admin_styles' );
				wp_enqueue_media();
				wp_enqueue_script( 'wp-color-picker' );
				wp_enqueue_script( 'jquery' );
				wp_enqueue_script( 'onplay-wallet-admin-settings' );
				$localize_param = array(
					'screen_id'          => $screen_id,
					'gateways'           => $this->get_wc_payment_gateways( 'id' ),
					'settings_screen_id' => "{$onplay_wallet_settings_screen_id}_page_onplay-wallet-settings",
				);
				wp_localize_script( 'onplay-wallet-admin-settings', 'onplay_wallet_admin_settings_param', $localize_param );
			}
		}

		/**
		 * Setting sections
		 *
		 * @return array
		 */
		public function get_settings_sections() {
			$sections = array(
				array(
					'id'    => '_wallet_settings_general',
					'title' => __( 'General Options', 'onplay-wallet' ),
					'icon'  => 'dashicons-admin-generic',
				),
				array(
					'id'    => '_wallet_settings_credit',
					'title' => __( 'Credit Options', 'onplay-wallet' ),
					'icon'  => 'dashicons-money-alt',
				),
				array(
					'id'    => '_wallet_settings_pos',
					'title' => __( 'OnplayPOS', 'onplay-wallet' ),
					'icon'  => 'dashicons-store',
				),
			);
			return apply_filters( 'onplay_wallet_settings_sections', $sections );
		}

		/**
		 * Returns all the settings fields
		 *
		 * @return array settings fields
		 */
		public function get_settings_fields() {
			$settings_fields = array(
				'_wallet_settings_general' => array_merge(
					array(
						array(
							'name'    => 'is_enable_wallet_topup',
							'label'   => __( 'Wallet topup', 'onplay-wallet' ),
							'desc'    => __( 'If enabled user will be able to add funds into wallet.', 'onplay-wallet' ),
							'type'    => 'checkbox',
							'default' => 'on',
						),
						array(
							'name'    => 'product_title',
							'label'   => __( 'Rechargeable Product Title', 'onplay-wallet' ),
							/* translators: 1: Product edit URL */
							'desc'    => sprintf( __( 'Enter wallet rechargeable product title | <a href="%s" target="_blank">Edit product</a>', 'onplay-wallet' ), get_edit_post_link( get_wallet_rechargeable_product()->get_id() ) ),
							'type'    => 'text',
							'default' => $this->get_rechargeable_product_title(),
						),
						array(
							'name'    => 'product_image',
							'label'   => __( 'Rechargeable Product Image', 'onplay-wallet' ),
							/* translators: 1: Product edit URL */
							'desc'    => sprintf( __( 'Choose wallet rechargeable product image | <a href="%s" target="_blank">Edit product</a>', 'onplay-wallet' ), get_edit_post_link( get_wallet_rechargeable_product()->get_id() ) ),
							'type'    => 'attachment',
							'options' => array(
								'button_label'         => __( 'Set product image', 'onplay-wallet' ),
								'uploader_title'       => __( 'Product image', 'onplay-wallet' ),
								'uploader_button_text' => __( 'Set product image', 'onplay-wallet' ),
							),
						),
					),
					$this->get_wc_tax_options(),
					array(
						array(
							'name'  => 'min_topup_amount',
							'label' => __( 'Minimum Topup Amount', 'onplay-wallet' ),
							'desc'  => __( 'The minimum amount needed for wallet top up', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'  => 'max_topup_amount',
							'label' => __( 'Maximum Topup Amount', 'onplay-wallet' ),
							'desc'  => __( 'The maximum amount needed for wallet top up', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'     => 'allowed_payment_gateways',
							'label'    => __( 'Allowed Payment Gateways', 'onplay-wallet' ),
							'desc'     => __( 'Select the payment gateways you want to allow for wallet top up.', 'onplay-wallet' ),
							'type'     => 'select',
							'options'  => $this->get_wc_payment_allowed_gateways(),
							'default'  => array_keys( $this->get_wc_payment_allowed_gateways() ),
							'size'     => 'regular-text wc-enhanced-select',
							'multiple' => true,
						),
					),
					array(
						array(
							'name'  => 'is_enable_gateway_charge',
							'label' => __( 'Payment gateway charge', 'onplay-wallet' ),
							'desc'  => __( 'Charge customer when they add balance to their wallet?', 'onplay-wallet' ),
							'type'  => 'checkbox',
						),
						array(
							'name'    => 'gateway_charge_type',
							'label'   => __( 'Charge type', 'onplay-wallet' ),
							'desc'    => __( 'Select gateway charge type percentage or fixed', 'onplay-wallet' ),
							'type'    => 'select',
							'options' => array(
								'percent' => __( 'Percentage', 'onplay-wallet' ),
								'fixed'   => __( 'Fixed', 'onplay-wallet' ),
							),
							'size'    => 'regular-text wc-enhanced-select',
						),
					),
					$this->get_wc_payment_gateways(),
					$this->wp_menu_locations(),
					array(
						array(
							'name'    => 'is_enable_partial_payment',
							'label'   => __( 'Partial payment', 'onplay-wallet' ),
							'desc'    => __( 'If checked user will be able to use part of wallet balance at checkout page.', 'onplay-wallet' ),
							'type'    => 'checkbox',
							'default' => 'on',
						),
						array(
							'name'  => 'is_auto_deduct_for_partial_payment',
							'label' => __( 'Auto deduct wallet balance for partial payment', 'onplay-wallet' ),
							'desc'  => __( 'If a purchase requires more balance than you have in your wallet, then if checked the wallet balance will be deduct first and the rest of the amount will need to be paid.', 'onplay-wallet' ),
							'type'  => 'checkbox',
						),
						array(
							'name'    => 'is_enable_wallet_transfer',
							'label'   => __( 'Wallet transfer', 'onplay-wallet' ),
							'desc'    => __( 'If checked user will be able to transfer fund to another user.', 'onplay-wallet' ),
							'type'    => 'checkbox',
							'default' => 'on',
						),
						array(
							'name'  => 'min_transfer_amount',
							'label' => __( 'Minimum transfer amount', 'onplay-wallet' ),
							'desc'  => __( 'Enter minimum transfer amount', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'    => 'transfer_charge_type',
							'label'   => __( 'Transfer charge type', 'onplay-wallet' ),
							'desc'    => __( 'Select transfer charge type percentage or fixed', 'onplay-wallet' ),
							'type'    => 'select',
							'options' => array(
								'percent' => __( 'Percentage', 'onplay-wallet' ),
								'fixed'   => __( 'Fixed', 'onplay-wallet' ),
							),
							'size'    => 'regular-text wc-enhanced-select',
						),
						array(
							'name'  => 'transfer_charge_amount',
							'label' => __( 'Transfer charge Amount', 'onplay-wallet' ),
							'desc'  => __( 'Enter transfer charge amount', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
					),
					array()
				),
				'_wallet_settings_credit'  => array_merge(
					array(
						array(
							'name'  => 'is_enable_cashback_reward_program',
							'label' => __( 'Cashback Reward Program', 'onplay-wallet' ),
							'desc'  => __( 'Run cashback reward program on your store', 'onplay-wallet' ),
							'type'  => 'checkbox',
						),
						array(
							'name'     => 'process_cashback_status',
							'label'    => __( 'Process cashback', 'onplay-wallet' ),
							'desc'     => __( 'Select order status to process cashback', 'onplay-wallet' ),
							'type'     => 'select',
							'options'  => apply_filters(
								'onplay_wallet_process_cashback_status',
								array(
									'pending'    => __( 'Pending payment', 'onplay-wallet' ),
									'on-hold'    => __( 'On hold', 'onplay-wallet' ),
									'processing' => __( 'Processing', 'onplay-wallet' ),
									'completed'  => __(
										'Completed',
										'onplay-wallet'
									),
								)
							),
							'default'  => array( 'processing', 'completed' ),
							'size'     => 'regular-text wc-enhanced-select',
							'multiple' => true,
						),
						array(
							'name'     => 'exclude_role',
							'label'    => __( 'Exclude user role', 'onplay-wallet' ),
							'desc'     => __( 'This option lets you specify which user role you want to exclude from the cashback program.', 'onplay-wallet' ),
							'type'     => 'select',
							'options'  => $this->get_editable_role_options(),
							'default'  => array(),
							'size'     => 'regular-text wc-enhanced-select',
							'multiple' => true,
						),
						array(
							'name'    => 'cashback_rule',
							'label'   => __( 'Cashback Rule', 'onplay-wallet' ),
							'desc'    => __( 'Select Cashback Rule cart or product wise', 'onplay-wallet' ),
							'type'    => 'select',
							'options' => apply_filters(
								'onplay_wallet_cashback_rules',
								array(
									'cart'        => __( 'Cart wise', 'onplay-wallet' ),
									'product'     => __( 'Product wise', 'onplay-wallet' ),
									'product_cat' => __(
										'Product category wise',
										'onplay-wallet'
									),
								)
							),
							'size'    => 'regular-text wc-enhanced-select',
						),
						array(
							'name'    => 'cashback_type',
							'label'   => __( 'Cashback type', 'onplay-wallet' ),
							'desc'    => __( 'Select cashback type percentage or fixed', 'onplay-wallet' ),
							'type'    => 'select',
							'options' => array(
								'percent' => __( 'Percentage', 'onplay-wallet' ),
								'fixed'   => __( 'Fixed', 'onplay-wallet' ),
							),
							'size'    => 'regular-text wc-enhanced-select',
						),
						array(
							'name'  => 'cashback_amount',
							'label' => __( 'Cashback Amount', 'onplay-wallet' ),
							'desc'  => __( 'Enter cashback amount', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'  => 'min_cart_amount',
							'label' => __( 'Minimum Cart Amount', 'onplay-wallet' ),
							'desc'  => __( 'Enter applicable minimum cart amount for cashback', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'  => 'max_cashback_amount',
							'label' => __( 'Maximum Cashback Amount', 'onplay-wallet' ),
							'desc'  => __( 'Enter maximum cashback amount', 'onplay-wallet' ),
							'type'  => 'number',
							'step'  => '0.01',
						),
						array(
							'name'    => 'allow_min_cashback',
							'label'   => __( 'Allow Minimum cashback', 'onplay-wallet' ),
							'desc'    => __( 'If checked minimum cashback amount will be applied on product category cashback calculation.', 'onplay-wallet' ),
							'type'    => 'checkbox',
							'default' => 'on',
						),
					),
				),
			);
			$settings_fields['_wallet_settings_pos'] = array(
				array(
					'name'  => 'pos_enable',
					'label' => __( 'Enable OnplayPOS Integration', 'onplay-wallet' ),
					'desc'  => __( 'Enable wallet integration with OnplayPOS.', 'onplay-wallet' ),
					'type'  => 'checkbox',
				),
				array(
					'name'  => 'pos_is_ssot',
					'label' => __( 'POS is Source of Truth', 'onplay-wallet' ),
					'desc'  => __( 'When enabled, checkout queries and debits the POS for wallet payments. The local balance becomes a cache. When disabled, the plugin uses the local wallet (legacy mode).', 'onplay-wallet' ),
					'type'  => 'checkbox',
				),
				array(
					'name'  => 'pos_api_url',
					'label' => __( 'POS API URL', 'onplay-wallet' ),
					'desc'  => __( 'Base URL of the POS wallet-connector API. Example: https://onplaypos.onplaygames.cl/api/wallet-connector', 'onplay-wallet' ),
					'type'  => 'text',
					'size'  => 'regular-text',
				),
				array(
					'name'    => 'pos_api_key',
					'label'   => __( 'API Key', 'onplay-wallet' ),
					'desc'    => __( 'Shared API key sent in the X-Onplay-Api-Key header for authentication with the POS.', 'onplay-wallet' ),
					'type'    => 'text',
					'size'    => 'regular-text',
					'default' => wp_generate_password( 32, false ),
				),
				array(
					'name'    => 'pos_webhook_secret',
					'label'   => __( 'Webhook Secret', 'onplay-wallet' ),
					'desc'    => sprintf(
						/* translators: %s: webhook URL */
						__( 'Secret to validate incoming POS webhooks (HMAC-SHA256). Webhook URL: %s', 'onplay-wallet' ),
						'<code>' . esc_url( rest_url( 'onplay/v1/pos/webhook' ) ) . '</code>'
					),
					'type'    => 'text',
					'size'    => 'regular-text',
					'default' => wp_generate_password( 32, false ),
				),
				array(
					'name'    => 'pos_api_timeout',
					'label'   => __( 'API Timeout (seconds)', 'onplay-wallet' ),
					'desc'    => __( 'Timeout for outbound API calls to the POS.', 'onplay-wallet' ),
					'type'    => 'number',
					'default' => 10,
					'step'    => '1',
				),
				array(
					'name'  => 'pos_enable_qr',
					'label' => __( 'Enable QR Payments', 'onplay-wallet' ),
					'desc'  => __( 'Allow customers to generate QR codes in their wallet for payment at POS terminals.', 'onplay-wallet' ),
					'type'  => 'checkbox',
				),
				array(
					'name'    => 'pos_sync_direction',
					'label'   => __( 'Sync Direction', 'onplay-wallet' ),
					'desc'    => __( 'Choose how wallet data synchronizes between WooCommerce and POS.', 'onplay-wallet' ),
					'type'    => 'select',
					'options' => array(
						'pos_to_wc'  => __( 'POS -> WooCommerce only (Recommended)', 'onplay-wallet' ),
						'both'       => __( 'Bidirectional', 'onplay-wallet' ),
						'wc_to_pos'  => __( 'WooCommerce -> POS only', 'onplay-wallet' ),
					),
					'default' => 'pos_to_wc',
					'size'    => 'regular-text wc-enhanced-select',
				),
				array(
					'name'  => 'pos_api_secret',
					'label' => __( 'POS API Secret (Legacy)', 'onplay-wallet' ),
					'desc'  => __( 'Used for legacy HMAC-SHA256 signature and QR token generation.', 'onplay-wallet' ),
					'type'  => 'text',
					'size'  => 'regular-text',
				),
				array(
					'name'  => 'pos_auto_sync',
					'label' => __( 'Auto-sync to POS', 'onplay-wallet' ),
					'desc'  => __( 'Automatically sync WooCommerce wallet transactions to the POS. Not needed when POS is Source of Truth.', 'onplay-wallet' ),
					'type'  => 'checkbox',
				),
			);
			return apply_filters( 'onplay_wallet_settings_filds', $settings_fields );
		}

		/**
		 * Fetch rechargeable product title
		 *
		 * @return string title
		 */
		public function get_rechargeable_product_title() {
			$product_title  = '';
			$wallet_product = get_wallet_rechargeable_product();
			if ( $wallet_product ) {
				$product_title = $wallet_product->get_title();
			}
			return $product_title;
		}

		/**
		 * Display plugin settings page
		 */
		public function plugin_page() {
			echo '<div class="wrap">';
			echo '<h2 style="margin-bottom: 15px;">' . esc_html__( 'Settings', 'onplay-wallet' ) . '</h2>';
			settings_errors();
			echo '<div class="wallet-settings-wrap">';
			$this->settings_api->show_navigation();
			$this->settings_api->show_forms();
			$this->render_pos_test_connection_button();
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Render the Test Connection button and inline JS for the POS settings tab.
		 */
		private function render_pos_test_connection_button() {
			$nonce = wp_create_nonce( 'onplay_pos_test_connection' );
			?>
			<div id="onplay-pos-test-connection" style="margin-top:15px;display:none;">
				<button type="button" class="button button-secondary" id="onplay-pos-test-btn">
					<?php esc_html_e( 'Test POS Connection', 'onplay-wallet' ); ?>
				</button>
				<span id="onplay-pos-test-result" style="margin-left:10px;"></span>
			</div>
			<script type="text/javascript">
			jQuery(function($) {
				// Show button only when POS settings tab is active.
				function showTestBtn() {
					var $posTab = $('a[href="#_wallet_settings_pos"]');
					if ($posTab.length && $posTab.hasClass('nav-tab-active')) {
						$('#onplay-pos-test-connection').show();
					} else {
						$('#onplay-pos-test-connection').hide();
					}
				}
				showTestBtn();
				$(document).on('click', '.nav-tab', function() {
					setTimeout(showTestBtn, 100);
				});

				$('#onplay-pos-test-btn').on('click', function() {
					var $btn    = $(this);
					var $result = $('#onplay-pos-test-result');
					$btn.prop('disabled', true);
					$result.html('<?php echo esc_js( __( 'Testing...', 'onplay-wallet' ) ); ?>');

					$.post(ajaxurl, {
						action: 'onplay_pos_test_connection',
						_wpnonce: '<?php echo esc_js( $nonce ); ?>'
					}, function(response) {
						$btn.prop('disabled', false);
						if (response.success) {
							$result.html('<span style="color:green;">&#10004; ' + response.data.message + '</span>');
						} else {
							$result.html('<span style="color:red;">&#10008; ' + response.data.message + '</span>');
						}
					}).fail(function() {
						$btn.prop('disabled', false);
						$result.html('<span style="color:red;">&#10008; <?php echo esc_js( __( 'Request failed.', 'onplay-wallet' ) ); ?></span>');
					});
				});
			});
			</script>
			<?php
		}

		/**
		 * Chargeable payment gateways
		 *
		 * @param string $context context.
		 * @return array
		 */
		public function get_wc_payment_gateways( $context = 'field' ) {
			$gateways = array();
			foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
				if ( 'yes' === $gateway->enabled && 'wallet' !== $gateway->id ) {
					$method_title = $gateway->get_title() ? $gateway->get_title() : __( '(no title)', 'onplay-wallet' );
					if ( 'field' === $context ) {
						$gateways[] = array(
							'name'  => 'charge_amount_' . $gateway->id,
							'label' => $method_title,
							'desc'  => __( 'Enter gateway charge amount for ', 'onplay-wallet' ) . $method_title,
							'type'  => 'number',
							'step'  => '0.01',
						);
					} else {
						$gateways[] = $gateway->id;
					}
				}
			}
			return $gateways;
		}

		/**
		 * Allowed payment gateways
		 *
		 * @param string $context context.
		 * @param string $prefix prefix.
		 * @return array
		 */
		public function get_wc_payment_allowed_gateways( $context = 'edit', $prefix = '' ) {
			$gateways = array();
			foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
				$method_title = $gateway->get_title() ? $gateway->get_title() : __( '(no title)', 'onplay-wallet' );
				if ( 'yes' === $gateway->enabled && 'wallet' !== $gateway->id ) {
					if ( 'field' === $context ) {
						$gateways[] = array(
							'name'    => $prefix . $gateway->id,
							'label'   => $method_title,
							'desc'    => __( 'Allow this gateway for recharge wallet', 'onplay-wallet' ),
							'type'    => 'checkbox',
							'default' => 'on',
						);
					} else {
						$gateways[ $gateway->id ] = $method_title;
					}
				}
			}
			return $gateways;
		}

		/**
		 * Allowed payment gateways
		 *
		 * @param string $context context.
		 * @return array
		 */
		public function get_wc_tax_options( $context = 'field' ) {
			$tax_options = array();
			if ( wc_tax_enabled() ) {
				$tax_options[] = array(
					'name'    => '_tax_status',
					'label'   => __( 'Rechargeable Product Tax status', 'onplay-wallet' ),
					'desc'    => __( 'Define whether or not the rechargeable Product is taxable.', 'onplay-wallet' ),
					'type'    => 'select',
					'options' => array(
						'taxable' => __( 'Taxable', 'onplay-wallet' ),
						'none'    => _x( 'None', 'Tax status', 'onplay-wallet' ),
					),
					'size'    => 'regular-text wc-enhanced-select',
				);
				$tax_options[] = array(
					'name'    => '_tax_class',
					'label'   => __( 'Rechargeable Product Tax class', 'onplay-wallet' ),
					'desc'    => __( 'Define whether or not the rechargeable Product is taxable.', 'onplay-wallet' ),
					'type'    => 'select',
					'options' => wc_get_product_tax_class_options(),
					'desc'    => __( 'Choose a tax class for rechargeable product. Tax classes are used to apply different tax rates specific to certain types of product.', 'onplay-wallet' ),
					'size'    => 'regular-text wc-enhanced-select',
				);
			}
			return $tax_options;
		}

		/**
		 * Get all registered nav menu locations settings
		 *
		 * @return array
		 */
		public function wp_menu_locations() {
			$menu_locations = array();
			if ( current_theme_supports( 'menus' ) ) {
				$locations = get_registered_nav_menus();
				if ( $locations ) {
					$menu_item_locations = array();
					foreach ( $locations as $location => $title ) {
						$menu_item_locations[ $location ] = $title;
					}
					$menu_locations = array(
						array(
							'name'     => 'mini_wallet_display_location',
							'label'    => __( 'Mini wallet display location', 'onplay-wallet' ),
							'desc'     => __( 'Select the location where you want to display mini wallet.', 'onplay-wallet' ),
							'type'     => 'select',
							'options'  => $menu_item_locations,
							'size'     => 'regular-text wc-enhanced-select',
							'multiple' => true,
						),
					);
				}
			}
			return $menu_locations;
		}

		/**
		 * Get all editable roles.
		 */
		public function get_editable_role_options() {
			$role_options   = array();
			$editable_roles = array_reverse( wp_roles()->roles );
			foreach ( $editable_roles as $role => $details ) {
				$name                  = translate_user_role( $details['name'] );
				$role_options[ $role ] = $name;
			}
			return $role_options;
		}

		/**
		 * Callback fuction of all option after save
		 *
		 * @param array  $old_value old_value.
		 * @param array  $value value.
		 * @param string $option option.
		 */
		public function update_option__wallet_settings_general_callback( $old_value, $value, $option ) {
			/**
			 * Save product title on option change
			 */
			if ( ! isset( $old_value['product_title'] ) || $old_value['product_title'] !== $value['product_title'] ) {
				$this->set_rechargeable_product_title( $value['product_title'] );
			}

			/**
			 * Save tax status
			 */
			if ( isset( $value['_tax_status'] ) && isset( $value['_tax_class'] ) ) {
				$this->set_rechargeable_tax_status( $value['_tax_status'], $value['_tax_class'] );
			}

			/**
			 * Save product image
			 */
			if ( ! isset( $old_value['product_image'] ) || $old_value['product_image'] !== $value['product_image'] ) {
				$this->set_rechargeable_product_image( $value['product_image'] );
			}
		}

		/**
		 * Set rechargeable product title
		 *
		 * @param string $title title.
		 * @return boolean | int
		 */
		public function set_rechargeable_product_title( $title ) {
			$wallet_product = get_wallet_rechargeable_product();
			if ( $wallet_product ) {
				$wallet_product->set_name( $title );
				return $wallet_product->save();
			}
			return false;
		}

		/**
		 * Set rechargeable tax status
		 *
		 * @param string $_tax_status Tax status.
		 * @param string $_tax_class Tax class.
		 * @return boolean | int
		 */
		public function set_rechargeable_tax_status( $_tax_status, $_tax_class ) {
			$wallet_product = get_wallet_rechargeable_product();
			if ( $wallet_product ) {
				$wallet_product->set_tax_status( $_tax_status );
				$wallet_product->set_tax_class( $_tax_class );
				return $wallet_product->save();
			}
			return false;
		}

		/**
		 * Set rechargeable product image
		 *
		 * @param int $attachment_id attachment_id.
		 * @return boolean | int
		 */
		public function set_rechargeable_product_image( $attachment_id ) {
			$wallet_product = get_wallet_rechargeable_product();
			if ( $wallet_product ) {
				$wallet_product->set_image_id( $attachment_id );
				return $wallet_product->save();
			}
			return false;
		}
	}

	endif;

new Onplay_Wallet_Settings( onplay_wallet()->settings_api );
