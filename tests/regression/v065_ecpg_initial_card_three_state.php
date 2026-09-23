<?php
/**
 * v065 — 首購訂閱綁卡對齊 Core 首購卡片權威（v0.5.12）的三態。
 *
 * 0.5.11：BindCardID 存進卡片庫後直接 `bind_initial_order_card()`（不帶收據）；訂閱還沒建立就回 false →
 * `ecpg_card_vault` 失敗 → VAULT_FAILED（要綠界重送）。0.5.12 先 `YSInitialSubscriptionCardBinding::enroll()`：
 *
 *   (a) Core 收下權威 → 帶收據綁卡（Core 走 recover()）；訂閱未建立＝pending 成功，不要求重送。
 *   (b) Core 拒絕權威（2.69.6 以前只認 PayUni、卡片／訂單不符、或拋例外）→ 退回 0.5.11 行為：
 *       不帶收據綁卡，綁不到照舊 VAULT_FAILED。絕不在權威沒寫進收據時回成功。
 *   (c) Core 沒有 enroll（或帶收據的 bind 簽章不足 8 參數）→ 同 (b)，而且根本不呼叫 enroll。
 *
 * 類別「存不存在」無法在同一個 process 內切換，所以本檔是 driver：每個 Core 形狀（flavor）開一個子程序，
 * 子程序宣告對應的替身、跑 production `EcpgSettlement::apply()`（經過真正的 vault runner）與
 * `EcpgInitialCardBinding::attach()`，自己印 PASS／FAIL；driver 彙總並要求每個子程序 rc 0、stderr 空。
 * 期望值全部手寫（呼叫參數、回傳、VAULT 狀態、錯誤日誌字串）。
 *
 * Run: php tests/regression/v065_ecpg_initial_card_three_state.php
 */

declare(strict_types=1);

namespace {
	$v065_flavor = (string) ( getenv( 'V065_FLAVOR' ) ?: '' );
	define( 'V065_FLAVOR', $v065_flavor );

	if ( '' === V065_FLAVOR ) {
		// ── driver ────────────────────────────────────────────────────────────
		$total_pass = 0;
		$total_fail = 0;
		foreach ( [ 'enroll', 'no_class', 'short_bind' ] as $flavor ) {
			$command = [ PHP_BINARY ];
			foreach ( [ 'error_reporting=' . E_ALL, 'display_errors=stderr', 'log_errors=0' ] as $ini ) {
				$command[] = '-d';
				$command[] = $ini;
			}
			$command[] = __FILE__;
			$env = getenv();
			$env['V065_FLAVOR'] = $flavor;
			$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
			if ( ! is_resource( $process ) ) {
				echo "  FAIL  flavor {$flavor}: cannot start child\n";
				++$total_fail;
				continue;
			}
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit = proc_close( $process );
			echo "## flavor {$flavor}\n" . $stdout;
			$pass = preg_match_all( '/^  PASS  /m', $stdout );
			$fail = preg_match_all( '/^  FAIL  /m', $stdout );
			$total_pass += (int) $pass;
			$total_fail += (int) $fail;
			if ( 0 !== $exit || '' !== $stderr || 0 === (int) $pass ) {
				++$total_fail;
				echo "  FAIL  flavor {$flavor} child exit={$exit} stderr=" . strlen( $stderr ) . 'B ' . substr( $stderr, 0, 400 ) . "\n";
			}
		}
		echo "\nv065 ECPG initial card three-state: {$total_pass} PASS / {$total_fail} FAIL\n";
		exit( $total_fail > 0 || 0 === $total_pass ? 1 : 0 );
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	function __( $text, $domain = '' ) { return $text; }
	function current_time( string $format ): string { unset( $format ); return '2026/09/23 12:00:00'; }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_json_encode( $data ) { return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder {
		/** @var array<int,object> */
		public static array $orders = [];
		/** @var list<string> */
		public static array $trace = [];
		public static function find( int $id ): ?object { self::$trace[] = 'find:' . $id; return isset( self::$orders[ $id ] ) ? clone self::$orders[ $id ] : null; }
		public static function forget( int $id ): void { self::$trace[] = 'forget:' . $id; }
	}
	final class YSCreditCard {
		public static int|false $create_result = 77;
		public static function create_or_get( array $data ): int|false { unset( $data ); return self::$create_result; }
	}
	if ( 'short_bind' === \V065_FLAVOR ) {
		/** 舊 Core 形狀：沒有帶收據的綁卡（5 參數）。 */
		final class YSSubscription {
			public static bool $bind_result = false;
			public static array $bind_calls = [];
			public static function bind_initial_order_card( int $order_id, int $customer_id, int $user_id, string $gateway_id, int $card_id ): bool {
				self::$bind_calls[] = func_get_args();
				return self::$bind_result;
			}
		}
	} else {
		/** 2.69.x 形狀：帶收據時走 recover()（8 參數）。 */
		final class YSSubscription {
			public static bool $bind_result = false;
			public static bool $throw = false;
			public static array $bind_calls = [];
			public static function bind_initial_order_card( int $order_id, int $customer_id, int $user_id, string $gateway_id, int $card_id, string $paid_receipt_id = '', ?object $expected_db = null, ?array $expected_session = null ): bool {
				self::$bind_calls[] = func_get_args();
				if ( self::$throw ) {
					throw new \RuntimeException( 'fixture recover exploded' );
				}
				return self::$bind_result;
			}
		}
	}
}

namespace YangSheep\Ecommerce\DTOs {
	final class YSPaymentDetailDTO {
		public function __construct( public array $fields ) {}
		public static function from_legacy_array( array $stored, string $hint = '' ): self { unset( $hint ); return new self( $stored ); }
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	final class YSPaymentLifecycleService {
		public static function mark_paid( int $order_id, object $detail, string $reason = '', ?callable $guard = null, array $options = [] ): array {
			unset( $detail, $reason, $guard );
			// 付款 CAS 才寫定 gateway 欄位：之後的 forget＋find 必須讀到它（0.5.12 的 enroll 以這份為準）。
			if ( isset( \YangSheep\Ecommerce\Models\YSOrder::$orders[ $order_id ] ) ) {
				\YangSheep\Ecommerce\Models\YSOrder::$orders[ $order_id ]->gateway_id = (string) ( $options['columns']['gateway_id'] ?? '' );
				\YangSheep\Ecommerce\Models\YSOrder::$orders[ $order_id ]->status     = 'processing';
			}
			return [ 'success' => true, 'retryable' => false, 'from' => 'pending', 'to' => 'processing', 'message' => '', 'receipt_id' => 'receipt-42' ];
		}
		public static function mark_failed( ...$args ): array { unset( $args ); return [ 'success' => true ]; }
	}
	final class YSPaymentEffects {
		public const STATE_DONE = 'done';
		public static array $effects = [];
		public static ?array $idle = null;
		public static function enroll( int $order_id, string $receipt, string $effect ): bool {
			unset( $order_id );
			if ( '' === $receipt ) { return false; }
			self::$effects[ $effect ] ??= [ 'state' => 'pending' ];
			return true;
		}
		public static function run( int $order_id, string $receipt, array $runners ): array {
			unset( $order_id );
			foreach ( $runners as $effect => $runner ) {
				if ( self::STATE_DONE === ( self::$effects[ $effect ]['state'] ?? '' ) ) { continue; }
				$key = 'yspe-' . $receipt . '-' . $effect;
				$ok  = null === self::$idle ? $runner( $key ) : $runner( $key, self::$idle[0], self::$idle[1] );
				self::$effects[ $effect ] = [ 'state' => true === $ok ? self::STATE_DONE : 'failed' ];
			}
			YSPaymentDetailStore::$detail['_ys_payment_effects']['receipt-42']['effects'] = self::$effects;
			return [];
		}
		public static function receipt_id( int $order_id, string $target, array $detail ): string { unset( $order_id, $target, $detail ); return 'receipt-42'; }
		public static function receipt( array $detail, string $receipt ): array { return is_array( $detail['_ys_payment_effects'][ $receipt ] ?? null ) ? $detail['_ys_payment_effects'][ $receipt ] : []; }
	}
	final class YSPaymentDetailStore {
		public static array $detail = [ '_ys_payment_effects' => [ 'receipt-42' => [ 'target' => 'processing', 'effects' => [] ] ] ];
		public static function read( int $order_id ): ?array { unset( $order_id ); return self::$detail; }
	}
	if ( 'no_class' !== \V065_FLAVOR ) {
		/** Core 首購卡片權威替身：$result＝true／false／'throw'。 */
		final class YSInitialSubscriptionCardBinding {
			public static bool|string $result = true;
			public static array $calls = [];
			public static function enroll( object $order, string $receipt_id, int $card_id, ?object $expected_db = null, ?array $expected_session = null ): bool {
				self::$calls[] = [
					'order_id' => (int) ( $order->id ?? 0 ), 'gateway_id' => (string) ( $order->gateway_id ?? '' ),
					'customer_id' => (int) ( $order->customer_id ?? 0 ), 'user_id' => (int) ( $order->user_id ?? -1 ),
					'receipt' => $receipt_id, 'card_id' => $card_id, 'db' => $expected_db, 'session' => $expected_session, 'argc' => func_num_args(),
				];
				if ( 'throw' === self::$result ) {
					throw new \RuntimeException( 'fixture enroll exploded' );
				}
				return true === self::$result;
			}
		}
	}
}

namespace YangSheep\Ecommerce\Utils {
	final class YSLogger {
		public static array $entries = [];
		public static function error( string $c, string $m, array $ctx = [] ): void { unset( $c ); self::$entries[] = [ 'error', $m, $ctx['reason'] ?? null ]; }
		public static function warning( string $c, string $m, array $ctx = [] ): void { unset( $c ); self::$entries[] = [ 'warning', $m, $ctx['reason'] ?? null ]; }
		public static function info( string $c, string $m, array $ctx = [] ): void { unset( $c ); self::$entries[] = [ 'info', $m, $ctx['reason'] ?? null ]; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static function payment_credentials(): array { return [ 'test_mode' => true, 'merchant_id' => '3002607', 'hash_key' => 'k', 'hash_iv' => 'i' ]; }
	}
	final class OrderPaymentDetail {
		public static array $details = [];
		public static function read( int $order_id ): ?array { return self::$details[ $order_id ] ?? []; }
	}
}

namespace YangSheep\YSCartEcpay\Payment {
	final class EcpayPaymentAttempt {
		public const ACTION_SUCCESS = 'success';
		public const ACTION_FAILURE = 'failure';
		public static function identity_is_discoverable( array $detail, string $mtn ): bool { return hash_equals( (string) ( $detail['ecpay_merchant_trade_no'] ?? '' ), $mtn ); }
		public static function historical_callback_matches( ...$args ): bool { unset( $args ); return false; }
		public static function callback_claim( object $order, array $detail, string $mtn, int $amount, string $merchant_id, string $environment = '', string $action = self::ACTION_SUCCESS ): ?array {
			unset( $order, $environment );
			return hash_equals( (string) ( $detail['ecpay_merchant_trade_no'] ?? '' ), $mtn ) && 1290 === $amount
				? [ 'gateway_id' => 'ys_ec_ecpay_ecpg_credit', 'merchant_trade_no' => $mtn, 'charged_amount' => $amount, 'merchant_id' => $merchant_id, 'action' => $action ]
				: null;
		}
		public static function callback_guard( array $claim ): callable { unset( $claim ); return static fn (): bool => true; }
		public static function callback_detail_patch( array $claim, array $fields = [] ): callable { unset( $claim ); return static fn ( array $d ): array => array_merge( $d, $fields ); }
		public static function callback_lifecycle_options( array $claim, callable $patch, array $columns = [] ): array {
			$columns['gateway_id'] = (string) ( $claim['gateway_id'] ?? '' );
			return [ 'detail_patch' => $patch, 'columns' => $columns ];
		}
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

	use YangSheep\Ecommerce\Models\YSCreditCard;
	use YangSheep\Ecommerce\Models\YSOrder;
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentEffects;
	use YangSheep\Ecommerce\Utils\YSLogger;
	use YangSheep\YSCartEcpay\Ecpg\EcpgInitialCardBinding;
	use YangSheep\YSCartEcpay\Ecpg\EcpgSettlement;
	use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;

	$ENROLL = 'YangSheep\\Ecommerce\\Services\\Payment\\YSInitialSubscriptionCardBinding';
	$MTN    = 'YSABCDEF1234567890';
	$GW     = 'ys_ec_ecpay_ecpg_credit';
	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}" . ( null === $detail ? '' : ' -- ' . json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) . "\n";
	};

	/** @param string $type ''（首購）或 subscription_renewal／subscription_custom（後續付款）。 */
	$reset = static function ( string $type = '' ) use ( $MTN, $ENROLL ): object {
		$detail = [ 'mer_trade_no' => $MTN, 'ecpay_merchant_trade_no' => $MTN, 'ecpay_charged_amount' => 1290 ];
		if ( '' !== $type ) {
			$detail['type'] = $type;
		}
		// 付款前的訂單 gateway 欄位還是空的（付款 CAS 才寫定）。
		$order = (object) [ 'id' => 42, 'status' => 'pending', 'customer_id' => 7, 'user_id' => 70, 'gateway_id' => '', 'payment_detail' => json_encode( $detail ) ];
		YSOrder::$orders = [ 42 => $order ];
		YSOrder::$trace  = [];
		OrderPaymentDetail::$details = [ 42 => $detail ];
		YSPaymentEffects::$effects = [];
		YSPaymentEffects::$idle    = null;
		YSPaymentDetailStore::$detail = [ '_ys_payment_effects' => [ 'receipt-42' => [ 'target' => 'processing', 'effects' => [] ] ] ];
		YSCreditCard::$create_result = 77;
		YSSubscription::$bind_result = false;
		YSSubscription::$bind_calls  = [];
		if ( property_exists( YSSubscription::class, 'throw' ) ) {
			YSSubscription::$throw = false;
		}
		if ( class_exists( $ENROLL, false ) ) {
			$ENROLL::$result = true;
			$ENROLL::$calls  = [];
		}
		YSLogger::$entries = [];
		return clone $order;
	};
	$data = [
		'RtnCode' => 1, 'RtnMsg' => 'Success', 'MerchantID' => '3002607', 'MerchantMemberID' => 'YSC7', 'BindCardID' => 'bindcard0123456789abcdef',
		'CardInfo'  => [ 'Card6No' => '431195', 'Card4No' => '2222', 'CardValidYY' => '28', 'CardValidMM' => '12', 'AuthCode' => '777777', 'Amount' => 1290 ],
		'OrderInfo' => [ 'MerchantTradeNo' => $MTN, 'TradeNo' => '2609231200001234', 'TradeAmt' => 1290, 'PaymentType' => 'Credit', 'TradeStatus' => '1' ],
	];
	$errors_of = static fn (): array => array_values( array_map( static fn ( array $e ): string => $e[1], array_filter( YSLogger::$entries, static fn ( array $e ): bool => 'error' === $e[0] ) ) );
	$legacy_args = [ 42, 7, 70, $GW, 77 ];

	if ( 'enroll' === V065_FLAVOR ) {
		// (a) Core 收下權威、訂閱尚未建立（帶收據的綁卡回 false）→ pending 成功。
		$order = $reset();
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$call = $ENROLL::$calls[0] ?? [];
		$assert( 'paid' === $r['status'] && 'done' === $r['vault'] && false === $r['retryable'], '(a1) enroll accepted + subscription not materialised → vault done, no ECPay retry (0.5.11 returned vault failed)', $r );
		$assert( 1 === count( $ENROLL::$calls ) && 42 === ( $call['order_id'] ?? 0 ) && 'receipt-42' === ( $call['receipt'] ?? '' ) && 77 === ( $call['card_id'] ?? 0 ), '(a2) enroll gets the paid receipt and the exact vaulted card', $call );
		$assert( $GW === ( $call['gateway_id'] ?? '' ) && 7 === ( $call['customer_id'] ?? 0 ) && 70 === ( $call['user_id'] ?? -1 ) && [ 'forget:42', 'find:42' ] === YSOrder::$trace, '(a3) enroll sees the order re-read AFTER mark_paid (forget＋find; gateway written by the payment CAS)', [ 'call' => $call, 'trace' => YSOrder::$trace ] );
		$assert( [ [ 42, 7, 70, $GW, 77, 'receipt-42', null, null ] ] === YSSubscription::$bind_calls, '(a4) binding carries the receipt (Core recover path), never the legacy 5-argument call', YSSubscription::$bind_calls );
		$assert( [] === $errors_of(), '(a5) no "要求重送" error is logged for a pending-by-design binding', YSLogger::$entries );
		$assert( 'done' === ( YSPaymentEffects::$effects['ecpg_card_vault']['state'] ?? '' ), '(a6) the provider-only ecpg_card_vault effect is completed on the receipt' );
		$r2 = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'done' === $r2['vault'] && 1 === count( $ENROLL::$calls ) && 1 === count( YSSubscription::$bind_calls ), '(a7) a replayed callback does not enrol or bind again (effect already done)' );

		// (a) 帶收據綁卡成功。
		$order = $reset();
		YSSubscription::$bind_result = true;
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_result' );
		$assert( 'done' === $r['vault'] && 1 === count( YSSubscription::$bind_calls ) && 'receipt-42' === ( YSSubscription::$bind_calls[0][5] ?? null ), '(a8) enroll accepted + materialised subscription → bound with the receipt, vault done' );

		// (a) 帶收據綁卡拋例外：權威已持久，仍不要求重送。
		$order = $reset();
		YSSubscription::$throw = true;
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'done' === $r['vault'] && in_array( 'ECPG 首購訂閱帶收據綁卡發生例外，已交由 Core 以收據上的權威續作', $errors_of(), true ), '(a9) a throwing receipt bind after a durable enrol still completes the vault effect and logs the handover', [ 'vault' => $r['vault'], 'errors' => $errors_of() ] );

		// (a) idle 模式：Core 交來的連線與 session fence 原樣轉給 enroll 與帶收據的綁卡。
		$order = $reset();
		$idle_db = new \stdClass();
		$idle_fence = [ 'connection_id' => '101', 'database' => 'fixture', 'table_prefix' => 'wp_' ];
		YSPaymentEffects::$idle = [ $idle_db, $idle_fence ];
		EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$call = $ENROLL::$calls[0] ?? [];
		$assert( $idle_db === ( $call['db'] ?? null ) && $idle_fence === ( $call['session'] ?? null ) && 5 === ( $call['argc'] ?? 0 )
			&& $idle_db === ( YSSubscription::$bind_calls[0][6] ?? null ) && $idle_fence === ( YSSubscription::$bind_calls[0][7] ?? null ),
			'(a10) idle-mode effect custody (db＋fence) is passed through to enroll and to the receipt bind' );

		// (b) Core 拒絕權威 → 0.5.11：不帶收據綁卡，綁不到＝要求重送。
		$order = $reset();
		$ENROLL::$result = false;
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'paid' === $r['status'] && 'failed' === $r['vault'] && 'failed' === ( YSPaymentEffects::$effects['ecpg_card_vault']['state'] ?? '' ), '(b1) enroll refused + subscription not materialised → vault failed (ECPay must resend), exactly as 0.5.11', $r );
		$assert( [ $legacy_args ] === YSSubscription::$bind_calls, '(b2) the refused path uses only the legacy 5-argument bind (no receipt)', YSSubscription::$bind_calls );
		$assert( in_array( 'ECPG 初始訂閱卡片綁定尚未完成，要求重送', $errors_of(), true ), '(b3) the refused path keeps the 0.5.11 "要求重送" error', $errors_of() );
		$order = $reset();
		$ENROLL::$result = false;
		YSSubscription::$bind_result = true;
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'done' === $r['vault'] && [ $legacy_args ] === YSSubscription::$bind_calls, '(b4) enroll refused + materialised subscription → legacy bind succeeds, vault done' );

		// (b) enroll 拋例外 → 當作拒絕（無法證明權威已持久）。
		$order = $reset();
		$ENROLL::$result = 'throw';
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'failed' === $r['vault'] && [ $legacy_args ] === YSSubscription::$bind_calls && in_array( 'ECPG 首購訂閱卡片權威補登發生例外，改用舊版綁卡流程', $errors_of(), true ), '(b5) a throwing enroll falls back to the legacy bind and never reports pending success', [ 'vault' => $r['vault'], 'binds' => YSSubscription::$bind_calls, 'errors' => $errors_of() ] );

		// 後續付款（續約／自訂費用）：只存卡，不 enroll、不綁。
		foreach ( [ 'subscription_renewal', 'subscription_custom' ] as $type ) {
			$order = $reset( $type );
			$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
			$assert( 'done' === $r['vault'] && [] === $ENROLL::$calls && [] === YSSubscription::$bind_calls, "(f) {$type} follow-up payment vaults the card without enrolling or binding the initial mandate" );
		}

		// 直接呼叫 attach()：半套 custody（只有 db 或只有 fence）一律不寫權威。
		$reset();
		YSOrder::$orders[42]->gateway_id = $GW;
		$half = EcpgInitialCardBinding::attach( 42, 7, 70, 77, 'receipt-42', new \stdClass(), null );
		$assert( false === $half && [] === $ENROLL::$calls && [ $legacy_args ] === YSSubscription::$bind_calls, '(g) half custody (db without fence) never enrols; it falls back to the legacy bind' );
	}

	if ( 'no_class' === V065_FLAVOR ) {
		// (c) Core 沒有首購卡片權威 API。
		$order = $reset();
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( ! class_exists( $ENROLL, false ), '(c0) this flavor really has no YSInitialSubscriptionCardBinding' );
		$assert( 'failed' === $r['vault'] && [ $legacy_args ] === YSSubscription::$bind_calls && [] === YSOrder::$trace, '(c1) no enroll API → 0.5.11 path: legacy bind, no re-read, vault failed while unmaterialised', [ 'vault' => $r['vault'], 'binds' => YSSubscription::$bind_calls, 'trace' => YSOrder::$trace ] );
		$order = $reset();
		YSSubscription::$bind_result = true;
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( 'done' === $r['vault'] && [ $legacy_args ] === YSSubscription::$bind_calls, '(c2) no enroll API + materialised subscription → legacy bind succeeds' );
	}

	if ( 'short_bind' === V065_FLAVOR ) {
		// (c') enroll 在，但帶收據的綁卡不在：寫了權威也沒有人接手，所以根本不 enroll。
		$order = $reset();
		$r = EcpgSettlement::apply( $order, $data, 'ecpg_return' );
		$assert( [] === $ENROLL::$calls && [ $legacy_args ] === YSSubscription::$bind_calls && 'failed' === $r['vault'], '(c3) enroll present but the bind has no receipt parameter → enroll is never called; legacy path', [ 'enroll' => $ENROLL::$calls, 'binds' => YSSubscription::$bind_calls, 'vault' => $r['vault'] ] );
	}

	echo "\nflavor " . V065_FLAVOR . ": {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 || 0 === $pass ? 1 : 0 );
}
