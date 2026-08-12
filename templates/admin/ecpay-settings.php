<?php
defined( 'ABSPATH' ) || exit;

$tab                   = (string) ( $settings['tab'] ?? 'api' );
$tabs                  = (array) ( $settings['tabs'] ?? [] );
$page_url              = (string) ( $settings['page_url'] ?? admin_url( 'admin.php?page=ys-provider-ecpay' ) );
$shipping_settings_url = (string) ( $settings['shipping_settings_url'] ?? admin_url( 'admin.php?page=ys-ec-shipping' ) );
?>
<div class="ysca-page-root">
	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="ys-ec-notice ys-ec-notice-success">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( '綠界設定已儲存。', 'ys-cart-ecpay' ); ?>
		</div>
	<?php endif; ?>

	<div class="ys-ec-filters ysca-tabs ysca-tabs--with-indicator" role="tablist" aria-label="<?php esc_attr_e( '綠界設定分頁', 'ys-cart-ecpay' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<?php $is_active = $tab === (string) $key; ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', (string) $key, $page_url ) ); ?>"
			   class="ys-ec-filter-btn ysca-tab <?php echo $is_active ? 'active ysca-tab--active' : ''; ?>"
			   role="tab"
			   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
			   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( (string) $label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ysca-form">
		<input type="hidden" name="action" value="ys_cart_ecpay_save_settings">
		<input type="hidden" name="ys_ec_ecpay_tab" value="<?php echo esc_attr( $tab ); ?>">
		<?php wp_nonce_field( $nonce_action ); ?>

		<div class="ysca-card">
			<div class="ysca-card__body">
				<label class="ysca-switch-label">
					<span class="ysca-switch">
						<input type="checkbox" name="ys_ec_ecpay_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
						<span class="ysca-switch-slider"></span>
					</span>
					<strong><?php esc_html_e( '啟用綠界 ECPay', 'ys-cart-ecpay' ); ?></strong>
				</label>
				<p class="description"><?php esc_html_e( '供應商啟用後，才會顯示並註冊對應的金流、物流方法。', 'ys-cart-ecpay' ); ?></p>
			</div>
		</div>

		<?php if ( 'api' === $tab ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( 'AIO 金流 API', 'ys-cart-ecpay' ); ?></h2>
					<div class="ysca-form-grid">
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '測試模式', 'ys-cart-ecpay' ); ?></span>
							<input type="checkbox" name="ys_ec_ecpay_payment_test_mode" value="1" <?php checked( $settings['payment_test_mode'] ); ?>>
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '商店代號', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_payment_merchant_id" value="<?php echo esc_attr( $settings['payment_merchant_id'] ); ?>" autocomplete="off">
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( 'Hash Key', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_payment_hash_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['payment_hash_key_is_set'] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( 'Hash IV', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_payment_hash_iv" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['payment_hash_iv_is_set'] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
						</label>
					</div>
				</div>
			</div>

			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '物流 API', 'ys-cart-ecpay' ); ?></h2>
					<div class="ysca-form-grid">
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '測試模式', 'ys-cart-ecpay' ); ?></span>
							<input type="checkbox" name="ys_ec_ecpay_logistics_test_mode" value="1" <?php checked( $settings['logistics_test_mode'] ); ?>>
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '商店代號', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_logistics_merchant_id" value="<?php echo esc_attr( $settings['logistics_merchant_id'] ); ?>" autocomplete="off">
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( 'Hash Key', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_logistics_hash_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['logistics_hash_key_is_set'] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
						</label>
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( 'Hash IV', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_logistics_hash_iv" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['logistics_hash_iv_is_set'] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
						</label>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( 'payment' === $tab ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '金流方式', 'ys-cart-ecpay' ); ?></h2>
					<?php foreach ( (array) $settings['payment_methods'] as $key => $label ) : ?>
						<div class="ys-ec-form-group">
							<label class="ysca-switch-label">
								<span class="ysca-switch">
									<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( (string) $key ); ?>_enabled" value="1" <?php checked( $settings[ (string) $key . '_enabled' ] ); ?>>
									<span class="ysca-switch-slider"></span>
								</span>
								<strong><?php echo esc_html( (string) $label ); ?></strong>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( 'shipping' === $tab ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '寄件人資料', 'ys-cart-ecpay' ); ?></h2>
					<div class="ysca-form-grid">
						<label class="ysca-field"><span class="ysca-field__label"><?php esc_html_e( '寄件人姓名', 'ys-cart-ecpay' ); ?></span><input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_sender_name" value="<?php echo esc_attr( $settings['sender_name'] ); ?>"></label>
						<label class="ysca-field"><span class="ysca-field__label"><?php esc_html_e( '寄件人電話', 'ys-cart-ecpay' ); ?></span><input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_sender_phone" value="<?php echo esc_attr( $settings['sender_phone'] ); ?>"></label>
						<label class="ysca-field"><span class="ysca-field__label"><?php esc_html_e( '郵遞區號', 'ys-cart-ecpay' ); ?></span><input class="ysca-input ysca-field--compact" type="text" name="ys_ec_ecpay_sender_zipcode" value="<?php echo esc_attr( $settings['sender_zipcode'] ); ?>"></label>
						<label class="ysca-field"><span class="ysca-field__label"><?php esc_html_e( '寄件地址', 'ys-cart-ecpay' ); ?></span><input class="ysca-input ysca-field--lg" type="text" name="ys_ec_ecpay_sender_address" value="<?php echo esc_attr( $settings['sender_address'] ); ?>"></label>
					</div>
				</div>
			</div>

			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '物流方式', 'ys-cart-ecpay' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'B2C（大宗寄倉）與 C2C（店到店）是綠界兩份不同的合約，服務金鑰也不同，請依你實際申請的合約開啟對應的方式。', 'ys-cart-ecpay' ); ?>
					</p>

					<?php
					$ys_ec_channel_labels = [
						'b2c'  => __( 'B2C 大宗寄倉', 'ys-cart-ecpay' ),
						'c2c'  => __( 'C2C 店到店', 'ys-cart-ecpay' ),
						'home' => __( '宅配', 'ys-cart-ecpay' ),
					];
					$ys_ec_temp_labels    = [
						'0001' => __( '常溫', 'ys-cart-ecpay' ),
						'0002' => __( '冷藏', 'ys-cart-ecpay' ),
						'0003' => __( '冷凍', 'ys-cart-ecpay' ),
					];
					?>

					<?php foreach ( (array) $settings['shipping_methods'] as $key => $method ) : ?>
						<div class="ys-ec-form-group">
							<label class="ysca-switch-label">
								<span class="ysca-switch">
									<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( (string) $key ); ?>_enabled" value="1" <?php checked( $settings[ (string) $key . '_enabled' ] ); ?>>
									<span class="ysca-switch-slider"></span>
								</span>
								<strong><?php echo esc_html( (string) ( $method['label'] ?? $key ) ); ?></strong>
								<code class="ysca-code-pill"><?php echo esc_html( (string) ( $method['id'] ?? '' ) ); ?></code>
								<span class="description">
									<?php echo esc_html( (string) ( $ys_ec_channel_labels[ (string) ( $method['channel'] ?? '' ) ] ?? '' ) ); ?>
									·
									<?php echo esc_html( (string) ( $ys_ec_temp_labels[ (string) ( $method['temperature'] ?? '' ) ] ?? '' ) ); ?>
									·
									<?php echo esc_html( (string) ( $method['logistics_subtype'] ?? '' ) ); ?>
								</span>
							</label>

							<?php if ( ! empty( $method['supports_return_store'] ) ) : ?>
								<label class="ysca-field ysca-mt-sm">
									<span class="ysca-field__label"><?php esc_html_e( '退貨門市代號（選填）', 'ys-cart-ecpay' ); ?></span>
									<input class="ysca-input ysca-field--compact"
									       type="text"
									       name="ys_ec_ecpay_<?php echo esc_attr( (string) $key ); ?>_return_store_id"
									       value="<?php echo esc_attr( (string) ( $method['return_store_id'] ?? '' ) ); ?>"
									       autocomplete="off">
									<span class="description">
										<?php esc_html_e( '綠界規定僅 7-ELEVEN 交貨便適用此欄位，且為選填——未填寫時退貨會退回原寄件門市。', 'ys-cart-ecpay' ); ?>
									</span>
								</label>
							<?php endif; ?>

							<?php if ( ! empty( $method['requires_goods_weight'] ) ) : ?>
								<label class="ysca-field ysca-mt-sm">
									<span class="ysca-field__label"><?php esc_html_e( '包裹預設重量（公斤，必填）', 'ys-cart-ecpay' ); ?></span>
									<input class="ysca-input ysca-field--compact"
									       type="number" step="0.001" min="0" max="20"
									       name="ys_ec_ecpay_<?php echo esc_attr( (string) $key ); ?>_goods_weight"
									       value="<?php echo esc_attr( (string) ( $method['goods_weight'] ?? '' ) ); ?>">
									<span class="description">
										<?php esc_html_e( '綠界規定中華郵政建單必填重量（上限 20 公斤）。訂單本身算得出重量時優先使用訂單的值，此處為後援；未填寫時這個方式無法啟用。', 'ys-cart-ecpay' ); ?>
									</span>
								</label>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<p class="description ysca-mt-md">
						<?php esc_html_e( '運費、免運門檻與排序由 YS CART 物流設定管理。', 'ys-cart-ecpay' ); ?>
						<a href="<?php echo esc_url( $shipping_settings_url ); ?>"><?php esc_html_e( '前往物流設定', 'ys-cart-ecpay' ); ?></a>
					</p>
					<p class="description">
						<?php esc_html_e( '貨到付款是否代收，由訂單實際的付款方式決定，不需要（也不能）在這裡另外開關。', 'ys-cart-ecpay' ); ?>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( 'diagnostics' === $tab ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '回呼網址', 'ys-cart-ecpay' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<?php foreach ( (array) $settings['callback_urls'] as $label => $url ) : ?>
								<tr>
									<th><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $label ) ) ); ?></th>
									<td><code><?php echo esc_html( (string) $url ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>

		<div class="ysca-inline-actions ysca-inline-actions--start ysca-mt-md">
			<button type="submit" class="ysca-btn ysca-btn--primary">
				<span class="dashicons dashicons-saved ysca-icon--sm"></span>
				<?php esc_html_e( '儲存綠界設定', 'ys-cart-ecpay' ); ?>
			</button>
		</div>
	</form>
</div>
