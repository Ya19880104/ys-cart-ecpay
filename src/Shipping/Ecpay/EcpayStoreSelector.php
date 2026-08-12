<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\Ecommerce\Services\Setup\YSPageResolver;

final class EcpayStoreSelector {
	private const MAP_PATH = '/Express/map';

	/** 核心的貨到付款金流 ID（與 requester 用同一個常數語意）。 */
	public const COD_GATEWAY_ID = 'ys_ec_cod';

	/**
	 * 物流方式 → 綠界 LogisticsSubType。
	 *
	 * 🔴 這裡**不再**自己維護一份清單，一律向 {@see EcpayShippingCatalog} 查。
	 * 舊版在這裡抄了第二份 subtype 表，於是「型錄加了方式、地圖沒加」就成了
	 * 一種可能——症狀是後台開得起來、按下選擇門市卻回 false，看起來像設定沒存好。
	 *
	 * @return array<string,string>
	 */
	private static function subtypes(): array {
		return EcpayShippingCatalog::map_subtypes();
	}

	/**
	 * 電子地圖表單資料。
	 *
	 * @param string $shipping_id    物流方式 ID。
	 * @param string $payment_method 顧客**當下選定的付款方式**；決定 IsCollection。
	 *
	 * @return array{action_url:string,fields:array<string,string>,temp_id:string,collection_mode:string}|false
	 */
	public static function build_map_form_data(
		string $shipping_id,
		string $context = 'checkout',
		int $order_id = 0,
		string $cart_scope = 'default',
		string $return_url = '',
		string $payment_method = ''
	) {
		$descriptor = EcpayShippingCatalog::get( $shipping_id );
		$subtypes   = self::subtypes();

		if ( null === $descriptor
			|| ! isset( $subtypes[ $shipping_id ] )
			|| ! self::is_method_enabled( $shipping_id )
			|| ! Settings::has_logistics_credentials() ) {
			return false;
		}

		// C2C 沒填退貨門市時連地圖都不要開：顧客選完門市、結帳完成，送單那一刻
		// 才失敗，善後成本高得多。
		$method = self::instantiate( $shipping_id );
		if ( null === $method || ! $method->is_configured() ) {
			return false;
		}

		$is_collection = self::resolve_collection_mode( $method, $payment_method );
		$cart_scope    = self::sanitize_cart_scope( $cart_scope );
		$return_url    = self::sanitize_return_url( $return_url, $cart_scope );
		$credentials   = Settings::logistics_credentials();
		$temp_id       = wp_generate_uuid4();
		// 地圖用的交易編號只用於這一次選店的來回比對，與送單的 MerchantTradeNo
		// 無關，因此可以是隨機值；重點是回呼必須**帶回同一個**。
		$merchant_trade_no = substr(
			(string) preg_replace( '/[^A-Za-z0-9]/', '', 'YSMAP' . wp_generate_password( 20, false, false ) ),
			0,
			20
		);

		set_transient( 'ys_ec_ecpay_map_' . $temp_id, [
			'shipping_id'       => $shipping_id,
			'logistics_subtype' => $subtypes[ $shipping_id ],
			'user_id'           => get_current_user_id(),
			'context'           => $context,
			'order_id'          => $order_id,
			'cart_scope'        => $cart_scope,
			'return_url'        => $return_url,
			'merchant_trade_no' => $merchant_trade_no,
			// 🔴 這次選店是在「要不要代收」的哪一種前提下做的。綠界會依此篩門市，
			// 因此它必須跟著這次 session 走，之後才驗得出「選店與送單前提不同」。
			'payment_method'    => $payment_method,
			'is_collection'     => $is_collection,
			'created_at'        => current_time( 'timestamp' ),
		], 30 * MINUTE_IN_SECONDS );

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $merchant_trade_no,
			'LogisticsType'     => 'CVS',
			'LogisticsSubType'  => $subtypes[ $shipping_id ],
			// 🔴 代收與否要與送單一致。地圖送 'N'、送單送 'Y' 時，綠界會依
			// 「不代收」篩門市——顧客選得了的門市卻可能不支援代收，建單就失敗。
			'IsCollection'      => $is_collection ? 'Y' : 'N',
			'ServerReplyURL'    => rest_url( 'ys-ecommerce/v1/ecpay/store-callback' ),
			'ExtraData'         => $temp_id,
			'Device'            => wp_is_mobile() ? '1' : '0',
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
		];

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		return [
			'action_url'      => Settings::logistics_endpoint( self::MAP_PATH ),
			'fields'          => $fields,
			'temp_id'         => $temp_id,
			'collection_mode' => $is_collection ? 'Y' : 'N',
		];
	}

	/**
	 * 這次選店是不是在「代收」前提下進行。
	 *
	 * 與 requester 的 `resolve_is_collection()` 是同一條規則：只認訂單／購物車
	 * **實際的付款方式**，不看任何後台開關。兩邊用同一條規則，才不會出現
	 * map=N、create=Y。
	 */
	private static function resolve_collection_mode( EcpayShipping $method, string $payment_method ): bool {
		if ( self::COD_GATEWAY_ID !== trim( $payment_method ) ) {
			return false;
		}

		return $method->supports_cod();
	}

	/**
	 * 既有的門市選擇，在付款方式變成 $payment_method 之後還算不算數。
	 *
	 * 🔴 不算數就必須重新選店。舊選擇是在另一種代收前提下由綠界篩出來的門市，
	 * 沿用它會得到「顧客選得到、建單被拒」——而那時候顧客已經結完帳了。
	 *
	 * @param array<string,mixed> $selection 門市選擇（store callback 的 payload）。
	 */
	public static function selection_requires_reselect( array $selection, string $payment_method ): bool {
		$shipping_id = (string) ( $selection['shipping_id'] ?? '' );
		$method      = self::instantiate( $shipping_id );
		if ( null === $method ) {
			return true;
		}

		// 缺少當初的前提就是無法證明它相符——重選比猜安全。
		if ( ! array_key_exists( 'collection_mode', $selection ) ) {
			return true;
		}

		$was = 'Y' === (string) $selection['collection_mode'];
		$now = self::resolve_collection_mode( $method, $payment_method );

		return $was !== $now;
	}

	public static function handle_store_callback( \WP_REST_Request $request ): void {
		$params   = self::params( $request );
		$temp_id  = (string) ( $params['ExtraData'] ?? $params['TempId'] ?? '' );
		$map_data = '' !== $temp_id ? get_transient( 'ys_ec_ecpay_map_' . $temp_id ) : false;

		if ( ! is_array( $map_data ) ) {
			wp_die( 'Invalid map session.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		if ( ! self::validate_map_owner( $map_data ) ) {
			wp_die( 'Invalid map session owner.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		$shipping_id = (string) ( $map_data['shipping_id'] ?? '' );
		if ( '' === $shipping_id || ! isset( self::subtypes()[ $shipping_id ] ) || ! self::is_method_enabled( $shipping_id ) ) {
			wp_die( 'Shipping method disabled.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		if ( ! self::verify_map_payload( $params, $map_data ) ) {
			wp_die( 'Invalid CheckMacValue.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$store_id = trim( (string) ( $params['CVSStoreID'] ?? '' ) );
		if ( '' === $store_id ) {
			wp_die( 'Missing store id.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$store_info = [
			'store_id'        => $store_id,
			'store_name'      => (string) ( $params['CVSStoreName'] ?? '' ),
			'store_address'   => (string) ( $params['CVSAddress'] ?? '' ),
			'store_phone'     => (string) ( $params['CVSTelephone'] ?? '' ),
			'cvs_type'        => (string) ( $map_data['logistics_subtype'] ?? '' ),
			'cvs_store_id'    => $store_id,
			'cvs_store_name'  => (string) ( $params['CVSStoreName'] ?? '' ),
			'cvs_store_addr'  => (string) ( $params['CVSAddress'] ?? '' ),
			'shipping_id'     => $shipping_id,
			// 🔴 這次選店的前提跟著結果一起回去。前端據此判斷「顧客改了付款方式
			// 之後這個門市還能不能用」（見 selection_requires_reselect()）。
			'collection_mode' => ( ! empty( $map_data['is_collection'] ) ) ? 'Y' : 'N',
			'payment_method'  => (string) ( $map_data['payment_method'] ?? '' ),
			'context'         => (string) ( $map_data['context'] ?? 'checkout' ),
			'order_id'        => (int) ( $map_data['order_id'] ?? 0 ),
			'cart_scope'      => self::sanitize_cart_scope( (string) ( $map_data['cart_scope'] ?? 'default' ) ),
			'return_url'      => self::sanitize_return_url(
				(string) ( $map_data['return_url'] ?? '' ),
				(string) ( $map_data['cart_scope'] ?? 'default' )
			),
			'selected_at'     => current_time( 'mysql' ),
		];

		set_transient( 'ys_ec_ecpay_store_' . $temp_id, $store_info, 30 * MINUTE_IN_SECONDS );
		delete_transient( 'ys_ec_ecpay_map_' . $temp_id );

		self::render_callback_page( $store_info );
	}

	/**
	 * 依 method_id 取得物流方式實例（只認型錄裡的類別）。
	 */
	private static function instantiate( string $shipping_id ): ?EcpayShipping {
		$descriptor = EcpayShippingCatalog::get( $shipping_id );
		if ( null === $descriptor ) {
			return null;
		}

		$class = (string) $descriptor['class'];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$method = new $class();

		return $method instanceof EcpayShipping ? $method : null;
	}

	/**
	 * @return array<string,string>
	 */
	private static function params( \WP_REST_Request $request ): array {
		$out = [];
		foreach ( $request->get_params() as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$out[ (string) $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		return $out;
	}

	private static function is_method_enabled( string $shipping_id ): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'shipping', $shipping_id, Plugin::manifest() );
		}

		$alias = EcpayShippingCatalog::id_to_alias()[ $shipping_id ] ?? '';

		return '' !== $alias && Settings::shipping_enabled( $alias );
	}

	/**
	 * @param array<string,mixed> $map_data
	 */
	private static function validate_map_owner( array $map_data ): bool {
		$stored_user_id  = (int) ( $map_data['user_id'] ?? 0 );
		$current_user_id = get_current_user_id();

		if ( $stored_user_id <= 0 || $current_user_id <= 0 ) {
			return true;
		}

		return $stored_user_id === $current_user_id;
	}

	/**
	 * 驗證選店回呼。
	 *
	 * 🔴 每一項都是**必須帶回**，不是「有帶才比」。
	 *
	 * 舊版寫成 `isset($params['MerchantTradeNo']) && $params['MerchantTradeNo'] !== ...`
	 * ——攻擊者（或任何一個壞掉的中介）只要**不送**那個欄位，這一關就自動通過。
	 * subtype 尤其致命：不送 subtype 就能把 B2C 的選店結果掛到 C2C 的 session 上。
	 *
	 * @param array<string,string> $params
	 * @param array<string,mixed>  $map_data
	 */
	private static function verify_map_payload( array $params, array $map_data ): bool {
		$credentials = Settings::logistics_credentials();
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| empty( $params['CheckMacValue'] ) ) {
			return false;
		}

		$expected = [
			'MerchantID'       => $credentials['merchant_id'],
			'MerchantTradeNo'  => (string) ( $map_data['merchant_trade_no'] ?? '' ),
			'LogisticsSubType' => (string) ( $map_data['logistics_subtype'] ?? '' ),
		];

		foreach ( $expected as $field => $value ) {
			if ( '' === $value ) {
				return false;
			}
			if ( ! array_key_exists( $field, $params ) ) {
				return false;
			}
			if ( (string) $params[ $field ] !== $value ) {
				return false;
			}
		}

		return CheckMacValue::verify( $params, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );
	}

	private static function sanitize_cart_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( '' === $scope || ! preg_match( '/^[a-z0-9_]{1,32}$/', $scope ) ) {
			return 'default';
		}

		return $scope;
	}

	private static function sanitize_return_url( string $return_url, string $cart_scope = 'default' ): string {
		$fallback = self::checkout_url();
		if ( 'default' !== $cart_scope ) {
			$fallback = add_query_arg( [ 'cart_scope' => $cart_scope ], $fallback );
		}

		$return_url = trim( $return_url );
		if ( '' === $return_url ) {
			return $fallback;
		}

		$return_url = wp_validate_redirect( esc_url_raw( $return_url ), $fallback );
		if ( 'default' !== $cart_scope ) {
			$return_url = add_query_arg( [ 'cart_scope' => $cart_scope ], $return_url );
		}

		return $return_url ?: $fallback;
	}

	private static function checkout_url(): string {
		if ( class_exists( YSPageResolver::class ) ) {
			return YSPageResolver::checkout_url();
		}

		return home_url( '/checkout/' );
	}

	/**
	 * @param array<string,mixed> $store_info
	 */
	private static function render_callback_page( array $store_info ): void {
		$json_data    = wp_json_encode( $store_info );
		$origin       = esc_url( home_url() );
		$checkout_url = esc_url( $store_info['return_url'] ?? self::checkout_url() );
		$context      = (string) ( $store_info['context'] ?? 'checkout' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		nocache_headers();

		if ( in_array( $context, [ 'admin', 'frontend_change' ], true ) ) {
			?>
<!DOCTYPE html>
<html lang="zh-TW">
<head><meta charset="utf-8"><title>ECPay Store Selected</title></head>
<body>
<script>
try {
	if (window.opener) {
		window.opener.postMessage({
			action: 'ys_ec_store_selected',
			provider: 'ecpay',
			data: <?php echo $json_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		}, <?php echo wp_json_encode( $origin ); ?>);
	}
} catch (e) {}
setTimeout(function () { window.close(); }, 600);
</script>
</body>
</html>
			<?php
			exit;
		}

		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="utf-8">
	<title>ECPay Store Selected</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<noscript><meta http-equiv="refresh" content="0;url=<?php echo esc_attr( $checkout_url ); ?>"></noscript>
</head>
<body>
<script>
(function () {
	var payload = <?php echo $json_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> || {};
	payload._timestamp = Date.now();
	var serialized = JSON.stringify(payload);
	try {
		localStorage.setItem('ys_ec_selected_store', serialized);
	} catch (e) {
		try { sessionStorage.setItem('ys_ec_selected_store', serialized); } catch (e2) {}
	}
	window.location.replace(<?php echo wp_json_encode( $checkout_url ); ?>);
})();
</script>
</body>
</html>
		<?php
		exit;
	}
}
