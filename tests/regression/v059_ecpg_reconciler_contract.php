<?php
/** ECPG QueryTrade routing, identity, amount and state convergence. */
declare(strict_types=1);

namespace YangSheep\Ecommerce\DTOs {
	final class YSPaymentDetailDTO {
		public function __construct(public array $fields) {}
		public static function from_legacy_array(array $fields, string $gateway = ''): self { return new self($fields + ['gateway' => $gateway]); }
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	interface YSPaymentReconcilerInterface { public function supports(object $order): bool; public function reconcile(object $order): YSPaymentReconcileResult; }
	final class YSPaymentReconcileResult {
		private function __construct(public string $action, public ?object $detail = null, public string $message = '', public array $raw = []) {}
		public static function unsupported(string $message = ''): self { return new self('unsupported', null, $message); }
		public static function paid(object $detail, string $message = '', array $raw = []): self { return new self('paid', $detail, $message, $raw); }
		public static function hold(string $message = '', ?object $detail = null, array $raw = []): self { return new self('hold', $detail, $message, $raw); }
		public static function error(string $message = '', ?object $detail = null, array $raw = []): self { return new self('error', $detail, $message, $raw); }
	}
}

namespace YangSheep\YSCartEcpay\Ecpg {
	final class EcpgOrderContext { public const GATEWAY_ID = 'ys_ec_ecpay_ecpg_credit'; }
	class EcpgClient {
		public const OUTCOME_SUCCESS = 'success';
		public const OUTCOME_PROVIDER_FAILED = 'provider_failed';
		public const OUTCOME_INDETERMINATE = 'indeterminate';
		public static array $next = [];
		public function query_trade(string $merchantTradeNo): array { $GLOBALS['v059_queries'][] = $merchantTradeNo; return self::$next; }
		public static function trade_status(array $data): ?string { return isset($data['OrderInfo']['TradeStatus']) ? (string) $data['OrderInfo']['TradeStatus'] : null; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings { public static function payment_credentials(): array { return ['merchant_id' => '3002607']; } }
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	$root = dirname(__DIR__, 2);
	require $root . '/src/Payment/EcpgPaymentReconciler.php';

	use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
	use YangSheep\YSCartEcpay\Payment\EcpgPaymentReconciler;

	$pass = 0; $fail = 0;
	$check = static function(string $label, bool $ok) use (&$pass, &$fail): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; $ok ? ++$pass : ++$fail; };
	$order = (object) [
		'id' => 42,
		'gateway_id' => 'ys_ec_ecpay_ecpg_credit',
		'payment_detail' => json_encode([
			'payment_provider' => 'ecpay',
			'payment_method' => 'ys_ec_ecpay_ecpg_credit',
			'ecpay_merchant_trade_no' => 'YSABC123',
			'ecpay_charged_amount' => 1290,
			'ecpay_merchant_id' => '3002607',
		]),
	];
	$data = [
		'MerchantID' => '3002607',
		'RtnCode' => 1,
		'RtnMsg' => 'Success',
		'OrderInfo' => ['MerchantTradeNo' => 'YSABC123', 'TradeNo' => '2609120001', 'TradeAmt' => 1290, 'TradeStatus' => '1'],
		'CardInfo' => ['Card4No' => '2222', 'Card6No' => '431195', 'AuthCode' => '777777'],
	];
	$reconciler = new EcpgPaymentReconciler();
	$check('Exact ECPG gateway is supported', $reconciler->supports($order));

	EcpgClient::$next = ['outcome' => 'success', 'data' => $data, 'message' => ''];
	$result = $reconciler->reconcile($order);
	$check('Paid query uses ECPG client and returns paid with exact identity and amount', $result->action === 'paid' && ($GLOBALS['v059_queries'] ?? []) === ['YSABC123'] && ($result->detail->fields['paid_amount'] ?? null) === 1290.0 && ($result->detail->fields['gateway_trade_no'] ?? null) === '2609120001');

	EcpgClient::$next = ['outcome' => 'success', 'data' => array_replace_recursive($data, ['OrderInfo' => ['TradeStatus' => '0']]), 'message' => ''];
	$check('Unpaid query remains on hold', $reconciler->reconcile($order)->action === 'hold');
	EcpgClient::$next = ['outcome' => 'success', 'data' => array_replace_recursive($data, ['OrderInfo' => ['TradeStatus' => 'future']]), 'message' => ''];
	$check('Unknown query state remains on hold', $reconciler->reconcile($order)->action === 'hold');
	EcpgClient::$next = ['outcome' => 'indeterminate', 'data' => null, 'message' => 'timeout'];
	$check('Indeterminate transport remains on hold', $reconciler->reconcile($order)->action === 'hold');
	EcpgClient::$next = ['outcome' => 'provider_failed', 'data' => null, 'message' => 'rejected'];
	$check('Rejected query request becomes an error without a payment transition', $reconciler->reconcile($order)->action === 'error');

	foreach ([
		'foreign merchant' => ['MerchantID' => '2000132'],
		'foreign trade' => ['OrderInfo' => ['MerchantTradeNo' => 'YSOTHER']],
		'amount mismatch' => ['OrderInfo' => ['TradeAmt' => 990]],
		'paid without provider trade number' => ['OrderInfo' => ['TradeNo' => '']],
	] as $label => $override) {
		EcpgClient::$next = ['outcome' => 'success', 'data' => array_replace_recursive($data, $override), 'message' => ''];
		$check(ucfirst($label) . ' is refused before marking paid', $reconciler->reconcile($order)->action === 'error');
	}

	echo "v059 ecpg reconciler PASS={$pass} FAIL={$fail}\n";
	exit($fail > 0 ? 1 : 0);
}
