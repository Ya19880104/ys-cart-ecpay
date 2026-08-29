<?php
declare(strict_types=1);

/**
 * `cart_scope` canonical ABI — 三條 public boundary 一致的 fail-closed 驗證。
 *
 * Integrator 裁決（2026-08-28）：
 *
 *   `cart_scope` 的正式 canonical ABI 是 `/^[a-z0-9_]{1,32}$/`。
 *   **只有「完全未提供」才可以使用 `default`。** 只要有提供，原值就必須**已經是**
 *   canonical 字串——禁止 sanitize／normalize 之後改綁到另一個 scope，也禁止默默降成
 *   `default`。這是刻意的 fail-closed hardening。
 *
 * 為什麼一定要在身分之前擋：`EcpayStoreSelector::current_principal()` 對登入者會在讀
 * `$cart_scope` **之前**就回 `'u:<id>'`（`:299-302`），headless 的 `X-YS-Guest-Token`
 * 分支（`:316-323`）同樣與 scope 無關。因此「先 normalize 再解析身分」在主流部署下
 * 等於**用另一個 scope 的身分去動敏感資源**——提領碼被消耗、map session 被簽發、
 * saved-store token 被鑄出。
 *
 * 本檔覆蓋 O-1／O-2／O-3／O-5：
 *   - `GET  /ecpay/store-result`（claim）
 *   - `POST /stores/ecpay/map-url`（map session ＋ signed form）
 *   - `POST /stores/ecpay/reauthorize`（saved-store token）
 *   - store-result 的節流契約（與 Core `YSRateLimiter` 相容、缺席時 fail-safe）
 *
 * Run: php tests/regression/v031_cart_scope_canonical_abi.php
 */

namespace {
    define('ABSPATH', __DIR__ . '/');

    function sanitize_text_field(mixed $value): string { return trim((string) $value); }
    function sanitize_key(mixed $value): string {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value));
    }
    function absint(mixed $value): int { return abs((int) $value); }
    function esc_url_raw(string $url): string { return $url; }
    function wp_unslash(mixed $value) { return $value; }
    function wp_json_encode(mixed $value, int $flags = 0) { return json_encode($value, $flags); }
    function __(string $text, string $domain = ''): string { return $text; }
    function is_user_logged_in(): bool { return $GLOBALS['v031_user_id'] > 0; }
    function get_current_user_id(): int { return $GLOBALS['v031_user_id']; }

    $GLOBALS['v031_user_id'] = 0;

    class WP_REST_Request {
        /** @param array<string,mixed> $query @param array<string,mixed> $body */
        public function __construct(private array $query = [], private array $body = []) {}
        /** @return array<string,mixed> */
        public function get_query_params(): array { return $this->query; }
        /** @return array<string,mixed> */
        public function get_json_params(): array { return $this->body; }
        /** @return array<string,mixed> */
        public function get_body_params(): array { return []; }
    }

    class WP_REST_Response {
        /** @var array<string,string> */
        public array $headers = [];
        /** @param array<string,mixed> $data */
        public function __construct(public array $data, private int $status) {}
        public function get_status(): int { return $this->status; }
        public function header(string $name, string $value): void { $this->headers[$name] = $value; }
    }
}

namespace YangSheep\Ecommerce\Api\Storefront {
    final class YSRequestParser {
        /** Mirrors Core 2.59.3: body only, no type filtering whatsoever. */
        public static function params(\WP_REST_Request $request): array {
            $params = $request->get_json_params();
            return [] !== $params ? $params : $request->get_body_params();
        }
    }

    final class YSRestResponder {
        public static function error(string $code, string $message, int $status = 400): \WP_REST_Response {
            return new \WP_REST_Response(['success' => false, 'code' => $code, 'message' => $message], $status);
        }
        public static function success(string $code, string $message, array $data = []): \WP_REST_Response {
            return new \WP_REST_Response(['success' => true, 'code' => $code, 'data' => $data], 200);
        }
    }

    final class YSRestAuth {
        public static function permission_customer_or_guest(): bool { return true; }
        public static function permission_customer_or_guest_write(): bool { return true; }
        public static function permission_logged_in_write(): bool { return true; }
    }
}

namespace YangSheep\Ecommerce\Security {
    // 🔴 子程序模式（argv `--no-rate-limiter`）**完全不宣告**這個類別，用來證明
    // 「Core 限流器不可用時的 fail-safe」是契約而不是推測。條件式宣告在 PHP 合法。
    if ( ! in_array('--no-rate-limiter', $GLOBALS['argv'] ?? [], true) ) {
        /** Mirrors Core `YSRateLimiter::check( string $action, int $max, int $window ): bool`. */
        final class YSRateLimiter {
            /** @var list<string> */
            public static array $calls = [];
            public static bool $allow = true;

            public static function check(string $action, int $max = 30, int $window = 60): bool {
                self::$calls[] = $action;
                return self::$allow;
            }
        }
    }
}

namespace YangSheep\YSCartEcpay\Support {
    final class ShippingMethodOperability {
        public static function has_operable_method(): bool { return true; }
        public static function is_operable(string $method_id): bool { return true; }
        public static function is_configured(string $method_id): bool { return true; }
    }
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
    /** 敏感動作一律計數：身分解析、提領、map session、saved-store token。 */
    final class EcpayStoreSelector {
        public const COD_GATEWAY_ID = 'ys_ec_cod';

        public static int $principal_calls = 0;
        public static int $claim_calls = 0;
        public static int $map_calls = 0;
        public static int $token_calls = 0;
        public static string $scope = 'NOT-CALLED';
        /** true＝模擬「解析過了、但辨識不出任何 principal」（匿名且無 guest token）。 */
        public static bool $anonymous = false;

        public static function reset(): void {
            self::$principal_calls = 0;
            self::$claim_calls = 0;
            self::$map_calls = 0;
            self::$token_calls = 0;
            self::$scope = 'NOT-CALLED';
        }

        public static function sensitive_calls(): int {
            return self::$principal_calls + self::$claim_calls + self::$map_calls + self::$token_calls;
        }

        public static function current_principal(string $scope): string {
            ++self::$principal_calls;
            self::$scope = $scope;
            return self::$anonymous ? '' : 'u:7';
        }

        public static function subscription_id_from_scope(string $scope): int {
            return 1 === preg_match('/^sub_([0-9]+)$/D', $scope, $matches)
                ? max(0, (int) $matches[1])
                : 0;
        }

        public static function claim_result_code(string $code, string $principal): array {
            ++self::$claim_calls;
            return ['error' => null, 'store' => ['store_id' => '001234']];
        }

        public static function build_map_form_data(...$args): array {
            ++self::$map_calls;
            return ['action_url' => 'https://logistics-stage.ecpay.com.tw/Express/map'];
        }

        public static function issue_canonical_saved_selection(...$args): string {
            ++self::$token_calls;
            return 'TOKEN';
        }
    }
}

namespace {
    use YangSheep\Ecommerce\Security\YSRateLimiter;
    use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CartScope.php';
    require_once $root . '/src/Plugin.php';

    $passed = 0;
    $failed = 0;
    $assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        if ($ok) { ++$passed; echo "PASS: {$label}\n"; return; }
        ++$failed; echo "FAIL: {$label}\n";
    };

    $CODE = 'AbCdEf0123456789AbCdEf0123456789';

    // 這些值全都是「有提供、但**不是** canonical」。修正前它們會被 sanitize_key 正規化
    // 或降級，然後照樣去解析身分；修正後一律 400。
    $NON_CANONICAL = [
        'uppercase'            => 'HEADLESS_1',      // sanitize_key 會改綁到 headless_1
        'trailing punctuation' => 'headless_1!!!',   // 同上，改綁到另一個真實 scope
        'hyphen separator'     => 'headless-1',      // sanitize_key 保留連字號，regex 拒絕 -> default
        'punctuation only'     => '!!!',             // -> default
        'empty string'         => '',                // -> default
        'whitespace only'      => '   ',             // -> default
        'leading space'        => ' headless_1',     // -> default
        'too long'             => str_repeat('a', 33),
        'integer'              => 0,                 // 純量但非字串
        'boolean'              => true,
        'array'                => ['headless_1'],
        'null'                 => null,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // O-1a  GET /ecpay/store-result
    // ─────────────────────────────────────────────────────────────────────────
    $limiter = 'YangSheep\Ecommerce\Security\YSRateLimiter';

    $store_result = static function (array $query) use ($CODE, $limiter): array {
        EcpayStoreSelector::reset();
        if (class_exists($limiter)) {
            $limiter::$calls = [];
        }
        $warnings = [];
        set_error_handler(static function (int $n, string $s) use (&$warnings): bool { $warnings[] = $s; return true; }, E_ALL);
        $response = (new \YangSheep\YSCartEcpay\Plugin())->ecpay_store_result(new WP_REST_Request($query));
        restore_error_handler();
        return ['response' => $response, 'warnings' => $warnings];
    };

    // ── O-5 fail-safe：Core 限流器完全不存在時的契約 ───────────────────────────
    //
    // 這個分支只在子程序跑（該程序沒有宣告 YSRateLimiter）。fail-safe 選的是**放行**：
    // 這是顧客結帳路徑上的一次性提領，沒有限流器就整條擋掉會讓 headless 站完全選不了
    // 門市；而端點本身已由 32 字元不可猜的提領碼與 principal 綁定守著。
    if (in_array('--no-rate-limiter', $argv ?? [], true)) {
        $r = $store_result(['code' => $CODE, 'cart_scope' => 'headless_1']);
        $assert(
            ! class_exists($limiter)
                && 200 === $r['response']->get_status()
                && 1 === EcpayStoreSelector::$claim_calls,
            'store-result fails safe (still serves the claim) when the Core rate limiter is unavailable'
        );
        echo "\ncart_scope canonical ABI [no-rate-limiter child]: {$passed} PASS / {$failed} FAIL\n";
        exit($failed > 0 ? 1 : 0);
    }

    foreach ($NON_CANONICAL as $label => $value) {
        $r = $store_result(['code' => $CODE, 'cart_scope' => $value]);
        $assert(
            400 === $r['response']->get_status()
                && 0 === EcpayStoreSelector::sensitive_calls()
                && [] === $r['warnings']
                && 'no-store, private' === ($r['response']->headers['Cache-Control'] ?? ''),
            "store-result rejects a non-canonical cart_scope ({$label}) before any identity work"
        );
    }

    $absent = $store_result(['code' => $CODE]);
    $assert(
        200 === $absent['response']->get_status()
            && 1 === EcpayStoreSelector::$principal_calls
            && 1 === EcpayStoreSelector::$claim_calls
            && 'default' === EcpayStoreSelector::$scope,
        'store-result still defaults when cart_scope is completely absent'
    );

    $canonical = $store_result(['code' => $CODE, 'cart_scope' => 'headless_1']);
    $assert(
        200 === $canonical['response']->get_status()
            && 1 === EcpayStoreSelector::$principal_calls
            && 'headless_1' === EcpayStoreSelector::$scope,
        'store-result accepts an already-canonical cart_scope unchanged'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // O-5  store-result throttling, and its fail-safe when the limiter is absent
    // ─────────────────────────────────────────────────────────────────────────
    YSRateLimiter::$allow = false;
    $limited = $store_result(['code' => $CODE, 'cart_scope' => 'headless_1']);
    $assert(
        429 === $limited['response']->get_status()
            && 'rate_limited' === ($limited['response']->data['code'] ?? '')
            && 1 === EcpayStoreSelector::$principal_calls
            && 0 === EcpayStoreSelector::$claim_calls
            && 'no-store, private' === ($limited['response']->headers['Cache-Control'] ?? ''),
        'store-result throttles after principal resolution and before any claim'
    );
    YSRateLimiter::$allow = true;

    // 🔴 節流不得跑在形狀閘門之前：畸形請求不該消耗合法使用者的配額。
    $malformed_quota = $store_result(['code' => $CODE, 'cart_scope' => ['x']]);
    $assert(
        400 === $malformed_quota['response']->get_status() && [] === YSRateLimiter::$calls,
        'a malformed store-result request does not spend rate-limit quota'
    );

    // 🔴 非空但不可能是鑄造出來的 code（不符 `^[A-Za-z0-9]{32}$`）同樣零身分、零計量、
    // 零提領——舊實作讓它先花掉共享 IP 配額、再進 claim 層做一次必然失敗的 transient
    // 讀取；反向代理下這種垃圾請求可以把全站共用的 bucket 燒光，阻塞正常 claim。
    foreach (['zzz' => 'zzz', '31-char' => substr($CODE, 0, 31), 'punctuated' => substr($CODE, 0, 31) . '-'] as $label => $bad) {
        $invalid_code = $store_result(['code' => $bad, 'cart_scope' => 'headless_1']);
        $assert(
            400 === $invalid_code['response']->get_status()
                && 0 === EcpayStoreSelector::sensitive_calls()
                && [] === YSRateLimiter::$calls
                && [] === $invalid_code['warnings']
                && 'no-store, private' === ($invalid_code['response']->headers['Cache-Control'] ?? ''),
            "store-result rejects an unmintable nonempty code ({$label}) with zero principal, zero metering, zero claim"
        );
    }

    // 🔴 principal 解析不出來（匿名且無 guest token）＝generic 400，**且不計量**。
    // 計量在 principal 之後才有意義：辨識不出的呼叫端沒有 actor bucket 可言，而替它
    // 記到共享 IP bucket 上，等於讓匿名垃圾流量替所有正常顧客把配額燒掉。
    EcpayStoreSelector::$anonymous = true;
    $no_principal = $store_result(['code' => $CODE, 'cart_scope' => 'headless_1']);
    EcpayStoreSelector::$anonymous = false;
    $assert(
        400 === $no_principal['response']->get_status()
            && 1 === EcpayStoreSelector::$principal_calls
            && 0 === EcpayStoreSelector::$claim_calls
            && [] === YSRateLimiter::$calls
            && [] === $no_principal['warnings']
            && 'no-store, private' === ($no_principal['response']->headers['Cache-Control'] ?? ''),
        'an unidentifiable principal is rejected with zero metering and zero claim'
    );

    // 🔴 計量的**形狀**也要釘住：actor bucket（由 principal 導出）先於共享 IP bucket，
    // 各恰一次。actor key 只可能在 principal 之後存在，這個斷言因此同時證明
    // 「limiter 在 principal 之後」與「IP bucket 仍在、沒被 actor bucket 取代」。
    $well_formed = $store_result(['code' => $CODE, 'cart_scope' => 'headless_1']);
    $expected_actor = 'ecpay_store_result_actor_' . substr(hash('sha256', 'u:7'), 0, 24);
    $assert(
        200 === $well_formed['response']->get_status()
            && 2 === count(YSRateLimiter::$calls)
            && 0 === strpos((string) YSRateLimiter::$calls[0], $expected_actor)
            && 0 === strpos((string) YSRateLimiter::$calls[1], 'ecpay_store_result_ip'),
        'a well-formed store-result request is metered through the actor bucket then the shared IP bucket'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // O-1b + O-2  POST /stores/ecpay/map-url
    // ─────────────────────────────────────────────────────────────────────────
    $map_url = static function (array $body): array {
        EcpayStoreSelector::reset();
        YSRateLimiter::$calls = [];
        $warnings = [];
        set_error_handler(static function (int $n, string $s) use (&$warnings): bool { $warnings[] = $s; return true; }, E_ALL);
        $response = (new \YangSheep\YSCartEcpay\Plugin())->ecpay_map_url(new WP_REST_Request([], $body));
        restore_error_handler();
        return ['response' => $response, 'warnings' => $warnings];
    };

    foreach ($NON_CANONICAL as $label => $value) {
        $r = $map_url([
            'shipping_id'    => 'ys_ec_ecpay_ship_family',
            'payment_method' => 'ys_ec_ecpay_atm',
            'cart_scope'     => $value,
        ]);
        $assert(
            400 === $r['response']->get_status()
                && 0 === EcpayStoreSelector::sensitive_calls()
                && [] === $r['warnings'],
            "map-url rejects a non-canonical cart_scope ({$label}) with no principal, no map session and no warning"
        );
    }

    // 其他欄位的形狀同樣必須在任何 string cast 之前擋掉（O-2 的 array-to-string）。
    foreach (['shipping_id', 'context', 'return_url', 'payment_method'] as $field) {
        $r = $map_url([
            'shipping_id'    => 'ys_ec_ecpay_ship_family',
            'payment_method' => 'ys_ec_ecpay_atm',
            $field           => ['x'],
        ]);
        $assert(
            400 === $r['response']->get_status()
                && 0 === EcpayStoreSelector::sensitive_calls()
                && [] === $r['warnings'],
            "map-url rejects a non-scalar {$field} before any string cast"
        );
    }

    // 控制組：canonical scope 必須順利通過 scope 閘門，繼續走到後面的既有守門。
    $reached = $map_url(['cart_scope' => 'headless_1', 'payment_method' => 'ys_ec_ecpay_atm']);
    $assert(
        'missing_shipping_id' === ($reached['response']->data['code'] ?? '')
            && 1 === EcpayStoreSelector::$principal_calls
            && 'headless_1' === EcpayStoreSelector::$scope,
        'map-url lets an already-canonical cart_scope through to the existing guards'
    );

    $map_absent = $map_url(['payment_method' => 'ys_ec_ecpay_atm']);
    $assert(
        'missing_shipping_id' === ($map_absent['response']->data['code'] ?? '')
            && 'default' === EcpayStoreSelector::$scope,
        'map-url still defaults when cart_scope is completely absent'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // O-1c + O-3  POST /stores/ecpay/reauthorize
    // ─────────────────────────────────────────────────────────────────────────
    require_once $root . '/src/Shipping/Ecpay/EcpaySavedStoreReauthorizer.php';
    $GLOBALS['v031_user_id'] = 7;

    $reauthorize = static function (array $params): array {
        EcpayStoreSelector::reset();
        $warnings = [];
        set_error_handler(static function (int $n, string $s) use (&$warnings): bool { $warnings[] = $s; return true; }, E_ALL);
        $result = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpaySavedStoreReauthorizer::reauthorize($params);
        restore_error_handler();
        return ['result' => $result, 'warnings' => $warnings];
    };

    foreach ($NON_CANONICAL as $label => $value) {
        $r = $reauthorize([
            'address_id'     => 1,
            'shipping_id'    => 'ys_ec_ecpay_ship_family',
            'payment_method' => 'ys_ec_ecpay_atm',
            'cart_scope'     => $value,
        ]);
        $assert(
            400 === (int) ($r['result']['status'] ?? 0)
                && 'invalid_saved_store_request' === (string) ($r['result']['code'] ?? '')
                && 0 === EcpayStoreSelector::sensitive_calls()
                && [] === $r['warnings'],
            "reauthorize rejects a non-canonical cart_scope ({$label}) before any principal or token"
        );
    }

    // 🔴 O-3 的核心：`null` 必須被視為「有提供但形狀錯」，不是「未提供」。
    // `isset()` 對 null 回 false，會讓它整個跳過檢查——這裡逐欄釘住。
    foreach (['address_id', 'shipping_id', 'payment_method', 'cart_scope'] as $field) {
        $r = $reauthorize([
            'address_id'     => 1,
            'shipping_id'    => 'ys_ec_ecpay_ship_family',
            'payment_method' => 'ys_ec_ecpay_atm',
            $field           => null,
        ]);
        $assert(
            400 === (int) ($r['result']['status'] ?? 0)
                && 'invalid_saved_store_request' === (string) ($r['result']['code'] ?? '')
                && 0 === EcpayStoreSelector::sensitive_calls(),
            "reauthorize treats a null {$field} as a malformed field, not as absent"
        );
    }

    // ── O-5 fail-safe，在一個真的沒有 YSRateLimiter 的子程序裡驗證 ───────────────
    $child_cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --no-rate-limiter';
    $child_output = [];
    $child_status = 1;
    exec($child_cmd . ' 2>&1', $child_output, $child_status);
    $child_text = implode("\n", $child_output);
    $assert(
        0 === $child_status
            && false !== strpos($child_text, 'store-result fails safe')
            && false !== strpos($child_text, '[no-rate-limiter child]'),
        'the rate-limiter fail-safe is proven in a process where the Core limiter does not exist'
    );

    echo "\ncart_scope canonical ABI: {$passed} PASS / {$failed} FAIL\n";
    exit($failed > 0 ? 1 : 0);
}
