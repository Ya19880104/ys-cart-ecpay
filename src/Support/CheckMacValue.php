<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || defined( 'YS_CART_ECPAY_TESTING' ) || exit;

final class CheckMacValue {
	private const DOTNET_REPLACEMENTS = [
		'%2d' => '-',
		'%5f' => '_',
		'%2e' => '.',
		'%21' => '!',
		'%2a' => '*',
		'%28' => '(',
		'%29' => ')',
	];

	/**
	 * @param array<string,mixed> $params
	 */
	public static function generate( array $params, string $hash_key, string $hash_iv, string $method = 'sha256' ): string {
		$method = strtolower( $method );
		if ( ! in_array( $method, [ 'sha256', 'md5' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported ECPay CheckMacValue method.' );
		}

		$filtered = [];
		foreach ( $params as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, 'CheckMacValue' ) ) {
				continue;
			}
			$filtered[ (string) $key ] = self::stringify( $value );
		}

		uksort(
			$filtered,
			static fn ( string $a, string $b ): int => strcasecmp( $a, $b )
		);

		$raw = 'HashKey=' . $hash_key;
		foreach ( $filtered as $key => $value ) {
			$raw .= '&' . $key . '=' . $value;
		}
		$raw .= '&HashIV=' . $hash_iv;

		return strtoupper( hash( $method, self::ecpay_url_encode( $raw ) ) );
	}

	/**
	 * @param array<string,mixed> $params
	 */
	public static function verify( array $params, string $hash_key, string $hash_iv, string $method = 'sha256' ): bool {
		$incoming = '';
		foreach ( $params as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, 'CheckMacValue' ) ) {
				$incoming = strtoupper( self::stringify( $value ) );
				break;
			}
		}

		if ( '' === $incoming ) {
			return false;
		}

		$expected = self::generate( $params, $hash_key, $hash_iv, $method );
		return hash_equals( $expected, $incoming );
	}

	public static function ecpay_url_encode( string $raw ): string {
		$encoded = strtolower( urlencode( $raw ) );
		return str_replace( array_keys( self::DOTNET_REPLACEMENTS ), array_values( self::DOTNET_REPLACEMENTS ), $encoded );
	}

	private static function stringify( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return trim( (string) $value );
		}
		return wp_json_encode( $value ) ?: '';
	}
}

