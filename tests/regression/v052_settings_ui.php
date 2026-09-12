<?php
declare(strict_types=1);

// Render the real settings template; only WordPress translation/HTML boundaries are replaced.
define('ABSPATH', __DIR__ . '/');
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});
function __(string $value, string $domain = ''): string { return $value; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
function esc_html(string $value): string { return esc_attr($value); }
function esc_url(string $value): string { return esc_attr($value); }
function esc_html__(string $value, string $domain = ''): string { return esc_html($value); }
function esc_html_e(string $value, string $domain = ''): void { echo esc_html($value); }
function esc_attr_e(string $value, string $domain = ''): void { echo esc_attr($value); }
function admin_url(string $path): string { return 'https://fixture.invalid/wp-admin/' . $path; }
function add_query_arg(string $key, string $value, string $url): string { return $url . '&' . rawurlencode($key) . '=' . rawurlencode($value); }
function checked(mixed $actual, mixed $expected = true): void { if ((string) $actual === (string) $expected) { echo 'checked="checked"'; } }
function selected(mixed $actual, mixed $expected): void { if ((string) $actual === (string) $expected) { echo 'selected="selected"'; } }
function disabled(mixed $actual, mixed $expected = true): void { if ((string) $actual === (string) $expected) { echo 'disabled="disabled"'; } }
function rest_url(string $path = ''): string { return 'https://fixture.invalid/wp-json/' . ltrim($path, '/'); }
function wp_json_encode(mixed $value, int $flags = 0): string { return (string) json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function wp_create_nonce(string $action = ''): string { return 'fixture-rest-nonce'; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
require_once dirname(__DIR__) . '/fixtures/core_admin_partials_stub.php';
require_once dirname(__DIR__, 2) . '/src/Payment/EcpayPaymentCatalog.php';
require_once dirname(__DIR__, 2) . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
require_once dirname(__DIR__, 2) . '/src/Support/Settings.php';
function wp_unslash(string $value): string { return stripslashes($value); }

function render_settings(string $b2c = 'disabled', string $c2c = 'payment', string $tab = 'api', string $error = '', string $family = 'c2c', bool $testMode = true): string {
    $_GET = $error === '' ? [] : ['settings_error' => $error];
    $settings = [
        'tab' => $tab, 'tabs' => ['api' => 'API', 'payment' => '金流', 'shipping' => '物流'],
        'enabled' => true, 'payment_credit_check_code_is_set' => true,
        'home_credential_family' => $family, 'logistics_reuse_payment' => true,
        'payment_mode' => 'redirect', 'payment_modes_implemented' => ['redirect'],
        'legacy_logistics_credentials_present' => true,
        'logistics_b2c_home_source_mode' => $b2c, 'logistics_c2c_source_mode' => $c2c,
        // 🔴 用真的型錄列渲染。手抄一份假的方式清單，測的就只是那份假清單。
        'payment_methods' => \YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog::admin_rows(),
        'credit_installment_periods' => '3,6,12',
        'credit_installment_periods_allowed' => \YangSheep\YSCartEcpay\Support\Settings::ALLOWED_CREDIT_INSTALLMENTS,
        'shipping_methods' => [], 'callback_urls' => ['payment' => 'https://fixture.invalid/callback'],
        'sender_name' => 'Fixture', 'sender_phone' => '', 'sender_zipcode' => '', 'sender_address' => '',
    ];
    foreach (\YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog::default_enabled_by_alias() as $alias => $on) {
        $settings[$alias . '_enabled'] = '1' === $on;
    }
    foreach (['payment', 'logistics_b2c_home', 'logistics_c2c'] as $group) {
        $settings[$group . '_test_mode'] = 'payment' === $group ? $testMode : true;
        $settings[$group . '_merchant_id'] = 'fixture-"<&' . $group;
        $settings[$group . '_hash_key_is_set'] = true;
        $settings[$group . '_hash_iv_is_set'] = true;
    }
    $nonce_action = 'fixture-save';
    ob_start();
    require dirname(__DIR__, 2) . '/templates/admin/ecpay-settings.php';
    return (string) ob_get_clean();
}
function dom(string $html): DOMXPath {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
function first(DOMXPath $xp, string $query): ?DOMElement {
    $node = $xp->query($query)->item(0);
    return $node instanceof DOMElement ? $node : null;
}
if (($argv[1] ?? '') === '--fixture') {
    echo render_settings($argv[2] ?? 'disabled', $argv[3] ?? 'payment');
    exit(0);
}
$checks = [];
function check(string $name, bool $pass): void { global $checks; $checks[] = ['name' => $name, 'pass' => $pass]; }
foreach (['disabled', 'payment', 'separate', 'legacy'] as $mode) {
    $xp = dom(render_settings($mode, $mode));
    foreach (['logistics_b2c_home' => $mode, 'logistics_c2c' => $mode] as $group => $expected) {
        $select = first($xp, '//select[@name="ys_ec_ecpay_' . $group . '_source"]');
        check($mode . ':' . $group . ':source choice exists', $select !== null);
        if (!$select) { continue; }
        $options = [];
        $selected = null;
        foreach ($select->getElementsByTagName('option') as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
            if ($option->hasAttribute('selected')) { $selected = $option->getAttribute('value'); }
        }
        $expectedOptions = ['disabled' => '不使用', 'payment' => '共用上方金流設定', 'separate' => '分開設定'];
        if ($expected === 'legacy') { $expectedOptions['legacy'] = '保留目前設定（舊版）'; }
        check($mode . ':' . $group . ':exact compatible choices', $options === $expectedOptions && $selected === $expected);
        $id = $select->getAttribute('id');
        $fieldsetId = $select->getAttribute('aria-controls');
        $fieldset = first($xp, '//fieldset[@id="' . $fieldsetId . '"]');
        check($mode . ':' . $group . ':label and fieldset linked', $id !== '' && first($xp, '//label[@for="' . $id . '"]') !== null && $fieldset !== null);
        check($mode . ':' . $group . ':no-JS fields reachable', $fieldset !== null && !$fieldset->hasAttribute('hidden') && !$fieldset->hasAttribute('disabled') && !$fieldset->hasAttribute('style'));
        $names = [];
        if ($fieldset) {
            foreach ($fieldset->getElementsByTagName('input') as $input) {
                $names[] = $input->getAttribute('name');
                check($mode . ':' . $group . ':' . $input->getAttribute('name') . ':no-JS enabled', !$input->hasAttribute('disabled') && !$input->hasAttribute('required'));
            }
        }
        check($mode . ':' . $group . ':all five separate fields', $names === array_map(static fn(string $suffix): string => 'ys_ec_ecpay_' . $group . '_' . $suffix, ['test_mode', 'merchant_id', 'hash_key', 'hash_iv', 'clear']));
    }
}
$html = render_settings('payment', 'legacy');
$xp = dom($html);
$text = $xp->document->textContent;
check('one concise credential warning', substr_count($text, '更換金鑰會造成原本已綁定付款的用戶失效，請小心操作。') === 1 && str_contains($text, '進行中的付款與物流單也可能受影響'));
check('payment heading and inherited environment explained', first($xp, '//h2[normalize-space(.)="金流設定"]') !== null && str_contains($text, '金流測試模式') && str_contains($text, '共用此組的物流'));
check('no-JS source rule is explained', str_contains($text, '只有選擇「分開設定」'));
check('obsolete global reuse control removed', first($xp, '//input[@name="ys_ec_ecpay_logistics_reuse_payment"]') === null);
check('no forced-disable instruction', !str_contains($text, '先停用全部'));
$credit = first($xp, '//details[summary="信用卡退款進階設定（選填）"]//input[@name="ys_ec_ecpay_payment_credit_check_code"]');
check('optional credit code remains blank and reachable in details', $credit !== null && $credit->getAttribute('value') === '' && !$credit->hasAttribute('required') && !$credit->hasAttribute('disabled'));
check('credit code official location and optional rule', first($xp, '//details//a[@href="https://developers.ecpay.com.tw/2894/"]') !== null && str_contains($text, '商家檢查碼') && str_contains($text, '信用卡收單') && str_contains($text, '信用卡授權資訊') && str_contains($text, '沒有可留空'));
$home = first($xp, '//details[summary="宅配使用的設定"]//select[@name="ys_ec_ecpay_home_credential_family"]');
check('home family keeps original POST field in details', $home !== null && str_contains($home->textContent, 'B2C／宅配這組') && str_contains($home->textContent, 'C2C 這組'));
$allBlank = true;
foreach ($xp->query('//input[@type="password"]') as $password) { $allBlank = $allBlank && $password->getAttribute('value') === ''; }
check('all saved secrets stay blank', $allBlank && $xp->query('//input[@type="password"]')->length === 7);
check('synthetic merchant ID safely round trips', first($xp, '//input[@name="ys_ec_ecpay_payment_merchant_id"]')?->getAttribute('value') === 'fixture-"<&payment');
check('DB rollback error is not shadowed by obsolete duplicate', str_contains(dom(render_settings('disabled', 'disabled', 'api', 'signer_gate_rollback_failed'))->document->textContent, '設定寫入失敗且還原未完全成功'));
check('DB read error remains', str_contains(dom(render_settings('disabled', 'disabled', 'api', 'settings_state_read_failed'))->document->textContent, '資料庫查詢失敗'));
check('invalid source choice rejection is visible', str_contains(dom(render_settings('disabled', 'disabled', 'api', 'invalid_logistics_source'))->document->textContent, '物流設定選項無效，請重新選擇後儲存。設定未變更。'));
$payment = dom(render_settings('disabled', 'disabled', 'payment'));
$shipping = dom(render_settings('disabled', 'disabled', 'shipping'));
check('payment tab remains independent', first($payment, '//input[@name="ys_ec_ecpay_credit_enabled"]') !== null && first($payment, '//select[@name="ys_ec_ecpay_logistics_c2c_source"]') === null);
check('shipping sender remains', first($shipping, '//input[@name="ys_ec_ecpay_sender_name"]') !== null);
check('diagnostics callbacks remain in their existing tab', str_contains(dom(render_settings('disabled', 'disabled', 'diagnostics'))->document->textContent, 'https://fixture.invalid/callback'));
check('save action and nonce unchanged', first($xp, '//input[@name="action"]')?->getAttribute('value') === 'ys_cart_ecpay_save_settings' && first($xp, '//input[@name="_wpnonce"]') !== null);
$homeHidden = dom(render_settings('payment', 'disabled', 'api', '', 'b2c_home'));
check('home family choice hidden when only one group is in use', first($homeHidden, '//select[@name="ys_ec_ecpay_home_credential_family"]') === null && !str_contains($homeHidden->document->textContent, '宅配使用的設定'));
$homeConflict = dom(render_settings('payment', 'disabled', 'api', '', 'c2c'));
$conflictDetails = first($homeConflict, '//details[summary="宅配使用的設定"]');
check('home family shown open with a warning when it points at a disabled group', $conflictDetails !== null && $conflictDetails->hasAttribute('open') && first($homeConflict, '//select[@name="ys_ec_ecpay_home_credential_family"]') !== null && str_contains($homeConflict->document->textContent, '已設為「不使用」'));
$homeBoth = dom(render_settings('payment', 'separate', 'api', '', 'b2c_home'));
$bothDetails = first($homeBoth, '//details[summary="宅配使用的設定"]');
check('home family shown collapsed without warning when both groups are in use', $bothDetails !== null && !$bothDetails->hasAttribute('open') && !str_contains($homeBoth->document->textContent, '已設為「不使用」') && first($homeBoth, '//select[@name="ys_ec_ecpay_home_credential_family"]//option[@value="b2c_home"][@selected]') !== null);
check('obsolete gate error strings are gone', !str_contains(dom(render_settings('disabled', 'disabled', 'api', 'signer_change_active_labels'))->document->textContent, '仍有未結束或升級前的物流單'));
// ── 交易模式：只有已實作的模式可選，未實作的看得到但存不進 ──
$partialHtml = render_settings('payment', 'separate');
$modeXp = dom($partialHtml);
check(
    'settings compose the Core Surface, flat Sections, Field, Notice, NavTabs and Button partials',
    substr_count($partialHtml, 'data-stub-partial="surface"') === 1
        && substr_count($partialHtml, 'data-stub-partial="section"') === 6
        && substr_count($partialHtml, 'data-variant="flat"') === 6
        && substr_count($partialHtml, 'data-stub-partial="field"') >= 1
        && substr_count($partialHtml, 'data-stub-partial="notice"') === 1
        && substr_count($partialHtml, 'data-stub-partial="nav-tabs"') === 1
        && substr_count($partialHtml, 'data-stub-partial="button"') === 2
        && !str_contains($partialHtml, 'class="ysca-card')
);
$modeInputs = [];
foreach ($modeXp->query('//input[@name="ys_ec_ecpay_payment_mode"]') as $input) {
    $modeInputs[$input->getAttribute('value')] = [
        'type' => $input->getAttribute('type'),
        'disabled' => $input->hasAttribute('disabled'),
        'checked' => $input->hasAttribute('checked'),
    ];
}
check('all three ECPay transaction modes are visible', array_keys($modeInputs) === ['redirect', 'ecpg_web', 'period']);
check('only the implemented mode is selectable and preselected', ($modeInputs['redirect'] ?? null) !== null && !$modeInputs['redirect']['disabled'] && $modeInputs['redirect']['checked']);
check('unimplemented modes are shown but cannot be submitted', ($modeInputs['ecpg_web']['disabled'] ?? false) && ($modeInputs['period']['disabled'] ?? false));
$modeText = $modeXp->document->textContent;
check('redirect mode states no PCI burden and no subscription support', str_contains($modeText, '卡號全程不經過本站') && str_contains($modeText, '不支援訂閱自動扣款'));
check('ECPG mode states it needs application and carries no PCI-DSS requirement', str_contains($modeText, '無需 PCI-DSS') && str_contains($modeText, '申請開通'));
check('background authorisation is explicitly declared out of scope', str_contains($modeText, 'PCI-DSS SAQ-D') && str_contains($modeText, '不支援、也不規劃支援'));
check('server IP helper is present and does not claim a mandatory allow list', first($modeXp, '//code[@id="ys-ec-ecpay-server-ip"]') !== null && str_contains($modeText, '並未要求設定 IP 白名單'));
check('unsupported mode rejection is visible', str_contains(dom(render_settings('disabled', 'disabled', 'api', 'unsupported_payment_mode'))->document->textContent, '本外掛尚未支援'));

// ── 測試／正式環境：畫面要說得出目前打哪個端點 ──
$prodXp = dom(render_settings('payment', 'separate', 'api', '', 'b2c_home', false));
check('production mode names the live endpoint', str_contains($prodXp->document->textContent, 'payment.ecpay.com.tw') && str_contains($prodXp->document->textContent, '會真實扣款'));
check('test mode names the stage endpoint', str_contains($modeText, 'payment-stage.ecpay.com.tw') && str_contains($modeText, '不會真的扣款'));

// ── 金流方式分頁：型錄的每一列都要真的渲染出來，開通提示不得漏 ──
use YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog;

$payXp   = dom(render_settings('disabled', 'disabled', 'payment'));
$payText = $payXp->document->textContent;
$rows    = EcpayPaymentCatalog::admin_rows();

$renderedToggles = [];
foreach ($payXp->query('//input[@type="checkbox"]') as $box) {
    $name = $box->getAttribute('name');
    if (preg_match('/^ys_ec_ecpay_(.+)_enabled$/', $name, $m) === 1) {
        $renderedToggles[$m[1]] = $box->hasAttribute('checked');
    }
}
check('every catalogue payment method renders a toggle', array_keys($rows) === array_keys($renderedToggles));
check('exactly the twelve catalogue methods are offered (11 redirect + 1 ECPG bind-card)', count($rows) === 12);

// 需開通的方式預設不能是勾起來的——勾著等於讓沒開通的站直接對外開賣。
$activationOff = true;
foreach ($rows as $alias => $row) {
    if ('' !== $row['activation']) {
        $activationOff = $activationOff && (($renderedToggles[$alias] ?? true) === false);
    }
}
check('methods needing ECPay activation default to off', $activationOff);

// 🔴 升級安全：0.4.0 新增的方式一個都不能預設開著。預設開＝既有站台升級之後，
// 結帳頁自己多出一個業主沒同意的付款方式。預設開的集合必須恰好是 0.4.0 之前
// 就已經在賣的那四個。
$defaultOn = array_keys(array_filter(EcpayPaymentCatalog::default_enabled_by_alias(), static fn(string $v): bool => '1' === $v));
sort($defaultOn);
check('upgrading a site never turns on a newly added method', $defaultOn === ['atm', 'barcode', 'credit', 'cvs']);
check('rendered toggles agree with the catalogue defaults', array_keys(array_filter($renderedToggles)) === ['credit', 'atm', 'cvs', 'barcode']);

$activationNotes = 0;
foreach ($rows as $row) {
    if ('' !== $row['activation']) {
        $activationNotes += str_contains($payText, $row['activation']) ? 1 : 0;
    }
}
check('each activation requirement is stated next to its toggle', $activationNotes === count(array_filter($rows, static fn(array $r): bool => '' !== $r['activation'])));
check('BNPL states its 3,000 minimum', str_contains($payText, '3,000 元'));
check('Apple Pay states domain verification is required', str_contains($payText, '網域驗證'));
check('payment tab explains the card never reaches this site', str_contains($payText, '卡號全程不經過本站'));

$periods = first($payXp, '//input[@name="ys_ec_ecpay_credit_installment_periods"]');
check('instalment periods field round trips the stored value', $periods !== null && $periods->getAttribute('value') === '3,6,12');
check('instalment periods list the values ECPay accepts', str_contains($payText, '3、5、6、8、9、10、12、18、24、30N'));
check('empty instalment periods are explained as hiding the method', str_contains($payText, '留空則'));

// 🔴 手續費：綠界導轉不可能在結帳頁依期數加費（期數是消費者在綠界頁面選的）。
// 這段文案是商家唯一會讀到的說明，寫錯會讓人去綠界後台找一個不存在的設定。
$feeDetails = first($payXp, '//details[summary="分期手續費怎麼算？"]');
check('the instalment fee question is answered on the page', $feeDetails !== null);
check(
    'it says why we cannot price per period ourselves',
    str_contains($payText, '期數是消費者在綠界付款頁上選的') && str_contains($payText, '沒辦法在結帳頁依期數加收手續費')
);
check(
    'it points at ECPay 消費者自費分期 with its real terms',
    str_contains($payText, '消費者自費分期') && str_contains($payText, '1,000 元')
        && str_contains($payText, '商家收到全額') && str_contains($payText, '一般前台會員無法申請關閉')
);
check(
    'it warns that an unactivated period silently becomes a single payment',
    str_contains($payText, '自動改為信用卡一次付清') && str_contains($payText, '寫入錯誤日誌')
);
check(
    'it states the combinations ECPay forbids',
    str_contains($payText, '紅利折抵') && str_contains($payText, '銀聯卡不支援分期') && str_contains($payText, '簽帳金融卡')
);

$failed = array_values(array_filter($checks, static fn(array $check): bool => !$check['pass']));
echo json_encode(['pass' => count($checks) - count($failed), 'fail' => count($failed), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($failed === [] ? 0 : 1);
