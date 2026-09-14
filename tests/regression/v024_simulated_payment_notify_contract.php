<?php
/**
 * v024 — A signed ECPay simulation callback must never mark an order paid.
 *
 * ECPay sends RtnCode=1 for both real payment success and backend simulation.
 * SimulatePaid=1 is not a funded payment, so acknowledging that callback must
 * not persist the real-paid identity or invoke the paid lifecycle transition.
 *
 * Run: php tests/regression/v024_simulated_payment_notify_contract.php
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

	function sanitize_text_field($value): string
	{
		return trim(strip_tags((string) $value));
	}

	function wp_unslash($value)
	{
		return is_string($value) ? stripslashes($value) : $value;
	}

	function wp_json_encode($data)
	{
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	function status_header(int $status): void
	{
		$GLOBALS['ys_ecpay_http_status'] = $status;
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
				'gateway_id'     => 'ys_ec_ecpay_credit',
				'payment_method' => 'ys_ec_ecpay_credit',
				'payment_detail' => json_encode([
					'mer_trade_no'       => 'YS7TLOCAL',
					'ecpay_charged_amount' => 100,
				]),
			];
		}
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	final class YSPaymentLifecycleService
	{
		private static function transition(string $kind, int $order_id, ?callable $guard, array $options): array
		{
			$current = \YangSheep\YSCartEcpay\Support\OrderPaymentDetail::read($order_id);
			if (null !== $guard && ! $guard($current)) {
				return ['success' => false, 'retryable' => false, 'outcome' => 'stale'];
			}
			$patch = $options['detail_patch'] ?? null;
			$next  = is_callable($patch) ? $patch($current) : $current;
			file_put_contents(
				(string) $GLOBALS['ys_ecpay_marker_path'],
				'atomic-' . $kind . ':' . json_encode(['detail' => $next, 'columns' => $options['columns'] ?? []]) . '|',
				FILE_APPEND
			);
			if ('persist-fail' === ($GLOBALS['ys_ecpay_scenario'] ?? '')) {
				return ['success' => false, 'retryable' => true, 'outcome' => 'db_error'];
			}
			return ['success' => true, 'retryable' => false, 'outcome' => 'won'];
		}

		public static function mark_paid(int $order_id, object $detail, string $source, ?callable $guard = null, array $options = []): array
		{
			unset($detail, $source);
			return self::transition('paid', $order_id, $guard, $options);
		}

		public static function mark_failed(int $order_id, object $detail, string $source, string $target = 'failed', ?callable $guard = null, array $options = []): array
		{
			unset($detail, $source, $target);
			return self::transition('failed', $order_id, $guard, $options);
		}

		public static function mark_pending_offline(int $order_id, object $detail, string $source, ?callable $guard = null, array $options = []): array
		{
			unset($detail, $source);
			return self::transition('pending-offline', $order_id, $guard, $options);
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
			unset($detail);
			return '' !== $merchant_trade_no && $amount > 0
				? ['gateway_id' => (string) ($order->gateway_id ?? ''), 'merchant_trade_no' => $merchant_trade_no, 'charged_amount' => $amount, 'merchant_id' => $merchant_id, 'environment' => $environment, 'action' => $action, 'scalar_gateway_bound' => true]
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
			unset($channel, $message, $context);
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
		public function __construct(private bool $persisted = true) {}

		public function is_persisted(): bool { return $this->persisted; }
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
		public static function reader_lease(): object
		{
			return (object) ['token' => 'local-reader'];
		}

		public static function reader_fence(string $token): bool
		{
			return 'local-reader' === $token;
		}
	}

	final class OrderPaymentDetail
	{
		/** @return array<string,mixed> */
		public static function read(int $order_id): array
		{
			unset($order_id);
			return [
				'mer_trade_no'           => 'YS7TLOCAL',
				'ecpay_charged_amount'    => 100,
				'payment_provider'        => 'ecpay',
				'payment_method'          => 'ys_ec_ecpay_credit',
				'ecpay_merchant_id'       => 'LOCAL-MERCHANT',
				'ecpay_environment'       => 'stage',
			];
		}

		public static function mutate(int $order_id, callable $callback): FakePersistedWrite
		{
			unset($order_id);
			file_put_contents((string) $GLOBALS['ys_ecpay_marker_path'], 'detail-write|', FILE_APPEND);
			$callback([]);
			return new FakePersistedWrite('persist-fail' !== ($GLOBALS['ys_ecpay_scenario'] ?? ''));
		}
	}

	final class ScalarColumnWriter
	{
		public static function required_string(string $value): ?string
		{
			return '' === $value ? null : $value;
		}

		/** @param array<string,string> $columns */
		public static function write(int $order_id, array $columns): array
		{
			unset($order_id, $columns);
			file_put_contents((string) $GLOBALS['ys_ecpay_marker_path'], 'scalar-write|', FILE_APPEND);
			return ['state' => 'persisted'];
		}

		/** @param array<string,string> $result */
		public static function is_persisted(array $result): bool
		{
			return 'persisted' === ($result['state'] ?? '');
		}
	}
}

namespace {
	use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
	use YangSheep\YSCartEcpay\Support\CheckMacValue;

	$root = dirname(__DIR__, 2);
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Api/EcpayPaymentController.php';

	if ('--child' === ($argv[1] ?? '')) {
		$scenario = (string) ($argv[2] ?? '');
		$GLOBALS['ys_ecpay_scenario'] = $scenario;
		$GLOBALS['ys_ecpay_marker_path'] = (string) ($argv[3] ?? '');

		$params = [
			'MerchantID'      => 'LOCAL-MERCHANT',
			'MerchantTradeNo' => 'YS7TLOCAL',
			'TradeAmt'        => '100',
			'RtnCode'         => 'notify-failed' === $scenario ? '0' : ('payment-info' === $scenario ? '2' : '1'),
			'RtnMsg'          => 'signed-raw-bytes' === $scenario ? '  Path C:\\Temp %2F  ' : 'Succeeded',
			'TradeNo'         => 'LOCAL-TRADE-1',
			'PaymentType'     => 'Credit_CreditCard',
			'SimulatePaid'    => 'simulated' === $scenario ? '1' : '0',
		];
		$params['CheckMacValue'] = CheckMacValue::generate(
			$params,
			'local-hash-key',
			'local-hash-iv',
			'sha256'
		);
		if ('invalid-cmv' === $scenario) {
			$params['CheckMacValue'] = 'INVALID';
		}

		$controller = new EcpayPaymentController();
		if ('payment-info' === $scenario) {
			$controller->payment_info(new WP_REST_Request($params));
		} else {
			$controller->notify(new WP_REST_Request($params));
		}
		exit(97);
	}

	$pass = 0;
	$fail = 0;
	$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
		if ($ok) {
			++$pass;
			echo "  PASS  {$label}\n";
			return;
		}

		++$fail;
		echo "  FAIL  {$label}\n";
	};

	$run = static function (string $scenario): array {
		$marker = tempnam(sys_get_temp_dir(), 'ys-ecpay-notify-');
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
		$exit   = proc_close($process);
		$event  = trim((string) file_get_contents($marker));
		@unlink($marker);

		return compact('stdout', 'stderr', 'exit', 'event');
	};

	$simulated        = $run('simulated');
	$simulated_replay = $run('simulated');
	$real             = $run('real');
	$signed_raw_bytes = $run('signed-raw-bytes');
	$invalid_cmv      = $run('invalid-cmv');
	$persist_fail     = $run('persist-fail');
	$notify_failed    = $run('notify-failed');
	$payment_info     = $run('payment-info');

	$assert(
		'' === $simulated['event'],
		'SimulatePaid=1 performs zero payment-detail writes, scalar writes, or lifecycle transitions'
	);
	$assert(
		'1|OK' === $simulated['stdout'] && '' === $simulated['stderr'] && 0 === $simulated['exit'],
		'SimulatePaid=1 returns the byte-exact successful callback ACK'
	);
	$assert(
		'' === $simulated_replay['event']
		&& '1|OK' === $simulated_replay['stdout']
		&& '' === $simulated_replay['stderr']
		&& 0 === $simulated_replay['exit'],
		'Replayed SimulatePaid=1 remains an ACK-only no-op'
	);
	$assert(
		str_contains($real['event'], 'atomic-paid:')
		&& str_contains($real['event'], 'LOCAL-TRADE-1')
		&& ! str_contains($real['event'], 'detail-write|')
		&& ! str_contains($real['event'], 'scalar-write|'),
		'RtnCode=1 with SimulatePaid=0 writes callback detail, scalar TradeNo, and paid lifecycle in one CAS'
	);
	$assert(
		'1|OK' === $real['stdout'] && '' === $real['stderr'] && 0 === $real['exit'],
		'Real paid callback returns the byte-exact successful callback ACK'
	);
	$assert(
		str_contains($signed_raw_bytes['event'], 'atomic-paid:')
		&& '1|OK' === $signed_raw_bytes['stdout']
		&& '' === $signed_raw_bytes['stderr']
		&& 0 === $signed_raw_bytes['exit'],
		'CMV verification uses already-unslashed body bytes before domain sanitization'
	);
	$assert(
		'' === $invalid_cmv['event']
		&& '0|Invalid CheckMacValue' === $invalid_cmv['stdout']
		&& '' === $invalid_cmv['stderr']
		&& 0 === $invalid_cmv['exit'],
		'Invalid CheckMacValue is rejected before any lifecycle mutation'
	);
	$assert(
		str_contains($persist_fail['event'], 'atomic-paid:')
		&& '0|Persist Failed' === $persist_fail['stdout']
		&& '' === $persist_fail['stderr']
		&& 0 === $persist_fail['exit'],
		'Real payment persistence failure is non-ACK and never advances lifecycle'
	);
	$assert(
		str_contains($notify_failed['event'], 'atomic-failed:')
		&& '1|OK' === $notify_failed['stdout']
		&& '' === $notify_failed['stderr']
		&& 0 === $notify_failed['exit'],
		'Non-success payment result transitions to failed and ACKs exactly once'
	);
	$assert(
		str_contains($payment_info['event'], 'atomic-pending-offline:')
		&& '1|OK' === $payment_info['stdout']
		&& '' === $payment_info['stderr']
		&& 0 === $payment_info['exit'],
		'ATM payment-info result transitions to pending-offline and ACKs exactly once'
	);

	echo "\nsimulated payment notify: {$pass} PASS / {$fail} FAIL\n";
	exit($fail > 0 ? 1 : 0);
}
