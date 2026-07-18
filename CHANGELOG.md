# Changelog

本外掛所有重要變更皆記錄於此。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

## [Unreleased]

### Added

- 信用卡退款（query-first 狀態機）：先查 `CreditDetail/QueryTrade` 關帳狀態，依綠界官方流程分流——已授權→N（僅全額）、要關帳全額→E 後接 N、要關帳部分→R、已關帳→R；狀態未知一律拒絕操作。**待受控正式商店實測**（stage DoAction 官方不可用；gate 清單見 `docs/credit-refund-sandbox-gate.md`），驗證通過前不對外宣稱支援。
- 以 core `refund_request_id` 為冪等鍵的 crash-safe 退款防護：同請求已成功→冪等重放；**傳輸不確定（timeout／非 2xx／無 RtnCode）→ 維持 pending 拒絕盲重送**（綠界端可能已生效，重試可能重複退款）；只有 provider 明確拒絕才可重試。
- 宣告 `supports_gateway_refund()`（core v2.56.4 退款能力協定）：信用卡 true、ATM／超商／條碼維持人工退款。
