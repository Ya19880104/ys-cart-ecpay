<?php
/**
 * v068: ECPay refund capability and admin refund behavior against a real Core.
 * Run: php -n tests/regression/v068_refund_record_only_core_pair.php --core=/fixed/core/export
 * Matrix: PHP 8.2/8.5 x Core 2.69.14/3.0.0. No database or provider HTTP.
 * Controller permission/nonce routing and browser UI are outside this offline test.
 */
declare(strict_types=1);

require_once __DIR__ . '/fixtures/refund_core_runtime.php';

function __(string $text, string $domain = ''): string { return $text; }
function absint($value): int { return abs((int) $value); }

// Any provider HTTP is a test failure, including accidental query-before-refund.
$GLOBALS['ecpay_http_calls'] = 0;
function wp_remote_request(...$args) {
    ++$GLOBALS['ecpay_http_calls'];
    throw new RuntimeException('Unexpected provider HTTP in record-only refund');
}
function wp_remote_post(...$args) { return wp_remote_request(...$args); }
function wp_remote_get(...$args) { return wp_remote_request(...$args); }

final class WP_REST_Request {
    public function __construct(private array $params) {}
    public function get_param(string $name) { return $this->params[$name] ?? null; }
    public function get_json_params(): array { return $this->params; }
}
final class WP_REST_Response {
    public function __construct(private array $data, private int $status = 200) {}
    public function get_data(): array { return $this->data; }
    public function get_status(): int { return $this->status; }
}

$opts = getopt('', ['core:']);
$core = realpath((string) ($opts['core'] ?? ''));
if (false === $core || !is_file($core . '/ys-cart.php')) {
    fwrite(STDERR, "A fixed Core export with ys-cart.php is required (--core=...).\n");
    exit(2);
}
$header = (string) file_get_contents($core . '/ys-cart.php');
if (!preg_match('/^ \* Version: (2\.69\.14|3\.0\.0)$/m', str_replace("\r\n", "\n", $header), $match)) {
    fwrite(STDERR, "This pair test requires Core 2.69.14 or 3.0.0.\n");
    exit(2);
}
define('YS_ECOMMERCE_VERSION', $match[1]);
$addon = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($core, $addon): void {
    foreach (['YangSheep\\Ecommerce\\' => $core, 'YangSheep\\YSCartEcpay\\' => $addon] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
            return;
        }
    }
});

use YangSheep\Ecommerce\Api\Admin\YSOrdersAdminController;
use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\Ecommerce\Handlers\YSRefundHandler;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Payment\EcpayCreditGateway;
use YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog;

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { ++$pass; } else { ++$fail; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
};
$catalog = EcpayPaymentCatalog::all();
$assert(12 === count($catalog), 'catalog has exactly 12 payment gateways');
foreach ($catalog as $id => $descriptor) {
    $gateway = new $descriptor['class']();
    $assert($id === $gateway->get_id(), 'concrete gateway identity: ' . $id);
    YSGatewayRegistry::register($gateway);
}

// Missing, explicit off and explicit on must affect only credit once-pay.
foreach ([null, '0', '1'] as $option) {
    if (null === $option) {
        unset($GLOBALS['ys_test_options'][EcpayCreditGateway::AUTO_REFUND_OPTION]);
    } else {
        $GLOBALS['ys_test_options'][EcpayCreditGateway::AUTO_REFUND_OPTION] = $option;
    }
    foreach ($catalog as $id => $descriptor) {
        $expected = 'ys_ec_ecpay_credit' === $id && '1' === $option
            ? YSGatewayRegistry::REFUND_AUTO : YSGatewayRegistry::REFUND_RECORD_ONLY;
        $actual = YSGatewayRegistry::refund_capability($id);
        $assert($expected === $actual, 'registry ' . $id . ' option=' . ($option ?? 'absent') . ' expected=' . $expected . ' actual=' . $actual);
    }
}

$seed = static function (string $id): void {
    $GLOBALS['wpdb'] = new YSTestWpdbStub();
    $GLOBALS['ys_test_actions'] = [];
    $GLOBALS['ys_test_filters'] = [];
    $GLOBALS['ys_test_hooks'] = [];
    $GLOBALS['ecpay_http_calls'] = 0;
    YSOrder::seed([
        'id' => 90, 'status' => 'processing', 'total' => 500.0,
        'refunded_amount' => 0.0, 'credit_used' => 0.0, 'customer_id' => 0,
        'gateway_id' => $id, 'payment_method' => $id, 'payment_detail' => '{}',
    ]);
};
$controller = new YSOrdersAdminController();
foreach (['0', '1'] as $option) {
    $GLOBALS['ys_test_options'][EcpayCreditGateway::AUTO_REFUND_OPTION] = $option;
    foreach ($catalog as $id => $descriptor) {
        if ('1' === $option && 'ys_ec_ecpay_credit' === $id) { continue; }
        $seed($id);
        $requestId = 'ECPAY-0513-' . $option . '-' . $id;
        $request = new WP_REST_Request([
            'id' => 90, 'amount' => 200.0, 'reason' => 'offline record-only refund',
            'refund_request_id' => $requestId,
        ]);
        $response = $controller->process_refund($request);
        $body = $response->get_data();
        $detail = json_decode((string) YSOrder::$state['payment_detail'], true);
        $entry = $detail['_ys_refund_finalization'][$requestId] ?? [];
        $label = $id . ' option=' . $option;
        $assert(200 === $response->get_status() && true === ($body['ok'] ?? false), 'admin refund succeeds: ' . $label);
        $assert(200.0 === (float) YSOrder::$state['refunded_amount']
            && 'processing' === YSOrder::$state['status']
            && $requestId === ($detail['refunds'][0]['request_id'] ?? null), 'refund amount and history recorded: ' . $label);
        $assert(true === ($entry['record_only'] ?? null)
            && true === ($entry['finalized'] ?? null)
            && true === ($entry['provider_done'] ?? null), 'record-only finalized without freeze: ' . $label);
        $assert(0 === $GLOBALS['ecpay_http_calls']
            && str_contains((string) ($body['message'] ?? ''), '未執行金流'), 'zero provider HTTP and manual notice: ' . $label);
        $again = $controller->process_refund($request);
        $after = json_decode((string) YSOrder::$state['payment_detail'], true);
        $assert(200 === $again->get_status() && true === ($again->get_data()['ok'] ?? false)
            && 200.0 === (float) YSOrder::$state['refunded_amount']
            && 1 === count($after['refunds'] ?? []) && 0 === $GLOBALS['ecpay_http_calls'], 'same request is idempotent, no freeze: ' . $label);
        $next = $controller->process_refund(new WP_REST_Request([
            'id' => 90, 'amount' => 100.0, 'reason' => 'second manual record',
            'refund_request_id' => $requestId . '-NEXT',
        ]));
        $assert(200 === $next->get_status() && true === ($next->get_data()['ok'] ?? false)
            && 300.0 === (float) YSOrder::$state['refunded_amount'] && 0 === $GLOBALS['ecpay_http_calls'],
            'new refund is allowed after finalization: ' . $label);
    }
}

echo json_encode(['suite' => 'v068_refund_record_only_core_pair', 'core_version' => YS_ECOMMERCE_VERSION,
    'php_version' => PHP_VERSION, 'pass' => $pass, 'fail' => $fail, 'live' => false], JSON_UNESCAPED_SLASHES) . "\n";
exit($fail > 0 ? 1 : 0);
