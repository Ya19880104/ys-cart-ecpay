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
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function payment_credentials(): array {
		return self::credentials( self::PAYMENT_KEYS );
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
	 * B2C and HOME intentionally share one account group; C2C never does.
	 *
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials_for_channel( string $channel ): array {
		$family = self::credential_family_for_channel( $channel );
		if ( '' === $family ) {
			return self::empty_credentials();
		}

		$b2c = self::credentials( self::LOGISTICS_B2C_HOME_KEYS );
		$c2c = self::credentials( self::LOGISTICS_C2C_KEYS );
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

		// Legacy fallback is deliberately narrow: both new groups must be empty,
		// and enabled method switches must identify exactly one credential family.
		// This supports a single-family historical install without ever allowing
		// the old tuple to service B2C/home and C2C simultaneously.
		if ( self::credentials_started( $b2c ) || self::credentials_started( $c2c )
			|| $family !== self::unambiguous_legacy_family() ) {
			return self::empty_credentials();
		}

		$legacy = self::credentials( self::LOGISTICS_KEYS );
		return self::credentials_complete( $legacy ) ? $legacy : self::empty_credentials();
	}

	/**
	 * @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string}
	 */
	public static function logistics_credentials_for_subtype( string $subtype ): array {
		$channel = EcpayShippingCatalog::channel_for_subtype( $subtype );
		return '' === $channel ? self::empty_credentials() : self::logistics_credentials_for_channel( $channel );
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

	private static function credential_family_for_channel( string $channel ): string {
		return match ( trim( $channel ) ) {
			EcpayShippingCatalog::CHANNEL_B2C, EcpayShippingCatalog::CHANNEL_HOME => 'b2c_home',
			EcpayShippingCatalog::CHANNEL_C2C => 'c2c',
			default => '',
		};
	}

	private static function unambiguous_legacy_family(): string {
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
			$family = self::credential_family_for_channel( (string) ( $descriptor['channel'] ?? '' ) );
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
