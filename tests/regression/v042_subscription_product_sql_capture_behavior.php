<?php
/** Offline statement-origin/capture proof. No SQL parser, connection or server state is exercised. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlBarrier as Barrier;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = (string) ( getenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' ) ?: dirname( __DIR__ ) . '/live/helpers' );
require_once $helpers . '/SubscriptionSqlSession.php';
require_once $helpers . '/SubscriptionSqlBarrier.php';
require_once $helpers . '/SubscriptionProductSqlFixture.php';
function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $data, $flags, $depth ); }
function current_time( string $type ): string { return '2026-09-05 00:00:00'; }
$pass = 0; $fail = 0;
$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail ): void { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$pass : ++$fail; };
$helperReceipt = Fixture::helperReceipt( $helpers );
$sources = Fixture::inspectSources( [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => (string) ( getenv( 'YS_ECPAY_ROOT' ) ?: dirname( __DIR__, 2 ) ), 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ] );
$products = Fixture::loadProduct( $sources );
$check( 'fifteen real product classes are loaded from their exact committed raw blobs', 15 === count( $products ) );
$attempts = 0;
try { Session::connect( [ 'kind' => 'sql-execution' ], static function () use ( &$attempts ): void { ++$attempts; } ); }
catch ( SubscriptionSqlFailure $error ) { $code = $error->getMessage(); }
$check( 'even an execution-shaped allocation cannot invoke a connector in this checkpoint', 0 === $attempts && 'sql_execution_not_authorized_in_checkpoint' === ( $code ?? '' ) && 0 === Session::connectionAttempts() );
$prefix = 'ecps_0123456789ab_';
$ddl = Fixture::captureDdl( $prefix );
$names = [];
foreach ( $ddl['statements'] as $sql ) { preg_match( '/\ACREATE TABLE ([A-Za-z0-9_]+)\s*\(/', $sql, $match ); $names[] = $match[1] ?? ''; }
$expectedNames = array_map( static fn ( string $role ): string => $prefix . 'ys_ec_' . $role, [ 'products', 'subscriptions', 'orders', 'order_items', 'order_created_outbox' ] );
$expectedNames[] = $prefix . 'options';
$check( 'actual TableMaker methods capture exactly the five owned product tables plus one fixture options table', $names === $expectedNames && 0 === $ddl['executed_statements'] );
$subscriptionDdl = $ddl['statements'][1];
$check( 'captured subscription authority keeps LONGTEXT, generation, exact hash, decimal total and nullable timestamp',
	1 === preg_match( '/fulfillment_profile LONGTEXT NULL/', $subscriptionDdl )
	&& 1 === preg_match( '/fulfillment_profile_generation BIGINT UNSIGNED NOT NULL DEFAULT 0/', $subscriptionDdl )
	&& 1 === preg_match( '/fulfillment_profile_hash CHAR\(64\) DEFAULT NULL/', $subscriptionDdl )
	&& 1 === preg_match( '/renewal_shipping_total DECIMAL\(12,2\) NOT NULL DEFAULT 0.00/', $subscriptionDdl )
	&& 1 === preg_match( '/fulfillment_profile_updated_at DATETIME DEFAULT NULL/', $subscriptionDdl ) );
$check( 'all captured tables require InnoDB and preserve primary or unique ownership keys',
	6 === count( array_filter( $ddl['statements'], static fn ( string $sql ): bool => str_contains( $sql, 'ENGINE=InnoDB' ) && str_contains( $sql, 'PRIMARY KEY' ) ) )
	&& str_contains( $ddl['statements'][5], 'UNIQUE KEY option_name (option_name)' ) );
$captured = [];
$db = Session::forCapture( $prefix, static function ( string $sql ) use ( &$captured ): array {
	$captured[] = $sql;
	return [ 'error' => '', 'affected' => 0, 'rows' => str_starts_with( $sql, 'SHOW TABLE STATUS' ) ? [ [ 'Engine' => 'InnoDB' ] ] : [] ];
} );
$GLOBALS['wpdb'] = $db;
$invalidInput = Fixture::updateProduct( 41, [ 'unexpected_input' => 'benign' ], [ 'customer_id' => 91, 'user_id' => 7 ] );
$check( 'real coordinator rejects client authority before any transport dispatch', 'browser_authority_violation' === ( $invalidInput['code'] ?? '' ) && [] === $captured );
$model = \YangSheep\Ecommerce\Models\YSSubscription::class;
$check( 'real model rejects missing lock custody without a statement', null === $model::find_for_fulfillment_profile_update( 41 ) && [] === $captured );
$fence = [ 'connection_id' => '9101', 'database' => 'ecpay_offline_fixture', 'owner_nonce' => str_repeat( 'a', 32 ), 'tables' => $model::admitted_table_map( $db ) ];
$model::find_for_fulfillment_profile_update( 41, $fence, $db );
$lockSql = end( $captured );
$check( 'real model emits its FOR UPDATE on the admitted table with all three physical-session predicates',
	str_starts_with( $lockSql, 'SELECT * FROM ' . $prefix . 'ys_ec_subscriptions WHERE id = 41 AND ' )
	&& str_ends_with( $lockSql, ' FOR UPDATE' ) && str_contains( $lockSql, 'CONNECTION_ID()' ) && str_contains( $lockSql, 'DATABASE()' ) && str_contains( $lockSql, '@ys_profile_tx_owner' ) );
$won = $model::update_fulfillment_profile_cas( 41, 3, '{"fixture":"canonical"}', str_repeat( 'b', 64 ), '65.00', '2026-09-05 00:00:00', $fence, $db );
$casSql = end( $captured );
$check( 'real model produces generation CAS but recording zero affected rows never claims a SQL win',
	false === $won && str_contains( $casSql, 'UPDATE ' . $prefix . 'ys_ec_subscriptions' )
	&& str_contains( $casSql, 'fulfillment_profile_generation = fulfillment_profile_generation + 1' )
	&& str_contains( $casSql, 'fulfillment_profile_generation = 3' ) && str_contains( $casSql, '@ys_profile_tx_owner' ) );
$store = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpaySubscriptionSelectionStore::class;
$before = count( $captured );
$check( 'actual ECPay store rejects missing claim custody before SQL', false === $store::claim( 'fixture-token', '{}', 41, 4 ) && $before === count( $captured ) );
$issued = json_encode( [ 'state' => 'issued', 'record' => [ 'subscription_id' => 41, 'expires_at' => time() + 300 ] ] );
$claimed = $store::claim( 'fixture-token', $issued, 41, 4, [ 'transaction_db' => $db ] + $fence );
$consumeSql = end( $captured );
$check( 'actual ECPay store produces exact-byte digest-keyed consume SQL and preserves all physical-session predicates',
	false === $claimed && str_starts_with( $consumeSql, 'UPDATE ' . $prefix . 'options SET option_value = ' )
	&& str_contains( $consumeSql, hash( 'sha256', 'fixture-token' ) ) && ! str_contains( $consumeSql, 'fixture-token' )
	&& str_contains( $consumeSql, 'option_value = BINARY' ) && str_contains( $consumeSql, 'CONNECTION_ID()' ) && str_contains( $consumeSql, 'DATABASE()' ) && str_contains( $consumeSql, '@ys_profile_tx_owner' ) );
$rejected = 0;
foreach ( [ [ 'SELECT %s', [] ], [ 'SELECT %d', [ 1, 2 ] ], [ 'SELECT %q', [ 1 ] ], [ 'SELECT %d', [ '01' ] ] ] as [ $sql, $args ] ) {
	try { $db->prepare( $sql, ...$args ); } catch ( SubscriptionSqlFailure $error ) { $rejected += 'prepare_contract_invalid' === $error->getMessage() ? 1 : 0; }
}
$check( 'unsupported placeholders and wrong arity or malformed integers fail closed', 4 === $rejected );
$before = count( $captured ); $db->close(); $db->close();
try { $db->query( 'SELECT 1' ); } catch ( SubscriptionSqlFailure $error ) { $closedCode = $error->getMessage(); }
$check( 'closed recording boundary rejects all later SQL and closes exactly once', 1 === $db->closed && 'session_closed' === ( $closedCode ?? '' ) && $before === count( $captured ) );
$scratch = sys_get_temp_dir() . '/ecpay-sql-capture-' . bin2hex( random_bytes( 8 ) ); mkdir( $scratch );
$barrier = Barrier::createPhase( $scratch, 'fixture' );
$barrier->arrive( 'before-lock', 'B', '9102' );
$signal = $barrier->await( 'before-lock', 10 );
try { $barrier->arrive( 'before-lock', 'A', '9101' ); } catch ( SubscriptionSqlFailure $error ) { $duplicateCode = $error->getMessage(); }
try { $barrier->await( 'never-arrives', 1 ); } catch ( SubscriptionSqlFailure $error ) { $timeoutCode = $error->getMessage(); }
$check( 'barriers preserve the first signal and give a bounded typed timeout', 'B' === $signal['role'] && 'barrier_exists' === ( $duplicateCode ?? '' ) && 'barrier_timeout' === ( $timeoutCode ?? '' ) );
$badSignals = [
	[ 'stage' => 'malformed-0', 'role' => 'foreign', 'connection_id' => '9102' ],
	[ 'stage' => 'malformed-1', 'role' => 'B', 'connection_id' => [ 'unexpected' ] ],
	[ 'stage' => 'malformed-2', 'role' => 'B', 'connection_id' => '9102', 'extra' => 'benign' ],
];
$badRejected = 0;
foreach ( $badSignals as $badSignal ) {
	file_put_contents( $barrier->path() . '/' . $badSignal['stage'] . '.json', json_encode( $badSignal ) );
	try { $barrier->await( $badSignal['stage'], 1 ); }
	catch ( SubscriptionSqlFailure $error ) { $badRejected += 'barrier_invalid' === $error->getMessage() ? 1 : 0; }
}
$check( 'barrier readback rejects foreign roles, non-scalar connection ids and extra payload fields', 3 === $badRejected );
$receipt = $scratch . '/capture.json';
file_put_contents( $receipt, json_encode( [ 'products' => $products, 'helpers' => $helperReceipt, 'ddl' => $ddl, 'captured_product_sql' => $captured, 'connection_attempts' => Session::connectionAttempts(), 'executed_sql' => 0 ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'CAPTURE_RECEIPTS ' . json_encode( [ 'path' => $receipt, 'sha256' => hash_file( 'sha256', $receipt ) ], JSON_UNESCAPED_SLASHES ) . "\n";
echo "subscription product SQL capture: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
