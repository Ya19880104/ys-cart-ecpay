<?php
/**
 * 發佈包 eligibility policy — builder 與 v004 契約測試共用的**唯一**一份。
 *
 * 先前 bin/build-release.php 與 tests/regression/v004 各自帶一份排除規則的抄本，
 * 兩邊漂移時測試無從發現（實際發生過：手工打的 0.2.11 漏掉整個 skills/、又收進
 * 政策上排除的 CHANGELOG.md，而 v004 只抽驗少數 entry 故全綠）。
 *
 * 本檔為**純函式、無副作用**：require 不輸出、不寫檔、不 exit、不註冊任何 hook，
 * 因此測試可以安全載入並對它做反例驗證。
 *
 * 本檔位於 bin/，屬於被排除的目錄，不會隨包出貨。
 */

declare(strict_types=1);

/**
 * 發佈包內所有 entry 的固定 mtime（2026-01-01 00:00:00 UTC）。
 *
 * 用固定值而非建置時間，是為了讓 artifact 的 SHA-256 成為**內容**的函數。
 * 沒有它，同一份 source 連續打兩次的 hash 就不同（目錄 entry 取「現在」、檔案
 * entry 取 checkout 時間），於是「回報 hash 以證明這個包來自那份 commit」不成立。
 */
if (!defined('YS_CART_ECPAY_RELEASE_MTIME')) {
    define('YS_CART_ECPAY_RELEASE_MTIME', 1767225600);
}

if (!function_exists('ys_cart_ecpay_release_slug')) {

    /** 發佈包的根目錄名（同時是外掛 slug）。 */
    function ys_cart_ecpay_release_slug(): string
    {
        return 'ys-cart-ecpay';
    }

    /**
     * 把絕對路徑轉成相對於 $root 的 forward-slash 路徑。
     *
     * @return string 空字串＝不在 $root 之下（呼叫端應視為錯誤）
     */
    function ys_cart_ecpay_release_relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        if (!str_starts_with($path, $root . '/')) {
            return '';
        }

        return substr($path, strlen($root) + 1);
    }

    /**
     * 這個相對路徑是否被排除？
     *
     * @return string|null null＝eligible；否則為人類可讀的排除理由
     */
    function ys_cart_ecpay_release_exclusion_reason(string $relative): ?string
    {
        if ('' === $relative) {
            return 'empty path';
        }

        $excludedDirs = ['.git', '.github', '.idea', '.vscode', 'artifacts', 'bin', 'node_modules', 'tests', 'tmp'];
        $excludedFiles = ['.gitignore', '.env', '.env.example', 'composer.json', 'composer.lock', 'CHANGELOG.md', 'phpunit.xml'];

        $hit = array_intersect(explode('/', $relative), $excludedDirs);
        if ($hit) {
            return 'excluded directory: ' . reset($hit);
        }

        if ('docs/superpowers' === $relative || str_starts_with($relative, 'docs/superpowers/')) {
            return 'excluded directory: docs/superpowers';
        }

        if (str_ends_with($relative, '.log')) {
            return 'log file';
        }
        if (str_ends_with($relative, '.tmp')) {
            return 'temp file';
        }

        $base = basename($relative);
        if (str_starts_with($base, '.env')) {
            return 'env file';
        }
        if (in_array($base, $excludedFiles, true)) {
            return 'excluded file: ' . $base;
        }

        return null;
    }

    /**
     * 掃描工作目錄，取得 eligible 的檔案與目錄。
     *
     * symlink 一律**不下降、不納入**，另外回報於 links——ZipArchive::addFile() 會
     * 跟隨 symlink 把目標內容打進包裡，等於把工作目錄外的檔案偷渡進發佈包。
     *
     * @return array{files: string[], dirs: string[], links: string[]} 三者皆已排序
     */
    function ys_cart_ecpay_release_scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        $files = [];
        $dirs  = [];
        $links = [];

        $base = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator(
            $base,
            static function ($current) use ($root, &$links): bool {
                $relative = ys_cart_ecpay_release_relative($root, (string) $current->getPathname());
                if ('' === $relative || null !== ys_cart_ecpay_release_exclusion_reason($relative)) {
                    return false;
                }
                if ($current->isLink()) {
                    $links[] = $relative;
                    return false; // 不 yield 也不下降（避免跟隨到工作目錄外）
                }

                return true;
            }
        );

        foreach (new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = ys_cart_ecpay_release_relative($root, (string) $item->getPathname());
            if ('' === $relative) {
                continue;
            }
            if ($item->isDir()) {
                $dirs[] = $relative;
                continue;
            }
            $files[] = $relative;
        }

        sort($files);
        sort($dirs);
        sort($links);

        return ['files' => $files, 'dirs' => $dirs, 'links' => $links];
    }

    /**
     * 由 scan 結果推導出 ZIP 應該有的**完整** entry 集合（含目錄 entry）。
     *
     * @param array{files: string[], dirs: string[]} $scan
     * @return string[] 已排序；目錄 entry 帶尾斜線
     */
    function ys_cart_ecpay_release_expected_entries(array $scan): array
    {
        $slug = ys_cart_ecpay_release_slug();
        $entries = [];

        foreach ($scan['dirs'] as $relative) {
            $entries[] = $slug . '/' . $relative . '/';
        }
        foreach ($scan['files'] as $relative) {
            $entries[] = $slug . '/' . $relative;
        }

        sort($entries);

        return $entries;
    }

    /**
     * 單一 ZIP entry 名稱的安全性檢查。
     *
     * 集合比對本身擋不住這些：`array_diff` 不看重複次數，而尾斜線的 entry 若被當成
     * 「目錄、不必比對」排除掉，`ys-cart-ecpay/../escape/` 這種 traversal 就能整個
     * 溜過去。解壓時它會寫到外掛目錄之外。
     *
     * @return string|null null＝安全；否則為問題描述
     */
    function ys_cart_ecpay_release_entry_problem(string $entry): ?string
    {
        if ('' === $entry) {
            return 'empty entry name';
        }
        if (str_contains($entry, "\0")) {
            return 'NUL byte in entry name';
        }
        if (str_contains($entry, '\\')) {
            return 'backslash in entry name (must use forward slashes)';
        }
        if (str_starts_with($entry, '/')) {
            return 'absolute path';
        }
        if (1 === preg_match('#^[A-Za-z]:#', $entry)) {
            return 'drive-letter path';
        }

        $isDir = str_ends_with($entry, '/');
        $body  = $isDir ? substr($entry, 0, -1) : $entry;

        if ('' === $body) {
            return 'entry is only a slash';
        }

        $segments = explode('/', $body);
        foreach ($segments as $segment) {
            if ('' === $segment) {
                return 'empty path segment';
            }
            if ('.' === $segment || '..' === $segment) {
                return "traversal segment '{$segment}'";
            }
        }

        if (ys_cart_ecpay_release_slug() !== $segments[0]) {
            return 'entry is outside the plugin root directory';
        }
        if (!$isDir && 1 === count($segments)) {
            return 'file sits at the archive root';
        }

        return null;
    }

    /**
     * 碰撞比對用的正規化鍵。
     *
     * - 反斜線一律轉成斜線（entry gate 會擋掉反斜線，但本函式必須能單獨對任意清單使用）
     * - 去掉尾斜線：目錄 `src/Plugin.php/` 與檔案 `src/Plugin.php` 在解壓時是同一個名字
     * - case-fold：NTFS／APFS 預設不分大小寫
     *
     * 已知界線：不做 Unicode 正規化（NFC/NFD）。目前工作目錄全為 ASCII 路徑；
     * 若日後出現非 ASCII 檔名，這裡要再補 normalizer。
     */
    function ys_cart_ecpay_release_collision_key(string $entry): string
    {
        $key = rtrim(str_replace('\\', '/', $entry), '/');

        return function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
    }

    /**
     * list-level 碰撞檢查：完全重複，以及**不分大小寫**才會撞到的名字。
     *
     * 逐一檢查每個 entry 的名稱合法性擋不住這一類：`src/Plugin.php` 與 `src/plugin.php`
     * 兩個名字各自都完全合法，在 case-sensitive 的建置機上也能同時存在；但解壓到
     * Windows／macOS 預設檔案系統時會互相覆蓋——安裝出來的外掛少一個檔，而且是**哪一個
     * 留下來取決於解壓順序**。目錄與檔案的 case variant（`src/` vs `SRC/`）同樣算碰撞。
     *
     * @param string[] $entries
     * @return string[] 人類可讀的問題描述；空陣列＝無碰撞
     */
    function ys_cart_ecpay_release_collision_problems(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $groups[ys_cart_ecpay_release_collision_key((string) $entry)][] = (string) $entry;
        }

        $problems = [];
        foreach ($groups as $key => $members) {
            if (count($members) < 2) {
                continue;
            }

            $distinct = array_values(array_unique($members));
            if (1 === count($distinct)) {
                $problems[] = sprintf('duplicate entry ×%d: %s', count($members), $distinct[0]);
                continue;
            }

            sort($distinct);
            $problems[] = sprintf('case-insensitive collision on "%s": %s', $key, implode(' ⟷ ', $distinct));
        }

        sort($problems);

        return $problems;
    }
}
