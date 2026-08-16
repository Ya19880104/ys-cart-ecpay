<?php
/**
 * 綠界物流方式 descriptor matrix —— 全外掛唯一事實來源（SOT）
 *
 * 🔴 為什麼要有這張表：
 *
 * 舊版把「有哪些物流方式」這件事拆散在七個地方各寫一份——manifest、
 * `Plugin::REGISTERED_SHIPPING_IDS`、`Plugin::is_method_enabled()` 的 legacy map、
 * `Settings::METHOD_KEYS`、`EcpaySettings::SHIPPING_METHOD_IDS`、
 * `EcpayStoreSelector::SUBTYPES` 與 `METHOD_ALIASES`。七份清單只要有一份漏了，
 * 症狀都不是「這個方式不見了」，而是**它半開著**：後台勾得到但註冊不進去、
 * 電子地圖開得起來但送單 subtype 對不上、方式列表看得到但存檔存不進去。
 *
 * 現在只有這一份。上面那七處全部由它導出，漏一個方式在語法上就不可能發生。
 *
 * 🔴 B2C 與 C2C 是**兩份不同的合約**（綠界以不同服務金鑰開通、subtype 不同、
 * C2C 另外必填退貨門市），不是一個開關。每一種通路 × 溫層都是獨立的方式，
 * 各自有 method_id、啟用開關、運費、免運門檻與 wire 欄位。
 *
 * ─────────────────────────────────────────────────────────────────────────
 * 🔴🔴 為什麼是 11 個方式而不是 12 個
 *
 * 綠界官方 API skill（綠界科技官方出品 V3.2）的「物流商支援表」與官方 PHP SDK
 * 範例，兩者列出的 `LogisticsSubType` 全集是：
 *
 *   B2C：FAMI / UNIMART / UNIMARTFREEZE / HILIFE
 *   C2C：FAMIC2C / UNIMARTC2C / HILIFEC2C
 *   宅配：TCAT / POST
 *
 * **`UNIMARTFREEZEC2C` 不在其中**——官方目前只列 7-ELEVEN 冷凍 B2C，
 * 沒有冷凍 C2C。`OKMARTC2C` 則已於 2026-07-01 終止服務，也不屬於
 * 這份可註冊型錄。
 *
 * 先前的實作自創了這個 subtype。自創 subtype 的後果不是「少一個選項」，而是
 * 業主開了它、顧客選得到、送單時綠界直接回「找不到加密金鑰，請確認是否有申請
 * 開通此物流方式」——與 B2C/C2C 用錯 subtype 的症狀一模一樣，極難查。
 *
 * 因此本型錄只收官方載明的方式。7-ELEVEN 冷凍 C2C 需要綠界正式書面確認後才能加。
 * ─────────────────────────────────────────────────────────────────────────
 *
 * @package YangSheep\YSCartEcpay
 */

declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

final class EcpayShippingCatalog {
	/** 通路：超商 B2C（大宗寄倉，賣家出貨到門市）。 */
	public const CHANNEL_B2C = 'b2c';

	/** 通路：超商 C2C（店到店，賣家拿寄貨編號到門市寄件）。 */
	public const CHANNEL_C2C = 'c2c';

	/** 通路：宅配。 */
	public const CHANNEL_HOME = 'home';

	/** 綠界溫層代碼：常溫。 */
	public const TEMP_ROOM = '0001';

	/** 綠界溫層代碼：冷藏。 */
	public const TEMP_CHILLED = '0002';

	/** 綠界溫層代碼：冷凍。 */
	public const TEMP_FROZEN = '0003';

	/**
	 * 物流方式的完整契約。
	 *
	 * 每個 descriptor 的欄位意義：
	 *
	 * - `alias`                      後台表單欄位與 legacy 設定 key 用的短名。
	 * - `label`                      後台與前台顯示名稱。
	 * - `channel`                    b2c / c2c / home（見上方常數）。
	 * - `temperature`                綠界 `Temperature` 欄位值；**方式的屬性，不是訂單的欄位**。
	 * - `logistics_type`             綠界 `LogisticsType`：CVS / HOME。
	 * - `logistics_subtype`          綠界 `LogisticsSubType`；電子地圖與送單必須送同一個。
	 * - `cod_capable`                這個通路**能不能**代收貨款。是能力，不是「這張訂單要代收」。
	 * - `requires_store`             送單前必須有收件門市代號（超商皆是）。
	 * - `supports_return_store`      這個 subtype 吃不吃 `ReturnStoreID`。
	 *                                🔴 官方規格：**選填**，且**僅 7-ELEVEN C2C
	 *                                （UNIMARTC2C）適用**，未設定時退回原寄件門市。
	 *                                因此它既不是「C2C 都有」，也不是必填——先前兩者都搞錯了：
	 *                                對全家／萊爾富送出一個它們不吃的欄位，又因為沒填就
	 *                                不讓方式啟用。
	 * - `return_store_option`        該方式**專屬**的退貨門市設定 key；共用一個 key 會讓
	 *                                全家的退貨寄到 7-11。不適用者為空字串。
	 * - `requires_collection_amount` 綠界載明這些 subtype 需一併傳 `CollectionAmount`。
	 * - `requires_goods_weight`      綠界載明 `POST`（中華郵政）必填 `GoodsWeight`。
	 * - `sends_home_conditions`      是否送宅配專屬條件（溫層／距離／規格／指定時段）。
	 *                                🔴 中華郵政官方明載「請忽略」這些欄位。
	 * - `required_response_fields`   建單回應中缺了就代表「貨出不去」的欄位，缺值 fail-closed。
	 * - `enabled_option`             啟用開關的設定 key。
	 * - `class`                      物流方式類別（每個方式一個獨立類別）。
	 *
	 * 🔴 `cod_capable` 只表示供應商契約允許代收，不是這張訂單要不要代收。
	 * UNIMARTFREEZE 的當前官方契約包含代收，因此能力為 true；代收與否仍由
	 * 訂單的最終付款方式決定。中華郵政不支援代收，維持 fail-closed。
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const METHODS = [
		// ── 全家 ───────────────────────────────────────────────────────────
		'ys_ec_ecpay_ship_family'         => [
			'alias'                      => 'ship_family',
			'label'                      => '全家超商取貨',
			'channel'                    => self::CHANNEL_B2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'FAMI',
			'cod_capable'                => true,
			'requires_store'             => true,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_family_enabled',
			'class'                      => EcpayShippingFamily::class,
		],
		'ys_ec_ecpay_ship_family_c2c'     => [
			'alias'                      => 'ship_family_c2c',
			'label'                      => '全家店到店（C2C）',
			'channel'                    => self::CHANNEL_C2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'FAMIC2C',
			'cod_capable'                => true,
			'requires_store'             => true,
			// 官方規格：ReturnStoreID **僅 7-ELEVEN C2C 適用**。全家店到店不吃這個欄位。
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID', 'CVSPaymentNo' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_family_c2c_enabled',
			'class'                      => EcpayShippingFamilyC2C::class,
		],

		// ── 7-ELEVEN（常溫） ───────────────────────────────────────────────
		'ys_ec_ecpay_ship_unimart'        => [
			'alias'                      => 'ship_unimart',
			'label'                      => '7-ELEVEN 超商取貨',
			'channel'                    => self::CHANNEL_B2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'UNIMART',
			'cod_capable'                => true,
			'requires_store'             => true,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			// 綠界載明：UNIMART／UNIMARTC2C／UNIMARTFREEZE 需一併傳 CollectionAmount
			// （值等於 GoodsAmount），否則建單失敗。
			'requires_collection_amount' => true,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_unimart_enabled',
			'class'                      => EcpayShippingUnimart::class,
		],
		'ys_ec_ecpay_ship_unimart_c2c'    => [
			'alias'                      => 'ship_unimart_c2c',
			'label'                      => '7-ELEVEN 交貨便（C2C）',
			'channel'                    => self::CHANNEL_C2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'UNIMARTC2C',
			'cod_capable'                => true,
			'requires_store'             => true,
			// 🔴 官方規格：`ReturnStoreID` String(6)，**否**（選填），
			// 「僅 7-ELEVEN C2C（UNIMARTC2C）適用；未設定時退回原寄件門市」。
			// 因此只有這一個方式提供這個欄位，而且**沒填不是錯誤**。
			'supports_return_store'      => true,
			'return_store_option'        => 'ys_ec_ecpay_ship_unimart_c2c_return_store_id',
			'requires_collection_amount' => true,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			// 🔴 7-ELEVEN 交貨便要**兩段**：寄貨編號＋驗證碼（官方載明驗證碼為
			// 統一超商專用）。少了驗證碼，賣家到門市機台輸不進去，貨就是出不去。
			'required_response_fields'   => [ 'AllPayLogisticsID', 'CVSPaymentNo', 'CVSValidationNo' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_unimart_c2c_enabled',
			'class'                      => EcpayShippingUnimartC2C::class,
		],

		// ── 萊爾富 ─────────────────────────────────────────────────────────
		'ys_ec_ecpay_ship_hilife'         => [
			'alias'                      => 'ship_hilife',
			'label'                      => '萊爾富超商取貨',
			'channel'                    => self::CHANNEL_B2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'HILIFE',
			'cod_capable'                => true,
			'requires_store'             => true,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_hilife_enabled',
			'class'                      => EcpayShippingHilife::class,
		],
		'ys_ec_ecpay_ship_hilife_c2c'     => [
			'alias'                      => 'ship_hilife_c2c',
			'label'                      => '萊爾富店到店（C2C）',
			'channel'                    => self::CHANNEL_C2C,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'HILIFEC2C',
			'cod_capable'                => true,
			'requires_store'             => true,
			// 官方規格：ReturnStoreID 僅 7-ELEVEN C2C 適用。萊爾富店到店不吃這個欄位。
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID', 'CVSPaymentNo' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_hilife_c2c_enabled',
			'class'                      => EcpayShippingHilifeC2C::class,
		],

		// ── 7-ELEVEN 冷凍（獨立 subtype，不是常溫加一個溫層參數；官方僅 B2C） ──
		'ys_ec_ecpay_ship_unimart_freeze' => [
			'alias'                      => 'ship_unimart_freeze',
			'label'                      => '7-ELEVEN 冷凍取貨',
			'channel'                    => self::CHANNEL_B2C,
			'temperature'                => self::TEMP_FROZEN,
			'logistics_type'             => 'CVS',
			'logistics_subtype'          => 'UNIMARTFREEZE',
			'cod_capable'                => true,
			'requires_store'             => true,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => true,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_unimart_freeze_enabled',
			'class'                      => EcpayShippingUnimartFreeze::class,
		],

		// ── 黑貓宅配（三個溫層各自是一個方式） ─────────────────────────────
		'ys_ec_ecpay_ship_tcat'           => [
			'alias'                      => 'ship_tcat',
			'label'                      => '黑貓宅配（常溫）',
			'channel'                    => self::CHANNEL_HOME,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'HOME',
			'logistics_subtype'          => 'TCAT',
			'cod_capable'                => true,
			'requires_store'             => false,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => true,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_tcat_enabled',
			'class'                      => EcpayShippingTcat::class,
		],
		'ys_ec_ecpay_ship_tcat_chilled'   => [
			'alias'                      => 'ship_tcat_chilled',
			'label'                      => '黑貓宅配（冷藏）',
			'channel'                    => self::CHANNEL_HOME,
			'temperature'                => self::TEMP_CHILLED,
			'logistics_type'             => 'HOME',
			'logistics_subtype'          => 'TCAT',
			'cod_capable'                => true,
			'requires_store'             => false,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => true,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_tcat_chilled_enabled',
			'class'                      => EcpayShippingTcatChilled::class,
		],
		'ys_ec_ecpay_ship_tcat_frozen'    => [
			'alias'                      => 'ship_tcat_frozen',
			'label'                      => '黑貓宅配（冷凍）',
			'channel'                    => self::CHANNEL_HOME,
			'temperature'                => self::TEMP_FROZEN,
			'logistics_type'             => 'HOME',
			'logistics_subtype'          => 'TCAT',
			'cod_capable'                => true,
			'requires_store'             => false,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => true,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_tcat_frozen_enabled',
			'class'                      => EcpayShippingTcatFrozen::class,
		],

		// ── 中華郵政宅配 ───────────────────────────────────────────────────
		'ys_ec_ecpay_ship_post'           => [
			'alias'                      => 'ship_post',
			'label'                      => '郵局宅配',
			'channel'                    => self::CHANNEL_HOME,
			'temperature'                => self::TEMP_ROOM,
			'logistics_type'             => 'HOME',
			'logistics_subtype'          => 'POST',
			'cod_capable'                => false,
			'requires_store'             => false,
			'supports_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			// 🔴 綠界官方明載：LogisticsSubType=POST 時 GoodsWeight 必填（上限 20 公斤）。
			'requires_goods_weight'      => true,
			// POST 的 Temperature 可省略（官方預設 0001；若送也只能 0001）。
			// Distance／Specification／ScheduledPickupTime／ScheduledDeliveryTime 請忽略；
			// 本地選擇省略整組宅配條件，採官方常溫預設。
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'AllPayLogisticsID' ],
			'enabled_option'             => 'ys_ec_ecpay_ship_post_enabled',
			'class'                      => EcpayShippingPost::class,
		],
	];

	/**
	 * C2C 的列印端點——與 B2C／宅配**不是**同一支 API。
	 *
	 * 🔴 舊版所有方式一律打 `/helper/printTradeDocument`。那支只認 B2C／宅配的
	 * `AllPayLogisticsID`；C2C 要打各超商專屬端點，而且必須帶**寄貨編號**
	 * （7-ELEVEN 還要驗證碼）。用錯端點的結果是：單建得起來、貨卻印不出託運單。
	 *
	 * 這也是為什麼核心必須把 `CVSPaymentNo`／`CVSValidationNo` 落盤——它們不是
	 * 「追蹤碼的一種」，是賣家把貨交出去的唯一憑據。
	 *
	 * `endpoint_kind` 與 `supports_batch` 是兩個獨立維度：專屬 C2C 端點
	 * 不等於只能單筆。FAMIC2C 與 UNIMARTC2C 專屬端點都支援各欄等長的
	 * 逗號批次；三支專屬 C2C 端點都以 100 筆作為單次批次上限。
	 *
	 * @var array<string,array{path:string,supports_batch:bool,max_batch:int,fields:array<int,string>}>
	 */
	private const PRINT_SPECIFIC = [
		'FAMIC2C'    => [
			'path'           => '/Express/PrintFAMIC2COrderInfo',
			'supports_batch' => true,
			'max_batch'      => 100,
			'fields'         => [ 'AllPayLogisticsID', 'CVSPaymentNo' ],
		],
		'UNIMARTC2C' => [
			'path'           => '/Express/PrintUniMartC2COrderInfo',
			'supports_batch' => true,
			'max_batch'      => 100,
			'fields'         => [ 'AllPayLogisticsID', 'CVSPaymentNo', 'CVSValidationNo' ],
		],
		'HILIFEC2C'  => [
			'path'           => '/Express/PrintHILIFEC2COrderInfo',
			'supports_batch' => true,
			'max_batch'      => 100,
			'fields'         => [ 'AllPayLogisticsID', 'CVSPaymentNo' ],
		],
	];

	/** All current logistics methods share the signed V5 query endpoint. */
	private const QUERY_PATH = '/Helper/QueryLogisticsTradeInfo/V5';

	/**
	 * Provider-confirmed cancellation contracts, keyed by the exact registered
	 * shipping method.  Do not infer cancellation support from `channel=c2c`:
	 * the legacy API below is documented only for 7-ELEVEN C2C.
	 *
	 * @var array<string,array{path:string,logistics_subtype:string,fields:array<int,string>}>
	 */
	private const CANCEL_SPECIFIC = [
		'ys_ec_ecpay_ship_unimart_c2c' => [
			'path'                 => '/Express/CancelC2COrder',
			'logistics_subtype'    => 'UNIMARTC2C',
			'fields'               => [ 'AllPayLogisticsID', 'CVSPaymentNo', 'CVSValidationNo' ],
		],
	];

	/** B2C／宅配共用的列印端點（可一次帶多筆，逗號分隔）。 */
	private const PRINT_DEFAULT_PATH = '/helper/printTradeDocument';

	/**
	 * 取得列印契約：端點、可否批次、需要哪些欄位。
	 *
	 * @return array{path:string,endpoint_kind:string,supports_batch:bool,max_batch:?int,fields:array<int,string>}|null
	 */
	public static function print_spec( string $method_id ): ?array {
		$descriptor = self::get( $method_id );
		if ( null === $descriptor ) {
			return null;
		}

		$subtype = (string) $descriptor['logistics_subtype'];
		if ( ! isset( self::PRINT_SPECIFIC[ $subtype ] ) ) {
			return [
				'path'           => self::PRINT_DEFAULT_PATH,
				'endpoint_kind'  => 'generic',
				'supports_batch' => true,
				'max_batch'      => null,
				'fields'         => [ 'AllPayLogisticsID' ],
			];
		}

		$specific = self::PRINT_SPECIFIC[ $subtype ];

		return [
			'path'           => $specific['path'],
			'endpoint_kind'  => 'specific',
			'supports_batch' => $specific['supports_batch'],
			'max_batch'      => $specific['max_batch'],
			'fields'         => $specific['fields'],
		];
	}

	/** Common signed logistics query endpoint. */
	public static function query_path(): string {
		return self::QUERY_PATH;
	}

	/**
	 * Map the current official ECPay LogisticsStatus table to Core's canonical
	 * pipeline states. Only codes whose meaning is unambiguous across the 11
	 * supported methods are mapped; return-started/request-pending and unknown
	 * codes deliberately remain advisory.
	 */
	public static function pipeline_state_for_logistics_status( string $status ): ?string {
		return match ( trim( $status ) ) {
			'300', '310', '311' => 'preparing',
			'3001', '3006', '3015', '3120', '3301', '3312', '3313' => 'in_transit',
			'2063', '2073', '3018', '3029', '2098' => 'arrived_at_store',
			'2067', '3022', '3003', '3307', '3308', '3309' => 'delivered',
			'2076', '2078', '3025', '3019', '2044', '2070', '3023', '5008', '3310' => 'returned',
			default => null,
		};
	}

	/** Preserve Core's durable shipping-label vocabulary at provider callbacks. */
	public static function label_status_for_pipeline_state( string $pipeline_state ): ?string {
		return match ( trim( $pipeline_state ) ) {
			'preparing'        => 'label_created',
			'in_transit'       => 'in_transit',
			'arrived_at_store' => 'arrived',
			'delivered'        => 'delivered',
			'returned'         => 'returned',
			'failed'           => 'failed',
			default            => null,
		};
	}

	/**
	 * Return the exact cancellation contract for a registered method.
	 *
	 * The subtype is re-bound to the method descriptor before returning the
	 * contract.  A future descriptor rename therefore fails closed instead of
	 * accidentally enabling this endpoint for a different service.
	 *
	 * @return array{path:string,logistics_subtype:string,fields:array<int,string>}|null
	 */
	public static function cancel_spec( string $method_id ): ?array {
		$descriptor = self::get( $method_id );
		$spec       = self::CANCEL_SPECIFIC[ $method_id ] ?? null;
		if ( null === $descriptor || null === $spec ) {
			return null;
		}

		return $spec['logistics_subtype'] === (string) $descriptor['logistics_subtype']
			? $spec
			: null;
	}

	/**
	 * 全部 descriptor（key = method_id）。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return self::METHODS;
	}

	/**
	 * 全部 method_id，順序即後台顯示順序。
	 *
	 * @return array<int,string>
	 */
	public static function ids(): array {
		return array_keys( self::METHODS );
	}

	/**
	 * 取得單一 descriptor；未知 id 回 null（呼叫端一律 fail-closed）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( string $method_id ): ?array {
		return self::METHODS[ $method_id ] ?? null;
	}

	/**
	 * 以 alias 反查 descriptor（回傳值多帶一個 `method_id`）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_by_alias( string $alias ): ?array {
		foreach ( self::METHODS as $method_id => $descriptor ) {
			if ( $descriptor['alias'] === $alias ) {
				return [ 'method_id' => $method_id ] + $descriptor;
			}
		}

		return null;
	}

	/**
	 * Resolve a provider subtype to one credential channel. Duplicate subtype
	 * descriptors (the three TCAT temperatures) are accepted only when every
	 * descriptor belongs to the same channel.
	 */
	public static function channel_for_subtype( string $subtype ): string {
		$subtype = strtoupper( trim( $subtype ) );
		if ( '' === $subtype ) {
			return '';
		}

		$channel = '';
		foreach ( self::METHODS as $descriptor ) {
			if ( $subtype !== strtoupper( (string) ( $descriptor['logistics_subtype'] ?? '' ) ) ) {
				continue;
			}
			$current = (string) ( $descriptor['channel'] ?? '' );
			if ( '' === $current || ( '' !== $channel && $channel !== $current ) ) {
				return '';
			}
			$channel = $current;
		}

		return $channel;
	}

	/**
	 * alias → method_id。
	 *
	 * @return array<string,string>
	 */
	public static function alias_to_id(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = $method_id;
		}

		return $out;
	}

	/**
	 * method_id → alias。
	 *
	 * @return array<string,string>
	 */
	public static function id_to_alias(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ $method_id ] = (string) $descriptor['alias'];
		}

		return $out;
	}

	/**
	 * alias → 啟用開關設定 key（供 Settings 的方式清單合併）。
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
	 * 需要電子地圖（超商）的方式：method_id → LogisticsSubType。
	 *
	 * @return array<string,string>
	 */
	public static function map_subtypes(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			if ( 'CVS' === $descriptor['logistics_type'] ) {
				$out[ $method_id ] = (string) $descriptor['logistics_subtype'];
			}
		}

		return $out;
	}

	/**
	 * 全部 C2C 方式的退貨門市設定 key：method_id → option key。
	 *
	 * @return array<string,string>
	 */
	public static function return_store_options(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			if ( true === $descriptor['supports_return_store'] ) {
				$out[ $method_id ] = (string) $descriptor['return_store_option'];
			}
		}

		return $out;
	}

	/**
	 * manifest 的 shipping.methods 區段——由本表導出，不另抄一份。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function manifest_methods(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$entry = [
				'id'             => $method_id,
				'label'          => (string) $descriptor['label'],
				'provider_label' => 'ECPay',
				'class'          => (string) $descriptor['class'],
				'shipping_type'  => 'CVS' === $descriptor['logistics_type'] ? 'cvs' : 'home',
			];

			if ( 'CVS' === $descriptor['logistics_type'] ) {
				$entry['store_selector'] = EcpayStoreSelector::class;
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * 後台「物流方式」分頁的渲染資料——同樣由本表導出。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function admin_rows(): array {
		$out = [];
		foreach ( self::METHODS as $method_id => $descriptor ) {
			$out[ (string) $descriptor['alias'] ] = [
				'id'                    => $method_id,
				'label'                 => (string) $descriptor['label'],
				'channel'               => (string) $descriptor['channel'],
				'temperature'           => (string) $descriptor['temperature'],
				'logistics_subtype'     => (string) $descriptor['logistics_subtype'],
				'cod_capable'           => (bool) $descriptor['cod_capable'],
				'supports_return_store' => (bool) $descriptor['supports_return_store'],
				'return_store_option'   => (string) $descriptor['return_store_option'],
				'requires_goods_weight' => (bool) $descriptor['requires_goods_weight'],
			];
		}

		return $out;
	}
}
