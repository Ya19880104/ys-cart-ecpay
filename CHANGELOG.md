# Changelog

本外掛所有重要變更皆記錄於此。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)。

## [Unreleased]

### Added

- 信用卡退刷（CreditDetail/DoAction）：後台退款直接觸發綠界退刷；未關帳交易全額改走放棄請款（N），部分金額明確拒絕。**待綠界測試環境對拍**（gate 清單見 `docs/credit-refund-sandbox-gate.md`），對拍通過前不對外宣稱支援。
- 以 core `refund_request_id` 為冪等鍵的 crash-safe 退刷防護：同請求已成功→冪等重放；送出後結果未明→拒絕盲重送（防重複退刷）；失敗→允許重試。
- 宣告 `supports_gateway_refund()`（core v2.56.4 退款能力協定）：信用卡 true、ATM／超商／條碼維持人工退款。
