<?php
/**
 * v057 — 站內付 2.0 綁卡閘道：型錄／註冊接線、首刷持久化、續扣契約（v0.5.0）
 *
 * 對著 production 程式碼驗證三層：
 *
 *   1. 接線：型錄多一列 `ys_ec_ecpay_ecpg_credit`（transport=ecpg、預設關）、manifest 由型錄導出、
 *      `Plugin::REGISTERED_GATEWAY_IDS` 與型錄同集合（0.5.0 前只列四個舊方式：只開新方式時付款
 *      回呼路由根本不註冊）、ECPG 六條路只在該方式開著時註冊、manifest 的 allowed_hosts 有兩個 domain。
 *   2. 首刷 `process_payment()`：不碰綠界，只持久化交易識別（穩定 MerchantTradeNo、實際金額、
 *      流程種類、會員鍵），交付**本站**付款頁網址；金額非正整數或沒有 dispatch context 一律拒絕
 *      且不落盤。
 *   3. 續扣 `process_token_charge()`：與 PayUni 對齊的前置拒絕、送出前識別落盤、payload 形狀、
 *      三種結果分類原樣交回 coordinator（success／indeterminate／provider_failed／rejected）。
 *
 * 核心與 WordPress 以替身提供；綠界客戶端以子類替身攔截 payload 並回腳本化結果。
 *
 * Run: php tests/regression/v057_ecpg_gateway_wiring.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	function __( $text, $domain = '' ) { return $text; }
	function rest_url( string $path = '' ): string { return 'https://shop.invalid/wp-json/' . ltrim( $path, '/' ); }
	function home_url( string $path = '' ): string { return 'https://shop.invalid' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
	function current_time( string $format ): string { return '2026/09/10 12:00:00'; }
	function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
}

namespace YangSheep\Ecommerce\Gateways {
	interface YSGatewayInterface {}
	interface YSOrderScopedTokenChargeGatewayInterface extends YSGatewayInterface {}
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder {
		/** @var array<int,object> */
		public static array $orders = [];
		/** @var array<int,array<int,object>> */
		public static array $items = [];
		public static int $forgotten = 0;
		public static function find( int $id ): ?object { return self::$orders[ $id ] ?? null; }
		public static function forget( int $id ): void { ++self::$forgotten; }
		public static function get_items( int $order_id ): array { return self::$items[ $order_id ] ?? []; }
		public static function generate_order_key( int $order_id, string $order_number ): string { return 'key-' . $order_id . '-' . $order_number; }
	}
	final class YSSubscription {
		public static ?object $subscription = null;
		public static function find( int $id ): ?object { return self::$subscription && (int) self::$subscription->id === $id ? self::$subscription : null; }
	}
	final class YSCreditCard {
		public const DEFAULT_LOOKUP_FOUND  = 'found';
		public const DEFAULT_LOOKUP_ABSENT = 'absent';
		public const DEFAULT_LOOKUP_ERROR  = 'error';
		public static array $default_result = [ 'outcome' => 'absent' ];
		public static array $default_calls = [];
		public static ?array $authority = null;
		public static array $authority_calls = [];
		public static function get_default_card_result( int $customer_id, ?string $gateway_id = null ): array { self::$default_calls[] = func_get_args(); return self::$default_result; }
		public static function get_token_charge_authority_for_owner( int $card_id, int $customer_id, int $user_id = 0, string $gateway_id = '' ): ?array {
			self::$authority_calls[] = func_get_args();
			return self::$authority;
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
	final class YSPaymentDispatch {
		public static int $order_id = 0;
		public static string $operation_key = '';
		public static bool $bind_result = true;
		public static array $bind_calls = [];
		public static function current_order_id(): int { return self::$order_id; }
		public static function current_operation_key(): string { return self::$operation_key; }
		public static function bind_renewal_token_identity_from_context( int $card_id, string $token_hash ): bool {
			self::$bind_calls[] = [ $card_id, $token_hash ];
			return self::$bind_result;
		}
	}
	final class YSSavedCardChargePolicy {
		public static bool $allow = true;
		public static function allows_subscription_renewal_charge( int $subscription_id ): bool { return self::$allow; }
		public static function blocked_result( string $source, array $context = [] ): array { return [ 'success' => false, 'message' => 'blocked:' . $source ]; }
	}
	final class YSPaymentDetailResultStub {
		public function __construct( private string $decision, private bool $persisted ) {}
		public function get_decision(): string { return $this->decision; }
		public function is_persisted(): bool { return $this->persisted; }
		public function to_log_context(): array { return []; }
	}
	final class YSPaymentDetailStore {
		public const STALE = 'stale';
		public static string $decision = 'updated';
		public static bool $persisted = true;
		public static array $deltas = [];
		public static function apply_delta( int $order_id, array $baseline, array $mutated ): YSPaymentDetailResultStub {
			self::$deltas[] = [ 'order_id' => $order_id, 'baseline' => $baseline, 'mutated' => $mutated ];
			return new YSPaymentDetailResultStub( self::$decision, self::$persisted );
		}
	}
}

namespace YangSheep\Ecommerce\Utils {
	final class YSLogger {
		public static array $entries = [];
		public static function error( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'error', $m ]; }
		public static function warning( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'warning', $m ]; }
		public static function info( string $c, string $m, array $ctx = [] ): void { self::$entries[] = [ 'info', $m ]; }
	}
}

namespace YangSheep\YSCartEcpay {
	final class Plugin {
		public static function manifest(): array { return []; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static bool $has_credentials = true;
		public static function payment_credentials(): array { return [ 'merchant_id' => '3002607', 'hash_key' => 'pwFHCqoQZGmho4w6', 'hash_iv' => 'EkRm7iFT261dpevs', 'test_mode' => true ]; }
		public static function has_payment_credentials(): bool { return self::$has_credentials; }
		public static function gateway_enabled( string $alias ): bool { return true; }
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
		/** @var array<int,array>|null */
		public static array $details = [];
		public static bool $read_null = false;
		public static bool $persist = true;
		public static function read( int $order_id ): ?array { return self::$read_null ? null : ( self::$details[ $order_id ] ?? [] ); }
		public static function mutate( int $order_id, callable $mutator ): FakeWrite {
			$current = self::$details[ $order_id ] ?? [];
			$next    = $mutator( $current );
			if ( is_array( $next ) && self::$persist ) {
				self::$details[ $order_id ] = $next;
			}
			return new FakeWrite( self::$persist );
		}
	}
	final class ScalarColumnWriter {
		public static array $writes = [];
		public static bool $persist = true;
		public static function write( int $order_id, array $columns ): array {
			self::$writes[] = [ $order_id, $columns ];
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
	require_once $root . '/src/Payment/EcpayPaymentCatalog.php';
	require_once $root . '/src/Payment/EcpayGatewayBase.php';
	require_once $root . '/src/Ecpg/EcpgAesCodec.php';
	require_once $root . '/src/Ecpg/EcpgClient.php';
	require_once $root . '/src/Ecpg/EcpgOrderContext.php';
	require_once $root . '/src/Payment/EcpayEcpgCreditGateway.php';
}

namespace YangSheep\YSCartEcpay\Ecpg {
	/** 攔截 payload、回腳本化結果；絕不碰網路。 */
	final class FakeEcpgClient extends EcpgClient {
		public array $calls = [];
		public array $next = [];
		public function create_payment_with_card_id( array $data ): array {
			$this->calls[] = $data;
			return $this->next;
		}
	}
}

namespace {
	use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
	use YangSheep\Ecommerce\Gateways\YSOrderScopedTokenChargeGatewayInterface;
	use YangSheep\Ecommerce\Models\YSCreditCard;
	use YangSheep\Ecommerce\Models\YSOrder;
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch;
	use YangSheep\Ecommerce\Services\Payment\YSSavedCardChargePolicy;
	use YangSheep\Ecommerce\Utils\YSLogger;
	use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
	use YangSheep\YSCartEcpay\Ecpg\EcpgOrderContext;
	use YangSheep\YSCartEcpay\Ecpg\FakeEcpgClient;
	use YangSheep\YSCartEcpay\Payment\EcpayEcpgCreditGateway;
	use YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog;
	use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
	use YangSheep\YSCartEcpay\Support\ScalarColumnWriter;
	use YangSheep\YSCartEcpay\Support\Settings;

	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}\n";
	};
	$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	echo "## v057 ECPG gateway wiring\n";

	// ── A. 型錄／manifest／Plugin 接線 ────────────────────────────────────────
	$descriptor = EcpayPaymentCatalog::get( 'ys_ec_ecpay_ecpg_credit' );
	$assert( is_array( $descriptor ) && 'ecpg_credit' === $descriptor['alias'], 'A1 catalogue has ys_ec_ecpay_ecpg_credit (alias ecpg_credit)' );
	$assert( is_array( $descriptor ) && false === $descriptor['default_enabled'] && '' !== (string) $descriptor['activation'] && str_contains( (string) $descriptor['activation'], '站內付' ), 'A2 ECPG method ships disabled with an activation note' );
	$assert( is_array( $descriptor ) && EcpayEcpgCreditGateway::class === $descriptor['class'] && 'ys_ec_ecpay_ecpg_credit_enabled' === $descriptor['enabled_option'], 'A3 descriptor points at the ECPG gateway class and its own switch' );
	$assert( 'ecpg' === EcpayPaymentCatalog::transport( 'ys_ec_ecpay_ecpg_credit' ) && 'aio' === EcpayPaymentCatalog::transport( 'ys_ec_ecpay_credit' ) && '' === EcpayPaymentCatalog::transport( 'nope' ), 'A4 transport(): ecpg / aio default / unknown empty' );
	$aio_count = count( array_filter( EcpayPaymentCatalog::ids(), static fn ( string $id ): bool => 'aio' === EcpayPaymentCatalog::transport( $id ) ) );
	$assert( 12 === count( EcpayPaymentCatalog::all() ) && 11 === $aio_count, 'A5 catalogue is 11 AIO methods + 1 ECPG method' );
	$assert( count( EcpayPaymentCatalog::manifest_methods() ) === 12 && in_array( 'ys_ec_ecpay_ecpg_credit', array_column( EcpayPaymentCatalog::manifest_methods(), 'id' ), true ), 'A6 manifest methods include the ECPG method' );
	$assert( isset( EcpayPaymentCatalog::admin_rows()['ecpg_credit'] ), 'A7 admin rows include the ECPG switch' );

	$plugin_src = (string) file_get_contents( $root . '/src/Plugin.php' );
	preg_match( '/REGISTERED_GATEWAY_IDS = \[(.*?)\];/s', $plugin_src, $m );
	preg_match_all( "/'([a-z_]+)'/", (string) ( $m[1] ?? '' ), $ids );
	$listed = $ids[1] ?? [];
	sort( $listed );
	$catalogue_ids = EcpayPaymentCatalog::ids();
	sort( $catalogue_ids );
	$assert( $listed === $catalogue_ids, 'A8 Plugin::REGISTERED_GATEWAY_IDS equals the catalogue id set (callback routes register for any enabled method)' );
	$assert( str_contains( $plugin_src, "is_method_enabled( 'payment', EcpgOrderContext::GATEWAY_ID )" ) && str_contains( $plugin_src, 'EcpgPaymentController::register_routes();' ), 'A9 ECPG routes register only when the ECPG method is enabled' );
	$manifest_src = (string) file_get_contents( $root . '/manifest.php' );
	foreach ( [ 'ecpg-stage.ecpay.com.tw', 'ecpg.ecpay.com.tw', 'ecpayment-stage.ecpay.com.tw', 'ecpayment.ecpay.com.tw' ] as $host ) {
		$assert( str_contains( $manifest_src, "'{$host}'" ), "A10 manifest allowed_hosts lists {$host}" );
	}
	$assert( str_contains( $manifest_src, "'route' => '/ecpay/ecpg/return'" ) && str_contains( $manifest_src, "'route' => '/ecpay/ecpg/result'" ), 'A11 manifest declares the ECPG callback routes' );
	$assert( is_file( $root . '/assets/js/ys-cart-ecpay-ecpg-pay.js' ) && is_file( $root . '/templates/frontend/ecpg-pay.php' ) && is_file( $root . '/templates/frontend/ecpg-notice.php' ), 'A12 pay page assets exist' );
	$template = (string) file_get_contents( $root . '/templates/frontend/ecpg-pay.php' );
	$assert( str_contains( $template, 'id="ECPayPayment"' ) && str_contains( $template, 'jquery-3.7.1.min.js' ) && str_contains( $template, 'node-forge@0.7.0' ), 'A13 pay page keeps the fixed SDK container id and loads jQuery + node-forge before the SDK' );

	// ── B. 閘道基本契約 ────────────────────────────────────────────────────────
	$client  = new FakeEcpgClient();
	$gateway = new EcpayEcpgCreditGateway( $client );
	$assert( 'ys_ec_ecpay_ecpg_credit' === $gateway->get_id() && EcpgOrderContext::GATEWAY_ID === $gateway->get_id(), 'B1 gateway id is the catalogue id' );
	$assert( true === $gateway->supports_token(), 'B2 supports_token() → subscription checkout keeps this gateway' );
	$assert( $gateway instanceof YSOrderScopedTokenChargeGatewayInterface, 'B3 opts into the order-scoped token-charge contract (Core fails unknown token gateways closed)' );
	$assert( '綠界信用卡（站內付）' === $gateway->get_title(), 'B4 title from the catalogue' );

	// ── C. 首刷 process_payment ────────────────────────────────────────────────
	$reset = static function () use ( $client ): void {
		YSOrder::$orders = [];
		YSOrder::$items  = [];
		OrderPaymentDetail::$details   = [];
		OrderPaymentDetail::$read_null = false;
		OrderPaymentDetail::$persist   = true;
		ScalarColumnWriter::$writes    = [];
		ScalarColumnWriter::$persist   = true;
		YSPaymentDispatch::$order_id      = 0;
		YSPaymentDispatch::$operation_key = '';
		YSPaymentDispatch::$bind_result   = true;
		YSPaymentDispatch::$bind_calls    = [];
		YSPaymentDetailStore::$deltas     = [];
		YSPaymentDetailStore::$decision   = 'updated';
		YSPaymentDetailStore::$persisted  = true;
		YSSavedCardChargePolicy::$allow   = true;
		YSCreditCard::$default_result     = [ 'outcome' => 'absent' ];
		YSCreditCard::$default_calls      = [];
		YSCreditCard::$authority          = null;
		YSCreditCard::$authority_calls    = [];
		YSLogger::$entries = [];
		Settings::$has_credentials = true;
		$client->calls = [];
		$client->next  = [];
	};
	$order_of = static function ( int $id, string $total, bool $subscription, int $customer_id = 7 ): object {
		YSOrder::$orders[ $id ] = (object) [ 'id' => $id, 'order_number' => 'YS-' . $id, 'total' => $total, 'status' => 'pending', 'customer_id' => $customer_id, 'user_id' => 70, 'billing_email' => 'buyer@example.com', 'billing_phone' => '0912345678', 'billing_name' => '王小明', 'billing_country' => 'TW', 'payment_detail' => '{}' ];
		YSOrder::$items[ $id ]  = [ (object) [ 'product_title' => '月訂閱', 'variant_label' => '', 'is_subscription' => $subscription ? 1 : 0, 'quantity' => 1 ] ];
		return YSOrder::$orders[ $id ];
	};

	$reset();
	YSPaymentDispatch::$operation_key = 'op-42-gen1-nonce';
	$order = $order_of( 42, '1290', true );
	$order->payment_detail = json_encode( [ 'type' => 'subscription_renewal', 'subscription_id' => 9 ] );
	OrderPaymentDetail::$details[42] = [ 'type' => 'subscription_renewal', 'subscription_id' => 9 ];
	$r = $gateway->process_payment( 42 );
	$expected_mtn = 'YS' . strtoupper( substr( hash( 'sha256', 'op-42-gen1-nonce' ), 0, 18 ) );
	$assert( true === ( $r['success'] ?? false ) && ! isset( $r['form_data'] ), 'C1 process_payment succeeds without any AIO form' );
	$assert( str_starts_with( (string) ( $r['redirect_url'] ?? '' ), 'https://shop.invalid/wp-json/ys-ecommerce/v1/ecpay/ecpg/pay?' ) && str_contains( (string) $r['redirect_url'], 'order=42' ) && str_contains( (string) $r['redirect_url'], 'key=key-42-YS-42' ), 'C2 redirect goes to the hosted pay page with order + key' );
	$d = OrderPaymentDetail::$details[42] ?? [];
	$assert( $expected_mtn === ( $d['mer_trade_no'] ?? null ) && $expected_mtn === ( $d['ecpay_merchant_trade_no'] ?? null ) && 'op-42-gen1-nonce' === ( $d['ecpay_operation_key'] ?? null ), 'C3 stable MerchantTradeNo derived from the operation key is persisted (both keys) with the operation key' );
	$assert( 1290 === ( $d['ecpay_charged_amount'] ?? null ) && 'ecpay' === ( $d['payment_provider'] ?? null ) && 'ys_ec_ecpay_ecpg_credit' === ( $d['payment_method'] ?? null ) && 'stage' === ( $d['ecpay_environment'] ?? null ) && '3002607' === ( $d['ecpay_merchant_id'] ?? null ), 'C4 charged amount, provider, method, environment and merchant are persisted' );
	$assert( 'subscription_renewal' === ( $d['type'] ?? null ) && 'bind' === ( $d['ecpay_ecpg_flow'] ?? null ) && 'YSC7' === ( $d['ecpay_ecpg_member_id'] ?? null ), 'C5 manual renewal of a subscription item → CreateBindCard flow retains follow-up identity' );
	$assert( [ [ 42, [ 'gateway_id' => 'ys_ec_ecpay_ecpg_credit', 'payment_method' => 'ys_ec_ecpay_ecpg_credit' ] ] ] === ScalarColumnWriter::$writes, 'C6 gateway identity columns written' );
	$assert( [] === $client->calls, 'C7 process_payment never calls ECPay' );

	$reset();
	YSPaymentDispatch::$operation_key = 'op-43';
	$order_of( 43, '500', false );
	$gateway->process_payment( 43 );
	$assert( 'pay' === ( OrderPaymentDetail::$details[43]['ecpay_ecpg_flow'] ?? null ), 'C8 non-subscription order → pay flow (no binding)' );

	$reset();
	YSPaymentDispatch::$operation_key = 'op-44';
	$order_of( 44, '500', true, 0 );
	$gateway->process_payment( 44 );
	$assert( 'pay' === ( OrderPaymentDetail::$details[44]['ecpay_ecpg_flow'] ?? null ) && '' === ( OrderPaymentDetail::$details[44]['ecpay_ecpg_member_id'] ?? 'x' ) && [ 'warning' ] === array_unique( array_column( YSLogger::$entries, 0 ) ), 'C9 subscription order without a customer cannot bind → pay flow + warning' );

	$reset();
	YSPaymentDispatch::$operation_key = 'op-45';
	$order_of( 45, '100.5', false );
	$r = $gateway->process_payment( 45 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === ( OrderPaymentDetail::$details[45] ?? [] ) && [] === ScalarColumnWriter::$writes, 'C10 non-integer TWD amount → rejected before any persistence' );

	$reset();
	$order_of( 46, '500', false );
	$r = $gateway->process_payment( 46 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === ( OrderPaymentDetail::$details[46] ?? [] ), 'C11 no dispatch context (no operation key) → rejected, nothing persisted' );

	$reset();
	YSPaymentDispatch::$operation_key = 'op-47';
	$order_of( 47, '500', false );
	OrderPaymentDetail::$persist = false;
	$r = $gateway->process_payment( 47 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === ScalarColumnWriter::$writes, 'C12 payment_detail write failure → rejected, identity columns untouched' );

	$reset();
	YSPaymentDispatch::$operation_key = 'op-48';
	$order_of( 48, '500', false );
	ScalarColumnWriter::$persist = false;
	$r = $gateway->process_payment( 48 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ), 'C13 gateway identity write failure → rejected' );

	$reset();
	$r = $gateway->process_payment( 999 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ), 'C14 unknown order → rejected' );

	// ── D. 續扣 process_token_charge ──────────────────────────────────────────
	$renewal = static function () use ( $reset, $order_of, $client ): object {
		$reset();
		$order = $order_of( 500, '1290', true, 7 );
		$order->status = 'pending';
		YSPaymentDispatch::$order_id      = 500;
		YSPaymentDispatch::$operation_key = 'renewal-500-op';
		YSSubscription::$subscription     = (object) [ 'id' => 9, 'customer_id' => 7, 'user_id' => 70, 'card_id' => 31 ];
		YSCreditCard::$authority          = [ 'card_id' => 31, 'raw_token' => 'BINDCARD1234567890', 'token_hash' => str_repeat( 'ab', 32 ) ];
		$client->next = [
			'outcome'  => EcpgClient::OUTCOME_SUCCESS,
			'sent'     => true,
			'rtn_code' => 1,
			'rtn_msg'  => 'Success',
			'message'  => '',
			'data'     => [
				'RtnCode'   => 1,
				'RtnMsg'    => 'Success',
				'OrderInfo' => [ 'MerchantTradeNo' => 'YSX', 'TradeNo' => '2609101200001234', 'TradeAmt' => 1290, 'TradeStatus' => '1', 'PaymentType' => 'Credit' ],
				'CardInfo'  => [ 'AuthCode' => '777777', 'Gwsr' => 12345, 'Card6No' => '431195', 'Card4No' => '2222', 'Amount' => 1290 ],
			],
		];
		return $order;
	};
	$expected_renewal_mtn = 'YS' . strtoupper( substr( hash( 'sha256', 'renewal-500-op' ), 0, 18 ) );

	$renewal();
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( true === ( $r['success'] ?? false ) && false === $r['indeterminate'] && '2609101200001234' === $r['gateway_trade_no'] && '2609101200001234' === $r['transaction_id'] && 1290.0 === $r['reported_paid_amount'], 'D1 success result carries trade no + reported amount' );
	$assert( ( $r['payment_detail'] ?? null ) instanceof YSPaymentDetailDTO && '2609101200001234' === $r['payment_detail']->get_gateway_trade_no() && 1290.0 === $r['payment_detail']->get( 'paid_amount' ) && $expected_renewal_mtn === $r['payment_detail']->get( 'mer_trade_no' ), 'D2 DTO carries gateway_trade_no / paid_amount / mer_trade_no (coordinator amount guard inputs)' );
	$assert( $expected_renewal_mtn === $r['provider_operation_id'], 'D3 provider_operation_id is the stable MerchantTradeNo' );
	$call = $client->calls[0] ?? [];
	$assert( 'BINDCARD1234567890' === ( $call['BindCardID'] ?? null ) && 0 === ( $call['Need3D'] ?? null ), 'D4 payload uses the vault raw token as BindCardID with Need3D=0' );
	$assert( $expected_renewal_mtn === ( $call['OrderInfo']['MerchantTradeNo'] ?? null ) && 1290 === ( $call['OrderInfo']['TotalAmount'] ?? null ) && 'https://shop.invalid/wp-json/ys-ecommerce/v1/ecpay/ecpg/return' === ( $call['OrderInfo']['ReturnURL'] ?? null ) && '月訂閱' === ( $call['OrderInfo']['ItemName'] ?? null ) && '2026/09/10 12:00:00' === ( $call['OrderInfo']['MerchantTradeDate'] ?? null ), 'D5 OrderInfo: MTN, integer amount, ReturnURL, ItemName, MerchantTradeDate' );
	$assert( 'YSC7' === ( $call['ConsumerInfo']['MerchantMemberID'] ?? null ) && 'buyer@example.com' === ( $call['ConsumerInfo']['Email'] ?? null ) && '0912345678' === ( $call['ConsumerInfo']['Phone'] ?? null ) && '158' === ( $call['ConsumerInfo']['CountryCode'] ?? null ), 'D6 ConsumerInfo: member key matches the binding key, contact fields present' );
	$delta = YSPaymentDetailStore::$deltas[0] ?? null;
	$assert( is_array( $delta ) && $expected_renewal_mtn === $delta['mutated']['mer_trade_no'] && 'token_charge' === $delta['mutated']['ecpay_ecpg_flow'] && 1290 === $delta['mutated']['ecpay_charged_amount'] && 'ys_ec_ecpay_ecpg_credit' === $delta['mutated']['payment_method'], 'D7 identity persisted via apply_delta before the provider call' );
	$assert( [ [ 31, str_repeat( 'ab', 32 ) ] ] === YSPaymentDispatch::$bind_calls, 'D8 card identity bound to the attempt (card id + token hash)' );
	$assert( [ [ 31, 7, 70, 'ys_ec_ecpay_ecpg_credit' ] ] === YSCreditCard::$authority_calls, 'D9 authority looked up for the exact owner + this gateway' );

	$renewal();
	$client->next['outcome'] = EcpgClient::OUTCOME_INDETERMINATE;
	$client->next['message'] = 'timeout';
	$client->next['data']    = null;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( false === $r['success'] && true === $r['indeterminate'] && str_contains( $r['message'], 'timeout' ), 'D10 indeterminate provider outcome stays indeterminate' );

	$renewal();
	$client->next = [ 'outcome' => EcpgClient::OUTCOME_PROVIDER_FAILED, 'sent' => true, 'rtn_code' => 10100252, 'rtn_msg' => '額度不足', 'message' => '綠界回報失敗（RtnCode 10100252）：額度不足', 'data' => [ 'RtnCode' => 10100252, 'RtnMsg' => '額度不足', 'OrderInfo' => [ 'MerchantTradeNo' => 'x' ] ] ];
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( false === $r['success'] && false === $r['indeterminate'] && ( $r['payment_detail'] ?? null ) instanceof YSPaymentDetailDTO && '10100252' === $r['payment_detail']->get( 'response_code' ), 'D11 definitive provider failure → terminal (indeterminate=false) with DTO' );

	$renewal();
	$client->next = [ 'outcome' => EcpgClient::OUTCOME_REJECTED, 'sent' => false, 'rtn_code' => null, 'rtn_msg' => '', 'message' => '付款資料寫入失敗，未送出任何請求。', 'data' => null ];
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && false === $r['success'], 'D12 client rejected before sending → pre-send rejection' );

	$renewal();
	unset( $client->next['data']['OrderInfo']['TradeNo'] );
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( false === $r['success'] && true === $r['indeterminate'], 'D13 success without TradeNo → indeterminate (no paid evidence)' );

	$renewal();
	$client->next['data']['OrderInfo']['TradeStatus'] = '0';
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( false === $r['success'] && true === $r['indeterminate'], 'D14 success with TradeStatus 0 → indeterminate' );

	$renewal();
	YSSavedCardChargePolicy::$allow = false;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( false === $r['success'] && str_starts_with( (string) $r['message'], 'blocked:' ) && [] === $client->calls, 'D15 policy denies → blocked, nothing sent' );

	$renewal();
	YSOrder::$orders[500]->status = 'processing';
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === $client->calls, 'D16 order not pending → rejected' );

	$renewal();
	$r = $gateway->process_token_charge( 9, 1200.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === $client->calls && [] === YSPaymentDetailStore::$deltas, 'D17 requested amount ≠ order total → rejected before persistence' );

	$renewal();
	YSOrder::$orders[500]->total = '1290.50';
	$r = $gateway->process_token_charge( 9, 1290.5 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === $client->calls, 'D18 non-integer order total → rejected (never rounded)' );

	$renewal();
	YSSubscription::$subscription->card_id = 0;
	YSCreditCard::$default_result = [ 'outcome' => 'found', 'card' => (object) [ 'id' => 31 ] ];
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && 'subscription_card_unbound' === ( $r['code'] ?? '' ) && [] === YSCreditCard::$default_calls && [] === YSCreditCard::$authority_calls && [] === $client->calls, 'D19 unbound subscription is manual-recovery only; mutable customer default is never consulted' );

	$renewal();
	YSSubscription::$subscription->card_id = 0;
	YSCreditCard::$default_result = [ 'outcome' => 'conflict' ];
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'subscription_card_unbound' === ( $r['code'] ?? '' ) && [] === YSCreditCard::$default_calls && [] === $client->calls, 'D20 every unbound subscription takes the same explicit manual-recovery path' );

	$renewal();
	YSCreditCard::$authority = null;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'saved_card_owner_or_token_unavailable' === ( $r['code'] ?? '' ) && [] === $client->calls, 'D21 authority unavailable → rejected' );

	$renewal();
	YSCreditCard::$authority['token_hash'] = 'nothex';
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'saved_card_authority_malformed' === ( $r['code'] ?? '' ), 'D22 malformed token hash → rejected' );

	$renewal();
	YSPaymentDispatch::$bind_result = false;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'renewal_card_identity_conflict' === ( $r['code'] ?? '' ) && [] === $client->calls && [] === YSPaymentDetailStore::$deltas, 'D23 card identity conflict → rejected before persistence' );

	$renewal();
	YSOrder::$orders[500]->payment_detail = json_encode( [ 'mer_trade_no' => 'YSOTHER' ] );
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === $client->calls, 'D24 another attempt already owns mer_trade_no → rejected' );

	$renewal();
	YSPaymentDetailStore::$decision  = 'stale';
	YSPaymentDetailStore::$persisted = false;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ) && [] === $client->calls, 'D25 identity persistence lost (stale dispatch) → nothing sent' );

	$renewal();
	YSPaymentDispatch::$order_id = 501;
	$r = $gateway->process_token_charge( 9, 1290.0 );
	$assert( 'rejected_terminal' === ( $r['outcome'] ?? '' ), 'D26 dispatch order missing → rejected' );

	echo "\nv057: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
