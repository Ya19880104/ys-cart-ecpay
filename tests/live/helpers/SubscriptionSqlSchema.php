<?php
/** Owned setup plan and supplied-session seed; no connector and no automatic cleanup. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
require_once __DIR__ . '/SubscriptionProductSqlFixture.php';
final class SubscriptionSqlSchema {
	public static function validateServer(array $rows,string $database):array {
		$keys=['version','database_name','connection_id','transaction_isolation','autocommit','sql_mode','time_zone','character_set_connection','collation_connection'];
		$r=$rows[0]??null;
		if(1!==count($rows) || !SubscriptionSqlEvidence::exactKeys($r,$keys)) { throw new SubscriptionSqlFailure('mysql_server_invalid'); }
		foreach($keys as $key) { if(!is_string($r[$key])) { throw new SubscriptionSqlFailure('mysql_server_invalid'); } }
		if(1!==preg_match('/\A8\.4\.[0-9]+(?:-commercial)?\z/',$r['version']) || $database!==$r['database_name'] || 1!==preg_match('/\A[1-9][0-9]{0,19}\z/',$r['connection_id'])
			|| !in_array($r['transaction_isolation'],['REPEATABLE-READ','READ-COMMITTED'],true) || '1'!==$r['autocommit'] || ''===$r['time_zone'] || 'utf8mb4'!==$r['character_set_connection'] || 'utf8mb4_unicode_ci'!==$r['collation_connection']
			|| !preg_match('/(?:\A|,)STRICT_(?:TRANS|ALL)_TABLES(?:,|\z)/',$r['sql_mode']) || str_contains($r['sql_mode'],'NO_BACKSLASH_ESCAPES')) { throw new SubscriptionSqlFailure('mysql_server_invalid'); }
		return $r;
	}
	/** Only the six captured TableMaker/fixture forms are admitted; unknown clauses fail closed. */
	public static function ddlContract(string $sql):array {
		$sql=preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
		if(1!==preg_match('/\ACREATE TABLE (ecps_[a-f0-9]{12}_(?:ys_ec_(?:products|subscriptions|orders|order_items|order_created_outbox)|options))\s*\((.*)\) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\z/s',trim($sql),$m)) { throw new SubscriptionSqlFailure('schema_ddl_unsupported'); }
		$columns=[]; $indexes=[];
		foreach(preg_split('/,\s*(?![^()]*\))/',trim($m[2])) as $declaration) {
			$declaration=preg_replace('/\s+/',' ',trim($declaration));
			if(preg_match('/\A(?:(PRIMARY) KEY|(UNIQUE )?KEY ([a-z_][a-z0-9_]*)) \(([a-z0-9_, ]+)\)\z/',$declaration,$k)) {
				$name=''!==($k[1]??'')?'PRIMARY':$k[3];
				if(isset($indexes[$name])) { throw new SubscriptionSqlFailure('schema_ddl_unsupported'); }
				$indexes[$name]=['unique'=>'PRIMARY'===$name || ''!==($k[2]??''),'columns'=>array_map('trim',explode(',',$k[4]))]; continue;
			}
			if(1!==preg_match('/\A([a-z_][a-z0-9_]*) ((?:BIGINT|INT|TINYINT)(?:\(1\))?(?: UNSIGNED)?|(?:VARCHAR|CHAR)\([0-9]+\)|DECIMAL\([0-9]+,[0-9]+\)|TEXT|LONGTEXT|JSON|DATETIME|DATE)(?: (.*))?\z/',$declaration,$c)) { throw new SubscriptionSqlFailure('schema_ddl_unsupported'); }
			if(1!==preg_match("/\A(?:(NOT NULL|NULL) ?)?(?:DEFAULT ('[^']*'|-?[0-9]+(?:\.[0-9]+)?|NULL|CURRENT_TIMESTAMP) ?)?(AUTO_INCREMENT)?(?: ?(ON UPDATE CURRENT_TIMESTAMP))?(?: ?(PRIMARY KEY))?\z/",$c[3]??'',$a) || isset($columns[$c[1]])) { throw new SubscriptionSqlFailure('schema_ddl_unsupported'); }
			$default=$a[2]??''; $default=in_array($default,['','NULL'],true)?null:('CURRENT_TIMESTAMP'===$default?'CURRENT_TIMESTAMP':trim($default,"'"));
			$columns[$c[1]]=['type'=>strtolower($c[2]),'nullable'=>'NOT NULL'!==($a[1]??''),'default'=>$default,'auto_increment'=>''!==($a[3]??''),'on_update'=>''!==($a[4]??''),'collation'=>preg_match('/\A(?:VARCHAR|CHAR|TEXT|LONGTEXT)/',$c[2])?'utf8mb4_unicode_ci':null];
			if(''!==($a[5]??'')) { $indexes['PRIMARY']=['unique'=>true,'columns'=>[$c[1]]]; }
		}
		foreach($indexes as $index) { foreach($index['columns'] as $column) { if(!isset($columns[$column])) { throw new SubscriptionSqlFailure('schema_ddl_unsupported'); } } }
		return ['table'=>$m[1],'columns'=>$columns,'indexes'=>$indexes];
	}
	private static function showContract(string $sql):array {
		$sql=preg_replace('/`([a-z_][a-z0-9_]*)`/','$1',$sql);
		$sql=preg_replace('/ COLLATE utf8mb4_unicode_ci(?= |,|\n)/','',$sql);
		$sql=preg_replace('/current_timestamp\(\)/i','CURRENT_TIMESTAMP',$sql);
		$sql=preg_replace_callback('/\b(PRIMARY|UNIQUE|KEY|BIGINT|INT|TINYINT|UNSIGNED|VARCHAR|CHAR|DECIMAL|TEXT|LONGTEXT|JSON|DATETIME|DATE|NOT|NULL|DEFAULT|AUTO_INCREMENT|ON|UPDATE|CURRENT_TIMESTAMP)\b/i',static fn(array $m):string=>strtoupper($m[1]),$sql);
		$sql=preg_replace('/\) ENGINE=InnoDB(?: AUTO_INCREMENT=[0-9]+)? DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\z/',') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',$sql);
		return self::ddlContract($sql);
	}
	public static function validateMetadata(array $plan,array $reads):array {
		if(!is_array($plan['captured_ddl']['statements']??null) || !is_array($plan['queries']??null) || !SubscriptionSqlEvidence::exactKeys($reads,array_keys($plan['queries']))) { throw new SubscriptionSqlFailure('mysql_schema_invalid'); }
		foreach($plan['queries'] as $key=>$sql) { $r=$reads[$key]; if(!SubscriptionSqlEvidence::exactKeys($r,['sql','sql_sha256','rows']) || $r['sql']!==$sql || $r['sql_sha256']!==hash('sha256',$sql) || !is_array($r['rows']) || !array_is_list($r['rows'])) { throw new SubscriptionSqlFailure('mysql_schema_invalid'); } }
		$server=self::validateServer($reads['session']['rows'],$plan['database']); $tables=[];
		foreach($plan['captured_ddl']['statements'] as $sql) {
			$contract=self::ddlContract($sql); $table=$contract['table']; $tables[]=$table;
			$columns=$reads[$table.':columns']['rows'];
			if(count($columns)!==count($contract['columns']) || array_column($columns,'Field')!==array_keys($contract['columns'])) { throw new SubscriptionSqlFailure('mysql_columns_invalid'); }
			foreach($columns as $row) {
				$expected=$contract['columns'][$row['Field']];
				if(!SubscriptionSqlEvidence::exactKeys($row,['Field','Type','Collation','Null','Key','Default','Extra','Privileges','Comment']) || !is_string($row['Extra'])) { throw new SubscriptionSqlFailure('mysql_columns_invalid'); }
				$extra=trim(strtolower(str_replace('DEFAULT_GENERATED','',$row['Extra']))); $extra=str_replace('current_timestamp()','current_timestamp',$extra);
				$wantExtra=trim(($expected['auto_increment']?'auto_increment':'').($expected['on_update']?' on update current_timestamp':''));
				$default=$row['Default']; if(is_string($default) && in_array(strtoupper($default),['CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()'],true)) { $default='CURRENT_TIMESTAMP'; }
				if($row['Type']!==$expected['type'] || $row['Collation']!==$expected['collation'] || $row['Null']!==($expected['nullable']?'YES':'NO') || $default!==$expected['default'] || $extra!==$wantExtra || ''!==$row['Comment']) { throw new SubscriptionSqlFailure('mysql_columns_invalid'); }
			}
			$indexes=[];
			foreach($reads[$table.':indexes']['rows'] as $row) {
				if(!is_array($row) || ($row['Table']??null)!==$table || !is_string($row['Key_name']??null) || !in_array($row['Non_unique']??null,['0','1'],true) || !is_string($row['Seq_in_index']??null) || !ctype_digit($row['Seq_in_index']) || (int)$row['Seq_in_index']<1 || !is_string($row['Column_name']??null) || 'A'!==($row['Collation']??null) || !array_key_exists('Sub_part',$row) || null!==$row['Sub_part'] || 'BTREE'!==($row['Index_type']??null) || 'YES'!==($row['Visible']??null) || !array_key_exists('Expression',$row) || null!==$row['Expression']) { throw new SubscriptionSqlFailure('mysql_indexes_invalid'); }
				$key=$row['Key_name']; $seq=(int)$row['Seq_in_index'];
				if(isset($indexes[$key]['columns'][$seq]) || (isset($indexes[$key]) && $indexes[$key]['unique']!==('0'===$row['Non_unique']))) { throw new SubscriptionSqlFailure('mysql_indexes_invalid'); }
				$indexes[$key]['unique']='0'===$row['Non_unique']; $indexes[$key]['columns'][$seq]=$row['Column_name'];
			}
			foreach($indexes as &$index) { ksort($index['columns']); if(array_keys($index['columns'])!==range(1,count($index['columns']))) { throw new SubscriptionSqlFailure('mysql_indexes_invalid'); } $index['columns']=array_values($index['columns']); } unset($index);
			ksort($indexes); ksort($contract['indexes']); if($indexes!==$contract['indexes']) { throw new SubscriptionSqlFailure('mysql_indexes_invalid'); }
			$create=$reads[$table.':create']['rows'];
			if(1!==count($create) || !SubscriptionSqlEvidence::exactKeys($create[0],['Table','Create Table']) || $create[0]['Table']!==$table || !is_string($create[0]['Create Table']) || !str_starts_with($create[0]['Create Table'],'CREATE TABLE `'.$table.'` (') || !preg_match('/\) ENGINE=InnoDB(?: AUTO_INCREMENT=[0-9]+)? DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\z/',$create[0]['Create Table'])) { throw new SubscriptionSqlFailure('mysql_schema_invalid'); }
			$shown=self::showContract($create[0]['Create Table']); ksort($shown['indexes']);
			if($shown!==$contract) { throw new SubscriptionSqlFailure('mysql_schema_invalid'); }
		}
		$engines=$reads['table-engines']['rows']; $expected=[]; sort($tables,SORT_STRING); foreach($tables as $table) { $expected[]=['table_name'=>$table,'engine'=>'InnoDB']; }
		if($engines!==$expected || 6!==count($tables)) { throw new SubscriptionSqlFailure('mysql_engine_invalid'); }
		return $server;
	}
	/** A creates only absent owned tables; B independently reads the resulting native metadata. */
	public static function mysql(SubscriptionSqlSession $db,bool $create):array {
		$execution=$db->executionReceipt();
		if($db->isCapture() || null===$execution) { throw new SubscriptionSqlFailure('mysql_session_required'); }
		$plan=self::metadataPlan($db,$execution['allocation']['database']); $before=[];
		return $db->observe(static function() use($db,$plan,$create,$before):array {
			$read=static function(string $sql) use($db):array { $rows=$db->get_results($sql,'ARRAY_A'); if(''!==$db->last_error) { throw new SubscriptionSqlFailure('metadata_read_failed'); } return ['sql'=>$sql,'sql_sha256'=>hash('sha256',$sql),'rows'=>$rows]; };
			if($create) {
				$before['session']=$read($plan['queries']['session']); self::validateServer($before['session']['rows'],$plan['database']);
				$before['table-engines']=$read($plan['queries']['table-engines']);
				if([]!==$before['table-engines']['rows']) { throw new SubscriptionSqlFailure('schema_collision'); }
				foreach($plan['captured_ddl']['statements'] as $sql) { self::ddlContract($sql); if(0!==$db->query($sql) || ''!==$db->last_error) { throw new SubscriptionSqlFailure('schema_create_failed'); } }
			}
			$reads=[]; foreach($plan['queries'] as $key=>$sql) { $reads[$key]=$read($sql); }
			$server=self::validateMetadata($plan,$reads);
			return ['scope'=>'MYSQLI SCHEMA','created'=>$create,'plan'=>$plan,'before'=>$before,'reads'=>$reads,'server'=>$server];
		},'schema-setup');
	}
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
		if ( ! $db->isCapture() && null===$db->executionReceipt() ) { throw new SubscriptionSqlFailure( 'mysql_session_required' ); }
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
		return [ 'token_digest' => hash( 'sha256', $opaqueToken ), 'issued_row'=>$issuedRow, 'initial_counts'=>$counts, 'scope' => $db->isCapture()?'CAPTURE RESULT CONTROL ONLY':'MYSQLI SEED READBACK', 'sql_execution' => $db->isCapture()?'NOT RUN':'EXECUTED' ];
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
