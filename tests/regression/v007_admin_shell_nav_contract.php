<?php
/**
 * Admin shell navigation placement contract.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v007_check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $fail++;
}

$plugin = file_get_contents($root . '/src/Plugin.php') ?: '';

echo "## ECPay admin shell nav placement\n";

v007_check(
    'ECPay registers YS CART admin nav group placement',
    false !== strpos($plugin, "ys_ec_admin_nav_groups")
        && false !== strpos($plugin, 'register_admin_nav_group')
);

v007_check(
    'ECPay settings slug is declared under the providers nav group',
    preg_match("/\\\$groups\\['providers'\\]\\['slugs'\\]\\[\\]\\s*=\\s*'ys-ecommerce-ecpay'/", $plugin) === 1
        && false !== strpos($plugin, 'array_values( array_unique')
);

echo "\nREGRESSION v007_admin_shell_nav_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
