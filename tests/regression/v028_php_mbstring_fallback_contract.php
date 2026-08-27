<?php
declare(strict_types=1);

/**
 * Minimum-PHP extension contract.
 *
 * WordPress recommends mbstring but does not require it. The provider must not
 * fatal when that extension is absent, and its ECPay length limits must remain
 * UTF-8 codepoint-safe through the shared fallback.
 */

$root = dirname(__DIR__, 2);
$helper = $root . '/src/Support/Utf8Text.php';

if ('--child' === ($argv[1] ?? '')) {
    define('ABSPATH', __DIR__ . '/');
    require_once $helper;

    $class = YangSheep\YSCartEcpay\Support\Utf8Text::class;
    echo json_encode([
        'mbstring' => extension_loaded('mbstring'),
        'length'   => $class::length("羊羊🐑\n商店"),
        'cut'      => $class::truncate("羊羊🐑\n商店", 4),
        'zero'     => $class::truncate('abc', 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        ++$passed;
        echo "PASS: {$label}\n";
        return;
    }
    ++$failed;
    echo "FAIL: {$label}\n";
};

$assert(is_file($helper), 'shared UTF-8 fallback helper exists');

$directCalls = [];
foreach ([
    'src/Cli/EcpayRefundAttemptCommand.php',
    'src/Payment/EcpayCreditGateway.php',
    'src/Payment/EcpayPaymentClient.php',
    'src/Shipping/Ecpay/EcpayShippingRequester.php',
] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    if (preg_match('/\bmb_(?:substr|strlen)\s*\(/', $source)) {
        $directCalls[] = $relative;
    }
}
$assert([] === $directCalls, 'provider runtime has no unguarded direct mbstring calls');

$process = proc_open(
    [PHP_BINARY, '-n', __FILE__, '--child'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (! is_resource($process)) {
    fwrite(STDERR, "FAIL: unable to start portable PHP child\n");
    exit(1);
}

$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
$result = json_decode($stdout, true);

$assert(0 === $exit && '' === $stderr && is_array($result), 'portable PHP child executes without fatal error');
$assert(6 === ($result['length'] ?? null), 'UTF-8 length counts codepoints without a hard mbstring dependency');
$assert("羊羊🐑\n" === ($result['cut'] ?? null), 'UTF-8 truncation preserves complete codepoints and newlines');
$assert('' === ($result['zero'] ?? null), 'zero-length truncation returns an empty string');

$mode = is_array($result) && empty($result['mbstring']) ? 'fallback exercised' : 'native mbstring exercised';
echo "\nphp mbstring fallback contract ({$mode}): {$passed} PASS / {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
