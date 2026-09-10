<?php
/**
 * 綠界一般支付（導轉 AIO）付款方式 descriptor matrix —— 全外掛唯一事實來源（SOT）
 *
 * 🔴 為什麼要有這張表：
 *
 * 物流那邊早就有 {@see \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog}，
 * 金流卻還把「有哪些付款方式」抄在五個地方——`manifest.php` 的 capabilities、
 * `Plugin::register_gateways()` 的一連串 if、`Plugin::is_method_enabled()` 的
 * legacy map、`Settings::PAYMENT_METHOD_KEYS`，以及 `EcpaySettings` 裡的
 * `PAYMENT_GATEWAY_IDS` 與 `payment_methods` 標籤表。
 *
 * 五份清單漏掉任何一份，症狀都不是「這個方式不見了」，而是**它半開著**：
 * 後台勾得起來但註冊不進去、方式列表看得到但 manifest 認不得（provider
 * lifecycle 一律判停用）、或是存檔存不進去。物流的型錄註解已經寫過這個教訓，
 * 金流在加到第五個方式之前把它補上。
 *
 * 現在只有這一份。上面五處全部由它導出，「型錄加了、註冊忘了」在語法上不可能發生。
 *
 * ─────────────────────────────────────────────────────────────────────────
 * 🔴 這張表只收「一般支付（導轉）」的方式
 *
 * 綠界的 `ChoosePayment` 合法值全集（官方 skill V3.4 guides/01 §AIO 參數表）：
 *
 *   ALL / Credit / ATM / CVS / BARCODE / WebATM / ApplePay / TWQR / BNPL /
 *   WeiXin / DigitalPayment
 *
 * 本表不收 `ALL`（讓綠界顯示全部選項＝我們無從得知消費者選了什麼，也無法逐
 * 方式開關），也不收 `DigitalPayment`（它是「只顯示數位支付」的分組選擇器，
 * 不是一個獨立方式）。
 *
 * v0.5.0 起多一個例外：**站內付 2.0 綁卡信用卡**（`ys_ec_ecpay_ecpg_credit`，`transport`
 * 為 `ecpg`）。它不是導轉——卡號在本站頁面由綠界 JS 元件收取、直送綠界，訂閱以綁定
 * 的 BindCardID 幕後續扣。它仍放在同一張型錄，是因為「有哪些付款方式」這個問題在
 * 後台、manifest、註冊三處都只能有一個答案；差別由 `transport` 標明，導轉專用的欄位
 * （`choose_payment`／`extra_fields`）對它沒有意義，保留是為了讓型錄形狀一致。
 * 定期定額（綠界端排程）仍不在這裡，見 {@see \YangSheep\YSCartEcpay\Support\Settings::IMPLEMENTED_PAYMENT_MODES}。
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 🔴 `activation` 不是文案，是「開了會壞」的警告
 *
 * Apple Pay／TWQR／微信／BNPL／分期／銀聯都必須先向綠界申請開通。沒開通就啟用，
 * 消費者會走到結帳最後一步才被綠界擋下——症狀出現在最貴的地方。因此這些方式
 * 一律**預設關閉**，後台顯示 `activation` 說明，由業主確認開通後自行開啟。
 *
 * @package YangSheep\YSCartEcpay
 */

declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

final class EcpayPaymentCatalog {
	/**
	 * 付款方式的完整契約。
	 *
	 * 每個 descriptor 的欄位意義：
	 *
	 * - `alias`           後台表單欄位與 legacy 設定 key 用的短名。
	 * - `label`           manifest 與後台方式列表的名稱（不含「綠界」前綴）。
	 * - `title`           前台結帳顯示的付款方式名稱。
	 * - `choose_payment`  綠界 AIO `ChoosePayment` 欄位值。
	 * - `extra_fields`    這個方式**額外**要送的 AIO 欄位（靜態值）。
	 *                     🔴 分期的 `CreditInstallment` 是設定值不是常數，因此由
	 *                     {@see EcpayCreditInstallmentGateway::extra_aio_fields()}
	 *                     在執行期提供，不放進這張表——表只放「不會變的事實」。
	 * - `min_amount`      這個方式的最低金額（綠界規則）。`is_available()` 依此在
	 *                     金額不足時不顯示該方式。
	 * - `activation`      需要向綠界另外申請開通時的說明；空字串＝一般合約即可用。
	 * - `default_enabled` 是否預設啟用。需要開通的方式一律 false。
	 * - `enabled_option`  啟用開關的設定 key。
	 * - `class`           付款方式類別（每個方式一個獨立類別）。
	 * - `transport`       `aio`（導轉，預設）或 `ecpg`（站內付 2.0）。v0.5.0 新增。
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const METHODS = [
		// ── 一般合約即可使用 ───────────────────────────────────────────────
		'ys_ec_ecpay_credit'             => [
			'alias'           => 'credit',
			'label'           => '信用卡',
			'title'           => '綠界信用卡',
			'choose_payment'  => 'Credit',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '',
			'default_enabled' => true,
			'enabled_option'  => 'ys_ec_ecpay_credit_enabled',
			'class'           => EcpayCreditGateway::class,
		],
		'ys_ec_ecpay_atm'                => [
			'alias'           => 'atm',
			'label'           => 'ATM 虛擬帳號',
			'title'           => '綠界 ATM 虛擬帳號',
			'choose_payment'  => 'ATM',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '',
			'default_enabled' => true,
			'enabled_option'  => 'ys_ec_ecpay_atm_enabled',
			'class'           => EcpayAtmGateway::class,
		],
		'ys_ec_ecpay_webatm'             => [
			'alias'           => 'webatm',
			'label'           => 'WebATM 網路 ATM',
			'title'           => '綠界 WebATM',
			'choose_payment'  => 'WebATM',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '',
			// 一般合約就能用，但仍預設關閉：它是 0.4.0 新增的方式，
			// 見 {@see self::default_enabled_by_alias()} 的升級安全規則。
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_webatm_enabled',
			'class'           => EcpayWebAtmGateway::class,
		],
		'ys_ec_ecpay_cvs'                => [
			'alias'           => 'cvs',
			'label'           => '超商代碼',
			'title'           => '綠界超商代碼',
			'choose_payment'  => 'CVS',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '',
			'default_enabled' => true,
			'enabled_option'  => 'ys_ec_ecpay_cvs_enabled',
			'class'           => EcpayCvsGateway::class,
		],
		'ys_ec_ecpay_barcode'            => [
			'alias'           => 'barcode',
			'label'           => '超商條碼',
			'title'           => '綠界超商條碼',
			'choose_payment'  => 'BARCODE',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '',
			'default_enabled' => true,
			'enabled_option'  => 'ys_ec_ecpay_barcode_enabled',
			'class'           => EcpayBarcodeGateway::class,
		],

		// ── 需向綠界另外申請開通（一律預設關閉）────────────────────────────
		'ys_ec_ecpay_credit_installment' => [
			'alias'           => 'credit_installment',
			'label'           => '信用卡分期',
			'title'           => '綠界信用卡分期',
			'choose_payment'  => 'Credit',
			// CreditInstallment 由 gateway 依設定的期數提供（見上方欄位說明）。
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '需先向綠界申請開通分期，並於下方設定要開放的期數。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_credit_installment_enabled',
			'class'           => EcpayCreditInstallmentGateway::class,
		],
		'ys_ec_ecpay_unionpay'           => [
			'alias'           => 'unionpay',
			'label'           => '銀聯卡',
			'title'           => '綠界銀聯卡',
			// 官方規格：銀聯是 ChoosePayment=Credit 搭配 UnionPay=1，
			// 不是一個獨立的 ChoosePayment 值。
			'choose_payment'  => 'Credit',
			'extra_fields'    => [ 'UnionPay' => '1' ],
			'min_amount'      => 1.0,
			'activation'      => '需先向綠界申請開通銀聯卡。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_unionpay_enabled',
			'class'           => EcpayUnionPayGateway::class,
		],
		'ys_ec_ecpay_applepay'           => [
			'alias'           => 'applepay',
			'label'           => 'Apple Pay',
			'title'           => '綠界 Apple Pay',
			'choose_payment'  => 'ApplePay',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '需先向綠界申請開通 Apple Pay 並完成網域驗證；未完成前綠界付款頁不會出現 Apple Pay。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_applepay_enabled',
			'class'           => EcpayApplePayGateway::class,
		],
		'ys_ec_ecpay_twqr'               => [
			'alias'           => 'twqr',
			'label'           => 'TWQR 行動支付',
			'title'           => '綠界 TWQR 行動支付',
			'choose_payment'  => 'TWQR',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '需先向綠界申請開通 TWQR。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_twqr_enabled',
			'class'           => EcpayTwqrGateway::class,
		],
		'ys_ec_ecpay_weixin'             => [
			'alias'           => 'weixin',
			'label'           => '微信支付',
			'title'           => '綠界微信支付',
			'choose_payment'  => 'WeiXin',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '需先向綠界申請開通微信支付。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_weixin_enabled',
			'class'           => EcpayWeiXinGateway::class,
		],
		'ys_ec_ecpay_bnpl'               => [
			'alias'           => 'bnpl',
			'label'           => 'BNPL 無卡分期',
			'title'           => '綠界 BNPL 無卡分期',
			'choose_payment'  => 'BNPL',
			'extra_fields'    => [],
			// 🔴 官方明載最低 3,000 元（未達會收到錯誤碼 10200105）。寫在這裡的
			// 意義是「金額不足時結帳頁就不顯示這個方式」，而不是讓消費者選了之後
			// 才在綠界那邊被退回來。
			'min_amount'      => 3000.0,
			'activation'      => '需先向綠界申請開通無卡分期（裕富／銀角零卡），且訂單金額須滿 3,000 元。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_bnpl_enabled',
			'class'           => EcpayBnplGateway::class,
		],
		// ── 站內付 2.0（特約商店；卡號在本站頁面由綠界元件收取）────────────────
		'ys_ec_ecpay_ecpg_credit'        => [
			'alias'           => 'ecpg_credit',
			'label'           => '信用卡（站內付 2.0 綁卡）',
			'title'           => '綠界信用卡（站內付）',
			// 不走 AIO；下面兩欄對它沒有意義，只為型錄形狀一致。
			'choose_payment'  => 'Credit',
			'extra_fields'    => [],
			'min_amount'      => 1.0,
			'activation'      => '需為綠界特約商店並申請開通「站內付 2.0」與「綁定信用卡」。卡號在本站頁面由綠界元件收取、直送綠界（官方載明無需 PCI-DSS）；訂閱訂單付款時綁定卡片，之後由本站排程自動續扣。',
			'default_enabled' => false,
			'enabled_option'  => 'ys_ec_ecpay_ecpg_credit_enabled',
			'class'           => EcpayEcpgCreditGateway::class,
			'transport'       => 'ecpg',
		],
	];

	public const TRANSPORT_AIO  = 'aio';
	public const TRANSPORT_ECPG = 'ecpg';

	/** 方式的傳輸模型；未標明＝導轉（AIO）。未知方式回空字串。 */
	public static function transport( string $method_id ): string {
		$descriptor = self::METHODS[ $method_id ] ?? null;
		if ( null === $descriptor ) {
			return '';
		}
		return (string) ( $descriptor['transport'] ?? self::TRANSPORT_AIO );
	}

	/**
	 * 全部方式（method_id => descriptor）。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return self::METHODS;
	}

	/** @return array<int,string> */
	public static function ids(): array {
		return array_keys( self::METHODS );
	}

	/** @return array<string,mixed>|null */
	public static function get( string $method_id ): ?array {
		return self::METHODS[ $method_id ] ?? null;
	}

	/** @return array<string,mixed>|null */
	public static function get_by_alias( string $alias ): ?array {
		foreach ( self::METHODS as $descriptor ) {
			if ( $alias === $descriptor['alias'] ) {
				return $descriptor;
			}
		}

		return null;
	}

	/** @return array<int,string> */
	public static function aliases(): array {
		$out = [];
		foreach ( self::METHODS as $descriptor ) {
			$out[] = (string) $descriptor['alias'];
		}

		return $out;
	}

	/**
	 * alias => method_id。
	 *
	 * @return array<string,string>
	 */
	public static function alias_to_id(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = (string) $method_id;
		}

		return $out;
	}

	/**
	 * method_id => alias（`Plugin::is_method_enabled()` 的 legacy map）。
	 *
	 * @return array<string,string>
	 */
	public static function id_to_alias(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ (string) $method_id ] = (string) $descriptor['alias'];
		}

		return $out;
	}

	/**
	 * alias => 啟用開關設定 key（`Settings::PAYMENT_METHOD_KEYS` 由此導出）。
	 *
	 * @return array<string,string>
	 */
	public static function enabled_option_by_alias(): array {
		$out = [];
		foreach ( self::METHODS as $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = (string) $descriptor['enabled_option'];
		}

		return $out;
	}

	/**
	 * alias => 預設啟用值（'1'／'0'）。
	 *
	 * 需要向綠界申請開通的方式一律 '0'：沒開通卻預設開著，等於讓消費者走到結帳
	 * 最後一步才被綠界擋下。
	 *
	 * 🔴 新增的方式一律 '0'，即使它一般合約就能用（WebATM 就是這種情形）。
	 * 預設開的後果是既有站台升級之後，結帳頁自己多出一個業主沒同意的付款方式。
	 * 因此預設開的集合永遠只有 0.4.0 之前就在賣的那四個。
	 *
	 * @return array<string,string>
	 */
	public static function default_enabled_by_alias(): array {
		$out = [];
		foreach ( self::METHODS as $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = empty( $descriptor['default_enabled'] ) ? '0' : '1';
		}

		return $out;
	}

	/**
	 * manifest `capabilities.payment.methods`。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function manifest_methods(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[] = [
				'id'    => (string) $method_id,
				'label' => (string) $descriptor['label'],
				'class' => (string) $descriptor['class'],
			];
		}

		return $out;
	}

	/**
	 * 後台「金流設定」分頁的方式列表——同樣由本表導出。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function admin_rows(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = [
				'id'             => (string) $method_id,
				'label'          => (string) $descriptor['label'],
				'choose_payment' => (string) $descriptor['choose_payment'],
				'activation'     => (string) $descriptor['activation'],
				'min_amount'     => (float) $descriptor['min_amount'],
			];
		}

		return $out;
	}
}
