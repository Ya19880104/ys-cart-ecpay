<?php
/**
 * v054 — 分期落回一次付清必須被偵測（v0.4.0）
 *
 * 綠界官方在「信用卡分期付款」明載：
 *
 *   「若廠商未開通刷卡分期期數時，交易會自動改為信用卡一次付清。」
 *
 * 這是一個**看起來完全成功的失敗**：`RtnCode=1`、`TradeAmt` 與我們送出的金額
 * 相符、訂單正常轉為已付款。消費者選的是分期、實際被一次扣完，而系統裡沒有
 * 任何一處會察覺——金額核對比的是我們自己送出的金額，那個金額本來就沒變。
 *
 * 唯一的證據是綠界隨 `NeedExtraPaidInfo=Y` 回傳的 `stage`（實際期數）。本檔驗證：
 * 分期方式付款卻收到 `stage < 2` 時，`payment_detail` 留下明確旗標、寫一筆
 * error log，且**仍然 ACK**（款項確實收到，拒絕 ACK 只會讓綠界無止盡重送）。
 *
 * 同時驗證不得誤判：期數正常、非分期方式、以及綠界根本沒送 `stage` 這三種情形
 * 都不可以留下旗標——「沒有這個欄位」不是「明確為 0」。
 *
 * Run: php tests/regression/v054_installment_fallback_detection.php
 */

declare(strict_types=1);

namespace {
	if (! defined('ABSPATH')) {
		define('ABSPATH', __DIR__);
	}
	if (! defined('YS_ECOMMERCE_TABLE_PREFIX')) {
		define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
	}

	final class WP_REST_Request
	{
		/** @param array<string,string> $params */
		public function __construct(private array $params) {}

		/** @return array<string,string> */
		public function get_params(): array { return $this->params; }
		/** @return array<string,string> */
		public function get_body_params(): array { return $this->params; }
	}

	function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
	function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
	function wp_json_encode($data) { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
	function status_header(int $status): void { $GLOBALS['ys_ecpay_http_status'] = $status; }

	/** 子行程把觀測到的事實一行一筆寫進 marker，父行程再解析。 */
	function ys_v054_record(string $line): void
	{
		file_put_contents((string) $GLOBALS['ys_ecpay_marker_path'], $line . "\n", FILE_APPEND);
	}
}

namespace YangSheep\Ecommerce\DTOs {
	final class YSPaymentDetailDTO
	{
		/** @param array<string,mixed> $detail */
		public static function from_legacy_array(array $detail, string $fallback): self
		{
			unset($detail, $fallback);
			return new self();
		}
	}
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder
	{
		public static function forget(int $id): void { unset($id); }
		public static function find(int $id): ?object
		{
			if (7 !== $id) {
				return null;
			}

			return (object) [
				'id'             => 7,
				'total'          => 100.0,
				'gateway_id'     => (string) ($GLOBALS['ys_ecpay_paid_method'] ?? ''),
				'payment_method' => (string) ($GLOBALS['ys_ecpay_paid_method'] ?? ''),
				'payment_detail' => json_encode(['mer_trade_no' => 'YS7TLOCAL', 'ecpay_charged_amount' => 100]),
			];
		}
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	final class YSPaymentLifecycleService
	{
		public static function mark_paid(int $order_id, object $detail, string $source, ?callable $guard = null, array $options = []): array
		{
			unset($detail, $source);
			$current = \YangSheep\YSCartEcpay\Support\OrderPaymentDetail::read($order_id);
			if (null !== $guard && ! $guard($current)) {
				return ['success' => false, 'retryable' => false, 'outcome' => 'stale'];
			}
			$patch = $options['detail_patch'] ?? null;
			$written = is_callable($patch) ? $patch($current) : $current;
			\ys_v054_record('detail:' . json_encode($written, JSON_UNESCAPED_UNICODE));
			\ys_v054_record('paid');
			return ['success' => true, 'retryable' => false, 'outcome' => 'won'];
		}

		public static function mark_failed(int $order_id, object $detail, string $source, string $target = 'failed', ?callable $guard = null, array $options = []): array
		{
			unset($order_id, $detail, $source, $target, $guard, $options);
			return ['success' => true];
		}

		public static function mark_pending_offline(int $order_id, object $detail, string $source, ?callable $guard = null, array $options = []): array
		{
			unset($order_id, $detail, $source, $guard, $options);
			return ['success' => true];
		}
	}
}

namespace YangSheep\YSCartEcpay\Payment {
	final class EcpayPaymentAttempt
	{
		public const ACTION_SUCCESS = 'success';
		public const ACTION_FAILURE = 'failure';
		public const ACTION_PAYMENT_INFO = 'payment_info';
		public static function identity_is_discoverable(array $detail, string $mtn): bool
		{
			return ($detail['mer_trade_no'] ?? $detail['ecpay_merchant_trade_no'] ?? '') === $mtn;
		}
		public static function historical_callback_matches(object $order, array $detail, string $mtn, int $amount, string $merchant_id, string $environment): bool
		{
			unset($order, $detail, $mtn, $amount, $merchant_id, $environment);
			return false;
		}
		public static function callback_claim(object $order, array $detail, string $merchant_trade_no, int $amount, string $merchant_id, string $environment = '', string $action = self::ACTION_SUCCESS): ?array
		{
			return '' !== $merchant_trade_no && $amount > 0
				? [
					'gateway_id' => (string) ($order->gateway_id ?? ''),
					'merchant_trade_no' => $merchant_trade_no,
					'charged_amount' => $amount,
					'merchant_id' => $merchant_id,
					'environment' => $environment,
					'action' => $action,
					'scalar_gateway_bound' => true,
				]
				: null;
		}
		public static function callback_guard(array $claim): callable
		{
			return static fn(array $detail): bool => ($detail['mer_trade_no'] ?? '') === ($claim['merchant_trade_no'] ?? '');
		}
		public static function callback_detail_patch(array $claim, array $fields = []): callable
		{
			unset($claim);
			return static fn(array $detail): array => array_merge($detail, $fields);
		}
		public static function callback_lifecycle_options(array $claim, callable $patch, array $columns = []): array
		{
			$columns['gateway_id'] = (string) ($claim['gateway_id'] ?? '');
			return ['detail_patch' => $patch, 'columns' => $columns, 'expected_column_values' => ['gateway_id' => $columns['gateway_id']]];
		}
	}
}

namespace YangSheep\Ecommerce\Utils {
	final class YSLogger
	{
		public static function error(string $channel, string $message, array $context = []): void
		{
			unset($channel);
			\ys_v054_record('error:' . $message . ':' . json_encode($context, JSON_UNESCAPED_UNICODE));
		}

		public static function warning(string $channel, string $message, array $context = []): void
		{
			unset($channel, $message, $context);
		}
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class FakePersistedWrite
	{
		public function is_persisted(): bool { return true; }
		public function to_log_context(): array { return []; }
	}

	final class Settings
	{
		/** @return array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} */
		public static function payment_credentials(): array
		{
			return [
				'merchant_id' => 'LOCAL-MERCHANT',
				'hash_key'    => 'local-hash-key',
				'hash_iv'     => 'local-hash-iv',
				'test_mode'   => true,
			];
		}
	}

	final class ProviderMaintenanceLock
	{
		public static function reader_lease(): object { return (object) ['token' => 'local-reader']; }
		public static function reader_fence(string $token): bool { return 'local-reader' === $token; }
	}

	final class OrderPaymentDetail
	{
		/** @return array<string,mixed> */
		public static function read(int $order_id): array
		{
			unset($order_id);

			return [
				'mer_trade_no'        => 'YS7TLOCAL',
				'ecpay_charged_amount' => 100,
				'payment_provider'     => 'ecpay',
				// 建單時由 EcpayGatewayBase 寫入——這是「消費者選的是哪個方式」的權威。
				'payment_method'       => (string) ($GLOBALS['ys_ecpay_paid_method'] ?? ''),
				'ecpay_merchant_id'    => 'LOCAL-MERCHANT',
				'ecpay_environment'    => 'stage',
			];
		}

		public static function mutate(int $order_id, callable $callback): FakePersistedWrite
		{
			unset($order_id);
			$written = $callback([]);
			\ys_v054_record('detail:' . json_encode($written, JSON_UNESCAPED_UNICODE));
			return new FakePersistedWrite();
		}
	}

	final class ScalarColumnWriter
	{
		public static function required_string(string $value): ?string { return '' === $value ? null : $value; }

		/** @param array<string,string> $columns */
		public static function write(int $order_id, array $columns): array
		{
			unset($order_id, $columns);
			return ['state' => 'persisted'];
		}

		/** @param array<string,string> $result */
		public static function is_persisted(array $result): bool { return 'persisted' === ($result['state'] ?? ''); }
	}
}

namespace {
	use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
	use YangSheep\YSCartEcpay\Support\CheckMacValue;

	$root = dirname(__DIR__, 2);
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Api/EcpayPaymentController.php';

	/**
	 * 情境定義：付款方式 → 綠界回報的 stage（null＝綠界根本沒送這個欄位）。
	 *
	 * @var array<string,array{method:string,stage:string|null}>
	 */
	$scenarios = [
		'installment-fell-back'   => ['method' => 'ys_ec_ecpay_credit_installment', 'stage' => '0'],
		'installment-single-only' => ['method' => 'ys_ec_ecpay_credit_installment', 'stage' => '1'],
		'installment-honoured'    => ['method' => 'ys_ec_ecpay_credit_installment', 'stage' => '6'],
		'installment-no-evidence' => ['method' => 'ys_ec_ecpay_credit_installment', 'stage' => null],
		'plain-credit'            => ['method' => 'ys_ec_ecpay_credit', 'stage' => '0'],
	];

	if ('--child' === ($argv[1] ?? '')) {
		$scenario = (string) ($argv[2] ?? '');
		$GLOBALS['ys_ecpay_marker_path'] = (string) ($argv[3] ?? '');
		$GLOBALS['ys_ecpay_paid_method'] = $scenarios[$scenario]['method'];

		$params = [
			'MerchantID'      => 'LOCAL-MERCHANT',
			'MerchantTradeNo' => 'YS7TLOCAL',
			'TradeAmt'        => '100',
			'RtnCode'         => '1',
			'RtnMsg'          => 'Succeeded',
			'TradeNo'         => 'LOCAL-TRADE-1',
			'PaymentType'     => 'Credit_CreditCard',
			'SimulatePaid'    => '0',
			'gwsr'            => '1234567',
		];
		if (null !== $scenarios[$scenario]['stage']) {
			$params['stage'] = $scenarios[$scenario]['stage'];
		}
		$params['CheckMacValue'] = CheckMacValue::generate($params, 'local-hash-key', 'local-hash-iv', 'sha256');

		(new EcpayPaymentController())->notify(new WP_REST_Request($params));
		exit(97);
	}

	$pass = 0;
	$fail = 0;
	$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
		if ($ok) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}\n";
	};

	$run = static function (string $scenario): array {
		$marker = tempnam(sys_get_temp_dir(), 'ys-ecpay-inst-');
		if (false === $marker) {
			throw new RuntimeException('Unable to allocate callback marker.');
		}
		file_put_contents($marker, '');

		$process = proc_open(
			[PHP_BINARY, __FILE__, '--child', $scenario, $marker],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		if (! is_resource($process)) {
			@unlink($marker);
			throw new RuntimeException('Unable to start callback child process.');
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit  = proc_close($process);
		$lines = array_values(array_filter(explode("\n", (string) file_get_contents($marker))));
		@unlink($marker);

		$detail = [];
		$errors = [];
		foreach ($lines as $line) {
			if (str_starts_with($line, 'detail:')) {
				$detail = json_decode(substr($line, 7), true) ?: [];
			} elseif (str_starts_with($line, 'error:')) {
				$errors[] = substr($line, 6);
			}
		}

		return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'detail' => $detail, 'errors' => $errors, 'lines' => $lines];
	};

	echo "## v054 分期落回一次付清的偵測\n";

	$results = [];
	foreach (array_keys($scenarios) as $scenario) {
		$results[$scenario] = $run($scenario);
	}

	// ── A. 落回一次付清：留旗標、寫 error、仍然 ACK ──────────────────────────
	$fellBack = $results['installment-fell-back'];
	$assert(
		'1' === (string) ($fellBack['detail']['ecpay_installment_fallback'] ?? ''),
		'A1 stage=0 的分期交易在 payment_detail 留下 ecpay_installment_fallback'
	);
	$assert(
		1 === count(array_filter($fellBack['errors'], static fn(string $e): bool => str_contains($e, '一次付清'))),
		'A2 落回一次付清寫且只寫一筆 error log'
	);
	$assert(
		str_contains(implode('', $fellBack['errors']), '"reported_stage":0')
			&& str_contains(implode('', $fellBack['errors']), 'YS7TLOCAL'),
		'A3 log 帶著實際期數與交易編號（業主查得到是哪一筆）'
	);
	$assert(
		'1|OK' === $fellBack['stdout'] && '' === $fellBack['stderr'] && 0 === $fellBack['exit'],
		'A4 仍然 ACK——款項已收到，拒絕 ACK 只會讓綠界無止盡重送'
	);
	$assert(
		in_array('paid', $fellBack['lines'], true),
		'A5 訂單仍照常轉為已付款（這是稽核訊號，不是付款失敗）'
	);
	$assert(
		'0' === (string) ($fellBack['detail']['ecpay_stage'] ?? ''),
		'A6 綠界回報的實際期數本身也留存（旗標與證據並存）'
	);

	// ── B. 1 期不是分期 ─────────────────────────────────────────────────────
	$assert(
		'1' === (string) ($results['installment-single-only']['detail']['ecpay_installment_fallback'] ?? ''),
		'B1 stage=1 同樣算落回一次付清（1 期不是分期）'
	);

	// ── C. 不得誤判 ─────────────────────────────────────────────────────────
	$honoured = $results['installment-honoured'];
	$assert(
		! array_key_exists('ecpay_installment_fallback', $honoured['detail'])
			&& [] === $honoured['errors']
			&& '6' === (string) ($honoured['detail']['ecpay_stage'] ?? ''),
		'C1 期數正常（stage=6）不留旗標也不寫 error'
	);

	$noEvidence = $results['installment-no-evidence'];
	$assert(
		! array_key_exists('ecpay_installment_fallback', $noEvidence['detail'])
			&& ! array_key_exists('ecpay_stage', $noEvidence['detail'])
			&& [] === $noEvidence['errors'],
		'C2 綠界沒送 stage 時不判定、也不捏造 ecpay_stage（缺證據 ≠ 證明落空）'
	);

	$plain = $results['plain-credit'];
	$assert(
		! array_key_exists('ecpay_installment_fallback', $plain['detail']) && [] === $plain['errors'],
		'C3 一般信用卡方式的 stage=0 是正常值，不得判成落空'
	);

	// ── D. 所有情境都必須 ACK 且不吐 stderr ─────────────────────────────────
	$allAcked = true;
	foreach ($results as $result) {
		$allAcked = $allAcked && '1|OK' === $result['stdout'] && '' === $result['stderr'] && 0 === $result['exit'];
	}
	$assert($allAcked, 'D1 五個情境全部乾淨 ACK');

	echo "\nRESULT: {$pass} pass / {$fail} fail\n";
	exit($fail > 0 ? 1 : 0);
}
