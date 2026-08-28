<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || defined( 'YS_CART_ECPAY_TESTING' ) || exit;

/**
 * `cart_scope` 的 canonical ABI——**唯一**一份判準。
 *
 * Integrator 裁決（2026-08-28）：
 *
 *   canonical 形狀是 `/^[a-z0-9_]{1,32}$/`。
 *   **只有「完全未提供」才可以使用 `default`。** 只要有提供，原值就必須**已經是**
 *   canonical 字串；禁止 sanitize／normalize 之後改綁到另一個 scope，也禁止默默降成
 *   `default`。
 *
 * 🔴 為什麼不能「先正規化再用」：
 *
 * `EcpayStoreSelector::current_principal()` 對登入者會在讀 `$cart_scope` **之前**就回
 * `'u:<id>'`，headless 的 `X-YS-Guest-Token` 分支同樣與 scope 無關。所以把
 * `HEADLESS_1` 正規化成 `headless_1`、把 `!!!` 降成 `default`，在主流部署下都會用
 * **另一個 scope 的身分**去動敏感資源：一次性提領碼被消耗、map session 被簽發、
 * saved-store token 被鑄出。呼叫端送錯 scope，不該由我們猜一個替它把資源用掉。
 *
 * 正式的 Core／affiliate caller 只產生 canonical scope，公開 headless SDK 也在送出前
 * 用同一條規則驗證（`sdk/ys-cart-ecpay-headless.js`）。這是刻意的 fail-closed
 * hardening：送非 canonical scope 的呼叫端會開始收到 400，而不是被靜默改綁。
 *
 * 契約：`tests/regression/v031_cart_scope_canonical_abi.php`
 */
final class CartScope {
	/** 結尾用 `D`：沒有它時 `$` 會允許尾端換行，`"headless_1\n"` 會被當成 canonical。 */
	public const CANONICAL_PATTERN = '/^[a-z0-9_]{1,32}$/D';

	public const DEFAULT_SCOPE = 'default';

	/**
	 * 這個**原始值**本身是不是 canonical scope。
	 *
	 * 只接受字串：`0`／`true`／`null`／陣列都不是「已經 canonical 的字串」，
	 * 而把它們轉成字串正是本規則要禁止的那一步。
	 *
	 * @param mixed $value 未經任何轉型的原始值
	 */
	public static function is_canonical( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::CANONICAL_PATTERN, $value );
	}

	/**
	 * 從請求 bag 取出 scope。
	 *
	 * @param array<string,mixed> $bag 未經轉型的原始參數
	 * @return string|null `null` 代表「有提供但不是 canonical」→ 呼叫端必須拒絕整個請求
	 */
	public static function resolve( array $bag, string $key = 'cart_scope' ): ?string {
		// `array_key_exists()` 而非 `isset()`：`isset()` 對 `null` 回 false，會把
		// 「有提供但送了 null」誤判成「未提供」而降成 default——正是要擋的那條路。
		if ( ! array_key_exists( $key, $bag ) ) {
			return self::DEFAULT_SCOPE;
		}

		return self::is_canonical( $bag[ $key ] ) ? (string) $bag[ $key ] : null;
	}
}
