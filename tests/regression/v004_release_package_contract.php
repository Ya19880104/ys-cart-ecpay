<?php
/**
 * v004 — 發佈包契約
 *
 * 這條測試已經是第四版；前三個缺口都讓它變成 false GREEN：
 *   1. 以 `glob + rsort` 取「現存最新」ZIP——版號推進後會驗一個陳舊的包並回報 PASS。
 *      現鎖定 plugin header 的當前版號。
 *   2. 只斷言「幾個必含 entry ＋ 一份 src/Plugin.php bytes」——手工打的 0.2.11 漏掉
 *      整個 skills/、又收進政策上排除的 CHANGELOG.md，測試仍全綠；其餘 49 個檔案
 *      也可以是任意舊版本。現改為精確集合 ＋ 全量 bytes。
 *   3. 集合比對排除了 directory entries，且以 `array_diff` 實作——`array_diff` 不看
 *      **重複次數**，尾斜線 entry 又整批被跳過，於是 duplicate file 與
 *      `ys-cart-ecpay/../escape/` 這類 traversal directory entry 都能通過。
 *      現：directory entry 納入精確集合、以排序後的完整清單比對（重複即不相等）、
 *      每一個 entry 另跑安全性檢查。
 *
 * 排除政策不再各自抄一份：builder 與本檔共用 bin/release-policy.php。本檔另對該
 * 政策的 entry 檢查跑反例（duplicate／traversal／absolute／backslash／drive letter）。
 *
 * Run: php tests/regression/v004_release_package_contract.php
 */

declare(strict_types=1);

$root = str_replace('\\', '/', dirname(__DIR__, 2));

require_once $root . '/bin/release-policy.php';

$slug = ys_cart_ecpay_release_slug();

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
        echo "  PASS  {$label}\n";
        return;
    }
    ++$fail;
    echo "  FAIL  {$label}\n";
};
$abort = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

// ── A. 政策本身的反例（先驗工具，再用工具驗產物）──────────────────────────

$unsafe = [
    'ys-cart-ecpay/../escape/'          => 'traversal directory entry',
    'ys-cart-ecpay/../escape.php'       => 'traversal file entry',
    'ys-cart-ecpay/./src/Plugin.php'    => 'dot segment',
    '/etc/passwd'                       => 'absolute path',
    'C:/Windows/win.ini'                => 'drive-letter path',
    'ys-cart-ecpay\\src\\Plugin.php'    => 'backslash separators',
    'other-plugin/src/Plugin.php'       => 'outside the plugin root',
    'ys-cart-ecpay//src/Plugin.php'     => 'empty path segment',
    'ys-cart-ecpay.php'                 => 'file at archive root',
    ''                                  => 'empty entry name',
    '/'                                 => 'slash only',
];
$unsafe_ok = true;
foreach ($unsafe as $entry => $why) {
    if (null === ys_cart_ecpay_release_entry_problem($entry)) {
        $unsafe_ok = false;
        printf("        ↳ 未被拒絕：%s（%s）\n", '' === $entry ? '(empty)' : $entry, $why);
    }
}
$assert($unsafe_ok, '(A1) entry 安全性檢查擋下 traversal／absolute／drive-letter／backslash／root 檔案');

$safe = ['ys-cart-ecpay/ys-cart-ecpay.php', 'ys-cart-ecpay/src/Plugin.php', 'ys-cart-ecpay/src/', 'ys-cart-ecpay/skills/ys-cart-ecpay-headless.md'];
$safe_ok = true;
foreach ($safe as $entry) {
    $problem = ys_cart_ecpay_release_entry_problem($entry);
    if (null !== $problem) {
        $safe_ok = false;
        printf("        ↳ 誤擋：%s（%s）\n", $entry, $problem);
    }
}
$assert($safe_ok, '(A2) 正常的檔案／目錄 entry 不被誤擋');

$assert(
    null !== ys_cart_ecpay_release_exclusion_reason('CHANGELOG.md')
    && null !== ys_cart_ecpay_release_exclusion_reason('tests/regression/v004_release_package_contract.php')
    && null !== ys_cart_ecpay_release_exclusion_reason('bin/build-release.php')
    && null !== ys_cart_ecpay_release_exclusion_reason('bin/release-policy.php')
    && null !== ys_cart_ecpay_release_exclusion_reason('docs/superpowers/plans/x.md')
    && null !== ys_cart_ecpay_release_exclusion_reason('.gitignore')
    && null === ys_cart_ecpay_release_exclusion_reason('README.md')
    && null === ys_cart_ecpay_release_exclusion_reason('docs/headless.md')
    && null === ys_cart_ecpay_release_exclusion_reason('sdk/ys-cart-ecpay-headless.js')
    && null === ys_cart_ecpay_release_exclusion_reason('skills/ys-cart-ecpay-headless.md'),
    '(A3) 排除政策：CHANGELOG／tests／bin／superpowers 排除，README／docs／SDK／skills 保留'
);

$assert(
    str_contains((string) file_get_contents($root . '/bin/build-release.php'), "require_once __DIR__ . '/release-policy.php'"),
    '(A4) builder 與本測試共用同一份 policy（不得各自抄一份排除規則）'
);

// (A5)~(A7) list-level 碰撞：逐一檢查 entry 名稱擋不住這一類——兩個名字各自都完全
// 合法，在 case-sensitive 的建置機上也能並存，解壓到 NTFS／APFS 才互相覆蓋。
$collisionFixtures = [
    'file case variant' => [
        'ys-cart-ecpay/src/Plugin.php',
        'ys-cart-ecpay/src/plugin.php',
    ],
    'directory case variant' => [
        'ys-cart-ecpay/src/',
        'ys-cart-ecpay/SRC/',
    ],
    'file vs directory of the same name' => [
        'ys-cart-ecpay/src/Plugin.php',
        'ys-cart-ecpay/src/Plugin.php/',
    ],
    'exact duplicate' => [
        'ys-cart-ecpay/src/Plugin.php',
        'ys-cart-ecpay/src/Plugin.php',
    ],
    'nested path segment case variant' => [
        'ys-cart-ecpay/src/Support/Settings.php',
        'ys-cart-ecpay/src/support/Settings.php',
    ],
    'separator variant' => [
        'ys-cart-ecpay/src/Plugin.php',
        'ys-cart-ecpay\\src\\Plugin.php',
    ],
];
$collision_ok = true;
foreach ($collisionFixtures as $why => $fixture) {
    if ([] === ys_cart_ecpay_release_collision_problems($fixture)) {
        $collision_ok = false;
        printf("        ↳ 未偵測到碰撞：%s（%s）\n", $why, implode(', ', $fixture));
    }
}
$assert($collision_ok, '(A5) 碰撞偵測涵蓋 file/dir case variant、file↔dir 同名、完全重複、路徑中段大小寫、分隔符變體');

$assert(
    [] === ys_cart_ecpay_release_collision_problems([
        'ys-cart-ecpay/src/',
        'ys-cart-ecpay/src/Plugin.php',
        'ys-cart-ecpay/src/Support/',
        'ys-cart-ecpay/src/Support/Settings.php',
        'ys-cart-ecpay/README.md',
    ]),
    '(A6) 正常的巢狀目錄＋檔案清單不被誤判為碰撞'
);

$dupProblem = ys_cart_ecpay_release_collision_problems(['ys-cart-ecpay/a.php', 'ys-cart-ecpay/a.php']);
$caseProblem = ys_cart_ecpay_release_collision_problems(['ys-cart-ecpay/a.php', 'ys-cart-ecpay/A.php']);
$assert(
    1 === count($dupProblem) && str_contains($dupProblem[0], 'duplicate entry')
    && 1 === count($caseProblem) && str_contains($caseProblem[0], 'case-insensitive collision'),
    '(A7) 完全重複與 case-fold 碰撞分別回報，訊息可辨識'
);

// ── B. 當前 source 版號 ─────────────────────────────────────────────────────

$pluginFile = $root . '/' . $slug . '.php';
if (!is_file($pluginFile)) {
    $abort("Plugin main file not found: {$pluginFile}");
}
$pluginSource = (string) file_get_contents($pluginFile);

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $pluginSource, $m)) {
    $abort("Unable to read plugin version header from {$pluginFile}");
}
$version = $m[1];

if (!preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $pluginSource, $cm)) {
    $abort("Unable to read YS_CART_ECPAY_VERSION constant from {$pluginFile}");
}
if ($cm[1] !== $version) {
    $abort("Version header ({$version}) and YS_CART_ECPAY_VERSION ({$cm[1]}) disagree in source");
}

// ── C. eligible 集合（共用政策）─────────────────────────────────────────────

$scan = ys_cart_ecpay_release_scan($root);
$assert([] === $scan['links'], '(C1) 工作目錄無 symlink（addFile 會跟隨 symlink 把目標內容打進包裡）');

if (!$scan['files']) {
    $abort('Derived an empty eligible file set — the exclusion policy or the working tree is wrong.');
}

// 交付面錨點：各自代表一整塊曾被漏掉的交付內容，單獨斷言以取得可讀的失敗訊息。
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
$missing_anchor = array_values(array_diff($anchors, $scan['files']));
$assert([] === $missing_anchor, '(C2) 交付面錨點齊全：README／docs／SDK／skills／vendor hub client（缺：' . (implode(', ', $missing_anchor) ?: '無') . '）');

$assert(!in_array('CHANGELOG.md', $scan['files'], true), '(C3) CHANGELOG.md 不隨包出貨（政策）');

// ── D. 開啟當前版號的 ZIP ───────────────────────────────────────────────────

$zipPath = $root . '/artifacts/' . $slug . '-' . $version . '.zip';
if (!is_file($zipPath)) {
    $found = glob($root . '/artifacts/' . $slug . '-*.zip') ?: [];
    fwrite(STDERR, "Release zip for the current version was not found: {$zipPath}\n");
    fwrite(STDERR, "  plugin version : {$version}\n");
    fwrite(STDERR, '  artifacts found: ' . ($found ? implode(', ', array_map('basename', $found)) : '(none)') . "\n");
    $abort('  Build the package for this version before treating the suite as a release gate.');
}

if (!class_exists('ZipArchive')) {
    $abort("ZipArchive extension is required to inspect {$zipPath}");
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    $abort("Unable to open release zip: {$zipPath}");
}

$names = [];
$symlinkEntries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    $names[] = $name;

    // ZIP 可用 unix external attributes 把 entry 標成 symlink；解壓時會建立連結而非檔案。
    $opsys = 0;
    $attr  = 0;
    if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
        && ZipArchive::OPSYS_UNIX === $opsys
        && 0xA000 === ((($attr >> 16) & 0xF000))) {
        $symlinkEntries[] = $name;
    }
}
$zipEntryCount = count($names);

// ── E. 每個 entry 的安全性 ＋ 無重複 ────────────────────────────────────────

$entry_problems = [];
foreach ($names as $name) {
    $problem = ys_cart_ecpay_release_entry_problem($name);
    if (null !== $problem) {
        $entry_problems[] = "{$name} — {$problem}";
    }
}
$assert([] === $entry_problems, '(E1) 每個 ZIP entry 都通過安全性檢查（' . (implode('; ', $entry_problems) ?: '無問題') . '）');

$duplicates = [];
foreach (array_count_values($names) as $name => $occurrences) {
    if ($occurrences > 1) {
        $duplicates[] = "{$name} ×{$occurrences}";
    }
}
$assert([] === $duplicates, '(E2) 無重複 entry（' . (implode(', ', $duplicates) ?: '無') . '）');

$assert([] === $symlinkEntries, '(E3) 無 symlink entry（' . (implode(', ', $symlinkEntries) ?: '無') . '）');

$zipCollisions = ys_cart_ecpay_release_collision_problems($names);
$assert([] === $zipCollisions, '(E4) 實際 ZIP 無 case-fold／重複碰撞（' . (implode('; ', $zipCollisions) ?: '無') . '）');

$sourceCollisions = ys_cart_ecpay_release_collision_problems(ys_cart_ecpay_release_expected_entries($scan));
$assert([] === $sourceCollisions, '(E5) eligible 來源集合本身無碰撞（' . (implode('; ', $sourceCollisions) ?: '無') . '）');

// 順序契約：碰撞判定必須早於 unlink，否則一次失敗的建置會順手毀掉上一份可用產物。
$builderSrc  = str_replace("\r\n", "\n", (string) file_get_contents($root . '/bin/build-release.php'));
$posGate     = strpos($builderSrc, 'ys_cart_ecpay_release_collision_problems');
$posEntry    = strpos($builderSrc, 'ys_cart_ecpay_release_entry_problem');
$posUnlink   = strpos($builderSrc, 'unlink($zipPath)');
$assert(
    false !== $posGate && false !== $posEntry && false !== $posUnlink
    && $posGate < $posUnlink && $posEntry < $posUnlink,
    '(E6) builder 的 entry 檢查與碰撞檢查都在 unlink 既有 ZIP 之前'
);

// ── F. 精確集合：檔案 ＋ 目錄，逐項相等（含順序無關但含重複計數）────────────

$expected = ys_cart_ecpay_release_expected_entries($scan);
$actual   = $names;
sort($actual);

if ($expected !== $actual) {
    $missing = array_values(array_diff($expected, $actual));
    $extra   = array_values(array_diff($actual, $expected));
    fwrite(STDERR, "Release zip entry set does not match the eligible source set.\n");
    fwrite(STDERR, '  expected entries : ' . count($expected) . "\n");
    fwrite(STDERR, '  zip entries      : ' . count($actual) . "\n");
    foreach ($missing as $entry) {
        fwrite(STDERR, "  MISSING from zip : {$entry}\n");
    }
    foreach ($extra as $entry) {
        fwrite(STDERR, "  UNEXPECTED in zip: {$entry}\n");
    }
    if (!$missing && !$extra) {
        fwrite(STDERR, "  (集合成員相同但清單不相等 — 存在重複 entry)\n");
    }
    $zip->close();
    $abort('  Rebuild with bin/build-release.php from this working tree.');
}
$assert(true, sprintf(
    '(F1) ZIP entry 集合與 eligible 集合完全相等（%d 檔 + %d 目錄 = %d entries）',
    count($scan['files']),
    count($scan['dirs']),
    $zipEntryCount
));

// ── G. 版號必須來自這份 source ─────────────────────────────────────────────

$zippedPlugin = $zip->getFromName($slug . '/' . $slug . '.php');
if (false === $zippedPlugin) {
    $zip->close();
    $abort("Unable to read {$slug}.php from the release zip");
}

$assert(
    1 === preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $zippedPlugin, $zm) && $zm[1] === $version
    && 1 === preg_match("/define\(\s*'YS_CART_ECPAY_VERSION'\s*,\s*'([^']+)'\s*\)/", $zippedPlugin, $zc) && $zc[1] === $version,
    "(G1) ZIP 內的 Version header 與 YS_CART_ECPAY_VERSION 都是 {$version}"
);

// ── H. 每一個 eligible 檔案逐位相同 ─────────────────────────────────────────

$mismatched = [];
foreach ($scan['files'] as $relative) {
    $entry  = $slug . '/' . $relative;
    $zipped = $zip->getFromName($entry);
    if (false === $zipped) {
        $mismatched[] = [$relative, '(unreadable in zip)', ''];
        continue;
    }
    $local = (string) file_get_contents($root . '/' . $relative);
    if ($zipped !== $local) {
        $mismatched[] = [$relative, hash('sha256', $zipped), hash('sha256', $local)];
    }
}
$zip->close();

if ($mismatched) {
    fwrite(STDERR, "Release zip contents differ from the working tree (byte-for-byte):\n");
    foreach ($mismatched as [$relative, $zipHash, $localHash]) {
        fwrite(STDERR, "  {$relative}\n    zip    : {$zipHash}\n    source : {$localHash}\n");
    }
    $abort("  The artifact was not built from this branch's current bytes.");
}
$assert(true, sprintf('(H1) %d 個 eligible 檔案與工作目錄逐位相同', count($scan['files'])));

echo "\nrelease package contract (version {$version}): {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
