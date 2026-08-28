<?php
/**
 * ECPay public docs and skill must publish the canonical store-map payload.
 */

declare(strict_types=1);

$root   = dirname(__DIR__, 2);
$sdk    = (string) file_get_contents($root . '/sdk/ys-cart-ecpay-headless.js');
$docs   = (string) file_get_contents($root . '/docs/headless.md');
$skill  = (string) file_get_contents($root . '/skills/ys-cart-ecpay-headless.md');
$readme = (string) file_get_contents($root . '/README.md');

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$check(
    'SDK exposes canonical store-map helper',
    str_contains($sdk, 'requestStoreMapForm')
        && str_contains($sdk, 'shipping_id: shippingId')
        && str_contains($sdk, '/wp-json/ys-ecommerce-headless/v1/stores/ecpay/map-url')
);

$check(
    'Docs publish shipping_id payload',
    str_contains($docs, '"shipping_id": "ys_ec_ecpay_ship_unimart"')
        && str_contains($docs, 'Use `shipping_id` as the public payload key')
        && str_contains($docs, 'YsCartEcpay.requestStoreMapForm')
);

$check(
    'Skill instructs agents to send shipping_id',
    str_contains($skill, 'as `shipping_id`')
        && str_contains($skill, 'provider-facing callback surfaces')
);

$check(
    'README documents headless route and callback boundary',
    str_contains($readme, '"shipping_id": "ys_ec_ecpay_ship_unimart"')
        && str_contains($readme, 'provider-facing callback routes')
        && str_contains($readme, 'YsCartEcpay.requestStoreMapForm')
);

// ── cart_scope canonical ABI：SDK 與文件必須與伺服器同一條規則 ────────────────
//
// 伺服器端的權威在 src/Support/CartScope.php。SDK 若各自帶一份 pattern、或 map 與
// claim 兩條路徑各驗各的，就會出現「SDK 放行、伺服器 400」的漂移。這裡釘住
// **只有一份 pattern**，而且兩條路徑共用同一個 validator。
$check(
    'SDK publishes exactly one canonical cart_scope pattern',
    1 === substr_count($sdk, 'var CART_SCOPE_PATTERN = /^[a-z0-9_]{1,32}$/;')
        && 1 === substr_count($sdk, 'function canonicalCartScope(')
);

$check(
    'SDK map and claim helpers share that one validator',
    2 === substr_count($sdk, 'var scope = canonicalCartScope(options);')
        && 2 === substr_count($sdk, 'Promise.reject(new Error(CART_SCOPE_ERROR))')
);

$check(
    'SDK exposes the shared rule to callers',
    str_contains($sdk, 'isCanonicalCartScope: isCanonicalCartScope')
        && str_contains($sdk, 'cartScopePattern: CART_SCOPE_PATTERN')
);

$check(
    'SDK pattern is byte-identical to the server CartScope pattern',
    str_contains(
        (string) file_get_contents($root . '/src/Support/CartScope.php'),
        "'/^[a-z0-9_]{1,32}$/D'"
    )
        && str_contains($sdk, '/^[a-z0-9_]{1,32}$/')
);

// ── SDK 驗證範圍的措辭必須**精確**，不得過度宣稱 ────────────────────────────
//
// 只有兩個高階 helper（requestStoreMapForm／claimStoreResult）在送出前驗證；
// legacy raw helper（requestMapForm＝原樣 POST）為了 ABI 相容不驗，靠伺服器
// fail-closed。文件寫成「整個 SDK 都會驗」就是把不存在的防線寫成存在。
$check(
    'Docs scope the client-side validation claim to the two high-level helpers only',
    str_contains($docs, 'Only the two high-level helpers validate before sending')
        && str_contains($docs, '`requestMapForm()` (legacy raw POST) | No')
        && ! str_contains($docs, 'The SDK enforces the identical rule')
);

$check(
    'README scopes the client-side validation claim the same way',
    str_contains($readme, 'The two high-level SDK helpers')
        && str_contains($readme, 'post their payload as-is')
        && ! str_contains($readme, 'The SDK enforces the same rule')
);

// ── 🔴 Truth-lock：server 端「exact canonical 400」只屬於 ECPay 的三條 boundary ──
//
// 沒有任何 raw helper 能**自行**保證 destination enforcement：`requestMapForm` 只是
// `postJson` 的 alias（sdk 匯出表 `requestMapForm: postJson`），接受呼叫端給的**任意
// URL**、原樣送出——只有當呼叫端確實把 URL 指向某條 ECPay public boundary 時，那個
// **destination** 才提供 exact canonical 400。`checkout()` 送 **Core** `/checkout/process`
// ——Core 的 `YSCheckoutController::read_cart_scope()` 對非 canonical 值 `sanitize_key`
// 後**降成 default**，不是 400；`submitForm()` 送呼叫端給的任意 actionUrl。
//
// 上一輪的 truth-lock 只鎖了 README，docs 的同一句 broad promise 存活、還把
// requestMapForm 寫成「命中 ECPay boundary」的無條件句——本段把**兩份文件**的
// 全稱句型與無條件 destination 句型一起永久禁止。
$broad_banned = static function (string $text): bool {
    return ! str_contains($text, 'rejects non-canonical values from them')
        && ! str_contains($text, 'identically')
        && ! str_contains($text, 'Hits the ECPay map-url boundary');
};

$check(
    'README does not claim identical server-side rejection for non-ECPay destinations',
    $broad_banned($readme)
        && str_contains($readme, 'requestMapForm')
        && str_contains($readme, 'not scope-aware')
        && str_contains($readme, 'normalises')
);

$check(
    'Docs carry no broad identical-rejection or unconditional-destination promise',
    $broad_banned($docs)
);

$check(
    'Docs state that raw-helper server behaviour is decided entirely by the actual destination',
    str_contains($docs, 'accepts whatever URL the caller supplies')
        && str_contains($docs, 'decided entirely by the actual destination')
        && str_contains($docs, 'only if the caller points it at')
);

$check(
    'README makes the requestMapForm promise conditional on the caller-chosen destination',
    str_contains($readme, 'when the caller points it at')
        && ! str_contains($readme, 'hits the ECPay map-url boundary, so')
);

$check(
    'Docs scope the exact-400 promise to the ECPay boundary and describe checkout/submitForm truthfully',
    ! str_contains($docs, 'not scope-aware; server-side gates apply')
        && str_contains($docs, 'follows the destination')
        && str_contains($docs, 'normalises a non-canonical scope to `default`')
        && str_contains($docs, 'call `isCanonicalCartScope()` first')
);

$check(
    'Docs publish the store-result ordering contract (shape → principal → metering → claim)',
    str_contains($docs, 'shape → principal → metering → claim')
        && str_contains($docs, 'ecpay_store_result_actor_')
        && str_contains($docs, 'ecpay_store_result_ip')
);

$check(
    'Docs publish the canonical cart_scope ABI and its fail-closed errors',
    str_contains($docs, '`cart_scope` is a canonical ABI (fail-closed)')
        && str_contains($docs, 'Only a completely omitted `cart_scope` falls back to `default`')
        && str_contains($docs, 'invalid_cart_scope')
        && str_contains($docs, 'invalid_saved_store_request')
        && str_contains($docs, 'store_result_invalid')
        && str_contains($docs, 'YsCartEcpay.isCanonicalCartScope')
);

// 🔴 行為必須真的跑一次，不能只比字串——字串比對抓不到「validator 寫了但沒被呼叫」。
// Node 缺席時 fail-closed：本外掛的發布 gate 本來就要求 Node（`node --check` SDK）。
$node = trim((string) shell_exec('node --version 2>&1'));
if ('' === $node || false === strpos($node, 'v')) {
    $check('Node is available to execute the SDK validator contract', false);
} else {
    $probe = $root . '/tests/regression/.v013-cart-scope-probe.cjs';
    file_put_contents($probe, <<<'JS'
const path = require('path');
const fs = require('fs');
const sdk = fs.readFileSync(path.join(__dirname, '..', '..', 'sdk', 'ys-cart-ecpay-headless.js'), 'utf8');
const g = { location: { href: 'https://example.test/', origin: 'https://example.test' } };
new Function('window', sdk)(g);
const api = g.YsCartEcpay;
const results = [];
const ok = (label, cond) => results.push((cond ? 'PASS ' : 'FAIL ') + label);

ok('canonical accepted', api.isCanonicalCartScope('headless_1') === true);
ok('uppercase rejected', api.isCanonicalCartScope('HEADLESS_1') === false);
ok('hyphen rejected', api.isCanonicalCartScope('my-scope') === false);
ok('empty rejected', api.isCanonicalCartScope('') === false);
ok('too long rejected', api.isCanonicalCartScope('a'.repeat(33)) === false);
ok('non-string rejected', api.isCanonicalCartScope(0) === false && api.isCanonicalCartScope(null) === false);

// 兩條路徑都必須在**發出任何請求之前**就 reject。fetch 被換成會爆的樁：
// 只要 validator 沒擋住，這裡就會冒出 'NETWORK' 而不是 cart_scope 錯誤。
// 樁必須放在 globalThis——SDK 的 postJson 呼叫 bare `fetch`，在 Node 下解析到
// globalThis 的原生實作，window 樁攔不到。
globalThis.fetch = () => { throw new Error('NETWORK'); };
g.fetch = globalThis.fetch;
const bad = { cart_scope: 'HEADLESS_1' };
const code = 'AbCdEf0123456789AbCdEf0123456789';

Promise.allSettled([
  api.requestStoreMapForm('https://example.test', 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_atm', bad),
  api.claimStoreResult('https://example.test', code, bad),
]).then((settled) => {
  settled.forEach((s, i) => {
    const name = i === 0 ? 'requestStoreMapForm' : 'claimStoreResult';
    ok(name + ' rejects a non-canonical scope before any network call',
      s.status === 'rejected' && /already be canonical/.test(String(s.reason && s.reason.message)));
  });

  // 文件的 truth 基準：requestMapForm 只是 postJson 的 alias——不驗 scope、不鎖
  // destination。對一個**任意非 ECPay** URL 帶非 canonical scope 呼叫它，必須
  // 「原樣送出」：fetch 被呼叫、URL 正是呼叫端給的、payload 裡的 HEADLESS_1
  // 一個 byte 都沒被改。這實證「沒有任何 raw helper 自行保證 destination
  // enforcement」，也就是文件那句 conditional wording 的依據。
  // SDK 的 postJson 呼叫的是 bare `fetch`，在 Node 下解析到 globalThis.fetch
  //（原生實作），不是我們的 window 樁——必須覆蓋 globalThis 才攔得到。
  const sent = [];
  globalThis.fetch = (url, init) => {
    sent.push({ url: String(url), body: String(init && init.body) });
    return Promise.resolve({ json: () => Promise.resolve({}) });
  };
  return api.requestMapForm('https://arbitrary.example/not-ecpay', { cart_scope: 'HEADLESS_1' }).then(() => {
    ok('requestMapForm posts as-is to whatever URL the caller supplies',
      sent.length === 1
        && sent[0].url === 'https://arbitrary.example/not-ecpay'
        && sent[0].body.indexOf('"cart_scope":"HEADLESS_1"') !== -1);
  });
}).then(() => {
  console.log(results.join('\n'));
  process.exit(results.some((r) => r.startsWith('FAIL')) ? 1 : 0);
});
JS
    );
    $out = [];
    $status = 1;
    exec(escapeshellarg(PHP_BINARY) === '' ? '' : 'node ' . escapeshellarg($probe) . ' 2>&1', $out, $status);
    @unlink($probe);
    $text = implode("\n", $out);
    $check(
        'SDK validator behaviour: both helpers reject a non-canonical scope before any network call',
        0 === $status && false === strpos($text, 'FAIL ') && false === strpos($text, 'NETWORK')
    );
    if (0 !== $status) {
        echo $text . PHP_EOL;
    }
}

echo "v013_headless_docs_contract FAIL={$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
