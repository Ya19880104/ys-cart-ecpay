<?php
/**
 * v058 — 站內付 2.0 結果落地與回呼（v0.5.0）
 *
 * 同一筆授權結果最多從三條路進來（同步回應／OrderResultURL／ReturnURL），順序不定、會重複。
 * 本檔對著 production `EcpgSettlement` 與 `EcpgPaymentController` 驗證：
 *
 *   A. 驗證順序與冪等：MerchantID → MerchantTradeNo 歸屬 → RtnCode → TradeStatus → 金額 → TradeNo；
 *      第二次進來（狀態機業務拒絕）讀成 already_paid，不是錯；存卡以 BindCardID 為 token、預設卡。
 *   B. 對綠界的回應規則（子程序，因為 controller 會 exit）：解不開＝400、找不到訂單＝404、
 *      已處理（含重複、業務拒絕）＝1|OK、**我們自己寫不進 DB**（含綁卡存不進去）＝500 不 ACK。
 *   C. 訂單查找的 SQL 必須是 `JSON_UNQUOTE(JSON_EXTRACT(...)) = %s`（引號比對在兩個引擎上永遠為假）。
 *
 * Run: php tests/regression/v058_ecpg_settlement_and_callbacks.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	define( 'YS_CART_ECPAY_DIR', str_replace( '\\', '/', dirname( __DIR__, 2 ) ) . '/' );
	define( 'YS_CART_ECPAY_URL', 'https://shop.invalid/wp-content/plugins/ys-cart-ecpay/' );
	define( 'YS_CART_ECPAY_VERSION', 'test' );

	$GLOBALS['ys_marker'] = null;
	function ys_mark( string $line ): void {
		if ( is_string( $GLOBALS['ys_marker'] ) ) {
			file_put_contents( $GLOBALS['ys_marker'], $line . "\n", FILE_APPEND );
		}
	}

	function __( $text, $domain = '' ) { return $text; }
	function esc_html__( $text, $domain = '' ) { return $text; }
	function esc_html_e( $text, $domain = '' ) { echo $text; }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $url ) { return (string) $url; }
	function rest_url( string $path = '' ): string { return 'https://shop.invalid/wp-json/' . ltrim( $path, '/' ); }
	function home_url( string $path = '' ): string { return 'https://shop.invalid' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
	function current_time( string $format ): string { return '2026/09/10 12:00:00'; }
	function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function wp_json_encode( $data ) { return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
	function wp_specialchars_decode( $text, $quote = 0 ) { return (string) $text; }
	function get_bloginfo( string $show = '' ): string { return 'Fixture Shop'; }
	function get_current_user_id(): int { return (int) ( $GLOBALS['ys_current_user'] ?? 0 ); }
	// REST 路徑：cookie 使用者要由 controller 自己驗 logged_in cookie 還原；nonce 綁定當前使用者。
	function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) { return (int) ( $GLOBALS['ys_cookie_user'] ?? 0 ); }
	function wp_set_current_user( $id ) { $GLOBALS['ys_current_user'] = (int) $id; }
	function is_user_logged_in(): bool { return (int) ( $GLOBALS['ys_current_user'] ?? 0 ) > 0; }
	function wp_create_nonce( $action = -1 ): string { return 'nonce-' . $action . '-' . (int) ( $GLOBALS['ys_current_user'] ?? 0 ); }
	function wp_remote_post( string $url, array $args ) {
		$sent = json_decode( (string) ( $args['body'] ?? '' ), true );
		$data = is_array( $sent ) ? \YangSheep\YSCartEcpay\Ecpg\EcpgAesCodec::decrypt( (string) ( $sent['Data'] ?? '' ), 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs' ) : null;
		ys_mark( 'remote:' . json_encode( [ 'url' => $url, 'data' => $data ] ) );
		return $GLOBALS['ys_remote_response'] ?? null;
	}
	function is_wp_error( $thing ): bool { return false; }
	function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['code'] ?? 0 ); }
	function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }
	function status_header( int $status ): void { ys_mark( 'status:' . $status ); }
	// header()／header_remove() 是 PHP 內建：CLI 下無作用，不需要也不能替身。
	function wp_safe_redirect( string $url, int $status = 302 ): void { ys_mark( 'redirect:' . $url ); }

	final class WP_REST_Request {
		public function __construct( private array $params, private string $body = '' ) {}
		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function get_params(): array { return $this->params; }
		public function get_body_params(): array { return $this->params; }
		public function get_json_params(): array { $d = json_decode( $this->body, true ); return is_array( $d ) ? $d : []; }
		public function get_body(): string { return $this->body; }
	}

	/** wpdb 替身：記錄 SQL、回腳本化的一列。 */
	final class FakeWpdb {
		public string $prefix = 'wp_';
		public array $queries = [];
		public ?object $row = null;
		public function prepare( string $sql, ...$args ): string { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
		public function get_row( string $sql ): ?object { $this->queries[] = $sql; return $this->row; }
	}
	$GLOBALS['wpdb'] = new FakeWpdb();
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder {
		public static array $orders = [];
		public static function find( int $id ): ?object { return self::$orders[ $id ] ?? null; }
		public static function get_items( int $order_id ): array { return []; }
		public static function generate_order_key( int $order_id, string $order_number ): string { return 'key-' . $order_id; }
		public static function verify_order_key( int $order_id, string $key ): bool { return 'key-' . $order_id === $key; }
		public static function is_repayable( string $status ): bool { return in_array( $status, [ 'timeout', 'failed', 'pending', 'offline_payment' ], true ); }
	}
	final class YSCreditCard {
		public static array $created = [];
		public static $create_result = 77;
		public static function create_or_get( array $data ): int|false {
			self::$created[] = $data;
			\ys_mark( 'vault:' . json_encode( [ 'token' => $data['token'], 'gateway' => $data['gateway_id'], 'last4' => $data['card_last4'], 'brand' => $data['card_brand'], 'expire' => $data['expire_date'], 'default' => $data['is_default'], 'customer' => $data['customer_id'] ] ) );
			return self::$create_result;
		}
		public static function get_customer_cards( int $customer_id, ?string $gateway_id = null ): array {
			\ys_mark( 'cards_lookup:' . $customer_id . ':' . (string) $gateway_id );
			return $GLOBALS['ys_cards'] ?? [];
		}
		public static function get_token_charge_authority_for_owner( int $c, int $cu, int $u = 0, string $g = '' ): ?array {
			\ys_mark( 'authority_lookup:' . json_encode( [ $c, $cu, $u, $g ] ) );
			return $GLOBALS['ys_authority'] ?? null;
		}
	}
	final class YSSubscription {
		public static bool $bind_result = true;
		public static array $bind_calls = [];
		public static function bind_initial_order_card( int $order_id, int $customer_id, int $user_id, string $gateway_id, int $card_id ): bool {
			self::$bind_calls[] = func_get_args();
			return self::$bind_result;
		}
	}
}

namespace YangSheep\Ecommerce\DTOs {
	final class YSPaymentDetailDTO {
		public function __construct( public array $fields ) {}
		public static function from_legacy_array( array $stored, string $hint = '' ): self { return new self( $stored ); }
		public function get( string $key, $default = null ) { return $this->fields[ $key ] ?? $default; }
		public function get_gateway_trade_no(): string { return (string) ( $this->fields['gateway_trade_no'] ?? '' ); }
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	final class YSPaymentLifecycleService {
		public static array $paid_result   = [ 'success' => true, 'retryable' => false, 'from' => 'pending', 'to' => 'processing', 'message' => '', 'receipt_id' => 'receipt-42' ];
		public static array $failed_result = [ 'success' => true, 'retryable' => false, 'from' => 'pending', 'to' => 'failed', 'message' => '' ];
		public static array $calls = [];
		public static function mark_paid( int $order_id, object $detail, string $reason = '' ): array {
			self::$calls[] = [ 'paid', $order_id, $detail->fields, $reason ];
			\ys_mark( 'paid:' . json_encode( [ 'order' => $order_id, 'reason' => $reason, 'trade_no' => $detail->fields['trade_no'] ?? null, 'paid_amount' => $detail->fields['paid_amount'] ?? null ] ) );
			return self::$paid_result;
		}
		public static function mark_failed( int $order_id, object $detail, string $reason = '' ): array {
			self::$calls[] = [ 'failed', $order_id, $detail->fields, $reason ];
			\ys_mark( 'failed:' . json_encode( [ 'order' => $order_id, 'reason' => $reason, 'code' => $detail->fields['response_code'] ?? null ] ) );
			return self::$failed_result;
		}
	}
	final class YSPaymentEffects {
		public const STATE_DONE = 'done';
		public static bool $enroll_result = true;
		public static array $enrolled = [];
		public static array $effects = [];
		public static int $run_calls = 0;
		public static function enroll( int $order_id, string $receipt, string $effect ): bool {
			self::$enrolled[] = [ $order_id, $receipt, $effect ];
			if ( '' === $receipt || ! self::$enroll_result
				|| ! isset( YSPaymentDetailStore::$detail['_ys_payment_effects'][ $receipt ] ) ) { return false; }
			self::$effects[ $effect ] ??= [ 'state' => 'pending' ];
			YSPaymentDetailStore::$detail['_ys_payment_effects'][ $receipt ]['effects'] = self::$effects;
			return true;
		}
		public static function run( int $order_id, string $receipt, array $runners ): array {
			++self::$run_calls;
			foreach ( $runners as $effect => $runner ) {
				if ( self::STATE_DONE === ( self::$effects[ $effect ]['state'] ?? '' ) ) { continue; }
				self::$effects[ $effect ] = [ 'state' => true === $runner( 'yspe-' . $receipt . '-' . $effect ) ? self::STATE_DONE : 'failed' ];
			}
			YSPaymentDetailStore::$detail['_ys_payment_effects'][ $receipt ]['effects'] = self::$effects;
			return [];
		}
		public static function receipt_id( int $order_id, string $target, array $detail ): string { return 'receipt-42'; }
		public static function receipt( array $detail, string $receipt ): array { return is_array( $detail['_ys_payment_effects'][ $receipt ] ?? null ) ? $detail['_ys_payment_effects'][ $receipt ] : []; }
	}
	final class YSPaymentDetailStore {
		public static bool $readable = true;
		public static array $detail = [ '_ys_payment_effects' => [ 'receipt-42' => [ 'target' => 'processing', 'effects' => [] ] ] ];
		public static function read( int $order_id ): ?array { return self::$readable ? self::$detail : null; }
	}
}

namespace YangSheep\Ecommerce\Utils {
	final class YSLogger {
		public static array $entries = [];
		public static function error( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'error', $m ]; \ys_mark( 'log:error:' . $m ); }
		public static function warning( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'warning', $m ]; \ys_mark( 'log:warning:' . $m ); }
		public static function info( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'info', $m ]; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static array $credentials = [ 'test_mode' => true, 'merchant_id' => '3002607', 'hash_key' => 'pwFHCqoQZGmho4w6', 'hash_iv' => 'EkRm7iFT261dpevs' ];
		public static function payment_credentials(): array { return self::$credentials; }
	}
	final class ProviderMaintenanceLock {
		public static function reader_lease(): ?object { return (object) [ 'token' => 'fixture-lease' ]; }
		public static function reader_fence( string $token ): bool { return true; }
	}
	final class FakeWrite {
		public function __construct( private bool $persisted ) {}
		public function is_persisted(): bool { return $this->persisted; }
		public function to_log_context(): array { return []; }
	}
	final class OrderPaymentDetail {
		public static array $details = [];
		public static bool $persist = true;
		public static function read( int $order_id ): ?array { return self::$details[ $order_id ] ?? []; }
		public static function mutate( int $order_id, callable $mutator ): FakeWrite {
			$next = $mutator( self::$details[ $order_id ] ?? [] );
			if ( is_array( $next ) && self::$persist ) {
				self::$details[ $order_id ] = $next;
				\ys_mark( 'detail:' . json_encode( $next ) );
			}
			return new FakeWrite( self::$persist );
		}
	}
	final class ScalarColumnWriter {
		public static array $writes = [];
		public static bool $persist = true;
		public static function write( int $order_id, array $columns ): array {
			self::$writes[] = [ $order_id, $columns ];
			\ys_mark( 'column:' . json_encode( $columns ) );
			return [ 'state' => self::$persist ? 'verified' : 'db_error' ];
		}
		public static function is_persisted( array $result ): bool { return 'verified' === ( $result['state'] ?? '' ); }
		public static function required_string( mixed $value ): ?string { $v = trim( (string) $value ); return '' === $v ? null : $v; }
	}
}

namespace {
	$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	require_once $root . '/src/Support/Utf8Text.php';
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Payment/EcpayPaymentClient.php';
	require_once $root . '/src/Ecpg/EcpgAesCodec.php';
	require_once $root . '/src/Ecpg/EcpgClient.php';
	require_once $root . '/src/Ecpg/EcpgOrderContext.php';
	require_once $root . '/src/Ecpg/EcpgSettlement.php';
	require_once $root . '/src/Api/EcpgPaymentController.php';

	use YangSheep\Ecommerce\Models\YSCreditCard;
	use YangSheep\Ecommerce\Models\YSOrder;
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentEffects;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
	use YangSheep\Ecommerce\Utils\YSLogger;
	use YangSheep\YSCartEcpay\Api\EcpgPaymentController;
	use YangSheep\YSCartEcpay\Ecpg\EcpgAesCodec;
	use YangSheep\YSCartEcpay\Ecpg\EcpgSettlement;
	use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
	use YangSheep\YSCartEcpay\Support\ScalarColumnWriter;
	use YangSheep\YSCartEcpay\Support\Settings;

	$KEY = 'pwFHCqoQZGmho4w6';
	$IV  = 'EkRm7iFT261dpevs';
	$MTN = 'YSABCDEF1234567890';

	$make_order = static function ( int $customer_id = 7, string $status = 'pending', int $charged = 1290 ) use ( $MTN ): object {
		$detail = [ 'mer_trade_no' => $MTN, 'ecpay_merchant_trade_no' => $MTN, 'ecpay_charged_amount' => $charged, 'ecpay_ecpg_flow' => $customer_id > 0 ? 'bind' : 'pay', 'ecpay_ecpg_member_id' => $customer_id > 0 ? 'YSC' . $customer_id : '' ];
		$order  = (object) [ 'id' => 42, 'order_number' => 'YS-42', 'total' => (string) $charged, 'status' => $status, 'customer_id' => $customer_id, 'user_id' => 70, 'gateway_id' => 'ys_ec_ecpay_ecpg_credit', 'billing_email' => 'b@example.com', 'billing_phone' => '0912345678', 'billing_name' => '王小明', 'billing_country' => 'TW', 'payment_detail' => json_encode( $detail ) ];
		YSOrder::$orders[42]            = $order;
		OrderPaymentDetail::$details[42] = $detail;
		return $order;
	};
	$success_data = static function ( array $overrides = [] ) use ( $MTN ): array {
		return array_replace_recursive( [
			'RtnCode'          => 1,
			'RtnMsg'           => 'Success',
			'PlatformID'       => '',
			'MerchantID'       => '3002607',
			'MerchantMemberID' => 'YSC7',
			'BindCardID'       => 'bindcard0123456789abcdef',
			'IsSameCard'       => false,
			'CardInfo'         => [ 'Card6No' => '431195', 'Card4No' => '2222', 'CardValidYY' => '28', 'CardValidMM' => '12', 'AuthCode' => '777777', 'Gwsr' => 12345678, 'ProcessDate' => '2026/09/10 12:00:05', 'Amount' => 1290, 'Eci' => 5 ],
			'OrderInfo'        => [ 'MerchantTradeNo' => $MTN, 'TradeNo' => '2609101200001234', 'PaymentDate' => '2026/09/10 12:00:05', 'TradeAmt' => 1290, 'PaymentType' => 'Credit', 'TradeDate' => '2026/09/10 12:00:00', 'ChargeFee' => 30, 'TradeStatus' => '1' ],
		], $overrides );
	};
	$envelope = static function ( array $data, string $key, string $iv, int $trans_code = 1 ): string {
		return json_encode( [ 'MerchantID' => '3002607', 'RpHeader' => [ 'Timestamp' => 1234564848 ], 'TransCode' => $trans_code, 'TransMsg' => 'Success', 'Data' => EcpgAesCodec::encrypt( $data, $key, $iv ) ] );
	};

	// ── 子程序：controller 會 exit，所以每個情境各跑一個 PHP ─────────────────
	if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
		$scenario            = (string) $argv[2];
		$GLOBALS['ys_marker'] = (string) $argv[3];
		$order = $make_order();
		$GLOBALS['wpdb']->row = (object) [ 'id' => 42 ];
		$data = $success_data();
		$key  = $KEY;
		switch ( $scenario ) {
			case 'paid-bind':
				break;
			case 'vault-fails':
				YSCreditCard::$create_result = false;
				break;
			case 'rtn-fail':
				$data = $success_data( [ 'RtnCode' => 10100252, 'RtnMsg' => '額度不足' ] );
				unset( $data['BindCardID'] );
				break;
			case 'unknown-order':
				$GLOBALS['wpdb']->row = null;
				break;
			case 'bad-key':
				$key = 'ejCk326UnaZWKisg';
				break;
			case 'amount-mismatch':
				$data = $success_data( [ 'OrderInfo' => [ 'TradeAmt' => 990 ], 'CardInfo' => [ 'Amount' => 990 ] ] );
				break;
			case 'already-paid':
				$order->status = 'completed';
				YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => false, 'outcome' => 'rejected', 'from' => 'completed', 'to' => 'processing', 'message' => 'not allowed', 'receipt_id' => '' ];
				break;
			case 'lifecycle-retryable':
				YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => true, 'from' => 'pending', 'to' => 'processing', 'message' => 'db' ];
				break;
			case 'result-paid':
				$request = new WP_REST_Request( [ 'ResultData' => $envelope( $data, $key, $IV ) ] );
				( new EcpgPaymentController() )->order_result( $request );
				exit( 97 );
			case 'result-fail':
				$data    = $success_data( [ 'RtnCode' => 10100255, 'RtnMsg' => '報失卡' ] );
				$request = new WP_REST_Request( [ 'ResultData' => $envelope( $data, $key, $IV ) ] );
				( new EcpgPaymentController() )->order_result( $request );
				exit( 97 );
			case 'pay-page':
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42' ] );
				( new EcpgPaymentController() )->pay_page( $request );
				exit( 97 );
			case 'pay-page-bad-key':
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'nope' ] );
				( new EcpgPaymentController() )->pay_page( $request );
				exit( 97 );
			case 'pay-page-saved':
				// 登入 cookie 屬於訂單擁有者（user 70）：付款頁要列出已綁定的卡、帶該使用者的 wp_rest nonce。
				$GLOBALS['ys_cookie_user'] = 70;
				$GLOBALS['ys_cards']       = [ (object) [ 'id' => 14, 'card_last4' => '2222', 'card_brand' => 'visa', 'expire_date' => '12/28', 'status' => 'active' ] ];
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42' ] );
				( new EcpgPaymentController() )->pay_page( $request );
				exit( 97 );
			case 'pay-page-foreign-cookie':
				// 登入的是別人（user 71）：能力網址仍可付款，但不得看到擁有者的卡。
				$GLOBALS['ys_cookie_user'] = 71;
				$GLOBALS['ys_cards']       = [ (object) [ 'id' => 14, 'card_last4' => '2222', 'card_brand' => 'visa', 'expire_date' => '12/28', 'status' => 'active' ] ];
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42' ] );
				( new EcpgPaymentController() )->pay_page( $request );
				exit( 97 );
			case 'charge-saved':
				// REST nonce 已把使用者還原成 70（擁有者）：以綁定卡幕後授權 → paid。
				$GLOBALS['ys_current_user'] = 70;
				$GLOBALS['ys_authority']    = [ 'card_id' => 14, 'raw_token' => 'bindcard0123456789abcdef', 'token_hash' => str_repeat( 'ab', 32 ) ];
				$charge = $success_data();
				unset( $charge['BindCardID'], $charge['MerchantMemberID'], $charge['IsSameCard'] );
				$GLOBALS['ys_remote_response'] = [ 'code' => 200, 'body' => $envelope( $charge, $KEY, $IV ) ];
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42', 'card_id' => 14 ] );
				( new EcpgPaymentController() )->charge_saved( $request );
				exit( 97 );
			case 'charge-saved-anon':
				// 沒有登入身分：能力網址不足以扣一張存好的卡。
				$GLOBALS['ys_current_user'] = 0;
				$GLOBALS['ys_authority']    = [ 'card_id' => 14, 'raw_token' => 'bindcard0123456789abcdef', 'token_hash' => str_repeat( 'ab', 32 ) ];
				$request = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42', 'card_id' => 14 ] );
				( new EcpgPaymentController() )->charge_saved( $request );
				exit( 97 );
			case 'pay-page-paid':
				$order->status = 'processing';
				$request       = new WP_REST_Request( [ 'order' => 42, 'key' => 'key-42' ] );
				( new EcpgPaymentController() )->pay_page( $request );
				exit( 97 );
			default:
				fwrite( STDERR, 'unknown scenario' );
				exit( 98 );
		}
		$body    = $envelope( $data, $key, $IV );
		$request = new WP_REST_Request( [], $body );
		( new EcpgPaymentController() )->return_notify( $request );
		exit( 97 );
	}

	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}\n";
	};
	$run = static function ( string $scenario ): array {
		$marker = tempnam( sys_get_temp_dir(), 'ys-ecpg-' );
		file_put_contents( $marker, '' );
		$process = proc_open( [ PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, '--child', $scenario, $marker ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'cannot start child' );
		}
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit  = proc_close( $process );
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $marker ) ) ) );
		@unlink( $marker );
		$find = static function ( string $prefix ) use ( $lines ): array {
			$out = [];
			foreach ( $lines as $line ) {
				if ( str_starts_with( $line, $prefix ) ) {
					$out[] = substr( $line, strlen( $prefix ) );
				}
			}
			return $out;
		};
		return [ 'stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'lines' => $lines, 'status' => $find( 'status:' ), 'paid' => $find( 'paid:' ), 'failed' => $find( 'failed:' ), 'vault' => $find( 'vault:' ), 'redirect' => $find( 'redirect:' ), 'errors' => $find( 'log:error:' ), 'columns' => $find( 'column:' ) ];
	};

	echo "## v058 ECPG settlement and callbacks\n";

	// ── A. EcpgSettlement::apply（同一 process）────────────────────────────────
	$reset = static function (): void {
		YSOrder::$orders = [];
		OrderPaymentDetail::$details = [];
		OrderPaymentDetail::$persist = true;
		ScalarColumnWriter::$writes  = [];
		ScalarColumnWriter::$persist = true;
		YSPaymentLifecycleService::$calls       = [];
		YSPaymentLifecycleService::$paid_result = [ 'success' => true, 'retryable' => false, 'from' => 'pending', 'to' => 'processing', 'message' => '', 'receipt_id' => 'receipt-42' ];
		YSPaymentEffects::$enroll_result = true;
		YSPaymentEffects::$enrolled = [];
		YSPaymentEffects::$effects = [];
		YSPaymentEffects::$run_calls = 0;
		YSPaymentDetailStore::$readable = true;
		YSPaymentDetailStore::$detail = [ '_ys_payment_effects' => [ 'receipt-42' => [ 'target' => 'processing', 'effects' => [] ] ] ];
		YSCreditCard::$created       = [];
		YSCreditCard::$create_result = 77;
		YSSubscription::$bind_result = true;
		YSSubscription::$bind_calls = [];
		YSLogger::$entries = [];
		Settings::$credentials = [ 'test_mode' => true, 'merchant_id' => '3002607', 'hash_key' => 'pwFHCqoQZGmho4w6', 'hash_iv' => 'EkRm7iFT261dpevs' ];
	};

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'done' === $r['vault'] && false === $r['retryable'], 'A1 happy path → paid, vault done' );
	$call = YSPaymentLifecycleService::$calls[0] ?? null;
	$assert( is_array( $call ) && 'paid' === $call[0] && 42 === $call[1] && 'webhook_ecpg_return' === $call[3], 'A2 mark_paid called for the order with a webhook_ reason' );
	$dto = $call[2] ?? [];
	$assert( '2609101200001234' === ( $dto['trade_no'] ?? null ) && '2609101200001234' === ( $dto['gateway_trade_no'] ?? null ) && $MTN === ( $dto['mer_trade_no'] ?? null ) && 1290.0 === ( $dto['paid_amount'] ?? null ) && '2222' === ( $dto['card_4no'] ?? null ) && '777777' === ( $dto['auth_code'] ?? null ) && 'credit_card' === ( $dto['payment_type'] ?? null ), 'A3 DTO carries trade no, mer_trade_no, paid amount, card tail, auth code' );
	$d = OrderPaymentDetail::$details[42];
	$assert( '12345678' === ( $d['gwsr'] ?? null ) && '5' === ( $d['ecpay_eci'] ?? null ) && '777777' === ( $d['ecpay_auth_code'] ?? null ) && 'Credit' === ( $d['ecpay_payment_type'] ?? null ) && 'ecpg_return' === ( $d['ecpay_ecpg_source'] ?? null ), 'A4 authorization evidence (gwsr/eci/auth code/type/source) persisted via CAS' );
	$assert( ! array_key_exists( 'BindCardID', $d ) && ! str_contains( json_encode( $d ), 'bindcard0123456789abcdef' ), 'A5 BindCardID never lands in payment_detail' );
	$assert( [ [ 42, [ 'gateway_trade_no' => '2609101200001234' ] ] ] === ScalarColumnWriter::$writes, 'A6 gateway_trade_no column written' );
	$card = YSCreditCard::$created[0] ?? [];
	$assert( 'bindcard0123456789abcdef' === ( $card['token'] ?? null ) && 'ys_ec_ecpay_ecpg_credit' === ( $card['gateway_id'] ?? null ) && 7 === ( $card['customer_id'] ?? null ) && 70 === ( $card['user_id'] ?? null ) && true === ( $card['is_default'] ?? null ), 'A7 vault: BindCardID is the token, owner + gateway bound, set as default' );
	$assert( '2222' === ( $card['card_last4'] ?? null ) && 'visa' === ( $card['card_brand'] ?? null ) && '12/28' === ( $card['expire_date'] ?? null ), 'A8 vault metadata: last4, brand from BIN, expiry MM/YY' );
	$assert( [ [ 42, 7, 70, 'ys_ec_ecpay_ecpg_credit', 77 ] ] === YSSubscription::$bind_calls, 'A8b receipt runner binds the exact vaulted card to subscriptions from this initial order' );
	$assert( [ [ 42, 'receipt-42', 'ecpg_card_vault' ] ] === YSPaymentEffects::$enrolled && 'done' === ( YSPaymentEffects::$effects['ecpg_card_vault']['state'] ?? '' ), 'A8c provider-only card binding is enrolled and completed on the payment receipt' );

	$reset();
	$order = $make_order();
	YSSubscription::$bind_result = false;
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'failed' === $r['vault'] && 'failed' === ( YSPaymentEffects::$effects['ecpg_card_vault']['state'] ?? '' ), 'A8d materialization-pending binding remains failed so ReturnURL retry can resume it' );
	YSSubscription::$bind_result = true;
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'done' === $r['vault'] && 'done' === ( YSPaymentEffects::$effects['ecpg_card_vault']['state'] ?? '' ) && 2 === count( YSSubscription::$bind_calls ), 'A8e replay resumes the same receipt and completes initial subscription binding' );
	$created_after_done = count( YSCreditCard::$created );
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'done' === $r['vault'] && $created_after_done === count( YSCreditCard::$created ) && 2 === count( YSSubscription::$bind_calls ), 'A8f completed receipt replay does not repeat vault or binding work' );

	$reset();
	$order = $make_order();
	$renewal_detail = json_decode( (string) $order->payment_detail, true );
	$renewal_detail['type'] = 'subscription_renewal';
	$order->payment_detail = json_encode( $renewal_detail );
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'done' === $r['vault'] && 1 === count( YSCreditCard::$created ) && [] === YSSubscription::$bind_calls, 'A8g renewal BindCardID is vaulted without replacing or waiting on the initial subscription mandate' );

	$reset();
	$order = $make_order();
	$custom_detail = json_decode( (string) $order->payment_detail, true );
	$custom_detail['source'] = 'subscription_custom';
	$order->payment_detail = json_encode( $custom_detail );
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'done' === $r['vault'] && 1 === count( YSCreditCard::$created ) && [] === YSSubscription::$bind_calls, 'A8h custom subscription BindCardID is vaulted without mutating the existing mandate' );

	$reset();
	$order = $make_order( 7, 'completed' );
	YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => false, 'outcome' => 'rejected', 'from' => 'completed', 'to' => 'processing', 'message' => 'not allowed', 'receipt_id' => '' ];
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_result' );
	$assert( 'already_paid' === $r['status'] && 'done' === $r['vault'] && [ 42, 'receipt-42', 'ecpg_card_vault' ] === ( YSPaymentEffects::$enrolled[0] ?? [] ), 'A9 fulfilled duplicate resumes the original durable paid receipt when Core rejects processing transition' );

	$reset();
	$order = $make_order( 7, 'shipping' );
	YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => false, 'outcome' => 'rejected', 'from' => 'shipping', 'to' => 'processing', 'message' => 'not allowed', 'receipt_id' => '' ];
	YSPaymentDetailStore::$detail = [];
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'already_paid' === $r['status'] && 'failed' === $r['vault'] && [] === YSCreditCard::$created, 'A9a fulfilled replay without its original receipt fails closed and never fabricates a provider effect receipt' );

	$reset();
	$order = $make_order();
	$order->status = 'cancelled';
	YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => false, 'outcome' => 'rejected', 'from' => 'cancelled', 'to' => 'processing', 'message' => 'not allowed' ];
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'persist_failed' === $r['status'] && true === $r['retryable'] && 'skipped' === $r['vault'] && [] === YSCreditCard::$created, 'A9b cancelled order plus provider success stays unacknowledged for reconciliation and never creates a card effect' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'MerchantID' => '2000132' ] ), 'ecpg_return' );
	$assert( 'rejected' === $r['status'] && [] === YSPaymentLifecycleService::$calls && [] === YSCreditCard::$created, 'A10 foreign MerchantID → rejected, nothing touched' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'OrderInfo' => [ 'MerchantTradeNo' => 'YSOTHER' ] ] ), 'ecpg_return' );
	$assert( 'rejected' === $r['status'] && [] === YSPaymentLifecycleService::$calls, 'A11 MerchantTradeNo not on this order → rejected' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'RtnCode' => 10100252, 'RtnMsg' => '額度不足' ] ), 'ecpg_confirm' );
	$assert( 'failed' === $r['status'] && '額度不足' === $r['message'] && 'failed' === ( YSPaymentLifecycleService::$calls[0][0] ?? '' ) && '10100252' === ( YSPaymentLifecycleService::$calls[0][2]['response_code'] ?? '' ) && [] === YSCreditCard::$created, 'A12 RtnCode≠1 → mark_failed with the code, no vault' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'OrderInfo' => [ 'TradeStatus' => '0' ] ] ), 'ecpg_return' );
	$assert( 'pending' === $r['status'] && [] === YSPaymentLifecycleService::$calls && [] === YSCreditCard::$created, 'A13 TradeStatus 0 (accepted, unpaid) → pending, no state change' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'OrderInfo' => [ 'TradeAmt' => 990 ], 'CardInfo' => [ 'Amount' => 990 ] ] ), 'ecpg_return' );
	$assert( 'rejected' === $r['status'] && [] === YSPaymentLifecycleService::$calls && 1 === count( array_filter( YSLogger::$entries, static fn ( array $e ): bool => 'error' === $e[0] ) ), 'A14 amount ≠ charged amount → rejected with an error log' );

	$reset();
	$order = $make_order();
	$r     = EcpgSettlement::apply( $order, $success_data( [ 'OrderInfo' => [ 'TradeNo' => '' ] ] ), 'ecpg_return' );
	$assert( 'rejected' === $r['status'] && [] === YSPaymentLifecycleService::$calls, 'A15 empty TradeNo → rejected (an identifier is not optional)' );

	$reset();
	$order = $make_order();
	$data  = $success_data();
	unset( $data['BindCardID'] );
	$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'skipped' === $r['vault'], 'A16 no BindCardID (pure payment) → paid, vault skipped' );

	$reset();
	$order = $make_order( 0 );
	$r     = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'skipped' === $r['vault'] && [] === YSCreditCard::$created, 'A17 no customer → vault skipped even with a BindCardID' );

	$reset();
	$order = $make_order();
	YSCreditCard::$create_result = false;
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'paid' === $r['status'] && 'failed' === $r['vault'], 'A18 vault write failure is reported separately from the paid transition' );

	$reset();
	$order = $make_order();
	OrderPaymentDetail::$persist = false;
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'persist_failed' === $r['status'] && true === $r['retryable'] && [] === YSPaymentLifecycleService::$calls && [] === ScalarColumnWriter::$writes, 'A19 evidence CAS failure → persist_failed before any state change' );

	$reset();
	$order = $make_order();
	ScalarColumnWriter::$persist = false;
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'persist_failed' === $r['status'] && [] === YSPaymentLifecycleService::$calls, 'A20 gateway_trade_no write failure → persist_failed' );

	$reset();
	$order = $make_order();
	YSPaymentLifecycleService::$paid_result = [ 'success' => false, 'retryable' => true, 'from' => 'pending', 'to' => 'processing', 'message' => 'db' ];
	$r = EcpgSettlement::apply( $order, $success_data(), 'ecpg_return' );
	$assert( 'persist_failed' === $r['status'] && true === $r['retryable'] && [] === YSCreditCard::$created, 'A21 retryable lifecycle failure → persist_failed, vault not attempted' );

	// ── C. 訂單查找 SQL ──────────────────────────────────────────────────────
	$reset();
	$order = $make_order();
	$GLOBALS['wpdb']->queries = [];
	$GLOBALS['wpdb']->row     = (object) [ 'id' => 42 ];
	$found = EcpgSettlement::find_order_by_merchant_trade_no( $MTN );
	$sql   = $GLOBALS['wpdb']->queries[0] ?? '';
	$assert( is_object( $found ) && 42 === $found->id, 'C1 order found by MerchantTradeNo' );
	$assert( 2 === substr_count( $sql, 'JSON_UNQUOTE(JSON_EXTRACT(payment_detail' ) && str_contains( $sql, "'$.mer_trade_no'" ) && str_contains( $sql, "'$.ecpay_merchant_trade_no'" ) && str_contains( $sql, "= '{$MTN}'" ), 'C2 lookup compares JSON_UNQUOTE(JSON_EXTRACT()) of both keys (never a quoted literal)' );
	$GLOBALS['wpdb']->queries = [];
	$assert( null === EcpgSettlement::find_order_by_merchant_trade_no( "YS' OR 1=1" ) && [] === $GLOBALS['wpdb']->queries, 'C3 non-alphanumeric MerchantTradeNo is refused before SQL' );
	$GLOBALS['wpdb']->row = (object) [ 'id' => 42 ];
	$assert( null === EcpgSettlement::find_order_by_merchant_trade_no( 'YSNOTMINE' ), 'C4 a row whose detail does not carry the number is not returned' );

	// ── B. controller 回呼（子程序）────────────────────────────────────────────
	$res = $run( 'paid-bind' );
	$assert( '1|OK' === $res['stdout'] && [ '200' ] === $res['status'] && 1 === count( $res['paid'] ) && 1 === count( $res['vault'] ) && '' === $res['stderr'], 'B1 ReturnURL paid + bind → mark_paid, vault, 1|OK 200, no stderr' );
	$res = $run( 'vault-fails' );
	$assert( '0|Persist Failed' === $res['stdout'] && [ '500' ] === $res['status'] && 1 === count( $res['paid'] ), 'B2 vault failure after paid → 500 (no ACK: BindCardID only exists in this payload)' );
	$res = $run( 'rtn-fail' );
	$assert( '1|OK' === $res['stdout'] && [ '200' ] === $res['status'] && 1 === count( $res['failed'] ) && [] === $res['paid'] && [] === $res['vault'], 'B3 RtnCode≠1 → mark_failed and ACK' );
	$res = $run( 'unknown-order' );
	$assert( '0|Order Not Found' === $res['stdout'] && [ '404' ] === $res['status'] && [] === $res['paid'], 'B4 unknown MerchantTradeNo → 404' );
	$res = $run( 'bad-key' );
	$assert( '0|Invalid Payload' === $res['stdout'] && [ '400' ] === $res['status'] && [] === $res['paid'], 'B5 payload encrypted with another key → 400' );
	$res = $run( 'amount-mismatch' );
	$assert( '1|OK' === $res['stdout'] && [ '200' ] === $res['status'] && [] === $res['paid'] && [] === $res['vault'] && 1 === count( $res['errors'] ), 'B6 amount mismatch → rejected but ACKed (retry cannot change it), error logged' );
	$res = $run( 'already-paid' );
	$assert( '1|OK' === $res['stdout'] && [ '200' ] === $res['status'] && 1 === count( $res['vault'] ), 'B7 fulfilled duplicate → recover original receipt, finish vault, ACK' );
	$res = $run( 'lifecycle-retryable' );
	$assert( '0|Persist Failed' === $res['stdout'] && [ '500' ] === $res['status'], 'B8 our own persistence failure → 500 so ECPay resends' );

	$res = $run( 'result-paid' );
	$assert( [ 'https://shop.invalid/thankyou/?order=42&key=key-42' ] === $res['redirect'] && 1 === count( $res['paid'] ) && 1 === count( $res['vault'] ), 'B9 OrderResultURL paid → settle + redirect to the thank-you page' );
	$res = $run( 'result-fail' );
	$assert( [ '200' ] === $res['status'] && 1 === count( $res['failed'] ) && str_contains( $res['stdout'], '報失卡' ) && str_contains( $res['stdout'], 'repay_order=42' ) && str_contains( $res['stdout'], '重新付款' ), 'B10 OrderResultURL failure → mark_failed and a notice page with the repay link' );

	$res = $run( 'pay-page' );
	$assert( [ '200' ] === $res['status'] && str_contains( $res['stdout'], 'id="ECPayPayment"' ) && str_contains( $res['stdout'], 'window.ysEcpayEcpg = {' ) && str_contains( $res['stdout'], '"flow":"bind"' ) && str_contains( $res['stdout'], '"env":"Stage"' ) && str_contains( $res['stdout'], 'ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js' ) && str_contains( $res['stdout'], '/ecpay/ecpg/token' ), 'B11 pay page renders the SDK container, config (flow/env/token endpoint) and the stage SDK' );
	$assert( str_contains( $res['stdout'], 'NT$ 1,290' ) && str_contains( $res['stdout'], 'YS-42' ) && '' === $res['stderr'], 'B12 pay page shows the charged amount and order number without notices' );
	$res = $run( 'pay-page-bad-key' );
	$assert( str_contains( $res['stdout'], '找不到訂單' ) && ! str_contains( $res['stdout'], 'ECPayPayment' ), 'B13 wrong order key → notice page, no payment UI' );
	$res = $run( 'pay-page-saved' );
	$assert( [ '200' ] === $res['status'] && str_contains( $res['stdout'], 'class="ys-ecpg__btn ys-ecpg-saved-pay" data-card-id="14"' ) && str_contains( $res['stdout'], '"hasSaved":true' ) && str_contains( $res['stdout'], '"nonce":"nonce-wp_rest-70"' ), 'B15 owner cookie on the REST pay page → saved card offered with a user-bound wp_rest nonce' );
	$assert( str_contains( $res['stdout'], 'id="ys-ecpg-new-card" hidden' ) && in_array( 'cards_lookup:7:ys_ec_ecpay_ecpg_credit', $res['lines'], true ), 'B16 saved-card view hides the new-card panel and looks up cards for this gateway only' );
	$res = $run( 'pay-page-foreign-cookie' );
	$assert( [ '200' ] === $res['status'] && ! str_contains( $res['stdout'], 'ys-ecpg-saved-pay' ) && str_contains( $res['stdout'], '"hasSaved":false' ) && ! in_array( 'cards_lookup:7:ys_ec_ecpay_ecpg_credit', $res['lines'], true ), 'B17 a different logged-in user never sees the owner\'s cards' );
	$res = $run( 'charge-saved' );
	$json = json_decode( $res['stdout'], true );
	$remote = array_values( array_filter( $res['lines'], static fn ( string $l ): bool => str_starts_with( $l, 'remote:' ) ) );
	$sent   = [] !== $remote ? json_decode( substr( $remote[0], 7 ), true ) : [];
	$assert( is_array( $json ) && true === ( $json['ok'] ?? false ) && 'paid' === ( $json['status'] ?? '' ) && [ '200' ] === $res['status'] && 1 === count( $res['paid'] ), 'B18 charge-saved by the owner → CreatePaymentWithCardID → paid JSON + mark_paid' );
	$assert( str_ends_with( (string) ( $sent['url'] ?? '' ), '/Merchant/CreatePaymentWithCardID' ) && 'bindcard0123456789abcdef' === ( $sent['data']['BindCardID'] ?? null ) && 0 === ( $sent['data']['Need3D'] ?? null ) && 'YSC7' === ( $sent['data']['ConsumerInfo']['MerchantMemberID'] ?? null ) && $MTN === ( $sent['data']['OrderInfo']['MerchantTradeNo'] ?? null ) && 1290 === ( $sent['data']['OrderInfo']['TotalAmount'] ?? null ), 'B19 charge-saved payload: vault token as BindCardID, Need3D 0, member key, persisted MTN and amount' );
	$assert( in_array( 'authority_lookup:[14,7,70,"ys_ec_ecpay_ecpg_credit"]', $res['lines'], true ), 'B20 charge-saved resolves the card through the owner-scoped token authority for this gateway' );
	$res = $run( 'charge-saved-anon' );
	$json = json_decode( $res['stdout'], true );
	$assert( [ '403' ] === $res['status'] && 'forbidden' === ( $json['status'] ?? '' ) && [] === array_filter( $res['lines'], static fn ( string $l ): bool => str_starts_with( $l, 'remote:' ) ) && [] === $res['paid'], 'B21 charge-saved without a logged-in owner → 403, nothing sent' );
	$res = $run( 'pay-page-paid' );
	$assert( [ 'https://shop.invalid/thankyou/?order=42&key=key-42' ] === $res['redirect'], 'B14 already-paid order → straight to the thank-you page' );

	echo "\nv058: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
