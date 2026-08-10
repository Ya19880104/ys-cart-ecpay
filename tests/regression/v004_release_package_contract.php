<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

// 必須鎖定「當前目標版號」的發佈包。先前以 glob + rsort 取現存最新 ZIP，於是
// 版號推進後仍會去驗一個陳舊的包並回報 PASS——通過與否和即將出貨的版本無關。
$pluginFile = $root . '/ys-cart-ecpay.php';
if (!is_file($pluginFile)
    || !preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', (string) file_get_contents($pluginFile), $m)) {
    fwrite(STDERR, "Unable to read plugin version from {$pluginFile}\n");
    exit(1);
}
$version = $m[1];
$zipPath = $root . '/artifacts/ys-cart-ecpay-' . $version . '.zip';

if (!is_file($zipPath)) {
    $found = glob($root . '/artifacts/ys-cart-ecpay-*.zip') ?: [];
    fwrite(STDERR, "Release zip for the current version was not found: {$zipPath}\n");
    fwrite(STDERR, "  plugin version : {$version}\n");
    fwrite(STDERR, '  artifacts found: ' . ($found ? implode(', ', array_map('basename', $found)) : '(none)') . "\n");
    fwrite(STDERR, "  Build the package for this version before treating the suite as a release gate.\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required to inspect {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    fwrite(STDERR, "Unable to open release zip: {$zipPath}\n");
    exit(1);
}

$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$zip->close();

$mustHave = [
    'ys-cart-ecpay/ys-cart-ecpay.php',
    'ys-cart-ecpay/vendor/autoload.php',
    'ys-cart-ecpay/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    'ys-cart-ecpay/vendor/yangsheep/ys-plugin-hub-client/src/Updater/YSUpdateChecker.php',
    'ys-cart-ecpay/README.md',
];

foreach ($mustHave as $entry) {
    if (!in_array($entry, $names, true)) {
        fwrite(STDERR, "Release zip missing required entry: {$entry}\n");
        exit(1);
    }
}

$forbiddenPatterns = [
    '#^ys-cart-ecpay/\\.git/#',
    '#^ys-cart-ecpay/\\.github/#',
    '#^ys-cart-ecpay/artifacts/#',
    '#^ys-cart-ecpay/bin/#',
    '#^ys-cart-ecpay/tests/#',
    '#^ys-cart-ecpay/docs/superpowers/#',
    '#^ys-cart-ecpay/tmp/#',
    '#^ys-cart-ecpay/node_modules/#',
    '#^ys-cart-ecpay/\\.env(\\..*)?$#',
    '#\\.log$#',
    '#\\.tmp$#',
    '#^ys-cart-ecpay/composer\\.(json|lock)$#',
];

foreach ($names as $entry) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $entry)) {
            fwrite(STDERR, "Release zip includes forbidden entry: {$entry}\n");
            exit(1);
        }
    }
}

echo "v004_release_package_contract passed\n";
