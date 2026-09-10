<?php
/**
 * v053 — 付款方式型錄與方式專屬 AIO 欄位（v0.4.0）
 *
 * 0.4.0 把「有哪些付款方式」收斂成一張型錄，並讓方式可以帶自己的 AIO 欄位
 * （銀聯的 `UnionPay=1`、分期的 `CreditInstallment`）。這兩件事各有一個安靜的
 * 失敗模式，本檔對著 **production 程式碼**驗證：
 *
 *   1. 額外欄位若沒被納入 CheckMacValue，綠界一律判驗章失敗——症狀是整批交易
 *      建不起來，而且從我們這邊看不出原因。
 *   2. 額外欄位若能覆寫建單欄位，簽出來的表單**完全合法**卻是錯的：`TotalAmount`
 *      被換掉＝顧客被扣的金額與我們記下的 `charged_amount` 不一致（退款端據此
 *      判定全額／部分）；`ReturnURL` 被換掉＝付款通知不會回到我們手上。
 *
 * 另外驗證型錄本身：12 個方式（11 個導轉＋1 個站內付 2.0 綁卡）的 ChoosePayment 都在綠界官方合法值內、每個 gateway
 * 類別的 id 都查得到 descriptor、不在型錄裡的 gateway 必須 fail-closed。
 *
 * Run: php tests/regression/v053_payment_catalog_and_extra_fields.php
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }

    function current_time(string $type): string
    {
        return '2026-09-09 12:00:00';
    }

    function rest_url(string $path = ''): string
    {
        return 'https://fixture.invalid/wp-json/' . ltrim($path, '/');
    }

    function home_url(string $path = ''): string
    {
        return 'https://fixture.invalid' . $path;
    }

    function wp_strip_all_tags($value): string
    {
        return strip_tags((string) $value);
    }

    function __($text, $domain = '')
    {
        return $text;
    }
}

namespace YangSheep\Ecommerce\Gateways {
    interface YSGatewayInterface {}
    interface YSOrderScopedTokenChargeGatewayInterface extends YSGatewayInterface {}
}

namespace YangSheep\Ecommerce\Models {
    class YSOrder
    {
        public static function find(int $id): ?object { return null; }
    }
}

namespace YangSheep\Ecommerce\Utils {
    class YSLogger
    {
        public static function error(string $c, string $m, array $ctx = []): void {}
        public static function warning(string $c, string $m, array $ctx = []): void {}
        public static function info(string $c, string $m, array $ctx = []): void {}
    }
}

namespace YangSheep\YSCartEcpay {
    final class Plugin
    {
        public static function manifest(): array { return []; }
    }
}

namespace YangSheep\YSCartEcpay\Support {
    /** 憑證與設定的替身；CheckMacValue 與 Utf8Text 用真的。 */
    class Settings
    {
        public static string $installments = '3,6,12';
        public static bool $enabled = true;

        public static function payment_credentials(): array
        {
            return ['merchant_id' => '2000132', 'hash_key' => '5294y06JbISpM5x9', 'hash_iv' => 'v77hoKGq4kWxNNIS', 'test_mode' => true];
        }
        public static function has_payment_credentials(): bool { return true; }
        public static function gateway_enabled(string $alias): bool { return self::$enabled; }
        public static function credit_installment_periods(): string { return self::$installments; }
        public static function payment_endpoint(): string { return 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5'; }
    }

    /** lease 一律成功；本檔測的不是維護鎖。 */
    class ProviderMaintenanceLock
    {
        public static function reader_lease(): ?object { return (object) ['token' => 'fixture-lease']; }
        public static function reader_fence(string $token): bool { return true; }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/Utf8Text.php';
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Payment/EcpayPaymentClient.php';
    require_once $root . '/src/Payment/EcpayGatewayBase.php';
    require_once $root . '/src/Payment/EcpayPaymentCatalog.php';
    // v0.5.0：站內付 2.0 綁卡閘道與它的三個純函式依賴（本檔沒有 autoloader）。
    require_once $root . '/src/Ecpg/EcpgAesCodec.php';
    require_once $root . '/src/Ecpg/EcpgClient.php';
    require_once $root . '/src/Ecpg/EcpgOrderContext.php';
    foreach ([
        'EcpayCreditGateway', 'EcpayAtmGateway', 'EcpayWebAtmGateway', 'EcpayCvsGateway',
        'EcpayBarcodeGateway', 'EcpayCreditInstallmentGateway', 'EcpayUnionPayGateway',
        'EcpayApplePayGateway', 'EcpayTwqrGateway', 'EcpayWeiXinGateway', 'EcpayBnplGateway',
        'EcpayEcpgCreditGateway',
    ] as $gateway) {
        require_once $root . '/src/Payment/' . $gateway . '.php';
    }
}

namespace YangSheep\YSCartEcpay\Payment {
    /** 不在型錄裡的方式——必須 fail-closed，不得帶著空白 ChoosePayment 去簽表單。 */
    final class EcpayOrphanGateway extends EcpayGatewayBase
    {
        public function get_id(): string { return 'ys_ec_ecpay_not_in_catalog'; }
    }

}

namespace {

    use YangSheep\YSCartEcpay\Payment\EcpayOrphanGateway;
    use YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog;
    use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
    use YangSheep\YSCartEcpay\Support\CheckMacValue;
    use YangSheep\YSCartEcpay\Support\Settings;

    $pass = 0;
    $fail = 0;
    $assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
        if ($ok) { ++$pass; echo "  PASS  {$label}\n"; return; }
        ++$fail; echo "  FAIL  {$label}\n";
    };

    $order = (object) ['id' => 42, 'order_number' => 'YS-42', 'total' => 3500];
    $build = static function (array $extra) use ($order): array {
        return (new EcpayPaymentClient())->build_aio_form($order, 'YSTESTTRADENO000001', 'Credit', $extra);
    };

    echo "## v053 付款方式型錄與方式專屬 AIO 欄位\n";

    // ── A. 額外欄位必須進入表單，且必須被 CheckMacValue 涵蓋 ──────────────────
    $plain = $build([]);
    $withUnionPay = $build(['UnionPay' => '1']);

    $assert(
        ($withUnionPay['fields']['UnionPay'] ?? null) === '1',
        'A1 額外欄位出現在送出的表單裡'
    );
    $assert(
        !array_key_exists('UnionPay', $plain['fields']),
        'A2 沒帶額外欄位時表單不會憑空多出欄位'
    );
    $assert(
        $plain['fields']['CheckMacValue'] !== $withUnionPay['fields']['CheckMacValue'],
        'A3 額外欄位改變 CheckMacValue（代表它在簽章範圍內）'
    );

    // 拿真的 CheckMacValue 重算一次：簽出來的值必須等於「對含額外欄位的整份欄位表」
    // 計算的結果。這條抓的是「簽完才加欄位」——那種寫法 A3 也會過。
    $recomputeFields = $withUnionPay['fields'];
    unset($recomputeFields['CheckMacValue']);
    $credentials = Settings::payment_credentials();
    $assert(
        CheckMacValue::generate($recomputeFields, $credentials['hash_key'], $credentials['hash_iv'], 'sha256')
            === $withUnionPay['fields']['CheckMacValue'],
        'A4 簽章是對「含額外欄位」的完整欄位表計算的'
    );

    // ── B. 額外欄位只能新增，不得覆寫建單欄位 ────────────────────────────────
    foreach (['TotalAmount' => '1', 'ReturnURL' => 'https://attacker.invalid/', 'ChoosePayment' => 'ATM', 'MerchantID' => '9999999'] as $field => $value) {
        $refused = false;
        try {
            $build([$field => $value]);
        } catch (\InvalidArgumentException $e) {
            $refused = str_contains($e->getMessage(), $field);
        }
        $assert($refused, "B1 額外欄位撞名 {$field} → 拒絕建單");
    }
    $assert(
        $build([])['fields']['TotalAmount'] === '3500',
        'B2 拒絕撞名之後，正常建單仍送出訂單金額本身'
    );

    $rejectedShape = 0;
    foreach ([['' => '1'], ['Redeem' => ['a']], ['Redeem' => null]] as $bad) {
        try {
            $build($bad);
        } catch (\InvalidArgumentException $e) {
            ++$rejectedShape;
        }
    }
    $assert($rejectedShape === 3, 'B3 空欄位名與非純量值一律拒絕');

    // ── C. 型錄：官方合法值、descriptor 完整、id 前綴 ────────────────────────
    $officialChoosePayment = ['Credit', 'ATM', 'CVS', 'BARCODE', 'WebATM', 'ApplePay', 'TWQR', 'BNPL', 'WeiXin'];
    $catalogOk = true;
    foreach (EcpayPaymentCatalog::all() as $methodId => $descriptor) {
        $catalogOk = $catalogOk
            && str_starts_with((string) $methodId, 'ys_ec_ecpay_')
            && in_array((string) $descriptor['choose_payment'], $officialChoosePayment, true)
            && (string) $descriptor['enabled_option'] === 'ys_ec_ecpay_' . $descriptor['alias'] . '_enabled'
            && ('' === (string) $descriptor['activation'] || empty($descriptor['default_enabled']));
    }
    $assert($catalogOk, 'C1 每個 descriptor 的 id／ChoosePayment／開關 key／預設值都合規');
    $assert(count(EcpayPaymentCatalog::all()) === 12, 'C2 型錄共 12 個方式（11 個一般支付＋1 個站內付 2.0 綁卡）');
    $assert(
        EcpayPaymentCatalog::alias_to_id() === array_flip(EcpayPaymentCatalog::id_to_alias()),
        'C3 alias↔id 兩個方向互為反函數'
    );
    $assert(
        count(EcpayPaymentCatalog::manifest_methods()) === count(EcpayPaymentCatalog::all()),
        'C4 manifest 導出的筆數與型錄一致（不會漏方式）'
    );

    // ── D. 每個 gateway 類別都照型錄回答，而不是自己抄一份 ───────────────────
    $wiringOk = true;
    $titles = [];
    foreach (EcpayPaymentCatalog::all() as $methodId => $descriptor) {
        $class = (string) $descriptor['class'];
        if (!class_exists($class)) { $wiringOk = false; continue; }
        $instance = new $class();
        $probe = new class($instance) {
            public function __construct(private object $g) {}
            public function call(string $method): mixed
            {
                $r = new \ReflectionMethod($this->g, $method);
                return $r->invoke($this->g);
            }
        };
        $wiringOk = $wiringOk
            && $instance->get_id() === (string) $methodId
            && $instance->get_title() === (string) $descriptor['title']
            && $probe->call('choose_payment') === (string) $descriptor['choose_payment']
            && $probe->call('gateway_key') === (string) $descriptor['alias']
            && abs($instance->get_min_amount() - (float) $descriptor['min_amount']) < 1e-9;
        $titles[] = $instance->get_title();
    }
    $assert($wiringOk, 'D1 每個 gateway 的 id／標題／ChoosePayment／alias／下限都取自型錄');
    $assert(count(array_unique($titles)) === count($titles), 'D2 前台顯示名稱彼此不重複');

    // 銀聯：靜態額外欄位由型錄提供
    $unionPay = new \YangSheep\YSCartEcpay\Payment\EcpayUnionPayGateway();
    $unionProbe = new \ReflectionMethod($unionPay, 'extra_aio_fields');
    $assert(
        $unionProbe->invoke($unionPay) === ['UnionPay' => '1']
            && $unionPay->get_id() !== 'ys_ec_ecpay_credit',
        'D3 銀聯是獨立方式，額外送 UnionPay=1（ChoosePayment 仍為 Credit）'
    );

    // BNPL：3,000 元下限讓金額不足的訂單看不到這個方式
    $bnpl = new \YangSheep\YSCartEcpay\Payment\EcpayBnplGateway();
    $assert(
        !$bnpl->is_available(['total' => 2999]) && $bnpl->is_available(['total' => 3000]),
        'D4 BNPL 未達 3,000 元不出現在結帳頁，達標才出現'
    );

    // ── E. 分期：期數是設定值，沒設定就不可用 ────────────────────────────────
    $installment = new \YangSheep\YSCartEcpay\Payment\EcpayCreditInstallmentGateway();
    $installmentProbe = new \ReflectionMethod($installment, 'extra_aio_fields');

    Settings::$installments = '3,6,12';
    $assert(
        $installmentProbe->invoke($installment) === ['CreditInstallment' => '3,6,12'],
        'E1 已設定期數時送出 CreditInstallment'
    );
    $assert($installment->is_enabled(), 'E2 已設定期數時分期可用');

    Settings::$installments = '';
    $assert(
        $installmentProbe->invoke($installment) === [],
        'E3 未設定期數時不送空白的 CreditInstallment'
    );
    $assert(
        !$installment->is_enabled(),
        'E4 未設定期數時分期整個不可用（不會退化成一次付清）'
    );
    Settings::$installments = '3,6,12';

    // ── F. 不在型錄裡的方式必須 fail-closed ─────────────────────────────────
    $orphan = new EcpayOrphanGateway();
    $orphanFailed = false;
    try {
        $orphan->get_title();
    } catch (\LogicException $e) {
        $orphanFailed = str_contains($e->getMessage(), 'ys_ec_ecpay_not_in_catalog');
    }
    $assert($orphanFailed, 'F1 型錄查不到的方式在被使用時就停下來，不會半開著');

    echo "\nRESULT: {$pass} pass / {$fail} fail\n";
    exit($fail > 0 ? 1 : 0);
}
