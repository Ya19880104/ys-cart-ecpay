<?php
declare(strict_types=1);

/**
 * 國內物流回應 decoder 路由契約（requester boundary）
 *
 * 綠界官方 PHP SDK 對這兩條路由用的是**不同**的回應 service：
 *
 *   - `/Express/Create`（超商／宅配建單）→ `PostWithCmvStrResponseService`
 *     （`example/Logistics/Domestic/CreateCvs.php`、`CreateHome.php`）。
 *     它是一般的 form 回應：`+` 依 application/x-www-form-urlencoded 解成空白。
 *   - `/Helper/QueryLogisticsTradeInfo`（查詢）→
 *     `PostWithCmvVerifiedEncodedStrResponseService`
 *     （`example/Logistics/Domestic/QueryLogisticsTradeInfo.php`）。
 *     它是 `VerifiedEncodedStrResponse`：驗章前先把 literal `+` 保留下來。
 *
 * 🔴 兩者不可共用同一個 decoder。共用的後果不是「解出來不好看」，而是
 * **CheckMacValue 驗不過** → 建單被判 `indeterminate` → 綠界那邊其實已經
 * 成立一張單，本站卻永遠停在人工裁決。
 *
 * 本檔在 requester 邊界（`create_order()` / `query_status()`）直接驗這件事，
 * 而且刻意做成**互斥**的一對：
 *
 *   - 建單回應以「空白＝`+`」上線、簽章簽在含空白的原值 → 只有 plain decoder 驗得過。
 *   - 查詢回應以「literal `+` 原樣」上線、簽章簽在含 `+` 的原值 → 只有 verified decoder 驗得過。
 *
 * 因此任何一條路由被換成另一個 decoder，這支測試就會變紅；它不對「綠界實際
 * 送哪一種 wire form」做任何假設，只釘住「哪一條路由用哪一個官方 decoder」。
 *
 * 同時保留既有的 transport-error 與 numeric-prefix／回應處理 oracle，避免
 * 修 decoder 時把那些 fail-closed 行為一併弄丟。
 *
 * Run: php tests/regression/v030_logistics_response_decoder_routing.php
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! defined( 'YS_CART_ECPAY_TESTING' ) ) {
		define( 'YS_CART_ECPAY_TESTING', true );
	}

	final class V030_WP_Error {
		public function __construct( private string $message = 'transport failure' ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	$GLOBALS['v030_create_queue'] = [];
	$GLOBALS['v030_query_queue']  = [];
	$GLOBALS['v030_calls']        = [];

	function is_wp_error( $thing ): bool {
		return $thing instanceof \V030_WP_Error;
	}

	function wp_remote_post( string $url, array $args = [] ) {
		$GLOBALS['v030_calls'][] = $url;

		$queue = false !== strpos( $url, '/Express/Create' )
			? 'v030_create_queue'
			: 'v030_query_queue';

		$next = array_shift( $GLOBALS[ $queue ] );

		return $next ?? new \V030_WP_Error( 'no queued response for ' . $url );
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}

	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}

	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 4 ), 0, $length );
	}

	function current_time( string $type ) {
		return 'mysql' === $type ? '2026-08-28 12:00:00' : '2026/08/28 12:00:00';
	}

	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

namespace YangSheep\Ecommerce\Shipping {
	interface YSShippingInterface {}
}

namespace YangSheep\YSCartEcpay\Support {

	/** 只提供 requester 需要的憑證／端點；被測的解碼與驗章一律是 production 程式碼。 */
	final class Settings {
		public const SENDER_KEYS = [
			'name'    => 'shipping_ecpay_sender_name',
			'phone'   => 'shipping_ecpay_sender_phone',
			'zipcode' => 'shipping_ecpay_sender_zipcode',
			'address' => 'shipping_ecpay_sender_address',
		];

		public const MERCHANT_ID = 'LOGI-MERCHANT';
		public const HASH_KEY    = 'v030-logistics-key';
		public const HASH_IV     = 'v030-logistics-iv';

		/** @return array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} */
		public static function logistics_credentials_for_method( string $method_id ): array {
			unset( $method_id );

			return [
				'merchant_id' => self::MERCHANT_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
				'test_mode'   => true,
			];
		}

		public static function logistics_endpoint( string $path = '', string $method_id = '' ): string {
			unset( $method_id );

			return 'https://logistics-stage.ecpay.com.tw' . $path;
		}

		public static function get( string $key, $default = '' ) {
			$values = [
				self::SENDER_KEYS['name']    => '陳大明',
				self::SENDER_KEYS['phone']   => '0987654321',
				self::SENDER_KEYS['zipcode'] => '10041',
				self::SENDER_KEYS['address'] => '台北市中正區重慶南路一段 122 號',
			];

			return $values[ $key ] ?? $default;
		}

		public static function shipping_method_option( string $method_id, string $suffix, $default = '' ) {
			unset( $method_id, $suffix );

			return $default;
		}

		public static function shipping_base_fee( string $method_id ): float {
			unset( $method_id );

			return 0.0;
		}

		public static function shipping_free_threshold( string $method_id ): float {
			unset( $method_id );

			return 0.0;
		}
	}

	final class ProviderMaintenanceLock {
		public static function reader_lease(): ?object {
			return (object) [ 'token' => 'v030-reader' ];
		}

		public static function reader_fence( string $token ): bool {
			return 'v030-reader' === $token;
		}
	}

	final class ShippingMethodOperability {
		public static function is_operable( string $method_id ): bool {
			unset( $method_id );

			return true;
		}

		public static function is_configured( string $method_id ): bool {
			unset( $method_id );

			return true;
		}
	}
}

namespace {

	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingFamily;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
	use YangSheep\YSCartEcpay\Support\CheckMacValue;
	use YangSheep\YSCartEcpay\Support\Settings;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Support/HttpFormClient.php';
	require_once $root . '/src/Support/Utf8Text.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShipping.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShippingFamily.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShippingRequester.php';

	$passed = 0;
	$failed = 0;
	$assert = static function ( bool $ok, string $label, string $detail = '' ) use ( &$passed, &$failed ): void {
		if ( $ok ) {
			++$passed;
			echo "PASS  {$label}\n";
			return;
		}
		++$failed;
		echo "FAIL  {$label}" . ( '' === $detail ? '' : "  [{$detail}]" ) . "\n";
	};

	const V030_TRADE_NO = 'YS300T0001';

	$sign = static function ( array $fields ): array {
		$fields['CheckMacValue'] = CheckMacValue::generate(
			$fields,
			Settings::HASH_KEY,
			Settings::HASH_IV,
			'md5'
		);

		return $fields;
	};

	/**
	 * 一般 form 回應線上形狀（RFC1738）：空白上線變成 `+`。
	 * 官方對 `/Express/Create` 使用的 plain decoder 會把它解回空白。
	 */
	$plain_wire = static function ( array $fields ): string {
		return http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 );
	};

	/**
	 * VerifiedEncodedStr 線上形狀：literal `+` 原樣保留（官方 SDK 在解析前
	 * 先把它換成 `%2B`），空白則以 `%20` 表示。
	 */
	$verified_wire = static function ( array $fields ): string {
		return str_replace( '%2B', '+', http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) );
	};

	$queue_create = static function ( $response ): void {
		$GLOBALS['v030_create_queue'][] = $response;
	};
	$queue_query = static function ( $response ): void {
		$GLOBALS['v030_query_queue'][] = $response;
	};
	$ok = static function ( string $body ): array {
		return [ 'code' => 200, 'body' => $body ];
	};

	$order_data = [
		'merchant_trade_no' => V030_TRADE_NO,
		'payment_method'    => 'ys_ec_ecpay_credit',
		'product_amount'    => 500,
		'receiver_store_id' => '001234',
		'receiver_name'     => '王小美',
		'receiver_phone'    => '0912345678',
		'sender_name'       => '陳大明',
		'sender_phone'      => '0987654321',
		'product_name'      => 'YS CART 測試商品',
	];

	$query_context = [
		'merchant_trade_no' => V030_TRADE_NO,
		'provider'          => 'ecpay',
		'shipping_method'   => 'ys_ec_ecpay_ship_family',
		'logistics_subtype' => 'FAMI',
	];

	/** 建單成功回應的權威欄位（含一個真的空白，與一個真的日期時間欄位）。 */
	$create_fields = static function ( array $overrides = [] ): array {
		return array_merge(
			[
				'MerchantID'        => Settings::MERCHANT_ID,
				'MerchantTradeNo'   => V030_TRADE_NO,
				'RtnCode'           => '300',
				'RtnMsg'            => 'A B',
				'AllPayLogisticsID' => 'ECPAY-LOGI-1',
				'UpdateStatusDate'  => '2026/08/28 12:00:00',
				'BookingNote'       => 'BN-0001',
			],
			$overrides
		);
	};

	/** 查詢成功回應的權威欄位（含一個真的 literal `+`）。 */
	$query_fields = static function ( array $overrides = [] ): array {
		return array_merge(
			[
				'MerchantID'        => Settings::MERCHANT_ID,
				'MerchantTradeNo'   => V030_TRADE_NO,
				'AllPayLogisticsID' => 'ECPAY-LOGI-1',
				'LogisticsType'     => 'CVS_FAMI',
				'LogisticsStatus'   => '300',
				'RtnMsg'            => 'A+B',
			],
			$overrides
		);
	};

	$requester = new EcpayShippingRequester( new EcpayShippingFamily() );

	// ── 1. Create 必須走官方的 plain（EncodedStr）decoder ────────────────────
	$queue_create( $ok( '1|' . $plain_wire( $sign( $create_fields() ) ) ) );
	$created = $requester->create_order( $order_data );
	$assert(
		true === ( $created['success'] ?? false )
			&& 'ECPAY-LOGI-1' === ( $created['provider_trade_no'] ?? '' )
			&& 'A B' === ( $created['message'] ?? '' ),
		'Create verifies the official CheckMacValue with the plain form decoder',
		(string) ( $created['outcome'] ?? '' ) . ' / ' . (string) ( $created['message'] ?? '' )
	);
	$assert(
		'2026/08/28 12:00:00' === (string) ( $created['raw_response']['UpdateStatusDate'] ?? '' ),
		'Create decodes a signed date-time field back to a real space',
		(string) ( $created['raw_response']['UpdateStatusDate'] ?? '' )
	);

	// ── 2. Query 必須走官方的 VerifiedEncodedStr decoder ────────────────────
	$queue_query( $ok( $verified_wire( $sign( $query_fields() ) ) ) );
	$queried = $requester->query_status( 'ECPAY-LOGI-1', $query_context );
	$assert(
		true === ( $queried['success'] ?? false )
			&& 'confirmed' === ( $queried['outcome'] ?? '' )
			&& 'A+B' === ( $queried['message'] ?? '' ),
		'Query verifies the official CheckMacValue with the verified encoded decoder',
		(string) ( $queried['outcome'] ?? '' ) . ' / ' . (string) ( $queried['message'] ?? '' )
	);

	// ── 3. 兩條路由的 decoder 不可互換（互斥交叉檢查）────────────────────────
	$queue_create( $ok( '1|' . $verified_wire( $sign( $create_fields( [ 'RtnMsg' => 'A+B' ] ) ) ) ) );
	$create_cross = $requester->create_order( $order_data );
	$assert(
		false === ( $create_cross['success'] ?? true )
			&& 'indeterminate' === ( $create_cross['outcome'] ?? '' ),
		'Create refuses a verified-encoded signature instead of silently accepting it',
		(string) ( $create_cross['outcome'] ?? '' )
	);

	$queue_query( $ok( $plain_wire( $sign( $query_fields( [ 'RtnMsg' => 'A B' ] ) ) ) ) );
	$query_cross = $requester->query_status( 'ECPAY-LOGI-1', $query_context );
	$assert(
		false === ( $query_cross['success'] ?? true )
			&& 'indeterminate' === ( $query_cross['outcome'] ?? '' ),
		'Query refuses a plain-encoded signature instead of silently accepting it',
		(string) ( $query_cross['outcome'] ?? '' )
	);

	// ── 4. 既有 transport-error oracle 必須保留 ─────────────────────────────
	$queue_create( new V030_WP_Error( 'connection reset' ) );
	$create_transport = $requester->create_order( $order_data );
	$assert(
		false === ( $create_transport['success'] ?? true )
			&& 'indeterminate' === ( $create_transport['outcome'] ?? '' ),
		'Create transport failure stays indeterminate',
		(string) ( $create_transport['outcome'] ?? '' )
	);

	$queue_create( [ 'code' => 502, 'body' => 'bad gateway' ] );
	$create_5xx = $requester->create_order( $order_data );
	$assert(
		false === ( $create_5xx['success'] ?? true )
			&& 'indeterminate' === ( $create_5xx['outcome'] ?? '' ),
		'Create non-2xx stays indeterminate',
		(string) ( $create_5xx['outcome'] ?? '' )
	);

	$queue_query( new V030_WP_Error( 'timeout' ) );
	$query_transport = $requester->query_status( 'ECPAY-LOGI-1', $query_context );
	$assert(
		false === ( $query_transport['success'] ?? true )
			&& 'indeterminate' === ( $query_transport['outcome'] ?? '' ),
		'Query transport failure stays indeterminate',
		(string) ( $query_transport['outcome'] ?? '' )
	);

	// ── 5. 既有 numeric-prefix oracle 必須保留 ──────────────────────────────
	$queue_create( $ok( '0|MerchantID Error' ) );
	$create_zero = $requester->create_order( $order_data );
	$assert(
		false === ( $create_zero['success'] ?? true )
			&& 'provider_failed' === ( $create_zero['outcome'] ?? '' ),
		'Create `0|` prefix remains an explicit provider rejection',
		(string) ( $create_zero['outcome'] ?? '' )
	);

	$queue_create( $ok( '10500040|商品金額範圍為1~20000元' ) );
	$create_code = $requester->create_order( $order_data );
	$assert(
		false === ( $create_code['success'] ?? true )
			&& 'provider_failed' === ( $create_code['outcome'] ?? '' )
			&& '商品金額範圍為1~20000元' === (string) ( $create_code['message'] ?? '' ),
		'Create numeric error-code prefix remains an explicit provider rejection',
		(string) ( $create_code['outcome'] ?? '' ) . ' / ' . (string) ( $create_code['message'] ?? '' )
	);

	$queue_create( $ok( 'MerchantID=' . Settings::MERCHANT_ID . '&RtnCode=1' ) );
	$create_unsigned = $requester->create_order( $order_data );
	$assert(
		false === ( $create_unsigned['success'] ?? true )
			&& 'indeterminate' === ( $create_unsigned['outcome'] ?? '' ),
		'Create response without a numeric prefix and without a valid signature stays indeterminate',
		(string) ( $create_unsigned['outcome'] ?? '' )
	);

	// ── 6. 既有 identity／必填欄位 oracle 必須保留 ──────────────────────────
	$queue_create( $ok( '1|' . $plain_wire( $sign( $create_fields( [ 'MerchantTradeNo' => 'YS300T9999' ] ) ) ) ) );
	$create_identity = $requester->create_order( $order_data );
	$assert(
		false === ( $create_identity['success'] ?? true )
			&& 'indeterminate' === ( $create_identity['outcome'] ?? '' ),
		'Create rejects a correctly signed response bound to another MerchantTradeNo',
		(string) ( $create_identity['outcome'] ?? '' )
	);

	$queue_create( $ok( '1|' . $plain_wire( $sign( $create_fields( [ 'AllPayLogisticsID' => '' ] ) ) ) ) );
	$create_missing = $requester->create_order( $order_data );
	$assert(
		false === ( $create_missing['success'] ?? true )
			&& 'indeterminate' === ( $create_missing['outcome'] ?? '' ),
		'Create keeps a signed success without shippable fields indeterminate',
		(string) ( $create_missing['outcome'] ?? '' )
	);

	$queue_query( $ok( $verified_wire( $sign( $query_fields( [ 'AllPayLogisticsID' => 'ECPAY-LOGI-OTHER' ] ) ) ) ) );
	$query_identity = $requester->query_status( 'ECPAY-LOGI-1', $query_context );
	$assert(
		false === ( $query_identity['success'] ?? true )
			&& 'indeterminate' === ( $query_identity['outcome'] ?? '' ),
		'Query rejects a correctly signed response bound to another logistics id',
		(string) ( $query_identity['outcome'] ?? '' )
	);

	// ── 7. 兩條路由確實各自打到自己的官方端點 ───────────────────────────────
	$create_calls = count( array_filter(
		$GLOBALS['v030_calls'],
		static fn ( string $url ): bool => false !== strpos( $url, '/Express/Create' )
	) );
	$query_calls = count( array_filter(
		$GLOBALS['v030_calls'],
		static fn ( string $url ): bool => false !== strpos( $url, '/Helper/QueryLogisticsTradeInfo/' )
	) );
	$assert(
		9 === $create_calls && 4 === $query_calls && 13 === count( $GLOBALS['v030_calls'] ),
		'Every request reached its own official logistics endpoint',
		"create={$create_calls} query={$query_calls} total=" . count( $GLOBALS['v030_calls'] )
	);

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
