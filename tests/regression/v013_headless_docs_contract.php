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
g.fetch = () => { throw new Error('NETWORK'); };
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
