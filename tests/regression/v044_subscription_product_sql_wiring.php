<?php
/** Offline product wiring/oracle unit proof, never a SQL acceptance result. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlScenario as Scenario;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = (string) ( getenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' ) ?: dirname( __DIR__ ) . '/live/helpers' );
require_once $helpers . '/SubscriptionSqlSession.php';
require_once $helpers . '/SubscriptionProductSqlFixture.php';
require_once __DIR__ . '/helpers/SubscriptionApplicationLog.php';
$applicationLog = \YSCartEcpay\Tests\SubscriptionApplicationLog::start( 'ecpay-sql-wiring-log' );
$pass = 0; $fail = 0;
$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail ): void { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$pass : ++$fail; };
$hasWiring = method_exists( Fixture::class, 'seedPlan' ) && method_exists( Fixture::class, 'readPair' ) && method_exists( Fixture::class, 'request' );
$check( 'deterministic fixture and product request/readback interfaces exist', $hasWiring );
$hasScenario = is_file( $helpers . '/SubscriptionSqlScenario.php' );
$check( 'P1-P12 worker and independent oracle interfaces exist', $hasScenario );
if ( $hasWiring && $hasScenario ) {
	require_once $helpers . '/SubscriptionSqlRequestBoundary.php';
	require_once $helpers . '/SubscriptionSqlScenario.php';
	$sources = Fixture::inspectSources( [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => (string) getenv( 'YS_ECPAY_ROOT' ), 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ] );
	$products = Fixture::loadProduct( $sources );
	$helperReceipt = method_exists( Fixture::class, 'helperReceipt' ) ? Fixture::helperReceipt( $helpers ) : [];
	$check( 'helper receipt names every actual helper hash and canonical or named mutation state',
		11 === count( $helperReceipt['files'] ?? [] ) && is_string( $helperReceipt['state'] ?? null )
		&& ( '1' !== getenv( 'YS_ECPAY_SQL_REQUIRE_CANONICAL_HELPERS' ) || 'CANONICAL' === $helperReceipt['state'] ) );
	$prefix = 'ecps_0123456789ab_'; $now = time(); $token = str_repeat( 'T', 32 );
	$seed = Fixture::seedPlan( $prefix, $now, $token );
	$invalid = 0;
	foreach ( [ [ 0, $token ], [ PHP_INT_MAX, $token ], [ $now, 'short' ] ] as [ $badNow, $badToken ] ) {
		try { Fixture::seedPlan( $prefix, $badNow, $badToken ); } catch ( SubscriptionSqlFailure $e ) { $invalid += 'seed_contract_invalid' === $e->getMessage() ? 1 : 0; }
	}
	try { Fixture::request( 'short' ); } catch ( SubscriptionSqlFailure $e ) { $invalid += 'request_contract_invalid' === $e->getMessage() ? 1 : 0; }
	$check( 'invalid seed time overflow and token inputs are typed before statements', 4 === $invalid && 0 === Session::connectionAttempts() );
	$ddl = Fixture::captureDdl( $prefix );
	$check( 'every planned product column exists in actual captured product DDL', [] === array_filter( array_keys( $seed['product'] ), static fn ( string $column ): bool => 1 !== preg_match( '/\n\s+' . preg_quote( $column, '/' ) . '\s+/', $ddl['statements'][0] ) ) );
	$check( 'seed plan preserves complete deterministic authority without exposing raw selection token',
		$seed === Fixture::seedPlan( $prefix, $now, $token ) && 3 === $seed['subscription']['fulfillment_profile_generation']
		&& '75.00' === $seed['subscription']['renewal_shipping_total'] && 'physical' === $seed['subscription']['fulfillment_contract_kind']
		&& 'fixture_renewable_gateway' === $seed['subscription']['gateway_id'] && null === $seed['subscription']['current_period_key']
		&& 501 === $seed['product']['id'] && -1 === $seed['product']['stock_qty'] && 'no' === $seed['selection']['autoload']
		&& ! str_contains( json_encode( $seed ), $token ) );
	// Independent literal transition, not the coordinator/model's SQL or profile builder.
	$newProfile = json_decode( $seed['subscription']['fulfillment_profile'], true );
	$newProfile['shipping_method_id'] = 'ys_ec_ecpay_ship_unimart'; $newProfile['shipping_provider'] = 'ecpay'; $newProfile['shipping_total'] = '65.00';
	$newProfile['fulfillment_snapshot']['provider_id'] = 'ecpay'; $newProfile['fulfillment_snapshot']['method_id'] = 'ys_ec_ecpay_ship_unimart';
	$newProfile['fulfillment_snapshot']['destination'] = [ 'type' => 'cvs', 'recipient_name' => 'Pair Recipient', 'recipient_phone' => '0912345678', 'country' => 'TW', 'store_id' => '991122', 'store_name' => 'Canonical Store', 'store_address' => 'No. 1 Store Rd.' ];
	$newProfile['fulfillment_snapshot']['service']['shipping_type'] = 'cvs';
	$expectedProfileBytes = json_encode( $newProfile, JSON_UNESCAPED_SLASHES );
	$expectedProfileHash = hash( 'sha256', $expectedProfileBytes );
	$fenceSql = static fn ( string $nonce ): string => "CAST(CAST(CONNECTION_ID() AS CHAR) AS BINARY) = CAST('9101' AS BINARY) AND CAST(DATABASE() AS BINARY) = CAST('ecpay_offline_fixture' AS BINARY) AND CAST(CAST(@ys_profile_tx_owner AS CHAR) AS BINARY) = CAST('" . $nonce . "' AS BINARY)";
	$expectedCas = static fn ( string $nonce ): string => "UPDATE {$prefix}ys_ec_subscriptions\n             SET fulfillment_profile = '{$expectedProfileBytes}',\n                 fulfillment_profile_generation = fulfillment_profile_generation + 1,\n                 fulfillment_profile_hash = '{$expectedProfileHash}',\n                 renewal_shipping_total = '65.00',\n                 fulfillment_profile_updated_at = '2026-09-05 00:00:00',\n                 updated_at = '2026-09-05 00:00:00'\n             WHERE id = 41\n               AND fulfillment_profile_generation = 3\n               AND status IN ('pending', 'active', 'on-hold', 'suspended')\n               AND " . $fenceSql( $nonce );
	$subscriptionRead = 'SELECT * FROM ' . $prefix . 'ys_ec_subscriptions WHERE id = 41';
	$optionRead = 'SELECT option_value FROM ' . $prefix . "options WHERE option_name = '" . $seed['selection']['option_name'] . "'";
	$captured = []; $owner = ''; $stock = -1;
	$db = Session::forCapture( $prefix, static function ( string $sql ) use ( &$captured, &$owner, &$stock, $seed, $prefix, $subscriptionRead, $optionRead, $fenceSql, $expectedCas ): array {
		$rows = [];
		if ( $sql === $subscriptionRead ) { $rows = [ $seed['subscription'] ]; }
		elseif ( '' !== $owner && $sql === $subscriptionRead . ' AND ' . $fenceSql( $owner ) . ' FOR UPDATE' ) { $rows = [ $seed['subscription'] ]; }
		elseif ( $sql === 'SELECT * FROM ' . $prefix . 'ys_ec_products WHERE id = 501' ) { $rows = [ array_replace( $seed['product'], [ 'stock_qty' => $stock ] ) ]; }
		elseif ( $sql === $optionRead ) { $rows = [ [ 'option_value' => $seed['selection']['option_value'] ] ]; }
		elseif ( $sql === "SHOW TABLE STATUS WHERE Name = '" . $prefix . "options'" ) { $rows = [ [ 'Engine' => 'InnoDB' ] ]; }
		elseif ( preg_match( "/\ASET @ys_profile_tx_owner = '([a-f0-9]{32})'\z/", $sql, $match ) ) { $owner = $match[1]; }
		elseif ( $sql === 'SELECT CAST(CONNECTION_ID() AS CHAR) AS cid, DATABASE() AS dbname, CAST(@ys_profile_tx_owner AS CHAR) AS owner' ) { $rows = [ [ 'cid' => '9101', 'dbname' => 'ecpay_offline_fixture', 'owner' => $owner ] ]; }
		elseif ( in_array( $sql, [ 'START TRANSACTION', 'ROLLBACK' ], true ) ) {}
		elseif ( '' !== $owner && $sql === $expectedCas( $owner ) ) {}
		else { throw new SubscriptionSqlFailure( 'offline_statement_not_declared' ); }
		$captured[] = $sql;
		return [ 'error' => '', 'rows' => $rows, 'affected' => 0 ];
	} );
	$GLOBALS['wpdb'] = $db; $GLOBALS['ecpay_sql_plugin'] = new \YangSheep\YSCartEcpay\Plugin();
	$profile = \YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService::class;
	$admitted = $profile::renewal_runtime_admission( (object) $seed['subscription'] );
	$check( 'actual YSProduct loaded row drives untracked-stock renewal admission', true === ( $admitted['ok'] ?? false )
		&& str_starts_with( end( $captured ), 'SELECT * FROM ' . $prefix . 'ys_ec_products WHERE id = 501' ) );
	$stock = 5;
	$rejected = $profile::renewal_runtime_admission( (object) $seed['subscription'] );
	$check( 'actual YSProduct changed stock row fails closed', 'subscription_tracked_stock_not_supported' === ( $rejected['error'] ?? '' ) );
	$stock = -1;
	$before = count( $captured ); $response = Fixture::updateProduct( 41, Fixture::request( $token ), [ 'customer_id' => 91, 'user_id' => 7 ] );
	$trace = array_slice( $captured, $before );
	$cas = array_values( array_filter( $trace, static fn ( string $sql ): bool => 1 === preg_match( '/\AUPDATE ' . $prefix . 'ys_ec_subscriptions\s+SET fulfillment_profile = /', $sql ) ) );
	$check( 'normal actual coordinator resolves canonical CVS and emits its fenced CAS; zero recording rows cannot claim a win',
		'stale_generation' === ( $response['code'] ?? '' ) && 1 === count( $cas )
		&& str_contains( $cas[0], '991122' ) && str_contains( $cas[0], '65.00' ) && str_contains( $cas[0], 'fixture_renewable_gateway' )
		&& str_contains( $cas[0], 'CONNECTION_ID()' ) && in_array( 'ROLLBACK', $trace, true ) && ! in_array( 'COMMIT', $trace, true )
		&& 0 === ( $GLOBALS['ecpay_sql_claim_calls'] ?? 0 ) );
	$pair = Fixture::readPair( $db, 41, $token );
	$check( 'pair readback retains raw bytes/hash and authority without raw token', true === $pair['ok'] && $pair['profile_bytes'] === $seed['subscription']['fulfillment_profile']
		&& $pair['selection_bytes'] === $seed['selection']['option_value'] && 3 === $pair['generation'] && 'issued' === $pair['selection_state']
		&& hash( 'sha256', $pair['profile_bytes'] ) === $pair['profile_hash'] && ! str_contains( json_encode( $pair ), $token ) );
	// Catch a recorder that supplies authority for an undeclared predicate, key or fence.
	$locked = array_values( array_filter( $trace, static fn ( string $sql ): bool => str_ends_with( $sql, ' FOR UPDATE' ) ) );
	$unknownStatements = [
		'subscription-tail' => 'SELECT * FROM ' . $prefix . "ys_ec_subscriptions WHERE id = 41 AND status = 'unlisted'",
		'subscription-id' => 'SELECT * FROM ' . $prefix . 'ys_ec_subscriptions WHERE id = 410',
		'product-tail' => 'SELECT * FROM ' . $prefix . "ys_ec_products WHERE id = 501 AND status = 'unlisted'",
		'product-id' => 'SELECT * FROM ' . $prefix . 'ys_ec_products WHERE id = 5010',
		'option-key' => 'SELECT option_value FROM ' . $prefix . "options WHERE option_name = 'ys_ec_ecpay_subsel_" . str_repeat( '0', 64 ) . "'",
		'option-tail' => 'SELECT option_value FROM ' . $prefix . "options WHERE option_name = '" . $seed['selection']['option_name'] . "' AND autoload = 'yes'",
		'engine-table' => "SHOW TABLE STATUS WHERE Name = '" . $prefix . "unowned'",
		'engine-tail' => "SHOW TABLE STATUS WHERE Name = '" . $prefix . "options' AND Engine = 'MyISAM'",
		'session-projection' => "SELECT CAST(CONNECTION_ID() AS CHAR) AS cid, DATABASE() AS dbname, 'unowned' AS owner",
		'session-tail' => 'SELECT CAST(CONNECTION_ID() AS CHAR) AS cid, DATABASE() AS dbname, CAST(@ys_profile_tx_owner AS CHAR) AS owner WHERE 0 = 1',
		'locked-owner' => str_replace( $owner, str_repeat( '0', 32 ), $locked[0] ?? '' ),
		'locked-tail' => ( $locked[0] ?? '' ) . ' LIMIT 2',
		'cas-tail' => ( $cas[0] ?? '' ) . ' AND 0 = 1',
		'cas-id' => str_replace( 'WHERE id = 41', 'WHERE id = 42', $cas[0] ?? '' ),
		'cas-generation' => str_replace( 'fulfillment_profile_generation = 3', 'fulfillment_profile_generation = 4', $cas[0] ?? '' ),
		'cas-owner' => str_replace( $owner, str_repeat( '0', 32 ), $cas[0] ?? '' ),
		'cas-billing' => str_replace( 'Billing Owner', 'Foreign Owner', $cas[0] ?? '' ),
		'cas-fee' => str_replace( "renewal_shipping_total = '65.00'", "renewal_shipping_total = '66.00'", $cas[0] ?? '' ),
		'cas-database' => str_replace( 'ecpay_offline_fixture', 'foreign_fixture', $cas[0] ?? '' ),
		'nonce-tail' => "SET @ys_profile_tx_owner = '" . str_repeat( 'a', 32 ) . "' ",
		'nonce-shape' => "SET @ys_profile_tx_owner = 'short'",
		'commit-not-declared' => 'COMMIT',
	];
	$negativeReceipts = [];
	foreach ( $unknownStatements as $name => $sql ) {
		$code = 'accepted';
		try { $db->get_results( $sql ); } catch ( SubscriptionSqlFailure $error ) { $code = $error->getMessage(); }
		$negativeReceipts[$name] = $code;
		$check( 'complete recorder rejects ' . $name, 'offline_statement_not_declared' === $code );
	}
	$brokenRow = $seed['subscription']; $brokenValue = json_decode( $seed['selection']['option_value'], true );
	$reader = Session::forCapture( $prefix, static function ( string $sql ) use ( &$brokenRow, &$brokenValue, $subscriptionRead, $optionRead ): array {
		$rows = match ( $sql ) {
			$subscriptionRead => null === $brokenRow ? [] : [ $brokenRow ],
			$optionRead => [ [ 'option_value' => json_encode( $brokenValue ) ] ],
			default => throw new SubscriptionSqlFailure( 'offline_statement_not_declared' ),
		};
		return [ 'error' => '', 'affected' => 0, 'rows' => $rows ];
	} );
	$brokenValue['state'] = 'consumed'; $brokenValue['consumed'] = [ 'subscription_id' => 42, 'generation' => 4, 'at' => '2026-09-05 00:00:00' ];
	$check( 'readback rejects consumed authority for a different subscription', false === Fixture::readPair( $reader, 41, $token )['ok'] );
	$brokenValue['consumed']['subscription_id'] = 41; $brokenValue['consumed']['generation'] = '4';
	$check( 'readback rejects a non-integer consumed generation', false === Fixture::readPair( $reader, 41, $token )['ok'] );
	$brokenValue = json_decode( $seed['selection']['option_value'], true ); $brokenRow['fulfillment_profile_hash'] = str_repeat( 'a', 64 );
	$check( 'readback rejects mismatched profile hash', false === Fixture::readPair( $reader, 41, $token )['ok'] );
	$brokenRow = null;
	$check( 'missing subscription cannot become a successful readback', false === Fixture::readPair( $reader, 41, $token )['ok'] );
	foreach ( [ 'subscription-tail', 'option-key' ] as $name ) {
		$code = 'accepted';
		try { $reader->get_results( $unknownStatements[$name] ); } catch ( SubscriptionSqlFailure $error ) { $code = $error->getMessage(); }
		$negativeReceipts['readback-' . $name] = $code;
		$check( 'corrupt-row reader rejects undeclared ' . $name, 'offline_statement_not_declared' === $code );
	}
	$manifest = Scenario::manifest();
	$check( 'all twelve worker scenarios are deterministic and remain NOT RUN', array_keys( $manifest ) === [ 'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10','P11','P12' ]
		&& $manifest === Scenario::manifest() && 12 === count( array_filter( $manifest, static fn ( array $row ): bool => 'NOT RUN' === $row['acceptance'] ) ) );
	$worker = Scenario::worker( 'P2', 'B' );
	$check( 'competitor worker requires real lock-wait evidence, not timing', in_array( 'server-lock-wait-observed', $worker['required_evidence'], true ) && 'NOT RUN' === $worker['acceptance'] );
	$bad = 0;
	foreach ( [ [ 'P0', 'A' ], [ 'P1', 'foreign' ] ] as [ $case, $role ] ) { try { Scenario::worker( $case, $role ); } catch ( SubscriptionSqlFailure $e ) { $bad += 'worker_contract_invalid' === $e->getMessage() ? 1 : 0; } }
	$check( 'unknown worker case and role fail typed before execution', 2 === $bad );
	$observed = [ 'pair' => $pair, 'sentinels_before' => [ 'subscription42' => str_repeat( 'a', 64 ) ], 'sentinels_after' => [ 'subscription42' => str_repeat( 'a', 64 ) ],
		'stderr_bytes' => 0, 'unexpected_log_events' => 0, 'response' => [ 'success' => false, 'code' => 'stale_generation' ], 'counts' => [ 'cas' => 0, 'consume' => 0, 'commit' => 0 ], 'evidence' => [] ];
	$verdict = Scenario::evaluate( 'P11', $pair, $observed );
	$check( 'ordinary rejection oracle accepts matching synthetic evidence only as an offline oracle verdict', true === $verdict['matches'] && 'NOT RUN' === $verdict['acceptance'] );
	$tampered = $observed; $tampered['pair']['selection_state'] = 'consumed';
	$check( 'split pair never satisfies the independent rejection oracle', false === Scenario::evaluate( 'P11', $pair, $tampered )['matches'] );
	$tampered = $observed; $tampered['stderr_bytes'] = 1;
	$check( 'unexpected diagnostics cannot be a valid scenario result', false === Scenario::evaluate( 'P11', $pair, $tampered )['matches'] );
	$tampered = $observed; $tampered['sentinels_after']['subscription42'] = str_repeat( 'b', 64 );
	$check( 'foreign sibling byte changes reject the scenario', false === Scenario::evaluate( 'P11', $pair, $tampered )['matches'] );
	$check( 'P2 is unproven without server lock-wait observation', false === Scenario::evaluate( 'P2', $pair, $observed )['matches'] );
	$newSelection = json_decode( $pair['selection_bytes'], true ); $newSelection['state'] = 'consumed';
	$newSelection['consumed'] = [ 'subscription_id' => 41, 'generation' => 4, 'at' => '2026-09-05 00:00:00' ];
	$newPair = array_replace( $pair, [ 'generation' => 4, 'shipping_total' => '65.00', 'selection_state' => 'consumed', 'selection_generation' => 4,
		'profile_bytes' => json_encode( $newProfile, JSON_UNESCAPED_SLASHES ), 'selection_bytes' => json_encode( $newSelection, JSON_UNESCAPED_SLASHES ),
		'profile_updated_at' => '2026-09-05 00:00:00', 'updated_at' => '2026-09-05 00:00:00' ] );
	$newPair['profile_hash'] = hash( 'sha256', $newPair['profile_bytes'] ); $newPair['selection_sha256'] = hash( 'sha256', $newPair['selection_bytes'] );
	$check( 'independent literal expected complete profile equals actual normal product CAS bytes and hash',
		1 === count( $cas ) && str_contains( $cas[0], "fulfillment_profile = '" . $newPair['profile_bytes'] . "'" )
		&& str_contains( $cas[0], "fulfillment_profile_hash = '" . $newPair['profile_hash'] . "'" ) );
	$brokenRow = array_replace( $seed['subscription'], [ 'fulfillment_profile' => $newPair['profile_bytes'], 'fulfillment_profile_hash' => $newPair['profile_hash'], 'fulfillment_profile_generation' => 4, 'renewal_shipping_total' => '65.00', 'fulfillment_profile_updated_at' => $newPair['profile_updated_at'], 'updated_at' => $newPair['updated_at'] ] );
	$brokenValue = $newSelection;
	$check( 'readback admits the complete canonical generation-four consumed fixture', $newPair === Fixture::readPair( $reader, 41, $token ) );
	$oracleCases = [
		'P1' => [ $newPair, [1,1,1], ['after-commit-readback','renewal-projection'], '' ],
		'P2' => [ $newPair, [1,1,1], ['server-lock-wait-observed','competitor-authority-changed'], '' ],
		'P3' => [ $pair, [1,1,0], ['precommit-drift','rollback-readback'], 'profile_update_session_drift' ],
		'P4' => [ $newPair, [1,1,1], ['commit-applied','ack-lost','protective-rollback'], 'profile_update_commit_indeterminate' ],
		'P5' => [ $pair, [1,1,1], ['commit-suppressed','protective-rollback'], 'profile_update_commit_indeterminate' ],
		'P6' => [ $pair, [1,1,0], ['original-closed-once','poison-before-close','no-post-close-sql'], 'profile_update_rollback_indeterminate' ],
		'P7' => [ $pair, [1,0,0], ['original-rollback','no-write-on-B'], 'store_selection_invalid' ],
		'P8' => [ $pair, [1,1,0], ['physical-session-changed','nonce-absent','fenced-consume-zero'], 'store_selection_invalid' ],
		'P9' => [ $pair, [1,1,0], ['physical-session-changed','no-commit-attempt'], 'profile_update_session_drift' ],
		'P10' => [ $pair, [1,1,1], ['replacement-commit','postcommit-drift'], 'profile_update_commit_indeterminate' ],
		'P11' => [ $pair, [0,0,0], [], 'stale_generation' ],
		'P12' => [ $pair, [0,0,0], ['exact-byte-mismatch','bindings-rechecked'], 'store_selection_invalid' ],
	];
	foreach ( $oracleCases as $case => [ $expectedPair, $counts, $evidence, $code ] ) {
		$unit = array_replace( $observed, [ 'pair' => $expectedPair, 'counts' => array_combine( [ 'cas','consume','commit' ], $counts ), 'evidence' => $evidence,
			'response' => '' === $code ? [ 'success' => true, 'data' => [ 'generation' => 4 ] ] : [ 'success' => false, 'code' => $code, 'retryable' => false, 'requires_manual_reconciliation' => true ] ] );
		$ok = Scenario::evaluate( $case, $pair, $unit );
		$unit['counts']['consume']++;
		$bad = Scenario::evaluate( $case, $pair, $unit );
		$check( $case . ' synthetic outcome matches only its literal counts and never becomes SQL acceptance', true === $ok['matches'] && false === $bad['matches'] && 'NOT RUN' === $ok['acceptance'] );
	}
	$newObservation = array_replace( $observed, [ 'pair' => $newPair, 'counts' => [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ], 'evidence' => ['after-commit-readback','renewal-projection'], 'response' => [ 'success' => true, 'data' => [ 'generation' => 4 ] ] ] );
	$newObservation['pair']['profile_updated_at'] = $pair['profile_updated_at'];
	$check( 'new-pair oracle rejects an unchanged authority timestamp', false === Scenario::evaluate( 'P1', $pair, $newObservation )['matches'] );
	foreach ( [ 'payment', 'parcel', 'recipient', 'extra-profile', 'object-list', 'consumed-at', 'extra-selection' ] as $change ) {
		$badProfile = $newProfile; $badSelection = $newSelection;
		if ( 'payment' === $change ) { $badProfile['fulfillment_snapshot']['service']['payment_method_id'] = 'foreign-gateway'; }
		if ( 'parcel' === $change ) { $badProfile['fulfillment_snapshot']['parcel']['weight_kg'] = '9.000'; }
		if ( 'recipient' === $change ) { $badProfile['fulfillment_snapshot']['destination']['recipient_name'] = 'Foreign Recipient'; }
		if ( 'extra-profile' === $change ) { $badProfile['foreign'] = true; }
		if ( 'object-list' === $change ) { $badProfile['fulfillment_snapshot']['items'] = (object) $badProfile['fulfillment_snapshot']['items']; }
		if ( 'consumed-at' === $change ) { $badSelection['consumed']['at'] = '2026-08-30 11:00:00'; }
		if ( 'extra-selection' === $change ) { $badSelection['foreign'] = true; }
		$badObservation = $newObservation; $badObservation['pair'] = $newPair;
		$badObservation['pair']['profile_bytes'] = json_encode( $badProfile, JSON_UNESCAPED_SLASHES );
		$badObservation['pair']['profile_hash'] = hash( 'sha256', $badObservation['pair']['profile_bytes'] );
		$badObservation['pair']['selection_bytes'] = json_encode( $badSelection, JSON_UNESCAPED_SLASHES );
		$badObservation['pair']['selection_sha256'] = hash( 'sha256', $badObservation['pair']['selection_bytes'] );
		$check( 'new-pair full authority rejects rehashed ' . $change, false === Scenario::evaluate( 'P1', $pair, $badObservation )['matches'] );
	}
	$scratch = sys_get_temp_dir() . '/ecpay-sql-wiring-' . bin2hex( random_bytes( 8 ) ); mkdir( $scratch ); $receipt = $scratch . '/receipts.json';
	file_put_contents( $receipt, json_encode( [ 'products' => $products, 'helpers' => $helperReceipt, 'seed_plan' => $seed, 'recorded_sql' => $captured, 'negative_statement_verdicts' => $negativeReceipts, 'response' => $response, 'pair' => $pair, 'workers' => $manifest, 'connection_attempts' => Session::connectionAttempts(), 'sql_execution' => 'NOT RUN' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'WIRING_RECEIPTS ' . json_encode( [ 'path' => $receipt, 'sha256' => hash_file( 'sha256', $receipt ) ], JSON_UNESCAPED_SLASHES ) . "\n";
}
$logReceipt = \YSCartEcpay\Tests\SubscriptionApplicationLog::inspect( $applicationLog, [] );
$check( 'normal wiring application log is exactly empty and retained', $logReceipt['ok'] );
echo 'APPLICATION_LOG ' . json_encode( $logReceipt, JSON_UNESCAPED_SLASHES ) . "\n";
echo "subscription product SQL wiring: {$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
