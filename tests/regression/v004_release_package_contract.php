<?php
/**
 * v004 — 發佈包契約
 *
 * 兩個缺口曾讓這條測試變成 false GREEN：
 *   1. 原以 `glob + rsort` 取「現存最新」ZIP。版號推進後仍會去驗一個陳舊的包並回報
 *      PASS——通過與否和即將出貨的版本毫無關係。現改為鎖定 plugin header 的當前版號，
 *      找不到對應 ZIP 即明確失敗。
 *   2. 只鎖檔名仍不夠：任何人放一個同名 ZIP（例如從別條 branch 打的 0.2.11）都會通過。
 *      現額外要求 ZIP **內容**與當前 source 對得上：主檔 Version header、
 *      YS_CART_ECPAY_VERSION 常數，以及 src/Plugin.php 的 bytes 必須逐位相同。
 *
 * Run: php tests/regression/v004_release_package_contract.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$slug = 'ys-cart-ecpay';

$pluginFile = $root . '/' . $slug . '.php';
if (!is_file($pluginFile)) {
    fwrite(STDERR, "Plugin main file not found: {$pluginFile}\n");
    exit(1);
}
$pluginSource = (string) file_get_contents($pluginFile);

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $pluginSource, $m)) {
    fwrite(STDERR, "Unable to read plugin version header from {$pluginFile}\n");
    exit(1);
}
$version = $m[1];

if (!preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $pluginSource, $cm)) {
    fwrite(STDERR, "Unable to read YS_CART_ECPAY_VERSION constant from {$pluginFile}\n");
    exit(1);
}
if ($cm[1] !== $version) {
    fwrite(STDERR, "Version header ({$version}) and YS_CART_ECPAY_VERSION ({$cm[1]}) disagree in source\n");
    exit(1);
}

$zipPath = $root . '/artifacts/' . $slug . '-' . $version . '.zip';
if (!is_file($zipPath)) {
    $found = glob($root . '/artifacts/' . $slug . '-*.zip') ?: [];
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

$mustHave = [
    $slug . '/' . $slug . '.php',
    $slug . '/vendor/autoload.php',
    $slug . '/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    $slug . '/vendor/yangsheep/ys-plugin-hub-client/src/Updater/YSUpdateChecker.php',
    $slug . '/README.md',
    $slug . '/src/Plugin.php',
];

foreach ($mustHave as $entry) {
    if (!in_array($entry, $names, true)) {
        $zip->close();
        fwrite(STDERR, "Release zip missing required entry: {$entry}\n");
        exit(1);
    }
}

$forbiddenPatterns = [
    '#^' . preg_quote($slug, '#') . '/\\.git/#',
    '#^' . preg_quote($slug, '#') . '/\\.github/#',
    '#^' . preg_quote($slug, '#') . '/artifacts/#',
    '#^' . preg_quote($slug, '#') . '/bin/#',
    '#^' . preg_quote($slug, '#') . '/tests/#',
    '#^' . preg_quote($slug, '#') . '/docs/superpowers/#',
    '#^' . preg_quote($slug, '#') . '/tmp/#',
    '#^' . preg_quote($slug, '#') . '/node_modules/#',
    '#^' . preg_quote($slug, '#') . '/\\.env(\\..*)?$#',
    '#\\.log$#',
    '#\\.tmp$#',
    '#^' . preg_quote($slug, '#') . '/composer\\.(json|lock)$#',
];

foreach ($names as $entry) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $entry)) {
            $zip->close();
            fwrite(STDERR, "Release zip includes forbidden entry: {$entry}\n");
            exit(1);
        }
    }
}

// ── 內容必須來自「當前 source」，否則同名 ZIP 一樣能 false GREEN ──────────────

$zippedPlugin = $zip->getFromName($slug . '/' . $slug . '.php');
if (false === $zippedPlugin) {
    $zip->close();
    fwrite(STDERR, "Unable to read {$slug}.php from the release zip\n");
    exit(1);
}

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $zippedPlugin, $zm) || $zm[1] !== $version) {
    $zip->close();
    fwrite(STDERR, "Release zip Version header does not match the current source ({$version})\n");
    exit(1);
}

if (!preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $zippedPlugin, $zc)
    || $zc[1] !== $version) {
    $zip->close();
    fwrite(STDERR, "Release zip YS_CART_ECPAY_VERSION does not match the current source ({$version})\n");
    exit(1);
}

// 至少一個實質原始碼檔必須逐位相同——證明這個包是從當前 branch 打出來的，
// 而不是另一條分支打出的同版號 ZIP。
$zippedCore = $zip->getFromName($slug . '/src/Plugin.php');
$zip->close();

if (false === $zippedCore) {
    fwrite(STDERR, "Unable to read src/Plugin.php from the release zip\n");
    exit(1);
}

$localCore = (string) file_get_contents($root . '/src/Plugin.php');
$normalize = static fn(string $s): string => str_replace("\r\n", "\n", $s);

if (hash('sha256', $normalize($zippedCore)) !== hash('sha256', $normalize($localCore))) {
    fwrite(STDERR, "Release zip src/Plugin.php does not match the working tree\n");
    fwrite(STDERR, '  zip    : ' . hash('sha256', $normalize($zippedCore)) . "\n");
    fwrite(STDERR, '  source : ' . hash('sha256', $normalize($localCore)) . "\n");
    fwrite(STDERR, "  The artifact was not built from this branch's current bytes.\n");
    exit(1);
}

echo "v004_release_package_contract passed (version {$version}, artifact verified against current source)\n";
