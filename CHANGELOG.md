# Changelog

本外掛所有重要變更皆記錄於此。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

## [Unreleased]

### Added

- 宣告核心版本硬性需求 `YS CART >= 2.57.0`（`Plugin::REQUIRES_CORE`）。本外掛沒有自己的 `payment_detail` 寫入器，全部走核心的 `YSPaymentDetailStore`，並依賴 `YSPaymentDispatch` 的 ambient guard 讓每一次 provider 寫入都是 owner-conditioned；兩者在 2.57.0 之前都不存在。版本不符時 `init()` 只掛 `admin_notices`，**不註冊任何 gateway、物流方式、REST 路由或 CLI**——一個註冊了卻無法安全落盤的 provider 會收到錢，比明顯缺席危險得多。
- 每一步金流動作都追加一筆**不可變**的 result event（先前只有送出前的 `sent`）。內容涵蓋人工核定需要的全部事實：`token`、`step`、`attempted`／`executed`、傳輸分類（`ok`／`rejected`／`indeterminate`）、`RtnCode`／`RtnMsg`、回應交易編號、指紋摘要與時間戳。舊版這些只存在於 log 檔，而核定的人看的是訂單。追加是冪等的（同一個 token＋階段不重複），既有事件一律不修改。

### Changed

- `done` 的冪等重放改為要求**完整證據**：`plan` 逐字元等於本次計畫、`executed === plan`、`operations` 對計畫裡的每一步都有一筆（且帶得出 token 與送出時間）、`response_trade_no` 是非空字串。任一項不成立即回 `indeterminate` 而非成功。冪等重放會讓核心結案（標記已退款、寫進帳、不再回頭核對），因此「status 是 done」這個字串遠遠不足以支撐那個結論——一筆只執行了 `E`（漏了 `N`）的 entry 先前會被當成完成。
- 冪等重放回報的交易編號**禁止 fallback**。舊版在 `response_trade_no` 為空時退回指紋內的 `trade_no`，把「綠界確認的退款交易編號」與「我們送出去的原始交易編號」混成一個值——呼叫端拿到一個看起來像退款憑據的東西，對帳時無從發現。
- 交易身分改為**typed-present**：`trade_no`、`mer_trade_no`、`ecpay_merchant_id`、`ecpay_environment`、`gwsr` 每一個都必須是非空字串，`charged_amount` 必須是正整數，而且**指紋端與當下值兩邊都要**。舊版用 `(string)` 轉型（`null`／`0`／`false` 都會變成看得下去的值），且對 `gwsr` 有一條「當下缺值不算漂移」的例外——期間有人刪掉 gwsr，比對就自動跳過而 DoAction 照送。
- `gwsr` 落盤從 best-effort 快取升為**必要條件**：寫不進去即中止退款（此時尚未動任何錢），而不是留到後續每一步 arm 時才失敗。
- QueryTradeInfo 的授權身分改為 **raw bytes 逐位元比較**。MAC 驗證可以依綠界的正規化規則——那是驗簽的需要；但「這份回應講的是不是我送出去的那一筆」不能靠正規化拉近。舊版對 `MerchantTradeNo`／`TradeNo` 先 `trim()`，於是 `" YS123"` 與 `"YS123"` 相等；綠界的交易編號不含空白，出現空白只代表回應不是我們以為的那一筆，或中間有東西改過它。
- 請款金額改用 `CoreRefundAuthorization::canonical_int()` 解析，**禁止 string-cast alias**。`(int)` 會把 `'1000abc'` 變成 1000、`'1e3'` 變成 1、超界字串飽和成 `PHP_INT_MAX`；它是退款上界的依據，也是指紋的一部分。
- 核心核定同步只接受 Core **實際**寫得出來的那一個 terminal tuple：paid → `status = provider_done` 且 `provider_done === true`；aborted → `status = aborted_provider_rejected` 且 `finalized === true`（且 `provider_done` 不得為 true）。#2F 接受了一整排狀態，其中 `submitting` 代表核定還沒完成——那時候同步等於在核心下結論之前就解除凍結。
- orphan 寫入改回 typed outcome（`written`／`core_unavailable`／`failed`）。資料庫全失敗時，log 輸出與 orphan 紀錄**同一份**完整事實（不攤平、不截斷），兩邊撈出來的形狀才對得起來。
- `wp ys-cart-ecpay refund-attempt list` 印出每一筆 operation 與 orphan 事實的**全部**欄位（未知欄位一併附上），不再挑欄位顯示。這些紀錄存在的唯一理由就是 ledger 寫不進去，這時候再砍一半等於把僅存的線索丟掉。
- 發佈包排除 `.codex`／`.claude`／`.agents`／`.DS_Store`。這些是交接筆記、審查紀錄與未完成的 gate 清單，會隨包散佈到每一個安裝站點的檔案系統（多半在 web root 底下）。v004 補上正反例。
- 正式 package gate 改為比對 **committed Git blob**，不只比工作樹。工作樹逐位相同只證明「這個包是從我現在看到的檔案打出來的」；我們回報給審查者的 hash 必須對應到一個可以被別人重現的 commit。

### Fixed

- 超商電子地圖（`/stores/ecpay/map-url`）補上購物車商品「允許的物流方式」交集驗證。先前僅檢查 provider 與該物流方式的**全域**啟用狀態，因此購物車商品只允許萊爾富時，前端仍可取得**已簽章**的 7-11 電子地圖表單；使用者選完門市、callback 也會寫入門市 session 與 `ys_ec_selected_store`，直到送單才被核心擋下。現以核心 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份共用守門驗證（fail-closed，回 `shipping_method_not_allowed`）。
- 此端點不再接受 `order_id`。該參數直接取自請求且未驗證訂單存在、擁有者或品項，任何非零值都能整段跳過上述物流限制；現直接拒收（`order_id_not_supported`）。核心 `assets/js/ys-ec-store-selector.js` 與 `sdk/ys-cart-ecpay-headless.js` 兩個呼叫端皆未使用此參數。
- 購物車讀取失敗不再被當成空購物車。核心把空車視為「不限物流」，因此先前把 handler 缺失／非陣列／例外一律轉成空陣列，會讓讀取失敗反而簽發地圖表單；現以 `null` 表示讀取失敗並 fail-closed，空陣列僅代表確定為空。
- 讀取改用核心 v2.56.4 的 `YSCartHandler::try_get_items_raw()`。舊的 `get_items_raw()` 在 `load_from_db()` 內就把「SQL 錯誤」「items 壞 JSON」「查無 row」全部抹平成 `[]`，provider 在外層無論怎麼包都救不回來；typed API 不存在時直接拒絕，不退回舊 API。
- 核心述詞缺席時改為 fail-closed。先前回 `true` 以「相容舊核心」，等於整道守門在舊核心上完全不存在；發版順序是流程約定，不能取代 runtime gate——降版、部分部署或安裝順序錯誤都會讓守門靜默消失。
- 訪客購物車的 scope 判斷只認**該 scope** 的 cookie。先前額外接受 default cookie，於是非 default scope 在該 scope 尚無購物車時仍會進入讀取路徑，觸發 `get_or_create_session()` 產生新 session cookie（純讀請求不該有此副作用），且讀到的是另一個 scope 的車。
- 發佈包契約改為鎖定 plugin header 的當前版號，並要求 ZIP **內容**與當前 source 對得上：主檔 `Version` header、`YS_CART_ECPAY_VERSION` 常數，以及 `src/Plugin.php` 的 bytes 必須逐位相同。先前以 `glob + rsort` 取現存最新 ZIP，版號推進後會驗到陳舊的包並回報 PASS；只鎖檔名也不夠，任何同名 ZIP（例如從別條分支打的同版號）都能矇混通過。
- 發佈包改為**可重現建置**：所有 entry 的 mtime 正規化為固定值。先前 artifact 的 SHA-256 是**建置時間**的函數而不是內容的函數——目錄 entry 沒有對應來源檔，`addEmptyDir()` 直接蓋上「現在」；檔案 entry 取檔案 mtime，而重新 clone 會把所有 mtime 換成 checkout 時間。同一份 source 連續打兩次就得到兩個不同的 hash（實測：同棵樹兩次建置，55 個檔案 entry 全部逐位相同，只有 30 個目錄 entry 的時間戳不同）。於是「回報 hash 以證明這個包來自那個 commit」整件事不成立，而對不上的原因與內容毫無關係。v004 新增 mtime 正規化斷言。
- 發佈包新增 **list-level 碰撞守門**（exact 與 case-fold）。逐一檢查 entry 名稱擋不住這一類：`src/Plugin.php` 與 `src/plugin.php` 各自都完全合法，在 case-sensitive 的建置機上也能並存，但解壓到 NTFS／APFS 會互相覆蓋——安裝出來的外掛少一個檔，而且哪一個留下來取決於解壓順序。比對鍵做 forward-slash 與尾斜線正規化，因此目錄與檔案的同名／大小寫變體也算碰撞。builder 在**刪除既有 ZIP 之前**判定（否則一次失敗的建置會順手毀掉上一份可用產物），v004 對 synthetic fixtures 與實際 ZIP 兩邊都跑。
- 發佈包的排除政策抽成 `bin/release-policy.php`，builder 與 v004 契約測試共用同一份（純函式、無副作用、位於被排除的 `bin/`）。先前兩邊各帶一份抄本，漂移時測試無從發現。同時把 entry 名稱的安全性檢查納入政策並在**寫入前**執行：traversal（`ys-cart-ecpay/../escape/`）、absolute／drive-letter 路徑、反斜線、空 segment、非單一根目錄、archive 根目錄下的裸檔案一律拒絕；工作目錄若含 symlink 直接停止打包（`addFile()` 會跟隨 symlink 把目標內容偷渡進包裡）。
- 發佈包契約再收緊為**精確集合＋全量 bytes**：依 `bin/build-release.php` 的排除政策從工作目錄推導 eligible 檔案集合，ZIP 的檔案 entry 必須與之完全相等，且每一個檔案都逐位相同。先前只斷言「幾個必含 entry ＋ 一份 `src/Plugin.php`」，於是手工打的 0.2.11 包漏掉整個 `skills/` 目錄、又收進政策上排除的 `CHANGELOG.md`，測試仍全綠；其餘所有檔案（gateway、`CheckMacValue`、SDK、vendor hub client）也可以是任意舊版本而不被發現。現另明確斷言 README／docs／SDK／skills 四個交付面與 CHANGELOG 的排除政策。目錄 entry 也納入精確集合、以排序後的完整清單比對（`array_diff` 不看重複次數，先前排除目錄 entry 又讓尾斜線的 traversal entry 整批溜過），並逐一驗證 entry 唯一、路徑安全、無 unix symlink 屬性。
- 物流 callback 查無訂單仍 ACK。CheckMacValue 已經驗過——這是一筆真實的綠界通知，只是此刻找不到對應訂單（建單交易尚未 commit、read replica 落後）。回 `1|OK` 會讓綠界停止重送，這筆物流狀態就永久遺失；現回 `0|Order Not Found`。同時修正 `advance_from_carrier_status()` 的回傳判定：它回的是 `array{success,…}` 而非 bool，`false === $advanced` 永遠不成立，那道「看起來有」的防護一次都沒擋過。
- 退款 reservation 改為綁定核心的**授權狀態**而不只是 entry 存在。一筆已 `finalized`、已 `provider_done`、或根本是 `record_only`（人工記帳、未經金流）的請求，其 entry 一樣存在——只驗存在等於允許對這些請求再送一次 `DoAction`。現要求 `status=submitting` 且三個旗標皆為否。
- `mark_attempt()` 的註解宣稱驗指紋，實作卻既沒收參數也沒比對。每一次 step／terminal 寫入現在都必須帶入 reservation 當時的指紋並嚴格比對；「終態名稱剛好相同」也不再直接視為冪等成功——必須連指紋一起相符，否則那可能是**另一筆交易**的 attempt。
- `executed` 只記**成功**的步驟。舊版在取得結果之前就把 step 推進陣列，於是 `E` 成功、`N` 失敗會被記成 `executed=E,N`——人工核定時看到的是「兩步都做了」，實際上 `N` 從未生效。事實分成三欄：`executed`（已完成）、`attempted_step`（送出過）、`failed_step` 與 `rtn_code`／`rtn_msg`。
- 卡別證據改為 **canonical 非負整數**才算數。舊版直接 `(int)` 轉型：`'abc'` 變 0、`'-1'` 變 -1、`'3.9'` 變 3——一個壞掉或帶負號的欄位會被讀成「沒有分期」而放行。無法解讀的證據不是「沒有證據」，是不可判定。一般信用卡另外要求**兩個**證明（非分期、且無紅利）；只有 `stage=0` 而完全沒有紅利欄位不再放行。
- 建單的 gateway identity 寫入失敗不得交付款表單（付款通知靠 `gateway_id` 決定由哪個 provider 處理），付款通知的 `gateway_trade_no` 寫入失敗不得 ACK（那是退款、對帳、客服查詢的唯一交易識別碼）。
- 物流 callback 先前會**執行時 fatal**：`EcpayLogisticsController` 用了 `OrderPaymentDetail` 與 `YSLogger` 卻沒有 import。`php -l` 只驗語法不驗符號，整條 lint 與所有既有測試都是綠的（那條路徑沒有被任何測試執行到）。新增 v019 靜態符號解析檢查，對 `src/` 每一個短名類別引用驗證它來自 `use`、同 namespace 的實際檔案，或全域類別白名單。
- 物流 callback 的其餘失敗也一併傳播：訂單純量欄位（`tracking_number`／`shipping_status`）寫入失敗、`shipping_labels` 追蹤碼同步的 SQL 失敗、以及出貨狀態機推進失敗，都不再靜默吞掉後回 `1|OK`。
- 付款資訊通知（`payment-info`）的取號結果沒落盤時不再 ACK。繳費代碼／虛擬帳號／繳費期限是消費者拿去繳費的唯一憑據，回 `1|OK` 會讓綠界停止重送，訂單頁上就永遠沒有繳費代碼。
- 分期／紅利／銀聯的判定改為**聚合所有證據來源**，並要求權威欄位在付款通知時就持久化（`stage`／`red_*`／`PaymentType`，已過 CheckMacValue 驗證）。舊版是「取第一個非空值」，於是持久化的 `stage=0` 會遮掉 `QueryTradeInfo` 回報的 `stage=3`——一筆分期交易被判成一般信用卡，然後以一般卡的規則退款。任何來源出現 positive evidence 即成立；來源互相衝突、或完全沒有 `stage`／`red_*` 欄位（v0.3.0 之前建立）一律導向人工。
- 建單金額改為 **canonical TWD 正整數**，非整數直接拒絕建單。舊寫法 `max( 1, (int) round( $order->total ) )` 會把 1000.5 送成 1001（消費者被扣 1001，訂單記著 1000.5，退款端永遠無法精確退回），也會把 0 元訂單悄悄變成 1 元交易。實際送出的金額持久化為 `ecpay_charged_amount`，付款通知的金額核對、退款的全額／部分判定與剩餘額度全部改用它。
- `E` 成功、`N` 明確失敗不再被標成可安全重試。`E`（取消關帳）已經改變綠界端狀態，標成 `failed`／`rejected_terminal` 等於告訴核心可以再送一次完整的 `E→N`——第二次的 `E` 會作用在一筆已被取消關帳的交易上。現改為維持 `pending`＋回 `indeterminate`，只能人工核定。
- `mark_attempt()` 加上 terminal ownership：CAS 內要求 entry **已存在**（只有 reservation 能建立）、仍為 `pending`（terminal 不可變）、且 `executed` 只能往前不能倒退。舊版是無條件 `array_merge`，能憑空建出一筆退款紀錄，也能把 CLI 已核定的終態抹掉並讓凍結解除。
- 指紋比對改為**型別敏感**。舊版一律轉字串／整數後比較，於是 `"1000"` 與 `1000`、`null` 與 `""` 都會被判成相符——指紋的用途正是要抓出「這不是同一筆」，用會抹平差異的比較方式等於沒有指紋。
- CLI 的核心核定同步把 gateway 歸屬、金額、原交易指紋三道核對**全部移進同一個 CAS closure**。舊版先用一份快照跑完核對、稍後才寫入，期間若有人改動 attempt 或訂單的授權資訊，我們仍會以「通過核對」的姿態寫下終態。legacy attempt 缺指紋欄位不再視為「跳過檢查」，而是「無法證明是同一筆」，一律不自動同步。
- 退款 reservation 在 CAS 內綁定核心的 `_ys_refund_finalization`：必須存在對應的請求、`gateway_id` 屬於本外掛、金額一致，且累計退款不得超過實際請款金額。先前 provider 端只憑自己的 ledger 仲裁，等於允許任何呼叫端以任意 `refund_request_id` 直接觸發退款。
- 建單時持久化環境與商店身分（`ecpay_environment`／`ecpay_merchant_id`），退款時要求一致。設定被切換之後（stage↔live、換商店代號），我們手上的憑證屬於另一個環境／另一家商店——拿它去操作這筆交易，最壞的情況是動到別家商店的同號交易。
- **原子式退款 reservation**。先前「檢查有沒有進行中的退款」讀的是方法開頭的舊快照，實際寫入 pending 卻在很久之後——兩個併發請求各自拿著自己的快照都判定「沒有 pending」，於是**都**走到 `DoAction` ＝ 退兩次款；`EcpayCreditGateway.php` 第 96／116 行查的是舊 history、第 221 行的 CAS retry 只會再插入 pending 而不重新仲裁。現在仲裁與寫入在**同一個 CAS closure** 內完成：CAS 落敗時 mutator 會拿當下最新的 ledger 重跑整段判定，因此只有一個併發請求能 reserve 成功。任何 `DoAction` 都必須在 reservation 落盤之後。
- 冪等重放與重試都要求**交易指紋相符**（金額／`TradeNo`／`MerchantTradeNo`／`gwsr`）。指紋不符代表 `refund_request_id` 撞號（不同交易共用同一個鍵），先前會直接回報成功。舊紀錄沒有指紋欄位時一律視為不符——「無法證明是同一筆」在退款這件事上必須等同於「不是」。
- 多步流程（要關帳＋全額＝`E` 後接 `N`）每一步成功後**先 durable 記錄才送下一步**。先前 `E` 成功後直接送 `N`，中途 crash 會留下「`E` 已執行但沒人知道」的狀態，人工核定無從判斷該補 `N` 還是重來。
- 終態（`done`／`failed`）寫入失敗一律回 `indeterminate`。先前 `done` 寫失敗仍回 `success` 並附註記——金流已經動了、紀錄卻沒落盤，對呼叫端宣告成功會讓核心把訂單結案，之後沒有任何機制會回來核對。
- 測試模式（stage）直接拒絕退刷。綠界官方明載 stage 無實際授權、`DoAction` 不可用；先前只有註解說明，實際上仍會把請求送到 stage endpoint 並把回應當真。
- 退刷金額改為 **canonical TWD 整數契約**。先前 `(int) round( $amount )` 會把 100.4 靜默變成 100、100.5 變成 101——送出去的金額與呼叫端要求的不是同一個數字，而這是一筆不可逆的金流動作。任何非整數在送出前拒絕，不四捨五入。
- 分期、紅利折抵、銀聯與**無法證明付款方式**的交易一律導向人工退款。這些方案的官方退款規則與一般信用卡不同（例如分期僅能全額），我們沒有權威來源，猜錯就是把錢退錯。只有能證明 `PaymentType=Credit_CreditCard` 且無分期／紅利標記才自動退刷；證據取自訂單付款紀錄與 `QueryTradeInfo` 回應。⚠️ 舊訂單若兩處都沒有標記，會被判為「無法證明」而導向人工——這是刻意的 fail-closed。
- 每日關帳時段**未**加入時段守門：本機沒有官方窗口時間的權威證據，不臆造。已列為 release gate G-8（見 `docs/credit-refund-sandbox-gate.md`）。
- `payment_detail` 的所有寫入改走 compare-and-swap（v0.3.0 起委派核心 `YSPaymentDetailStore`；`Support\OrderPaymentDetail` 僅保留為薄殼）。此欄位是單一 JSON，先前五處全走 read-modify-write 後整包覆蓋；付款通知回寫 `gwsr` 與退款 ledger `_ys_ecpay_refunds` 是同一欄位的併發 writer，重疊時後寫者會靜默蓋掉先寫者——而被蓋掉的正是「不重複退款」的唯一依據。其中信用卡閘道的 `gwsr` 回寫更是直接寫回方法開頭的舊快照，屬必然覆蓋而非競態。新寫入器分流三種 MySQL／wpdb 天性：`query()` 回 `false`（SQL 錯誤）與回 `0`（CAS 落敗）語意不同、同值 UPDATE 天生 `affected=0` 不算落敗、欄位為 SQL NULL 時 WHERE 需用 `IS NULL`。

### Added

- 信用卡退款（query-first 狀態機）：先查 `CreditDetail/QueryTrade` 關帳狀態，依綠界官方流程分流——已授權→N（僅全額）、要關帳全額→E 後接 N、要關帳部分→R、已關帳→R；狀態未知一律拒絕操作。**待受控正式商店實測**（stage DoAction 官方不可用；gate 清單見 `docs/credit-refund-sandbox-gate.md`），驗證通過前不對外宣稱支援。
- 以 core `refund_request_id` 為冪等鍵的 crash-safe 退款防護：同請求已成功→冪等重放；**傳輸不確定（timeout／非 2xx／無 RtnCode）→ 維持 pending 拒絕盲重送**（綠界端可能已生效，重試可能重複退款）；只有 provider 明確拒絕才可重試。
- 宣告 `supports_gateway_refund()`（core v2.56.4 退款能力協定）：信用卡 true、ATM／超商／條碼維持人工退款。
- 新增「信用卡查詢檢查碼」（CreditCheckCode）設定欄位（加密儲存；關帳狀態查詢必填）；建單改送 `NeedExtraPaidInfo=Y` 並於付款通知持久化授權單號（gwsr）。
- 訂單級退款凍結：只要存在任何結果未明的退款請求，一律拒絕新的退款操作直到人工核定（不依賴請求 ID 穩定性的金流層最後防線）。

## [0.2.10] - 2026-07-28

### Fixed

- Stop the bundled YS Hub Client library from registering an invalid
  WooCommerce HPOS declaration from its vendor path.
