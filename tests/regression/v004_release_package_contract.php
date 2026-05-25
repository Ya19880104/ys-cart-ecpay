<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$artifacts = glob($root . '/artifacts/ys-cart-ecpay-*.zip') ?: [];

if (!$artifacts) {
    echo "v004_release_package_contract skipped: no release zip built yet\n";
    exit(0);
}

rsort($artifacts);
$zipPath = $artifacts[0];

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
