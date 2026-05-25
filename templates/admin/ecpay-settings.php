<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="ysca-card">
	<div class="ysca-card__body">
		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'ECPay settings saved.', 'ys-cart-ecpay' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ysca-form">
			<input type="hidden" name="action" value="ys_cart_ecpay_save_settings">
			<?php wp_nonce_field( $nonce_action ); ?>

			<h2><?php esc_html_e( 'General', 'ys-cart-ecpay' ); ?></h2>
			<label class="ysca-field">
				<span class="ysca-field__label"><?php esc_html_e( 'Enable ECPay provider', 'ys-cart-ecpay' ); ?></span>
				<input type="checkbox" name="ys_ec_ecpay_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
			</label>

			<h2><?php esc_html_e( 'AIO Payment', 'ys-cart-ecpay' ); ?></h2>
			<div class="ysca-form-grid">
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Sandbox mode', 'ys-cart-ecpay' ); ?></span>
					<input type="checkbox" name="ys_ec_ecpay_payment_test_mode" value="1" <?php checked( $settings['payment_test_mode'] ); ?>>
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Merchant ID', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="text" name="ys_ec_ecpay_payment_merchant_id" value="<?php echo esc_attr( $settings['payment_merchant_id'] ); ?>" autocomplete="off">
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Hash Key', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="password" name="ys_ec_ecpay_payment_hash_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['payment_hash_key_is_set'] ? __( 'Saved. Leave blank to keep current value.', 'ys-cart-ecpay' ) : '' ); ?>">
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Hash IV', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="password" name="ys_ec_ecpay_payment_hash_iv" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['payment_hash_iv_is_set'] ? __( 'Saved. Leave blank to keep current value.', 'ys-cart-ecpay' ) : '' ); ?>">
				</label>
			</div>

			<h3><?php esc_html_e( 'Payment methods', 'ys-cart-ecpay' ); ?></h3>
			<p>
				<label><input type="checkbox" name="ys_ec_ecpay_credit_enabled" value="1" <?php checked( $settings['credit_enabled'] ); ?>> Credit Card</label><br>
				<label><input type="checkbox" name="ys_ec_ecpay_atm_enabled" value="1" <?php checked( $settings['atm_enabled'] ); ?>> ATM</label><br>
				<label><input type="checkbox" name="ys_ec_ecpay_cvs_enabled" value="1" <?php checked( $settings['cvs_enabled'] ); ?>> CVS Code</label><br>
				<label><input type="checkbox" name="ys_ec_ecpay_barcode_enabled" value="1" <?php checked( $settings['barcode_enabled'] ); ?>> Barcode</label>
			</p>

			<h2><?php esc_html_e( 'Domestic Logistics', 'ys-cart-ecpay' ); ?></h2>
			<div class="ysca-form-grid">
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Sandbox mode', 'ys-cart-ecpay' ); ?></span>
					<input type="checkbox" name="ys_ec_ecpay_logistics_test_mode" value="1" <?php checked( $settings['logistics_test_mode'] ); ?>>
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Merchant ID', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="text" name="ys_ec_ecpay_logistics_merchant_id" value="<?php echo esc_attr( $settings['logistics_merchant_id'] ); ?>" autocomplete="off">
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Hash Key', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="password" name="ys_ec_ecpay_logistics_hash_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['logistics_hash_key_is_set'] ? __( 'Saved. Leave blank to keep current value.', 'ys-cart-ecpay' ) : '' ); ?>">
				</label>
				<label class="ysca-field">
					<span class="ysca-field__label"><?php esc_html_e( 'Hash IV', 'ys-cart-ecpay' ); ?></span>
					<input class="regular-text" type="password" name="ys_ec_ecpay_logistics_hash_iv" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['logistics_hash_iv_is_set'] ? __( 'Saved. Leave blank to keep current value.', 'ys-cart-ecpay' ) : '' ); ?>">
				</label>
			</div>

			<h3><?php esc_html_e( 'Sender', 'ys-cart-ecpay' ); ?></h3>
			<div class="ysca-form-grid">
				<label class="ysca-field"><span class="ysca-field__label">Name</span><input type="text" name="ys_ec_ecpay_sender_name" value="<?php echo esc_attr( $settings['sender_name'] ); ?>"></label>
				<label class="ysca-field"><span class="ysca-field__label">Phone</span><input type="text" name="ys_ec_ecpay_sender_phone" value="<?php echo esc_attr( $settings['sender_phone'] ); ?>"></label>
				<label class="ysca-field"><span class="ysca-field__label">Zipcode</span><input type="text" name="ys_ec_ecpay_sender_zipcode" value="<?php echo esc_attr( $settings['sender_zipcode'] ); ?>"></label>
				<label class="ysca-field"><span class="ysca-field__label">Address</span><input type="text" class="regular-text" name="ys_ec_ecpay_sender_address" value="<?php echo esc_attr( $settings['sender_address'] ); ?>"></label>
			</div>

			<h3><?php esc_html_e( 'Shipping methods', 'ys-cart-ecpay' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th>Enabled</th><th>Method</th><th>Cost</th><th>Free threshold</th></tr></thead>
				<tbody>
				<?php foreach ( [
					'ship_family' => 'FamilyMart',
					'ship_unimart' => '7-ELEVEN',
					'ship_hilife' => 'Hi-Life',
					'ship_tcat' => 'TCAT',
					'ship_post' => 'Post',
				] as $key => $label ) : ?>
					<tr>
						<td><input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( $key ); ?>_enabled" value="1" <?php checked( $settings[ $key . '_enabled' ] ); ?>></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><input type="number" min="0" step="1" name="ys_ec_ecpay_<?php echo esc_attr( $key ); ?>_cost" value="<?php echo esc_attr( $settings[ $key . '_cost' ] ); ?>"></td>
						<td><input type="number" min="0" step="1" name="ys_ec_ecpay_<?php echo esc_attr( $key ); ?>_free_threshold" value="<?php echo esc_attr( $settings[ $key . '_free_threshold' ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save ECPay settings', 'ys-cart-ecpay' ); ?></button>
			</p>
		</form>
	</div>
</div>

