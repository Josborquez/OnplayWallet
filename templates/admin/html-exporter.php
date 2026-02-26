<?php
/**
 * Admin View: Wallet Transactions Export
 *
 * @package OnplayWallet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
wp_enqueue_script( 'selectWoo' );
wp_enqueue_script( 'onplaywallet-exporter-script' );
$exporter = new OnplayWallet_CSV_Exporter();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Export Transactions', 'onplay-wallet' ); ?></h1>
	<div class="onplaywallet-exporter-wrapper">
		<form class="onplaywallet-exporter">
			<header>
				<span class="spinner is-active"></span>
				<h2><?php esc_html_e( 'Export transactions to a CSV file', 'onplay-wallet' ); ?></h2>
				<p><?php esc_html_e( 'This tool allows you to generate and download a CSV file containing a list of all transactions.', 'onplay-wallet' ); ?></p>
			</header>
			<section>
				<table class="form-table woocommerce-exporter-options">
					<tbody>
						<tr>
							<th scope="row">
								<label for="onplaywallet-exporter-type"><?php esc_html_e( 'Wallet balance only?', 'onplay-wallet' ); ?></label>
							</th>
							<td>
								<input type="checkbox" <?php checked( true ); ?> name="onplaywallet-exporter-type" id="onplaywallet-exporter-type" class="onplaywallet-exporter-type" value="1">
							</td>
						</tr>
						<tr class="export-transaction-settings-fields">
							<th scope="row">
								<label for="onplaywallet-exporter-columns"><?php esc_html_e( 'Which columns should be exported?', 'onplay-wallet' ); ?></label>
							</th>
							<td>
								<select id="onplaywallet-exporter-columns" name="onplaywallet-exporter-columns" class="onplaywallet-exporter-columns wc-enhanced-select" style="width:100%;" multiple data-placeholder="<?php esc_attr_e( 'Export all columns', 'onplay-wallet' ); ?>">
									<?php
									foreach ( $exporter->get_default_column_names() as $column_id => $column_name ) {
										echo '<option value="' . esc_attr( $column_id ) . '">' . esc_html( $column_name ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="onplaywallet-exporter-users"><?php esc_html_e( 'Which users should be exported?', 'onplay-wallet' ); ?></label>
							</th>
							<td>
								<select id="onplaywallet-exporter-users" name="onplaywallet-exporter-users" class="onplaywallet-exporter-users" style="width:100%;" multiple data-placeholder="<?php esc_attr_e( 'Export all users', 'onplay-wallet' ); ?>"></select>
							</td>
						</tr>
						<tr class="export-transaction-settings-fields">
							<th scope="row">
								<label for="onplaywallet-exporter-from-date"><?php esc_html_e( 'From date', 'onplay-wallet' ); ?></label>
							</th>
							<td>
								<input type="date" id="onplaywallet-exporter-from-date" name="onplaywallet-exporter-from-date" style="width: 100%" class="onplaywallet-exporter-from-date" />
							</td>
						</tr>
						<tr class="export-transaction-settings-fields">
							<th scope="row">
								<label for="onplaywallet-exporter-to-date"><?php esc_html_e( 'To date', 'onplay-wallet' ); ?></label>
							</th>
							<td>
								<input type="date" id="onplaywallet-exporter-to-date" name="onplaywallet-exporter-to-date" style="width: 100%" class="onplaywallet-exporter-to-date" />
							</td>
						</tr>
					</tbody>
				</table>
				<progress class="onplaywallet-exporter-progress" max="100" value="50"></progress>
			</section>
			<div class="tw-actions">
				<button type="submit" class="onplaywallet-exporter-button button button-primary" value="<?php esc_attr_e( 'Generate CSV', 'onplay-wallet' ); ?>"><?php esc_html_e( 'Generate CSV', 'onplay-wallet' ); ?></button>
			</div>
		</form>
	</div>
</div>
