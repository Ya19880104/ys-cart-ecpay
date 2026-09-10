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
		'invalid_logistics_source'           => __( '物流設定選項無效，請重新選擇後儲存。設定未變更。', 'ys-cart-ecpay' ),
		'unsupported_payment_mode'            => __( '所選的交易模式本外掛尚未支援，設定未變更。目前僅支援「一般支付（導轉）」。', 'ys-cart-ecpay' ),
		'invalid_home_credential_family'      => __( '宅配憑證來源無效，設定未變更。', 'ys-cart-ecpay' ),
		'secret_encrypt_failed'               => __( '金鑰加密能力不可用（YS CART 核心未載入或版本過舊），設定未變更。', 'ys-cart-ecpay' ),
		'settings_crash_repair_requires_full_credentials' => __( '前次設定寫入未完整結束，簽章操作已安全停用。請在 API 設定頁重新輸入每一組 MerchantID／HashKey／HashIV，或明確勾選清除此組憑證，再儲存以完成全量修復。', 'ys-cart-ecpay' ),
		'settings_crash_repair_requires_api_tab' => __( '前次 API 憑證設定未完整結束，簽章操作仍安全停用；付款／物流方式頁不能解除此隔離。請回 API 設定頁完成全量憑證修復。', 'ys-cart-ecpay' ),
		'provider_lifecycle_commit_failed_rolled_back' => __( '供應商啟用狀態與 Core lifecycle 同步失敗，已還原原設定。', 'ys-cart-ecpay' ),
		'provider_lifecycle_rollback_failed' => __( '供應商啟用狀態同步失敗且無法完整還原；系統已維持簽章隔離。請先修復資料庫寫入問題，再到 API 設定頁完成全量修復。', 'ys-cart-ecpay' ),
		'method_lifecycle_commit_failed_rolled_back' => __( '付款／物流方式與 Core lifecycle 同步失敗，已還原本次設定。', 'ys-cart-ecpay' ),
		'method_lifecycle_rollback_failed' => __( '付款／物流方式同步失敗且無法完整還原；系統已維持簽章隔離。請先修復資料庫寫入問題，再到 API 設定頁完成全量修復。', 'ys-cart-ecpay' ),
		'settings_maintenance_lock_unavailable' => __( '目前有另一個設定儲存、或簽章操作（建單／查詢／回呼／列印）正在進行中，請稍後再試。設定未變更。', 'ys-cart-ecpay' ),
		'settings_state_read_failed'          => __( '無法可靠讀取目前設定狀態（資料庫查詢失敗），已安全中止，設定未變更。', 'ys-cart-ecpay' ),
		'settings_commit_failed_rolled_back'  => __( '設定寫入未完全成功，已全數還原為儲存前狀態，請稍後重試。', 'ys-cart-ecpay' ),
		'signer_gate_rollback_failed'         => __( '設定寫入失敗且還原未完全成功——目前憑證狀態可能不一致，請立即檢查各組憑證欄位並重新儲存正確值。', 'ys-cart-ecpay' ),
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
			<?php
			$ys_ec_mode      = (string) ( $settings['payment_mode'] ?? 'redirect' );
			$ys_ec_supported = (array) ( $settings['payment_modes_implemented'] ?? [ 'redirect' ] );
			$ys_ec_mode_can  = static fn( string $m ): bool => in_array( $m, $ys_ec_supported, true );
			?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '交易模式', 'ys-cart-ecpay' ); ?></h2>
					<p class="description"><?php esc_html_e( '綠界的金流分成幾種各自獨立的服務。以下列出本外掛目前支援與尚未支援的模式，未支援者僅供了解與提前申請。', 'ys-cart-ecpay' ); ?></p>

					<label class="ysca-choice ysca-mt-md">
						<input type="radio" name="ys_ec_ecpay_payment_mode" value="redirect" <?php checked( $ys_ec_mode, 'redirect' ); ?> <?php disabled( ! $ys_ec_mode_can( 'redirect' ) ); ?>>
						<strong><?php esc_html_e( '一般支付（導轉）', 'ys-cart-ecpay' ); ?></strong>
						<span class="ys-ec-badge ys-ec-badge-green ysca-badge--xs"><?php esc_html_e( '目前支援', 'ys-cart-ecpay' ); ?></span>
					</label>
					<p class="description ysca-choice-copy">
						<?php esc_html_e( '消費者跳轉到綠界付款頁完成付款，卡號全程不經過本站，因此沒有 PCI-DSS 負擔。', 'ys-cart-ecpay' ); ?><br>
						<?php esc_html_e( '涵蓋：信用卡、ATM 虛擬帳號、超商代碼、超商條碼。', 'ys-cart-ecpay' ); ?><br>
						<?php esc_html_e( '⚠ 這個模式不支援訂閱自動扣款——訂閱訂單會照常建立，但需要顧客自行付款。', 'ys-cart-ecpay' ); ?>
					</p>

					<label class="ysca-choice ysca-mt-md">
						<input type="radio" name="ys_ec_ecpay_payment_mode" value="ecpg_web" <?php checked( $ys_ec_mode, 'ecpg_web' ); ?> <?php disabled( ! $ys_ec_mode_can( 'ecpg_web' ) ); ?>>
						<strong><?php esc_html_e( '站內付 2.0 Web（特店專用）', 'ys-cart-ecpay' ); ?></strong>
						<span class="ys-ec-badge ys-ec-badge-gray ysca-badge--xs"><?php esc_html_e( '全域切換尚未支援；綁卡信用卡已以付款方式提供', 'ys-cart-ecpay' ); ?></span>
					</label>
					<p class="description ysca-choice-copy">
						<?php esc_html_e( '由綠界的 JS 元件在本站頁面直接渲染付款欄位，消費者不離開結帳頁；卡號輸入後直送綠界，綠界官方載明此模式無需 PCI-DSS 認證。', 'ys-cart-ecpay' ); ?><br>
						<?php esc_html_e( '需要先向綠界申請開通此服務（一般特約商店預設沒有）。', 'ys-cart-ecpay' ); ?><br>
						<?php esc_html_e( 'v0.5.0 起，「信用卡（站內付 2.0 綁卡）」在「金流方式」分頁以獨立付款方式提供，可與導轉方式並存：訂閱商品用它綁卡自動續扣，一般商品可繼續走導轉。這裡的模式切換只影響一般支付。', 'ys-cart-ecpay' ); ?>
					</p>
					<details class="ysca-mt-md">
						<summary><?php esc_html_e( '站內付 2.0 開通方式與伺服器資訊', 'ys-cart-ecpay' ); ?></summary>
						<div class="ysca-panel--warning ysca-stack-sm ysca-mt-md">
							<ol>
								<li><?php esc_html_e( '登入綠界廠商後台，向綠界業務或客服（02-2655-1775）申請開通「站內付 2.0」。', 'ys-cart-ecpay' ); ?></li>
								<li><?php esc_html_e( '確認合約模式（代收付／新型閘道）與要開通的付款方式。', 'ys-cart-ecpay' ); ?></li>
								<li><?php esc_html_e( '若綠界要求提供本站的伺服器來源 IP，可從下方複製。', 'ys-cart-ecpay' ); ?></li>
							</ol>
						</div>
						<div class="ys-ec-form-group ysca-mt-md">
							<label><strong><?php esc_html_e( '伺服器對外 IP', 'ys-cart-ecpay' ); ?></strong></label>
							<div class="ysca-inline-actions ysca-inline-actions--start">
								<code id="ys-ec-ecpay-server-ip" class="ysca-code-pill ysca-code-pill--lg"><?php esc_html_e( '查詢中…', 'ys-cart-ecpay' ); ?></code>
								<button type="button" id="ys-ec-ecpay-refresh-ip" class="ysca-btn ysca-btn--sm"><?php esc_html_e( '重新查詢', 'ys-cart-ecpay' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( '綠界官方文件並未要求設定 IP 白名單；此處僅在綠界主動索取來源 IP 時提供方便複製。', 'ys-cart-ecpay' ); ?></p>
						</div>
					</details>

					<label class="ysca-choice ysca-mt-md">
						<input type="radio" name="ys_ec_ecpay_payment_mode" value="period" <?php checked( $ys_ec_mode, 'period' ); ?> <?php disabled( ! $ys_ec_mode_can( 'period' ) ); ?>>
						<strong><?php esc_html_e( '定期定額（訂閱）', 'ys-cart-ecpay' ); ?></strong>
						<span class="ys-ec-badge ys-ec-badge-gray ysca-badge--xs"><?php esc_html_e( '規劃中', 'ys-cart-ecpay' ); ?></span>
					</label>
					<p class="description ysca-choice-copy">
						<?php esc_html_e( '建立委託後由綠界自己排程扣款，每期扣款結果再回呼通知本站；金額固定、期數固定。', 'ys-cart-ecpay' ); ?><br>
						<?php esc_html_e( '與本站訂閱模組的對接流程尚未實作。', 'ys-cart-ecpay' ); ?>
					</p>

					<p class="description ysca-mt-md">
						<?php esc_html_e( '另有「信用卡幕後授權」（卡號由本站直接傳送給綠界）需通過 PCI-DSS SAQ-D 認證，本外掛不支援、也不規劃支援。', 'ys-cart-ecpay' ); ?>
					</p>
				</div>
			</div>
			<script>
			(function () {
				var el = document.getElementById('ys-ec-ecpay-server-ip');
				var btn = document.getElementById('ys-ec-ecpay-refresh-ip');
				if (!el || !btn) { return; }
				var root = (typeof ysEcAdmin !== 'undefined' && ysEcAdmin.headlessRoot) ? ysEcAdmin.headlessRoot : <?php echo wp_json_encode( rest_url( 'ys-ecommerce-headless/v1' ) ); ?>;
				var nonce = (typeof ysEcAdmin !== 'undefined' && ysEcAdmin.restNonce) ? ysEcAdmin.restNonce : <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
				function load() {
					el.textContent = <?php echo wp_json_encode( __( '查詢中…', 'ys-cart-ecpay' ) ); ?>;
					fetch(root + '/admin/server/info', { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (b) {
							el.textContent = (b && b.data && b.data.ip)
								? b.data.ip
								: <?php echo wp_json_encode( __( '查詢失敗', 'ys-cart-ecpay' ) ); ?>;
						})
						.catch(function () { el.textContent = <?php echo wp_json_encode( __( '連線錯誤', 'ys-cart-ecpay' ) ); ?>; });
				}
				btn.addEventListener('click', load);
				load();
			}());
			</script>
			<div class="ys-ec-notice ys-ec-notice-warning ysca-mt-md">
				<p><?php esc_html_e( '更換金鑰會造成原本已綁定付款的用戶失效，請小心操作。進行中的付款與物流單也可能受影響。', 'ys-cart-ecpay' ); ?></p>
			</div>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '金流設定', 'ys-cart-ecpay' ); ?></h2>
					<div class="ysca-form-grid">
						<label class="ysca-field">
							<span class="ysca-field__label"><?php esc_html_e( '金流測試模式', 'ys-cart-ecpay' ); ?></span>
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
					<p class="description">
						<?php if ( ! empty( $settings['payment_test_mode'] ) ) : ?>
							<strong><?php esc_html_e( '目前為測試環境', 'ys-cart-ecpay' ); ?></strong>：<?php esc_html_e( '交易送往 payment-stage.ecpay.com.tw，不會真的扣款。請填綠界提供的測試商店代號與金鑰。', 'ys-cart-ecpay' ); ?>
						<?php else : ?>
							<strong><?php esc_html_e( '目前為正式環境', 'ys-cart-ecpay' ); ?></strong>：<?php esc_html_e( '交易送往 payment.ecpay.com.tw，會真實扣款。請確認填的是綠界核發的正式商店代號與金鑰。', 'ys-cart-ecpay' ); ?>
						<?php endif; ?>
						<br><?php esc_html_e( '共用此組的物流會一起套用上方金流的測試模式與商店設定。', 'ys-cart-ecpay' ); ?>
					</p>
					<details class="ysca-mt-md">
						<summary><?php esc_html_e( '信用卡退款進階設定（選填）', 'ys-cart-ecpay' ); ?></summary>
						<label class="ysca-field ysca-mt-md">
							<span class="ysca-field__label"><?php esc_html_e( '商家檢查碼', 'ys-cart-ecpay' ); ?></span>
							<input class="ysca-input ysca-field--md" type="password" name="ys_ec_ecpay_payment_credit_check_code" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $settings['payment_credit_check_code_is_set'] ) ? __( '已儲存，留空不變更', 'ys-cart-ecpay' ) : '' ); ?>">
							<span class="ysca-field__hint"><?php esc_html_e( '一般收款與儲存設定不需要，沒有可留空；外掛的信用卡退款查詢需要使用。', 'ys-cart-ecpay' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( '請至綠界後台「信用卡收單 → 信用卡授權資訊」查看。', 'ys-cart-ecpay' ); ?> <a href="https://developers.ecpay.com.tw/2894/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '綠界官方說明', 'ys-cart-ecpay' ); ?></a></p>
					</details>
				</div>
			</div>

			<?php
			$ys_ec_logistics_groups = [
				'logistics_b2c_home' => __( 'B2C 超商／宅配設定', 'ys-cart-ecpay' ),
				'logistics_c2c'      => __( 'C2C 店到店設定', 'ys-cart-ecpay' ),
			];
			?>
			<?php foreach ( $ys_ec_logistics_groups as $ys_ec_group => $ys_ec_group_label ) : ?>
				<?php
				$ys_ec_source_mode = (string) ( $settings[ $ys_ec_group . '_source_mode' ] ?? 'disabled' );
				$ys_ec_source_id   = 'ys-ec-ecpay-' . $ys_ec_group . '-source';
				$ys_ec_fields_id   = 'ys-ec-ecpay-' . $ys_ec_group . '-fields';
				?>
				<div class="ysca-card ysca-mt-md">
					<div class="ysca-card__body">
						<h2><label for="<?php echo esc_attr( $ys_ec_source_id ); ?>"><?php echo esc_html( $ys_ec_group_label ); ?></label></h2>
						<select class="ysca-input ysca-field--md" id="<?php echo esc_attr( $ys_ec_source_id ); ?>" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_source" aria-controls="<?php echo esc_attr( $ys_ec_fields_id ); ?>" data-ys-ecpay-logistics-source>
							<option value="disabled" <?php selected( $ys_ec_source_mode, 'disabled' ); ?>><?php esc_html_e( '不使用', 'ys-cart-ecpay' ); ?></option>
							<option value="payment" <?php selected( $ys_ec_source_mode, 'payment' ); ?>><?php esc_html_e( '共用上方金流設定', 'ys-cart-ecpay' ); ?></option>
							<option value="separate" <?php selected( $ys_ec_source_mode, 'separate' ); ?>><?php esc_html_e( '分開設定', 'ys-cart-ecpay' ); ?></option>
							<?php if ( 'legacy' === $ys_ec_source_mode ) : ?>
								<option value="legacy" selected="selected"><?php esc_html_e( '保留目前設定（舊版）', 'ys-cart-ecpay' ); ?></option>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( '只有選擇「分開設定」時，才會套用下方欄位。', 'ys-cart-ecpay' ); ?></p>
						<fieldset class="ysca-fieldset ysca-mt-md" id="<?php echo esc_attr( $ys_ec_fields_id ); ?>">
							<legend class="ysca-fieldset__legend"><?php esc_html_e( '分開設定', 'ys-cart-ecpay' ); ?></legend>
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
								<label class="ysca-field">
									<span class="ysca-field__label"><?php esc_html_e( '清除此組憑證', 'ys-cart-ecpay' ); ?></span>
									<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( $ys_ec_group ); ?>_clear" value="1">
								</label>
							</div>
							<p class="description"><?php esc_html_e( '勾選並儲存後，清除此組的測試模式與商店設定。', 'ys-cart-ecpay' ); ?></p>
						</fieldset>
					</div>
				</div>
			<?php endforeach; ?>
			<?php
			$ys_ec_home_family   = (string) ( $settings['home_credential_family'] ?? '' );
			$ys_ec_b2c_in_use    = 'disabled' !== (string) ( $settings['logistics_b2c_home_source_mode'] ?? 'disabled' );
			$ys_ec_c2c_in_use    = 'disabled' !== (string) ( $settings['logistics_c2c_source_mode'] ?? 'disabled' );
			// 兩組都在使用時才真的有得選。但若宅配目前指向的那一組已停用，必須讓管理員看見並改選；
			// 欄位沒有顯示時就不會送出，後端把「未提供」視為不變更（不會切回預設）。
			$ys_ec_home_conflict = ( 'c2c' === $ys_ec_home_family && ! $ys_ec_c2c_in_use )
				|| ( 'b2c_home' === $ys_ec_home_family && ! $ys_ec_b2c_in_use );
			$ys_ec_show_home     = ( $ys_ec_b2c_in_use && $ys_ec_c2c_in_use ) || $ys_ec_home_conflict;
			?>
			<?php if ( $ys_ec_show_home ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<details<?php echo $ys_ec_home_conflict ? ' open' : ''; ?>>
						<summary><?php esc_html_e( '宅配使用的設定', 'ys-cart-ecpay' ); ?></summary>
					<?php if ( $ys_ec_home_conflict ) : ?>
						<div class="ys-ec-notice ys-ec-notice-warning ysca-mt-md">
							<p><?php esc_html_e( '宅配目前指向的那一組已設為「不使用」，黑貓／郵局宅配將沒有可用的設定。請改選另一組，或把該組改回使用。', 'ys-cart-ecpay' ); ?></p>
						</div>
					<?php endif; ?>
					<label class="ysca-field ysca-mt-md">
						<span class="ysca-field__label"><?php esc_html_e( '黑貓／郵局宅配使用', 'ys-cart-ecpay' ); ?></span>
						<select class="ysca-input ysca-field--md" name="ys_ec_ecpay_home_credential_family">
							<option value="b2c_home" <?php selected( $ys_ec_home_family, 'b2c_home' ); ?>><?php esc_html_e( 'B2C／宅配這組', 'ys-cart-ecpay' ); ?></option>
							<option value="c2c" <?php selected( $ys_ec_home_family, 'c2c' ); ?>><?php esc_html_e( 'C2C 這組', 'ys-cart-ecpay' ); ?></option>
						</select>
					</label>
					<p class="description"><?php esc_html_e( '黑貓／郵局宅配依綠界合約可能掛在 B2C 或 C2C 的商店代號下；兩組都有使用時，請依綠界實際為你開通宅配的那一組選擇。', 'ys-cart-ecpay' ); ?></p>
					</details>
				</div>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( 'payment' === $tab ) : ?>
			<div class="ysca-card ysca-mt-md">
				<div class="ysca-card__body">
					<h2><?php esc_html_e( '金流方式', 'ys-cart-ecpay' ); ?></h2>
					<p class="description"><?php esc_html_e( '以下為一般支付（導轉）可用的付款方式。消費者會跳到綠界付款頁完成付款，卡號全程不經過本站。「信用卡（站內付 2.0 綁卡）」例外：卡號在本站頁面由綠界元件收取、直送綠界，付款成功後可自動續扣訂閱。', 'ys-cart-ecpay' ); ?></p>
					<?php foreach ( (array) $settings['payment_methods'] as $ys_ec_pm_key => $ys_ec_pm ) : ?>
						<?php
						$ys_ec_pm_label      = is_array( $ys_ec_pm ) ? (string) ( $ys_ec_pm['label'] ?? '' ) : (string) $ys_ec_pm;
						$ys_ec_pm_activation = is_array( $ys_ec_pm ) ? (string) ( $ys_ec_pm['activation'] ?? '' ) : '';
						?>
						<div class="ys-ec-form-group">
							<label class="ysca-switch-label">
								<span class="ysca-switch">
									<input type="checkbox" name="ys_ec_ecpay_<?php echo esc_attr( (string) $ys_ec_pm_key ); ?>_enabled" value="1" <?php checked( $settings[ (string) $ys_ec_pm_key . '_enabled' ] ); ?>>
									<span class="ysca-switch-slider"></span>
								</span>
								<strong><?php echo esc_html( $ys_ec_pm_label ); ?></strong>
							</label>
							<?php if ( '' !== $ys_ec_pm_activation ) : ?>
								<p class="description"><?php echo esc_html( $ys_ec_pm_activation ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( '標示「需先向綠界申請開通」的方式預設關閉。沒開通就開啟，消費者會走到綠界付款頁才被擋下——請先在綠界廠商後台確認已開通再啟用。', 'ys-cart-ecpay' ); ?>
					</p>

					<h2 class="ysca-mt-md"><?php esc_html_e( '分期期數', 'ys-cart-ecpay' ); ?></h2>
					<label class="ysca-field">
						<span class="ysca-field__label"><?php esc_html_e( '開放的期數（逗號分隔）', 'ys-cart-ecpay' ); ?></span>
						<input class="ysca-input ysca-field--md" type="text" name="ys_ec_ecpay_credit_installment_periods" value="<?php echo esc_attr( (string) ( $settings['credit_installment_periods'] ?? '' ) ); ?>" placeholder="3,6,12">
					</label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: comma-separated list of the instalment periods ECPay accepts. */
							esc_html__( '綠界接受的期數：%s（30N 為永豐 30 期；5、8、9、10 需為閘道商合約）。只填你已向綠界開通的期數；不在清單內的值會被忽略。留空則「信用卡分期」不會出現在結帳頁。', 'ys-cart-ecpay' ),
							esc_html( implode( '、', (array) ( $settings['credit_installment_periods_allowed'] ?? [] ) ) )
						);
						?>
					</p>

					<details class="ysca-mt-md">
						<summary><?php esc_html_e( '分期手續費怎麼算？', 'ys-cart-ecpay' ); ?></summary>
						<div class="ysca-stack-sm ysca-mt-md">
							<p class="description">
								<?php esc_html_e( '綠界導轉的分期，期數是消費者在綠界付款頁上選的——我們送出訂單時還不知道最後會分幾期，因此沒辦法在結帳頁依期數加收手續費。', 'ys-cart-ecpay' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( '要讓消費者負擔分期成本，用綠界自己的「消費者自費分期」：', 'ys-cart-ecpay' ); ?></strong><br>
								<?php esc_html_e( '訂單滿 1,000 元時綠界會在付款頁提供，手續費由消費者一次付清，商家收到全額，不需要我們加價。它是信用卡一次付清與分期的附加服務，預設就開著（一般前台會員無法申請關閉；特約會員要關閉請洽所屬業務）。', 'ys-cart-ecpay' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( '⚠ 綠界規則：若廠商未開通所選期數，交易會自動改為信用卡一次付清。這是一筆看起來成功的交易，消費者卻沒有分到期。本外掛會比對綠界回報的實際期數，落回一次付清時在訂單的付款紀錄留下標記並寫入錯誤日誌。', 'ys-cart-ecpay' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( '⚠ 綠界規則：分期不可與紅利折抵、定期定額同時設定；銀聯卡不支援分期；卡號判定為簽帳金融卡時綠界會擋下分期交易。', 'ys-cart-ecpay' ); ?>
							</p>
						</div>
					</details>
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
