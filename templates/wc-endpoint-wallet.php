<?php
/**
 * The Template for displaying wallet dashboard.
 *
 * This template can be overridden by copying it to yourtheme/onplay-wallet/wc-endpoint-wallet.php.
 *
 * HOWEVER, on occasion we will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @author  Subrata Mal
 * @version     1.1.8
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

global $wp;
do_action( 'onplay_wallet_before_my_wallet_content' );
$is_rendred_from_myaccount = wc_post_content_has_shortcode( 'onplay-wallet' ) ? false : is_account_page();
$menu_items                = apply_filters(
	'onplay_wallet_nav_menu_items',
	array(
		'add'          => array(
			'title' => apply_filters( 'onplay_wallet_account_topup_menu_title', __( 'Wallet topup', 'onplay-wallet' ) ),
			'url'   => $is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_onplay_wallet_endpoint', 'my-wallet' ), 'add', wc_get_page_permalink( 'myaccount' ) ) ) : add_query_arg( 'wallet_action', 'add' ),
			'icon'  => 'dashicons dashicons-plus-alt',
		),
		'transfer'     => array(
			'title' => apply_filters( 'onplay_wallet_account_transfer_amount_menu_title', __( 'Wallet transfer', 'onplay-wallet' ) ),
			'url'   => $is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_onplay_wallet_endpoint', 'my-wallet' ), 'transfer', wc_get_page_permalink( 'myaccount' ) ) ) : add_query_arg( 'wallet_action', 'transfer' ),
			'icon'  => 'dashicons dashicons-randomize',
		),
		'transactions' => array(
			'title' => apply_filters( 'onplay_wallet_account_transaction_menu_title', __( 'Transactions', 'onplay-wallet' ) ),
			'url'   => $is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_onplay_wallet_endpoint', 'my-wallet' ), 'transactions', wc_get_page_permalink( 'myaccount' ) ) ) : add_query_arg( 'wallet_action', 'transactions' ),
			'icon'  => 'dashicons dashicons-list-view',
		),
	),
	$is_rendred_from_myaccount
);
$current_action            = isset( $_GET['wallet_action'] ) ? $_GET['wallet_action'] : ( isset( $wp->query_vars['onplay-wallet'] ) ? $wp->query_vars['onplay-wallet'] : '' );
// Default to transactions if no action or just 'onplay-wallet' endpoint.
if ( empty( $current_action ) && ! isset( $_GET['wallet_action'] ) ) {
	$current_action = 'transactions';
}
if ( ! function_exists( 'is_wallet_tab_active' ) ) {
	/**
	 * Helper to check active state.
	 *
	 * @param string $tab_key tab key.
	 * @param string $current_action current action.
	 * @param array  $menu_item menu item.
	 * @return bool
	 */
	function is_wallet_tab_active( $tab_key, $current_action, $menu_item = null ) {
		if ( $tab_key === $current_action ) {
			return true;
		}
		if ( 'transactions' === $tab_key && empty( $current_action ) ) {
			return true;
		}

		// Check submenu.
		if ( $menu_item && isset( $menu_item['submenu'] ) && is_array( $menu_item['submenu'] ) ) {
			if ( array_key_exists( $current_action, $menu_item['submenu'] ) ) {
				return true;
			}
		}
		return false;
	}
}
?>

<div class="onplay-wallet-my-wallet-container">
	
	<!-- Header -->
	<div class="onplay-wallet-header">
		<h2><?php echo esc_html( apply_filters( 'onplay_wallet_account_menu_title', __( 'My Wallet', 'onplay-wallet' ) ) ); ?></h2>
		<p><?php esc_html_e( 'Manage your wallet and transactions seamlessly.', 'onplay-wallet' ); ?></p>
	</div>

	<!-- Top Section Wrapper -->
	<div class="onplay-wallet-top-section">
		<!-- Balance Card -->
		<div class="onplay-wallet-balance-card">
			<h3><?php esc_html_e( 'Total Balance', 'onplay-wallet' ); ?></h3>
			<?php
			$pos_settings    = get_option( '_wallet_settings_pos', array() );
			$pos_ssot_active = isset( $pos_settings['pos_enable'] ) && 'on' === $pos_settings['pos_enable']
				&& isset( $pos_settings['pos_is_ssot'] ) && 'on' === $pos_settings['pos_is_ssot'];

			$wallet_balance_display = '';
			$pos_degraded           = false;

			if ( $pos_ssot_active && onplay_wallet()->pos_connector->is_outbound_configured() ) {
				$current_user = wp_get_current_user();
				$balance_resp = onplay_wallet()->pos_connector->get_balance( $current_user->user_email );
				if ( ! is_wp_error( $balance_resp ) && isset( $balance_resp['balance'] ) ) {
					$fresh_balance = floatval( $balance_resp['balance'] );
					update_user_meta( $current_user->ID, '_current_onplay_wallet_balance', $fresh_balance );
					$wallet_balance_display = wc_price( $fresh_balance, onplay_wallet_wc_price_args( $current_user->ID ) );
				} else {
					// Degraded mode: use local cache.
					$pos_degraded           = true;
					$wallet_balance_display = onplay_wallet()->wallet->get_wallet_balance( get_current_user_id() );
				}
			} else {
				$wallet_balance_display = onplay_wallet()->wallet->get_wallet_balance( get_current_user_id() );
			}
			?>
			<p class="onplay-wallet-price"><?php echo $wallet_balance_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php if ( $pos_degraded ) : ?>
				<p style="font-size:0.8em;opacity:0.8;margin-top:5px;"><?php esc_html_e( 'Saldo en cache. El POS no responde en este momento.', 'onplay-wallet' ); ?></p>
			<?php endif; ?>
			<?php
			if ( isset( $pos_settings['pos_enable'] ) && 'on' === $pos_settings['pos_enable'] && isset( $pos_settings['pos_enable_qr'] ) && 'on' === $pos_settings['pos_enable_qr'] ) :
				$qr_payload = onplay_wallet()->pos_connector->generate_wallet_qr( get_current_user_id() );
				if ( ! is_wp_error( $qr_payload ) ) :
					$qr_data_encoded = rawurlencode( $qr_payload );
					$qr_image_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . $qr_data_encoded;
			?>
				<div class="onplay-wallet-qr-section" style="text-align:center;margin-top:15px;padding-top:15px;border-top:1px solid rgba(255,255,255,0.2);">
					<p style="margin-bottom:8px;font-size:0.85em;opacity:0.9;"><?php esc_html_e( 'Pay at POS with QR', 'onplay-wallet' ); ?></p>
					<img src="<?php echo esc_url( $qr_image_url ); ?>" alt="<?php esc_attr_e( 'Wallet QR Code', 'onplay-wallet' ); ?>" style="width:150px;height:150px;background:#fff;padding:5px;border-radius:8px;" />
					<p style="margin-top:5px;font-size:0.75em;opacity:0.7;"><?php esc_html_e( 'Show this code at any OnplayPOS terminal', 'onplay-wallet' ); ?></p>
				</div>
			<?php
				endif;
			endif;
			?>
		</div>

		<!-- Navigation Tabs -->
		<div class="onplay-wallet-nav-tabs">
			<?php foreach ( $menu_items as $item => $menu_item ) : ?>
				<?php if ( apply_filters( 'onplay_wallet_is_enable_' . $item, true ) ) : ?>
					<div class="onplay-wallet-nav-item-wrapper <?php echo isset( $menu_item['submenu'] ) ? 'has-submenu' : ''; ?>">
						<a href="<?php echo esc_url( $menu_item['url'] ); ?>" class="onplay-wallet-nav-tab <?php echo is_wallet_tab_active( $item, $current_action, $menu_item ) ? 'active' : ''; ?>">
							<span class="<?php echo esc_attr( $menu_item['icon'] ); ?>"></span>
							<?php echo esc_html( $menu_item['title'] ); ?>
							<?php if ( isset( $menu_item['submenu'] ) ) : ?>
								<span class="onplay-wallet-submenu-toggle dashicons dashicons-arrow-down-alt2"></span>
							<?php endif; ?>
						</a>
						<?php if ( isset( $menu_item['submenu'] ) && is_array( $menu_item['submenu'] ) ) : ?>
							<div class="onplay-wallet-submenu">
								<?php foreach ( $menu_item['submenu'] as $sub_key => $sub_item ) : ?>
									<a href="<?php echo esc_url( $sub_item['url'] ); ?>" class="onplay-wallet-submenu-item">
										<?php echo esc_html( $sub_item['title'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php do_action( 'onplay_wallet_menu_items' ); ?>
		</div>
	</div>
	<!-- Print notices -->
	<?php wc_print_notices(); ?>
	<!-- Content Area -->
	<div class="onplay-wallet-content-area">
		<?php if ( ( isset( $wp->query_vars['onplay-wallet'] ) && ! empty( $wp->query_vars['onplay-wallet'] ) ) || isset( $_GET['wallet_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php
			if ( apply_filters( "onplay_wallet_is_enable_{$current_action}", true ) ) {
				do_action( "onplay_wallet_{$current_action}_content" );
			}
			do_action( 'onplay_wallet_menu_content' ); // will be removed in future.
		} elseif ( apply_filters( 'onplay_wallet_is_enable_transactions', true ) ) {
			?>
			<!-- Recent Transactions -->
			<div class="onplay-wallet-transactions-list">
				<h3 class="onplay-wallet-section-title"><?php esc_html_e( 'Balance History', 'onplay-wallet' ); ?></h3>
				<?php
				$pos_txn_data      = null;
				$pos_txn_available = false;

				// Try to fetch transactions from POS when SSoT is active.
				if ( $pos_ssot_active && onplay_wallet()->pos_connector->is_outbound_configured() ) {
					$current_user_txn = wp_get_current_user();
					$pos_txn_data     = onplay_wallet()->pos_connector->get_transactions(
						$current_user_txn->user_email,
						apply_filters( 'onplay_wallet_transactions_count', 10 ),
						1
					);
					if ( ! is_wp_error( $pos_txn_data ) && isset( $pos_txn_data['transactions'] ) && ! empty( $pos_txn_data['transactions'] ) ) {
						$pos_txn_available = true;
					}
				}

				if ( $pos_txn_available ) :
				?>
					<table class="onplay-wallet-transactions-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'onplay-wallet' ); ?></th>
								<th><?php esc_html_e( 'Description', 'onplay-wallet' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Amount', 'onplay-wallet' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pos_txn_data['transactions'] as $pos_txn ) : ?>
								<?php
								$txn_type   = isset( $pos_txn['type'] ) ? $pos_txn['type'] : 'debit';
								$txn_amount = isset( $pos_txn['amount'] ) ? floatval( $pos_txn['amount'] ) : 0;
								$txn_desc   = isset( $pos_txn['description'] ) ? $pos_txn['description'] : ( isset( $pos_txn['details'] ) ? $pos_txn['details'] : '' );
								$txn_date   = isset( $pos_txn['date'] ) ? $pos_txn['date'] : ( isset( $pos_txn['createdAt'] ) ? $pos_txn['createdAt'] : '' );
								?>
								<tr>
									<td><?php echo $txn_date ? esc_html( wp_date( wc_date_format(), strtotime( $txn_date ) ) ) : '—'; ?></td>
									<td><?php echo esc_html( $txn_desc ); ?></td>
									<td class="amount <?php echo esc_attr( $txn_type ); ?>">
										<?php
										echo 'credit' === $txn_type ? '+' : '-';
										echo wp_kses_post( wc_price( $txn_amount ) );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<?php
					// Fallback to local transactions.
					$transactions = get_wallet_transactions( array( 'limit' => apply_filters( 'onplay_wallet_transactions_count', 10 ) ) );
					?>
					<?php if ( ! empty( $transactions ) ) { ?>
						<table class="onplay-wallet-transactions-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'onplay-wallet' ); ?></th>
									<th><?php esc_html_e( 'Description', 'onplay-wallet' ); ?></th>
									<th style="text-align: right;"><?php esc_html_e( 'Amount', 'onplay-wallet' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $transactions as $transaction ) : ?>
									<tr>
										<td><?php echo esc_html( wc_string_to_datetime( $transaction->date )->date_i18n( wc_date_format() ) ); ?></td>
										<td><?php echo wp_kses_post( $transaction->details ); ?></td>
										<td class="amount <?php echo esc_attr( $transaction->type ); ?>">
											<?php
											echo 'credit' === $transaction->type ? '+' : '-';
											echo wp_kses_post( wc_price( apply_filters( 'onplay_wallet_amount', $transaction->amount, $transaction->currency, $transaction->user_id ), onplay_wallet_wc_price_args( $transaction->user_id ) ) );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php
					} else {
						echo '<p style="padding: 20px; color: #666; text-align: center;">' . esc_html__( 'No transactions found', 'onplay-wallet' ) . '</p>';
					}
				endif;
				?>
			</div>
		<?php } ?>
	</div>
</div>
<?php
do_action( 'onplay_wallet_after_my_wallet_content' );
