<?php
/**
 * 發佈包 builder。
 *
 * 排除規則與 eligible 集合的推導全部委派給 bin/release-policy.php——tests/regression/
 * v004 讀的是**同一份**政策，兩邊不會再各自漂移。entry 以「目錄先、檔案後，各自排序」
 * 的固定順序寫入，讓同一份工作目錄重打時位元組穩定。
 */

declare(strict_types=1);

require_once __DIR__ . '/release-policy.php';

$slug    = ys_cart_ecpay_release_slug();
$root    = str_replace('\\', '/', dirname(__DIR__));
$main    = $root . '/' . $slug . '.php';
$source  = (string) file_get_contents($main);
$version = preg_match('/^ \* Version:\s*([^\r\n]+)/m', $source, $matches) ? trim($matches[1]) : '0.0.0';
$outDir  = $root . '/artifacts';
$zipPath = $outDir . '/' . $slug . '-' . $version . '.zip';

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Zip extension is required.\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Unable to create artifacts directory.\n");
    exit(1);
}

$scan = ys_cart_ecpay_release_scan($root);

if ($scan['links']) {
    fwrite(STDERR, "Refusing to build: the working tree contains symlinks, which would be followed into the package:\n");
    foreach ($scan['links'] as $link) {
        fwrite(STDERR, "  {$link}\n");
    }
    exit(1);
}

if (!$scan['files']) {
    fwrite(STDERR, "Refusing to build: the eligible file set is empty.\n");
    exit(1);
}

// entry 名稱在寫入前就先過安全性檢查，壞名稱不該先進包再靠測試抓。
foreach (ys_cart_ecpay_release_expected_entries($scan) as $entry) {
    $problem = ys_cart_ecpay_release_entry_problem($entry);
    if (null !== $problem) {
        fwrite(STDERR, "Refusing to build: unsafe archive entry '{$entry}' ({$problem}).\n");
        exit(1);
    }
}

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "Unable to open zip: {$zipPath}\n");
    exit(1);
}

foreach ($scan['dirs'] as $relative) {
    $zip->addEmptyDir($slug . '/' . $relative);
}

foreach ($scan['files'] as $relative) {
    if (!$zip->addFile($root . '/' . $relative, $slug . '/' . $relative)) {
        $zip->close();
        fwrite(STDERR, "Unable to add file to zip: {$relative}\n");
        exit(1);
    }
}

$zip->close();

echo $zipPath . PHP_EOL;
