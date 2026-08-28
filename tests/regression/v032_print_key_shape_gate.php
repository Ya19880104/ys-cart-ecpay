<?php
declare(strict_types=1);

/**
 * 列印端點的 key 形狀閘門（O-4）。
 *
 * `EcpayPrintController::handle()` 之前把 `$_GET['key']` 直接 `(string)` 轉型再拿去
 * `get_transient()` / `delete_transient()`：
 *
 *   - `?key[]=x` 會觸發 array-to-string warning（而且是在輸出任何內容之前），並以
 *     捏造出來的識別碼 `Array` 去對 transient 做讀取**與無條件刪除**。
 *   - key 沒有任何格式檢查，因此任何長度／字元的輸入都會變成一次 transient 往返。
 *
 * key 的真實格式由 `EcpayShippingRequester` 鑄造：`wp_generate_password( 24, false, false )`
 * ——24 個 `[A-Za-z0-9]`。閘門就照這個 exact 格式驗，且必須在**碰任何 transient 或
 * 送出任何 header 之前**完成。管理權限檢查維持在最前面，錯誤訊息不得洩漏 transient
 * 的識別資訊（不回顯 key、不回顯 transient 名稱）。
 *
 * Run: php tests/regression/v032_print_key_shape_gate.php
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['v032_can'] = true;
    $GLOBALS['v032_transient_calls'] = [];
    $GLOBALS['v032_headers'] = [];
    $GLOBALS['v032_die'] = null;

    final class V032_Died extends \Exception {
        public function __construct(public string $body, public int $status) { parent::__construct($body); }
    }

    function current_user_can(string $cap): bool { return $GLOBALS['v032_can']; }
    function sanitize_text_field(mixed $value): string { return trim((string) $value); }
    function sanitize_key(mixed $value): string {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value));
    }
    function wp_unslash(mixed $value) { return $value; }
    function esc_html__(string $text, string $domain = ''): string { return $text; }
    function esc_html(string $text): string { return $text; }
    function esc_attr(string $text): string { return $text; }
    function esc_url(string $url): string { return $url; }
    function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
    function wp_die(string $message = '', $title = '', $args = []): void {
        $status = is_int($title) ? $title : (int) ($args['response'] ?? 0);
        throw new V032_Died($message, $status);
    }
    function get_transient(string $key) { $GLOBALS['v032_transient_calls'][] = 'get:' . $key; return false; }
    function delete_transient(string $key): bool { $GLOBALS['v032_transient_calls'][] = 'delete:' . $key; return true; }
    function status_header(int $code): void { $GLOBALS['v032_headers'][] = 'status:' . $code; }
    function nocache_headers(): void { $GLOBALS['v032_headers'][] = 'nocache'; }
    function __(string $text, string $domain = ''): string { return $text; }
}

namespace YangSheep\YSCartEcpay\Support {
    final class ShippingMethodOperability {
        public static function is_operable(string $method_id): bool { return true; }
    }
    final class ProviderMaintenanceLock {
        public static function reader_lease(): ?object { return (object) ['token' => 'v032']; }
        public static function reader_fence(string $token): bool { return true; }
    }
    final class Settings {
        public static function logistics_credentials_for_method(string $m): array {
            return ['merchant_id' => 'M', 'hash_key' => 'k', 'hash_iv' => 'i', 'test_mode' => true];
        }
        public static function logistics_endpoint(string $path = '', string $method_id = ''): string {
            return 'https://logistics-stage.ecpay.com.tw' . $path;
        }
    }
    final class CheckMacValue {
        public static function verify(array $p, string $k, string $i, string $m = 'sha256'): bool { return true; }
    }
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
    final class EcpayShippingCatalog {
        public static function print_spec(string $method_id): ?array {
            return ['path' => '/helper/printTradeDocument'];
        }
    }
}

namespace {
    use YangSheep\YSCartEcpay\Api\EcpayPrintController;

    require_once dirname(__DIR__, 2) . '/src/Api/EcpayPrintController.php';

    $passed = 0;
    $failed = 0;
    $assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        if ($ok) { ++$passed; echo "PASS: {$label}\n"; return; }
        ++$failed; echo "FAIL: {$label}\n";
    };

    /** @param mixed $key */
    $handle = static function ($key, bool $provide = true): array {
        $GLOBALS['v032_transient_calls'] = [];
        $GLOBALS['v032_headers'] = [];
        $_GET = $provide ? ['key' => $key] : [];
        $warnings = [];
        set_error_handler(static function (int $n, string $s) use (&$warnings): bool { $warnings[] = $s; return true; }, E_ALL);
        $died = null;
        try {
            EcpayPrintController::handle();
        } catch (V032_Died $e) {
            $died = $e;
        }
        restore_error_handler();

        return [
            'died'       => $died,
            'warnings'   => $warnings,
            'transients' => $GLOBALS['v032_transient_calls'],
            'headers'    => $GLOBALS['v032_headers'],
        ];
    };

    // 真實 key 格式：wp_generate_password( 24, false, false ) → 24 個 [A-Za-z0-9]
    $VALID_KEY = 'AbCdEf0123456789AbCdEf01';
    $assert(24 === strlen($VALID_KEY) && 1 === preg_match('/^[A-Za-z0-9]{24}$/D', $VALID_KEY), 'the fixture key matches the real generator format');

    // ── 權限仍然排第一 ─────────────────────────────────────────────────────────
    $GLOBALS['v032_can'] = false;
    $denied = $handle($VALID_KEY);
    $assert(
        null !== $denied['died'] && 403 === $denied['died']->status && [] === $denied['transients'],
        'capability check still runs before anything else'
    );
    $GLOBALS['v032_can'] = true;

    // ── 形狀不合格 → 不得碰 transient、不得發 header、不得有 warning ──────────
    $BAD = [
        'array'            => ['x'],
        'null'             => null,
        'empty'            => '',
        'whitespace only'  => '   ',
        'too short'        => 'AbCdEf0123456789AbCdEf0',
        'too long'         => 'AbCdEf0123456789AbCdEf012',
        'punctuation'      => 'AbCdEf0123456789AbCdEf-1',
        'trailing newline' => "AbCdEf0123456789AbCdEf01\n",
    ];
    foreach ($BAD as $label => $key) {
        $r = $handle($key);
        $assert(
            null !== $r['died']
                && 400 === $r['died']->status
                && [] === $r['transients']
                && [] === $r['headers']
                && [] === $r['warnings'],
            "a {$label} print key is rejected before any transient or header"
        );
        $assert(
            null !== $r['died'] && false === strpos($r['died']->body, 'ys_ec_ecpay_print_'),
            "the {$label} rejection does not disclose the transient identifier"
        );
    }

    $missing = $handle(null, false);
    $assert(
        null !== $missing['died'] && 400 === $missing['died']->status && [] === $missing['transients'],
        'a missing print key is rejected before any transient'
    );

    // ── 合格形狀仍照既有流程往下走（會在 payload 缺失時 410）────────────────────
    $ok = $handle($VALID_KEY);
    $assert(
        null !== $ok['died']
            && 410 === $ok['died']->status
            && ['get:ys_ec_ecpay_print_' . $VALID_KEY, 'delete:ys_ec_ecpay_print_' . $VALID_KEY] === $ok['transients'],
        'a correctly shaped key still reaches the existing one-time transient path'
    );

    echo "\nprint key shape gate: {$passed} PASS / {$failed} FAIL\n";
    exit($failed > 0 ? 1 : 0);
}
