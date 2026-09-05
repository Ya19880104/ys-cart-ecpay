<?php
/** Typed artifact custody. Offline matching never promotes a fixture into SQL acceptance. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
require_once __DIR__ . '/SubscriptionSqlScenario.php';
final class SubscriptionSqlEvidence {
	public static function cases(): array {
		$names = [ 'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10','P11a','P11b','P11c','P11d','P11e','P11f','P11g','P11h','P12a','P12b' ];
		$rows = [];
		foreach ( $names as $case ) { $rows[$case] = [ 'case' => $case, 'topology' => 'P7' === $case ? 'one-worker-two-handles' : 'two-workers', 'sql_execution' => 'NOT RUN' ]; }
		return $rows;
	}
	public static function exactKeys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) ) { return false; }
		$actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $keys, SORT_STRING ); return $actual === $keys;
	}
	public static function persist( string $root, string $name, array $value ): array {
		if ( false === realpath( $root ) || ! is_dir( $root ) || 1 !== preg_match( '/\A[a-z][a-z0-9.-]{0,90}\.json\z/', $name ) ) { throw new SubscriptionSqlFailure( 'evidence_invalid' ); }
		$path = realpath( $root ) . '/' . $name;
		if ( file_exists( $path ) ) { throw new SubscriptionSqlFailure( 'evidence_exists' ); }
		$bytes = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ) . "\n";
		$handle = fopen( $path, 'x' );
		if ( false === $handle ) { throw new SubscriptionSqlFailure( 'evidence_create_failed' ); }
		try { if ( strlen( $bytes ) !== fwrite( $handle, $bytes ) ) { throw new SubscriptionSqlFailure( 'evidence_write_failed' ); } }
		finally { fclose( $handle ); }
		return [ 'path' => realpath( $path ), 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ];
	}
	public static function read( string $root, array $ref ): array {
		if ( ! self::exactKeys( $ref, [ 'path','bytes','sha256' ] ) || ! is_string( $ref['path'] ) || ! is_int( $ref['bytes'] ) || $ref['bytes'] < 0
			|| ! is_string( $ref['sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $ref['sha256'] ) || false === realpath( $root ) || false === realpath( $ref['path'] )
			|| realpath( dirname( $ref['path'] ) ) !== realpath( $root ) || ! is_file( $ref['path'] ) ) { throw new SubscriptionSqlFailure( 'evidence_invalid' ); }
		$bytes = file_get_contents( $ref['path'] );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $ref['bytes'] || ! hash_equals( $ref['sha256'], hash( 'sha256', $bytes ) ) ) { throw new SubscriptionSqlFailure( 'evidence_invalid' ); }
		$value = json_decode( $bytes, true );
		if ( ! is_array( $value ) ) { throw new SubscriptionSqlFailure( 'evidence_invalid' ); }
		return $value;
	}
	public static function evaluate( string $case, array $baseline, array $workers, array $artifacts ): array {
		if ( ! isset( self::cases()[$case] ) ) { throw new SubscriptionSqlFailure( 'scenario_unknown' ); }
		$errors = [];
		try { self::collect( $case, $baseline, $workers, $artifacts ); }
		catch ( SubscriptionSqlFailure $error ) { $errors[] = $error->getMessage(); }
		return [ 'matches' => [] === $errors, 'errors' => $errors, 'sql_execution' => 'NOT RUN', 'scope' => 'OFFLINE ORACLE ONLY' ];
	}
	public static function raw( string $root, mixed $ref ): string {
		if ( ! self::exactKeys( $ref, ['path','bytes','sha256'] ) || ! is_string($ref['path']) || ! is_int($ref['bytes']) || $ref['bytes'] < 0 || ! is_string($ref['sha256'])
			|| 1 !== preg_match('/\A[a-f0-9]{64}\z/',$ref['sha256']) || false === realpath($root) || false === realpath($ref['path']) || realpath(dirname($ref['path'])) !== realpath($root) || ! is_file($ref['path']) ) { throw new SubscriptionSqlFailure('evidence_invalid'); }
		$bytes = file_get_contents($ref['path']);
		if ( ! is_string($bytes) || strlen($bytes) !== $ref['bytes'] || hash('sha256',$bytes) !== $ref['sha256'] ) { throw new SubscriptionSqlFailure('evidence_invalid'); }
		return $bytes;
	}
	private static function need( bool $ok, string $code ): void { if ( ! $ok ) { throw new SubscriptionSqlFailure( $code ); } }
	public static function events( string $case, string $role ): array {
		if ( ! isset(self::cases()[$case]) || ! in_array($role,['A','B'],true) ) { throw new SubscriptionSqlFailure('scenario_unknown'); }
		if ( 'B' === $role ) { return []; }
		$prefix = '[YS CART][subscription] ';
		return match($case) {
			'P3'=>[$prefix . 'profile_update_session_drift subscription_id=41'=>1],
			'P4','P5'=>[$prefix . 'profile_update_commit_indeterminate subscription_id=41'=>1],
			'P6'=>['[YS-EC] [error] [subscription] subscription_db_boundary_poisoned reason=profile_update_rollback_unacknowledged subscription_id=41 order_id=0'=>1],
			'P8'=>[$prefix . 'profile_update_rollback_resolved_by_disconnect subscription_id=41'=>1],
			'P9'=>[$prefix . 'profile_update_rollback_resolved_by_disconnect subscription_id=41'=>1,$prefix . 'profile_update_session_drift subscription_id=41'=>1],
			'P10'=>[$prefix . 'profile_update_commit_indeterminate reason=session_drift subscription_id=41'=>1],
			default=>[],
		};
	}
	public static function logMatches( string $bytes, array $expected ): bool {
		$observed = [];
		if ( '' !== $bytes ) {
			$lines=explode("\n",$bytes); if ( '' !== array_pop($lines) ) { return false; }
			foreach($lines as $line) {
				if ( 1 !== preg_match('/\A\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z0-9_+\/:\-]+\] ([^\r\n]*)\r?\z/',$line,$m) || ! array_key_exists($m[1],$expected) ) { return false; }
				$observed[$m[1]]=($observed[$m[1]] ?? 0)+1;
			}
		}
		ksort($observed); ksort($expected); return $observed === $expected;
	}
	private static function collect( string $case, array $base, array $workers, array $artifacts ): void {
		self::need( self::exactKeys($base,['version','phase','case','prefix','source_heads','runtime_sha256','token_digest','tables']) && 1 === $base['version'] && $case === $base['case'] && is_string($base['phase']) && is_string($base['prefix']) && is_string($base['token_digest']) && 1 === preg_match('/\A[a-f0-9]{64}\z/',$base['token_digest']) && is_array($base['tables']), 'baseline_invalid' );
		SubscriptionSqlSession::assertPrefix($base['prefix']);
		self::need( self::exactKeys($workers,['A','B']) && self::exactKeys($artifacts,['root','workers']) && is_string($artifacts['root']) && basename($artifacts['root']) === $base['phase'] && self::exactKeys($artifacts['workers'],['A','B']), 'artifact_manifest_invalid' );
		$expectedKeys=['version','phase','case','role','topology','source_receipt','runtime_receipt','session_receipts','statement_receipts','barrier_receipts','baseline_receipt','readback_receipt','product_response','projection_receipt','fault_receipt','diagnostics','rc'];
		$before = self::pair($base['tables'],$base['prefix'],$base['token_digest']);
		self::need(3 === $before['generation'] && 'issued' === $before['selection_state'], 'baseline_authority_invalid');
		foreach(['A','B'] as $role) {
			$w=$workers[$role];
			self::need( is_array($w) && self::read($artifacts['root'],$artifacts['workers'][$role]) === $w && self::exactKeys($w,$expectedKeys) && 1 === $w['version'] && $w['phase'] === $base['phase'] && $w['case'] === $case && $w['role'] === $role && self::cases()[$case]['topology'] === $w['topology'] && 0 === $w['rc'], 'worker_receipt_invalid' );
			self::need( is_array($w['source_receipt']) && self::exactKeys($w['source_receipt'],['sources','products','helpers']) && self::exactKeys($w['runtime_receipt'],['version','sha256','scope']) && hash_file('sha256',PHP_BINARY) === $base['runtime_sha256'] && $w['runtime_receipt']['sha256'] === $base['runtime_sha256'] && PHP_VERSION === $w['runtime_receipt']['version'] && 'CAPTURE ONLY' === $w['runtime_receipt']['scope'], 'source_runtime_invalid' );
			self::need( self::exactKeys($base['source_heads'],['core','ecpay','affiliate']) && self::exactKeys($w['source_receipt']['sources'],['core','ecpay','affiliate']), 'source_runtime_invalid' );
			$roots=[];
			foreach($w['source_receipt']['sources'] as $r=>$source) { self::need(is_array($source) && is_string($source['root'] ?? null) && ($source['head'] ?? null) === $base['source_heads'][$r],'source_runtime_invalid'); $roots[$r]=$source['root']; }
			self::need($w['baseline_receipt'] === $base['tables'] && is_array($w['statement_receipts']) && array_is_list($w['statement_receipts']) && is_array($w['session_receipts']) && is_array($w['fault_receipt']) && self::exactKeys($w['diagnostics'],['log','stderr']), 'worker_receipt_invalid');
			$session=$w['session_receipts'];
			self::need(self::exactKeys($session,['identity','replacements','closes','ready']) && self::exactKeys($session['identity'],['connection_id','database','owner_nonce']) && is_int($session['replacements']) && $session['replacements']>=0 && is_bool($session['ready']) && is_array($session['closes']) && array_is_list($session['closes']), 'session_receipt_invalid');
			$id=$session['identity'];
			self::need(is_string($id['connection_id']) && 1===preg_match('/\A[1-9][0-9]{0,19}\z/',$id['connection_id']) && is_string($id['database']) && 1===preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$id['database']) && (null===$id['owner_nonce'] || (is_string($id['owner_nonce']) && 1===preg_match('/\A[a-f0-9]{32}\z/',$id['owner_nonce']))), 'session_receipt_invalid');
			self::need('' === self::raw($artifacts['root'],$w['diagnostics']['stderr']) && self::logMatches(self::raw($artifacts['root'],$w['diagnostics']['log']),self::events($case,$role)), 'unexpected_diagnostics');
			self::counts($case,$role,$w['statement_receipts']);
			self::statementAuthority($w['statement_receipts'],$base['prefix'],$before);
			self::barriers($case,$role,$w['barrier_receipts'],$artifacts['root']);
			self::faultProof($case,$role,$w);
		}
		$afterTables=$workers['B']['readback_receipt']; self::need(is_array($afterTables),'readback_missing');
		$after=self::pair($afterTables,$base['prefix'],$base['token_digest']);
		$expectedTables=$base['tables'];
		if(in_array($case,['P1','P2','P4'],true)) {
			self::need(SubscriptionSqlScenario::newPair($before,$after),'new_pair_invalid');
			$target=$base['prefix'].'ys_ec_subscriptions';
			foreach(['fulfillment_profile','fulfillment_profile_generation','fulfillment_profile_hash','renewal_shipping_total','fulfillment_profile_updated_at','updated_at'] as $key) { $expectedTables[$target][0][$key]=$afterTables[$target][0][$key]; }
			$expectedTables[$base['prefix'].'options'][1]['option_value']=$after['selection_bytes'];
		} elseif(in_array($case,['P12a','P12b'],true)) {
			$i=$workers['B']['fault_receipt']['interference'] ?? null;
			self::need(self::exactKeys($i,['case','option_name','before_bytes','after_bytes','sql_sha256']) && $i['case'] === $case && $i['option_name'] === 'ys_ec_ecpay_subsel_'.$base['token_digest'] && $i['before_bytes'] === $before['selection_bytes'] && is_string($i['after_bytes']), 'interference_invalid');
			$expected=json_decode($before['selection_bytes']);
			if('P12a' === $case) { $expected->record->principal='u:8'; self::need($i['after_bytes']===json_encode($expected,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'interference_delta_invalid'); }
			else { self::need($i['after_bytes'] === " \n".$before['selection_bytes'],'interference_delta_invalid'); }
			$expectedTables[$base['prefix'].'options'][1]['option_value']=$i['after_bytes'];
		}
		self::need($expectedTables === $afterTables,'full_rows_changed');
		self::response($case,$workers['A']['product_response'],$after);
		if('P2' === $case) {
			self::need($workers['B']['product_response'] === ['success'=>false,'code'=>'subscription_authority_changed','message'=>'訂閱物流資料目前無法更新。','status'=>409],'competitor_response_invalid');
			$barrier=SubscriptionSqlBarrier::attach(dirname($artifacts['root']),$base['phase']);
			$wait=$barrier->awaitBound('P2','wait-observed',1); $locked=$barrier->awaitBound('P2','b-seam',1);
			$proof=$wait['receipt']['data'];
			self::need(self::exactKeys($proof,['kind','sql_sha256','rows','scope']) && 'data_lock_waits_join' === $proof['kind'] && 'CAPTURE RESULT CONTROL ONLY' === $proof['scope'] && is_array($proof['rows']) && 1 === count($proof['rows']), 'contention_proof_unavailable');
			$r=$proof['rows'][0]; $aid=$workers['A']['session_receipts']['identity']['connection_id']; $bid=$workers['B']['session_receipts']['identity']['connection_id'];
			self::need($wait['marker']['connection_id']===$aid && $locked['marker']['connection_id']===$bid,'contention_proof_unavailable');
			self::need($aid !== $bid && ($r['requesting_id'] ?? null) === $bid && ($r['blocking_id'] ?? null) === $aid && ($r['schema_name'] ?? null) === $workers['A']['session_receipts']['identity']['database'] && ($r['table_name'] ?? null) === $base['prefix'].'ys_ec_subscriptions' && ($r['index_name'] ?? null) === 'PRIMARY' && ($r['lock_data'] ?? null) === '41', 'contention_proof_unavailable');
			$lock=array_values(array_filter($workers['B']['statement_receipts'],static fn(array $s):bool=>'product'===$s['origin'] && 'locked-read'===$s['kind']));
			$observed=array_values(array_filter($workers['A']['statement_receipts'],static fn(array $s):bool=>'observer'===$s['origin'] && $s['sql_sha256']===$proof['sql_sha256'] && $s['actual']['rows']===$proof['rows']));
			self::need(1===count($lock) && $lock[0]['sql_sha256']===($locked['receipt']['data']['lock_sql_sha256'] ?? null) && 1===count($observed) && $observed[0]['actual']['rows']===$proof['rows'], 'contention_proof_unavailable');
		} elseif(null !== $workers['B']['product_response']) { throw new SubscriptionSqlFailure('observer_response_invalid'); }
		if('P1' === $case) { self::projection($workers['B']['projection_receipt'],$after); }
		elseif(null !== $workers['B']['projection_receipt']) { throw new SubscriptionSqlFailure('projection_unexpected'); }
		// Do expensive local Git/blob/Reflection proof only after all cheap rejection oracles.
		// It is still refreshed for every successful evaluation; no stale custody cache exists.
		self::need($workers['A']['source_receipt'] === $workers['B']['source_receipt'],'source_runtime_invalid');
		$actualSources=SubscriptionProductSqlFixture::inspectSources($roots);
		self::need($actualSources === $workers['A']['source_receipt']['sources'] && SubscriptionProductSqlFixture::loadProduct($actualSources) === $workers['A']['source_receipt']['products'] && SubscriptionProductSqlFixture::helperReceipt(__DIR__) === $workers['A']['source_receipt']['helpers'], 'source_runtime_invalid');
	}
	private static function barriers(string $case,string $role,mixed $refs,string $root):void {
		self::need(is_array($refs) && array_is_list($refs),'barrier_manifest_invalid');
		$expected='A'===$role?['setup-complete','a-complete']:['b-complete'];
		if(in_array($case,['P2','P12a','P12b'],true)) { $expected[]='A'===$role?'a-seam':'b-seam'; }
		if('P2'===$case && 'A'===$role) { $expected[]='wait-observed'; }
		$stages=[]; $barrier=SubscriptionSqlBarrier::attach(dirname($root),basename($root));
		foreach($refs as $ref) {
			self::need(is_array($ref),'barrier_manifest_invalid'); $m=self::read($root,$ref);
			self::need(is_string($m['stage']??null) && ($m['role']??null)===$role && ($m['case']??null)===$case && basename($ref['path'])===strtolower($case).'-'.$m['stage'].'.json','barrier_manifest_invalid');
			self::need($barrier->awaitBound($case,$m['stage'],1)['marker']===$m,'barrier_manifest_invalid'); $stages[]=$m['stage'];
		}
		sort($stages); sort($expected); self::need($stages===$expected,'barrier_manifest_invalid');
		if(in_array($case,['P2','P12a','P12b'],true)) { $barrier->awaitBound($case,'release',1); }
	}
	private static function statementAuthority(array $rows,string $prefix,array $before):void {
		$q=static fn(string $v):string=>"'".str_replace(['\\',"'"],['\\\\',"\\'"],$v)."'";
		$p=json_decode($before['profile_bytes'],true);
		$p['shipping_method_id']='ys_ec_ecpay_ship_unimart'; $p['shipping_provider']='ecpay'; $p['shipping_total']='65.00';
		$p['fulfillment_snapshot']['provider_id']='ecpay'; $p['fulfillment_snapshot']['method_id']='ys_ec_ecpay_ship_unimart';
		$p['fulfillment_snapshot']['destination']=['type'=>'cvs','recipient_name'=>'Pair Recipient','recipient_phone'=>'0912345678','country'=>'TW','store_id'=>'991122','store_name'=>'Canonical Store','store_address'=>'No. 1 Store Rd.']; $p['fulfillment_snapshot']['service']['shipping_type']='cvs';
		$new=json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); $hash=hash('sha256',$new);
		$selection=json_decode($before['selection_bytes'],true); $selection['state']='consumed'; $selection['consumed']=['subscription_id'=>41,'generation'=>4,'at'=>'2026-09-05 00:00:00'];
		$consumed=json_encode($selection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		foreach($rows as $s) {
			if('product'!==$s['origin'] || !in_array($s['kind'],['profile-cas','consume'],true)) { continue; }
			$id=$s['before_identity'];
			self::need(self::exactKeys($id,['connection_id','database','owner_nonce']) && is_string($id['connection_id']) && is_string($id['database']) && is_string($id['owner_nonce']) && 1===preg_match('/\A[a-f0-9]{32}\z/',$id['owner_nonce']),'statement_authority_invalid');
			$fence='CAST(CAST(CONNECTION_ID() AS CHAR) AS BINARY) = CAST('.$q($id['connection_id']).' AS BINARY) AND CAST(DATABASE() AS BINARY) = CAST('.$q($id['database']).' AS BINARY) AND CAST(CAST(@ys_profile_tx_owner AS CHAR) AS BINARY) = CAST('.$q($id['owner_nonce']).' AS BINARY)';
			$expected='consume'===$s['kind'] ? 'UPDATE '.$prefix.'options SET option_value = '.$q($consumed).' WHERE option_name = '.$q('ys_ec_ecpay_subsel_'.$before['token_digest']).' AND option_value = BINARY '.$q($before['selection_bytes']).' AND '.$fence
				: "UPDATE {$prefix}ys_ec_subscriptions\n             SET fulfillment_profile = ".$q($new).",\n                 fulfillment_profile_generation = fulfillment_profile_generation + 1,\n                 fulfillment_profile_hash = ".$q($hash).",\n                 renewal_shipping_total = '65.00',\n                 fulfillment_profile_updated_at = '2026-09-05 00:00:00',\n                 updated_at = '2026-09-05 00:00:00'\n             WHERE id = 41\n               AND fulfillment_profile_generation = 3\n               AND status IN ('pending', 'active', 'on-hold', 'suspended')\n               AND ".$fence;
			self::need($expected===$s['sql'],'statement_authority_invalid');
		}
	}
	private static function pair(array $tables,string $prefix,string $digest): array {
		$names=array_map(static fn(string $s):string=>$prefix.$s,['ys_ec_products','ys_ec_subscriptions','ys_ec_orders','ys_ec_order_items','ys_ec_order_created_outbox','options']);
		self::need(self::exactKeys($tables,$names),'snapshot_shape_invalid');
		foreach($names as $i=>$name) { self::need(is_array($tables[$name]) && array_is_list($tables[$name]) && count($tables[$name]) === [1,2,0,0,0,2][$i],'snapshot_shape_invalid'); }
		$r=$tables[$prefix.'ys_ec_subscriptions'][0]; $option=$tables[$prefix.'options'][1];
		self::need(is_array($r) && (41===($r['id']??null)||'41'===($r['id']??null)) && is_string($r['fulfillment_profile']??null) && hash('sha256',$r['fulfillment_profile'])===($r['fulfillment_profile_hash']??null) && is_array($option) && ($option['option_name']??null)==='ys_ec_ecpay_subsel_'.$digest && is_string($option['option_value']??null) && ($option['autoload']??null)==='no', 'snapshot_authority_invalid');
		$v=json_decode($option['option_value'],true);
		self::need(is_array($v) && in_array($v['state']??null,['issued','consumed'],true) && is_array($v['record']??null) && 41===($v['record']['subscription_id']??null) && in_array($r['fulfillment_profile_generation']??null,[3,4,'3','4'],true),'snapshot_authority_invalid');
		return ['ok'=>true,'subscription_id'=>41,'contract_kind'=>$r['fulfillment_contract_kind'],'generation'=>(int)$r['fulfillment_profile_generation'],'profile_bytes'=>$r['fulfillment_profile'],'profile_hash'=>$r['fulfillment_profile_hash'],'shipping_total'=>$r['renewal_shipping_total'],'profile_updated_at'=>$r['fulfillment_profile_updated_at'],'updated_at'=>$r['updated_at'],'selection_bytes'=>$option['option_value'],'selection_sha256'=>hash('sha256',$option['option_value']),'selection_state'=>$v['state'],'selection_generation'=>$v['consumed']['generation']??null,'token_digest'=>$digest];
	}
	private static function counts(string $case,string $role,array $statements):void {
		$count=['profile-cas'=>[0,0,0],'consume'=>[0,0,0],'commit'=>[0,0,0],'rollback'=>[0,0,0]];
		foreach($statements as $i=>$s) {
			self::need(self::exactKeys($s,['sequence','origin','kind','sql','sql_sha256','before_identity','after_identity','sent','actual','presented','fault']) && $s['sequence']===$i+1 && is_string($s['sql']) && hash('sha256',$s['sql'])===$s['sql_sha256'] && is_bool($s['sent']) && in_array($s['origin'],['product','schema-setup','observer','fault-control'],true) && is_array($s['presented']), 'statement_receipt_invalid');
			self::need($s['sent'] ? self::exactKeys($s['actual'],['error','rows','affected']) : null===$s['actual'],'statement_receipt_invalid');
			if('product'!==$s['origin']) { continue; }
			if(isset($count[$s['kind']])) { ++$count[$s['kind']][0]; $count[$s['kind']][1]+=$s['sent']?1:0; $count[$s['kind']][2]+=1===($s['actual']['affected']??null)?1:0; }
			elseif(1===preg_match('/\A(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/',$s['sql'])) { throw new SubscriptionSqlFailure('unclassified_product_write'); }
		}
		$expected=match($case) {
			'P1','P2'=>[[1,1,1],[1,1,1],[1,1,0],[0,0,0]],
			'P3','P9'=>[[1,1,1],[1,1,1],[0,0,0],[1,1,0]],
			'P4'=>[[1,1,1],[1,1,1],[1,1,0],[1,1,0]],
			'P5'=>[[1,1,1],[1,1,1],[1,0,0],[1,1,0]],
			'P6'=>[[1,1,1],[1,1,1],[0,0,0],[1,0,0]],
			'P7','P12a'=>[[1,1,1],[0,0,0],[0,0,0],[1,1,0]],
			'P8'=>[[1,1,1],[1,1,0],[0,0,0],[1,1,0]],
			'P10'=>[[1,1,1],[1,1,1],[1,1,0],[0,0,0]],
			'P12b'=>[[0,0,0],[1,1,0],[0,0,0],[1,1,0]],
			default=>[[0,0,0],[0,0,0],[0,0,0],[0,0,0]],
		};
		if('B'===$role) { $expected=[[0,0,0],[0,0,0],[0,0,0],'P2'===$case?[1,1,0]:[0,0,0]]; }
		self::need(array_values($count)===$expected,'statement_counts_invalid');
	}
	private static function faultProof(string $case,string $role,array $w):void {
		self::need(self::exactKeys($w['fault_receipt'],['case','role','trigger_count','triggered','actual_commit','interference']) && $w['fault_receipt']['case']===$case && $w['fault_receipt']['role']===$role,'fault_proof_invalid');
		$expected='B'===$role ? [] : match($case) { 'P2'=>['pause-before-commit'],'P3'=>['precommit-unreadable'],'P4'=>['ack-lost'],'P5'=>['commit-suppressed'],'P6'=>['precommit-unreadable','rollback-suppressed'],'P7'=>['swap-global'],'P8'=>['replace-before-consume'],'P9'=>['replace-before-verify'],'P10'=>['replace-before-commit'],'P12a'=>['pause-before-claim'],default=>[] };
		self::need(($w['fault_receipt']['triggered']??null)===$expected && ($w['fault_receipt']['trigger_count']??null)===count($expected),'fault_proof_invalid');
		if('A'!==$role) { return; }
		if('P4'===$case) { $commits=array_values(array_filter($w['statement_receipts'],static fn(array $s):bool=>'product'===$s['origin'] && 'commit'===$s['kind'])); self::need(1===count($commits) && $commits[0]['actual']===($w['fault_receipt']['actual_commit']??null) && false===($commits[0]['presented']['affected']??null),'commit_actual_proof_invalid'); }
		if('P6'===$case) { $c=$w['session_receipts']['closes']??null; self::need(is_array($c) && 1===count($c) && true===($c[0]['poisoned']??null) && false===($w['session_receipts']['ready']??null) && count($w['statement_receipts'])===($c[0]['sequence']??null),'poison_proof_invalid'); }
		if(in_array($case,['P8','P9','P10'],true)) {
			self::need(1===($w['session_receipts']['replacements']??null) && array_key_exists('owner_nonce',$w['session_receipts']['identity']) && null===$w['session_receipts']['identity']['owner_nonce'],'replacement_proof_invalid');
			$c=$w['session_receipts']['closes']??[]; self::need(1===count($c) && ($c[0]['identity']['connection_id']??null)!==($w['session_receipts']['identity']['connection_id']??null),'replacement_proof_invalid');
			if('P8'===$case) { $s=array_values(array_filter($w['statement_receipts'],static fn(array $s):bool=>'consume'===$s['kind'])); self::need(1===count($s) && $s[0]['before_identity']!==$s[0]['after_identity'] && $s[0]['sent'] && 0===$s[0]['actual']['affected'],'replay_proof_invalid'); }
		}
	}
	private static function response(string $case,mixed $r,array $after):void {
		if(in_array($case,['P1','P2'],true)) {
			$p=json_decode($after['profile_bytes'],true);
			$expected=['success'=>true,'data'=>['generation'=>4,'contract_kind'=>'physical','shipping_method_id'=>'ys_ec_ecpay_ship_unimart','shipping_provider'=>'ecpay','destination'=>$p['fulfillment_snapshot']['destination'],'shipping_total'=>'65.00','updated_at'=>'2026-09-05 00:00:00']];
			self::need($r===$expected,'product_response_invalid'); return;
		}
		if('P12b'===$case) { self::need($r===['store_claimed'=>false,'scope'=>'ACTUAL STORE CAS ONLY'],'product_response_invalid'); return; }
		if('P11h'===$case) { self::need($r===['error'=>'訂閱物流認領缺少有效的交易圍籬，請重新選擇門市。','store'=>[]],'product_response_invalid'); return; }
		$code=match($case) { 'P3','P9'=>'profile_update_session_drift','P4','P5','P10'=>'profile_update_commit_indeterminate','P6'=>'profile_update_rollback_indeterminate','P7','P8'=>'claim_rejected','P11a'=>'subscription_not_found','P11c'=>'stale_generation','P11f'=>'shipping_method_not_operable','P11g'=>'shipping_provider_mismatch',default=>'store_selection_invalid' };
		if(in_array($case,['P3','P4','P5','P6','P9','P10'],true)) {
			$expected=['success'=>false,'code'=>$code,'message'=>'訂閱物流更新結果無法確認，請勿重複送出並聯絡客服。','status'=>503,'retryable'=>false,'requires_manual_reconciliation'=>true];
		} else {
			$message=match($case) { 'P7','P8'=>'這次的取貨門市選擇已被使用，請重新選擇門市。', 'P11b'=>'取貨門市與目前的購物車不符，請重新選擇門市。', 'P12a'=>'取貨門市的選擇無效，請重新選擇門市。', 'P11d','P11e'=>'取貨門市的選擇已逾時或無效，請重新選擇門市。', default=>'訂閱物流資料目前無法更新。' };
			$expected=['success'=>false,'code'=>$code,'message'=>$message,'status'=>'P11a'===$case?404:409];
		}
		self::need($r===$expected,'product_response_invalid');
	}
	private static function projection(mixed $r,array $after):void {
		$p=json_decode($after['profile_bytes'],true); $d=$p['fulfillment_snapshot']['destination'];
		$order=['shipping_total'=>65.0,'shipping_method_id'=>'ys_ec_ecpay_ship_unimart','shipping_provider'=>'ecpay','fulfillment_snapshot'=>$p['fulfillment_snapshot']];
		foreach($p['billing'] as $k=>$v) { $order['billing_'.$k]=$v; }
		foreach($p['invoice'] as $k=>$v) { $order['invoice_'.$k]=$v; }
		$order+=['shipping_name'=>$d['recipient_name'],'shipping_phone'=>$d['recipient_phone'],'shipping_country'=>'TW','shipping_postcode'=>'','shipping_state'=>'','shipping_city'=>'','shipping_district'=>'','shipping_address'=>'','shipping_address2'=>'','cvs_store_id'=>'991122','cvs_store_name'=>'Canonical Store','cvs_store_addr'=>'No. 1 Store Rd.'];
		$expected=['ok'=>true,'order_data'=>$order,'generation'=>4,'hash'=>$after['profile_hash'],'allowed_shipping_methods'=>$p['allowed_shipping_methods']];
		self::need(SubscriptionSqlScenario::sameJsonValue($expected,$r),'projection_invalid');
	}
}
