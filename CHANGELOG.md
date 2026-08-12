# Changelog

## [0.2.12] - 2026-08-12

物流專用版（shipping-only），自 `v0.2.10` 重新實作。**不含**信用卡退刷、退款授權、
CLI 退款、payment detail CAS，也不要求核心 2.57.0——搭配核心 2.56.5 即可。

（`0.2.11` 已被 map-only 分支占用，故版號直接進到 `0.2.12`。）

### Added

- **11 個獨立的物流方式**，每一個都有自己的 `method_id`、啟用開關、運費、免運門檻
  與 wire 欄位：全家（B2C／C2C）、7-ELEVEN（B2C／C2C）、萊爾富（B2C／C2C）、
  7-ELEVEN 冷凍（B2C）、黑貓宅配常溫／冷藏／冷凍、中華郵政。
  B2C 與 C2C 是綠界**兩份不同的合約**（不同服務金鑰、不同 subtype、C2C 另需退貨
  門市），不是一個開關。
- `EcpayShippingCatalog`：物流方式的**單一事實來源**。manifest、類別註冊、後台
  清單與存檔、啟用清單、電子地圖 subtype、送單欄位、回呼驗證、封裝與測試矩陣全部
  由它導出。先前這份清單散在七個地方各寫一份，漏一處的症狀不是「方式不見了」，
  而是**它半開著**——後台勾得到但註冊不進去、地圖開得起來但送單 subtype 對不上。
- 後台每個 C2C 方式各有**自己的**退貨門市代號輸入欄位；中華郵政有包裹預設重量欄位。
  未填寫時該方式無法啟用（不是等到出貨那天才失敗）。

### Fixed

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
