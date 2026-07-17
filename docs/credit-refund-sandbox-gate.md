# 信用卡退刷 Sandbox Gate（feat/credit-refund 併入 main 的必要條件）

> 狀態：**全部未通過前不併 main、不發版、不對外宣稱支援退刷。**
> 環境：綠界測試環境（`payment-stage.ecpay.com.tw`）＋測試商店 MerchantID/HashKey/HashIV（後台設定 test_mode=1）。

## Gate 清單

| # | Gate | 步驟 | 過關標準 | 產出 |
|---|---|---|---|---|
| G-1 | **關帳後退刷（R）成功** | 測試環境刷一筆信用卡→待（或手動）關帳→後台執行全額退款 | DoAction 回 `RtnCode=1`；綠界後台顯示退刷 | 回應 payload 存 fixture |
| G-2 | **未關帳 R 的確切失敗碼** | 刷一筆、不關帳、立即退刷 | 記下 RtnCode/RtnMsg → 填入 `EcpayCreditGateway::UNCLOSED_RTN_CODES`（目前為空＝不觸發 N fallback，fail-closed） | 碼值＋payload |
| G-3 | **未關帳全額放棄請款（N）成功** | G-2 之後對同交易送 Action=N 全額 | `RtnCode=1`；綠界後台顯示取消請款 | payload |
| G-4 | **部分退刷** | 關帳後退部分金額 | `RtnCode=1` 且綠界端金額正確；若綠界拒部分退刷→改文件宣告「僅全額」並調整金額驗證 | payload |
| G-5 | **綠界端重複 DoAction 行為** | 同一交易重送相同 R 請求 | 記錄綠界端是否自身冪等（第二次的 RtnCode）——決定 pending 拒送策略是否可放寬 | payload |
| G-6 | **crash 冪等演練** | 以同 `refund_request_id` 模擬：pending 中斷→重送被拒；done→冪等重放；failed→可重試 | 契約測試 + 手動演練紀錄一致 | 演練紀錄 |
| G-7 | **與 core 退款鏈整合** | 從 core 後台退款面板對綠界信用卡訂單執行退款 | `refunded_amount`/紀錄/歷程正確；`supports_gateway_refund` 生效（無「訂單退款≠金流退款」警示） | 截圖 |

Fixture 落地：`tests/fixtures/refund/`（本地、gitignored），檔名 `doaction-{gate}.json`，附時間與測試商店代號（**不含正式憑證**）。

## 對拍後必辦

1. `UNCLOSED_RTN_CODES` 填入 G-2 實測碼值（空清單＝N fallback 永不觸發）。
2. 移除 client/gateway 的 `@deferred-sandbox` 標記。
3. CHANGELOG [Unreleased] → 版本化；README 支援矩陣更新。
