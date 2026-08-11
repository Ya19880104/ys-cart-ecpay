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
$expectedEntries = ys_cart_ecpay_release_expected_entries($scan);
foreach ($expectedEntries as $entry) {
    $problem = ys_cart_ecpay_release_entry_problem($entry);
    if (null !== $problem) {
        fwrite(STDERR, "Refusing to build: unsafe archive entry '{$entry}' ({$problem}).\n");
        exit(1);
    }
}

// 逐一檢查擋不住 case-fold 碰撞——兩個名字各自都合法，解壓到不分大小寫的檔案系統
// 才會互相覆蓋。必須在**刪除既有 ZIP 之前**判定，否則一次失敗的建置會順手毀掉
// 上一份可用的產物。
$collisions = ys_cart_ecpay_release_collision_problems($expectedEntries);
if ($collisions) {
    fwrite(STDERR, "Refusing to build: archive entries collide on a case-insensitive filesystem:\n");
    foreach ($collisions as $collision) {
        fwrite(STDERR, "  {$collision}\n");
    }
    exit(1);
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

// 所有 entry 的 mtime 正規化成固定值，讓「同一份 source → 同一個 SHA-256」成立。
//
// 沒有這一步，artifact 的 hash 是**建置時間**的函數而不是內容的函數：
//   - 目錄 entry 沒有對應的來源檔，addEmptyDir() 直接蓋上「現在」；同一棵樹連續
//     打兩次，30 個目錄 entry 的時間戳就不同，hash 也就不同。
//   - 檔案 entry 取檔案 mtime，而重新 clone 會把所有 mtime 換成 checkout 時間。
// 於是「回報 hash 以證明這個包來自那份 source」整件事就不成立——兩次建置對不上，
// 而對不上的原因與內容無關。v004 的逐位比對是內容契約，hash 則要能識別 source。
if (!method_exists($zip, 'setMtimeIndex')) {
    $zip->close();
    fwrite(STDERR, "ZipArchive::setMtimeIndex() is unavailable (needs libzip 1.9+); refusing to build a non-reproducible artifact.\n");
    exit(1);
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    if (!$zip->setMtimeIndex($i, YS_CART_ECPAY_RELEASE_MTIME)) {
        $zip->close();
        fwrite(STDERR, "Unable to normalise mtime for entry #{$i}.\n");
        exit(1);
    }
}

$zip->close();

echo $zipPath . PHP_EOL;
