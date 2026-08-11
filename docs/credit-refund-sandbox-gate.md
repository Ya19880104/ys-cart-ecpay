# 信用卡退款驗證 Gate（feat/credit-refund 併入 main 的必要條件）

> 狀態：**全部未通過前不併 main、不發版、不對外宣稱支援退款。**
> 🔴 **綠界官方明載：測試環境（stage）因無實際授權，`CreditDetail/DoAction` 不可用**——
> 本 gate **不使用 stage 對拍**（改版前的「sandbox 對拍」規劃作廢）。驗證改以：
> (a) **受控正式商店**小額實刷實測；(b) 綠界技術窗口書面確認；(c) 本地 mock＝**結構測試**
> （只驗 payload/簽章/分流邏輯，不得宣稱等同實測）。

## 驗證環境

- 正式商店（受控）：自有測試訂單、小額（NT$5–10）、實體卡，全程紀錄；每一 gate 完成後立即退款歸零。
- 事前與綠界確認測試刷退對帳單影響；避開結算敏感時段。

## Gate 清單

| # | Gate | 環境 | 步驟 | 過關標準 |
|---|---|---|---|---|
| G-Q | **QueryTrade V2 契約鎖定** | 正式（唯讀，無風險） | 對一筆真實信用卡交易呼叫 `query_credit_close_status`（gwsr＋金額） | 回應結構與 `status` 值域（已授權／要關帳／已關帳…）與實作映射一致；未映射值補進 state_map |
| G-0 | **gwsr 取得鏈** | 正式（唯讀） | 付款紀錄無 gwsr 的歷史訂單 → QueryTradeInfo 補查 | `gwsr` 欄位存在且可回寫 |
| G-1 | **已授權 → N（全額取消授權）** | 正式小額 | 刷卡後（關帳前）執行全額退款 | 查詢=已授權 → 送 N → RtnCode=1；綠界後台顯示取消授權 |
| G-2 | **要關帳＋全額 → E 後接 N** | 正式小額 | 交易進入要關帳狀態後執行全額退款 | E 成功 → N 成功；綠界後台狀態正確 |
| G-3 | **要關帳＋部分 → R** | 正式小額 | 要關帳狀態執行部分退款 | R 成功且金額正確 |
| G-4 | **已關帳 → R（退刷）** | 正式小額 | 關帳後執行退款（全額與部分各一） | R 成功；綠界後台顯示退刷 |
| G-5 | **綠界端重複 DoAction 行為** | 正式小額 | 對已成功動作重送相同請求 | 記錄第二次 RtnCode——決定 pending 凍結策略是否可放寬 |
| G-6 | **不確定結果凍結演練** | 本地 mock（結構） | 模擬 timeout／非 2xx／無 RtnCode | attempt 維持 pending、拒絕重送（契約測試已覆蓋）；正式環境不演練中斷 |
| G-7 | **與 core 退款鏈整合** | dev 站＋正式小額 | core 後台退款面板對綠界信用卡訂單執行 | `refunded_amount`／紀錄／歷程正確；`supports_gateway_refund` 生效 |
| G-8 | **每日關帳時段** | 綠界技術窗口書面確認 | 取得官方對「每日關帳作業時段內 DoAction 行為」的書面說明（實際窗口時間、期間送出的回應碼、是否應改為拒絕） | 取得**確切**窗口與行為後，才在程式內加入時段守門。🔴 在此之前**不得臆造時間**——目前程式碼沒有任何時段判斷，關帳期間的失敗會走既有的 `rejected_terminal`／`indeterminate` 分流 |
| G-9 | **卡別方案 gate 對照** | 綠界技術窗口＋正式（唯讀） | 確認分期（`stage`）、紅利折抵（`red_dan`／`red_de_amt`）、銀聯與其他 `PaymentType` 的實際欄位值與退款規則 | 目前實作只有能證明 `PaymentType=Credit_CreditCard` 且無分期／紅利標記才自動退刷，其餘一律導向人工。**取得官方規則後**才可放寬 |

證據落地：`tests/fixtures/refund/`（本地、gitignored），檔名 `{gate}-{action}.json`，附時間與訂單號（**不含正式憑證**）。

## 對拍後必辦

1. G-Q 若出現未映射狀態值 → 補 `query_credit_close_status` 的 state_map 再重驗。
2. 移除 client/gateway 的 `@deferred-live-verification` 標記。
3. CHANGELOG [Unreleased] → 版本化；README 支援矩陣更新。
