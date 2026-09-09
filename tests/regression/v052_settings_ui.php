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
function esc_html_e(string $value, string $domain = ''): void { echo esc_html($value); }
function esc_attr_e(string $value, string $domain = ''): void { echo esc_attr($value); }
function admin_url(string $path): string { return 'https://fixture.invalid/wp-admin/' . $path; }
function add_query_arg(string $key, string $value, string $url): string { return $url . '&' . rawurlencode($key) . '=' . rawurlencode($value); }
function checked(mixed $actual, mixed $expected = true): void { if ((string) $actual === (string) $expected) { echo 'checked="checked"'; } }
function selected(mixed $actual, mixed $expected): void { if ((string) $actual === (string) $expected) { echo 'selected="selected"'; } }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash(string $value): string { return stripslashes($value); }

function render_settings(string $b2c = 'disabled', string $c2c = 'payment', string $tab = 'api', string $error = ''): string {
    $_GET = $error === '' ? [] : ['settings_error' => $error];
    $settings = [
        'tab' => $tab, 'tabs' => ['api' => 'API', 'payment' => '金流', 'shipping' => '物流'],
        'enabled' => true, 'payment_credit_check_code_is_set' => true,
        'home_credential_family' => 'c2c', 'logistics_reuse_payment' => true,
        'legacy_logistics_credentials_present' => true,
        'logistics_b2c_home_source_mode' => $b2c, 'logistics_c2c_source_mode' => $c2c,
        'payment_methods' => ['credit' => '信用卡'], 'credit_enabled' => true,
        'shipping_methods' => [], 'callback_urls' => ['payment' => 'https://fixture.invalid/callback'],
        'sender_name' => 'Fixture', 'sender_phone' => '', 'sender_zipcode' => '', 'sender_address' => '',
    ];
    foreach (['payment', 'logistics_b2c_home', 'logistics_c2c'] as $group) {
        $settings[$group . '_test_mode'] = true;
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
$failed = array_values(array_filter($checks, static fn(array $check): bool => !$check['pass']));
echo json_encode(['pass' => count($checks) - count($failed), 'fail' => count($failed), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($failed === [] ? 0 : 1);
