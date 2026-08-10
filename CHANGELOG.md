# Changelog

本外掛所有重要變更皆記錄於此。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

## [Unreleased]

### Fixed

- 超商電子地圖（`/stores/ecpay/map-url`）補上購物車商品「允許的物流方式」交集驗證。先前僅檢查 provider 與該物流方式的**全域**啟用狀態，因此購物車商品只允許萊爾富時，前端仍可取得**已簽章**的 7-11 電子地圖表單，使用者選完門市、callback 也會寫入門市 session 與 `ys_ec_selected_store`，直到送單才被核心擋下。現以核心 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份共用守門驗證（fail-closed，回 `shipping_method_not_allowed`）；核心較舊而無此述詞時放行，仍由核心送單驗證把關。
- 電子地圖端點不再接受 `order_id`。該參數直接取自請求且未驗證訂單存在、擁有者或品項，任何非零值都能整段跳過上述物流限制；現直接拒收（`order_id_not_supported`）。核心 `assets/js/ys-ec-store-selector.js` 與 `sdk/ys-cart-ecpay-headless.js` 兩個呼叫端皆未使用此參數。
- 購物車讀取失敗不再被當成空購物車。核心把空車視為「不限物流」，因此先前把 handler 缺失／非陣列／例外一律轉成空陣列，會讓讀取失敗反而簽發地圖表單；現以 `null` 表示讀取失敗並 fail-closed，空陣列僅代表確定為空。購物車讀取為純讀（`get_items_raw()` + `ys_ec_cart_key_scope` 綁定），訪客無 session cookie 時直接短路以避免 `setcookie()` 副作用。
- `payment_detail` 的所有寫入改走 compare-and-swap（新增 `Support\OrderPaymentDetail`）。此欄位是單一 JSON，先前五處全走 read-modify-write 後整包覆蓋；付款通知回寫 `gwsr` 與退款 ledger `_ys_ecpay_refunds` 是同一欄位的併發 writer，重疊時後寫者會靜默蓋掉先寫者——而被蓋掉的正是「不重複退款」的唯一依據。其中信用卡閘道的 `gwsr` 回寫更是直接寫回方法開頭的舊快照，屬必然覆蓋而非競態。新寫入器分流三種 MySQL／wpdb 天性：`query()` 回 `false`（SQL 錯誤）與回 `0`（CAS 落敗）語意不同、同值 UPDATE 天生 `affected=0` 不算落敗、欄位為 SQL NULL 時 WHERE 需用 `IS NULL`。
- 發佈包契約測試改為鎖定 plugin header 的當前版號；先前以 `glob + rsort` 取現存最新 ZIP，版號推進後仍會驗一個陳舊的包並回報 PASS。

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
