<?php
/**
 * v004 — 發佈包契約
 *
 * 這條測試已經是第四版；前三個缺口都讓它變成 false GREEN：
 *   1. 以 `glob + rsort` 取「現存最新」ZIP——版號推進後會驗一個陳舊的包並回報 PASS。
 *      現鎖定 exact HEAD plugin header 的版號。
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
require_once $root . '/bin/release-source.php';

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

// 集合、版號與內容綁同一顆 HEAD；checkout 的 CRLF 和未追蹤檔案不是出貨來源。
try {
    $scan = ys_cart_ecpay_release_head($root);
    $builderSource = ys_cart_ecpay_release_git($root, ['cat-file', 'blob', $scan['head'] . ':bin/build-release.php']);
} catch (RuntimeException $error) {
    $abort($error->getMessage());
}

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

// 🔴 #2G：AI 代理／編輯器的工作目錄與 macOS 殘留檔一律不得出貨。
//
// 它們裝的是交接筆記、審查紀錄、未完成的 gate 清單——給我們自己看的東西，卻會
// 隨包散佈到每一個安裝站點的檔案系統（多半在 web root 底下）。
$agent_leak_cases = [
    '.codex/handoff.md',
    '.codex/notes/x.txt',
    '.claude/settings.json',
    '.claude/skills/y/SKILL.md',
    '.agents/reviewer.md',
    '.DS_Store',
    'src/.DS_Store',
    'docs/.DS_Store',
];
$agent_leaks = [];
foreach ($agent_leak_cases as $case) {
    if (null === ys_cart_ecpay_release_exclusion_reason($case)) {
        $agent_leaks[] = $case;
    }
}
$assert(
    [] === $agent_leaks,
    '(A8) 🔴 .codex／.claude／.agents／.DS_Store 一律排除（漏網：' . (implode(', ', $agent_leaks) ?: '無') . '）'
);

// 反例的反例：名字**看起來像**但不是的，不得被誤擋。
$assert(
    null === ys_cart_ecpay_release_exclusion_reason('docs/claude-integration.md')
    && null === ys_cart_ecpay_release_exclusion_reason('src/Support/DSStoreHelper.php')
    && null === ys_cart_ecpay_release_exclusion_reason('skills/agents-guide.md'),
    '(A9) 排除以完整路徑片段為準——名字相近的正常檔案不受影響'
);

$assert(
    str_contains($builderSource, "require_once __DIR__ . '/release-policy.php'"),
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

// ── B. exact HEAD source 版號 ───────────────────────────────────────────────

$pluginFile = $scan['head'] . ':' . $slug . '.php';
if (!isset($scan['bytes'][$slug . '.php'])) {
    $abort("Committed plugin main file not found: {$pluginFile}");
}
$pluginSource = $scan['bytes'][$slug . '.php'];

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

$assert([] === $scan['links'], '(C1) eligible HEAD entries 無 symlink 或不支援的 Git object');

if (!$scan['files']) {
    $abort('Derived an empty eligible HEAD file set — the exclusion policy or the committed tree is wrong.');
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

// 🔴 must-not-ship：內部 release runbook。它記載受控正式商店的實刷實退步驟、
// gitignored 的證據路徑，以及尚未完成的 gate——給我們自己看的作業文件，隨包散佈
// 到每一個安裝站點沒有任何好處。以 exact path 斷言而非前綴比對：docs/ 底下的
// 其他文件（headless.md）仍應出貨。檔案必須留在 repo，只是不出貨。
$mustNotShip = ['docs/credit-refund-sandbox-gate.md'];
$shipLeaks   = [];
$missingDocs = [];
foreach ($mustNotShip as $forbidden) {
    if (in_array($forbidden, $scan['files'], true)) {
        $shipLeaks[] = $forbidden;
    }
    try {
        ys_cart_ecpay_release_git($root, ['cat-file', '-e', $scan['head'] . ':' . $forbidden]);
    } catch (RuntimeException $error) {
        $missingDocs[] = $forbidden;
    }
}
$assert([] === $shipLeaks, '(C4) 內部 release runbook 不隨包出貨（' . (implode(', ', $shipLeaks) ?: '無洩漏') . '）');
$assert([] === $missingDocs, '(C5) 該 runbook 仍保留在 HEAD（排除 ≠ 刪除）');
$assert(
    null === ys_cart_ecpay_release_exclusion_reason('docs/headless.md'),
    '(C6) 排除以 exact path 為準——docs/ 底下的其他文件仍應出貨'
);

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

// 每個 entry 的 mtime 必須是固定值，artifact 的 SHA-256 才會是**內容**的函數。
// 沒有這一條，同一份 source 連續打兩次就會得到不同的 hash（目錄 entry 取「現在」、
// 檔案 entry 取 checkout 時間），「回報 hash 以證明這個包來自那個 commit」就不成立。
$stampProblems = [];
$expectedStamp = getdate(YS_CART_ECPAY_RELEASE_MTIME);
for ($i = 0; $i < $zipEntryCount; $i++) {
    $stat = $zip->statIndex($i);
    if (!is_array($stat) || !isset($stat['mtime'])) {
        $stampProblems[] = $names[$i] . ' — unreadable mtime';
        continue;
    }
    if ((int) $stat['mtime'] !== YS_CART_ECPAY_RELEASE_MTIME) {
        $stampProblems[] = $names[$i] . ' — ' . gmdate('c', (int) $stat['mtime']);
    }
}
$assert(
    [] === $stampProblems,
    '(E7) 所有 entry 的 mtime 都已正規化為固定值（' . count($stampProblems) . ' 個偏離：'
        . (implode('; ', array_slice($stampProblems, 0, 3)) ?: '無') . '）'
);

$zipCollisions = ys_cart_ecpay_release_collision_problems($names);
$assert([] === $zipCollisions, '(E4) 實際 ZIP 無 case-fold／重複碰撞（' . (implode('; ', $zipCollisions) ?: '無') . '）');

$sourceCollisions = ys_cart_ecpay_release_collision_problems(ys_cart_ecpay_release_expected_entries($scan));
$assert([] === $sourceCollisions, '(E5) eligible 來源集合本身無碰撞（' . (implode('; ', $sourceCollisions) ?: '無') . '）');

// 順序契約：碰撞判定必須早於 unlink，否則一次失敗的建置會順手毀掉上一份可用產物。
$builderSrc  = str_replace("\r\n", "\n", $builderSource);
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
    $abort('  Rebuild with bin/build-release.php from the reviewed clean HEAD.');
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

// ── H. 每一個 eligible 檔案與同一顆 HEAD snapshot 逐位相同 ──────────────────

$mismatched = [];
foreach ($scan['files'] as $relative) {
    $entry  = $slug . '/' . $relative;
    $zipped = $zip->getFromName($entry);
    if (false === $zipped) {
        $mismatched[] = [$relative, '(unreadable in zip)', ''];
        continue;
    }
    $committed = $scan['bytes'][$relative];
    if ($zipped !== $committed) {
        $mismatched[] = [$relative, hash('sha256', $zipped), hash('sha256', $committed)];
    }
}
$zip->close();

if ($mismatched) {
    fwrite(STDERR, "Release zip contents differ from the exact HEAD snapshot (byte-for-byte):\n");
    foreach ($mismatched as [$relative, $zipHash, $committedHash]) {
        fwrite(STDERR, "  {$relative}\n    zip    : {$zipHash}\n    HEAD   : {$committedHash}\n");
    }
    $abort('  The artifact does not match the pinned committed bytes; checkout EOL is not an exception.');
}
$assert(true, sprintf('(H1) %d 個 eligible 檔案與 exact HEAD snapshot 逐位相同', count($scan['files'])));

// ── I. 🔴 #2G：獨立重新讀 committed Git blob，不信任共用來源 helper 的 bytes ─
//
// (H1) 比對共用來源 helper 的 HEAD snapshot；本段另走獨立 binary-safe Git reader，
// 防止共用 helper 回傳錯誤 bytes 而與 builder 一起通過。同時拒絕測試期間 HEAD 改變。
//
// 而我們回報給審查者的是 hash：那個 hash 必須對應到一個**可以被別人重現的
// commit**，否則「這個包來自那份 commit」只是宣稱。因此比對基準是
// `git cat-file blob HEAD:<path>` 的原始位元組。
//
// 這道 gate **不會**因為環境不方便而放行：不是 git repo、git 不可用、檔案未追蹤、
// 或 artifact 內容與 HEAD 不同，全部視為未通過；工作樹 CRLF 不參與 bytes 比對。
$git_problems = [];
$git_checked  = 0;

// 🔴 #2H：**binary-safe** 讀取，不 split、不 rtrim。
//
// 舊版用 `exec()` 收 `git cat-file` 的輸出：exec 依行切開再 implode，尾端的
// 換行會被吃掉，於是最後只能 `rtrim()` 兩邊再比——那讓「ZIP 少一個結尾 LF」
// 這種真實差異變成 false-GREEN。公開套件的衛生契約要求逐位元相同，測試本身
// 就不能有這個天窗。
//
// 改用 proc_open 直接讀 stdout 的原始位元組。
$git_bytes = static function (array $args) use ($root): array {
    $cmd = 'git -C ' . escapeshellarg($root);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => 1, 'out' => '', 'err' => 'proc_open failed'];
    }

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'out' => (string) $out, 'err' => (string) $err];
};

$run_git = static function (array $args) use ($git_bytes): array {
    $r = $git_bytes($args);

    return ['code' => $r['code'], 'out' => trim($r['out'] . $r['err'])];
};

$head = $run_git(['rev-parse', 'HEAD']);
if (0 !== $head['code']) {
    $git_problems[] = 'git rev-parse HEAD 失敗（不是 git repo 或 git 不可用）：' . $head['out'];
} else {
    $head_sha = trim($head['out']);
    if ($head_sha !== $scan['head']) {
        $git_problems[] = 'HEAD changed after the committed source snapshot was captured';
    }

    // 逐檔比對 ZIP 的位元組與 HEAD 的 blob。ZIP 已在上方 close，重開一次。
    $zip2 = new ZipArchive();
    if (true !== $zip2->open($zipPath)) {
        $git_problems[] = 'ZIP 無法重新開啟以進行 Git-blob 比對';
    } else {
        foreach ($scan['files'] as $relative) {
            $blob = $git_bytes(['cat-file', 'blob', $head_sha . ':' . $relative]);
            if (0 !== $blob['code']) {
                $git_problems[] = sprintf('%s：HEAD 沒有這個檔案（未追蹤或未 commit）', $relative);
                continue;
            }

            $zipped = $zip2->getFromName($slug . '/' . $relative);
            if (false === $zipped) {
                $git_problems[] = sprintf('%s：ZIP 內讀不到', $relative);
                continue;
            }

            ++$git_checked;

            // 🔴 #2H：**逐位元**比較，不 rtrim。少一個結尾 LF 也是差異——
            // 那正是「artifact 不是從這個 commit 建出來的」最常見的形狀之一。
            if ($zipped !== $blob['out']) {
                $git_problems[] = sprintf(
                    '%s：ZIP 與 HEAD blob 不同（zip %s／%d B、blob %s／%d B）',
                    $relative,
                    substr(hash('sha256', $zipped), 0, 16),
                    strlen($zipped),
                    substr(hash('sha256', $blob['out']), 0, 16),
                    strlen($blob['out'])
                );
            }
        }
        $zip2->close();
    }
}

if ($git_problems) {
    fwrite(STDERR, "Git-blob equality gate failed:\n");
    foreach (array_slice($git_problems, 0, 30) as $problem) {
        fwrite(STDERR, "  {$problem}\n");
    }
    if (count($git_problems) > 30) {
        fwrite(STDERR, sprintf("  ...（另有 %d 項）\n", count($git_problems) - 30));
    }

    fwrite(
        STDERR,
        "  ZIP 必須與 exact HEAD blob 逐位相同；LF／CRLF checkout 都使用同一份 committed bytes。\n"
    );
}

$assert(
    [] === $git_problems,
    sprintf('(I1) 🔴 %d 個 eligible 檔案與 HEAD 的 Git blob 逐位相同（問題 %d 項）', $git_checked, count($git_problems))
);

echo "\nrelease package contract (version {$version}): {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
