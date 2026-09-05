<?php
/** Private worker packet boundary; product schedules use explicitly supplied sessions only. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
require_once __DIR__ . '/SubscriptionSqlAllocation.php';
require_once __DIR__ . '/SubscriptionSqlEvidence.php';
require_once __DIR__ . '/SubscriptionSqlSchema.php';
require_once __DIR__ . '/SubscriptionSqlFaults.php';
require_once __DIR__ . '/SubscriptionSqlBarrier.php';
final class SubscriptionSqlWorker {
	public static function validatePacket( mixed $packet ): array {
		if ( ! SubscriptionSqlEvidence::exactKeys( $packet, [ 'version','phase','case','role','allocation','source_heads','token' ] ) || 1 !== $packet['version']
			|| ! is_string( $packet['phase'] ) || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,79}\z/', $packet['phase'] ) || ! is_string( $packet['case'] ) || ! isset( SubscriptionSqlEvidence::cases()[$packet['case']] )
			|| ! in_array( $packet['role'], [ 'A','B' ], true ) || ! is_string( $packet['token'] ) || 1 !== preg_match( '/\A[A-Za-z0-9]{32}\z/', $packet['token'] )
			|| ! SubscriptionSqlEvidence::exactKeys( $packet['source_heads'], [ 'core','ecpay','affiliate' ] ) ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		foreach ( $packet['source_heads'] as $head ) { if ( ! is_string( $head ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/', $head ) ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); } }
		try { SubscriptionSqlAllocation::validate( $packet['allocation'] ); } catch ( SubscriptionSqlFailure $e ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		return $packet;
	}
	public static function protocol( mixed $packet ): array {
		$p = self::validatePacket( $packet );
		return [ 'version' => 1, 'phase' => $p['phase'], 'case' => $p['case'], 'role' => $p['role'], 'scope' => 'IPC ONLY', 'token_digest' => hash( 'sha256', $p['token'] ), 'connection_attempts' => SubscriptionSqlSession::connectionAttempts(), 'sql_execution' => 'NOT RUN' ];
	}
	private static function signal( SubscriptionSqlBarrier $barrier, array $p, string $stage, int $sequence, SubscriptionSqlSession $db, array $data ): array {
		$id = $db->identity()['connection_id'];
		if ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $id ) ) { throw new SubscriptionSqlFailure( 'worker_identity_missing' ); }
		$receipt = SubscriptionSqlEvidence::persist( $barrier->path(), strtolower( $p['case'] ) . '-' . $stage . '-receipt.json',
			[ 'version'=>1,'phase'=>$p['phase'],'case'=>$p['case'],'role'=>$p['role'],'sequence'=>$sequence,'scope'=>'CAPTURE CONTROL FLOW ONLY','data'=>$data ] );
		return $barrier->publish( $p['case'], $stage, $p['role'], $id, $receipt );
	}
	/** Real product code over supplied capture transports. There is deliberately no live lane. */
	public static function run( array $packet, SubscriptionSqlSession $a, ?SubscriptionSqlSession $b, SubscriptionSqlBarrier $barrier ): array {
		$p = self::validatePacket( $packet ); $case = $p['case']; $role = $p['role'];
		if ( ! $a->isCapture() || ( null !== $b && ! $b->isCapture() ) ) { throw new SubscriptionSqlFailure( 'sql_execution_not_authorized_in_checkpoint' ); }
		if ( ! $a->ready || $a->prefix !== $p['allocation']['prefix'] || basename( $barrier->path() ) !== $p['phase'] || ( null !== $b && ( $b === $a || $b->prefix !== $a->prefix || ! $b->ready ) )
			|| ( 'A' === $role && in_array( $case, ['P7','P8','P9','P10'], true ) && null === $b ) ) { throw new SubscriptionSqlFailure( 'worker_session_invalid' ); }
		$sources = SubscriptionProductSqlFixture::inspectSources( [ 'core'=>getenv( 'YS_CORE_ROOT' ),'ecpay'=>getenv( 'YS_ECPAY_ROOT' ),'affiliate'=>getenv( 'YS_AFFILIATE_ROOT' ) ] );
		foreach ( $p['source_heads'] as $name=>$head ) { if ( $sources[$name]['head'] !== $head ) { throw new SubscriptionSqlFailure( 'worker_source_drift' ); } }
		$products = SubscriptionProductSqlFixture::loadProduct( $sources ); require_once __DIR__ . '/SubscriptionSqlRequestBoundary.php';
		$logPath = $barrier->path() . '/' . strtolower( $case ) . '-' . strtolower( $role ) . '-application.log';
		if ( file_exists( $logPath ) ) { throw new SubscriptionSqlFailure( 'worker_log_exists' ); }
		$handle = fopen( $logPath, 'x' ); if ( false === $handle ) { throw new SubscriptionSqlFailure( 'worker_log_failed' ); } fclose( $handle );
		$oldLog = ini_get( 'error_log' ); $oldLogErrors = ini_get( 'log_errors' ); ini_set( 'error_log', $logPath ); ini_set( 'log_errors', '1' );
		$oldDb = $GLOBALS['wpdb'] ?? null; $oldPlugin = $GLOBALS['ecpay_sql_plugin'] ?? null;
		$GLOBALS['wpdb'] = $a; $GLOBALS['ecpay_sql_plugin'] = new \YangSheep\YSCartEcpay\Plugin();
		$registry = \YangSheep\Ecommerce\Shipping\YSShippingRegistry::class; $enabled = $registry::$enabled; $provider = $registry::$fixtureProvider;
		$barriers = []; $projection = null; $interference = null; $response = null;
		$fault = SubscriptionSqlFaults::arm( $case, $role );
		try {
			$a->observe( static fn (): mixed => $a->get_row( SubscriptionSqlSession::SESSION_SQL, 'ARRAY_A' ) );
			if ( 'A' === $role ) {
				$seed = $a->observe( static fn (): array => SubscriptionSqlSchema::seed( $a, SubscriptionSqlSchema::plan( $a->prefix ), $p['token'], 1788566400 ), 'schema-setup' );
				if ( 'P11d' === $case ) { $interference = $a->observe( static fn (): array => SubscriptionSqlSchema::interfere( $a, $p['token'], $case ), 'schema-setup' ); }
				$baseline = $a->observe( static fn (): array => SubscriptionSqlSchema::snapshot( $a ) );
				$barriers[] = self::signal( $barrier, $p, 'setup-complete', 1, $a, [ 'baseline'=>$baseline,'seed'=>$seed ] );
			} else {
				$setup = $barrier->awaitBound( $case, 'setup-complete' ); $baseline = $setup['receipt']['data']['baseline']; $seed = $setup['receipt']['data']['seed'];
			}
			$action = static function ( string $name ) use ( $a, $b, $barrier, $p, &$barriers, &$interference ): void {
				if ( 'none' === $name ) { return; }
				if ( 'swap-global' === $name ) { $GLOBALS['wpdb'] = $b; return; }
				if ( in_array( $name, ['replace-before-consume','replace-before-verify','replace-before-commit'], true ) ) {
					if ( null === $b ) { throw new SubscriptionSqlFailure( 'replacement_required' ); }
					$a->replaceWith( $b ); $a->observe( static fn (): mixed => $a->get_row( SubscriptionSqlSession::SESSION_SQL, 'ARRAY_A' ) ); return;
				}
				if ( ! in_array( $name, ['pause-before-commit','pause-before-claim'], true ) ) { throw new SubscriptionSqlFailure( 'fault_action_invalid' ); }
				$barriers[] = self::signal( $barrier, $p, 'a-seam', 2, $a, [ 'action'=>$name ] );
				$other = $barrier->awaitBound( $p['case'], 'b-seam' );
				if ( 'P2' === $p['case'] ) {
					$wait = self::observeWait( $a, $other['marker']['connection_id'] );
					$barriers[] = self::signal( $barrier, $p, 'wait-observed', 3, $a, $wait );
				} else { $interference = $other['receipt']['data']['interference'] ?? null; }
				$barrier->awaitBound( $p['case'], 'release' );
			};
			$a->instrument( static function ( string $kind, string $sql, array $id ) use ( $fault, $action, $case, $role, $barrier, $p, $a, &$barriers ): array {
				if ( 'P2' === $case && 'B' === $role && 'locked-read' === $kind ) { $barriers[] = self::signal( $barrier, $p, 'b-seam', 1, $a, [ 'lock_sql_sha256'=>hash( 'sha256', $sql ) ] ); }
				$d = $fault->before( $kind, $sql, $id ); $action( $d['action'] ); return $d;
			}, $fault->after(...) );
			$GLOBALS['ecpay_sql_before_real_claim'] = static function () use ( $fault, $action, $a ): void { $d = $fault->before( 'claim', '', $a->identity() ); $action( $d['action'] ); };
			if ( 'B' === $role && in_array( $case, ['P12a','P12b'], true ) ) {
				$barrier->awaitBound( $case, 'a-seam' );
				$interference = $a->observe( static fn (): array => SubscriptionSqlSchema::interfere( $a, $p['token'], $case ), 'fault-control' );
				$barriers[] = self::signal( $barrier, $p, 'b-seam', 1, $a, [ 'interference'=>$interference ] );
			}
			if ( 'A' === $role || 'P2' === $case ) {
				if ( 'B' === $role ) { $barrier->awaitBound( $case, 'a-seam' ); }
				$input = SubscriptionProductSqlFixture::request( $p['token'] ); $owner = ['customer_id'=>91,'user_id'=>7]; $id = 41;
				if ( 'P11a' === $case ) { $owner['user_id'] = 8; }
				if ( 'P11b' === $case ) { $id = 42; }
				if ( 'P11c' === $case ) { $input['expected_generation'] = 2; }
				if ( 'P11e' === $case ) { $input['selection_token'] = hash( 'md5', $p['token'] . 'unissued' ); }
				if ( 'P11f' === $case ) { $registry::$enabled = false; }
				if ( 'P11g' === $case ) { $registry::$fixtureProvider = 'other-provider'; }
				if ( 'P11h' === $case ) {
					$input['ecpay_store_token'] = $p['token'];
					$input['cart_scope'] = 'sub_41'; $input['shipping_method'] = $input['shipping_method_id']; $input['payment_method'] = 'fixture_renewable_gateway';
					$response = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::claim_selection_authoritative( $input, $input['shipping_method_id'], 'fixture_renewable_gateway', [] );
				} elseif ( 'P12b' === $case ) {
					$nonce = bin2hex( random_bytes(16) );
					if ( false === $a->query( $a->prepare( 'SET @ys_profile_tx_owner = %s', $nonce ) ) || false === $a->query( 'START TRANSACTION' ) ) { throw new SubscriptionSqlFailure( 'store_fixture_transaction_failed' ); }
					$a->get_row( SubscriptionSqlSession::SESSION_SQL, 'ARRAY_A' ); $fence = $a->identity(); $fence['transaction_db'] = $a;
					$barriers[] = self::signal( $barrier, $p, 'a-seam', 2, $a, ['old_bytes_sha256'=>hash( 'sha256', $seed['issued_row']['option_value'] )] );
					$other = $barrier->awaitBound( $case, 'b-seam' ); $interference = $other['receipt']['data']['interference'] ?? null; $barrier->awaitBound( $case, 'release' );
					$claimed = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpaySubscriptionSelectionStore::claim( $p['token'], $seed['issued_row']['option_value'], 41, 4, $fence );
					if ( false === $a->query( 'ROLLBACK' ) || '' !== $a->last_error ) { throw new SubscriptionSqlFailure( 'store_fixture_rollback_failed' ); }
					$response = [ 'store_claimed'=>$claimed,'scope'=>'ACTUAL STORE CAS ONLY' ];
				} else { $response = SubscriptionProductSqlFixture::updateProduct( $id, $input, $owner ); }
			}
			$a->instrument( null, null ); unset( $GLOBALS['ecpay_sql_before_real_claim'] );
			if ( 'A' === $role ) { $barriers[] = self::signal( $barrier, $p, 'a-complete', 4, $a, [ 'response'=>$response ] ); }
			else { $barrier->awaitBound( $case, 'a-complete' ); }
			$observer = 'P7' === $case && 'A' === $role ? $b : $a;
			// P6 must never query poisoned A; B owns the independent final observation.
			$readback = $observer->ready ? $observer->observe( static fn (): array => SubscriptionSqlSchema::snapshot( $observer ) ) : null;
			if ( 'P1' === $case && 'B' === $role ) {
				$GLOBALS['wpdb'] = $a;
				$projection = $a->observe( static function (): array { $row = \YangSheep\Ecommerce\Models\YSSubscription::find(41); return \YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService::renewal_projection( $row ); } );
			}
			if ( 'B' === $role ) { $barriers[] = self::signal( $barrier, $p, 'b-complete', 2, $a, [ 'readback_sha256'=>hash( 'sha256', json_encode( $readback, JSON_THROW_ON_ERROR ) ) ] ); }
			return [ 'version'=>1,'phase'=>$p['phase'],'case'=>$case,'role'=>$role,'topology'=>SubscriptionSqlEvidence::cases()[$case]['topology'],
				'source_receipt'=>['sources'=>$sources,'products'=>$products,'helpers'=>SubscriptionProductSqlFixture::helperReceipt(__DIR__)], 'runtime_receipt'=>['version'=>PHP_VERSION,'sha256'=>hash_file('sha256',PHP_BINARY),'scope'=>'CAPTURE ONLY'],
				'session_receipts'=>['identity'=>$a->identity(),'replacements'=>$a->physicalReplacements(),'closes'=>$a->closes(),'ready'=>$a->ready], 'statement_receipts'=>$a->statements(),'barrier_receipts'=>$barriers,
				'baseline_receipt'=>$baseline,'readback_receipt'=>$readback,'product_response'=>$response,'projection_receipt'=>$projection,'fault_receipt'=>array_merge($fault->finish(),['interference'=>$interference]),
				'diagnostics'=>['log'=>['path'=>realpath($logPath),'bytes'=>filesize($logPath),'sha256'=>hash_file('sha256',$logPath)]], 'rc'=>0 ];
		} finally {
			$a->instrument( null, null ); unset( $GLOBALS['ecpay_sql_before_real_claim'] ); $GLOBALS['wpdb'] = $oldDb; $GLOBALS['ecpay_sql_plugin'] = $oldPlugin;
			$registry::$enabled = $enabled; $registry::$fixtureProvider = $provider; ini_set( 'error_log', (string) $oldLog ); ini_set( 'log_errors', (string) $oldLogErrors );
		}
	}
	/** Read-only observation on A; row absence never degrades into a timing assertion. */
	public static function observeWait( SubscriptionSqlSession $a, string $bId ): array {
		$id = $a->identity();
		if ( ! is_string( $id['connection_id'] ) || ! is_string( $id['database'] ) || 1 !== preg_match( '/\A[1-9][0-9]{0,19}\z/', $bId ) || $bId === $id['connection_id'] ) { throw new SubscriptionSqlFailure( 'contention_proof_unavailable' ); }
		$sql = $a->prepare( 'SELECT rt.PROCESSLIST_ID AS requesting_id, bt.PROCESSLIST_ID AS blocking_id, rl.OBJECT_SCHEMA AS schema_name, rl.OBJECT_NAME AS table_name, rl.INDEX_NAME AS index_name, rl.LOCK_DATA AS lock_data FROM performance_schema.data_lock_waits w JOIN performance_schema.threads rt ON rt.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks rl ON rl.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND rl.ENGINE = w.ENGINE WHERE rt.PROCESSLIST_ID = %d AND bt.PROCESSLIST_ID = %d AND rl.OBJECT_SCHEMA = %s AND rl.OBJECT_NAME = %s AND rl.INDEX_NAME = %s AND rl.LOCK_DATA = %s', $bId, $id['connection_id'], $id['database'], $a->prefix . 'ys_ec_subscriptions', 'PRIMARY', '41' );
		$until=hrtime(true)+10000000000;
		do {
			$rows = $a->observe( static function () use ( $a, $sql ): array { $rows = $a->get_results( $sql, 'ARRAY_A' ); if ( '' !== $a->last_error ) { throw new SubscriptionSqlFailure( 'contention_proof_unavailable' ); } return $rows; } );
			if([]!==$rows) { break; } usleep(1000);
		} while(hrtime(true)<$until);
		if ( 1 !== count( $rows ) || (string)($rows[0]['requesting_id'] ?? '') !== $bId || (string)($rows[0]['blocking_id'] ?? '') !== $id['connection_id'] || ( $rows[0]['schema_name'] ?? null ) !== $id['database'] || ( $rows[0]['table_name'] ?? null ) !== $a->prefix . 'ys_ec_subscriptions' || ( $rows[0]['index_name'] ?? null ) !== 'PRIMARY' || ( $rows[0]['lock_data'] ?? null ) !== '41' ) { throw new SubscriptionSqlFailure( 'contention_proof_unavailable' ); }
		return [ 'kind'=>'data_lock_waits_join','sql_sha256'=>hash('sha256',$sql),'rows'=>$rows,'scope'=>$a->isCapture() ? 'CAPTURE RESULT CONTROL ONLY' : 'SUPPLIED SERVER OBSERVATION' ];
	}
}
