<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailResult;

/**
 * provider 端的 payment_detail 寫入結果（v0.3.0）
 *
 * 為什麼不直接回核心的 `YSPaymentDetailResult`：核心服務缺席時（安裝順序錯誤、
 * 降版、部分部署）我們仍然必須回一個「失敗」給呼叫端——但那個情境下連
 * `YSPaymentDetailResult` 這個類別都不存在，實例化它會直接 fatal。fatal 出現在
 * 退款路徑上比回報失敗更糟：訊息不可讀、狀態不確定、而且是在金流動作附近。
 *
 * 因此這裡是一層極薄的結果載體：核心在場就原樣搬運它的結論，核心缺席就合成一個
 * 明確的 `core_unavailable` 失敗。**沒有任何情況會退回自己那份寫入器。**
 */
final class DetailWriteOutcome {

	public const CORE_UNAVAILABLE = 'core_unavailable';

	private function __construct(
		private string $outcome,
		private mixed $decision = null,
		private ?array $detail = null,
		private int $attempts = 0,
		private string $message = ''
	) {}

	public static function from_core( YSPaymentDetailResult $result ): self {
		return new self(
			$result->get_outcome(),
			$result->get_decision(),
			$result->get_detail(),
			$result->get_attempts(),
			$result->get_message()
		);
	}

	public static function core_unavailable(): self {
		return new self(
			self::CORE_UNAVAILABLE,
			null,
			null,
			0,
			'core YSPaymentDetailStore is unavailable (requires YS CART 2.57.0+)'
		);
	}

	public function get_outcome(): string {
		return $this->outcome;
	}

	/** 已確認落盤，或確認無需落盤。 */
	public function is_persisted(): bool {
		return 'updated' === $this->outcome || 'no_op' === $this->outcome;
	}

	/** mutator 主動放棄——沒有寫入，但不是錯誤。 */
	public function is_aborted(): bool {
		return 'aborted' === $this->outcome;
	}

	/** 訂單不存在（與寫入失敗要分開處理）。 */
	public function is_missing_order(): bool {
		return 'not_found' === $this->outcome;
	}

	public function get_decision(): mixed {
		return $this->decision;
	}

	public function get_detail(): ?array {
		return $this->detail;
	}

	public function get_attempts(): int {
		return $this->attempts;
	}

	public function get_message(): string {
		return $this->message;
	}

	/** @return array<string,mixed> 供 log 使用（不含 detail 內容） */
	public function to_log_context(): array {
		return [
			'outcome'  => $this->outcome,
			'attempts' => $this->attempts,
			'message'  => $this->message,
		];
	}
}
