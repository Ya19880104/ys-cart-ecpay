<?php
/** Capture-only setup/fault boundaries; no server result is asserted here. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlSchema as Schema;
use YSCartEcpay\Tests\Live\SubscriptionSqlFaults as Faults;
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = (string)(getenv('YS_ECPAY_SQL_HARNESS_HELPER_ROOT') ?: dirname( __DIR__ ) . '/live/helpers');
require_once $helpers . '/SubscriptionSqlSession.php';
if ( defined('ECPAY_B1_CAPTURE_LIBRARY') ) { return; }
if ( '--worker-control' === ( $argv[1] ?? null ) ) {
	require_once $helpers . '/SubscriptionSqlWorker.php';
	$packet=json_decode((string)stream_get_contents(STDIN),true); $barrier=\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::attach((string)$argv[2],$packet['phase']);
	$fixture=ecpay_b1_capture_transport($packet,$barrier->path());
	$result=\YSCartEcpay\Tests\Live\SubscriptionSqlWorker::run($packet,$fixture['a'],$fixture['b'],$barrier);
	if('P7'===$packet['case'] && 'A'===$packet['role']) { $packet['role']='B'; $second=\YSCartEcpay\Tests\Live\SubscriptionSqlWorker::run($packet,$fixture['b'],null,$barrier); $result=['A'=>$result,'B'=>$second]; }
	if([]!==$fixture['unknown']->rows) { throw new SubscriptionSqlFailure('capture_unexpected_statement'); }
	echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); exit(0);
}
$pass = 0; $fail = 0;
$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail ): void { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$pass : ++$fail; };
$sql = [];
$db = Session::forCapture( 'ecps_0123456789ab_', static function ( string $s ) use ( &$sql ): array { $sql[] = $s; return [ 'rows' => [], 'error' => '', 'affected' => 0 ]; } );
$db->insert( 'ecps_0123456789ab_ys_ec_subscriptions', [ 'next_amount' => null, 'current_period_key' => null, 'title' => '', 'quantity' => 2, 'amount' => '500.00' ] );
$check( 'setup insert keeps SQL NULL separate from empty strings, integer and decimal bytes',
	$sql === [ "INSERT INTO `ecps_0123456789ab_ys_ec_subscriptions` (`next_amount`, `current_period_key`, `title`, `quantity`, `amount`) VALUES (NULL, NULL, '', 2, '500.00')" ] );
$available = is_file( $helpers . '/SubscriptionSqlSchema.php' ) && is_file( $helpers . '/SubscriptionSqlFaults.php' );
$check( 'schema and finite transport fault interfaces exist', $available );
if ( $available ) {
	require_once $helpers . '/SubscriptionSqlSchema.php';
	require_once $helpers . '/SubscriptionSqlFaults.php';
	$actual = [ 'error' => '', 'rows' => [], 'affected' => 0 ];
	foreach ( [ 'P3','P4','P5','P6','P7','P8','P9','P10' ] as $case ) {
		$fault = Faults::arm( $case, 'A' );
		$check( $case . ' fault is initially armed but not triggered', 0 === $fault->receipt()['trigger_count'] );
	}
	$bad = 0;
	foreach ( [ [ 'P0', 'A' ], [ 'P4', 'foreign' ] ] as [ $case, $role ] ) { try { Faults::arm( $case, $role ); } catch ( SubscriptionSqlFailure $e ) { $bad += 'fault_contract_invalid' === $e->getMessage() ? 1 : 0; } }
	$check( 'unknown fault or role cannot become a no-op success', 2 === $bad );
	$fault = Faults::arm( 'P5', 'A' );
	$fault->after( 'profile-cas', 'owned-profile-cas', [ 'error' => '', 'rows' => [], 'affected' => 1 ] );
	$fault->after( 'consume', 'owned-consume', [ 'error' => '', 'rows' => [], 'affected' => 1 ] );
	$decision = $fault->before( 'commit', 'COMMIT', [ 'connection_id' => '9101' ] );
	$check( 'COMMIT suppression marks attempted versus not sent explicitly', false === $decision['send'] && false === $decision['presented']['affected'] && 'commit-suppressed' === $decision['fault'] );
	$code = ''; try { $fault->before( 'commit', 'COMMIT', [ 'connection_id' => '9101' ] ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'same fault cannot silently fire twice', 'fault_duplicate_trigger' === $code );
	$fault = Faults::arm( 'P4', 'A' );
	$fault->after( 'profile-cas', 'owned-profile-cas', [ 'error' => '', 'rows' => [], 'affected' => 1 ] );
	$fault->after( 'consume', 'owned-consume', [ 'error' => '', 'rows' => [], 'affected' => 1 ] );
	$presented = $fault->after( 'commit', 'COMMIT', $actual );
	$check( 'ack loss retains actual successful transport result separately from presented failure', $actual === $fault->receipt()['actual_commit'] && false === $presented['affected'] && 'fixture_ack_lost' === $presented['error'] );
	$code=''; try { Faults::arm('P3','A')->before('unknown-kind','',[]); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('unknown seam is not silently treated as an unfaulted statement', 'fault_contract_invalid'===$code);
	$check('finite fault state requires completion evidence', method_exists(Faults::class,'finish'));
	if(method_exists(Faults::class,'finish')) {
		$code=''; try { Faults::arm('P3','A')->finish(); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
		$check('untriggered armed fault cannot complete a scenario', 'fault_untriggered'===$code);
	}
}
$check( 'setup and fault unit tests construct no connection', 0 === Session::connectionAttempts() );
$sources = Fixture::inspectSources( [ 'core'=>getenv( 'YS_CORE_ROOT' ),'ecpay'=>getenv( 'YS_ECPAY_ROOT' ),'affiliate'=>getenv( 'YS_AFFILIATE_ROOT' ) ] );
$products = Fixture::loadProduct( $sources ); require_once $helpers . '/SubscriptionSqlRequestBoundary.php';
$check( 'actual sixteen product classes loaded for issuance', 16 === count( $products ) );
$halfSeed=Fixture::seedPlan($db->prefix,1788566400,str_repeat('H',32)); $beforeDispatch=$db->dispatches;
$half=\YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService::renewal_projection((object)$halfSeed['subscription'],null,$db,null);
$check('actual projection rejects half a catalog custody credential before any query', ['ok'=>false,'error'=>'subscription_tracked_stock_not_supported']===$half && $db->dispatches===$beforeDispatch);
$badValues=0;
foreach([[],(object)[],true,1.2] as $badValue) { $beforeDispatch=$db->dispatches; try { $db->insert($db->prefix.'ys_ec_subscriptions',['next_amount'=>$badValue]); } catch(SubscriptionSqlFailure $e) { $badValues+=('insert_contract_invalid'===$e->getMessage() && $db->dispatches===$beforeDispatch)?1:0; } }
$check('unsupported seed types reject before dispatch rather than coercing bytes', 4===$badValues);
$check( 'actual selector randomness dependency is narrowly declared', function_exists( 'wp_generate_password' ) );
$check( 'setup provides complete owned-table snapshots and empty-table admission', method_exists( Schema::class, 'snapshot' ) );
if ( function_exists( 'wp_generate_password' ) ) {
	$token = str_repeat( 'Q', 32 ); $now = time(); $seed = Fixture::seedPlan( $db->prefix, 1788566400, $token ); $seen = [];
	$shape = static function ( string $table, array $row ) use ( $db ): string {
		return 'INSERT INTO ' . $db->prepare( '%i', $table ) . ' (' . implode( ', ', array_map( static fn ( string $key ): string => '`' . $key . '`', array_keys( $row ) ) ) . ') VALUES (' . implode( ', ', array_map( static fn ( mixed $v ): string => null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : $db->prepare( '%s', $v ) ), array_values( $row ) ) ) . ')';
	};
	$allow = [ $shape( $db->prefix . 'ys_ec_products', $seed['product'] ), $shape( $db->prefix . 'ys_ec_subscriptions', $seed['subscription'] ) ];
	$issuedRows = []; $actualIssued = null;
	for ( $i = 0; $i <= 3; ++$i ) { $row = $seed['selection']; $decoded = json_decode( $row['option_value'], true ); $decoded['record']['expires_at'] = $now + $i + 1800; $row['option_value'] = json_encode( $decoded, JSON_UNESCAPED_SLASHES ); $statement = $shape( $db->options, $row ); $allow[] = $statement; $issuedRows[$statement] = $row; }
	$sentinel = $seed['subscription']; $sentinel['id'] = 42;
	$allow[] = $shape( $db->prefix . 'ys_ec_subscriptions', $sentinel ); $allow[] = $shape( $db->options, ['option_name'=>'ecpay_fixture_sentinel','option_value'=>'preserve-sentinel-bytes','autoload'=>'no'] );
	$countQueries = array_map( static fn ( string $t ): string => 'SELECT COUNT(*) FROM `' . $t . '`', Schema::plan($db->prefix)['tables'] );
	$issuedRead = $db->prepare( 'SELECT option_name, option_value, autoload FROM ' . $db->options . ' WHERE option_name = %s', $seed['selection']['option_name'] );
	$show = $db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $db->options );
	$cleanup = $db->prepare( 'SELECT option_name, option_value FROM ' . $db->options . ' WHERE option_name LIKE %s LIMIT %d', $db->esc_like( 'ys_ec_ecpay_subsel_' ) . '%', 20 );
	$readbackTamper=false;
	$issuance = Session::forCapture( $db->prefix, static function ( string $s ) use ( &$seen, $allow, $show, $cleanup, $countQueries, $issuedRead, $issuedRows, &$actualIssued, &$readbackTamper ): array {
		$seen[] = $s;
		if ( in_array( $s, $countQueries, true ) ) { return ['error'=>'','rows'=>[['count'=>'0']],'affected'=>0]; }
		if ( $issuedRead === $s && null !== $actualIssued ) { $row=$actualIssued; if($readbackTamper) { $v=json_decode($row['option_value'],true); $v['record']['store_id']='foreign-fixture'; $row['option_value']=json_encode($v,JSON_UNESCAPED_SLASHES); } return ['error'=>'','rows'=>[$row],'affected'=>0]; }
		if ( $show === $s ) { return [ 'error'=>'','rows'=>[ [ 'Engine'=>'InnoDB' ] ],'affected'=>0 ]; }
		if ( $cleanup === $s ) { return [ 'error'=>'','rows'=>[],'affected'=>0 ]; }
		if ( in_array( $s, $allow, true ) ) { if ( isset($issuedRows[$s]) ) { $actualIssued=$issuedRows[$s]; } return [ 'error'=>'','rows'=>[],'affected'=>1 ]; }
		throw new SubscriptionSqlFailure( 'seed_statement_not_declared' );
	} );
	$receipt = Schema::seed( $issuance, Schema::plan( $db->prefix ), $token, 1788566400 );
	$check( 'real selector cleanup read precedes real Store issue INSERT without DELETE', 14 === count( $seen ) && $show === $seen[8] && $cleanup === $seen[9] && in_array( $seen[10], $allow, true ) && hash( 'sha256', $token ) === $receipt['token_digest'] && 6 === count($receipt['initial_counts']) );
	$check( 'raw token absent from all captured setup statements', ! str_contains( implode( "\n", $seen ), $token ) );
	$readbackTamper=true; $code=''; try { Schema::seed($issuance,Schema::plan($db->prefix),$token,1788566400); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('true issuance readback rejects an undeclared record field change', 'seed_issuance_readback_invalid'===$code);
	$code = ''; try { wp_generate_password( 33, false, false ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'other randomness calls reject without fabricating a token', 'issuance_randomness_invalid' === $code );
}
$extended = method_exists( Session::class, 'instrument' ) && method_exists( Session::class, 'replaceWith' );
$check( 'supplied transport has immutable result and physical replacement instrumentation', $extended );
if ( $extended ) {
	$sent = []; $replacementSent = [];
	$a = Session::forCapture( 'ecps_0123456789ab_', static function ( string $s ) use ( &$sent ): array { $sent[] = $s; return [ 'error'=>'', 'rows'=>[], 'affected'=>1 ]; } );
	$b = Session::forCapture( 'ecps_0123456789ab_', static function ( string $s ) use ( &$replacementSent ): array { $replacementSent[] = $s; return [ 'error'=>'', 'rows'=>[], 'affected'=>0 ]; } );
	$a->instrument( static fn ( string $kind, string $s, array $id ): array => [ 'send'=>false, 'action'=>'none', 'fault'=>'unit-suppressed', 'presented'=>[ 'error'=>'fixture_commit_suppressed','rows'=>[],'affected'=>false ] ], null );
	$check( 'suppression is attempt one, sent zero, actual null', false === $a->query( 'COMMIT' ) && [] === $sent && false === $a->statements()[0]['sent'] && null === $a->statements()[0]['actual'] );
	$a->instrument( null, static function ( string $kind, string $s, array $actual ) use ( $a ): array { $a->observe( static fn (): mixed => $a->get_var( 'SELECT 7' ) ); return [ 'error'=>'fixture_ack_lost','rows'=>[],'affected'=>false ]; } );
	$check( 'observer cannot overwrite actual or presented result', false === $a->query( 'COMMIT' ) && 'fixture_ack_lost' === $a->last_error && 1 === $a->statements()[1]['actual']['affected'] && false === $a->statements()[1]['presented']['affected'] );
	$a->instrument( null, null ); $object = spl_object_id( $a ); $a->replaceWith( $b );
	$check( 'replacement preserves wrapper identity and byte-exact dispatch', 0 === $a->query( 'SELECT 9' ) && $object === spl_object_id( $a ) && [ 'SELECT 9' ] === $replacementSent && 1 === $a->physicalReplacements() && ! $b->ready );
	$a->close(); $code = ''; try { $a->query( 'SELECT 10' ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'closed transport rejects all later statements without invoking recorder', 'session_closed' === $code && [ 'SELECT 9' ] === $replacementSent );
}
require_once $helpers . '/SubscriptionSqlWorker.php';
$check( 'supplied capture workers implement actual product schedules', method_exists( \YSCartEcpay\Tests\Live\SubscriptionSqlWorker::class, 'run' ) );
$check('finite server/session/schema metadata capture interface exists without an execution lane',method_exists(Schema::class,'metadataPlan') && method_exists(Schema::class,'captureMetadata'));
if(method_exists(Schema::class,'metadataPlan') && method_exists(Schema::class,'captureMetadata')) {
	$metadataPlan=Schema::metadataPlan($db,'ecpay_offline_fixture'); $seenMetadata=[];
	$tables=Schema::plan($db->prefix)['tables']; $expectedNames=['session','table-engines'];
	foreach($tables as $table) { foreach(['create','columns','indexes'] as $kind) { $expectedNames[]=$table.':'.$kind; } }
	$check('metadata plan enumerates only twenty finite owned read statements and retains all six captured DDLs',array_keys($metadataPlan['queries'])===$expectedNames && 6===count($metadataPlan['captured_ddl']['statements']) && 'UNSATISFIED'===$metadataPlan['schema_acceptance']);
	$metadataRows=[]; foreach($metadataPlan['queries'] as $key=>$query) { $metadataRows[$query]=[['capture_key'=>$key,'nullable'=>null,'decimal_bytes'=>'65.00']]; }
	$capture=Session::forCapture($db->prefix,static function(string $s) use(&$seenMetadata,$metadataRows):array { $seenMetadata[]=$s; if(!array_key_exists($s,$metadataRows)) { throw new SubscriptionSqlFailure('metadata_statement_not_declared'); } return ['error'=>'','rows'=>$metadataRows[$s],'affected'=>0]; });
	$metadata=Schema::captureMetadata($capture,$metadataPlan);
	$check('raw metadata capture preserves SQL null and bytes with observer origin but cannot grant schema acceptance',20===count($seenMetadata) && $seenMetadata===array_values($metadataPlan['queries']) && 'UNSATISFIED'===$metadata['schema_acceptance'] && 'NOT RUN'===$metadata['sql_execution'] && null===$metadata['reads']['session']['rows'][0]['nullable'] && '65.00'===$metadata['reads']['session']['rows'][0]['decimal_bytes'] && ['observer']===array_values(array_unique(array_column($capture->statements(),'origin'))));
	$badPlan=$metadataPlan; $badPlan['queries']['session']='SELECT 9'; $code=''; $before=$capture->dispatches;
	try { Schema::captureMetadata($capture,$badPlan); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('caller cannot replace a metadata query or use a foreign schema before dispatch','metadata_plan_invalid'===$code && $before===$capture->dispatches);
	$code=''; try { Schema::metadataPlan($capture,'foreign-database-name'); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('metadata database identifier is strict and has no default','metadata_plan_invalid'===$code);
	$failed=Session::forCapture($db->prefix,static fn(string $s):array=>['error'=>'fixture_metadata_unavailable','rows'=>[],'affected'=>false]); $code='';
	try { Schema::captureMetadata($failed,$metadataPlan); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('metadata transport error is a typed failure, never an empty successful observation','metadata_read_failed'===$code && 1===$failed->dispatches && 0===Session::connectionAttempts());
}
echo "subscription SQL seed/fault boundaries: {$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );

/** Result-control fixture only. Exact complete statements, no SQL engine or replacement product methods. */
function ecpay_b1_capture_transport(array $p,string $phase):array {
	$helpers=(string)(getenv('YS_ECPAY_SQL_HARNESS_HELPER_ROOT') ?: dirname(__DIR__).'/live/helpers'); require_once $helpers.'/SubscriptionSqlWorker.php';
	$sources=Fixture::inspectSources(['core'=>getenv('YS_CORE_ROOT'),'ecpay'=>getenv('YS_ECPAY_ROOT'),'affiliate'=>getenv('YS_AFFILIATE_ROOT')]); Fixture::loadProduct($sources); require_once $helpers.'/SubscriptionSqlRequestBoundary.php';
	$prefix=$p['allocation']['prefix']; $token=$p['token']; $seed=Fixture::seedPlan($prefix,1788566400,$token); $case=$p['case'];
	$unknown=(object)['rows'=>[]];
	$build=static function(string $physical) use($prefix,$seed,$case,$phase,$p,$unknown):Session {
		$quote=static fn(string $s):string=>"'".str_replace(['\\',"'"],['\\\\',"\\'"],$s)."'";
		$shape=static fn(string $t,array $r):string=>'INSERT INTO `'.$t.'` (`'.implode('`, `',array_keys($r)).'`) VALUES ('.implode(', ',array_map(static fn(mixed $v):string=>null===$v?'NULL':(is_int($v)?(string)$v:$quote($v)),array_values($r))).')';
		$sentinel=$seed['subscription']; $sentinel['id']=42; $optSentinel=['option_name'=>'ecpay_fixture_sentinel','option_value'=>'preserve-sentinel-bytes','autoload'=>'no'];
		$owner=''; $issued=$seed['selection']; $now=time(); $issuedVariants=[];
		for($i=0;$i<=30;++$i) { $r=$seed['selection']; $v=json_decode($r['option_value'],true); $v['record']['expires_at']=$now+$i+1800; $r['option_value']=json_encode($v,JSON_UNESCAPED_SLASHES); $issuedVariants[$shape($prefix.'options',$r)]=$r; }
		$inserts=[$shape($prefix.'ys_ec_products',$seed['product']),$shape($prefix.'ys_ec_subscriptions',$seed['subscription']),$shape($prefix.'ys_ec_subscriptions',$sentinel),$shape($prefix.'options',$optSentinel)];
		$tables=Schema::plan($prefix)['tables']; $snapshot=[]; $counts=[];
		foreach($tables as $table) { $snapshot['SELECT * FROM `'.$table.'` ORDER BY `'.($table===$prefix.'options'?'option_name':'id').'`']=$table; $counts[]='SELECT COUNT(*) FROM `'.$table.'`'; }
		$profile=json_decode($seed['subscription']['fulfillment_profile'],true);
		$profile['shipping_method_id']='ys_ec_ecpay_ship_unimart'; $profile['shipping_provider']='ecpay'; $profile['shipping_total']='65.00';
		$profile['fulfillment_snapshot']['provider_id']='ecpay'; $profile['fulfillment_snapshot']['method_id']='ys_ec_ecpay_ship_unimart';
		$profile['fulfillment_snapshot']['destination']=['type'=>'cvs','recipient_name'=>'Pair Recipient','recipient_phone'=>'0912345678','country'=>'TW','store_id'=>'991122','store_name'=>'Canonical Store','store_address'=>'No. 1 Store Rd.'];
		$profile['fulfillment_snapshot']['service']['shipping_type']='cvs'; $newBytes=json_encode($profile,JSON_UNESCAPED_SLASHES); $newHash=hash('sha256',$newBytes);
		$newRow=array_replace($seed['subscription'],['fulfillment_profile'=>$newBytes,'fulfillment_profile_hash'=>$newHash,'fulfillment_profile_generation'=>4,'renewal_shipping_total'=>'65.00','fulfillment_profile_updated_at'=>'2026-09-05 00:00:00','updated_at'=>'2026-09-05 00:00:00']);
		$commit=$phase.'/capture-commit.json'; $interferenceFile=$phase.'/capture-interference.json';
		return Session::forCapture($prefix,static function(string $sql) use(&$owner,&$issued,$p,$phase,$physical,$case,$prefix,$seed,$sentinel,$optSentinel,$snapshot,$counts,$inserts,$issuedVariants,$newRow,$newBytes,$newHash,$quote,$commit,$interferenceFile,$unknown):array {
			$rows=[]; $affected=0;
			$setupFile=$phase.'/'.strtolower($case).'-setup-complete-receipt.json';
			if(is_file($setupFile) && ('B'===$p['role'] || 'A'!==$physical)) { $setup=json_decode(file_get_contents($setupFile),true); $issued=$setup['data']['seed']['issued_row']; }
			$issuedBytes=$issued['option_value']; $optionBytes=$issuedBytes;
			if(is_file($interferenceFile)) { $optionBytes=json_decode(file_get_contents($interferenceFile),true)['after_bytes']; }
			$consumed=json_decode($issuedBytes,true); $consumed['state']='consumed'; $consumed['consumed']=['subscription_id'=>41,'generation'=>4,'at'=>'2026-09-05 00:00:00']; $consumedBytes=json_encode($consumed,JSON_UNESCAPED_SLASHES);
			$committed=is_file($commit); $current=$committed?$newRow:$seed['subscription']; if($committed) { $optionBytes=$consumedBytes; }
			$cid='B'===$physical?'9102':('R'===$physical?'9103':'9101');
			$fence="CAST(CAST(CONNECTION_ID() AS CHAR) AS BINARY) = CAST('9101' AS BINARY) AND CAST(DATABASE() AS BINARY) = CAST('ecpay_offline_fixture' AS BINARY) AND CAST(CAST(@ys_profile_tx_owner AS CHAR) AS BINARY) = CAST('".$owner."' AS BINARY)";
			$localFence=str_replace("CAST('9101' AS BINARY)","CAST('".$cid."' AS BINARY)",$fence);
			$subRead='SELECT * FROM '.$prefix.'ys_ec_subscriptions WHERE id = 41';
			$optionRead='SELECT option_value FROM '.$prefix.'options WHERE option_name = '.$quote($issued['option_name']);
			$cas="UPDATE {$prefix}ys_ec_subscriptions\n             SET fulfillment_profile = '{$newBytes}',\n                 fulfillment_profile_generation = fulfillment_profile_generation + 1,\n                 fulfillment_profile_hash = '{$newHash}',\n                 renewal_shipping_total = '65.00',\n                 fulfillment_profile_updated_at = '2026-09-05 00:00:00',\n                 updated_at = '2026-09-05 00:00:00'\n             WHERE id = 41\n               AND fulfillment_profile_generation = 3\n               AND status IN ('pending', 'active', 'on-hold', 'suspended')\n               AND ".$localFence;
			$consume='UPDATE '.$prefix.'options SET option_value = '.$quote($consumedBytes).' WHERE option_name = '.$quote($issued['option_name']).' AND option_value = BINARY '.$quote($issuedBytes).' AND '.$fence;
			$waitSql="SELECT rt.PROCESSLIST_ID AS requesting_id, bt.PROCESSLIST_ID AS blocking_id, rl.OBJECT_SCHEMA AS schema_name, rl.OBJECT_NAME AS table_name, rl.INDEX_NAME AS index_name, rl.LOCK_DATA AS lock_data FROM performance_schema.data_lock_waits w JOIN performance_schema.threads rt ON rt.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks rl ON rl.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND rl.ENGINE = w.ENGINE WHERE rt.PROCESSLIST_ID = 9102 AND bt.PROCESSLIST_ID = 9101 AND rl.OBJECT_SCHEMA = 'ecpay_offline_fixture' AND rl.OBJECT_NAME = '".$prefix."ys_ec_subscriptions' AND rl.INDEX_NAME = 'PRIMARY' AND rl.LOCK_DATA = '41'";
			if(in_array($sql,$counts,true)) { $rows=[['count'=>'0']]; }
			elseif(in_array($sql,$inserts,true)) { $affected=1; }
			elseif(isset($issuedVariants[$sql])) { $issued=$issuedVariants[$sql]; $affected=1; }
			elseif($sql==='SHOW TABLE STATUS WHERE Name = '.$quote($prefix.'options')) { $rows=[['Engine'=>'InnoDB']]; }
			elseif($sql==='SELECT option_name, option_value FROM '.$prefix.'options WHERE option_name LIKE '.$quote('ys\\_ec\\_ecpay\\_subsel\\_%').' LIMIT 20') {}
			elseif($sql==='SELECT option_name, option_value, autoload FROM '.$prefix.'options WHERE option_name = '.$quote($issued['option_name'])) { $rows=[$issued]; }
			elseif(isset($snapshot[$sql])) {
				$rows=match($snapshot[$sql]) {$prefix.'ys_ec_products'=>[$seed['product']],$prefix.'ys_ec_subscriptions'=>[$current,$sentinel],$prefix.'options'=>[$optSentinel,array_replace($issued,['option_value'=>$optionBytes])],default=>[]};
			}
			elseif($sql===$subRead) { $rows=['P2'===$case && 'B'===$p['role'] ? $seed['subscription'] : $current]; }
			elseif($sql==='SELECT * FROM '.$prefix.'ys_ec_subscriptions WHERE id = 42') { $rows=[$sentinel]; }
			elseif($sql==='SELECT * FROM '.$prefix.'ys_ec_products WHERE id = 501') { $rows=[$seed['product']]; }
			elseif($sql===$optionRead) { $rows=[['option_value'=>'P2'===$case && 'B'===$p['role'] ? $issuedBytes:$optionBytes]]; }
			elseif($sql==='SELECT option_value FROM '.$prefix.'options WHERE option_name = '.$quote('ys_ec_ecpay_subsel_'.hash('sha256',hash('md5',$p['token'].'unissued')))) {}
			elseif(preg_match("/\ASET @ys_profile_tx_owner = '([a-f0-9]{32})'\z/",$sql,$m)) { $owner=$m[1]; }
			elseif(Session::SESSION_SQL===$sql) { $rows=[['cid'=>$cid,'dbname'=>'ecpay_offline_fixture','owner'=>''===$owner?null:$owner]]; }
			elseif(''!==$owner && $sql===$subRead.' AND '.$localFence.' FOR UPDATE') {
				if('P2'===$case && 'B'===$p['role']) { $h=fopen($phase.'/capture-b-lock-dispatched.json','x'); fwrite($h,'{"scope":"CAPTURE RESULT CONTROL ONLY"}'); fclose($h); $until=hrtime(true)+10000000000; while(!is_file($commit) && hrtime(true)<$until) { usleep(1000); } if(!is_file($commit)) { throw new SubscriptionSqlFailure('capture_contention_timeout'); } $rows=[$newRow]; }
				else { $rows=[$seed['subscription']]; }
			}
			elseif(''!==$owner && $sql===$cas) { $affected=1; }
			elseif(''!==$owner && $sql===$consume) { $affected='P12b'===$case?0:1; }
			elseif('R'===$physical && 'P8'===$case && 1===preg_match('/\AUPDATE '.preg_quote($prefix,'/').'options SET option_value = /',$sql)) {
				// Reconstruct the one old-session SQL from the actual A seam receipt, never accept its prefix.
				$seam=json_decode(file_get_contents($phase.'/capture-old-owner.json'),true); $expected=str_replace("CAST('' AS BINARY)","CAST('".$seam['owner']."' AS BINARY)",$consume);
				if($sql!==$expected) { $unknown->rows[]=hash('sha256',$sql); throw new SubscriptionSqlFailure('capture_statement_not_declared'); } $affected=0;
			}
			elseif('COMMIT'===$sql) { if('A'===$physical) { $h=fopen($commit,'x'); fwrite($h,'{"scope":"CAPTURE RESULT CONTROL ONLY"}'); fclose($h); } }
			elseif('P2'===$case && $waitSql===$sql) { if(is_file($phase.'/capture-b-lock-dispatched.json') && !is_file($commit)) { $rows=[['requesting_id'=>'9102','blocking_id'=>'9101','schema_name'=>'ecpay_offline_fixture','table_name'=>$prefix.'ys_ec_subscriptions','index_name'=>'PRIMARY','lock_data'=>'41']]; } }
			elseif(in_array($sql,['START TRANSACTION','ROLLBACK'],true)) {}
			else {
				$v=json_decode($issuedBytes,true); if('P11d'===$case) { $v['record']['expires_at']=1; } else { $v['record']['principal']='u:8'; }
				$changed='P12b'===$case?" \n".$issuedBytes:json_encode($v,JSON_UNESCAPED_SLASHES);
				$exact='UPDATE '.$prefix.'options SET option_value = '.$quote($changed).' WHERE option_name = '.$quote($issued['option_name']).' AND option_value = BINARY '.$quote($issuedBytes);
				if(in_array($case,['P11d','P12a','P12b'],true) && $sql===$exact) { $h=fopen($interferenceFile,'x'); fwrite($h,json_encode(['after_bytes'=>$changed])); fclose($h); $affected=1; }
				else { $unknown->rows[]=hash('sha256',$sql); throw new SubscriptionSqlFailure('capture_statement_not_declared'); }
			}
			if('A'===$physical && ''!==$owner && !is_file($phase.'/capture-old-owner.json')) { $h=fopen($phase.'/capture-old-owner.json','x'); fwrite($h,json_encode(['owner'=>$owner])); fclose($h); }
			return ['error'=>'','rows'=>$rows,'affected'=>$affected];
		});
	};
	return ['a'=>$build($p['role']),'b'=>'A'===$p['role'] && in_array($case,['P7','P8','P9','P10'],true)?$build('P7'===$case?'B':'R'):null,'unknown'=>$unknown];
}

/** Two real child processes, supplied capture transports only; P7 uses one child/two wrappers. */
function ecpay_b1_run_control(string $case,string $root,array $sources):array {
	$phase=strtolower($case).'-control'; $barrier=\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::createPhase($root,$phase);
	$prefix='ecps_'.substr(hash('sha256',$case),0,12).'_'; $heads=array_map(static fn(array $r):string=>$r['head'],$sources); $token=bin2hex(random_bytes(16));
	$allocation=['kind'=>'offline-design','host'=>'127.0.0.1','port'=>9,'database'=>'ecpay_offline_fixture','user'=>'offline_fixture','prefix'=>$prefix,'expires_at'=>time()+300];
	$children=[]; $workers=[]; $refs=[]; $errors=[];
	try {
		foreach('P7'===$case?['A']:['A','B'] as $role) {
			$packet=['version'=>1,'phase'=>$phase,'case'=>$case,'role'=>$role,'allocation'=>$allocation,'source_heads'=>$heads,'token'=>$token];
			$base=$barrier->path().'/'.strtolower($role).'-child';
			$command=[PHP_BINARY,'-n','-d','error_reporting=-1','-d','display_errors=stderr','-d','log_errors=0',__FILE__,'--worker-control',$root];
			$process=proc_open($command,[0=>['pipe','r'],1=>['file',$base.'.stdout','x'],2=>['file',$base.'.stderr','x']],$pipes);
			if(!is_resource($process)) { throw new SubscriptionSqlFailure('capture_child_start_failed'); }
			$private=json_encode($packet,JSON_THROW_ON_ERROR); if(strlen($private)!==fwrite($pipes[0],$private)) { throw new SubscriptionSqlFailure('capture_child_input_failed'); } fclose($pipes[0]); unset($packet,$private);
			$children[$role]=['process'=>$process,'base'=>$base,'command'=>$command];
		}
		$deadline=hrtime(true)+30000000000; $released=false;
		do {
			if(!$released && in_array($case,['P2','P12a','P12b'],true)) {
				$stage='P2'===$case?'wait-observed':'b-seam';
				if(is_file($barrier->path().'/'.strtolower($case).'-'.$stage.'.json')) {
					$proof=$barrier->awaitBound($case,$stage,1);
					$ref=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),strtolower($case).'-release-receipt.json',['version'=>1,'phase'=>$phase,'case'=>$case,'role'=>'controller','sequence'=>1,'scope'=>'IPC CAPTURE CONTROL ONLY','prior_sha256'=>hash('sha256',json_encode($proof))]);
					$barrier->publish($case,'release','controller','1',$ref); $released=true;
				}
			}
			$running=false;
			foreach($children as $role=>&$child) {
				if(isset($child['rc'])) { continue; } $status=proc_get_status($child['process']);
				if($status['running']) { $running=true; continue; }
				$closed=proc_close($child['process']); $child['rc']=$status['exitcode']>=0?$status['exitcode']:$closed;
			} unset($child);
			if(!$running) { break; } usleep(1000);
		} while(hrtime(true)<$deadline);
		foreach($children as $role=>$child) {
			if(!isset($child['rc'])) { throw new SubscriptionSqlFailure('capture_child_timeout'); }
			$out=file_get_contents($child['base'].'.stdout'); $err=file_get_contents($child['base'].'.stderr');
			if(0!==$child['rc'] || ''!==$err) { $errors[$role]=['rc'=>$child['rc'],'stderr_bytes'=>strlen($err),'stdout_sha256'=>hash('sha256',$out)]; continue; }
			$value=json_decode($out,true);
			if(!is_array($value)) { $errors[$role]=['code'=>'capture_output_invalid']; continue; }
			$rows='P7'===$case?$value:[$role=>$value];
			foreach($rows as $r=>$w) {
				$w['diagnostics']['stderr']=['path'=>realpath($child['base'].'.stderr'),'bytes'=>strlen($err),'sha256'=>hash('sha256',$err)];
				$workers[$r]=$w; $refs[$r]=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),strtolower($r).'-worker.json',$w);
			}
		}
		foreach(glob($barrier->path().'/*') as $path) { if(is_file($path) && str_contains(file_get_contents($path),$token)) { $errors['private']='capture_private_sentinel_leaked'; } }
		$result=['errors'=>$errors,'workers'=>$workers,'artifacts'=>['root'=>$barrier->path(),'workers'=>$refs],'base'=>['version'=>1,'phase'=>$phase,'case'=>$case,'prefix'=>$prefix,'source_heads'=>$heads,'runtime_sha256'=>hash_file('sha256',PHP_BINARY),'token_digest'=>hash('sha256',$token),'tables'=>$workers['A']['baseline_receipt']??[]],'children'=>array_map(static fn(array $r):array=>['rc'=>$r['rc']??null,'base'=>$r['base'],'command'=>$r['command']],$children)];
		\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),'control-summary.json',$result);
		return $result;
	} finally { foreach($children as $child) { if(is_resource($child['process'])) { proc_terminate($child['process']); proc_close($child['process']); } } unset($token); }
}
