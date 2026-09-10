<?php
/**
 * 站內付 2.0 託管付款頁（v0.5.0）
 *
 * 由 EcpgPaymentController::pay_page() 以 $ecpg 餵入；獨立於佈景主題（付款頁不該被主題
 * 的 JS／CSS 影響綠界 SDK）。script 載入順序是官方硬規定：jQuery → node-forge → SDK → 本站。
 *
 * @var array<string,mixed> $ecpg
 */
defined( 'ABSPATH' ) || exit;

$ys_ecpg_config = wp_json_encode( $ecpg['config'] );
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $ecpg['site_name'] ); ?> — <?php esc_html_e( '信用卡付款', 'ys-cart-ecpay' ); ?></title>
	<style>
		:root { color-scheme: light; }
		* { box-sizing: border-box; }
		body { margin: 0; background: #f4f5f7; color: #1f2933; font: 15px/1.6 -apple-system, "Segoe UI", "Noto Sans TC", "PingFang TC", sans-serif; }
		.ys-ecpg { max-width: 560px; margin: 32px auto; padding: 0 16px; }
		.ys-ecpg__card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(16, 24, 40, .08); padding: 24px; }
		.ys-ecpg__head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 8px; }
		.ys-ecpg__site { font-weight: 600; }
		.ys-ecpg__amount { font-size: 22px; font-weight: 700; }
		.ys-ecpg__meta { color: #52606d; font-size: 13px; margin: 0 0 16px; }
		.ys-ecpg__badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #fff4e5; color: #b25e09; font-size: 12px; margin-left: 8px; }
		.ys-ecpg__saved { border: 1px solid #e4e7eb; border-radius: 10px; padding: 12px 16px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
		.ys-ecpg__saved-label { font-weight: 600; }
		.ys-ecpg__saved-sub { color: #52606d; font-size: 13px; }
		.ys-ecpg__btn { appearance: none; border: 0; border-radius: 8px; padding: 12px 18px; font-size: 15px; font-weight: 600; cursor: pointer; background: #1f6feb; color: #fff; }
		.ys-ecpg__btn[disabled] { opacity: .5; cursor: not-allowed; }
		.ys-ecpg__btn--ghost { background: #eef2f6; color: #1f2933; }
		.ys-ecpg__btn--block { width: 100%; margin-top: 16px; }
		.ys-ecpg__link { display: inline-block; margin-top: 12px; color: #1f6feb; }
		.ys-ecpg__link[hidden], [hidden] { display: none !important; }
		.ys-ecpg-status { min-height: 24px; margin: 12px 0 0; font-size: 14px; }
		.ys-ecpg-status--error { color: #b42318; }
		.ys-ecpg-status--ok { color: #027a48; }
		.ys-ecpg-status--info { color: #52606d; }
		#ECPayPayment { min-height: 120px; }
		.ys-ecpg__foot { color: #7b8794; font-size: 12px; text-align: center; margin-top: 16px; }
	</style>
</head>
<body>
<div class="ys-ecpg">
	<div class="ys-ecpg__card">
		<div class="ys-ecpg__head">
			<span class="ys-ecpg__site"><?php echo esc_html( $ecpg['site_name'] ); ?></span>
			<span class="ys-ecpg__amount">NT$ <?php echo esc_html( number_format( (int) $ecpg['amount'] ) ); ?></span>
		</div>
		<p class="ys-ecpg__meta">
			<?php
			printf(
				/* translators: %s: order number */
				esc_html__( '訂單編號 %s', 'ys-cart-ecpay' ),
				esc_html( $ecpg['order_number'] )
			);
			?>
			<?php if ( 'bind' === $ecpg['flow'] ) : ?>
				<span class="ys-ecpg__badge"><?php esc_html_e( '訂閱：付款後綁定此卡自動續扣', 'ys-cart-ecpay' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $ecpg['test_mode'] ) ) : ?>
				<span class="ys-ecpg__badge"><?php esc_html_e( '綠界測試環境', 'ys-cart-ecpay' ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $ecpg['saved_cards'] ) ) : ?>
			<?php foreach ( (array) $ecpg['saved_cards'] as $ys_ecpg_card ) : ?>
				<div class="ys-ecpg__saved">
					<div>
						<div class="ys-ecpg__saved-label"><?php echo esc_html( strtoupper( (string) $ys_ecpg_card['brand'] ) ); ?> •••• <?php echo esc_html( (string) $ys_ecpg_card['last4'] ); ?></div>
						<?php if ( '' !== (string) $ys_ecpg_card['expire'] ) : ?>
							<div class="ys-ecpg__saved-sub"><?php esc_html_e( '有效期限', 'ys-cart-ecpay' ); ?> <?php echo esc_html( (string) $ys_ecpg_card['expire'] ); ?></div>
						<?php endif; ?>
					</div>
					<button type="button" class="ys-ecpg__btn ys-ecpg-saved-pay" data-card-id="<?php echo esc_attr( (string) $ys_ecpg_card['id'] ); ?>"><?php esc_html_e( '用這張卡付款', 'ys-cart-ecpay' ); ?></button>
				</div>
			<?php endforeach; ?>
			<button type="button" id="ys-ecpg-choose-new" class="ys-ecpg__btn ys-ecpg__btn--ghost ys-ecpg__btn--block"><?php esc_html_e( '使用其他信用卡', 'ys-cart-ecpay' ); ?></button>
		<?php endif; ?>

		<div id="ys-ecpg-new-card" <?php echo empty( $ecpg['saved_cards'] ) ? '' : 'hidden'; ?>>
			<!-- 渲染綠界站內付 2.0 付款／綁卡畫面，請勿更動 id -->
			<div id="ECPayPayment"></div>
			<button type="button" id="ys-ecpg-pay" class="ys-ecpg__btn ys-ecpg__btn--block" disabled><?php esc_html_e( '確認付款', 'ys-cart-ecpay' ); ?></button>
		</div>

		<p id="ys-ecpg-status" class="ys-ecpg-status"></p>
		<a id="ys-ecpg-repay" class="ys-ecpg__link" href="<?php echo esc_url( $ecpg['repay_url'] ); ?>" hidden><?php esc_html_e( '重新付款', 'ys-cart-ecpay' ); ?></a>
	</div>
	<p class="ys-ecpg__foot"><?php esc_html_e( '卡號由綠界科技 ECPay 的付款元件直接收取並加密傳送，本站不經手、不儲存卡號。', 'ys-cart-ecpay' ); ?></p>
</div>
<script>window.ysEcpayEcpg = <?php echo $ys_ecpg_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output ?>;</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/node-forge@0.7.0/dist/forge.min.js"></script>
<script src="<?php echo esc_url( $ecpg['sdk_url'] ); ?>"></script>
<script src="<?php echo esc_url( $ecpg['script_url'] ); ?>"></script>
</body>
</html>
