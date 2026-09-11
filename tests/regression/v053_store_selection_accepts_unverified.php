<?php
/**
 * v0.5.2 選店不再因「我方目錄查不到名稱」擋掉合法訂單。
 *
 * 事故（2026-09-11，客戶站）：顧客選完超商門市，結帳時被擋在
 * 「目前無法取得門市的伺服器權威資料，請稍後重新選擇門市。」全家與 7-ELEVEN 都下不了單。
 *
 * 根因（user 一眼看穿）：**根本不需要門市目錄**。上 wire 的只有 `CVSStoreID`
 * （建單的 `ReceiverStoreID`），它由一次性 nonce 綁在 token 裡、綠界建單時自行驗證，
 * 瀏覽器偽造不了。`store_verified` 只反映「我方目錄查不查得到這間店的**顯示名稱**」。
 *
 * 但兩條路徑對它的態度不一致：
 *   - claim_selection_authoritative（訂單成立那一刻認領）：只認 store_id，不看 store_verified。
 *   - inspect_selection_authoritative（結帳前的欄位驗證）：硬性要 store_verified===1，
 *     於是目錄一空，顧客再選一百次都被擋——因為目錄空不空跟這次選店無關。
 *
 * 修正是讓欄位驗證與訂單成立一致：有 store_id 就過，名稱缺了只影響顯示。
 *
 * 本契約以原始碼形狀釘住「那道 gate 真的移除」與「兩條路徑一致」；verified===1 的
 * 端到端（真 token／durable row）由 v035／v036 覆蓋，本契約不重造那套 harness。
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

$src = str_replace("\r\n", "\n", (string) file_get_contents($root . '/src/Shipping/Ecpay/EcpayStoreSelector.php'));

/** 取出一個 static 方法的原始碼（大括號配對）。 */
$method = static function (string $name) use ($src): string {
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

$inspect = $method('inspect_selection_authoritative');
$claim = $method('claim_selection_authoritative');

echo "v0.5.2 store selection accepts unverified\n";

// ── 0. 防空轉 ──────────────────────────────────────────────
$check(
    '0a. 兩個方法都抓得到且以 } 結尾',
    '' !== $inspect && '' !== $claim
        && str_ends_with(trim($inspect), '}') && str_ends_with(trim($claim), '}'),
    sprintf('inspect=%dB claim=%dB', strlen($inspect), strlen($claim))
);
$check(
    '0b. 抽取器對不存在的方法回空（不會假綠）',
    '' === $method('no_such_method_here')
);

// ── 1. inspect 不再把 store_verified!==1 當硬錯 ──────────────
// 只禁那道 gate 運算式本身（`… !== (int) ( $record['store_verified'] …）；
// 註解裡解釋為什麼不看 store_verified 是允許的。剝掉註解再驗程式碼。
$inspect_code = '';
foreach (token_get_all("<?php " . $inspect) as $tok) {
    if (is_array($tok)) {
        if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $inspect_code .= $tok[1];
    } else {
        $inspect_code .= $tok;
    }
}
$check(
    '1a. inspect 的程式碼（剝註解）不再用 store_verified 當 gate',
    !str_contains($inspect_code, 'store_verified'),
    '程式碼仍用到 store_verified'
);
$check(
    '1b. inspect 不再吐「無法取得門市的伺服器權威資料」',
    !str_contains($inspect, '無法取得門市的伺服器權威資料')
);

// ── 2. inspect 剩下的硬錯只有「沒有 store_id」──────────────
// 取出 inspect 裡每一處 return error 的判斷條件，逐一檢查：除了 authority reject／
// 記錄不存在，唯一因「店家資料」而錯的條件必須是 store_id 為空。
$check(
    '2a. inspect 仍會擋「store_id 為空」',
    (bool) preg_match("/''\s*===\s*\\\$store\['store_id'\]/", $inspect)
);
$check(
    '2b. inspect 不再要求 store_name／store_address 同時非空',
    !preg_match("/''\s*===\s*\\\$store\['store_name'\]\s*\|\|\s*''\s*===\s*\\\$store\['store_address'\]/", $inspect)
);

// ── 3. inspect 與 claim 一致：都以 store_id 為權威、都不看 store_verified ──
$check(
    '3a. claim 從來就不看 store_verified（回歸保護：不要在修 inspect 時反手把 claim 弄嚴）',
    '' !== $claim && !str_contains($claim, "store_verified")
);
$check(
    '3b. inspect 與 claim 都從 $record[\'store_id\'] 取店號',
    str_contains($inspect, "\$record['store_id']") && str_contains($claim, "\$record['store_id']")
);

// ── 4. 回呼端仍然「查得到就標 verified、查不到用綠界回傳值標 unverified」──
// （這是資訊層級，不是 gate；改 inspect 不該把回呼的降級行為也改掉。）
$callback = $method('handle_store_callback');
$check(
    '4a. 回呼查不到目錄時用綠界帶回的 CVSStoreName／CVSAddress（不是留空）',
    str_contains($callback, 'CVSStoreName') && str_contains($callback, 'CVSAddress')
);
$check(
    '4b. 回呼查不到時仍排一次近期補抓（目錄會自己補起來，只是不擋這次）',
    str_contains($callback, 'schedule_refresh_soon')
);

echo "\nv0.5.2 store selection accepts unverified: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
