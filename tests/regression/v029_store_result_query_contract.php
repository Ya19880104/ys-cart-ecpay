<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');

    function sanitize_text_field(mixed $value): string {
        return trim((string)$value);
    }

    function sanitize_key(mixed $value): string {
        return strtolower((string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$value));
    }

    class WP_REST_Request {
        /** @param array<string,mixed> $query */
        public function __construct(private array $query) {}
        /** @return array<string,mixed> */
        public function get_query_params(): array { return $this->query; }
        /** @return array<string,mixed> */
        public function get_json_params(): array { return []; }
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

    /** Records the EXACT route config the plugin registers. */
    $GLOBALS['v029_routes'] = [];
    function register_rest_route(string $namespace, string $route, array $args = []): bool {
        $GLOBALS['v029_routes'][$namespace . $route] = $args;
        return true;
    }
}

namespace YangSheep\Ecommerce\Api\Storefront {
    final class YSRequestParser {
        /** Mirrors Core 2.59.3: body only, deliberately no query fallback. */
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

namespace YangSheep\YSCartEcpay\Support {
    final class ShippingMethodOperability {
        public static function has_operable_method(): bool { return true; }
        public static function is_operable(string $method_id): bool { return true; }
    }
}

namespace YangSheep\Ecommerce\Security {
    /**
     * Mirrors Core `YSRateLimiter::check( string $action, int $max, int $window ): bool`.
     *
     * 🔴 計量順序是本檔的核心契約之一：限流 bucket 是**共享資源**（Core 的
     * `get_client_ip()` 在 CDN 後全站共用一個 IP bucket），因此「誰有資格花配額」
     * 必須被釘住——形狀不合格、或解析不出 principal 的請求，一次都不准計量。
     */
    final class YSRateLimiter {
        /** @var list<string> */
        public static array $calls = [];
        public static bool $allow = true;

        public static function check(string $action, int $max = 30, int $window = 60): bool {
            self::$calls[] = $action . '|' . $max . '|' . $window;
            return self::$allow;
        }
    }
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
    /**
     * 🔴 這個 double 必須量 **呼叫次數**，不是只記最後一次的引數。
     *
     * 只記引數的話，「非純量被丟掉、於是傳進去的是空字串」與「根本沒有呼叫」
     * 兩件事看起來一模一樣——而它們的安全性天差地遠：前者仍然會拿一個
     * principal 去碰一次性提領碼。v029 先前正是只斷言引數，才把假綠報成
     * no-claim。
     */
    final class EcpayStoreSelector {
        public static string $scope = '';
        public static string $code = '';
        public static string $principal = '';
        public static int $current_principal_calls = 0;
        public static int $claim_calls = 0;
        /** 設成非 null（例如 ''）可模擬「解析過身分但辨識不出任何 principal」。 */
        public static ?string $forced_principal = null;

        public static function reset(): void {
            self::$scope = 'NOT-CALLED';
            self::$code = 'NOT-CALLED';
            self::$principal = 'NOT-CALLED';
            self::$current_principal_calls = 0;
            self::$claim_calls = 0;
            self::$forced_principal = null;
        }

        public static function current_principal(string $scope): string {
            ++self::$current_principal_calls;
            self::$scope = $scope;
            return null === self::$forced_principal ? 'principal:' . $scope : self::$forced_principal;
        }

        public static function claim_result_code(string $code, string $principal): array {
            ++self::$claim_calls;
            self::$code = $code;
            self::$principal = $principal;
            if ('AbCdEf0123456789AbCdEf0123456789' !== $code) {
                return ['error' => 'invalid', 'store' => []];
            }
            return ['error' => null, 'store' => ['store_id' => '001234']];
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/Support/CartScope.php';
    require_once dirname(__DIR__, 2) . '/src/Plugin.php';

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

    $code = 'AbCdEf0123456789AbCdEf0123456789';
    $selector = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::class;

    /**
     * 跑一次 handler，並回報這一次的 warning 與 double 的呼叫次數。
     *
     * 每個 case 前都 reset()——計數器不歸零的話，後面的 case 會把前面的呼叫算進來，
     * 「這一次有沒有呼叫」就永遠問不出答案。
     *
     * @param array<string,mixed> $query
     */
    $limiter = \YangSheep\Ecommerce\Security\YSRateLimiter::class;

    $run = static function (array $query, ?string $forced_principal = null) use ($selector, $limiter): array {
        $selector::reset();
        $selector::$forced_principal = $forced_principal;
        $limiter::$calls = [];
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_ALL);
        $response = (new \YangSheep\YSCartEcpay\Plugin())->ecpay_store_result(new WP_REST_Request($query));
        restore_error_handler();

        return [
            'response'        => $response,
            'warnings'        => $warnings,
            'principal_calls' => $selector::$current_principal_calls,
            'claim_calls'     => $selector::$claim_calls,
            'rate_calls'      => $limiter::$calls,
        ];
    };

    /** 每一個被拒絕的形狀都必須同時滿足這六件事：形狀錯＝零身分、零計量、零提領。 */
    $assert_rejected_without_touching_identity = static function (array $result, string $case) use ($assert): void {
        $response = $result['response'];
        $assert([] === $result['warnings'], "{$case}: raises no PHP warning");
        $assert(
            400 === $response->get_status() && false === ($response->data['success'] ?? true),
            "{$case}: is rejected with HTTP 400"
        );
        $assert(0 === $result['principal_calls'], "{$case}: resolves no principal");
        $assert([] === $result['rate_calls'], "{$case}: spends no rate-limit quota");
        $assert(0 === $result['claim_calls'], "{$case}: consumes no one-time claim");
        $assert(
            'no-store, private' === ($response->headers['Cache-Control'] ?? ''),
            "{$case}: rejection stays private and non-cacheable"
        );
    };

    // ── 合法 scalar：既有行為必須完全保留，且各恰好呼叫一次 ────────────────────
    $valid = $run(['code' => $code, 'cart_scope' => 'headless_1']);
    $assert(
        200 === $valid['response']->get_status() && true === ($valid['response']->data['success'] ?? false),
        'GET store-result accepts its documented query parameters'
    );
    $assert($code === $selector::$code, 'one-time result code reaches the claim function without being dropped');
    $assert('headless_1' === $selector::$scope && 'principal:headless_1' === $selector::$principal, 'cart scope binds the same principal used by the claim');
    $assert('no-store, private' === ($valid['response']->headers['Cache-Control'] ?? ''), 'store result remains private and non-cacheable');
    $assert(
        1 === $valid['principal_calls'] && 1 === $valid['claim_calls'],
        'a valid request resolves the principal once and claims once'
    );

    // ── 🔴 計量順序：principal 有效之後、claim 之前，actor ＋ IP 兩個 bucket 都要過 ──
    //
    // actor bucket 的 key 從 principal 的 SHA-256 導出（與 ecpay_map_url() 同一個
    // 派生法），所以它只可能在 principal 解析**之後**存在——這個斷言同時釘住
    // 「limiter 在 principal 之後」與「IP bucket 沒有被 actor bucket 取代」。
    $expected_actor = 'ecpay_store_result_actor_' . substr(hash('sha256', 'principal:headless_1'), 0, 24) . '|12|60';
    $assert(
        [$expected_actor, 'ecpay_store_result_ip|60|60'] === $valid['rate_calls'],
        'a valid request is metered through the actor bucket then the shared IP bucket, exactly once each'
    );

    // ── 🔴 429 之後**不得**提領：限流是 claim 的前置條件，不是事後記帳 ─────────────
    $limiter::$allow = false;
    $throttled = $run(['code' => $code, 'cart_scope' => 'headless_1']);
    $limiter::$allow = true;
    $assert(
        429 === $throttled['response']->get_status()
            && 'rate_limited' === ($throttled['response']->data['code'] ?? '')
            && 1 === $throttled['principal_calls']
            && 0 === $throttled['claim_calls']
            && 'no-store, private' === ($throttled['response']->headers['Cache-Control'] ?? ''),
        'a throttled request returns 429 after principal resolution and performs zero claims'
    );

    // ── 🔴 principal 解析不出來＝與既有安全邊界同一句 generic 400 ────────────────
    //
    // 解析「已經跑了」（principal_calls=1）但結果是空——此時 rate limiter 與 claim
    // 都不得執行：計量一個辨識不出的呼叫端，等於讓匿名垃圾流量燒掉 CDN 後**全站
    // 共用**的 IP bucket，反向代理下可阻塞正常 claim；而 claim 層對空 principal 的
    // 拒絕又會多做一次 transient 讀取。
    $anonymous = $run(['code' => $code, 'cart_scope' => 'headless_1'], '');
    $assert(
        400 === $anonymous['response']->get_status()
            && 'store_result_invalid' === ($anonymous['response']->data['code'] ?? '')
            && '缺少提領碼或無法辨識身分。' === ($anonymous['response']->data['message'] ?? ''),
        'an unidentifiable principal gets the same generic 400 as every other early rejection'
    );
    $assert(
        1 === $anonymous['principal_calls']
            && [] === $anonymous['rate_calls']
            && 0 === $anonymous['claim_calls'],
        'an unidentifiable principal spends no rate-limit quota and consumes no claim'
    );
    $assert(
        'no-store, private' === ($anonymous['response']->headers['Cache-Control'] ?? '')
            && [] === $anonymous['warnings'],
        'the unidentifiable-principal rejection stays private, non-cacheable and warning-free'
    );

    // ── 🔴 形狀必須在身分之前被拒 ───────────────────────────────────────────────
    //
    // 先前的修正只是把非純量「丟掉」，之後仍無條件呼叫 current_principal() 與
    // claim_result_code()。那不叫 no-claim：
    //
    //   code=<有效的一次性提領碼>&cart_scope[]=…
    //
    // 會讓 malformed scope 靜默降成 `default`，然後拿 `principal:default` 去碰一張
    // **有效**的提領碼。而 `current_principal()` 對登入者會在讀 `$cart_scope` 之前就
    // 回 `'u:<id>'`（EcpayStoreSelector.php:299-302），headless 的 `X-YS-Guest-Token`
    // 分支（:316-323）同樣與 scope 無關——所以在這兩種主流部署下 principal 會相符、
    // `delete_transient()` 真的執行：提領碼被畸形請求消耗掉、回 HTTP 200，顧客那一
    // 邊只好重選門市。三種形狀分開測，避免像先前那樣兩個參數同時給 array，把
    // 「scalar code + array scope」這條路徑整個遮蔽掉。
    //
    // 🔴 本檔釘住的性質**僅限**：「`code`／`cart_scope` 為非純量，或 `code` sanitize
    // 後為空」時不解析身分、不提領。它**不是**通用的 no-claim——任何非空純量的
    // code 仍會走到 claim_result_code()，只是被那裡的 `/^[A-Za-z0-9]{32}$/` 早退擋在
    // transient I/O 之前。不要把這裡的綠燈讀成「所有畸形輸入都不會碰提領碼」。
    $assert_rejected_without_touching_identity(
        $run(['code' => [$code], 'cart_scope' => 'headless_1']),
        'array code with a valid scalar scope'
    );
    $assert_rejected_without_touching_identity(
        $run(['code' => $code, 'cart_scope' => ['headless_1']]),
        'valid scalar code with an array scope'
    );
    $assert_rejected_without_touching_identity(
        $run(['code' => [$code], 'cart_scope' => ['headless_1']]),
        'array code with an array scope'
    );

    // ── 缺失／空白提領碼同樣不得走到身分解析 ────────────────────────────────────
    $assert_rejected_without_touching_identity(
        $run(['cart_scope' => 'headless_1']),
        'missing code'
    );
    $assert_rejected_without_touching_identity(
        $run(['code' => '', 'cart_scope' => 'headless_1']),
        'empty code'
    );
    $assert_rejected_without_touching_identity(
        $run(['code' => "   \t ", 'cart_scope' => 'headless_1']),
        'whitespace-only code'
    );

    // ── null 必須走 array_key_exists() 那條路，不是 isset() ─────────────────────
    //
    // 🔴 `is_scalar(null)` 是 false，但 `isset()` 對 null 也是 false。守門若寫成
    // `isset($q[$f]) && !is_scalar(...)`，`cart_scope => null` 會整個跳過形狀檢查、
    // 被 `(string) null` 變成 ''、降成 default，然後照樣拿有效提領碼去提領——正是
    // 這次要關掉的那個洞，從 null 這道門重新打開。同 repo 的
    // EcpaySavedStoreReauthorizer.php:42-44 用的就是 isset()，所以這條**必須**被釘住。
    $assert_rejected_without_touching_identity(
        $run(['code' => null, 'cart_scope' => 'headless_1']),
        'null code'
    );
    $assert_rejected_without_touching_identity(
        $run(['code' => $code, 'cart_scope' => null]),
        'null cart scope'
    );

    // ── 🔴 code 必須先過**鑄造格式**（exact `^[A-Za-z0-9]{32}$`），不是只驗非空 ────
    //
    // 提領碼的鑄造端是 `generate_result_code()`：恆為 32 個 `[A-Za-z0-9]`。任何不符
    // 這個格式的非空值——`'0'`、`'zzz'`、31 或 33 字元、帶標點——**永遠不可能**是一張
    // 真的提領碼，卻在舊契約下照樣解析 principal、花共享限流配額、再進 claim 層做一次
    // 必然失敗的 transient 讀取。舊的 v029 甚至把「`'0'` 走到 principal/claim」鎖成
    // 綠色契約；本段把它反轉：不符鑄造格式＝形狀錯＝零身分、零計量、零提領。
    foreach ([
        'zero'                  => '0',
        'short word'            => 'zzz',
        '31 characters'         => substr($code, 0, 31),
        '33 characters'         => $code . 'A',
        'punctuated 32'         => substr($code, 0, 31) . '-',
        'valid with trailing newline' => $code . "\n",
    ] as $label => $bad_code) {
        $assert_rejected_without_touching_identity(
            $run(['code' => $bad_code, 'cart_scope' => 'headless_1']),
            "a nonempty code that cannot be a minted code ({$label})"
        );
    }

    // ── 🔴 Route custody：捕捉 register_rest_route 的 exact config ────────────────
    //
    // 形狀閘門讀的是 raw query bag，因此它的正確性取決於**路由沒有先幫我們洗過形狀**。
    // WP 會在 dispatch 前把 `args` 的 `sanitize_callback` 結果寫回 `params`，而
    // `sanitize_text_field()` 對陣列回空字串——只要有人替 `code`／`cart_scope` 宣告
    // sanitize_callback，`?cart_scope[]=x` 就會以純量 `''` 抵達 handler，閘門永遠不觸發，
    // 而請求 stub 沒有 args pipeline、任何行為測試都看不出來。所以這件事只能由
    // **註冊設定本身**來釘。
    (new \YangSheep\YSCartEcpay\Plugin())->register_storefront_routes('ys-ecommerce/v1');
    $route = $GLOBALS['v029_routes']['ys-ecommerce/v1/ecpay/store-result'] ?? null;

    $assert(is_array($route), 'the store-result route is registered');
    $assert('GET' === ($route['methods'] ?? null), 'store-result stays a GET route');
    $assert(
        is_array($route) && ! array_key_exists('args', $route),
        'store-result declares no args pipeline that could reshape code/cart_scope before the handler'
    );
    $assert(
        is_array($route) && isset($route['permission_callback']) && is_callable($route['permission_callback'], true),
        'store-result keeps a permission callback'
    );
    $assert(
        is_array($route)
            && [] === array_diff(array_keys($route), ['methods', 'callback', 'permission_callback']),
        'store-result registers exactly methods/callback/permission_callback and nothing else'
    );

    // ── 🔴 exact early error code + message + no-store ──────────────────────────
    //
    // 前置拒絕刻意沿用提領層既有那一句，不新增字串：這個端點在 permission 之前就會
    // 回應，多一種只有「形狀錯」看得到的訊息等於白送一個判別位元給呼叫端。
    $early = $run(['code' => [$code], 'cart_scope' => 'headless_1']);
    $assert(
        'store_result_invalid' === ($early['response']->data['code'] ?? ''),
        'early rejection reuses the exact store_result_invalid code'
    );
    $assert(
        '缺少提領碼或無法辨識身分。' === ($early['response']->data['message'] ?? ''),
        'early rejection reuses the exact claim-layer message and introduces no new oracle'
    );
    $assert(
        'no-store, private' === ($early['response']->headers['Cache-Control'] ?? ''),
        'early rejection still carries no-store, private'
    );

    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $start = strpos($source, 'public function ecpay_store_result');
    $end = false === $start ? false : strpos($source, 'public function ecpay_reauthorize_saved_store', $start);
    $body = false !== $start && false !== $end ? substr($source, $start, $end - $start) : '';
    $assert(false !== strpos($body, 'get_query_params()') && false === strpos($body, 'YSRequestParser::params'), 'GET handler reads the query bag explicitly');

    echo "\nstore result query contract: {$passed} PASS / {$failed} FAIL\n";
    exit($failed > 0 ? 1 : 0);
}
