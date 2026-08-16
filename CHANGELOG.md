# Changelog

## 0.2.16（候選）

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
