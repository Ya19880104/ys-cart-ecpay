<?php
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;

final class SubscriptionSqlAllocation {
	public static function validate( mixed $value ): array {
		$keys = [ 'database', 'expires_at', 'host', 'kind', 'port', 'prefix', 'user' ];
		$actual = is_array( $value ) ? array_keys( $value ) : [];
		sort( $actual, SORT_STRING );
		if ( $actual !== $keys ) { throw new SubscriptionSqlFailure( 'allocation_invalid' ); }
		foreach ( [ 'database', 'host', 'kind', 'prefix', 'user' ] as $key ) {
			if ( ! is_string( $value[$key] ) || '' === $value[$key] ) { throw new SubscriptionSqlFailure( 'allocation_invalid' ); }
		}
		if ( ! in_array( $value['kind'], [ 'offline-design', 'sql-execution' ], true ) || '127.0.0.1' !== $value['host']
			|| ! is_int( $value['port'] ) || $value['port'] < 1 || $value['port'] > 65535
			|| ! is_int( $value['expires_at'] ) || $value['expires_at'] <= time()
			|| 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $value['database'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,80}\z/', $value['user'] )
			|| 1 !== preg_match( '/\Aecps_[a-f0-9]{12}_\z/', $value['prefix'] ) ) { throw new SubscriptionSqlFailure( 'allocation_invalid' ); }
		return $value;
	}
}
