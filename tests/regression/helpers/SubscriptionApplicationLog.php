<?php
/** Test-only raw application-log custody and exact event accounting. */
declare(strict_types=1);

namespace YSCartEcpay\Tests;

final class SubscriptionApplicationLog {
	public static function start( string $label ): string {
		$directory = sys_get_temp_dir() . '/' . $label . '-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $directory ) ) { throw new \RuntimeException( 'Cannot create application-log directory' ); }
		$file = $directory . '/application.log';
		$handle = fopen( $file, 'x' );
		if ( false === $handle ) { throw new \RuntimeException( 'Cannot create application-log evidence' ); }
		fclose( $handle );
		if ( false === ini_set( 'error_log', $file ) ) {
			throw new \RuntimeException( 'Cannot create application-log evidence' );
		}
		return $file;
	}

	/** @param array<string,int> $expected Exact full event payloads and counts. */
	public static function inspect( string $file, array $expected ): array {
		$bytes = is_file( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false;
		$observed = [];
		$unexpected = 0;
		if ( is_string( $bytes ) && '' !== $bytes ) {
			$lines = explode( "\n", $bytes );
			if ( '' === end( $lines ) ) { array_pop( $lines ); }
			else { ++$unexpected; } // An incomplete final record is never silently trimmed.
			foreach ( $lines as $line ) {
				// Strip only PHP's timestamp envelope; do not trim or substring-match payloads.
				if ( 1 !== preg_match( '/\A\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z0-9_+\/:\-]+\] ([^\r\n]*)\r?\z/', $line, $match )
					|| ! array_key_exists( $match[1], $expected ) ) {
					++$unexpected;
					continue;
				}
				$observed[ $match[1] ] = ( $observed[ $match[1] ] ?? 0 ) + 1;
			}
		}
		ksort( $expected );
		ksort( $observed );
		return [ 'ok' => is_string( $bytes ) && 0 === $unexpected && $observed === $expected,
			'path' => $file, 'bytes' => is_string( $bytes ) ? strlen( $bytes ) : null,
			'sha256' => is_string( $bytes ) ? hash( 'sha256', $bytes ) : null,
			'expected_events' => $expected, 'observed_events' => $observed, 'unexpected_records' => $unexpected ];
	}
}
