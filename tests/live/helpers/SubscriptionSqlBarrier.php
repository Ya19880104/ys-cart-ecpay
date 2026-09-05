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
	public static function attach( string $root, string $phase ): self {
		if ( 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,79}\z/', $phase ) || false === realpath( $root ) || ! is_dir( $root . '/' . $phase )
			|| realpath( dirname( $root . '/' . $phase ) ) !== realpath( $root ) || realpath( $root . '/' . $phase ) !== realpath( $root ) . DIRECTORY_SEPARATOR . $phase ) { throw new SubscriptionSqlFailure( 'phase_invalid' ); }
		return new self( realpath( $root . '/' . $phase ) );
	}
	private static function stages( string $case ): array {
		if ( ! isset( SubscriptionSqlEvidence::cases()[$case] ) ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
		$stages = [ 'setup-complete'=>[ 'A',1,[] ], 'a-complete'=>[ 'A',4,['setup-complete'] ], 'b-complete'=>[ 'B',2,['a-complete'] ] ];
		if ( in_array( $case, [ 'P2','P12a','P12b' ], true ) ) {
			$stages['a-seam'] = [ 'A',2,['setup-complete'] ]; $stages['b-seam'] = [ 'B',1,['a-seam'] ];
			$stages['release'] = [ 'controller',1,[ 'P2' === $case ? 'wait-observed' : 'b-seam' ] ];
			$stages['a-complete'][2][] = 'release';
		}
		if ( 'P2' === $case ) { $stages['wait-observed'] = [ 'A',3,['b-seam'] ]; }
		return $stages;
	}
	/** New controllers use only this bound protocol, never the legacy three-field marker. */
	public function publish( string $case, string $stage, string $role, string $connectionId, array $artifact ): array {
		$spec = self::stages( $case )[$stage] ?? null;
		if ( null === $spec || $role !== $spec[0] || 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $connectionId ) ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
		foreach ( $spec[2] as $prior ) { $this->awaitBound( $case, $prior, 1 ); }
		if (!is_string($artifact['path']??null) || basename($artifact['path'])!==strtolower($case).'-'.$stage.'-receipt.json') { throw new SubscriptionSqlFailure('barrier_receipt_invalid'); }
		$payload = SubscriptionSqlEvidence::read( $this->directory, $artifact );
		if ( 1 !== ( $payload['version'] ?? null ) || basename( $this->directory ) !== ( $payload['phase'] ?? null ) || $case !== ( $payload['case'] ?? null ) || $role !== ( $payload['role'] ?? null ) || $spec[1] !== ( $payload['sequence'] ?? null ) ) { throw new SubscriptionSqlFailure( 'barrier_receipt_invalid' ); }
		foreach ( self::stages( $case ) as $other => $bound ) {
			if ( $role === $bound[0] && $bound[1] >= $spec[1] && is_file( $this->directory . '/' . strtolower( $case ) . '-' . $other . '.json' ) ) { throw new SubscriptionSqlFailure( 'barrier_sequence_invalid' ); }
		}
		return SubscriptionSqlEvidence::persist( $this->directory, strtolower( $case ) . '-' . $stage . '.json', [ 'version'=>1,'phase'=>basename( $this->directory ),'case'=>$case,'stage'=>$stage,'role'=>$role,'sequence'=>$spec[1],'connection_id'=>$connectionId,'receipt_sha256'=>$artifact['sha256'] ] );
	}
	public function awaitBound( string $case, string $stage, int $deadlineMs = 10000 ): array {
		$spec = self::stages( $case )[$stage] ?? null;
		if ( null === $spec || $deadlineMs < 1 || $deadlineMs > 10000 ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
		$path = $this->directory . '/' . strtolower( $case ) . '-' . $stage . '.json'; $until = hrtime( true ) + $deadlineMs * 1000000;
		do {
			if ( is_file( $path ) ) {
				$bytes = file_get_contents( $path ); $value = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
				if ( ! SubscriptionSqlEvidence::exactKeys( $value, ['version','phase','case','stage','role','sequence','connection_id','receipt_sha256'] )
					|| 1 !== $value['version'] || basename( $this->directory ) !== $value['phase'] || $case !== $value['case'] || $stage !== $value['stage'] || $spec[0] !== $value['role'] || $spec[1] !== $value['sequence']
					|| ! is_string( $value['connection_id'] ) || 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $value['connection_id'] ) || ! is_string( $value['receipt_sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value['receipt_sha256'] ) ) { throw new SubscriptionSqlFailure( 'barrier_invalid' ); }
				$receiptPath = $this->directory . '/' . strtolower( $case ) . '-' . $stage . '-receipt.json';
				if ( ! is_file( $receiptPath ) || hash_file( 'sha256', $receiptPath ) !== $value['receipt_sha256'] ) { throw new SubscriptionSqlFailure( 'barrier_receipt_invalid' ); }
				$payload = SubscriptionSqlEvidence::read( $this->directory, ['path'=>$receiptPath,'bytes'=>filesize( $receiptPath ),'sha256'=>$value['receipt_sha256']] );
				foreach ( ['version','phase','case','role','sequence'] as $key ) { if ( ( $payload[$key] ?? null ) !== $value[$key] ) { throw new SubscriptionSqlFailure( 'barrier_receipt_invalid' ); } }
				return [ 'marker'=>$value, 'receipt'=>$payload ];
			}
			usleep( 1000 );
		} while ( hrtime( true ) < $until );
		throw new SubscriptionSqlFailure( 'barrier_timeout' );
	}
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
