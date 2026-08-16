<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\Ecommerce\YSEcommerce;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;

final class Settings {
	public const ENABLED = 'ys_ec_ecpay_enabled';

	public const PAYMENT_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_payment_test_mode',
		'merchant_id' => 'ys_ec_ecpay_payment_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_payment_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_payment_hash_iv',
		// v0.3.0：信用卡明細查詢檢查碼（CreditDetail/QueryTrade 必填；綠界後台取得），加密儲存。
		// 不參與 CheckMacValue 簽章，因此**不屬於 signer_snapshot**；它只是查詢端點的
		// 附加驗證欄位，填錯的症狀是查詢被拒（乾淨失敗），不會造成簽章身分分裂。
		'credit_check_code' => 'ys_ec_ecpay_payment_credit_check_code',
	];

	public const LOGISTICS_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_logistics_test_mode',
		'merchant_id' => 'ys_ec_ecpay_logistics_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_logistics_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_logistics_hash_iv',
	];

	/** B2C convenience-store and home-delivery credentials. */
	public const LOGISTICS_B2C_HOME_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_logistics_b2c_home_test_mode',
		'merchant_id' => 'ys_ec_ecpay_logistics_b2c_home_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_logistics_b2c_home_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_logistics_b2c_home_hash_iv',
	];

	/** C2C convenience-store credentials. */
	public const LOGISTICS_C2C_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_logistics_c2c_test_mode',
		'merchant_id' => 'ys_ec_ecpay_logistics_c2c_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_logistics_c2c_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_logistics_c2c_hash_iv',
	];

	/** Which explicit credential profile signs HOME requests. */
	public const HOME_CREDENTIAL_FAMILY = 'ys_ec_ecpay_home_credential_family';
	public const FAMILY_B2C_HOME = 'b2c_home';
	public const FAMILY_C2C = 'c2c';

	/**
	 * 金流方式的啟用開關。
	 *
	 * 物流的部分**不在這裡**——它由 {@see EcpayShippingCatalog} 導出（見
	 * {@see self::method_keys()}）。抄第二份清單正是「後台勾得到、卻註冊不進去」
	 * 這類半開狀態的來源。
	 */
	public const PAYMENT_METHOD_KEYS = [
		'credit'  => 'ys_ec_ecpay_credit_enabled',
		'atm'     => 'ys_ec_ecpay_atm_enabled',
		'cvs'     => 'ys_ec_ecpay_cvs_enabled',
		'barcode' => 'ys_ec_ecpay_barcode_enabled',
	];

	/**
	 * alias → 啟用開關設定 key（金流 ＋ 物流）。
	 *
	 * @return array<string,string>
	 */
	public static function method_keys(): array {
		return self::PAYMENT_METHOD_KEYS + EcpayShippingCatalog::enabled_option_by_alias();
	}

	/**
	 * 單一 alias 的啟用開關設定 key；未知 alias 回空字串。
	 */
	public static function method_key( string $alias ): string {
		return (string) ( self::method_keys()[ $alias ] ?? '' );
	}

	public const SENDER_KEYS = [
		'name'    => 'shipping_ecpay_sender_name',
		'phone'   => 'shipping_ecpay_sender_phone',
		'zipcode' => 'shipping_ecpay_sender_zipcode',
		'address' => 'shipping_ecpay_sender_address',
	];

	public static function get( string $key, mixed $default = '' ): mixed {
		return YSEcommerce::get_instance()->get_setting( $key, $default );
	}

	public static function update( string $key, mixed $value ): bool {
		return YSEcommerce::get_instance()->update_setting( $key, $value );
	}

	/**
	 * 直讀 settings 表一列（🔴 v0.2.16 R13：commit/rollback 驗證專用）。
	 *
	 * 為什麼不走 get()：core 的 get_setting 有 per-request cache，且把
	 * 「row 不存在」與 default 壓成同一個值——對「寫入是否真的落盤」與
	 * 「row 是否存在」這兩個問題都答不準。本方法繞過 cache 直問 DB，
	 * 並保留 wpdb 錯誤通道（讀取失敗 ≠ 不存在）。
	 *
	 * @return array{ok:bool, existed:bool, value:string} ok=false＝無法判定（fail-closed）
	 */
	public static function db_probe( string $key ): array {
		global $wpdb;
		$fail = [ 'ok' => false, 'existed' => false, 'value' => '' ];
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $fail;
		}
		$table            = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'settings';
		$wpdb->last_error = '';
		try {
			$value = $wpdb->get_var( $wpdb->prepare(
				"SELECT setting_value FROM {$table} WHERE setting_key = %s",
				$key
			) );
		} catch ( \Throwable $e ) {
			return $fail;
		}
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return $fail;
		}

		return [ 'ok' => true, 'existed' => null !== $value, 'value' => null === $value ? '' : (string) $value ];
	}

	/**
	 * 刪除 settings 表一列（🔴 v0.2.16 R13：rollback 恢復「row 不存在」態專用）。
	 *
	 * 「row 不存在」是一個**狀態**，不是空值：reader 對 absent 的預設值
	 * （例如 test_mode 預設 '1'＝test）可能與任何顯式值都不同。rollback 把
	 * 原本不存在的 key 寫成 '' 會悄悄改變 effective 行為（''≠absent）。
	 * 呼叫端必須以 db_probe() 驗證刪除結果（core 2.56.12 無 cache 逐鍵失效
	 * API，本 request 內的 get() 可能仍回快取值——驗證一律走 DB 直讀）。
	 */
	public static function delete( string $key ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return false;
		}
		$table  = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'settings';
		$result = $wpdb->delete( $table, [ 'setting_key' => $key ] );
		if ( false === $result ) {
			return false;
		}
		$core = YSEcommerce::get_instance();
		if ( method_exists( $core, 'forget_setting' ) ) {
			$core->forget_setting( $key ); // 新核心有逐鍵失效 API 時順手清 cache。
		}

		return true;
	}

	public static function enabled(): bool {
		return '1' === (string) self::get( self::ENABLED, '0' );
	}

	public static function gateway_enabled( string $key ): bool {
		$setting_key = self::method_key( $key );
		return '' !== $setting_key && self::enabled() && '1' === (string) self::get( $setting_key, '0' );
	}

	public static function shipping_enabled( string $key ): bool {
		$setting_key = self::method_key( $key );
		return '' !== $setting_key && self::enabled() && '1' === (string) self::get( $setting_key, '0' );
	}

	/**
	 * @param array<string,string|null>|null $pending 見 {@see self::logistics_credentials_for_channel()}
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function payment_credentials( ?array $pending = null ): array {
		return self::credentials( self::PAYMENT_KEYS, $pending );
	}

	/**
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials(): array {
		$b2c = self::logistics_credentials_for_channel( EcpayShippingCatalog::CHANNEL_B2C );
		$c2c = self::logistics_credentials_for_channel( EcpayShippingCatalog::CHANNEL_C2C );
		$b2c_ok = self::credentials_complete( $b2c );
		$c2c_ok = self::credentials_complete( $c2c );
		if ( $b2c_ok && ! $c2c_ok ) {
			return $b2c;
		}
		if ( $c2c_ok && ! $b2c_ok ) {
			return $c2c;
		}

		return self::empty_credentials();
	}

	/**
	 * Resolve the credential family from the catalog entry for one exact method.
	 *
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials_for_method( string $method_id ): array {
		$descriptor = EcpayShippingCatalog::get( trim( $method_id ) );
		if ( null === $descriptor ) {
			return self::empty_credentials();
		}

		return self::logistics_credentials_for_channel( (string) ( $descriptor['channel'] ?? '' ) );
	}

	/**
	 * Resolve credentials for an exact provider catalog channel.
	 *
	 * HOME is an independent ECPay LogisticsType. Production merchants may have
	 * it enabled on either their B2C or C2C credential profile, so the operator
	 * must declare the profile instead of the plugin inferring it from sandbox
	 * account groupings. The default remains B2C/home for backward compatibility.
	 *
	 * 🔴 v0.2.16 R13 `$pending` overlay：設定 request 用它在**寫入之前**評估
	 * 「commit 後的 effective 簽章」——key 存在於 $pending 即以其值取代 DB 讀
	 * （值為 null＝視同 row 不存在→退 reader 預設）。null pending＝純 DB 讀，
	 * runtime 路徑完全不變。secrets 在 overlay 中一律是**儲存格式**（密文）。
	 *
	 * @param array<string,string|null>|null $pending
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials_for_channel( string $channel, ?array $pending = null ): array {
		$family = self::credential_family_for_channel( $channel, $pending );
		if ( '' === $family ) {
			return self::empty_credentials();
		}

		$b2c = self::credentials( self::LOGISTICS_B2C_HOME_KEYS, $pending );
		$c2c = self::credentials( self::LOGISTICS_C2C_KEYS, $pending );
		if ( self::credentials_complete( $b2c )
			&& self::credentials_complete( $c2c )
			&& self::same_credential_tuple( $b2c, $c2c ) ) {
			return self::empty_credentials();
		}

		$selected = 'c2c' === $family ? $c2c : $b2c;
		if ( self::credentials_complete( $selected ) ) {
			return $selected;
		}
		// Any partial explicit group is an operator error, not permission to use
		// an unrelated legacy account.
		if ( self::credentials_started( $selected ) ) {
			return self::empty_credentials();
		}

		// ── v0.2.16 顯式「重用金流憑證」模式 ──
		//
		// 綠界正式商家通常只有一組介接金鑰（金流物流同一 MID；B2C/C2C 差在後台
		// 開通能力），因此提供**顯式**開關讓物流簽章重用金流 tuple——不是隱性
		// fallback。生效條件全部成立才回金流憑證，任一不成立 fail-closed：
		//   1. 開關 ys_ec_ecpay_logistics_reuse_payment = '1'；
		//   2. 兩個新物流組與 legacy 組**全空**（任何 partial 都不進本分支，
		//      落回原有規則被各自的 partial 守門拒絕）；
		//   3. 金流 tuple 完整；環境（test_mode）以金流為單一真相。
		// 與 legacy fallback 不同：不要求 family 唯一——同一組金鑰服務 B2C/HOME/C2C
		// 正是本模式的目的，能否使用以綠界後台對該 MID 的開通能力為準。
		$legacy = self::credentials( self::LOGISTICS_KEYS, $pending );
		if ( self::payment_reuse_enabled( $pending )
			&& ! self::credentials_started( $b2c )
			&& ! self::credentials_started( $c2c )
			&& ! self::credentials_started( $legacy ) ) {
			$payment = self::payment_credentials( $pending );
			// 開關開了但金流不完整＝設定錯誤，不得改用任何其他來源。
			return self::credentials_complete( $payment ) ? $payment : self::empty_credentials();
		}

		// Legacy fallback is deliberately narrow: both new groups must be empty,
		// and enabled method switches must identify exactly one credential family.
		// This supports a single-family historical install without ever allowing
		// the old tuple to service B2C/home and C2C simultaneously.
		if ( self::credentials_started( $b2c ) || self::credentials_started( $c2c )
			|| $family !== self::unambiguous_legacy_family( $pending ) ) {
			return self::empty_credentials();
		}

		return self::credentials_complete( $legacy ) ? $legacy : self::empty_credentials();
	}

	/**
	 * 顯式「物流重用金流憑證」開關（v0.2.16）。
	 *
	 * @param array<string,string|null>|null $pending 見 logistics_credentials_for_channel()
	 */
	public static function payment_reuse_enabled( ?array $pending = null ): bool {
		return '1' === self::read_with_pending( 'ys_ec_ecpay_logistics_reuse_payment', '0', $pending );
	}

	/**
	 * pending-overlay 讀值：key 在 $pending 中即以其值取代 DB（null＝視同 row
	 * 不存在→回 $default）；否則走一般 get()。
	 *
	 * @param array<string,string|null>|null $pending
	 */
	private static function read_with_pending( string $key, string $default, ?array $pending ): string {
		if ( null !== $pending && array_key_exists( $key, $pending ) ) {
			return null === $pending[ $key ] ? $default : (string) $pending[ $key ];
		}

		return (string) self::get( $key, $default );
	}

	/**
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials_for_subtype( string $subtype ): array {
		$channel = EcpayShippingCatalog::channel_for_subtype( $subtype );
		return '' === $channel ? self::empty_credentials() : self::logistics_credentials_for_channel( $channel );
	}

	/**
	 * @param array<string,string>           $keys
	 * @param array<string,string|null>|null $pending
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	private static function credentials( array $keys, ?array $pending = null ): array {
		$raw_key = self::read_with_pending( $keys['hash_key'], '', $pending );
		$raw_iv  = self::read_with_pending( $keys['hash_iv'], '', $pending );

		$credentials = [
			// 🔴 v0.2.16 R13：live **只在顯式 '0'** 時成立——absent／''／其他值一律
			// test（fail-safe 朝 stage：環境判定不明時打測試端點會大聲失敗，而不是
			// 拿不確定的環境打正式機）。沒有任何 writer 會產生「tuple 完整＋test_mode
			// 空字串」的狀態；此硬化是 rollback 語意的第二道防線。
			'test_mode'   => '0' !== self::read_with_pending( $keys['test_mode'], '1', $pending ),
			'merchant_id' => self::read_with_pending( $keys['merchant_id'], '', $pending ),
			'hash_key'    => self::decrypt_secret( $raw_key ),
			'hash_iv'     => self::decrypt_secret( $raw_iv ),
		];

		// 信用卡查詢檢查碼（僅 PAYMENT_KEYS 有；logistics 各群組無此欄位）。
		if ( isset( $keys['credit_check_code'] ) ) {
			$credentials['credit_check_code'] = self::decrypt_secret(
				self::read_with_pending( $keys['credit_check_code'], '', $pending )
			);
		}

		return $credentials;
	}

	public static function decrypt_secret( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( ! class_exists( YSCrypto::class ) || ! method_exists( YSCrypto::class, 'decrypt_from_storage' ) ) {
			return '';
		}

		try {
			$plain = (string) YSCrypto::decrypt_from_storage( $stored );
		} catch ( \Throwable $error ) {
			return '';
		}
		return '' !== $plain ? $plain : $stored;
	}

	public static function encrypt_secret( string $plain ): string {
		if ( ! class_exists( YSCrypto::class ) || ! method_exists( YSCrypto::class, 'encrypt_for_storage' ) ) {
			throw new \RuntimeException( 'YS CART encrypted-secret capability is unavailable.' );
		}

		$encrypted = (string) YSCrypto::encrypt_for_storage( $plain );
		if ( '' === $encrypted ) {
			throw new \RuntimeException( 'YS CART failed to encrypt the provider secret.' );
		}

		return $encrypted;
	}

	public static function payment_endpoint(): string {
		$credentials = self::payment_credentials();
		return $credentials['test_mode']
			? 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5'
			: 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5';
	}

	public static function payment_query_endpoint(): string {
		$credentials = self::payment_credentials();
		return $credentials['test_mode']
			? 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5'
			: 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5';
	}

	/**
	 * 信用卡請退款操作端點（CreditDetail/DoAction）— v0.3.0 信用卡退刷用。
	 *
	 * ⚠ 綠界官方明載：**測試環境（stage）因無實際授權，DoAction 不可用**——
	 * stage URL 僅保留結構一致性；實際驗證一律走受控正式商店小額實測
	 * （見 docs/credit-refund-sandbox-gate.md）。
	 */
	public static function payment_do_action_endpoint(): string {
		$credentials = self::payment_credentials();
		return $credentials['test_mode']
			? 'https://payment-stage.ecpay.com.tw/CreditDetail/DoAction'
			: 'https://payment.ecpay.com.tw/CreditDetail/DoAction';
	}

	/**
	 * 信用卡交易關帳狀態查詢端點（CreditDetail/QueryTrade/V2）— query-first 退款分流用。
	 */
	public static function payment_credit_query_endpoint(): string {
		$credentials = self::payment_credentials();
		return $credentials['test_mode']
			? 'https://payment-stage.ecpay.com.tw/CreditDetail/QueryTrade/V2'
			: 'https://payment.ecpay.com.tw/CreditDetail/QueryTrade/V2';
	}

	public static function logistics_endpoint( string $path = '', string $method_id = '' ): string {
		$credentials = '' === trim( $method_id )
			? self::logistics_credentials()
			: self::logistics_credentials_for_method( $method_id );
		return self::logistics_endpoint_from_credentials( $path, $credentials );
	}

	public static function logistics_endpoint_for_channel( string $path, string $channel ): string {
		return self::logistics_endpoint_from_credentials( $path, self::logistics_credentials_for_channel( $channel ) );
	}

	/** @param array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} $credentials */
	private static function logistics_endpoint_from_credentials( string $path, array $credentials ): string {
		$base = $credentials['test_mode']
			? 'https://logistics-stage.ecpay.com.tw'
			: 'https://logistics.ecpay.com.tw';

		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	public static function shipping_method_option( string $method_id, string $key, mixed $default = '' ): mixed {
		return self::get( 'shipping_' . $method_id . '_' . $key, $default );
	}

	public static function shipping_base_fee( string $method_id ): float {
		return max( 0.0, (float) self::shipping_method_option( $method_id, 'base_fee', '0' ) );
	}

	public static function shipping_free_threshold( string $method_id ): float {
		return max( 0.0, (float) self::shipping_method_option( $method_id, 'free_threshold', '0' ) );
	}

	public static function has_payment_credentials(): bool {
		$c = self::payment_credentials();
		return '' !== $c['merchant_id'] && '' !== $c['hash_key'] && '' !== $c['hash_iv'];
	}

	public static function has_logistics_credentials(): bool {
		$c = self::logistics_credentials();
		return self::credentials_complete( $c );
	}

	public static function has_logistics_credentials_for_method( string $method_id ): bool {
		return self::credentials_complete( self::logistics_credentials_for_method( $method_id ) );
	}

	/** @param array<string,string|null>|null $pending 見 logistics_credentials_for_channel() */
	public static function home_credential_family( ?array $pending = null ): string {
		// pending overlay：設定 request 在寫入前評估「commit 後」的 family。
		// null＝視同 row 不存在→沿用歷史預設 b2c_home。
		if ( null !== $pending && array_key_exists( self::HOME_CREDENTIAL_FAMILY, $pending ) ) {
			$pending_value = $pending[ self::HOME_CREDENTIAL_FAMILY ];
			return null === $pending_value
				? self::FAMILY_B2C_HOME
				: self::normalize_home_credential_family( (string) $pending_value );
		}

		global $wpdb;

		// Core's generic get_setting() intentionally collapses a SQL error and a
		// missing row into the same default (and caches that miss).  That semantic
		// is unsafe for a cryptographic signer selector: a transient read failure
		// must never choose the historical B2C signer.  Read this one row directly
		// and retain wpdb's error channel.
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return '';
		}
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'settings';
		$wpdb->last_error = '';
		try {
			$query = $wpdb->prepare(
				"SELECT id, setting_value FROM {$table} WHERE setting_key = %s LIMIT 1",
				self::HOME_CREDENTIAL_FAMILY
			);
			if ( ! is_string( $query ) || '' === $query ) {
				return '';
			}
			$row = $wpdb->get_row( $query );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return '';
		}
		// The option did not exist before v0.2.15.  Only that genuinely missing
		// value inherits the historical B2C/home behavior.  A persisted unknown
		// value is corrupted operator state and must not silently select a signer.
		if ( null === $row ) {
			return self::FAMILY_B2C_HOME;
		}
		if ( ! is_object( $row ) || ! property_exists( $row, 'setting_value' ) ) {
			return '';
		}
		$raw = $row->setting_value;
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		return self::normalize_home_credential_family( (string) $raw );
	}

	public static function normalize_home_credential_family( string $family ): string {
		$family = strtolower( trim( $family ) );
		return in_array( $family, [ self::FAMILY_B2C_HOME, self::FAMILY_C2C ], true )
			? $family
			: '';
	}

	/** @param array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} $credentials */
	private static function credentials_complete( array $credentials ): bool {
		return '' !== $credentials['merchant_id'] && '' !== $credentials['hash_key'] && '' !== $credentials['hash_iv'];
	}

	/** @param array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} $credentials */
	private static function credentials_started( array $credentials ): bool {
		return '' !== $credentials['merchant_id'] || '' !== $credentials['hash_key'] || '' !== $credentials['hash_iv'];
	}

	/**
	 * @param array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} $left
	 * @param array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} $right
	 */
	private static function same_credential_tuple( array $left, array $right ): bool {
		// Environment selects an endpoint; it is not part of the signing
		// identity. Reusing the same MID/key/IV across the two channel families
		// would make callbacks impossible to bind even if one endpoint is test
		// and the other is live.
		return hash_equals( $left['merchant_id'], $right['merchant_id'] )
			&& hash_equals( $left['hash_key'], $right['hash_key'] )
			&& hash_equals( $left['hash_iv'], $right['hash_iv'] );
	}

	/** @param array<string,string|null>|null $pending */
	private static function credential_family_for_channel( string $channel, ?array $pending = null ): string {
		return match ( trim( $channel ) ) {
			EcpayShippingCatalog::CHANNEL_B2C => self::FAMILY_B2C_HOME,
			EcpayShippingCatalog::CHANNEL_HOME => self::home_credential_family( $pending ),
			EcpayShippingCatalog::CHANNEL_C2C => self::FAMILY_C2C,
			default => '',
		};
	}

	/** @param array<string,string|null>|null $pending */
	private static function unambiguous_legacy_family( ?array $pending = null ): string {
		$families = [];
		$lifecycle = '\\YangSheep\\Ecommerce\\Core\\Provider\\YSProviderLifecycleState';
		$has_lifecycle = class_exists( $lifecycle )
			&& class_exists( Plugin::class )
			&& method_exists( $lifecycle, 'is_provider_enabled' )
			&& method_exists( $lifecycle, 'is_capability_enabled' )
			&& method_exists( $lifecycle, 'is_method_enabled' );
		$manifest = $has_lifecycle ? Plugin::manifest() : [];
		if ( $has_lifecycle
			&& ( ! $lifecycle::is_provider_enabled( 'ys_ecpay', $manifest )
				|| ! $lifecycle::is_capability_enabled( 'ys_ecpay', 'shipping', $manifest ) ) ) {
			return '';
		}
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			$alias = (string) ( $descriptor['alias'] ?? '' );
			$family = self::credential_family_for_channel( (string) ( $descriptor['channel'] ?? '' ), $pending );
			$enabled = $has_lifecycle
				? $lifecycle::is_method_enabled( 'shipping', (string) $method_id, $manifest )
				: self::shipping_enabled( $alias );
			if ( '' !== $alias && '' !== $family && $enabled ) {
				$families[ $family ] = true;
			}
		}

		return 1 === count( $families ) ? (string) array_key_first( $families ) : '';
	}

	/** @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} */
	private static function empty_credentials(): array {
		return [ 'test_mode' => true, 'merchant_id' => '', 'hash_key' => '', 'hash_iv' => '' ];
	}
}
