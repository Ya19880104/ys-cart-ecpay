<?php
/** Real private Git fixtures: changed-path and clean custody are not mocked. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helperRoot = (string) ( getenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' ) ?: dirname( __DIR__ ) . '/live/helpers' );
require_once $helperRoot . '/SubscriptionSqlSession.php';
require_once $helperRoot . '/SubscriptionProductSqlFixture.php';
require_once $helperRoot . '/SubscriptionSqlAllocation.php';
$scratch = sys_get_temp_dir() . '/ecpay-sql-custody-' . bin2hex( random_bytes( 8 ) ); mkdir( $scratch );
$source = (string) ( getenv( 'YS_ECPAY_ROOT' ) ?: dirname( __DIR__, 2 ) );
$commands = [];
$git = static function ( array $args ) use ( &$commands, $scratch ): void {
	$n = count( $commands ); $out = $scratch . '/git-' . $n . '.stdout.txt'; $err = $scratch . '/git-' . $n . '.stderr.txt';
	$p = proc_open( [ 'git', ...$args ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes );
	if ( ! is_resource( $p ) ) { throw new RuntimeException( 'fixture_git_launch_failed' ); }
	fclose( $pipes[0] ); $rc = proc_close( $p );
	$commands[] = [ 'rc' => $rc, 'stdout' => $out, 'stderr' => $err ];
	if ( 0 !== $rc ) { throw new RuntimeException( 'fixture_git_command_failed' ); }
};
$cases = [
	'clean-checkpoint' => [ null, false, 'accepted' ],
	'dirty-ecpay' => [ 'README.md', false, 'pair_anchor_drift' ],
	'allowed-descendant' => [ 'tests/live/PRODUCT_SQL_OFFLINE.md', true, 'accepted' ],
	'forbidden-package-descendant' => [ 'manifest.php', true, 'pair_path_not_allowed' ],
	'unknown-test-descendant' => [ 'tests/live/not-allocated.php', true, 'pair_path_not_allowed' ],
];
$pass = 0; $fail = 0; $receipts = [];
$helperReceipt = Fixture::helperReceipt( $helperRoot );
$savedOverride = getenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' ); $savedPhase = getenv( 'YS_ECPAY_SQL_MUTATION_PHASE' );
putenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT=' . $helperRoot ); putenv( 'YS_ECPAY_SQL_MUTATION_PHASE' );
$actual = 'accepted';
try { Fixture::helperReceipt( $helperRoot ); } catch ( SubscriptionSqlFailure $e ) { $actual = $e->getMessage(); }
putenv( false === $savedOverride ? 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' : 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT=' . $savedOverride );
putenv( false === $savedPhase ? 'YS_ECPAY_SQL_MUTATION_PHASE' : 'YS_ECPAY_SQL_MUTATION_PHASE=' . $savedPhase );
$ok = 'helper_override_not_named' === $actual;
echo ( $ok ? 'PASS ' : 'FAIL ' ) . "unnamed helper override is refused\n"; $ok ? ++$pass : ++$fail;
$allocation = [ 'kind' => 'offline-design', 'host' => '127.0.0.1', 'port' => 33784, 'database' => 'offline_fixture', 'user' => 'offline_fixture', 'prefix' => 'ecps_0123456789ab_', 'expires_at' => time() + 300 ];
$ok = $allocation === \YSCartEcpay\Tests\Live\SubscriptionSqlAllocation::validate( $allocation );
echo ( $ok ? 'PASS ' : 'FAIL ' ) . "exact typed allocation remains admissible\n"; $ok ? ++$pass : ++$fail;
foreach ( [ 'extra' => [ 'unexpected' => 'benign' ], 'secret-like' => [ 'password' => 'benign-not-a-secret' ], 'port-type' => [ 'port' => '33784' ] ] as $name => $change ) {
	$actual = 'accepted';
	try { \YSCartEcpay\Tests\Live\SubscriptionSqlAllocation::validate( array_replace( $allocation, $change ) ); }
	catch ( SubscriptionSqlFailure $e ) { $actual = $e->getMessage(); }
	$ok = 'allocation_invalid' === $actual && 0 === Session::connectionAttempts();
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . 'allocation-' . $name . "\n"; $ok ? ++$pass : ++$fail;
}
foreach ( $cases as $name => [ $path, $commit, $expected ] ) {
	$root = $scratch . '/' . $name;
	$git( [ 'clone', '--shared', '--no-checkout', $source, $root ] );
	$git( [ '-C', $root, '-c', 'core.autocrlf=false', 'checkout', '--detach', 'b5a3773a28d88c6613fe7e5fd707210376059af4' ] );
	if ( null !== $path ) {
		file_put_contents( $root . '/' . $path, "\nbenign fixture custody change\n", FILE_APPEND );
		if ( $commit ) {
			$git( [ '-C', $root, 'add', '-f', '--', $path ] );
			$git( [ '-C', $root, '-c', 'user.name=Offline Fixture', '-c', 'user.email=offline@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-m', 'fixture-only custody change' ] );
		}
	}
	$actual = 'accepted';
	try { Fixture::inspectSources( [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => $root, 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ] ); }
	catch ( SubscriptionSqlFailure $e ) { $actual = $e->getMessage(); }
	$ok = $expected === $actual && 0 === Session::connectionAttempts();
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ' expected=' . $expected . ' actual=' . $actual . "\n";
	$ok ? ++$pass : ++$fail;
	$receipts[] = [ 'case' => $name, 'expected' => $expected, 'actual' => $actual, 'fixture' => $root, 'connection_attempts' => Session::connectionAttempts() ];
}
$path = $scratch . '/receipts.json';
file_put_contents( $path, json_encode( [ 'helpers' => $helperReceipt, 'cases' => $receipts, 'commands' => $commands ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'CUSTODY_RECEIPTS ' . json_encode( [ 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ], JSON_UNESCAPED_SLASHES ) . "\n";
echo "subscription product SQL custody: {$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
