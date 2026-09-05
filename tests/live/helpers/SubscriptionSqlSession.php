<?php
/** Transport boundary for the product-SQL harness. This checkpoint cannot open connections. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;

final class SubscriptionSqlFailure extends \RuntimeException {}

final class SubscriptionSqlSession {
	private static int $connectionAttempts = 0;
	public string $last_error = '';
	public bool $ready = true;
	public int $dispatches = 0;
	public int $closed = 0;
	public string $options;
	private ?\Closure $before = null;
	private ?\Closure $after = null;
	private array $trace = [];
	private array $identity = [ 'connection_id'=>null, 'database'=>null, 'owner_nonce'=>null ];
	private string $origin = 'product';
	private int $replacements = 0;
	private bool $capture = false;
	private array $closeReceipts = [];
	public const SESSION_SQL = 'SELECT CAST(CONNECTION_ID() AS CHAR) AS cid, DATABASE() AS dbname, CAST(@ys_profile_tx_owner AS CHAR) AS owner';
	private function __construct( public string $prefix, private \Closure $execute, private \Closure $quote, private \Closure $disconnect ) {
		$this->options = $prefix . 'options';
	}
	public static function connectionAttempts(): int { return self::$connectionAttempts; }
	/** No receipt, environment flag or caller may turn this offline checkpoint into SQL execution. */
	public static function connect( array $allocation, ?callable $connector = null ): never {
		unset( $allocation, $connector );
		throw new SubscriptionSqlFailure( 'sql_execution_not_authorized_in_checkpoint' );
	}
	/** Supplied-handle adapter for a future separately authorized worker; never connects itself. */
	public static function fromMysqli( \mysqli $link, string $prefix ): self {
		self::assertPrefix( $prefix );
		return new self( $prefix, static function ( string $sql ) use ( $link ): array {
			try { $result = $link->query( $sql ); }
			catch ( \mysqli_sql_exception $error ) { return [ 'error' => $error->getMessage(), 'rows' => [], 'affected' => false ]; }
			if ( false === $result ) { return [ 'error' => $link->error, 'rows' => [], 'affected' => false ]; }
			$rows = [];
			if ( $result instanceof \mysqli_result ) {
				while ( $row = $result->fetch_assoc() ) { $rows[] = $row; }
				$result->free();
			}
			return [ 'error' => '', 'rows' => $rows, 'affected' => $link->affected_rows ];
		}, static fn ( string $value ): string => "'" . $link->real_escape_string( $value ) . "'", static fn (): bool => $link->close() );
	}
	/** Recording boundary only: proves product statement origin, never SQL/server semantics. */
	public static function forCapture( string $prefix, callable $recorder ): self {
		self::assertPrefix( $prefix );
		$self = new self( $prefix, \Closure::fromCallable( $recorder ),
			static fn ( string $value ): string => "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value ) . "'", static fn (): bool => true );
		$self->capture = true;
		return $self;
	}
	public function isCapture(): bool { return $this->capture; }
	public function identity(): array { return $this->identity; }
	public function statements(): array { return $this->trace; }
	public function closes(): array { return $this->closeReceipts; }
	public function physicalReplacements(): int { return $this->replacements; }
	public function instrument( ?callable $before, ?callable $after ): void {
		$this->before = null === $before ? null : \Closure::fromCallable( $before );
		$this->after = null === $after ? null : \Closure::fromCallable( $after );
	}
	/** A separately labeled observer must not perturb the result delivered to product code. */
	public function observe( callable $reader, string $origin = 'observer' ): mixed {
		if ( ! in_array( $origin, [ 'observer','schema-setup','fault-control' ], true ) ) { throw new SubscriptionSqlFailure( 'statement_origin_invalid' ); }
		$prior = $this->origin; $error = $this->last_error; $this->origin = $origin;
		try { return $reader(); } finally { $this->origin = $prior; $this->last_error = $error; }
	}
	/** Transfer an already supplied transport. This method never invokes any connector. */
	public function replaceWith( self $replacement ): void {
		if ( $replacement === $this || ! $this->ready || ! $replacement->ready || $this->prefix !== $replacement->prefix || $this->capture !== $replacement->capture ) { throw new SubscriptionSqlFailure( 'replacement_invalid' ); }
		$old = $this->identity;
		if ( true !== ( $this->disconnect )() ) { throw new SubscriptionSqlFailure( 'replacement_close_failed' ); }
		$this->execute = $replacement->execute; $this->quote = $replacement->quote; $this->disconnect = $replacement->disconnect;
		$this->identity = $replacement->identity; $replacement->ready = false; ++$this->replacements;
		$this->closeReceipts[] = [ 'sequence'=>count( $this->trace ), 'reason'=>'controlled-replacement', 'identity'=>$old, 'poisoned'=>false ];
	}
	private static function kind( string $sql ): string {
		if ( 'COMMIT' === $sql ) { return 'commit'; }
		if ( 'ROLLBACK' === $sql ) { return 'rollback'; }
		if ( 'START TRANSACTION' === $sql ) { return 'begin'; }
		if ( self::SESSION_SQL === $sql ) { return 'session-verify'; }
		if ( 1 === preg_match( "/\\ASET @ys_profile_tx_owner = '[a-f0-9]{32}'\\z/", $sql ) ) { return 'owner-set'; }
		// Classification is provenance, not SQL admission. The capture recorder still independently
		// admits complete statements. Only genuine loaded model/store callsites classify the writes.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
			$call = ( $frame['class'] ?? '' ) . '::' . ( $frame['function'] ?? '' );
			if ( 'YangSheep\\Ecommerce\\Models\\YSSubscription::update_fulfillment_profile_cas' === $call ) { return 'profile-cas'; }
			if ( 'YangSheep\\Ecommerce\\Models\\YSSubscription::find_for_fulfillment_profile_update' === $call ) { return 'locked-read'; }
			if ( 'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpaySubscriptionSelectionStore::claim' === $call ) { return 'consume'; }
		}
		return 'read-or-setup';
	}
	public static function assertPrefix( string $prefix ): void {
		if ( 1 !== preg_match( '/\Aecps_[a-f0-9]{12}_\z/', $prefix ) ) { throw new SubscriptionSqlFailure( 'prefix_invalid' ); }
	}
	public function prepare( string $sql, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = array_values( $args[0] ); }
		$index = 0;
		$out = '';
		for ( $i = 0, $length = strlen( $sql ); $i < $length; ++$i ) {
			if ( '%' !== $sql[$i] ) { $out .= $sql[$i]; continue; }
			$type = $sql[++$i] ?? '';
			if ( '%' === $type ) { $out .= '%'; continue; }
			if ( ! in_array( $type, [ 's', 'd', 'f', 'i' ], true ) || ! array_key_exists( $index, $args ) ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
			$value = $args[$index++];
			if ( 's' === $type ) {
				if ( ! is_scalar( $value ) && null !== $value ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
				$out .= ( $this->quote )( (string) $value );
			} elseif ( 'd' === $type ) {
				if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/\A-?(0|[1-9][0-9]*)\z/', $value ) || (string) (int) $value !== $value ) ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
				$out .= (string) $value;
			} elseif ( 'f' === $type ) {
				if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
				$out .= sprintf( '%.14F', (float) $value );
			} else {
				if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $value ) ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
				$out .= '`' . $value . '`';
			}
		}
		if ( count( $args ) !== $index ) { throw new SubscriptionSqlFailure( 'prepare_contract_invalid' ); }
		return $out;
	}
	private function dispatch( string $sql ): array {
		if ( ! $this->ready ) { throw new SubscriptionSqlFailure( 'session_closed' ); }
		$kind = self::kind( $sql ); $index = count( $this->trace ); $origin = $this->origin;
		$this->trace[] = [ 'sequence'=>$index + 1, 'origin'=>$origin, 'kind'=>$kind, 'sql'=>$sql, 'sql_sha256'=>hash( 'sha256', $sql ), 'before_identity'=>$this->identity,
			'after_identity'=>null, 'sent'=>false, 'actual'=>null, 'presented'=>null, 'fault'=>null ];
		$decision = 'product' === $origin && null !== $this->before ? ( $this->before )( $kind, $sql, $this->identity ) : [ 'send'=>true,'action'=>'none','fault'=>null,'presented'=>null ];
		if ( ! is_array( $decision ) || ! is_bool( $decision['send'] ?? null ) || ! array_key_exists( 'presented', $decision ) || ! array_key_exists( 'fault', $decision ) ) { throw new SubscriptionSqlFailure( 'transport_decision_invalid' ); }
		$this->trace[$index]['fault'] = $decision['fault'];
		if ( $decision['send'] ) {
			++$this->dispatches; $this->trace[$index]['sent'] = true;
			$result = ( $this->execute )( $sql ); self::validateResult( $result );
			$this->trace[$index]['actual'] = $result;
			if ( self::SESSION_SQL === $sql && '' === $result['error'] && isset( $result['rows'][0] ) ) {
				$r = $result['rows'][0]; $this->identity = [ 'connection_id'=>$r['cid'] ?? null, 'database'=>$r['dbname'] ?? null, 'owner_nonce'=>$r['owner'] ?? null ];
			}
			if ( 'product' === $origin && null !== $this->after ) { $result = ( $this->after )( $kind, $sql, $result ); }
		} else { $result = $decision['presented']; }
		self::validateResult( $result );
		$this->trace[$index]['presented'] = $result; $this->trace[$index]['after_identity'] = $this->identity;
		$this->last_error = $result['error'];
		return $result;
	}
	private static function validateResult( mixed $result ): void {
		if ( ! is_array( $result ) || ! is_string( $result['error'] ?? null ) || ! is_array( $result['rows'] ?? null )
			|| ! array_key_exists( 'affected', $result ) || ( ! is_int( $result['affected'] ) && false !== $result['affected'] ) ) { throw new SubscriptionSqlFailure( 'transport_result_invalid' ); }
	}
	public function query( string $sql ): int|false { $r = $this->dispatch( $sql ); return '' === $r['error'] ? $r['affected'] : false; }
	public function get_results( string $sql, string $output = 'OBJECT' ): array {
		$r = $this->dispatch( $sql );
		if ( '' !== $r['error'] ) { return []; }
		return 'ARRAY_A' === $output ? $r['rows'] : array_map( static fn ( array $row ): object => (object) $row, $r['rows'] );
	}
	public function get_row( string $sql, string $output = 'OBJECT' ): array|object|null { return $this->get_results( $sql, $output )[0] ?? null; }
	public function get_var( string $sql ): mixed { $row = $this->get_results( $sql, 'ARRAY_A' )[0] ?? []; return array_values( $row )[0] ?? null; }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
	public function insert( string $table, array $data, mixed $format = null ): int|false {
		if ( null !== $format || [] === $data ) { throw new SubscriptionSqlFailure( 'insert_contract_invalid' ); }
		$columns = array_keys( $data );
		$values = [];
		foreach ( $data as $value ) {
			if ( null === $value ) { $values[] = 'NULL'; }
			elseif ( is_int( $value ) ) { $values[] = $this->prepare( '%d', $value ); }
			elseif ( is_string( $value ) ) { $values[] = $this->prepare( '%s', $value ); }
			else { throw new SubscriptionSqlFailure( 'insert_contract_invalid' ); }
		}
		$sql = 'INSERT INTO ' . $this->prepare( '%i', $table ) . ' (' . implode( ', ', array_map( fn ( string $column ): string => $this->prepare( '%i', $column ), $columns ) ) . ') VALUES (' . implode( ', ', $values ) . ')';
		return $this->query( $sql );
	}
	public function close(): bool {
		if ( ! $this->ready ) { return true; }
		$this->ready = false; ++$this->closed;
		$boundary = 'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionSharedDbBoundary';
		$this->closeReceipts[] = [ 'sequence'=>count( $this->trace ), 'reason'=>'close', 'identity'=>$this->identity, 'poisoned'=>class_exists( $boundary, false ) && $boundary::poisoned() ];
		return ( $this->disconnect )();
	}
}
