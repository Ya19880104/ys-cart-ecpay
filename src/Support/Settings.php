<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\Ecommerce\YSEcommerce;

final class Settings {
	public const ENABLED = 'ys_ec_ecpay_enabled';

	public const PAYMENT_KEYS = [
		'test_mode'         => 'ys_ec_ecpay_payment_test_mode',
		'merchant_id'       => 'ys_ec_ecpay_payment_merchant_id',
		'hash_key'          => 'ys_ec_ecpay_payment_hash_key',
		'hash_iv'           => 'ys_ec_ecpay_payment_hash_iv',
		// v0.3.0：信用卡明細查詢檢查碼（CreditDetail/QueryTrade 必填；綠界後台取得），加密儲存。
		'credit_check_code' => 'ys_ec_ecpay_payment_credit_check_code',
	];

	public const LOGISTICS_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_logistics_test_mode',
		'merchant_id' => 'ys_ec_ecpay_logistics_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_logistics_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_logistics_hash_iv',
	];

	public const METHOD_KEYS = [
		'credit'         => 'ys_ec_ecpay_credit_enabled',
		'atm'            => 'ys_ec_ecpay_atm_enabled',
		'cvs'            => 'ys_ec_ecpay_cvs_enabled',
		'barcode'        => 'ys_ec_ecpay_barcode_enabled',
		'ship_family'    => 'ys_ec_ecpay_ship_family_enabled',
		'ship_unimart'   => 'ys_ec_ecpay_ship_unimart_enabled',
		'ship_hilife'    => 'ys_ec_ecpay_ship_hilife_enabled',
		'ship_tcat'      => 'ys_ec_ecpay_ship_tcat_enabled',
		'ship_post'      => 'ys_ec_ecpay_ship_post_enabled',

		// v0.3.0：C2C（店到店）——與 B2C 是兩種不同的合約、不同的 subtype，
		// 綁定的服務金鑰也不同。各自獨立啟用，由業主依合約決定開哪一個。
		'ship_family_c2c'  => 'ys_ec_ecpay_ship_family_c2c_enabled',
		'ship_unimart_c2c' => 'ys_ec_ecpay_ship_unimart_c2c_enabled',
		'ship_hilife_c2c'  => 'ys_ec_ecpay_ship_hilife_c2c_enabled',

		// v0.3.0：低溫——超商冷凍（B2C／C2C）與宅配溫層
		'ship_unimart_freeze'     => 'ys_ec_ecpay_ship_unimart_freeze_enabled',
		'ship_unimart_freeze_c2c' => 'ys_ec_ecpay_ship_unimart_freeze_c2c_enabled',
		'ship_tcat_chilled'       => 'ys_ec_ecpay_ship_tcat_chilled_enabled',
		'ship_tcat_frozen'        => 'ys_ec_ecpay_ship_tcat_frozen_enabled',
	];

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

	public static function enabled(): bool {
		return '1' === (string) self::get( self::ENABLED, '0' );
	}

	public static function gateway_enabled( string $key ): bool {
		return self::enabled() && '1' === (string) self::get( self::METHOD_KEYS[ $key ] ?? '', '0' );
	}

	public static function shipping_enabled( string $key ): bool {
		return self::enabled() && '1' === (string) self::get( self::METHOD_KEYS[ $key ] ?? '', '0' );
	}

	/**
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function payment_credentials(): array {
		return self::credentials( self::PAYMENT_KEYS );
	}

	/**
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials(): array {
		return self::credentials( self::LOGISTICS_KEYS );
	}

	/**
	 * @param array<string,string> $keys
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	private static function credentials( array $keys ): array {
		$raw_key = (string) self::get( $keys['hash_key'], '' );
		$raw_iv  = (string) self::get( $keys['hash_iv'], '' );

		$credentials = [
			'test_mode'   => '1' === (string) self::get( $keys['test_mode'], '1' ),
			'merchant_id' => (string) self::get( $keys['merchant_id'], '' ),
			'hash_key'    => self::decrypt_secret( $raw_key ),
			'hash_iv'     => self::decrypt_secret( $raw_iv ),
		];

		// 信用卡查詢檢查碼（僅 PAYMENT_KEYS 有；logistics 群組無此欄位）
		if ( isset( $keys['credit_check_code'] ) ) {
			$credentials['credit_check_code'] = self::decrypt_secret( (string) self::get( $keys['credit_check_code'], '' ) );
		}

		return $credentials;
	}

	public static function decrypt_secret( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$plain = class_exists( YSCrypto::class ) ? (string) YSCrypto::decrypt_from_storage( $stored ) : '';
		return '' !== $plain ? $plain : $stored;
	}

	public static function encrypt_secret( string $plain ): string {
		return class_exists( YSCrypto::class ) ? (string) YSCrypto::encrypt_for_storage( $plain ) : $plain;
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

	public static function logistics_endpoint( string $path = '' ): string {
		$credentials = self::logistics_credentials();
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

	/**
	 * 貨到付款（代收）是否啟用（v0.3.0）
	 *
	 * 🔴 舊版 `IsCollection` 在送單與電子地圖兩處都寫死 `'N'`，因此就算業主在
	 * 後台開了貨到付款，送出去的仍然是「不代收」——貨送到了，錢沒收。
	 */
	public static function shipping_cod_enabled( string $method_id ): bool {
		return '1' === (string) self::shipping_method_option( $method_id, 'cod_enabled', '0' );
	}

	public static function has_payment_credentials(): bool {
		$c = self::payment_credentials();
		return '' !== $c['merchant_id'] && '' !== $c['hash_key'] && '' !== $c['hash_iv'];
	}

	public static function has_logistics_credentials(): bool {
		$c = self::logistics_credentials();
		return '' !== $c['merchant_id'] && '' !== $c['hash_key'] && '' !== $c['hash_iv'];
	}
}
