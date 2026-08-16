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
	<?php
	$ys_ec_settings_errors = [
		'invalid_home_credential_family'      => __( '宅配憑證來源無效，設定未變更。', 'ys-cart-ecpay' ),
		'signer_change_requires_methods_disabled' => __( '本次儲存會改變物流簽章憑證來源。請先停用全部物流方式再變更（避免變更過程中建立的物流單使用不一致的憑證）。設定未變更。', 'ys-cart-ecpay' ),
		'signer_change_active_labels'         => __( '仍有未結束或升級前的物流單，為避免舊單回呼、查詢或列印失驗，本次會改變簽章憑證的儲存未套用。', 'ys-cart-ecpay' ),
		'signer_change_label_lookup_failed'   => __( '無法確認既有物流單狀態，已採安全模式，本次會改變簽章憑證的儲存未套用。', 'ys-cart-ecpay' ),
		'secret_encrypt_failed'               => __( '金鑰加密能力不可用（YS CART 核心未載入或版本過舊），設定未變更。', 'ys-cart-ecpay' ),
		'payment_signer_change_requires_methods_disabled' => __( '本次儲存會改變金流簽章憑證。請先停用全部綠界付款方式再變更（已簽出的結帳表單與進行中的付款會以舊憑證送回，變更會使其失驗）。設定未變更。', 'ys-cart-ecpay' ),
		'payment_signer_change_active_attempts' => __( '仍有未終局的綠界付款訂單（pending／offline_payment）。為避免其回呼與查詢失驗，本次會改變金流簽章憑證的儲存未套用；請待付款終局後再變更。', 'ys-cart-ecpay' ),
		'settings_crash_repair_requires_full_credentials' => __( '前次設定寫入未完整結束，簽章操作已安全停用。請在 API 設定頁重新輸入每一組 MerchantID／HashKey／HashIV，或明確勾選清除此組憑證，再儲存以完成全量修復。', 'ys-cart-ecpay' ),
		'settings_crash_repair_requires_api_tab' => __( '前次 API 憑證設定未完整結束，簽章操作仍安全停用；付款／物流方式頁不能解除此隔離。請回 API 設定頁完成全量憑證修復。', 'ys-cart-ecpay' ),
		'provider_lifecycle_commit_failed_rolled_back' => __( '供應商啟用狀態與 Core lifecycle 同步失敗，已還原原設定。', 'ys-cart-ecpay' ),
		'provider_lifecycle_rollback_failed' => __( '供應商啟用狀態同步失敗且無法完整還原；系統已維持簽章隔離。請先修復資料庫寫入問題，再到 API 設定頁完成全量修復。', 'ys-cart-ecpay' ),
		'method_lifecycle_commit_failed_rolled_back' => __( '付款／物流方式與 Core lifecycle 同步失敗，已還原本次設定。', 'ys-cart-ecpay' ),
		'method_lifecycle_rollback_failed' => __( '付款／物流方式同步失敗且無法完整還原；系統已維持簽章隔離。請先修復資料庫寫入問題，再到 API 設定頁完成全量修復。', 'ys-cart-ecpay' ),
		'payment_signer_change_attempt_lookup_failed' => __( '無法確認進行中付款訂單的狀態，已採安全模式，本次會改變金流簽章憑證的儲存未套用。', 'ys-cart-ecpay' ),
		'settings_maintenance_lock_unavailable' => __( '目前有另一個設定儲存、或簽章操作（建單／查詢／回呼／列印）正在進行中，請稍後再試。設定未變更。', 'ys-cart-ecpay' ),
		'settings_state_read_failed'          => __( '無法可靠讀取目前設定狀態（資料庫查詢失敗），已安全中止，設定未變更。', 'ys-cart-ecpay' ),
		'settings_commit_failed_rolled_back'  => __( '設定寫入未完全成功，已全數還原為儲存前狀態，請稍後重試。', 'ys-cart-ecpay' ),
		'signer_gate_rollback_failed'         => __( '設定寫入失敗且還原未完全成功——目前憑證狀態可能不一致，請立即檢查各組憑證欄位並重新儲存正確值。', 'ys-cart-ecpay' ),
		'signer_gate_rollback_failed'         => __( '簽章憑證變更被拒且自動還原失敗——設定可能處於中間狀態，請立即人工檢查全部憑證欄位。', 'ys-cart-ecpay' ),
	];
	$ys_ec_settings_error = sanitize_key( wp_unslash( (string) ( $_GET['settings_error'] ?? '' ) ) );
	?>
	<?php if ( isset( $ys_ec_settings_errors[ $ys_ec_settings_error ] ) ) : ?>
		<div class="ys-ec-notice ys-ec-notice-error">
			<span class="dashicons dashicons-warning"></span>
			<?php echo esc_html( $ys_ec_settings_errors[ $ys_ec_settings_error ] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( class_exists( \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::class )
		&& \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::crashed_flag_present() ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( '🔴 前次綠界設定儲存未正常完成（程序中斷），憑證狀態尚未驗證——所有綠界出網操作（建單／查詢／回呼驗章／列印）已暫停。請核對本頁各組憑證欄位後重新儲存一次；儲存成功即恢復。', 'ys-cart-ecpay' ); ?></p>
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
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '信用卡查詢檢查碼', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_payment_credit_check_code" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $settings['payment_credit_check_code_is_set'] ) ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
							<span class="ysca-field__hint"><?php esc_html_e( '綠界後台「信用卡收單」查詢檢查碼（CreditCheckCode）；信用卡退款的關帳狀態查詢必填。', 'ys-cart-ecpay' ); ?></span>
						</label>
					</div>
				</div>
			</div>

			<?php
			$ys_ec_logistics_groups = [
				'logistics_b2c_home' => __( '物流 API：B2C 超商／宅配', 'ys-cart-ecpay' ),
				'logistics_c2c'      => __( '物流 API：C2C 超商', 'ys-cart-ecpay' ),
			];
			?>
			<?php foreach ( $ys_ec_logistics_groups as $ys_ec_group => $ys_ec_group_label ) : ?>
				<div class="ysca-card ysca-mt-md">
					<div class="ysca-card__body">
						<h2><?php echo esc_html( $ys_ec_group_label ); ?></h2>
						<div class="ysca-form-grid">
							<label class="ysca-field">
								<span class="ysca-field__label"><?php esc_html_e( '測試模式', 'ys-cart-ecpay' ); ?></span>
								<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_test_mode" value="1" <?php checked( $settings[ $ys_ec_group . '_test_mode' ] ); ?>>
							</label>
							<label class="ysca-field">
								<span class="ysca-field__label"><?php esc_html_e( '商店代號', 'ys-cart-ecpay' ); ?></span>
								<input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_merchant_id" value="<?php echo esc_attr( $settings[ $ys_ec_group . '_merchant_id' ] ); ?>" autocomplete="off">
							</label>
							<label class="ysca-field">
								<span class="ysca-field__label"><?php esc_html_e( 'Hash Key', 'ys-cart-ecpay' ); ?></span>
								<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_hash_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings[ $ys_ec_group . '_hash_key_is_set' ] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
							</label>
							<label class="ysca-field">
								<span class="ysca-field__label"><?php esc_html_e( 'Hash IV', 'ys-cart-ecpay' ); ?></span>
								<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_hash_iv" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings[ $ys_ec_group . '_hash_iv_is_set' ] ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
							</label>
							<?php if ( 'payment' !== $ys_ec_group ) : ?>
								<label class="ysca-field">
									<span class="ysca-field__label"><?php esc_html_e( '清除此組憑證', 'ys-cart-ecpay' ); ?></span>
									<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_clear" value="1">
								</label>
								<p class="description"><?php esc_html_e( '勾選並儲存後，本組（測試模式／商店代號／Hash Key／Hash IV）全部清空。要啟用「重用金流憑證」時，所有物流憑證組必須為空。', 'ys-cart-ecpay' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '宅配憑證來源', 'ys-cart-ecpay' ); ?></h2>
					<label class="ysca-field">
						<span class="ysca-field__label"><?php esc_html_e( 'HOME（黑貓／郵局）使用', 'ys-cart-ecpay' ); ?></span>
						<select class="ysca-input ysca-field--md" name="ys_ec_ecpay_home_credential_family">
							<option value="b2c_home" <?php selected( $settings['home_credential_family'], 'b2c_home' ); ?>><?php esc_html_e( 'B2C／宅配憑證', 'ys-cart-ecpay' ); ?></option>
							<option value="c2c" <?php selected( $settings['home_credential_family'], 'c2c' ); ?>><?php esc_html_e( 'C2C 憑證', 'ys-cart-ecpay' ); ?></option>
						</select>
					</label>
					<p class="description"><?php esc_html_e( '請依綠界後台該 MerchantID 實際開通的宅配能力選擇。切換前必須先停用全部宅配方式，且不能有未結束或升級前的宅配物流單；系統不會自行複製或混用兩組金鑰。', 'ys-cart-ecpay' ); ?></p>
				</div>
			</div>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '重用金流憑證', 'ys-cart-ecpay' ); ?></h2>
					<label class="ysca-field">
						<span class="ysca-field__label"><?php esc_html_e( '物流簽章重用金流憑證', 'ys-cart-ecpay' ); ?></span>
						<input type="checkbox" name="ys_ec_ecpay_logistics_reuse_payment" value="1" <?php checked( ! empty( $settings['logistics_reuse_payment'] ) ); ?>>
					</label>
					<p class="description"><?php esc_html_e( '綠界商家通常金流與物流共用同一組 MerchantID／HashKey／HashIV。啟用後，只要上方所有物流憑證欄位（含舊版單一憑證）全部留空，物流建單、選店、查詢、回呼即以金流憑證簽章，環境（測試／正式）也以金流設定為準。任一物流欄位有值時本開關不生效，仍走該組憑證的原有規則。', 'ys-cart-ecpay' ); ?></p>
				</div>
			</div>
			<?php if ( ! empty( $settings['legacy_logistics_credentials_present'] ) ) : ?>
				<div class="ys-ec-notice ys-ec-notice-warning ysca-mt-md">
					<?php esc_html_e( '偵測到舊版單一物流憑證。請把它移轉到實際的 B2C 或 C2C 憑證欄位，並依商家合約選擇宅配憑證來源。', 'ys-cart-ecpay' ); ?>
				</div>
			<?php endif; ?>
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
						<?php esc_html_e( '超商 B2C／C2C 與宅配能力以綠界後台對該 MerchantID 的實際開通狀態為準；宅配使用哪組憑證請在 API 設定明確指定。', 'ys-cart-ecpay' ); ?>
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
