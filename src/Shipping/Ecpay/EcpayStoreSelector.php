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

	/**
	 * 物流方式 → 綠界 LogisticsSubType（v0.3.0）
	 *
	 * 🔴 B2C 與 C2C 是**兩份不同的合約**，綠界以不同的服務金鑰開通。
	 * 用 B2C 的 subtype 打 C2C 商店代號，電子地圖會回「找不到加密金鑰，
	 * 請確認是否有申請開通此物流方式!」——注意是「此物流方式」而非
	 * 整個服務，那正是 subtype 對不上的症狀。
	 */
	private const SUBTYPES = [
		'ys_ec_ecpay_ship_family'  => 'FAMI',
		'ys_ec_ecpay_ship_unimart' => 'UNIMART',
		'ys_ec_ecpay_ship_hilife'  => 'HILIFE',

		// C2C（店到店）
		'ys_ec_ecpay_ship_family_c2c'  => 'FAMIC2C',
		'ys_ec_ecpay_ship_unimart_c2c' => 'UNIMARTC2C',
		'ys_ec_ecpay_ship_hilife_c2c'  => 'HILIFEC2C',

		// 超商冷凍（獨立 subtype，不是常溫加一個溫層參數）
		'ys_ec_ecpay_ship_unimart_freeze'     => 'UNIMARTFREEZE',
		'ys_ec_ecpay_ship_unimart_freeze_c2c' => 'UNIMARTFREEZEC2C',
	];

	private const METHOD_ALIASES = [
		'ys_ec_ecpay_ship_family'  => 'ship_family',
		'ys_ec_ecpay_ship_unimart' => 'ship_unimart',
		'ys_ec_ecpay_ship_hilife'  => 'ship_hilife',

		'ys_ec_ecpay_ship_family_c2c'  => 'ship_family_c2c',
		'ys_ec_ecpay_ship_unimart_c2c' => 'ship_unimart_c2c',
		'ys_ec_ecpay_ship_hilife_c2c'  => 'ship_hilife_c2c',

		'ys_ec_ecpay_ship_unimart_freeze'     => 'ship_unimart_freeze',
		'ys_ec_ecpay_ship_unimart_freeze_c2c' => 'ship_unimart_freeze_c2c',
	];

	/**
	 * Map callback: ecpay/store-callback; logistics notify: ecpay/logistics-notify.
	 *
	 * @return array{action_url:string,fields:array<string,string>,temp_id:string}|false
	 */
	public static function build_map_form_data(
		string $shipping_id,
		string $context = 'checkout',
		int $order_id = 0,
		string $cart_scope = 'default',
		string $return_url = ''
	) {
		$method_alias = self::METHOD_ALIASES[ $shipping_id ] ?? '';
		if ( ! isset( self::SUBTYPES[ $shipping_id ] )
			|| '' === $method_alias
			|| ! self::is_method_enabled( $shipping_id, $method_alias )
			|| ! Settings::has_logistics_credentials() ) {
			return false;
		}

		$cart_scope        = self::sanitize_cart_scope( $cart_scope );
		$return_url        = self::sanitize_return_url( $return_url, $cart_scope );
		$credentials       = Settings::logistics_credentials();
		$temp_id           = wp_generate_uuid4();
		$merchant_trade_no = substr( preg_replace( '/[^A-Za-z0-9]/', '', 'YSMAP' . time() . wp_rand( 100, 999 ) ) ?: 'YSMAP', 0, 20 );

		set_transient( 'ys_ec_ecpay_map_' . $temp_id, [
			'shipping_id'       => $shipping_id,
			'logistics_subtype' => self::SUBTYPES[ $shipping_id ],
			'user_id'           => get_current_user_id(),
			'context'           => $context,
			'order_id'          => $order_id,
			'cart_scope'        => $cart_scope,
			'return_url'         => $return_url,
			'merchant_trade_no' => $merchant_trade_no,
			'created_at'        => current_time( 'timestamp' ),
		], 30 * MINUTE_IN_SECONDS );

		$fields = [
			'MerchantID'        => $credentials['merchant_id'],
			'MerchantTradeNo'   => $merchant_trade_no,
			'LogisticsType'     => 'CVS',
			'LogisticsSubType'  => self::SUBTYPES[ $shipping_id ],
			// 🔴 v0.3.0：代收與否要與送單一致。地圖送 'N'、送單送 'Y'，
			// 綠界會回門市不支援代收——顧客選得了門市，建單卻失敗。
			'IsCollection'      => self::is_cod_enabled( $shipping_id ) ? 'Y' : 'N',
			'ServerReplyURL'    => rest_url( 'ys-ecommerce/v1/ecpay/store-callback' ),
			'ExtraData'         => $temp_id,
			'Device'            => wp_is_mobile() ? '1' : '0',
			'MerchantTradeDate' => current_time( 'Y/m/d H:i:s' ),
		];

		$fields['CheckMacValue'] = CheckMacValue::generate( $fields, $credentials['hash_key'], $credentials['hash_iv'], 'md5' );

		return [
			'action_url' => Settings::logistics_endpoint( self::MAP_PATH ),
			'fields'     => $fields,
			'temp_id'    => $temp_id,
		];
	}

	public static function handle_store_callback( \WP_REST_Request $request ): void {
		$params = self::params( $request );
		$temp_id = (string) ( $params['ExtraData'] ?? $params['TempId'] ?? '' );
		$map_data = '' !== $temp_id ? get_transient( 'ys_ec_ecpay_map_' . $temp_id ) : false;

		if ( ! $map_data ) {
			wp_die( 'Invalid map session.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		if ( ! self::validate_map_owner( $map_data ) ) {
			wp_die( 'Invalid map session owner.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		$shipping_id  = (string) ( $map_data['shipping_id'] ?? '' );
		$method_alias = self::METHOD_ALIASES[ $shipping_id ] ?? '';
		if ( '' === $shipping_id || '' === $method_alias || ! self::is_method_enabled( $shipping_id, $method_alias ) ) {
			wp_die( 'Shipping method disabled.', 'ECPay Store Callback', [ 'response' => 403 ] );
		}

		if ( ! self::verify_map_payload( $params, $map_data ) ) {
			wp_die( 'Invalid CheckMacValue.', 'ECPay Store Callback', [ 'response' => 400 ] );
		}

		$store_info = [
			'store_id'       => (string) ( $params['CVSStoreID'] ?? '' ),
			'store_name'     => (string) ( $params['CVSStoreName'] ?? '' ),
			'store_address'  => (string) ( $params['CVSAddress'] ?? '' ),
			'store_phone'    => (string) ( $params['CVSTelephone'] ?? '' ),
			'cvs_type'       => (string) ( $params['LogisticsSubType'] ?? $map_data['logistics_subtype'] ?? '' ),
			'cvs_store_id'   => (string) ( $params['CVSStoreID'] ?? '' ),
			'cvs_store_name' => (string) ( $params['CVSStoreName'] ?? '' ),
			'cvs_store_addr' => (string) ( $params['CVSAddress'] ?? '' ),
			'shipping_id'    => (string) ( $map_data['shipping_id'] ?? '' ),
			'context'        => (string) ( $map_data['context'] ?? 'checkout' ),
			'order_id'       => (int) ( $map_data['order_id'] ?? 0 ),
			'cart_scope'     => self::sanitize_cart_scope( (string) ( $map_data['cart_scope'] ?? 'default' ) ),
			'return_url'     => self::sanitize_return_url(
				(string) ( $map_data['return_url'] ?? '' ),
				(string) ( $map_data['cart_scope'] ?? 'default' )
			),
			'selected_at'    => current_time( 'mysql' ),
		];

		set_transient( 'ys_ec_ecpay_store_' . $temp_id, $store_info, 30 * MINUTE_IN_SECONDS );
		delete_transient( 'ys_ec_ecpay_map_' . $temp_id );

		self::render_callback_page( $store_info );
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

	private static function is_method_enabled( string $shipping_id, string $method_alias ): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'shipping', $shipping_id, Plugin::manifest() );
		}

		return Settings::shipping_enabled( $method_alias );
	}

	/**
	 * 這個物流方式有沒有開貨到付款（v0.3.0）
	 *
	 * 電子地圖與送單必須送同一個 `IsCollection`：地圖送 'N'、送單送 'Y' 時，
	 * 綠界會依「不代收」篩門市，顧客選得到的門市卻可能不支援代收——建單就失敗。
	 */
	private static function is_cod_enabled( string $shipping_id ): bool {
		return Settings::shipping_cod_enabled( $shipping_id );
	}

	private static function validate_map_owner( array $map_data ): bool {
		$stored_user_id  = (int) ( $map_data['user_id'] ?? 0 );
		$current_user_id = get_current_user_id();

		if ( $stored_user_id <= 0 || $current_user_id <= 0 ) {
			return true;
		}

		return $stored_user_id === $current_user_id;
	}

	/**
	 * @param array<string,string> $params
	 */
	private static function verify_map_payload( array $params, array $map_data ): bool {
		$credentials = Settings::logistics_credentials();
		if ( '' === $credentials['merchant_id']
			|| '' === $credentials['hash_key']
			|| '' === $credentials['hash_iv']
			|| empty( $params['CheckMacValue'] ) ) {
			return false;
		}

		if ( isset( $params['MerchantID'] ) && (string) $params['MerchantID'] !== $credentials['merchant_id'] ) {
			return false;
		}

		if ( isset( $params['MerchantTradeNo'] )
			&& (string) $params['MerchantTradeNo'] !== (string) ( $map_data['merchant_trade_no'] ?? '' ) ) {
			return false;
		}

		if ( isset( $params['LogisticsSubType'] )
			&& (string) $params['LogisticsSubType'] !== (string) ( $map_data['logistics_subtype'] ?? '' ) ) {
			return false;
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
