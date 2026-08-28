<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;
use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;

final class EcpayPrintController {
	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_print', [ __CLASS__, 'handle' ] );
	}

	public static function unregister(): void {
		remove_action( 'admin_post_ys_cart_ecpay_print', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ys-cart-ecpay' ), 403 );
		}

		// 🔴 key 的**確切格式**要在碰任何 transient 或送出任何 header 之前驗完。
		//
		// 舊版直接 `(string)` 轉型：`?key[]=x` 會發 array-to-string warning（而且是在輸出
		// 之前），並以捏造出來的識別碼 `Array` 去對 transient 做讀取**與無條件刪除**。
		// key 又沒有格式檢查，於是任何輸入都變成一次 transient 往返。
		//
		// 真實格式由鑄造端決定：`EcpayShippingRequester` 用
		// `wp_generate_password( 24, false, false )`，即 24 個 `[A-Za-z0-9]`。
		// 錯誤訊息維持既有那一句，不回顯 key、也不回顯 transient 名稱。
		// 驗的是 **unslash 後的原值**，不先 sanitize：這條 regex 比任何 sanitizer 都嚴格，
		// 讓閘門成為唯一權威。先 `sanitize_text_field()` 的話，`…01%0A` 會被 trim 成合法
		// 長度而通過——但鑄造端從來不會產生帶換行的 key。
		$raw_key = $_GET['key'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = is_string( $raw_key ) ? wp_unslash( $raw_key ) : null;
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z0-9]{24}$/D', $key ) ) {
			wp_die( esc_html__( 'Missing print payload.', 'ys-cart-ecpay' ), 400 );
		}

		$payload = get_transient( 'ys_ec_ecpay_print_' . $key );
		delete_transient( 'ys_ec_ecpay_print_' . $key );

		if ( ! is_array( $payload ) || empty( $payload['api_url'] ) || empty( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) {
			wp_die( esc_html__( 'Print payload expired.', 'ys-cart-ecpay' ), 410 );
		}

		$method_id = sanitize_key( (string) ( $payload['method_id'] ?? '' ) );
		if ( ! ShippingMethodOperability::is_operable( $method_id ) ) {
			wp_die( esc_html__( 'ECPay print method is disabled.', 'ys-cart-ecpay' ), 403 );
		}
		// 🔴 R14 reader lease：本頁輸出的表單會直接 POST 給綠界，且下方以當下
		// 憑證重驗 payload——設定 commit 期間不服務（維護結束後重按列印即可）。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			wp_die( esc_html__( '綠界設定維護中，請稍後再試。', 'ys-cart-ecpay' ), 503 );
		}
		$credentials = Settings::logistics_credentials_for_method( $method_id );
		$fields = $payload['fields'];
		$spec = EcpayShippingCatalog::print_spec( $method_id );
		$expected_api_url = null === $spec ? '' : Settings::logistics_endpoint( (string) $spec['path'], $method_id );
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			wp_die( esc_html__( '綠界設定維護中，請重新產生列印資料。', 'ys-cart-ecpay' ), 503 );
		}
		if ( '' === $expected_api_url
			|| ! hash_equals( $expected_api_url, (string) $payload['api_url'] )
			|| '' === $credentials['merchant_id']
			|| ! isset( $fields['MerchantID'], $fields['CheckMacValue'] )
			|| ! hash_equals( $credentials['merchant_id'], (string) $fields['MerchantID'] )
			|| ! CheckMacValue::verify( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' ) ) {
			wp_die( esc_html__( 'ECPay print credentials do not match this method.', 'ys-cart-ecpay' ), 403 );
		}

		$api_url = (string) $payload['api_url'];
		$host    = strtolower( (string) wp_parse_url( $api_url, PHP_URL_HOST ) );
		if ( ! in_array( $host, [ 'logistics.ecpay.com.tw', 'logistics-stage.ecpay.com.tw' ], true ) ) {
			wp_die( esc_html__( 'Unsupported print host.', 'ys-cart-ecpay' ), 400 );
		}
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			wp_die( esc_html__( '綠界設定維護中，請重新產生列印資料。', 'ys-cart-ecpay' ), 503 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		nocache_headers();

		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="utf-8">
	<title>ECPay Print</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
	<form id="ys-cart-ecpay-print" method="post" action="<?php echo esc_url( $api_url ); ?>">
		<?php foreach ( $payload['fields'] as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php endforeach; ?>
		<noscript><button type="submit">Print</button></noscript>
	</form>
	<script>document.getElementById('ys-cart-ecpay-print').submit();</script>
</body>
</html>
		<?php
		exit;
	}
}
