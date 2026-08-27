<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

/** UTF-8 length limits without making optional PHP mbstring a hard dependency. */
final class Utf8Text {
	public static function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? strlen( $value ) : $count;
	}

	public static function truncate( string $value, int $limit ): string {
		if ( $limit <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $limit, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value, $matches );
		if ( false === $count ) {
			return substr( $value, 0, $limit );
		}

		return implode( '', array_slice( $matches[0], 0, $limit ) );
	}
}
