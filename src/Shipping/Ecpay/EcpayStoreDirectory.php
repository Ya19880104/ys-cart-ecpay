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

use YangSheep\YSCartEcpay\Support\HttpFormClient;
use YangSheep\YSCartEcpay\Support\Settings;

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
	 * 因此查詢只讀快取；快取由 {@see self::refresh()} 另外補（尚未接排程，
	 * 見交接檔的 stage gate）。查不到就是查不到，呼叫端標成未驗證即可。
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function cached_store_list( string $subtype ): array {
		$cvs_type = self::cvs_type( $subtype );
		if ( '' === $cvs_type ) {
			return [];
		}

		$cached = get_transient( self::CACHE_PREFIX . $cvs_type );

		return is_array( $cached ) ? $cached : [];
	}

	/**
	 * 向綠界重新抓一份門市清單並快取（**不在回呼路徑上呼叫**）
	 *
	 * @return int 快取到幾間門市；0 代表沒抓到（不會把失敗快取成空清單）
	 */
	public static function refresh( string $subtype ): int {
		$cvs_type = self::cvs_type( $subtype );
		if ( '' === $cvs_type ) {
			return 0;
		}

		$credentials = Settings::logistics_credentials();
		if ( '' === $credentials['merchant_id'] ) {
			return 0;
		}

		$response = ( new HttpFormClient() )->post(
			Settings::logistics_endpoint( '/Helper/GetStoreList' ),
			[
				'MerchantID' => $credentials['merchant_id'],
				'CvsType'    => $cvs_type,
			]
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

		set_transient( self::CACHE_PREFIX . $cvs_type, $parsed, self::CACHE_TTL );

		return count( $parsed );
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

		// 回應可能是 { StoreList: [...] } 或直接就是陣列，兩種都試。
		$rows = $decoded;
		foreach ( [ 'StoreList', 'StoreInfo', 'Data', 'data' ] as $wrapper ) {
			if ( isset( $decoded[ $wrapper ] ) && is_array( $decoded[ $wrapper ] ) ) {
				$rows = $decoded[ $wrapper ];
				break;
			}
		}

		$out = [];
		foreach ( (array) $rows as $row ) {
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
	 * 官方 SDK 範例列出的 CvsType：All / FAMI / UNIMART / HILIFE / OKMART /
	 * UNIMARTFREEZE（`SDK_PHP/example/Logistics/Domestic/GetStoreList.php:15-24`）。
	 * C2C 的 subtype 對應到同一個通路。
	 */
	private static function cvs_type( string $subtype ): string {
		$map = [
			'FAMI'          => 'FAMI',
			'FAMIC2C'       => 'FAMI',
			'UNIMART'       => 'UNIMART',
			'UNIMARTC2C'    => 'UNIMART',
			'UNIMARTFREEZE' => 'UNIMARTFREEZE',
			'HILIFE'        => 'HILIFE',
			'HILIFEC2C'     => 'HILIFE',
			'OKMART'        => 'OKMART',
			'OKMARTC2C'     => 'OKMART',
		];

		return (string) ( $map[ strtoupper( $subtype ) ] ?? '' );
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
