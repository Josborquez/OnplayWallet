<?php
/**
 * The Template for displaying wallet dashboard.
 *
 * This template can be overridden by copying it to yourtheme/onplay-wallet/transfer.php.
 *
 * HOWEVER, on occasion we will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @author  Subrata Mal
 * @version     1.5.15
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<!-- Transfer Form -->
<div class="onplay-wallet-form-wrapper">
	<h3 class="onplay-wallet-section-title"><?php esc_html_e( 'Wallet Transfer', 'onplay-wallet' ); ?></h3>
	<form method="post" action="" id="onplay_wallet_transfer_form">
		<p class="onplay-wallet-field-container form-row form-row-wide">
			<label for="onplay_wallet_transfer_user_id"><?php esc_html_e( 'Select Recipient', 'onplay-wallet' ); ?>
			<?php
			if ( apply_filters( 'onplay_wallet_user_search_exact_match', true ) ) {
				esc_html_e( '(Email)', 'onplay-wallet' );
			}
			?>
				</label>
			<select name="onplay_wallet_transfer_user_id" id="onplay_wallet_transfer_user_id" class="onplay-wallet-select2" required=""></select>
		</p>
		<p class="onplay-wallet-field-container form-row form-row-wide">
			<label for="onplay_wallet_transfer_amount"><?php esc_html_e( 'Amount', 'onplay-wallet' ); ?></label>
			<input id="onplay_wallet_transfer_amount" type="number" step="0.01" min="<?php echo onplay_wallet()->settings_api->get_option( 'min_transfer_amount', '_wallet_settings_general', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="onplay_wallet_transfer_amount" required="" placeholder="0.00"/>
		</p>
		<p class="onplay-wallet-field-container form-row form-row-wide">
			<label for="onplay_wallet_transfer_note"><?php esc_html_e( 'What\'s this for?', 'onplay-wallet' ); ?></label>
			<textarea id="onplay_wallet_transfer_note" name="onplay_wallet_transfer_note" placeholder="<?php esc_attr_e( 'Optional note...', 'onplay-wallet' ); ?>"></textarea>
		</p>
		<p class="onplay-wallet-field-container form-row">
			<?php wp_nonce_field( 'onplay_wallet_transfer', 'onplay_wallet_transfer' ); ?>
			<input type="submit" class="button" name="onplay_wallet_transfer_fund" value="<?php esc_html_e( 'Proceed to Transfer', 'onplay-wallet' ); ?>" />
		</p>
	</form>
</div>