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
 *   C2C：FAMIC2C / UNIMARTC2C / HILIFEC2C / OKMARTC2C
 *   宅配：TCAT / POST
 *
 * **`UNIMARTFREEZEC2C` 不在其中**——整份官方鏡像（guides＋references＋SDK）
 * 搜不到任何 `FREEZEC2C` 字樣。也就是說 7-ELEVEN 冷凍**只有 B2C**，沒有 C2C。
 *
 * 先前的實作自創了這個 subtype。自創 subtype 的後果不是「少一個選項」，而是
 * 業主開了它、顧客選得到、送單時綠界直接回「找不到加密金鑰，請確認是否有申請
 * 開通此物流方式」——與 B2C/C2C 用錯 subtype 的症狀一模一樣，極難查。
 *
 * 因此本型錄只收官方載明的方式。7-ELEVEN 冷凍 C2C 需要綠界正式書面確認後才能加。
 * （另：官方尚有 `OKMARTC2C`（OK 超商，僅 C2C），本批未在需求範圍內，故未收。）
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
	 * - `requires_return_store`      送單前必須有退貨門市代號（C2C 皆是，綠界規定必填）。
	 * - `return_store_option`        該方式**專屬**的退貨門市設定 key；共用一個 key 會讓
	 *                                全家的退貨寄到 7-11。B2C／宅配為空字串。
	 * - `requires_collection_amount` 綠界載明這些 subtype 需一併傳 `CollectionAmount`。
	 * - `requires_goods_weight`      綠界載明 `POST`（中華郵政）必填 `GoodsWeight`。
	 * - `sends_home_conditions`      是否送宅配專屬條件（溫層／距離／規格／指定時段）。
	 *                                🔴 中華郵政官方明載「請忽略」這些欄位。
	 * - `required_response_fields`   建單回應中缺了就代表「貨出不去」的欄位，缺值 fail-closed。
	 * - `enabled_option`             啟用開關的設定 key。
	 * - `class`                      物流方式類別（每個方式一個獨立類別）。
	 *
	 * 🔴 `cod_capable` 的保守取值：綠界官方文件**沒有**逐 subtype 的代收支援矩陣，
	 * 只載明 `IsCollection` 這個開關與 20,000 元上限。本 session 紀律禁止使用憑證／
	 * 連線實測，因此 7-ELEVEN 冷凍與中華郵政採 fail-closed（false）——寧可少開一個
	 * 能力，也不要讓業主開了代收卻在送單當下被綠界打回。這一項列在 #3S 的
	 * 「未驗 official-provider gates」，正式開通前必須以綠界合約內容確認後再放行。
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => true,
			'return_store_option'        => 'ys_ec_ecpay_ship_family_c2c_return_store_id',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'CVSPaymentNo' ],
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => true,
			'return_store_option'        => 'ys_ec_ecpay_ship_unimart_c2c_return_store_id',
			'requires_collection_amount' => true,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			// 🔴 7-ELEVEN 交貨便要**兩段**：寄貨編號＋驗證碼（官方載明驗證碼為
			// 統一超商專用）。少了驗證碼，賣家到門市機台輸不進去，貨就是出不去。
			'required_response_fields'   => [ 'CVSPaymentNo', 'CVSValidationNo' ],
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => true,
			'return_store_option'        => 'ys_ec_ecpay_ship_hilife_c2c_return_store_id',
			'requires_collection_amount' => false,
			'requires_goods_weight'      => false,
			'sends_home_conditions'      => false,
			'required_response_fields'   => [ 'CVSPaymentNo' ],
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
			'cod_capable'                => false,
			'requires_store'             => true,
			'requires_return_store'      => false,
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => false,
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
			'requires_return_store'      => false,
			'return_store_option'        => '',
			'requires_collection_amount' => false,
			// 🔴 綠界官方明載：LogisticsSubType=POST 時 GoodsWeight 必填（上限 20 公斤）。
			'requires_goods_weight'      => true,
			// 🔴 綠界官方明載：中華郵政請忽略 Temperature／Distance／Specification／
			// ScheduledPickupTime／ScheduledDeliveryTime。舊版對郵局照送這些欄位。
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
	 * @var array<string,string>
	 */
	private const PRINT_PATHS = [
		'FAMIC2C'    => '/Express/PrintFAMIC2COrderInfo',
		'UNIMARTC2C' => '/Express/PrintUniMartC2COrderInfo',
		'HILIFEC2C'  => '/Express/PrintHILIFEC2COrderInfo',
	];

	/** B2C／宅配共用的列印端點（可一次帶多筆，逗號分隔）。 */
	private const PRINT_DEFAULT_PATH = '/helper/printTradeDocument';

	/**
	 * 取得列印契約：端點、可否批次、需要哪些欄位。
	 *
	 * @return array{path:string,batch:bool,fields:array<int,string>}|null
	 */
	public static function print_spec( string $method_id ): ?array {
		$descriptor = self::get( $method_id );
		if ( null === $descriptor ) {
			return null;
		}

		$subtype = (string) $descriptor['logistics_subtype'];
		if ( ! isset( self::PRINT_PATHS[ $subtype ] ) ) {
			return [
				'path'   => self::PRINT_DEFAULT_PATH,
				'batch'  => true,
				'fields' => [ 'AllPayLogisticsID' ],
			];
		}

		$fields = [ 'AllPayLogisticsID', 'CVSPaymentNo' ];
		if ( in_array( 'CVSValidationNo', (array) $descriptor['required_response_fields'], true ) ) {
			$fields[] = 'CVSValidationNo';
		}

		return [
			'path'   => self::PRINT_PATHS[ $subtype ],
			'batch'  => false,
			'fields' => $fields,
		];
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
			if ( true === $descriptor['requires_return_store'] ) {
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
				'requires_return_store' => (bool) $descriptor['requires_return_store'],
				'return_store_option'   => (string) $descriptor['return_store_option'],
				'requires_goods_weight' => (bool) $descriptor['requires_goods_weight'],
			];
		}

		return $out;
	}
}
