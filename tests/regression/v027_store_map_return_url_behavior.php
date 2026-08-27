<?php
declare(strict_types=1);

/**
 * Store-map callback return URL behavior.
 *
 * The production renderer exits after emitting HTML, so the parent executes one
 * child process and inspects the actual JavaScript redirect URL.
 */

if (in_array('--child', $argv, true)) {
    define('ABSPATH', __DIR__ . '/');

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES);
    }

    function home_url(string $path = ''): string
    {
        return 'https://shop.test' . $path;
    }

    // Faithful distinction needed by this regression: display context encodes
    // ampersands, while raw URL sanitization does not introduce HTML entities.
    function esc_url(string $url): string
    {
        return str_replace('&', '&#038;', $url);
    }

    function esc_url_raw(string $url): string
    {
        return $url;
    }

    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Minimal WordPress-compatible query append behavior, including fragment
     * extraction before query parsing. That fragment rule is what exposes an
     * earlier display-context escape as a broken URL.
     *
     * @param array<string,string> $args
     */
    function add_query_arg(array $args, string $url): string
    {
        $fragment = '';
        $hash = strpos($url, '#');
        if (false !== $hash) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $pairs = [];
        foreach ($args as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . (string) $value;
        }
        return $url . $separator . implode('&', $pairs) . $fragment;
    }

    function status_header(int $status): void {}
    function nocache_headers(): void {}

    require_once dirname(__DIR__, 2) . '/src/Shipping/Ecpay/EcpayStoreSelector.php';

    $method = new ReflectionMethod(
        YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::class,
        'render_callback_page'
    );
    $method->invoke(null, [
        'context'    => 'checkout',
        'return_url' => 'https://shop.test/checkout/?alpha=1&beta=2',
        'store_id'   => '991122',
    ], str_repeat('B', 32));
    exit(0);
}

$command = [PHP_BINARY, __FILE__, '--child'];
$pipes = [];
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "FAIL: unable to start child process\n");
    exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);

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

$assert(0 === $exit, 'production callback renderer exits successfully');
$assert('' === $stderr, 'production callback renderer emits no stderr');

$redirect = '';
if (preg_match('/window\.location\.replace\(("(?:[^"\\\\]|\\\\.)*")\);/', (string) $stdout, $match)) {
    $decoded = json_decode($match[1], true);
    $redirect = is_string($decoded) ? $decoded : '';
}

$assert('' !== $redirect, 'callback HTML contains a decodable JavaScript redirect URL');

$parts = parse_url($redirect);
$query = [];
if (is_array($parts)) {
    parse_str((string) ($parts['query'] ?? ''), $query);
}

$assert('1' === ($query['alpha'] ?? null), 'first caller query parameter is preserved');
$assert('2' === ($query['beta'] ?? null), 'second caller query parameter is preserved');
$assert(str_repeat('B', 32) === ($query['ys_ec_store_result'] ?? null), 'one-time result code is appended as a query parameter');
$assert('' === (string) ($parts['fragment'] ?? ''), 'display escaping does not move query parameters into a fragment');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
