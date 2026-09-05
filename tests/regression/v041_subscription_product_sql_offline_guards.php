<?php
/** Offline CLI contract: refused preflights must never touch the connection boundary. */
declare(strict_types=1);
$repo = dirname( __DIR__, 2 );
$entry = $repo . '/tests/live/live_subscription_product_pair.php';
$scratch = sys_get_temp_dir() . '/ecpay-sql-offline-' . bin2hex( random_bytes( 8 ) );
mkdir( $scratch );
$allocation = $scratch . '/allocation.json';
$grant = [ 'kind' => 'offline-design', 'host' => '127.0.0.1', 'port' => 9, 'database' => 'ecpay_offline_fixture',
	'user' => 'offline_fixture', 'prefix' => 'ecps_0123456789ab_', 'expires_at' => time() + 3600 ];
file_put_contents( $allocation, json_encode( $grant ) );
file_put_contents( $scratch . '/malformed-allocation.json', '{' );
file_put_contents( $scratch . '/expired-allocation.json', json_encode( array_replace( $grant, [ 'expires_at' => time() - 60 ] ) ) );
file_put_contents( $scratch . '/unknown-allocation.json', json_encode( array_replace( $grant, [ 'kind' => 'unknown' ] ) ) );
$core = (string) getenv( 'YS_CORE_ROOT' );
$affiliate = (string) getenv( 'YS_AFFILIATE_ROOT' );
$base = [ 'YS_CORE_ROOT' => $core, 'YS_ECPAY_ROOT' => $repo, 'YS_AFFILIATE_ROOT' => $affiliate,
	'YS_ECPAY_SQL_ALLOCATION' => $allocation, 'YS_TEST_MYSQL_DSN' => '127.0.0.1:9', 'YS_TEST_MYSQL_DB' => 'ecpay_offline_fixture',
	'YS_TEST_MYSQL_USER' => 'offline_fixture', 'YS_ECPAY_SQL_PREFIX' => 'ecps_0123456789ab_' ];
$cases = [
	'missing-allocation' => [ [ 'YS_ECPAY_SQL_ALLOCATION' => '' ], [], 'allocation_required' ],
	'malformed-allocation' => [ [ 'YS_ECPAY_SQL_ALLOCATION' => $scratch . '/malformed-allocation.json' ], [], 'allocation_invalid' ],
	'expired-allocation' => [ [ 'YS_ECPAY_SQL_ALLOCATION' => $scratch . '/expired-allocation.json' ], [], 'allocation_invalid' ],
	'unknown-allocation' => [ [ 'YS_ECPAY_SQL_ALLOCATION' => $scratch . '/unknown-allocation.json' ], [], 'allocation_invalid' ],
	'missing-dsn' => [ [ 'YS_TEST_MYSQL_DSN' => '' ], [], 'dsn_required' ],
	'missing-db' => [ [ 'YS_TEST_MYSQL_DB' => '' ], [], 'database_required' ],
	'missing-user' => [ [ 'YS_TEST_MYSQL_USER' => '' ], [], 'user_required' ],
	'missing-core' => [ [ 'YS_CORE_ROOT' => '' ], [], 'pair_root_required' ],
	'missing-ecpay' => [ [ 'YS_ECPAY_ROOT' => '' ], [], 'pair_root_required' ],
	'missing-affiliate' => [ [ 'YS_AFFILIATE_ROOT' => '' ], [], 'pair_root_required' ],
	'wrong-core-anchor' => [ [ 'YS_CORE_ROOT' => $repo ], [], 'pair_anchor_drift' ],
	'unknown-driver' => [ [], [ '--driver=unknown' ], 'driver_unknown' ],
	'native-unavailable' => [ [], [ '--driver=wordpress' ], 'native_wpdb_prerequisite_unsatisfied' ],
	'unknown-mode' => [ [], [ '--mode=unknown' ], 'mode_unknown' ],
	'unknown-argument' => [ [], [ '--selection-token=benign-sensitive-marker' ], 'argument_unknown' ],
	'dsn-mismatch' => [ [ 'YS_TEST_MYSQL_DSN' => '127.0.0.1:10' ], [], 'allocation_mismatch' ],
	'non-loopback' => [ [ 'YS_TEST_MYSQL_DSN' => 'example.invalid:9' ], [], 'dsn_invalid' ],
	'invalid-prefix' => [ [ 'YS_ECPAY_SQL_PREFIX' => 'wp_' ], [], 'prefix_invalid' ],
	'missing-prefix' => [ [ 'YS_ECPAY_SQL_PREFIX' => '' ], [], 'prefix_invalid' ],
	'missing-phase' => [ [], [ '--phase=' ], 'phase_invalid' ],
	'invalid-phase' => [ [], [ '--phase=../outside' ], 'phase_invalid' ],
	'missing-evidence-root' => [ [], [ '--evidence-root=' . $scratch . '/absent-root' ], 'phase_invalid' ],
	'phase-collision' => [ [], [], 'phase_exists' ],
	'offline-ready' => [ [], [], 'offline_preflight_ready' ],
	'capture-ddl' => [ [], [ '--mode=capture-ddl' ], 'ddl_captured_not_executed' ],
	'execution-locked' => [ [], [ '--mode=execute' ], 'sql_execution_not_authorized_in_checkpoint' ],
];
$pass = 0;
$fail = 0;
$receipts = [];
foreach ( $cases as $case => [ $overrides, $args, $expected ] ) {
	$phase = $scratch . '/' . $case;
	if ( 'phase-collision' === $case ) { mkdir( $phase ); file_put_contents( $phase . '/sentinel', 'preserve' ); }
	$command = [ PHP_BINARY, '-n', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $entry,
		'--driver=adapter', '--mode=preflight', '--phase=' . $case, '--evidence-root=' . $scratch, ...$args ];
	$out = $scratch . '/' . $case . '.stdout.txt';
	$err = $scratch . '/' . $case . '.stderr.txt';
	$environment = array_merge( getenv(), $base, $overrides );
	unset( $environment['YS_TEST_MYSQL_PASSWORD'] );
	$process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes, $repo, $environment );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Child launch failed' ); }
	fclose( $pipes[0] );
	$rc = proc_close( $process );
	$bytes = (string) file_get_contents( $out );
	$result = json_decode( $bytes, true );
	$expectedRc = in_array( $case, [ 'offline-ready', 'capture-ddl' ], true ) ? 0 : 2;
	$ok = $rc === $expectedRc && 0 === filesize( $err ) && is_array( $result )
		&& $expected === ( $result['code'] ?? null ) && 0 === ( $result['connection_attempts'] ?? -1 )
		&& 0 === ( $result['sql_statements'] ?? -1 ) && ! str_contains( $bytes, 'benign-sensitive-marker' );
	if ( 'phase-collision' === $case ) { $ok = $ok && 'preserve' === file_get_contents( $phase . '/sentinel' ); }
	elseif ( 2 === $expectedRc ) { $ok = $ok && ! file_exists( $phase ); }
	if ( 'capture-ddl' === $case ) {
		$ddl = is_string( $result['ddl_path'] ?? null ) && is_file( $result['ddl_path'] ) ? json_decode( file_get_contents( $result['ddl_path'] ), true ) : [];
		$ok = $ok && 6 === count( $ddl['statements'] ?? [] ) && 0 === ( $ddl['executed_statements'] ?? -1 );
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $case . " returns {$expected} before any connection or SQL\n";
	$ok ? ++$pass : ++$fail;
	$receipts[] = [ 'case' => $case, 'rc' => $rc, 'expected_rc' => $expectedRc, 'result' => $result, 'stdout' => $out,
		'stdout_sha256' => hash( 'sha256', $bytes ), 'stderr' => $err, 'stderr_bytes' => filesize( $err ), 'stderr_sha256' => hash_file( 'sha256', $err ) ];
}
$path = $scratch . '/receipts.json';
file_put_contents( $path, json_encode( $receipts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'OFFLINE_RECEIPTS ' . json_encode( [ 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ], JSON_UNESCAPED_SLASHES ) . "\n";
echo "subscription product SQL offline guards: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
