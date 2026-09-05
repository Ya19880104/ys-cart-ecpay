<?php
/** Owned setup plan and supplied-session seed; no connector and no automatic cleanup. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
require_once __DIR__ . '/SubscriptionProductSqlFixture.php';
final class SubscriptionSqlSchema {
	/** Finite read contract only. No MySQL SHOW CREATE normalization or schema acceptance is implied. */
	public static function metadataPlan(SubscriptionSqlSession $db,string $database):array {
		if(1!==preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$database)) { throw new SubscriptionSqlFailure('metadata_plan_invalid'); }
		$schema=self::plan($db->prefix);
		$queries=[
			'session'=>'SELECT VERSION() AS version, DATABASE() AS database_name, CAST(CONNECTION_ID() AS CHAR) AS connection_id, @@SESSION.transaction_isolation AS transaction_isolation, @@SESSION.autocommit AS autocommit, @@SESSION.sql_mode AS sql_mode, @@SESSION.time_zone AS time_zone, @@SESSION.character_set_connection AS character_set_connection, @@SESSION.collation_connection AS collation_connection',
			'table-engines'=>'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$db->prepare('%s',$database).' AND TABLE_NAME IN ('.implode(', ',array_map(static fn(string $table):string=>$db->prepare('%s',$table),$schema['tables'])).') ORDER BY TABLE_NAME',
		];
		foreach($schema['tables'] as $table) {
			$qualified=$db->prepare('%i.%i',$database,$table);
			$queries[$table.':create']='SHOW CREATE TABLE '.$qualified;
			$queries[$table.':columns']='SHOW FULL COLUMNS FROM '.$qualified;
			$queries[$table.':indexes']='SHOW INDEX FROM '.$qualified;
		}
		return ['database'=>$database,'prefix'=>$db->prefix,'queries'=>$queries,'captured_ddl'=>$schema['ddl'],'scope'=>'CAPTURE READ CONTRACT ONLY','schema_acceptance'=>'UNSATISFIED'];
	}
	/** Retains unvalidated raw metadata; deliberately never returns a schema or server acceptance verdict. */
	public static function captureMetadata(SubscriptionSqlSession $db,array $plan):array {
		if(!$db->isCapture()) { throw new SubscriptionSqlFailure('sql_execution_not_authorized_in_checkpoint'); }
		if(!is_string($plan['database']??null) || $plan!==self::metadataPlan($db,$plan['database'])) { throw new SubscriptionSqlFailure('metadata_plan_invalid'); }
		$reads=$db->observe(static function() use($db,$plan):array {
			$reads=[];
			foreach($plan['queries'] as $key=>$sql) {
				$rows=$db->get_results($sql,'ARRAY_A');
				if(''!==$db->last_error) { throw new SubscriptionSqlFailure('metadata_read_failed'); }
				$reads[$key]=['sql'=>$sql,'sql_sha256'=>hash('sha256',$sql),'rows'=>$rows];
			}
			return $reads;
		});
		return ['plan'=>$plan,'reads'=>$reads,'runtime'=>['php'=>PHP_VERSION,'binary_sha256'=>hash_file('sha256',PHP_BINARY),'mysqli_loaded'=>extension_loaded('mysqli')],'scope'=>'UNVALIDATED CAPTURE METADATA','schema_acceptance'=>'UNSATISFIED','sql_execution'=>'NOT RUN'];
	}
	public static function plan( string $prefix ): array {
		$ddl = SubscriptionProductSqlFixture::captureDdl( $prefix );
		$tables = [];
		foreach ( $ddl['statements'] as $sql ) {
			if ( 1 !== preg_match( '/\ACREATE TABLE ([A-Za-z0-9_]+)\s*\(/', $sql, $match ) || ! str_contains( $sql, 'ENGINE=InnoDB' ) ) { throw new SubscriptionSqlFailure( 'schema_plan_invalid' ); }
			$tables[] = $match[1];
		}
		return [ 'prefix' => $prefix, 'tables' => $tables, 'ddl' => $ddl, 'scope' => 'CAPTURE ONLY' ];
	}
	public static function seed( SubscriptionSqlSession $db, array $plan, string $opaqueToken, int $now ): array {
		if ( $db->prefix !== ( $plan['prefix'] ?? null ) || $plan !== self::plan( $db->prefix ) ) { throw new SubscriptionSqlFailure( 'schema_plan_invalid' ); }
		if ( ! $db->isCapture() ) { throw new SubscriptionSqlFailure( 'sql_execution_not_authorized_in_checkpoint' ); }
		$counts = [];
		foreach ( $plan['tables'] as $table ) {
			$count = $db->get_var( 'SELECT COUNT(*) FROM ' . $db->prepare( '%i', $table ) );
			if ( '' !== $db->last_error || ( 0 !== $count && '0' !== $count ) ) { throw new SubscriptionSqlFailure( 'seed_requires_empty_tables' ); }
			$counts[$table] = $count;
		}
		$seed = SubscriptionProductSqlFixture::seedPlan( $db->prefix, $now, $opaqueToken );
		foreach ( [ 'ys_ec_products' => $seed['product'], 'ys_ec_subscriptions' => $seed['subscription'] ] as $suffix => $row ) {
			if ( 1 !== $db->insert( $db->prefix . $suffix, $row ) || '' !== $db->last_error ) { throw new SubscriptionSqlFailure( 'seed_insert_failed' ); }
		}
		$selection = [ 'shipping_id' => 'ys_ec_ecpay_ship_unimart', 'cvs_type' => 'UNIMARTC2C', 'store_id' => '991122', 'store_name' => 'Canonical Store', 'store_address' => 'No. 1 Store Rd.', 'store_verified' => 1,
			'collection_mode' => 'N', 'payment_method' => 'fixture_renewable_gateway', 'cart_scope' => 'sub_41', 'context' => 'subscription', 'authority_marker' => 'subscription_fulfillment_v1', 'subscription_id' => 41 ];
		$issueStarted=time();
		$old = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = $db;
		$GLOBALS['ecpay_sql_issuance_token'] = $opaqueToken;
		try {
			$method = new \ReflectionMethod( \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::class, 'issue_selection_token' );
			$issued = $method->invoke( null, $selection, 'u:7' );
			if ( $opaqueToken !== $issued ) { throw new SubscriptionSqlFailure( 'seed_issuance_failed' ); }
		} finally { unset( $GLOBALS['ecpay_sql_issuance_token'] ); $GLOBALS['wpdb'] = $old; }
		$issuedRow = $db->get_row( $db->prepare( 'SELECT option_name, option_value, autoload FROM ' . $db->options . ' WHERE option_name = %s', $seed['selection']['option_name'] ), 'ARRAY_A' );
		$decoded = is_array( $issuedRow ) && is_string( $issuedRow['option_value'] ?? null ) ? json_decode( $issuedRow['option_value'], true ) : null;
		if ( '' !== $db->last_error || ! is_array( $issuedRow ) || 'no' !== ( $issuedRow['autoload'] ?? null ) || $seed['selection']['option_name'] !== ( $issuedRow['option_name'] ?? null )
			|| ! is_array( $decoded ) || 'issued' !== ( $decoded['state'] ?? null ) || 41 !== ( $decoded['record']['subscription_id'] ?? null ) || 'u:7' !== ( $decoded['record']['principal'] ?? null )
			|| ! is_int( $decoded['record']['expires_at'] ?? null ) || $decoded['record']['expires_at'] <= time() ) { throw new SubscriptionSqlFailure( 'seed_issuance_readback_invalid' ); }
		$expected=json_decode($seed['selection']['option_value'],true,512,JSON_THROW_ON_ERROR);
		$expected['record']['expires_at']=$decoded['record']['expires_at'];
		if($decoded!==$expected || array_keys($issuedRow)!==['option_name','option_value','autoload'] || $decoded['record']['expires_at']<$issueStarted+1800 || $decoded['record']['expires_at']>time()+1800) { throw new SubscriptionSqlFailure('seed_issuance_readback_invalid'); }
		$sentinel = $seed['subscription']; $sentinel['id'] = 42;
		if ( 1 !== $db->insert( $db->prefix . 'ys_ec_subscriptions', $sentinel ) || '' !== $db->last_error
			|| 1 !== $db->insert( $db->options, [ 'option_name'=>'ecpay_fixture_sentinel','option_value'=>'preserve-sentinel-bytes','autoload'=>'no' ] ) || '' !== $db->last_error ) { throw new SubscriptionSqlFailure( 'seed_sentinel_failed' ); }
		return [ 'token_digest' => hash( 'sha256', $opaqueToken ), 'issued_row'=>$issuedRow, 'initial_counts'=>$counts, 'scope' => 'CAPTURE RESULT CONTROL ONLY', 'sql_execution' => 'NOT RUN' ];
	}
	/** Full raw owned table rows, retaining SQL null and every foreign sibling byte. */
	public static function snapshot( SubscriptionSqlSession $db ): array {
		$result = [];
		foreach ( self::plan( $db->prefix )['tables'] as $table ) {
			$key = $table === $db->options ? 'option_name' : 'id';
			$rows = $db->get_results( 'SELECT * FROM ' . $db->prepare( '%i', $table ) . ' ORDER BY ' . $db->prepare( '%i', $key ), 'ARRAY_A' );
			if ( '' !== $db->last_error ) { throw new SubscriptionSqlFailure( 'snapshot_read_failed' ); }
			$result[$table] = $rows;
		}
		return $result;
	}
	/** Controlled owned-row interference only, never a replacement for product CAS. */
	public static function interfere( SubscriptionSqlSession $db, string $token, string $case ): array {
		if ( ! in_array( $case, ['P11d','P12a','P12b'], true ) || 1 !== preg_match( '/\A[A-Za-z0-9]{32}\z/', $token ) ) { throw new SubscriptionSqlFailure( 'interference_invalid' ); }
		$key = 'ys_ec_ecpay_subsel_' . hash( 'sha256', $token );
		$before = $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', $key ) );
		$value = is_string( $before ) ? json_decode( $before, true ) : null;
		if ( '' !== $db->last_error || ! is_array( $value ) || 'issued' !== ( $value['state'] ?? null ) || 41 !== ( $value['record']['subscription_id'] ?? null ) ) { throw new SubscriptionSqlFailure( 'interference_invalid' ); }
		if ( 'P12a' === $case ) { $value['record']['principal'] = 'u:8'; }
		if ( 'P11d' === $case ) { $value['record']['expires_at'] = 1; }
		$after = 'P12b' === $case ? " \n" . $before : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		$sql = $db->prepare( 'UPDATE ' . $db->options . ' SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s', $after, $key, $before );
		if ( 1 !== $db->query( $sql ) || '' !== $db->last_error ) { throw new SubscriptionSqlFailure( 'interference_failed' ); }
		$readback = $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', $key ) );
		if ( '' !== $db->last_error || $after !== $readback ) { throw new SubscriptionSqlFailure( 'interference_readback_failed' ); }
		return [ 'case'=>$case,'option_name'=>$key,'before_bytes'=>$before,'after_bytes'=>$after,'sql_sha256'=>hash( 'sha256', $sql ) ];
	}
}
