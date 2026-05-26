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

$plugin   = file_get_contents($root . '/src/Plugin.php') ?: '';
$manifest = file_get_contents($root . '/manifest.php') ?: '';

echo "## ECPay admin shell nav placement\n";

v007_check(
    'ECPay exposes a manifest admin page for YS CART provider navigation',
    false !== strpos($manifest, "'admin_page'")
        && false !== strpos($manifest, "'slug'                => 'ys-provider-ecpay'")
        && false !== strpos($manifest, "'render_callback'")
);

v007_check(
    'ECPay does not hard-code admin nav groups or legacy provider menu hooks',
    false === strpos($plugin, 'ys_ec_admin_nav_groups')
        && false === strpos($plugin, 'ys_ec_admin_payment_menus')
        && false === strpos($plugin, 'add_submenu_page')
);

echo "\nREGRESSION v007_admin_shell_nav_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
