# Changelog

## 0.5.7 - 2026-09-13

- 內嵌 YS Plugin Hub Client 同步至受審的 `2.0.6` full runtime，納入 updater、快取資料、schema readback、日誌防護與工具箱選單正規化；外掛最低 PHP 版本同步調整為 8.2。付款、設定保存、資料移轉、ECPG 訂閱與物流行為不變。

## 0.5.6 - 2026-09-13

- 修正 AIO 付款完成後返回商店顯示無效訂單：付款表單與已驗證的回傳頁共用 Core 訂單完成頁網址及訂單識別，並沿用既有訪客存取授權。金流結算與訂閱綁卡流程不變。

## 0.5.5 - 2026-09-13

- 設定頁沿用共用頁首，移除內容面板內的重複標題；設定儲存、金鑰保留、付款與物流行為不變。

## 0.5.4 - 2026-09-12（AIO／物流需 YS CART core >= 2.61.7；ECPG 需 Core >= 2.66.3；多溫層 mapping 需 Core >= 2.67.0）

### Changed

- ECPG 結算把首單綁卡納入 Core `YSPaymentEffects` durable receipt：只有 receipt effect 完成才 ACK；重播先 query 已保存 receipt，續單／custom payment 不再等待不存在的首單 subscription row，也不覆寫既有 mandate。ECPG method-level gate 要求 Core 2.66.3 的完整 receipt 與初始訂閱卡片綁定 API；AIO 與物流的全域 floor 維持 2.61.7。
- ECPG 補單／查詢走專屬 reconciler，不再誤接 AIO client；訂單已進 shipping／completed 時，vault retry 仍須 durable receipt 的綁卡 effect 完成才 ACK。
- 既有 ECPay 設定頁改由 Core Surface／Section／Field／Button 等 Partials 組合；設定來源、欄位名稱、save action、nonce、PRG 與 capability gate 不變。
- 11 個物流方法的 `temperature_layer_mapping_v1` 由同一份 shipping catalog 導出，每個方法只宣告其 exact `room`／`chilled`／`frozen` profile。能力只在載入 Core 2.67.0+ 時出現；runtime decision／digest／wire provider 均為 registered method 的 `ecpay`，manifest id `ys_ecpay` 仍只管理 lifecycle。Rolling upgrade 在舊 Core 保持 capability absent、strict-v1／AIO／物流行為不變。

## 0.5.3 - 2026-09-11（需 YS CART core >= 2.61.7）

### Removed

- 撤回 0.5.1 加的三件事：物流設定存檔時同步向綠界重抓門市目錄、設定頁的「立即更新門市目錄」按鈕、以及「門市目錄尚未建立——顧客現在選不了門市」警示（連同 `EcpayStoreDirectory::cached_count()` 與契約 `v052_store_directory_bootstrap`）。
  - 為什麼：0.5.1 是對錯誤根因的修補。0.5.2 已證明下單只需要店號，門市目錄只影響後台顯示的店名；存檔時同步出網等於每次存設定都多打一輪綠界 API、拖慢儲存，而那句警示在 0.5.2 之後是錯的——顧客選得了門市。
  - 門市目錄本身回到 0.5.0 的原設計：twicedaily 排程在背景更新、回呼查不到時排一次近期補抓，不再是任何下單關卡。

## 0.5.2 - 2026-09-11（需 YS CART core >= 2.61.7）

### Fixed

- 🔴🔴 **選完超商門市卻結不了帳**（客戶站事故，接續 0.5.1）：顧客選好門市，結帳時被擋在「目前無法取得門市的伺服器權威資料，請稍後重新選擇門市。」——再選幾次都一樣。**這才是主因，門市目錄有沒有建好其實不影響下單能力。**
  - 根因：上 wire 的只有 `CVSStoreID`（建單的 `ReceiverStoreID`），它由一次性 nonce 綁在選店 token 裡、綠界建單時自行驗證，瀏覽器偽造不了。`store_verified` 只反映「我方門市目錄查不查得到這間店的**顯示名稱**」。訂單成立那一刻的認領（`claim_selection_authoritative`）從來就只認 store_id、不看 store_verified；但結帳前的欄位驗證（`inspect_selection_authoritative`）卻硬性要 `store_verified===1`，於是目錄一空就把合法訂單擋死。
  - 修正：讓欄位驗證與訂單成立一致——**有店號就放行**，名稱缺了只影響顯示。回呼端維持原本的降級（查得到用目錄的 canonical 名稱並標 verified；查不到用綠界回呼帶回的 `CVSStoreName`／`CVSAddress` 並標 unverified），只是 unverified 不再是結帳的 gate。
  - 契約 `v053_store_selection_accepts_unverified` 釘住「gate 移除」與「inspect 與 claim 一致」；`store_verified===1` 的端到端仍由 `v035`／`v036` 覆蓋。

## 0.5.1 - 2026-09-11（需 YS CART core >= 2.61.7）

### Fixed

- 🔴 **剛裝好的站選不了超商門市**（客戶站事故）：顧客選完門市會拿到「目前無法取得門市的伺服器權威資料」，全家與 7-ELEVEN 都下不了單。根因不是憑證也不是 API，是**時機**——門市目錄只由 `twicedaily` 排程建立，新站在第一次排程跑完之前目錄是空的；低流量站的 WP cron 又可能遲遲不觸發，於是目錄一直空著。站主在後台也看不到這個狀態，只能從客訴才知道。
  - 物流分頁**儲存設定時同步重建目錄**，並把「補了幾間門市」寫在存檔後的通知裡。
  - 物流分頁新增**「立即更新門市目錄」**按鈕（走同一個 nonce 與權限檢查），給「剛裝好／剛換憑證／排程沒跑」三種情況一個手動出口。
  - 每個**啟用中**的超商方式直接顯示目錄現況：已快取幾間，或「門市目錄尚未建立——顧客現在選不了門市」。沒啟用的方式不顯示，以免誤導。
  - 重建後仍是 0 間時，通知會說出可能原因（商店代號／HashKey／HashIV 不符，或綠界後台沒對這組 MerchantID 開通該項物流）。
  - **顧客路徑不變**：選店回呼仍然只讀快取、絕不同步出網（外部 API 慢或掛掉都不該擋住結帳）。契約 `v052_store_directory_bootstrap` 逐條釘住這一點。

## 0.5.0 - 2026-09-10（需 YS CART core >= 2.61.7）

### Added

- **站內付 2.0 綁卡信用卡**（`ys_ec_ecpay_ecpg_credit`，軌 B）。綠界成為與 PayUni 同級的真 token provider：
  - 結帳時本外掛不碰綠界，只持久化穩定的交易識別（由核心 operation key 導出的 MerchantTradeNo、實際金額、流程種類、會員鍵），把顧客送到**本站網域**的託管付款頁。頁面依官方順序載入 jQuery → node-forge → 綠界 JS SDK，卡號欄位由綠界元件渲染、卡號直送綠界（官方載明無需 PCI-DSS）。
  - 訂閱訂單走「交易且綁卡」（`GetTokenbyBindingCard` → `CreateBindCard`）：一次授權既付本期又拿到 `BindCardID`，以 YSCrypto 加密存進核心卡片庫並設為該閘道的預設卡。一般訂單走純交易（`GetTokenbyTrade` → `CreatePayment`），不留卡。已登入且已綁卡的顧客可在付款頁直接用已綁定的卡付款（`CreatePaymentWithCardID`），免再輸卡號。
  - 續約由核心排程呼叫 `process_token_charge()`，以 `BindCardID` 幕後授權（`CreatePaymentWithCardID`，Need3D=0），契約與 PayUni 逐條對齊：前置拒絕（政策閘、訂單狀態、卡片權威四態、金額精確整數）、送出前把 dispatch 標成 submitted、交易識別先落盤、結果分成 success／provider_failed／indeterminate 原樣交回核心。
  - 3D 驗證用整頁導轉（綠界禁止 iframe）。結果最多從三條路進來（同步回應、OrderResultURL、ReturnURL），全部匯集到同一段冪等落地：MerchantID → MerchantTradeNo 歸屬 → RtnCode → TradeStatus → 金額（比**建單時實際送出**的金額）→ TradeNo，通過才推進已付款並存卡；第二次進來被狀態機業務拒絕時讀成「已付」而非錯誤。
  - 對綠界的回應規則：解不開／不是本店的＝400、找不到訂單＝404、已處理（含重複）＝`1|OK`、**我們自己寫不進 DB**（含綁卡存不進去——`BindCardID` 只在那份 payload 裡）＝500 不 ACK，讓綠界依其機制重送。
  - 站內付 AES-JSON 客戶端：`Data` 編解碼與官方 PHP SDK `AesService` 逐字一致（json_encode → urlencode → AES-128-CBC → Base64；以官方測試向量釘住）；信封只有 `Timestamp`（不帶發票／物流的 `Revision`）；Token／交易走 `ecpg`、查詢走 `ecpayment` 兩個 domain 不混用；逾時、HTTP 非 200、Data 解不開、RtnCode 10300066 一律判「結果不明」，絕不壓成失敗。
  - 本方式預設關閉，後台標明需為綠界特約商店並開通「站內付 2.0」與「綁定信用卡」；manifest 的 `allowed_hosts` 補上 `ecpg(-stage)`／`ecpayment(-stage)`，回呼路由 `/ecpay/ecpg/return`（S2S）與 `/ecpay/ecpg/result`（3D 導回）。
- 測試：`v055` AES 官方向量、`v056` 客戶端契約（信封／分類／送出前置）、`v057` 型錄接線＋首刷持久化＋續扣契約、`v058` 結果落地與回呼（子程序驗 ACK 規則）；`v053` 的型錄計數更新為 12。

### Changed

- **最低核心版本提高到 2.61.7**（原 2.58.0）：綁卡續扣依賴該版的 order-scoped token-charge 契約（`YSOrderScopedTokenChargeGatewayInterface`、`YSSavedCardChargePolicy` 續約閘、`YSCreditCard` token 權威、`YSPaymentDispatch` 卡片身分綁定）。舊核心上外掛照舊不註冊任何方式並顯示後台通知。
- 「交易模式」選擇器的語意收窄為**一般支付（導轉）的交易模型**；站內付 2.0 綁卡以獨立付款方式與導轉方式並存，不經過該選擇器（`ecpg_web` 全域模式仍未實作，畫面說明已更新）。
- `EcpayGatewayBase::process_payment()` 的建單識別持久化抽成 `persist_payment_identity()`，導轉與站內付共用同一段（行為與訊息不變）。
- 型錄 descriptor 新增 `transport`（`aio`／`ecpg`），`EcpayPaymentCatalog::transport()` 導出。

### Fixed

- `Plugin::REGISTERED_GATEWAY_IDS` 只列 0.4.0 之前的四個方式：只開 WebATM／分期／銀聯／Apple Pay／TWQR／微信／BNPL 時，付款回呼路由根本不會註冊，綠界通知打到 404、訂單永遠停在待付款。現在與型錄同集合（`v057` 釘住兩邊相等）。

## 0.4.1 - 2026-09-10（需 YS CART core >= 2.58.0）

### Added

- 偵測「分期落回一次付清」。綠界官方在信用卡分期付款明載：**若廠商未開通刷卡分期期數，交易會自動改為信用卡一次付清**。那是一筆看起來完全成功的失敗——`RtnCode=1`、金額與我們送出的相符、訂單正常轉為已付款，但消費者選了分期卻被一次扣完，系統裡沒有任何一處會察覺（金額核對比的是我們自己送出的金額，那個金額本來就沒變）。現在以綠界回傳的 `stage`（實際期數）比對：分期方式付款卻收到 `stage < 2` 時，於訂單付款紀錄留下 `ecpay_installment_fallback`，並寫一筆帶交易編號與實際期數的錯誤日誌。**仍然照常 ACK 與轉為已付款**——款項確實收到了，拒絕 ACK 只會讓綠界無止盡重送。綠界沒送 `stage` 時不判定：缺證據不等於證明落空。
- 設定頁說明分期手續費怎麼算。綠界導轉的期數是消費者在綠界付款頁上選的，我們送出訂單時還不知道會分幾期，**因此無法在結帳頁依期數加收手續費**。要讓消費者負擔分期成本，用綠界自己的「消費者自費分期」：訂單滿 1,000 元時綠界在付款頁提供，手續費由消費者一次付清，商家收全額；它是信用卡一次付清與分期的附加服務，預設就開著（一般前台會員無法申請關閉）。另列出綠界禁止的組合：分期不可與紅利折抵、定期定額並用，銀聯卡不支援分期，簽帳金融卡會被綠界擋下。

## 0.4.0 - 2026-09-09（需 YS CART core >= 2.58.0）

### Added

- 一般支付（導轉）補齊綠界官方 AIO 的全部付款方式，從 4 種增為 11 種。新增：**WebATM**、**信用卡分期**、**銀聯卡**、**Apple Pay**、**TWQR 行動支付**、**微信支付**、**BNPL 無卡分期**。
  - 需向綠界另外申請開通的方式（分期／銀聯／Apple Pay／TWQR／微信／BNPL）在後台各自標明開通條件，且**一律預設關閉**。沒開通就開著，消費者會走到綠界付款頁才被擋下——症狀出現在最貴的地方。
  - 升級不會自動開啟任何新方式：預設開啟的仍然只有 0.4.0 之前就在賣的信用卡／ATM／超商代碼／超商條碼。
- 新增「分期期數」設定。只接受綠界載明的期數（3、5、6、8、9、10、12、18、24、30N），存檔時正規化：未知值丟掉、重複去除、順序固定。留空代表分期不可用——不會送出空白的 `CreditInstallment` 讓綠界當成一次付清處理（那是結帳頁寫著分期、消費者卻被扣全額的無聲錯誤）。
- BNPL 依綠界規則設 3,000 元下限，金額不足時結帳頁就不顯示這個方式，而不是讓消費者選完才被綠界退回（錯誤碼 10200105）。

### Changed

- 「有哪些付款方式」收斂為單一事實來源 `EcpayPaymentCatalog`（與物流的 `EcpayShippingCatalog` 同一個做法）。先前這份清單抄在五個地方：`manifest.php`、`Plugin::register_gateways()` 的一連串 if、`Plugin::is_method_enabled()` 的 legacy map、`Settings::PAYMENT_METHOD_KEYS`，以及後台的 `PAYMENT_GATEWAY_IDS` 與標籤表。漏掉任何一份的症狀不是「方式不見了」，而是**它半開著**——後台勾得到卻註冊不進去，或 manifest 認不得因而一律判停用。現在加一個方式＝型錄加一列。
- 付款方式可帶自己的 AIO 欄位（銀聯的 `UnionPay=1`、分期的 `CreditInstallment`）。這些欄位在**簽章之前**併入，因此一定落在 CheckMacValue 範圍內；欄位名與建單欄位撞名時**拒絕建單**，不允許覆寫——`TotalAmount` 被換掉會讓實際扣款金額與我們記錄的 `charged_amount` 不一致（退款端據此判定全額／部分），`ReturnURL` 被換掉則付款通知永遠回不來。

## 0.3.4 - 2026-09-09（需 YS CART core >= 2.58.0）

### Added

- 設定頁新增「交易模式」：把綠界三種各自獨立的金流服務攤開讓商家選擇，並標明本外掛的支援狀態。
  - **一般支付（導轉）**——目前唯一可用，也是預設。消費者跳轉綠界付款頁完成付款，卡號全程不經過本站，沒有 PCI-DSS 負擔；涵蓋信用卡、ATM 虛擬帳號、超商代碼、超商條碼。畫面明確標示**不支援訂閱自動扣款**。
  - **站內付 2.0 Web（特店專用）**——尚未實作，但提供說明、綠界開通步驟與伺服器對外 IP（供綠界索取時複製）。綠界官方文件並未要求 IP 白名單，文案據實說明，不謊稱必填。
  - **定期定額（訂閱）**——規劃中，說明其為綠界端排程扣款、每期回呼通知本站。
  - 另標明「信用卡幕後授權」需 PCI-DSS SAQ-D，本外掛不支援也不規劃。
- 金流設定區依目前環境顯示交易實際送往 `payment-stage.ecpay.com.tw`（測試、不會真的扣款）或 `payment.ecpay.com.tw`（正式、會真實扣款）。

### Changed

- 尚未實作的交易模式在畫面上看得到但**不可送出**；若仍被送進來，整次儲存以 `unsupported_payment_mode` 拒絕，**不靜默降級**成一般支付——讓商家以為已切換而實際沒切，是比不給選更危險的失敗形態。資料列若被人為改成未實作的值，讀取端一律回退到一般支付。

## 0.3.3 - 2026-09-09（需 YS CART core >= 2.58.0）

### Fixed

- 儲存核對：`setting_value` 在資料表上是 nullable LONGTEXT，先前 `Settings::db_probe()` 把 NULL 當成讀取失敗而中止整次儲存（`settings_state_read_failed`）。現在依 core `get_setting()` 的既有語意把 NULL 視為「不存在」，只有真正的查詢錯誤或不可判定的型別才中止。

### Changed

- 「宅配使用的設定」只在 B2C 與 C2C 兩組都在使用時顯示；若宅配目前指向的那一組已設為「不使用」，改為展開並顯示警告提醒改選。欄位未顯示時不會送出，原本的選擇保持不變。
- 移除 0.3.2 拿掉金鑰變更閘門後殘留、已零引用的九個方法（`payment_method_is_enabled`、`unresolved_payment_attempts_state`、`sync_provider_lifecycle_verified`、`lifecycle_provider_enabled_state`、`home_method_is_enabled`、`method_is_enabled_from_db`、`setting_truthy`、`all_methods_authority_state`、`label_authority_state`）與四則已無生產者的錯誤文案，無行為變更。
- 程式註解中的「信用卡查詢檢查碼」統一改為綠界官方名稱「商家檢查碼（CreditCheckCode）」。

## 0.3.2 - 2026-09-09（需 YS CART core >= 2.58.0）

### Fixed

- 修正設定空字串被 WordPress `wpdb::get_var()` 當成不存在，導致儲存後核對誤報並還原。改以完整資料列判斷存在性，保留真正寫入失敗的回復處理。
- 更換金鑰改為頁面影響提示，不再要求先停用全部方式或清空歷史待付款／物流單；保留管理員權限、加密及寫入期間互斥。

### Changed

- B2C／宅配與 C2C 可各自選擇不使用、共用金流或分開設定；只有分開設定展開獨立金鑰與測試模式。切換來源保留原設定，舊版未明確選擇的來源維持原解析。
- 商家檢查碼移入選填的信用卡退款進階設定；一般收款與設定儲存不需要填入。

## 0.3.1 — 未單獨發布，內容隨 0.3.2 一併發布（需 YS CART core >= 2.58.0）

### Fixed

- 全數位商品不需配送時，ECPay 不再因空白物流方式攔截其他付款方式的普通結帳；沿用既有型錄歸屬判斷回傳未處理，並保留 typed context 與一次性物流 claim 的驗證。
- headless `GET /ecpay/store-result` 現在從 query string 讀取 one-time code 與 cart
  scope；先前誤用只解析 JSON／form body 的共用 parser，合法提領一律回 400。
- headless `GET /ecpay/store-result` 的參數**形狀**現在在解析身分之前就被檢查：`code` 或
  `cart_scope` 有提供但非純量、或 `code` 清理後為空，一律直接回 400，不解析 principal、
  不動一次性提領碼。先前只是把非純量「丟掉」再照常提領——而 `?code=<有效提領碼>&cart_scope[]=…`
  會讓 scope 靜默降成 `default`，對登入者與 headless 訪客而言 principal 仍然相符，於是那張
  **有效**的提領碼被畸形請求消耗掉並回 200，顧客只能重選門市。被拒絕的回應沿用提領層既有
  訊息，並維持 `Cache-Control: no-store, private`。
- 在沒有可選 PHP `mbstring` extension 的 WordPress 主機上，不再因付款、物流或退款
  字串長度限制而 fatal；共用 UTF-8 fallback 仍以完整 code point 截斷。
- 付款通知的 `SimulatePaid=1` 現在只回覆 `1|OK`，不再寫入真實交易身分或推進
  paid lifecycle。
- 回應解碼改為**逐端點**對齊綠界官方 PHP SDK，不再依「有沒有簽章」一刀切：官方指定
  `PostWithCmvVerifiedEncodedStrResponseService` 的端點（AIO `QueryTradeInfo`、國內物流
  `QueryLogisticsTradeInfo`）使用 `VerifiedEncodedStrResponse` 的 literal `+` 保留規則；
  官方指定一般 decoder 的端點（`CreditDetail/DoAction`、國內物流 `/Express/Create`）
  維持將 `+` 解成空白。
- 國內物流建單 `/Express/Create` 的回應解碼回到官方的一般 form decoder。先前一併套用
  literal `+` 保留規則，會讓回應中帶空白的簽署欄位（如 `UpdateStatusDate`）解成
  `2026/08/28+12:00:00`，CheckMacValue 因此驗不過——綠界端其實已經成立物流單，本站卻
  一律判定 `indeterminate`，超商／宅配建單等同全面停擺且不會自動重試。
- CheckMacValue 輸入與 REST callback 改為保留官方實際簽署的 decoded scalar bytes：
  不再 trim，也不再對 WordPress 已 unslash 的 body params 二次 unslash；欄位只在驗章
  成功後、寫入或顯示前才清理。
- `CreditDetail/QueryTrade/V2` 與 `DoAction` 回應改為 fail-closed 綁定請求交易身分與
  金額；缺失或錯筆回應維持 indeterminate，不允許盲重送不可逆退款。綁定的欄位依綠界
  官方現行文件（QueryTrade/V2：成功時 `RtnMsg` 為空、`RtnValue.TradeID`／`amount`／
  `close_data`；DoAction：URL-encoded 的 `MerchantID`／`MerchantTradeNo`／`TradeNo`／
  `RtnCode`／`RtnMsg`）。真實退款 action 的結果、狀態流轉與重試行為仍為
  live-deferred——綠界測試環境不提供 DoAction，且自動退款預設關閉。
- 物流 callback 改用 typed replay reservation 與 stable event id；所有 provider
  projections 與 replay commit 完成後才發布 shipping pipeline hook，寫入失敗時零 hook。
  已完成 replay 保留四天，涵蓋綠界官方三天重送窗口並多留一天緩衝。
- 電子地圖回跳 URL 在加入 one-time result code 前不再做 display-context escape，保留
  原本的多個 query parameters。

### Changed

- 🔴 **`cart_scope` 成為 canonical ABI（破壞性、刻意 fail-closed）**。它必須**原樣**符合
  `/^[a-z0-9_]{1,32}$/`，或整個省略；**只有完全未提供才會套用 `default`**。伺服器不再
  正規化、也不再降級：`HEADLESS_1` 不會被當成 `headless_1`，`my-scope`、`!!!`、空字串、
  `null`、陣列、超過 32 字元一律回 400。
  三條 public boundary 一致：`POST /stores/ecpay/map-url`（`invalid_cart_scope`）、
  `GET /ecpay/store-result`（`store_result_invalid`）、`POST /stores/ecpay/reauthorize`
  （`invalid_saved_store_request`），且都在解析 principal、開 map session、鑄 selection
  token 或消耗一次性提領碼**之前**就拒絕。
  之所以不能「先正規化再用」：`current_principal()` 對登入者會在讀 scope 之前就回
  `'u:<id>'`，headless 的 `X-YS-Guest-Token` 分支也與 scope 無關——正規化等於用**另一個
  scope 的身分**去動敏感資源。判準集中在 `src/Support/CartScope.php`（取代先前三份各自
  漂移的 `sanitize_cart_scope()` 副本），headless SDK 送出前套用同一條規則。
  **升級注意**：送非 canonical scope 的呼叫端會開始收到 400，而不再被靜默改綁。
- `POST /stores/ecpay/map-url` 現在在任何 string cast 之前檢查參數形狀。先前
  `{"cart_scope":["x"]}` 會發 array-to-string warning 並產生一個合法但錯誤的 scope；
  `return_url` 送物件更會是未捕捉的 fatal。畸形請求也不再消耗呼叫端真正的節流配額。
- `POST /stores/ecpay/reauthorize` 的欄位存在性判斷由 `isset()` 改為 `array_key_exists()`，
  `null` 因此被正確視為「有提供但形狀錯」；形狀檢查同時上移到任何資料庫讀取之前
  （認證仍排第一）。
- 管理端列印 `admin_post_ys_cart_ecpay_print` 現在先驗 key 的**確切格式**
  （`/^[A-Za-z0-9]{24}$/`，即鑄造端 `wp_generate_password( 24, false, false )` 的形狀），
  再碰任何 transient 或送出任何 header。`?key[]=x` 不再觸發 array-to-string warning，也不再
  以捏造的識別碼做 transient 讀取與刪除；錯誤訊息維持既有那一句，不洩漏 transient 識別資訊。
- 公開的 `GET /ecpay/store-result` 的守門順序成為契約：**形狀 → 身分 → 計量 → 提領**。
  `code` 必須**原樣**符合鑄造格式 `/^[A-Za-z0-9]{32}$/`（callback 簽發的確切形狀）——任何
  其他非空值先前仍會解析身分、花掉共享限流配額、再進提領層做一次必然失敗的 transient
  讀取；現在一律在最前面以 generic 400 拒絕，零身分、零計量、零提領。解析不出 principal
  的請求（匿名且無 guest token）同樣拿 generic 400 且**不計量**——Core 的 `get_client_ip()`
  在 CDN／反向代理後全站共用一個 IP bucket，替辨識不出的呼叫端計量等於讓匿名垃圾流量
  阻塞正常 claim。
- 上述節流複用 Core 既有的 `YSRateLimiter`，改為雙 bucket、與姊妹端點 `ecpay_map_url()`
  同構。第一個 bucket 的 action 名稱由 principal 的 SHA-256 導出（12/60）——外部輸入
  **不能直接注入** action 名稱（恆為固定前綴＋hash 導出後綴）；而 Core 的 `check()` 會再把
  client IP 拼進儲存 key，因此它實際是 **per-(principal, IP)** 的細粒度上限，不是跨 IP 的
  單一 actor cap，且訪客可藉輪換 guest token 換得新 bucket。**該 IP 的整體 cap 由保留的
  per-IP bucket 承擔**（`ecpay_store_result_ip`，60/60）；**不另建**任何平行儲存。
  429 之後**不提領**。限流器不可用時 fail-safe 為放行，並由 v031 在一個真的沒有該類別的
  子程序中證明。
- headless 文件與 README 的 SDK 驗證宣稱**縮限至實際範圍**：只有 `requestStoreMapForm()`
  與 `claimStoreResult()` 兩個高階 helper 在送出前驗證 `cart_scope`（共用同一個 validator）；
  legacy raw helper（`requestMapForm` 等）為 ABI 相容原樣送出、由伺服器 fail-closed。
  `docs/headless.md` 新增 SDK helper 驗證範圍表。
- 最低核心版本提高為 **YS CART 2.58.0**，並 fail-closed 探測 order serialization、
  typed replay reservation／token／commit／release、五參數 shipping pipeline advance
  與 deferred hook capability；舊 gate transient namespace 同步升版，避免沿用先前的
  false-green cache。

### Tests

- 新增付款模擬通知、物流 callback durability／replay 狀態機、ECPay 回應身分與 parser、
  電子地圖 multi-query return URL 的可執行 regression，並補強 raw signed REST bytes 與
  Core 2.58 capability gate oracle；另以停用所有 php.ini extensions 的子程序驗證
  `mbstring` fallback，並覆蓋 headless store-result 的 query-only ownership claim。
- 新增 requester 邊界的物流回應 decoder 路由契約（`v030`）：以**互斥**的一對線上形狀
  同時釘住 `/Express/Create` 用一般 decoder、`/Helper/QueryLogisticsTradeInfo` 用
  verified decoder，任一條被換成另一個 decoder 就會變紅；同時保留 transport-error、
  numeric-prefix、identity 與必填欄位的既有 fail-closed oracle。
- `v029` 增補 headless store-result 的參數形狀契約，並改為量測 **principal／claim 的呼叫
  次數**而不是引數值——只斷言引數的話，「被呼叫但引數是空字串」與「根本沒被呼叫」完全同形。
  涵蓋 array code、array scope、兩者皆 array、缺 code、空 code、whitespace-only code、
  `null`（釘住 `array_key_exists` 而非 `isset`）與 `code='0'`（釘住 `'' ===` 而非 `empty()`）。
  另新增 **route custody**：捕捉 `register_rest_route()` 的 exact config，釘住這條路由不得
  宣告 `args`／`sanitize_callback`（否則 WP 會在 handler 之前把陣列洗成純量 `''`，形狀閘門
  永遠不觸發、而行為測試看不出來），並釘住前置拒絕的 exact error code／message 與 no-store。
- 新增 `v031`（`cart_scope` canonical ABI，三條 public boundary ＋ 節流契約，含在一個真的
  沒有 `YSRateLimiter` 的子程序中證明 fail-safe）與 `v032`（列印 key 的 exact 形狀閘門）。
- `v013` 增補 SDK／文件的 canonical validator custody：釘住 SDK 只有**一份** pattern、map 與
  claim 兩條路徑共用同一個 validator、pattern 與伺服器端 `CartScope` 逐字相同，並實際以
  Node 執行 SDK 驗證兩條路徑都在**發出任何請求之前**就 reject。
- 修復 `v037`／`v039` 在**自己的 pin 上**就是 fatal 的手動 require 鏈：Core 2.59.4 併入
  Core 2.61 之後，`YSFulfillmentSnapshotService` 改以 `YSShippingMethodId::normalize()`
  正規化 `method_id`，而這兩支測試沒有 autoloader、也沒有 require 那個類別，於是
  `Class ... not found` 直接中止（連帶讓驅動它們的 `v040` 全紅）。補上 require 之後
  v037 14/0、v039 16/0、v040 10/0。
  同時把這兩個路徑納入 `SubscriptionProductSqlFixture::ALLOWED_DESCENDANT_PATHS`——
  該 allowlist 把 ECPay 樹鎖在 pin 上，不擴充就沒辦法修一支在 pin 上本來就紅的測試，
  而 allowlist 檔案本身就在允許清單內，因此這是它自己規則容許的擴充。

## 0.3.0 - 2026-08-17（信用卡退款；需 YS CART core >= 2.57.0）

> 本節僅列**相對已發布 0.2.16** 的新內容。0.3.0 開發線早期實作的 C2C／低溫／
> 貨到付款、電子地圖購物車守門、durable callbacks 與可重現發佈包等，已由
> 0.2.12–0.2.16 先行出貨（見下方各節），不在此重列；其中 `UNIMARTFREEZEC2C`
> （7-ELEVEN 冷凍 C2C）依官方型錄審查**移除**——綠界目前僅載明冷凍 B2C，
> 冷凍 C2C 需綠界書面確認後才會加入。

### Added

- **信用卡退款（query-first 狀態機）**：先查 `CreditDetail/QueryTrade` 關帳狀態，
  依綠界官方流程分流——已授權→N（僅全額）、要關帳全額→E 後接 N、要關帳部分→R、
  已關帳→R；狀態未知一律拒絕操作。**待受控正式商店實測**（stage DoAction 官方
  不可用；gate 清單見 `docs/credit-refund-sandbox-gate.md`），驗證通過前不對外
  宣稱支援。
- 以 core `refund_request_id` 為冪等鍵的 crash-safe 退款防護：同請求已成功→冪等
  重放（要求完整證據：plan 逐字元相等、executed === plan、每步 operation 齊備、
  非空 `response_trade_no`；禁止 fallback 到原交易編號）；**傳輸不確定（timeout／
  非 2xx／無 RtnCode）→ 維持 pending 拒絕盲重送**；只有 provider 明確拒絕才可重試。
- **原子式退款 reservation**：仲裁與寫入在同一個 CAS closure 內完成（CAS 落敗時
  mutator 以當下最新 ledger 重跑判定），只有一個併發請求能 reserve 成功；任何
  `DoAction` 都必須在 reservation 落盤之後。reservation 綁定核心
  `_ys_refund_finalization`（請求存在、gateway 歸屬、金額一致、累計不超過實際
  請款金額）與核心授權狀態（`status=submitting` 且未 finalized／未 provider_done／
  非 record_only）。
- 交易指紋（金額／`TradeNo`／`MerchantTradeNo`／`gwsr`／環境／商店代號）
  **typed-present** 且型別敏感比對；每一步寫入在 CAS 內重驗；舊紀錄缺指紋一律
  視為「無法證明是同一筆」＝不是。多步流程（E→N）每步成功先 durable 記錄才送
  下一步；終態寫入失敗回 `indeterminate`（不宣告成功）。
- 每一步金流動作追加**不可變 result event**（token、step、attempted／executed、
  傳輸分類、RtnCode／RtnMsg、回應交易編號、指紋摘要、時間戳），append-only 冪等。
- 訂單級退款凍結：存在任何結果未明的退款請求即拒絕新退款操作，直到人工核定。
- `wp ys-cart-ecpay refund-attempt` CLI（list／resolve；resolve 為真 CAS conditional
  UPDATE），與核心 finalization 人工核定的自動同步（gateway 歸屬＋金額＋原交易
  指紋三道核對全在同一 CAS closure；terminal tuple 嚴格比對）。
- 宣告 `supports_gateway_refund()`（core 退款能力協定）：信用卡 true、ATM／超商／
  條碼維持人工。**自動退款預設關閉**（`ys_ec_ecpay_auto_refund_enabled` 於 gate
  完成後明確開啟；0.3.0 維持 record-only／manual-only）。
- 新增「信用卡查詢檢查碼」（CreditCheckCode）設定欄位（加密儲存、與 payment
  憑證同一原子管線與清空語意；不參與 CheckMacValue 簽章故**不屬於 signer
  snapshot**）；建單改送 `NeedExtraPaidInfo=Y` 並於付款通知持久化授權單號（gwsr；
  落盤為必要條件，寫不進去即中止後續退款路徑）。
- 分期／紅利／銀聯／無法證明付款方式的交易一律導向人工退款（聚合所有證據來源、
  canonical 非負整數才算數、來源衝突或無欄位＝不可判定 fail-closed）。
- 測試模式（stage）直接拒絕退刷（綠界官方明載 stage 無實際授權、DoAction 不可用）。

### Changed（core 2.57.0 配對）

- 宣告核心硬性需求 **YS CART >= 2.57.0**（`YS_CART_ECPAY_REQUIRES_CORE`）；
  `core_requirements()` 能力探測新增 `YSPaymentDetailStore`（共用 CAS）與
  `YSPaymentDispatch::current_operation_key`。版本／能力不符時 bootstrap 只掛
  admin notice，不註冊任何 gateway、物流、REST 路由或 CLI；核心整個缺席也改為
  notice-only（不再靜默）。
- `payment_detail` 的所有寫入**委派核心共用 CAS（`YSPaymentDetailStore`）**；
  `Support\OrderPaymentDetail` 僅保留為薄殼（核心服務缺席＝一律失敗，絕不退回
  provider 私有寫入器）。物流 callback 的 shipping 投影同步改走此 CAS——付款
  通知、退款 ledger、物流投影是同一個 JSON 欄位的併發 writer。
- `MerchantTradeNo` 改由核心**穩定 operation key** 導出（order_id／attempt 世代／
  nonce 的函數；續作沿用已記錄的編號），不再是 `'YS' . $order_id . 'T' . time()`；
  缺 dispatch context 回空字串並中止建單。建單回傳 typed outcome
  （`rejected_terminal`／`provider_unavailable`＋`retryable`）。
- 建單金額 **canonical TWD 正整數**（非整數拒絕、不四捨五入）；實際送出金額持久化
  為 `ecpay_charged_amount`，退款全額／部分判定與剩餘額度全部改用它；退刷金額同
  契約。建單同時持久化環境與商店身分（`ecpay_environment`／`ecpay_merchant_id`），
  退款時要求一致。
- 建單 gateway identity（`gateway_id`／`payment_method`）寫入失敗不得交付款表單；
  `QueryTradeInfo` 授權身分 raw bytes 逐位比對；請款金額以
  `CoreRefundAuthorization::canonical_int()` 解析（禁止 string-cast alias）。
- 退款出網入口（`CreditDetail/QueryTrade`／`DoAction`）納入 0.2.16 的 **reader
  lease＋pre-send fence** 體系（與建單表單、QueryTradeInfo、物流出網同級）。

### Tests

- v015／v017／v018／v020／v021（退款契約全套）、v019（靜態符號解析：`src/` 短名
  類別引用必須來自 use／同 namespace 檔案／全域白名單）、v004 Git-blob 比對改用
  binary-safe 讀取（`proc_open`，不 split、不 `rtrim`）。

## 0.2.16 - 2026-08-16（已發布）

### 金流簽章納入權威＋讀寫真互斥（R14）

- **金流（payment）簽章來源納入同一 authority**：變更金流 MerchantID／HashKey／
  HashIV／環境時，必須（a）停用全部綠界付款方式（已簽出的結帳表單以舊憑證
  送回會失驗）（b）沒有進行中的綠界付款訂單（pending 訂單的回呼與查詢都按
  當下憑證驗章，變更會使其無法收斂）（c）狀態查詢成功——否則儲存不套用。
- 設定 commit 與**所有**簽章／驗章操作（付款表單、付款與物流回呼、物流建單／
  取消／查詢、電子地圖、門市清單、託運單列印）改為 reader/writer **真互斥**：
  出網操作全程持 reader lease、送出前 own-row fence；設定儲存讓步給進行中的
  操作。前次儲存程序中斷（crash）時，所有出網操作暫停並於後台顯示警示，
  重新儲存成功即恢復——不會有任何請求以「寫到一半」的憑證出網。
- provider 啟用開關的核心鏡像同步納入原子範圍：同步後讀回驗證，失敗連同本次
  全部設定一起還原。

### 設定儲存原子化與出網互斥（R13）

- api tab（provider 開關／宅配憑證來源／重用開關／三組憑證）改**單一原子管線**：
  先計算 desired state（零寫入）→ provider 維護鎖內驗 gate → 逐鍵 commit＋DB
  readback。任何會改變 effective 簽章來源的變更（含金流 key 旋轉於重用生效中、
  清除使用中的憑證組、宅配憑證來源切換）共用同一 gate：全部物流方式停用＋無
  未結束／升級前物流單＋狀態查詢成功。被 gate 拒絕的儲存**連一個暫時值都不曾
  寫入**——不存在「寫入→驗證→回滾」期間並行建單讀到暫時簽章出網的窗口。
- 物流建單／取消／查詢／列印在送出前檢查同一把維護鎖：設定 commit 進行中一律
  拒送（未送出任何請求，稍後重試即可）。
- 寫入失敗的回滾以 {存在性, 值} 恢復每一鍵：原本不存在的設定列以刪除恢復
  「不存在」態（不是寫空字串），test_mode 的預設語意不被汙染；回滾全量掃描
  不因單鍵失敗中斷，結束後以簽章快照驗證回到儲存前狀態，失敗誠實回報。
- 憑證環境判定硬化：正式環境只在 test_mode 顯式為 '0' 時成立，缺值／空字串
  一律視為測試環境（環境不明時打測試端點，錯誤大聲可見）。

### 重用金流憑證（顯式模式）

- 物流簽章可重用金流憑證：綠界商家通常金流物流共用同一組 MerchantID／HashKey／
  HashIV，新開關啟用後，**所有物流憑證欄位（含舊版單一憑證）全部留空**時，
  物流建單／選店／查詢／回呼即以金流 tuple 簽章，環境（測試／正式）以金流為單一
  真相。任一物流欄位有值（含 partial）本模式不生效，仍走該組原有規則；開關開啟
  但金流 tuple 不完整一律 fail-closed。與宅配憑證來源（HOME family）正交。
- 切換本開關是**維護操作**：存在未結束或升級前的物流單、或狀態查詢失敗時拒絕
  切換（比照宅配憑證來源守門）；開關寫入後 readback 驗證。
- 各物流憑證組新增「清除此組憑證」：勾選存檔即整組清空（原「secret 空白＝保留」
  慣例讓已填過的站永遠清不空、進不了重用模式）。

## 0.2.15（已發布）

### 宅配憑證能力

- HOME（黑貓／郵局）不再硬綁 B2C 測試帳號分組。後台新增明確的「宅配憑證來源」：
  預設沿用 B2C／宅配憑證，也可依綠界後台該 MerchantID 的實際開通能力改用 C2C
  憑證。同一個 C2C profile 因此可以合法服務 C2C 超取與 HOME，不需要把同一組
  MID／HashKey／HashIV 假裝成兩套憑證。
- 只有「尚未建立 profile option」的升級站沿用既有 B2C／宅配預設；已儲存的非法值
  fail closed，舊版表單缺少新欄位時保留現值，不會靜默切換 signer。
- 切換 profile 前必須先停用全部 HOME 方法，且不得有 active authority 或無 attempt 的
  legacy HOME label；狀態查詢失敗時拒絕切換，避免舊單 callback／query／print 失驗。
  partial credential、跨 profile 相同 tuple 與 exact method identity 的既有守門維持不變。

## 0.2.14（已發布）

### 配對契約與安全性

- 最低核心版本提升至 **YS CART 2.56.12**。啟動時會同時驗證物流 authority、
  storefront 查詢、地址 `shipping_provider` schema、付款 reconciliation 與安全加密能力；
  部分部署不掛 provider hooks，也不會以明文保存密鑰。
- B2C／預設宅配與 C2C 改用兩組獨立物流憑證；所有建單、電子地圖、callback、查詢、列印、
  取消與門市目錄都依 exact method/channel 選帳號，簽章 tuple 相同或交叉替換時 fail closed。
- 結帳改走 typed fulfillment resolve/claim：canonical destination、服務條件與 immutable
  snapshot 由 Core 在同一次訂單交易落盤，provider claim 不再事後覆寫訂單。
- 已存 CVS 地址改為登入擁有者 + exact provider/method 的 server-side reauthorization；
  canonical directory miss 會排補抓並回 409，每次只簽 fresh one-use token，response 為 no-store。

### 物流 API

- 依官方 11 種方式更新 create/print 契約：UNIMARTFREEZE 支援 COD；TCAT 溫層條件；
  POST 重量；FAMI／UNIMART／HILIFE C2C 等長批次（上限 100），缺欄、逗號注入、混 method
  或 mixed subtype 均整批拒絕。
- 新增簽章與 durable identity 全綁定的 QueryLogisticsTradeInfo/V5；取消 API 僅允許
  exact UNIMARTC2C，且只有 `1|OK` 是 terminal cancelled。
- 物流狀態採官方狀態表的單一映射；未知與退回中的非終態不猜。query 與 webhook 都在
  order serialization 內重驗 current active label，已取消／被替換的舊 label 只 ACK/no-op。
- 門市目錄快取 key 綁定 credential family、環境與 signer identity；stage→live 或帳號輪替
  立即 miss，stage dummy store 不會沿用到正式環境。

### 金流 authority

- QueryTradeInfo/V5 回應必須驗 CheckMacValue、MerchantID、MerchantTradeNo；paid 還要求
  TradeNo 與正整數 TradeAmt。缺欄或金額不符保持 frozen，零 lifecycle transition。
- 送出付款表單前，MerchantTradeNo/gateway/payment identity 必須 durable exact readback；
  明確 pre-send 拒絕與 provider-effect 後不確定結果使用不同 typed outcome。

## 0.2.13（未發布）

### 修正（stage 實測驅動；FINDINGS-STAGE-2026-08-13）

- **門市目錄解得動真實的 GetStoreList 形狀**。綠界的回應是
  `StoreList[].{CvsType, StoreInfo[]}` 兩層巢狀（stage 實測），0.2.12 的
  parser 把群組元素當門市列 → 永遠解出空清單 → 所有選店恆為
  `store_verified=0`。攤平巢狀後 canonical 名稱地址開始生效；並新增
  「RtnCode!=1 不得快取空清單」守門。
- **數字錯誤碼開頭的同步拒絕歸為明確拒絕**。官方的同步拒絕除了
  `0|ErrorMessage` 還有 `10500040|商品金額範圍為1~20000元` 這種
  **錯誤碼開頭**的形狀（stage 實測）。0.2.12 只認 `0|`，錯誤碼開頭的會
  落到 indeterminate——安全但把常見欄位錯誤都變成人工裁決。現在
  成功只有 `1|`，其餘數字前綴＝provider_failed；完全認不出的形狀
  仍是 indeterminate。
- **門市目錄接上 production**（此前只有測試會呼叫 `refresh()`，安裝後快取
  永遠不會建立）：`register_cron()` 掛 twicedaily 排程（首跑 +60 秒）；
  選店查不到 canonical 時排 60 秒後的單發補抓——單發用**獨立 hook**
  （`…_soon`）去重，與週期事件共用 hook 會被 `wp_next_scheduled()`
  永遠擋住，補抓一次都排不進去。
- **GetStoreList 請求補簽 `CheckMacValue`**（官方契約必填；先前 stage 實測
  通過是測試腳本自己簽的，production 未簽正式環境會被打回）。
- **通路綁定**：多通路回應只攤平 `CvsType` 等於請求通路的群組。不同通路
  可能有同號門市，不綁定會把別家通路的門市名掛在這家的號碼上並標成
  canonical。
- **快取寫入以「寫後讀回逐位比對」為準**：`set_transient()` 回傳值不可靠
  （值相同回 false），只比筆數又會被「舊快取剛好同筆數」騙過——寫入失敗
  時 `refresh()` 回 0，不把殘留的舊門市冒充成新目錄。
- **總開關 gating**：provider 整個停用後，目錄排程不再對綠界發任何 HTTP
  （`refresh_enabled_channels()` 改走「總開關 × 方法旗標」的合成 gate；
  殘留的方法旗標不再足以觸發請求），`register_cron()` 在停用時同時清掉
  既有排程，停用的站不會一直醒來。
- 修 `HttpFormClient::parse_body()` 對 JSON 回應丟 PHP warning 的根因
  （JSON body 早退＋scalar-safe 映射，附零 warning 常設守門測試）。

## [0.2.12] - 2026-08-13

物流專用版（shipping-only），自 `v0.2.10` 重新實作。**不含**信用卡退刷、退款授權、
CLI 退款、payment detail CAS，也不要求核心 2.57.0——搭配核心 2.56.9 即可。

（`0.2.11` 已被 map-only 分支占用，故版號直接進到 `0.2.12`。）

### Added

- **11 個獨立的物流方式**，每一個都有自己的 `method_id`、啟用開關、運費、免運門檻
  與 wire 欄位：全家（B2C／C2C）、7-ELEVEN（B2C／C2C）、萊爾富（B2C／C2C）、
  7-ELEVEN 冷凍（B2C）、黑貓宅配常溫／冷藏／冷凍、中華郵政。
  超商 B2C 與 C2C 使用不同 subtype 與營運流程；實際可用服務及憑證組合以綠界後台
  對該 MerchantID 的開通能力為準。C2C 另有專屬寄貨／驗證碼與部分退貨門市欄位。
- `EcpayShippingCatalog`：物流方式的**單一事實來源**。manifest、類別註冊、後台
  清單與存檔、啟用清單、電子地圖 subtype、送單欄位、回呼驗證、封裝與測試矩陣全部
  由它導出。先前這份清單散在七個地方各寫一份，漏一處的症狀不是「方式不見了」，
  而是**它半開著**——後台勾得到但註冊不進去、地圖開得起來但送單 subtype 對不上。
- 後台每個 C2C 方式各有**自己的**退貨門市代號輸入欄位；中華郵政有包裹預設重量欄位。
  未填寫時該方式無法啟用（不是等到出貨那天才失敗）。

### Fixed

- **物流狀態 callback 進入核心的訂單級 advisory serialization**。不同簽章的狀態通知
  不會再同時讀到同一個舊狀態、各自通過 pipeline 後由較舊事件最後覆寫；鎖內會重查
  label 綁定、丟掉鎖前 order cache，且 advisory lock release 不確定時不 ACK。
- **認領門市選擇時以伺服器保存的 tuple 覆寫訂單，並讀回確認**。結帳請求不能再
  改寫 token 內的門市名稱與地址；寫入回 false 或 silent no-op 都會讓結帳失敗。
  官方 map response 本身沒有簽章，所以查不到 canonical directory 時名稱／地址只
  標成 `store_verified=0` 的顯示 hint，不宣稱是供應商驗證資料；建單 wire 使用店號。
- **物流通知在 `SHOW TABLES` 查不動時回 503**。先前把查詢失敗讀成「表不存在」，
  於是走到「不是我們的單」→ ACK，而 ACK 不可逆。
- **pipeline 拒絕遲到／亂序的通知時，`payment_detail` 的狀態投影也不再被覆寫**
  （先前只擋了物流單那一側）。憑據類欄位仍然補上——它們是補齊，不是倒退。
- **headless 訪客的購物車讀 `X-YS-Guest-Token`**（能力自核心 2.56.7 引入；本版最低
  核心因物流 authority 的 migration serialization 修復而提升至 2.56.9）。前端在另一個
  origin 時沒有我方 cookie，先前會被當成空車，於是所有物流方式都「不受商品限制」。
- **門市選擇憑證的擁有者改由電子地圖那一刻決定**（回呼只能複製，不得重算）。
  綠界選店頁以跨站 browser POST 回到 callback，而購物車 cookie 是
  `SameSite=Lax`——跨站 POST 不會帶。在回呼裡重算身分，訪客一律算出空字串，
  於是簽出一張「無法識別擁有者」的憑證：顧客帶著它回到結帳頁，反而被自己的守門
  擋下來。這不是攻擊情境，是**正常流程 100% 失敗**。身分現在在同源的地圖請求裡
  算好、存進 map session；算不出身分時直接不開地圖（讓顧客選完門市才失敗，
  善後成本高得多）。headless 前端在另一個 origin 時沒有我方 cookie，訪客身分
  改以核心既有的 `X-YS-Guest-Token` 判定。
- **跨 origin headless 選店改成一次性 result-code exchange**。callback 不再要求另一個
  origin 去讀 WordPress origin 的 localStorage；它只向 allowlisted `return_url` 帶一個
  32 字元 code，前端用同一 principal 到 `/ecpay/store-result` 提領一次。SDK 同時改用
  absolute `apiBase`、`credentials: include`，並提供 guest token／WP nonce、result claim
  與 checkout helpers。
- **結帳驗證只驗不消耗，憑證在訂單成立那一刻才認領**。先前在欄位驗證裡就把憑證
  刪掉了——而驗證會因為**其他欄位**（電話格式、地址沒填）整批失敗，顧客照著錯誤
  訊息補好再送出，卻被告知「請重新選擇門市」，而他從頭到尾沒動過門市。
  一次性與原子性不變（仍以 `delete_transient()` 的回傳值認領），兩個併發的結帳
  依舊只有一個能用掉那次選店。
- **電子地圖的付款方式改為驗值，不只驗欄位存在**。先前只檢查 key 有沒有送，於是
  空字串、未註冊的金流、以及「貨到付款 × 不支援代收的物流方式」三種都會過，
  而且全部被靜默當成 `IsCollection=N`。最後那一種最傷：顧客選了貨到付款，地圖卻用
  「不代收」去篩門市，他選得到一個不支援代收的門市，結完帳，送單那天才被綠界拒絕。
- **物流通知在 label 尚未落盤時回 503，而不是 ACK 掉**。建單的順序是
  「送出 → 收到回應 → 才 INSERT label」，通知完全可能在那個 INSERT 之前抵達
  （回應遺失、本地落盤失敗的那些單尤其如此——而那正是最需要後續通知的情況）。
  分辨的依據是建單授權：`MerchantTradeNo` 在送出**之前**就落盤，查得到就代表這一單
  確實是我們發出去的，只是 label 還沒寫進來。查不到才是真的不干我們的事。
- **物流通知先讓 pipeline 決定，狀態才寫得下去**。順序反過來的話，遇到一則遲到或
  亂序的通知就會：label 已經被改成「配送中」，pipeline 才說「已取貨不能倒退」而拒絕
  ——訂單說已取貨、label 說配送中，兩邊各說各話，而我們還回了 `1|OK` 讓對方不要再送。
- **傳輸層失敗一律回 `indeterminate`**：逾時、連線中斷、非 2xx、簽章驗不過、
  回應缺必要欄位都不是「明確失敗」——只證明我們不知道。只有綠界簽章驗過且明白
  回報拒絕才是 `provider_failed`。先前一律壓成沒有 outcome 的失敗，呼叫端會預設
  終局失敗而放行下一次建單。
- **門市選擇憑證改綁精確的付款方式**（不再只比代收模式 N/Y）：用信用卡選的門市
  不能拿去給另一個非代收金流用。
- **訪客的憑證也綁身分**：登入者綁 user id、訪客綁購物車 session；兩邊都算不出
  身分時一律拒絕（正常結帳一定有購物車 session）。
- **一次性消耗改為原子認領**：先 `get` 再 `delete` 的寫法會讓兩個併發的結帳同時
  通過；現在以 `delete_transient()` 的回傳值認領，搶不到的那一個被拒絕。
- **中華郵政重量：明確傳入 `<= 0` 也 fail-closed**，不再退回後台預設值。
  後台預設值只在呼叫端**根本沒提供**這個欄位時才用（核心現在只在算得出正的重量時
  才帶，讀取失敗帶 `null`，商品沒重量則不帶）。
- **物流通知的追蹤碼只取 `BookingNote`**：不再退回 `AllPayLogisticsID`。
  顧客拿物流編號去物流商網站是查不到的，而客服看到「有追蹤碼」就不會再追。
- **通知在全部寫入 durable 之後才 ACK**：訂單更新後讀回來確認，pipeline 的
  persistence 失敗回 503。
- **`ReturnStoreID` 依官方規格修正**：它是**選填**，而且**僅 7-ELEVEN C2C
  （UNIMARTC2C）適用**，未設定時綠界會退回原寄件門市。先前兩件事都搞錯——對全家／
  萊爾富送出一個它們不吃的欄位，又因為沒填就不讓方式啟用（那是一個完全合法的設定）。
- **建單成功必須帶回非空的 `AllPayLogisticsID`**（11 個方法皆然），且回傳的
  `provider_trade_no` 只認它，不得 fallback 到 `MerchantTradeNo`。
- **特店交易編號改由核心的建單授權提供**（送出前已落盤），requester 不再自己編；
  缺值或超過 20 字元一律中止。
- **中華郵政重量全面 fail-closed**：核心回報讀取失敗（`null`）、值 ≤ 0、
  或超過 20 公斤都中止建單，**不再 clamp 成 20**。悄悄把 25 公斤改成 20 公斤送出去，
  綠界收下的是一張運費算錯、到門市才被退的單，而系統這邊看起來一切正常。
  後台輸入超出範圍時視同未填（該方式因此無法啟用，錯誤是看得見的）。
- **物流通知在資料庫失敗時不再 ACK**。`find_label()` 改為 typed 結果，把「確定不是
  我們的單」與「資料庫讀不動」分開；讀取或寫入失敗一律回 503 讓綠界重送，全部寫入
  durable 之後才回 `1|OK`。ACK 不可逆——回了 OK，那筆狀態就永遠遺失了。
- **取消回 typed `unsupported`**（不是 `false`），並接受陣列 context。本版未實作綠界
  的取消 API；回 `false` 會讓核心的重新取單／換門市把它讀成「可以重建」，而綠界那邊
  的舊單還活著。
- **門市選擇改為伺服器端綁定**：選店回呼發出一張不透明的 token，權威資料（擁有者、
  購物車 scope、物流方式、subtype、門市、代收前提）全部留在伺服器；結帳時由伺服器
  消耗並逐項比對，付款方式 N→Y／Y→N、換方式、竄改門市代號、重複使用都會被拒絕並
  要求重選。先前整包資料放在 localStorage 由前端送回，「這次選店的代收前提」是一個
  可竄改的欄位，等於沒有守門。headless SDK 的 `requestStoreMapForm()` 也改為必須帶
  付款方式，並提供 `selectionToken()` 與 `selectionTokenField`。
- **bootstrap 加上核心版本與能力／schema gate**：核心低於 2.56.9、缺少建單授權 API、
  或物流 schema 未就位時**一個 hook 都不掛**，只顯示後台提示。「先發核心再發本外掛」
  是流程約定，不能取代 runtime gate。
- **貨到付款不再由後台開關決定**。`IsCollection` 改由**訂單實際的付款方式**決定
  （`ys_ec_cod` → `Y`，其餘 → `N`）。先前 `supports_cod()` 讀一個後台設定並直接
  當成 wire 值，只要業主打開它，**線上已刷卡付完的訂單也會送出代收**，顧客到門市
  取貨時被再收一次錢。`supports_cod()` 現在只表示「這個通路能不能代收」這個能力，
  供核心決定貨到付款要不要出現在結帳頁。訂單缺 `payment_method` 時中止建單——
  缺欄位不是 `false`，是無法證明。
- **C2C 退貨門市改為每個方式一把 key**，並補上後台輸入與儲存。先前所有 C2C 共用
  一個隱藏設定且後台沒有任何入口：業主無從填起，送單必然失敗；就算手動塞進資料庫，
  全家的退貨門市也會被 7-ELEVEN 拿去用。
- **寄件憑據完整回傳給核心落盤**：`CVSPaymentNo`（寄貨編號）與 `CVSValidationNo`
  （7-ELEVEN 驗證碼）分開回傳，不再混進 tracking。回應缺少該方式「沒有它就出不了貨」
  的欄位時視為建單失敗。
- **列印端點依通路決定**。C2C 有各超商專屬的列印 API 且必須帶寄貨編號（7-ELEVEN
  還要驗證碼），舊版一律打 B2C 的 `printTradeDocument`——C2C 因此印不出託運單。
- **中華郵政的欄位改對**：補上綠界規定必填的 `GoodsWeight`，並停止送出官方明載
  「請忽略」的 `Temperature`／`Distance`／`Specification`／`ScheduledDeliveryTime`。
- **7-ELEVEN 家族補上 `CollectionAmount`**（綠界載明 UNIMART／UNIMARTC2C／
  UNIMARTFREEZE 必填，缺了建單失敗）。
- **選店回呼的綁定改為必填**。`MerchantTradeNo` 與 `LogisticsSubType` 先前寫成
  「有傳才比」——不送那個欄位就自動通過，等於可以把 B2C 的選店結果掛到 C2C 的
  session 上。現在缺欄位一律拒絕。
- **物流狀態通知綁定到具體那一張物流單**：先以 (provider, 物流編號) 找出該列，
  再逐項驗 `MerchantTradeNo`／`LogisticsSubType`／物流方式，最後才以主鍵更新。
  舊版以 (order_id, provider_trade_no) 當更新條件，同一張訂單有多張單時可能一次
  改到不只一列。
- **建單簽章驗證不再把自家的合成欄位算進去**。`_status_prefix` 是解析
  `1|Key=Val&…` 回應時我們自己加的，綠界從未送過它；把它丟進 CheckMacValue 會讓
  每一筆帶前綴的回應都驗不過——症狀是建單其實成功了，本地卻判定簽章失敗，
  於是物流單變成孤兒。
- **特店交易編號不再含時間**。舊版以 `substr(time(), -6)` 當尾碼，同一張訂單每重試
  一次就在綠界那邊產生一張新的物流單。改由（訂單編號 × 物流方式 × 第幾次建單）
  穩定導出。
- **電子地圖補上商品物流允許清單守門**（與核心結帳共用同一份述詞，fail-closed），
  並拒收 `order_id` 參數。
- 缺收件門市代號時中止建單——綠界明載門市代號必須來自電子地圖，不可手填猜測。

### Notes

- 綠界官方 API skill（V3.2）與官方 PHP SDK 列出的 C2C subtype 只有
  `FAMIC2C`／`UNIMARTC2C`／`HILIFEC2C`／`OKMARTC2C`，**沒有冷凍 C2C**。
  因此 7-ELEVEN 冷凍只提供 B2C；自創一個未載明的 subtype 只會讓送單被綠界拒絕。
- 逐 subtype 的代收支援矩陣官方文件未載明。7-ELEVEN 冷凍與中華郵政採 fail-closed
  （不開放代收），待以綠界合約內容確認後再放行。

## [0.2.10] - 2026-07-28

### Fixed

- Stop the bundled YS Hub Client library from registering an invalid
  WooCommerce HPOS declaration from its vendor path.
