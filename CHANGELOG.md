# Changelog

本外掛所有重要變更皆記錄於此。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

## [Unreleased]

### Fixed

- 超商電子地圖（`/stores/ecpay/map-url`）補上購物車商品「允許的物流方式」交集驗證。先前僅檢查 provider 與該物流方式的**全域**啟用狀態，因此購物車商品只允許萊爾富時，前端仍可取得**已簽章**的 7-11 電子地圖表單，使用者選完門市、callback 也會寫入門市 session 與 `ys_ec_selected_store`，直到送單才被核心擋下。現以核心 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份共用守門驗證（fail-closed，回 `shipping_method_not_allowed`）；僅套用於購物車情境（`order_id=0`），帶訂單時以訂單品項為準。購物車讀取為純讀（`get_items_raw()` + `ys_ec_cart_key_scope` 綁定），訪客無 session cookie 時直接短路以避免 `setcookie()` 副作用；核心較舊而無此述詞時放行，仍由核心送單驗證把關。

### Added

- 信用卡退款（query-first 狀態機）：先查 `CreditDetail/QueryTrade` 關帳狀態，依綠界官方流程分流——已授權→N（僅全額）、要關帳全額→E 後接 N、要關帳部分→R、已關帳→R；狀態未知一律拒絕操作。**待受控正式商店實測**（stage DoAction 官方不可用；gate 清單見 `docs/credit-refund-sandbox-gate.md`），驗證通過前不對外宣稱支援。
- 以 core `refund_request_id` 為冪等鍵的 crash-safe 退款防護：同請求已成功→冪等重放；**傳輸不確定（timeout／非 2xx／無 RtnCode）→ 維持 pending 拒絕盲重送**（綠界端可能已生效，重試可能重複退款）；只有 provider 明確拒絕才可重試。
- 宣告 `supports_gateway_refund()`（core v2.56.4 退款能力協定）：信用卡 true、ATM／超商／條碼維持人工退款。
- 新增「信用卡查詢檢查碼」（CreditCheckCode）設定欄位（加密儲存；關帳狀態查詢必填）；建單改送 `NeedExtraPaidInfo=Y` 並於付款通知持久化授權單號（gwsr）。
- 訂單級退款凍結：只要存在任何結果未明的退款請求，一律拒絕新的退款操作直到人工核定（不依賴請求 ID 穩定性的金流層最後防線）。
