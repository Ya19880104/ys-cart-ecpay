<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\Ecommerce\YSEcommerce;

final class Settings {
	public const ENABLED = 'ys_ec_ecpay_enabled';

	public const PAYMENT_KEYS = [
		'test_mode'   => 'ys_ec_ecpay_payment_test_mode',
		'merchant_id' => 'ys_ec_ecpay_payment_merchant_id',
		'hash_key'    => 'ys_ec_ecpay_payment_hash_key',
		'hash_iv'     => 'ys_ec_ecpay_payment_hash_iv',
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

		return [
			'test_mode'   => '1' === (string) self::get( $keys['test_mode'], '1' ),
			'merchant_id' => (string) self::get( $keys['merchant_id'], '' ),
			'hash_key'    => self::decrypt_secret( $raw_key ),
			'hash_iv'     => self::decrypt_secret( $raw_iv ),
		];
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

	public static function has_payment_credentials(): bool {
		$c = self::payment_credentials();
		return '' !== $c['merchant_id'] && '' !== $c['hash_key'] && '' !== $c['hash_iv'];
	}

	public static function has_logistics_credentials(): bool {
		$c = self::logistics_credentials();
		return '' !== $c['merchant_id'] && '' !== $c['hash_key'] && '' !== $c['hash_iv'];
	}
}
