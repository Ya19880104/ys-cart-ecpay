<?php
/**
 * v004 — 發佈包契約
 *
 * 這條測試已經是第三版；前兩個缺口都讓它變成 false GREEN：
 *   1. 原以 `glob + rsort` 取「現存最新」ZIP。版號推進後仍會去驗一個陳舊的包並回報
 *      PASS——通過與否和即將出貨的版本毫無關係。現改為鎖定 plugin header 的當前版號。
 *   2. 只鎖檔名與「幾個必含 entry ＋ 一份 src/Plugin.php bytes」仍不夠：
 *      - 少了整個 skills/ 目錄照樣 PASS（實際發生過：手工打的包漏了 skills/、
 *        又把政策上排除的 CHANGELOG.md 收了進去，測試全綠）。
 *      - 除了 src/Plugin.php 以外的任何檔案（gateway、CheckMacValue、SDK、vendor
 *        hub client…）可以是任意舊版本，測試同樣全綠。
 *
 * 本版改為**精確集合＋全量 bytes**：
 *   - 依 bin/build-release.php 的排除政策，從工作目錄推導出 eligible 檔案集合；
 *   - ZIP 內的檔案 entry 必須與該集合**完全相等**（不得多、不得少）；
 *   - 每一個 eligible 檔案的 bytes 必須與工作目錄**逐位相同**；
 *   - 另外明確斷言幾個容易被漏掉的交付面（README／docs／SDK／skills）與
 *     CHANGELOG.md 的排除政策，讓「集合對了但內容錯了」以外的失敗有可讀訊息。
 *
 * Run: php tests/regression/v004_release_package_contract.php
 */

declare(strict_types=1);

$root = str_replace('\\', '/', dirname(__DIR__, 2));
$slug = 'ys-cart-ecpay';

$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

// ── 1. 當前 source 版號 ─────────────────────────────────────────────────────

$pluginFile = $root . '/' . $slug . '.php';
if (!is_file($pluginFile)) {
    $fail("Plugin main file not found: {$pluginFile}");
}
$pluginSource = (string) file_get_contents($pluginFile);

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $pluginSource, $m)) {
    $fail("Unable to read plugin version header from {$pluginFile}");
}
$version = $m[1];

if (!preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $pluginSource, $cm)) {
    $fail("Unable to read YS_CART_ECPAY_VERSION constant from {$pluginFile}");
}
if ($cm[1] !== $version) {
    $fail("Version header ({$version}) and YS_CART_ECPAY_VERSION ({$cm[1]}) disagree in source");
}

// ── 2. 排除政策（必須與 bin/build-release.php 一致）─────────────────────────

$excludeDirs = ['.git', '.github', '.idea', '.vscode', 'artifacts', 'bin', 'node_modules', 'tests', 'tmp'];
$excludeFiles = ['.gitignore', '.env', '.env.example', 'composer.json', 'composer.lock', 'CHANGELOG.md', 'phpunit.xml'];

$isExcluded = static function (string $relative) use ($excludeDirs, $excludeFiles): bool {
    if (array_intersect(explode('/', $relative), $excludeDirs)) {
        return true;
    }
    if ('docs/superpowers' === $relative || str_starts_with($relative, 'docs/superpowers/')) {
        return true;
    }
    if (str_ends_with($relative, '.log') || str_ends_with($relative, '.tmp')) {
        return true;
    }
    if (str_starts_with(basename($relative), '.env')) {
        return true;
    }

    return in_array(basename($relative), $excludeFiles, true);
};

// ── 3. 從工作目錄推導 eligible 檔案集合 ─────────────────────────────────────

$eligible = [];
$walker = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($walker as $file) {
    $relative = str_replace('\\', '/', substr((string) $file->getPathname(), strlen($root) + 1));
    if ($isExcluded($relative)) {
        continue;
    }
    if ($file->isDir()) {
        continue;
    }
    $eligible[] = $relative;
}
sort($eligible);

if (!$eligible) {
    $fail('Derived an empty eligible file set — the exclusion policy or the working tree is wrong.');
}

// 交付面錨點：這些各自代表一整塊曾被漏掉的交付內容，單獨斷言以取得可讀的失敗訊息。
$anchors = [
    $slug . '.php',
    'index.php',
    'manifest.php',
    'README.md',
    'docs/headless.md',
    'sdk/ys-cart-ecpay-headless.js',
    'skills/ys-cart-ecpay-headless.md',
    'src/Plugin.php',
    'templates/admin/ecpay-settings.php',
    'vendor/autoload.php',
    'vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    'vendor/yangsheep/ys-plugin-hub-client/src/Updater/YSUpdateChecker.php',
];
foreach ($anchors as $anchor) {
    if (!in_array($anchor, $eligible, true)) {
        $fail("Expected deliverable is missing from the working tree: {$anchor}");
    }
}

// 政策：CHANGELOG.md 不隨包出貨（更新說明由 Hub 提供）。
if (in_array('CHANGELOG.md', $eligible, true)) {
    $fail('CHANGELOG.md must stay excluded from the release package.');
}

// ── 4. 開啟當前版號的 ZIP ───────────────────────────────────────────────────

$zipPath = $root . '/artifacts/' . $slug . '-' . $version . '.zip';
if (!is_file($zipPath)) {
    $found = glob($root . '/artifacts/' . $slug . '-*.zip') ?: [];
    fwrite(STDERR, "Release zip for the current version was not found: {$zipPath}\n");
    fwrite(STDERR, "  plugin version : {$version}\n");
    fwrite(STDERR, '  artifacts found: ' . ($found ? implode(', ', array_map('basename', $found)) : '(none)') . "\n");
    $fail('  Build the package for this version before treating the suite as a release gate.');
}

if (!class_exists('ZipArchive')) {
    $fail("ZipArchive extension is required to inspect {$zipPath}");
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    $fail("Unable to open release zip: {$zipPath}");
}

$closeAndFail = static function (ZipArchive $zip, string $message): void {
    $zip->close();
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}

// 目錄 entry（尾端 /）是 ZipArchive::addEmptyDir() 的產物，不參與集合比對，
// 但仍須通過禁用樣式檢查——洩漏的目錄同樣是洩漏。
$zipFiles = [];
foreach ($names as $entry) {
    if (!str_ends_with($entry, '/')) {
        $zipFiles[] = $entry;
    }
}
sort($zipFiles);

// ── 5. 路徑集合必須完全相等 ─────────────────────────────────────────────────

$expected = array_map(static fn(string $rel): string => $slug . '/' . $rel, $eligible);
sort($expected);

$missing = array_values(array_diff($expected, $zipFiles));
$extra   = array_values(array_diff($zipFiles, $expected));

if ($missing || $extra) {
    fwrite(STDERR, "Release zip path set does not match the eligible source set.\n");
    fwrite(STDERR, '  expected files : ' . count($expected) . "\n");
    fwrite(STDERR, '  zip files      : ' . count($zipFiles) . "\n");
    foreach ($missing as $entry) {
        fwrite(STDERR, "  MISSING from zip : {$entry}\n");
    }
    foreach ($extra as $entry) {
        fwrite(STDERR, "  UNEXPECTED in zip: {$entry}\n");
    }
    $closeAndFail($zip, '  Rebuild with bin/build-release.php from this working tree.');
}

// ── 6. 禁用樣式（含目錄 entry）────────────────────────────────────────────

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
    '#^' . preg_quote($slug, '#') . '/CHANGELOG\\.md$#',
    '#\\.log$#',
    '#\\.tmp$#',
    '#^' . preg_quote($slug, '#') . '/composer\\.(json|lock)$#',
];

foreach ($names as $entry) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $entry)) {
            $closeAndFail($zip, "Release zip includes forbidden entry: {$entry}");
        }
    }
}

// ── 7. 版號必須來自這份 source ─────────────────────────────────────────────

$zippedPlugin = $zip->getFromName($slug . '/' . $slug . '.php');
if (false === $zippedPlugin) {
    $closeAndFail($zip, "Unable to read {$slug}.php from the release zip");
}

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $zippedPlugin, $zm) || $zm[1] !== $version) {
    $closeAndFail($zip, "Release zip Version header does not match the current source ({$version})");
}

if (!preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $zippedPlugin, $zc)
    || $zc[1] !== $version) {
    $closeAndFail($zip, "Release zip YS_CART_ECPAY_VERSION does not match the current source ({$version})");
}

// ── 8. 每一個 eligible 檔案都必須逐位相同 ───────────────────────────────────

$mismatched = [];
foreach ($eligible as $relative) {
    $entry  = $slug . '/' . $relative;
    $zipped = $zip->getFromName($entry);
    if (false === $zipped) {
        $mismatched[] = [$relative, '(unreadable in zip)', ''];
        continue;
    }
    $local = (string) file_get_contents($root . '/' . $relative);
    if ($zipped !== $local) {
        $mismatched[] = [
            $relative,
            hash('sha256', $zipped),
            hash('sha256', $local),
        ];
    }
}
$zip->close();

if ($mismatched) {
    fwrite(STDERR, "Release zip contents differ from the working tree (byte-for-byte):\n");
    foreach ($mismatched as [$relative, $zipHash, $localHash]) {
        fwrite(STDERR, "  {$relative}\n    zip    : {$zipHash}\n    source : {$localHash}\n");
    }
    $fail("  The artifact was not built from this branch's current bytes.");
}

printf(
    "v004_release_package_contract passed (version %s, %d files verified byte-for-byte against the working tree)\n",
    $version,
    count($eligible)
);
