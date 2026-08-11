# Changelog

## [Unreleased]

### Fixed

- 超商電子地圖（`/stores/ecpay/map-url`）補上購物車商品「允許的物流方式」交集驗證。先前僅檢查 provider 與該物流方式的**全域**啟用狀態，因此購物車商品只允許萊爾富時，前端仍可取得**已簽章**的 7-11 電子地圖表單；使用者選完門市、callback 也會寫入門市 session 與 `ys_ec_selected_store`，直到送單才被核心擋下。現以核心 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份共用守門驗證（fail-closed，回 `shipping_method_not_allowed`）。
- 此端點不再接受 `order_id`。該參數直接取自請求且未驗證訂單存在、擁有者或品項，任何非零值都能整段跳過上述物流限制；現直接拒收（`order_id_not_supported`）。核心 `assets/js/ys-ec-store-selector.js` 與 `sdk/ys-cart-ecpay-headless.js` 兩個呼叫端皆未使用此參數。
- 購物車讀取失敗不再被當成空購物車。核心把空車視為「不限物流」，因此先前把 handler 缺失／非陣列／例外一律轉成空陣列，會讓讀取失敗反而簽發地圖表單；現以 `null` 表示讀取失敗並 fail-closed，空陣列僅代表確定為空。
- 讀取改用核心 v2.56.4 的 `YSCartHandler::try_get_items_raw()`。舊的 `get_items_raw()` 在 `load_from_db()` 內就把「SQL 錯誤」「items 壞 JSON」「查無 row」全部抹平成 `[]`，provider 在外層無論怎麼包都救不回來；typed API 不存在時直接拒絕，不退回舊 API。
- 核心述詞缺席時改為 fail-closed。先前回 `true` 以「相容舊核心」，等於整道守門在舊核心上完全不存在；發版順序是流程約定，不能取代 runtime gate——降版、部分部署或安裝順序錯誤都會讓守門靜默消失。
- 訪客購物車的 scope 判斷只認**該 scope** 的 cookie。先前額外接受 default cookie，於是非 default scope 在該 scope 尚無購物車時仍會進入讀取路徑，觸發 `get_or_create_session()` 產生新 session cookie（純讀請求不該有此副作用），且讀到的是另一個 scope 的車。
- 發佈包契約改為鎖定 plugin header 的當前版號，並要求 ZIP **內容**與當前 source 對得上：主檔 `Version` header、`YS_CART_ECPAY_VERSION` 常數，以及 `src/Plugin.php` 的 bytes 必須逐位相同。先前以 `glob + rsort` 取現存最新 ZIP，版號推進後會驗到陳舊的包並回報 PASS；只鎖檔名也不夠，任何同名 ZIP（例如從別條分支打的同版號）都能矇混通過。
- 發佈包新增 **list-level 碰撞守門**（exact 與 case-fold）。逐一檢查 entry 名稱擋不住這一類：`src/Plugin.php` 與 `src/plugin.php` 各自都完全合法，在 case-sensitive 的建置機上也能並存，但解壓到 NTFS／APFS 會互相覆蓋——安裝出來的外掛少一個檔，而且哪一個留下來取決於解壓順序。比對鍵做 forward-slash 與尾斜線正規化，因此目錄與檔案的同名／大小寫變體也算碰撞。builder 在**刪除既有 ZIP 之前**判定（否則一次失敗的建置會順手毀掉上一份可用產物），v004 對 synthetic fixtures 與實際 ZIP 兩邊都跑。
- 發佈包的排除政策抽成 `bin/release-policy.php`，builder 與 v004 契約測試共用同一份（純函式、無副作用、位於被排除的 `bin/`）。先前兩邊各帶一份抄本，漂移時測試無從發現。同時把 entry 名稱的安全性檢查納入政策並在**寫入前**執行：traversal（`ys-cart-ecpay/../escape/`）、absolute／drive-letter 路徑、反斜線、空 segment、非單一根目錄、archive 根目錄下的裸檔案一律拒絕；工作目錄若含 symlink 直接停止打包（`addFile()` 會跟隨 symlink 把目標內容偷渡進包裡）。
- 發佈包契約再收緊為**精確集合＋全量 bytes**：依 `bin/build-release.php` 的排除政策從工作目錄推導 eligible 檔案集合，ZIP 的檔案 entry 必須與之完全相等，且每一個檔案都逐位相同。先前只斷言「幾個必含 entry ＋ 一份 `src/Plugin.php`」，於是手工打的 0.2.11 包漏掉整個 `skills/` 目錄、又收進政策上排除的 `CHANGELOG.md`，測試仍全綠；其餘所有檔案（gateway、`CheckMacValue`、SDK、vendor hub client）也可以是任意舊版本而不被發現。現另明確斷言 README／docs／SDK／skills 四個交付面與 CHANGELOG 的排除政策。目錄 entry 也納入精確集合、以排序後的完整清單比對（`array_diff` 不看重複次數，先前排除目錄 entry 又讓尾斜線的 traversal entry 整批溜過），並逐一驗證 entry 唯一、路徑安全、無 unix symlink 屬性。

## [0.2.10] - 2026-07-28

### Fixed

- Stop the bundled YS Hub Client library from registering an invalid
  WooCommerce HPOS declaration from its vendor path.
