<?php
/**
 * 綠界門市目錄 —— 門市名稱與地址的 canonical 來源
 *
 * 🔴 為什麼需要它（CODEX #3W C6／E）：
 *
 * 電子地圖的回呼是**瀏覽器**帶回來的，`CVSStoreName` 與 `CVSAddress` 因此是
 * 使用者可控的字串。舊版直接把它們寫進訂單，於是「門市代號對、名稱地址被改掉」
 * 這件事在系統裡完全看不出來——出貨單、通知信、客服畫面上的門市都是假的。
 *
 * 真正上 wire 的只有 `CVSStoreID`（建單的 `ReceiverStoreID`），所以貨不會寄錯；
 * 但紀錄錯了一樣會出事（顧客拿著錯的門市名去領貨、客服查不到）。
 *
 * 這裡以綠界官方的 `/Helper/GetStoreList` 取得 canonical 的門市 tuple 並快取。
 *
 * 🔴 誠實說明證據等級：官方鏡像有這個端點與送出參數
 * （`guides/06-logistics-domestic.md:350`、`SDK_PHP/example/Logistics/Domestic/GetStoreList.php:12-26`），
 * 但**沒有回應欄位的定義**。因此欄位對應由 filter `ys_ec_ecpay_store_list_parse`
 * 提供，預設實作只認幾個常見的鍵名；**查不到就查不到**，絕不猜。
 * 查不到時呼叫端必須把資料標成未驗證，而不是拿瀏覽器帶回來的值冒充。
 *
 * @package YangSheep\YSCartEcpay
 */

declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

use YangSheep\YSCartEcpay\Support\CheckMacValue;
use YangSheep\YSCartEcpay\Support\HttpFormClient;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;

defined( 'ABSPATH' ) || exit;

final class EcpayStoreDirectory {

	private const CACHE_PREFIX = 'ys_ec_ecpay_stores_';

	/** 門市清單快取 12 小時；門市不會一小時換一次。 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * 查一間門市的 canonical 資料
	 *
	 * @return array{name?:string,address?:string,phone?:string} 查不到時回空陣列
	 */
	public static function lookup( string $subtype, string $store_id ): array {
		$subtype  = trim( $subtype );
		$store_id = trim( $store_id );

		if ( '' === $subtype || '' === $store_id ) {
			return [];
		}

		/**
		 * 直接注入門市資料（測試與離線環境用；也讓站方可以自己接一份清單）
		 *
		 * 回傳非空陣列就直接採用，不會發任何 HTTP 請求。
		 *
		 * @param array<string,string> $store    空陣列代表「這裡沒有答案」
		 * @param string               $subtype  綠界 LogisticsSubType
		 * @param string               $store_id 門市代號
		 */
		$injected = apply_filters( 'ys_ec_ecpay_store_lookup', [], $subtype, $store_id );
		if ( is_array( $injected ) && [] !== $injected ) {
			return self::normalize( $injected );
		}

		$list = self::cached_store_list( $subtype );
		if ( ! isset( $list[ $store_id ] ) ) {
			return [];
		}

		return self::normalize( $list[ $store_id ] );
	}

	/**
	 * 已快取的門市清單（**不會**發 HTTP）
	 *
	 * 🔴 選店回呼是顧客正在等的那一個請求。在它裡面同步打一支外部 API：
	 * 對方慢一秒顧客就多等一秒，對方掛掉顧客就卡住——而換來的只是
	 * 「門市名稱好看一點」。名稱與地址**不上 wire**（建單只送 `ReceiverStoreID`），
	 * 所以它們永遠不值得擋住結帳。
	 *
	 * 因此查詢只讀快取；快取由排程（{@see self::register_cron()}）與
	 * 回呼未命中時的一次性補抓（{@see self::schedule_refresh_soon()}）維持。
	 * 查不到就是查不到，呼叫端標成未驗證即可。
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function cached_store_list( string $subtype ): array {
		// The credential-scoped key and its transient are one logical read. Keep an
		// outer lease across both so a writer cannot rotate credentials after key
		// derivation and before the old authority's cache is consumed.
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return [];
		}
		$cache_key = self::cache_key( $subtype );
		if ( '' === $cache_key ) {
			return [];
		}
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return [];
		}

		$cached = get_transient( $cache_key );

		return is_array( $cached ) ? $cached : [];
	}

	/**
	 * 向綠界重新抓一份門市清單並快取（**不在回呼路徑上同步呼叫**）
	 *
	 * @return int 快取到幾間門市；0 代表沒抓到（不會把失敗快取成空清單）
	 */
	public static function refresh( string $subtype ): int {
		$cvs_type = self::cvs_type( $subtype );
		$channel  = EcpayShippingCatalog::channel_for_subtype( $subtype );
		if ( '' === $cvs_type || '' === $channel
			|| ! ShippingMethodOperability::has_operable_store_method( $subtype ) ) {
			return 0;
		}

		// 🔴 R14 reader lease：門市清單請求以當下憑證簽章——設定 commit 期間
		// 不出網（回 0＝既有「查不到」語意，下次排程重試）。
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return 0;
		}
		$cache_key = self::cache_key( $subtype );
		if ( '' === $cache_key ) {
			return 0;
		}

		$credentials = Settings::logistics_credentials_for_subtype( $subtype );
		if ( '' === $credentials['merchant_id'] || '' === $credentials['hash_key'] || '' === $credentials['hash_iv'] ) {
			return 0;
		}

		// 🔴 官方契約要求 `CheckMacValue`（developers.ecpay.com.tw/47496）。
		// v0.2.13 第一版漏簽——stage G1 之所以成功，是測試腳本自己簽的；
		// production 沒簽的話正式環境會直接被打回。
		$fields = [
			'MerchantID' => $credentials['merchant_id'],
			'CvsType'    => $cvs_type,
		];
		$fields['CheckMacValue'] = CheckMacValue::generate(
			$fields,
			$credentials['hash_key'],
			$credentials['hash_iv'],
			'md5'
		);
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return 0;
		}

		$response = ( new HttpFormClient() )->post(
			Settings::logistics_endpoint_for_channel( '/Helper/GetStoreList', $channel ),
			$fields
		);

		if ( empty( $response['success'] ) ) {
			// 🔴 查不到就是查不到。**不要**把失敗快取成空清單——那會讓接下來
			// 12 小時內每一次選店都被標成未驗證，而原因只是一次網路抖動。
			return 0;
		}

		$parsed = self::parse_store_list( (string) ( $response['body'] ?? '' ), $cvs_type );
		if ( [] === $parsed ) {
			return 0;
		}

		set_transient( $cache_key, $parsed, self::CACHE_TTL );

		// 🔴 讀回來**逐位比對**確認快取真的落了。`set_transient()` 的回傳值不可靠
		// （值相同時 `update_option()` 回 false）；而只比筆數會被「舊快取剛好
		// 同筆數」騙過（CODEX 二審 blocker #2）——寫入失敗＋殘留三筆舊門市＝
		// count 相等，refresh 卻回報成功，舊門市繼續被當 canonical。
		$stored = get_transient( $cache_key );
		if ( $stored !== $parsed ) {
			return 0;
		}

		return count( $parsed );
	}

	/**
	 * 排程與回呼未命中的接線（production 的**實際 caller**）
	 *
	 * 🔴 v0.2.13 第一版只有測試會呼叫 `refresh()`——安裝之後快取永遠不會建立，
	 * `store_verified` 恆為 0，整個門市目錄形同虛設。這裡補上兩條 production 路徑：
	 *
	 *   1. 週期排程：`register_cron()`（由 Plugin::init() 呼叫）掛上 twicedaily
	 *      事件，handler 是 {@see self::refresh_enabled_channels()}。
	 *   2. 未命中補抓：回呼查不到 canonical 時，排一次 60 秒後的單發事件
	 *      （{@see self::schedule_refresh_soon()}）——第一位顧客選店可能還是
	 *      unverified，但目錄會在一分鐘內補起來，不會永遠空著。
	 */
	public const CRON_HOOK = 'ys_ec_ecpay_refresh_store_directory';

	/**
	 * 未命中補抓的單發事件用**獨立的** hook（CODEX 二審 blocker #1）：
	 * 與 twicedaily 共用 CRON_HOOK 時，`wp_next_scheduled()` 永遠命中週期事件，
	 * 單發因此一次都排不進去——「60 秒補抓」實際不存在，未命中要等最多 12 小時。
	 */
	public const CRON_HOOK_SOON = 'ys_ec_ecpay_refresh_store_directory_soon';

	public static function register_cron(): void {
		if ( ! ShippingMethodOperability::has_operable_store_method() ) {
			self::clear_schedules();
			return;
		}

		add_action( self::CRON_HOOK, [ self::class, 'refresh_enabled_channels' ] );
		add_action( self::CRON_HOOK_SOON, [ self::class, 'refresh_enabled_channels' ] );

		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		// Handler 端另有同一道 operability gate，雙層 fail-closed：
		// 這裡管「不要醒來」，那裡管「醒來也不准打 HTTP」。
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			// 首跑排在一分鐘後：新安裝不必等半天才有第一份目錄。
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * 回呼查不到 canonical 時，排一次近期的單發補抓（不在回呼內同步打 API）。
	 *
	 * 去重只對**自己的** hook（{@see self::CRON_HOOK_SOON}）：對 CRON_HOOK 去重
	 * 會被永遠存在的週期事件吃掉，單發從此排不進去。
	 */
	public static function schedule_refresh_soon(): void {
		if ( ! ShippingMethodOperability::has_operable_store_method() ) {
			self::clear_schedules( [ self::CRON_HOOK_SOON ] );
			return;
		}

		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK_SOON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK_SOON );
		}
	}

	/**
	 * 依型錄把**已啟用**的超商方式收斂成不重複的通路，逐一 refresh。
	 *
	 * @return array<string,int> cvs_type => 快取到的門市數
	 */
	public static function refresh_enabled_channels(): array {
		$types = [];
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			if ( empty( $descriptor['requires_store'] ) ) {
				continue;
			}
			// Use the same L1 × L2 × exact-L3 × credentials × configuration gate
			// as registration and direct request surfaces.
			if ( ! ShippingMethodOperability::is_operable( (string) $method_id ) ) {
				continue;
			}
			$cvs = self::cvs_type( (string) $descriptor['logistics_subtype'] );
			$subtype = (string) $descriptor['logistics_subtype'];
			if ( '' !== $cvs ) {
				$types[ $subtype ] = $subtype;
			}
		}

		$out = [];
		foreach ( $types as $subtype ) {
			$out[ $subtype ] = self::refresh( $subtype );
		}

		return $out;
	}

	/**
	 * Keep equal store channels isolated by their current signing authority.
	 *
	 * Stage e-map rows are dummy data, and different merchant contracts can
	 * expose different canonical stores for the same CvsType. A credential or
	 * environment rotation must therefore miss the old cache immediately.
	 */
	private static function cache_key( string $subtype ): string {
		$cvs_type = self::cvs_type( $subtype );
		$channel  = EcpayShippingCatalog::channel_for_subtype( $subtype );
		if ( '' === $cvs_type || '' === $channel ) {
			return '';
		}
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return '';
		}
		$credentials = Settings::logistics_credentials_for_subtype( $subtype );
		if ( '' === (string) ( $credentials['merchant_id'] ?? '' )
			|| '' === (string) ( $credentials['hash_key'] ?? '' )
			|| '' === (string) ( $credentials['hash_iv'] ?? '' ) ) {
			return '';
		}
		$family = EcpayShippingCatalog::CHANNEL_C2C === $channel ? 'C2C' : 'B2C_HOME';
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return '';
		}
		$fingerprint = hash_hmac(
			'sha256',
			$family . "\0" . $cvs_type . "\0"
				. ( ! empty( $credentials['test_mode'] ) ? 'stage' : 'live' ) . "\0"
				. (string) $credentials['merchant_id'],
			(string) $credentials['hash_key'] . "\0" . (string) $credentials['hash_iv']
		);
		return self::CACHE_PREFIX . $family . '_' . $cvs_type . '_' . substr( $fingerprint, 0, 20 );
	}

	/**
	 * 把 `/Helper/GetStoreList` 的回應解析成 `門市代號 => tuple`
	 *
	 * 🔴 官方鏡像沒有這個 API 的回應欄位定義（只有端點與送出參數），
	 * 所以這裡的鍵名對應是**推測**，並且開了一個 filter 讓它可以被換掉。
	 * 解不出來就回空陣列——寧可標成「未驗證」，也不要對著猜出來的欄位名
	 * 生出一份看起來很權威的假資料。
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function parse_store_list( string $body, string $cvs_type ): array {
		/**
		 * 自行解析門市清單回應
		 *
		 * @param array<string,array<string,string>> $parsed   空陣列代表「交給預設實作」
		 * @param string                             $body     原始回應
		 * @param string                             $cvs_type 綠界 CvsType
		 */
		$custom = apply_filters( 'ys_ec_ecpay_store_list_parse', [], $body, $cvs_type );
		if ( is_array( $custom ) && [] !== $custom ) {
			return $custom;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		// 官方回應帶 RtnCode（1=成功）。明確說失敗就不要把空清單快取成「查過了」。
		if ( isset( $decoded['RtnCode'] ) && 1 !== (int) $decoded['RtnCode'] ) {
			return [];
		}

		// 回應可能是 { StoreList: [...] } 或直接就是陣列，兩種都試。
		$rows = $decoded;
		foreach ( [ 'StoreList', 'StoreInfo', 'Data', 'data' ] as $wrapper ) {
			if ( isset( $decoded[ $wrapper ] ) && is_array( $decoded[ $wrapper ] ) ) {
				$rows = $decoded[ $wrapper ];
				break;
			}
		}

		// 🔴 真實形狀（stage 實測 2026-08-13，evidence-stage-2/G1-storelist.json）是
		// **兩層巢狀**：`StoreList[]` 的每個元素是 `{CvsType, StoreInfo:[門市…]}`，
		// 門市列在 `StoreInfo` 裡面。v0.2.12 把群組元素直接當門市列，找不到
		// `StoreId` → 解出空陣列 → 所有選店永遠 unverified。
		//
		// 🔴 群組必須**綁定請求的通路**：只攤平 `CvsType` 等於本次請求的群組。
		// 官方對多通路請求（CvsType=All）會回多個群組，而不同通路可能有**同號**
		// 門市——不比對就攤平，會把別家通路的門市名掛在這一家的號碼上，
		// 然後標成 canonical verified。直接就是門市列的形狀（filter 注入）照收。
		$flat = [];
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row['StoreInfo'] ) && is_array( $row['StoreInfo'] ) ) {
				$group_type = strtoupper( trim( (string) ( $row['CvsType'] ?? '' ) ) );
				if ( $group_type !== strtoupper( $cvs_type ) ) {
					continue;
				}
				foreach ( $row['StoreInfo'] as $store_row ) {
					$flat[] = $store_row;
				}
				continue;
			}
			if ( is_array( $row ) ) {
				$direct_type = strtoupper( trim( (string) ( $row['CvsType'] ?? '' ) ) );
				if ( '' !== $direct_type && $direct_type !== strtoupper( $cvs_type ) ) {
					continue;
				}
			}
			$flat[] = $row;
		}

		$out = [];
		foreach ( $flat as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = '';
			foreach ( [ 'StoreId', 'StoreID', 'CVSStoreID', 'StoreCode' ] as $key ) {
				if ( '' !== trim( (string) ( $row[ $key ] ?? '' ) ) ) {
					$id = trim( (string) $row[ $key ] );
					break;
				}
			}

			if ( '' === $id ) {
				continue;
			}

			$out[ $id ] = [
				'name'    => self::first_of( $row, [ 'StoreName', 'CVSStoreName', 'Name' ] ),
				'address' => self::first_of( $row, [ 'StoreAddr', 'StoreAddress', 'CVSAddress', 'Address' ] ),
				'phone'   => self::first_of( $row, [ 'StoreTel', 'StorePhone', 'CVSTelephone', 'Telephone' ] ),
			];
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string>   $keys
	 */
	private static function first_of( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * `LogisticsSubType` → `CvsType`
	 *
	 * Map supported C2C subtypes to the same base store-directory channel.
	 */
	private static function cvs_type( string $subtype ): string {
		return ShippingMethodOperability::store_channel( $subtype );
	}

	/** @param array<int,string> $hooks */
	private static function clear_schedules( array $hooks = [ self::CRON_HOOK, self::CRON_HOOK_SOON ] ): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		foreach ( $hooks as $hook ) {
			if ( false !== wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}

	/**
	 * @param array<string,mixed> $tuple
	 * @return array{name:string,address:string,phone:string}
	 */
	private static function normalize( array $tuple ): array {
		return [
			'name'    => trim( (string) ( $tuple['name'] ?? '' ) ),
			'address' => trim( (string) ( $tuple['address'] ?? '' ) ),
			'phone'   => trim( (string) ( $tuple['phone'] ?? '' ) ),
		];
	}
}
