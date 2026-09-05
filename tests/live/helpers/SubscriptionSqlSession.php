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
		return new self( $prefix, \Closure::fromCallable( $recorder ),
			static fn ( string $value ): string => "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value ) . "'", static fn (): bool => true );
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
		++$this->dispatches;
		$result = ( $this->execute )( $sql );
		if ( ! is_array( $result ) || ! is_string( $result['error'] ?? null ) || ! is_array( $result['rows'] ?? null )
			|| ! array_key_exists( 'affected', $result ) || ( ! is_int( $result['affected'] ) && false !== $result['affected'] ) ) { throw new SubscriptionSqlFailure( 'transport_result_invalid' ); }
		$this->last_error = $result['error'];
		return $result;
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
		$sql = 'INSERT INTO ' . $this->prepare( '%i', $table ) . ' (' . implode( ', ', array_map( fn ( string $column ): string => $this->prepare( '%i', $column ), $columns ) ) . ') VALUES (' . implode( ', ', array_fill( 0, count( $data ), '%s' ) ) . ')';
		return $this->query( $this->prepare( $sql, ...array_values( $data ) ) );
	}
	public function close(): bool { if ( ! $this->ready ) { return true; } $this->ready = false; ++$this->closed; return ( $this->disconnect )(); }
}
