<?php
/**
 * v0.5.1 門市目錄的建立時機。
 *
 * 事故（2026-09-11，客戶站）：剛裝好綠界模組的站，顧客一選門市就拿到
 * 「目前無法取得門市的伺服器權威資料」，全家與 7-ELEVEN 都下不了單。
 * 根因不是憑證也不是 API——是**時機**：門市目錄只由 twicedaily 排程建立，
 * 新站在第一次排程跑完之前目錄是空的，而低流量站的 WP cron 可能遲遲不觸發。
 * 站主在後台也看不到目錄是空的，只能從客訴才知道。
 *
 * 本契約釘住三件事：
 *   1. 站主路徑（存設定／按按鈕）會**同步**把目錄補起來。
 *   2. 顧客路徑（選店回呼）仍然**只讀快取、絕不同步出網**——這是原設計的重點，
 *      不可以為了修這個 bug 而讓顧客的請求去等外部 API。
 *   3. 設定頁把目錄現況攤開，而且只對**啟用中**的方式示警。
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
        echo "  PASS  {$label}\n";
        return;
    }
    ++$fail;
    echo "  FAIL  {$label}\n";
    if ('' !== $detail) {
        echo "        {$detail}\n";
    }
};

$read = static function (string $rel) use ($root): string {
    $path = $root . '/' . $rel;
    return is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : '';
};

/** 取出某個 static 方法的原始碼（以大括號配對，不靠正則猜結尾）。 */
$method_source = static function (string $src, string $name): string {
    if (!preg_match('/\n\t(?:public|private|protected)?\s*(?:static\s+)?function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = (int) $m[0][1];
    $open = strpos($src, '{', $start);
    if (false === $open) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $len = strlen($src); $i < $len; $i++) {
        if ('{' === $src[$i]) {
            ++$depth;
        } elseif ('}' === $src[$i]) {
            --$depth;
            if (0 === $depth) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
};

$directory = $read('src/Shipping/Ecpay/EcpayStoreDirectory.php');
$settings = $read('src/Admin/EcpaySettings.php');
$template = $read('templates/admin/ecpay-settings.php');

echo "v0.5.1 store directory bootstrap\n";

// ── 0. 防空轉：抽取器本身要可信 ──────────────────────────────
$check(
    '0a. 三個來源檔都讀得到且非空',
    '' !== $directory && '' !== $settings && '' !== $template,
    sprintf('directory=%dB settings=%dB template=%dB', strlen($directory), strlen($settings), strlen($template))
);
$probe = $method_source($directory, 'refresh');
$check(
    '0b. $method_source 取得的是完整方法（refresh 以 } 結尾且含 set_transient）',
    '' !== $probe && str_ends_with(trim($probe), '}') && str_contains($probe, 'set_transient'),
    '取到 ' . strlen($probe) . ' bytes'
);
$check(
    '0c. $method_source 對不存在的方法回空字串（不會假裝成功）',
    '' === $method_source($directory, 'this_method_does_not_exist')
);

// ── 1. 站主路徑：存設定就把目錄補起來 ────────────────────────
$handle_save = $method_source($settings, 'handle_save');
$check(
    '1a. handle_save() 取得成功',
    '' !== $handle_save,
    strlen($handle_save) . ' bytes'
);
$check(
    '1b. 物流分頁儲存成功後同步呼叫 refresh_enabled_channels()',
    str_contains($handle_save, "'shipping' === \$tab")
        && str_contains($handle_save, 'EcpayStoreDirectory::refresh_enabled_channels()')
);
$check(
    '1c. 重建結果帶回網址（站主看得到補了幾間），而不是靜默完成',
    str_contains($handle_save, "stores_built")
);

// ── 2. 手動出口：立即更新門市目錄 ────────────────────────────
$check(
    '2a. handle_save() 認得 ys_ec_ecpay_refresh_stores 這個動作',
    str_contains($handle_save, "\$_POST['ys_ec_ecpay_refresh_stores']")
);
$nonce_pos = strpos($handle_save, 'check_admin_referer');
$refresh_pos = strpos($handle_save, "\$_POST['ys_ec_ecpay_refresh_stores']");
$check(
    '2b. 這個動作在 nonce 檢查「之後」才處理（不是未驗證就出網）',
    false !== $nonce_pos && false !== $refresh_pos && $refresh_pos > $nonce_pos,
    sprintf('nonce@%s refresh@%s', var_export($nonce_pos, true), var_export($refresh_pos, true))
);
$cap_pos = strpos($handle_save, "current_user_can( 'manage_options' )");
$check(
    '2c. 權限檢查也在它之前',
    false !== $cap_pos && false !== $refresh_pos && $refresh_pos > $cap_pos
);
$check(
    '2d. 設定頁有那顆按鈕，name 與 handler 對得上',
    str_contains($template, 'name="ys_ec_ecpay_refresh_stores"')
);

// ── 3. 顧客路徑不准同步出網（原設計的重點，不可為了修 bug 而破壞）──
$cached_list = $method_source($directory, 'cached_store_list');
$lookup = $method_source($directory, 'lookup');
$check(
    '3a. cached_store_list() 只讀 transient，不呼叫 refresh()／不發 HTTP',
    '' !== $cached_list
        && str_contains($cached_list, 'get_transient')
        && !str_contains($cached_list, 'self::refresh(')
        && !str_contains($cached_list, 'wp_remote_'),
    substr(preg_replace('/\s+/', ' ', $cached_list) ?? '', 0, 160)
);
$check(
    '3b. lookup() 同樣不同步出網',
    '' !== $lookup
        && !str_contains($lookup, 'self::refresh(')
        && !str_contains($lookup, 'wp_remote_')
);

// ── 4. 目錄現況攤在設定頁上 ──────────────────────────────────
$cached_count = $method_source($directory, 'cached_count');
$check(
    '4a. EcpayStoreDirectory::cached_count() 存在且是 public static',
    '' !== $cached_count && str_contains($cached_count, 'public static function cached_count')
);
$check(
    '4b. cached_count() 只數快取（同樣不出網）',
    '' !== $cached_count
        && str_contains($cached_count, 'cached_store_list')
        && !str_contains($cached_count, 'refresh')
        && !str_contains($cached_count, 'wp_remote_')
);
$check(
    '4c. 設定頁投影帶入 store_directory_count（只對需要門市的方式）',
    str_contains($settings, "'store_directory_count'")
        && str_contains($settings, "requires_store")
);
// 🔴 這一條原本寫成「template 裡有 store_directory_count 而且有 _enabled']」——兩個字串
// 在檔案裡各自存在（後者是 toggle 的 checked），把 gate 整段拿掉仍然全綠。突變測試抓到了。
// 正解：取出**那一行 if 的條件本體**，兩個條件必須同時落在同一個條件式裡。
$gate = '';
$gate_open = strpos($template, "isset( \$method['store_directory_count'] )");
if (false !== $gate_open) {
    $gate_close = strpos($template, ') : ?>', $gate_open);
    if (false !== $gate_close) {
        $gate = substr($template, $gate_open, $gate_close - $gate_open);
    }
}
$check(
    '4d. 目錄狀態只對「啟用中」的方式顯示（沒啟用的不該喊顧客選不了門市）',
    '' !== $gate && str_contains($gate, "_enabled"),
    '' === $gate ? '找不到 store_directory_count 的顯示條件' : '條件本體：' . trim(preg_replace('/\s+/', ' ', $gate) ?? '')
);
$check(
    '4e. 目錄為空時的文案指得出後果與解法',
    str_contains($template, '門市目錄尚未建立')
        && str_contains($template, '立即更新門市目錄')
);
$check(
    '4f. 重建後是 0 間時要說出可能原因（而不是只報一個 0）',
    str_contains($template, 'stores_built')
        && str_contains($template, 'HashKey')
);

echo "\nv0.5.1 store directory bootstrap: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
