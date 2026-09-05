<?php
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlBarrier as Barrier;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
require_once __DIR__ . '/helpers/SubscriptionSqlSession.php';
require_once __DIR__ . '/helpers/SubscriptionSqlAllocation.php';
require_once __DIR__ . '/helpers/SubscriptionSqlBarrier.php';
require_once __DIR__ . '/helpers/SubscriptionProductSqlFixture.php';
$result = [ 'success' => false, 'code' => '', 'connection_attempts' => 0, 'sql_statements' => 0, 'sql_execution' => 'NOT RUN', 'native_wpdb' => 'PREREQUISITE UNSATISFIED' ];
$rc = 2;
try {
	$options = [];
	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( 1 !== preg_match( '/\A--(driver|mode|phase|evidence-root|role)=(.*)\z/s', $argument, $match ) ) { throw new SubscriptionSqlFailure( 'argument_unknown' ); }
		$options[$match[1]] = $match[2];
	}
	if ( ! in_array( $options['driver'] ?? '', [ 'adapter', 'wordpress' ], true ) ) { throw new SubscriptionSqlFailure( 'driver_unknown' ); }
	if ( 'wordpress' === $options['driver'] ) { throw new SubscriptionSqlFailure( 'native_wpdb_prerequisite_unsatisfied' ); }
	if ( 'ipc-worker' === ( $options['mode'] ?? '' ) ) {
		require_once __DIR__ . '/helpers/SubscriptionSqlWorker.php';
		if ( ! \YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::exactKeys( $options, [ 'driver','mode','role' ] ) ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$private = stream_get_contents( STDIN, 32769 );
		if ( ! is_string( $private ) || strlen( $private ) > 32768 ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$packet = \YSCartEcpay\Tests\Live\SubscriptionSqlWorker::validatePacket( json_decode( $private, true ) );
		if ( $options['role'] !== $packet['role'] ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$protocol = \YSCartEcpay\Tests\Live\SubscriptionSqlWorker::protocol( $packet );
		unset( $packet, $private );
		echo json_encode( $protocol, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		exit( 0 );
	}
	if ( isset( $options['role'] ) ) { throw new SubscriptionSqlFailure( 'argument_unknown' ); }
	if ( ! in_array( $options['mode'] ?? '', [ 'preflight', 'capture-ddl', 'execute' ], true ) ) { throw new SubscriptionSqlFailure( 'mode_unknown' ); }
	$allocationPath = (string) getenv( 'YS_ECPAY_SQL_ALLOCATION' );
	if ( '' === $allocationPath || ! is_file( $allocationPath ) ) { throw new SubscriptionSqlFailure( 'allocation_required' ); }
	$allocation = \YSCartEcpay\Tests\Live\SubscriptionSqlAllocation::validate( json_decode( (string) file_get_contents( $allocationPath ), true ) );
	$dsn = (string) getenv( 'YS_TEST_MYSQL_DSN' );
	$database = (string) getenv( 'YS_TEST_MYSQL_DB' );
	$user = (string) getenv( 'YS_TEST_MYSQL_USER' );
	$prefix = (string) getenv( 'YS_ECPAY_SQL_PREFIX' );
	if ( '' === $dsn ) { throw new SubscriptionSqlFailure( 'dsn_required' ); }
	if ( '' === $database ) { throw new SubscriptionSqlFailure( 'database_required' ); }
	if ( '' === $user ) { throw new SubscriptionSqlFailure( 'user_required' ); }
	if ( 1 !== preg_match( '/\A127\.0\.0\.1:([1-9][0-9]{0,4})\z/', $dsn, $match ) || (int) $match[1] > 65535 ) { throw new SubscriptionSqlFailure( 'dsn_invalid' ); }
	if ( 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $database ) ) { throw new SubscriptionSqlFailure( 'database_invalid' ); }
	Session::assertPrefix( $prefix );
	if ( '127.0.0.1' !== ( $allocation['host'] ?? null ) || (int) $match[1] !== ( $allocation['port'] ?? null )
		|| $database !== ( $allocation['database'] ?? null ) || $user !== ( $allocation['user'] ?? null ) || $prefix !== ( $allocation['prefix'] ?? null ) ) { throw new SubscriptionSqlFailure( 'allocation_mismatch' ); }
	$roots = [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => (string) getenv( 'YS_ECPAY_ROOT' ), 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ];
	if ( in_array( '', $roots, true ) ) { throw new SubscriptionSqlFailure( 'pair_root_required' ); }
	$sources = Fixture::inspectSources( $roots );
	$products = Fixture::loadProduct( $sources );
	$helpers = Fixture::helperReceipt( __DIR__ . '/helpers' );
	if ( 'execute' === $options['mode'] ) { Session::connect( $allocation ); }
	$barrier = Barrier::createPhase( $options['evidence-root'] ?? '', $options['phase'] ?? '' );
	$result += [ 'sources' => $sources, 'products' => $products, 'helpers' => $helpers, 'evidence_directory' => $barrier->path(), 'allocation_kind' => $allocation['kind'] ];
	$result['success'] = true;
	$result['code'] = 'offline_preflight_ready';
	if ( 'capture-ddl' === $options['mode'] ) {
		$ddl = Fixture::captureDdl( $prefix );
		$path = $barrier->path() . '/ddl-capture.json';
		file_put_contents( $path, json_encode( $ddl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
		$result['code'] = 'ddl_captured_not_executed';
		$result['ddl_path'] = $path;
		$result['ddl_sha256'] = hash_file( 'sha256', $path );
	}
	$rc = 0;
} catch ( SubscriptionSqlFailure $error ) { $result['success'] = false; $result['code'] = $error->getMessage(); }
catch ( Throwable $error ) { $result['success'] = false; $result['code'] = 'harness_failure'; }
$result['connection_attempts'] = Session::connectionAttempts();
echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
exit( $rc );
