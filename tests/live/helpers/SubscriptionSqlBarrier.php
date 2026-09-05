<?php
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;

final class SubscriptionSqlBarrier {
	private function __construct( private string $directory ) {}
	public static function createPhase( string $root, string $phase ): self {
		if ( 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,79}\z/', $phase ) || ! is_dir( $root ) || false === realpath( $root ) ) { throw new SubscriptionSqlFailure( 'phase_invalid' ); }
		$path = realpath( $root ) . '/' . $phase;
		if ( file_exists( $path ) ) { throw new SubscriptionSqlFailure( 'phase_exists' ); }
		if ( ! mkdir( $path ) ) { throw new SubscriptionSqlFailure( 'phase_create_failed' ); }
		return new self( $path );
	}
	public function path(): string { return $this->directory; }
	/** Barrier payload is deliberately fixed; tokens and arbitrary diagnostic values cannot enter it. */
	public function arrive( string $stage, string $role, string $connectionId ): void {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,63}\z/', $stage ) || ! in_array( $role, [ 'A', 'B', 'controller' ], true )
			|| 1 !== preg_match( '/\A[0-9]{1,20}\z/', $connectionId ) ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
		$path = $this->directory . '/' . $stage . '.json';
		if ( file_exists( $path ) ) { throw new SubscriptionSqlFailure( 'barrier_exists' ); }
		$handle = fopen( $path, 'x' );
		if ( false === $handle ) { throw new SubscriptionSqlFailure( 'barrier_create_failed' ); }
		fwrite( $handle, json_encode( [ 'stage' => $stage, 'role' => $role, 'connection_id' => $connectionId ], JSON_THROW_ON_ERROR ) );
		fclose( $handle );
	}
	public function await( string $stage, int $deadlineMs ): array {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,63}\z/', $stage ) || $deadlineMs < 1 || $deadlineMs > 10000 ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
		$path = $this->directory . '/' . $stage . '.json';
		$until = hrtime( true ) + $deadlineMs * 1000000;
		do {
			if ( is_file( $path ) ) {
				$result = json_decode( (string) file_get_contents( $path ), true );
				if ( is_array( $result ) ) {
					if ( 3 !== count( $result ) || $stage !== ( $result['stage'] ?? null ) || ! in_array( $result['role'] ?? null, [ 'A', 'B', 'controller' ], true )
						|| ! is_string( $result['connection_id'] ?? null ) || 1 !== preg_match( '/\A[0-9]{1,20}\z/', $result['connection_id'] ) ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
					return $result;
				}
			}
			usleep( 1000 );
		} while ( hrtime( true ) < $until );
		throw new SubscriptionSqlFailure( 'barrier_timeout' );
	}
}
