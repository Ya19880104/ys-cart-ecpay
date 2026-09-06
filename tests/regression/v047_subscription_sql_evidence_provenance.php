<?php
/** Artifact/provenance oracle tests. Synthetic fixtures never constitute SQL acceptance. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionSqlEvidence as Evidence;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = (string)(getenv('YS_ECPAY_SQL_HARNESS_HELPER_ROOT') ?: dirname( __DIR__ ) . '/live/helpers');
require_once $helpers . '/SubscriptionSqlSession.php';
$pass = 0; $fail = 0;
$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail ): void { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$pass : ++$fail; };
$available = is_file( $helpers . '/SubscriptionSqlEvidence.php' );
$check( 'typed immutable evidence collector exists', $available );
if ( $available ) {
	require_once $helpers . '/SubscriptionSqlEvidence.php';
	$cases = Evidence::cases();
	$check( 'nonempty arbitrary arrays cannot bypass complete provenance validation', false === Evidence::evaluate( 'P1', [ 'anything'=>true ], [ 'anything'=>true ], [ 'anything'=>true ] )['matches'] );
	$check( 'explicit scenario manifest distinguishes eight admission and two interference subcases', array_keys( $cases ) === [ 'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10','P11a','P11b','P11c','P11d','P11e','P11f','P11g','P11h','P12a','P12b' ] );
	$check( 'P7 is not labeled as P2 contention or physical evidence', 'one-worker-two-handles' === $cases['P7']['topology'] && 'two-workers' === $cases['P2']['topology'] );
	foreach(array_keys($cases) as $case) {
		$events=Evidence::events($case,'A'); $raw=''; foreach($events as $event=>$count) { $raw.=str_repeat('[05-Sep-2026 00:00:00 UTC] '.$event."\n",$count); }
		$check($case.' exact application-log manifest rejects a single extra byte', Evidence::logMatches($raw,$events) && !Evidence::logMatches($raw.'x',$events));
		if(''!==$raw) { $check($case.' duplicate expected event is still a failure',!Evidence::logMatches($raw.$raw,$events)); }
	}
	foreach ( array_keys( $cases ) as $case ) {
		$r = Evidence::evaluate( $case, [], [], [] );
		$check( $case . ' cannot accept absent role/source/server/artifact evidence', false === $r['matches'] && 'NOT RUN' === $r['sql_execution'] && 'OFFLINE ORACLE ONLY' === $r['scope'] );
	}
	$scratch = sys_get_temp_dir() . '/ecpay-b1-evidence-' . bin2hex( random_bytes( 8 ) ); mkdir( $scratch );
	$ref = Evidence::persist( $scratch, 'observer.json', [ 'value' => 'synthetic-observation' ] );
	$check( 'create-exclusive artifact receipt binds path bytes and hash', Evidence::read( $scratch, $ref ) === [ 'value' => 'synthetic-observation' ] );
	$code = ''; try { Evidence::persist( $scratch, 'observer.json', [] ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'duplicate artifact cannot overwrite observation', 'evidence_exists' === $code );
	$bad = $ref; $bad['sha256'] = str_repeat( '0', 64 );
	$code = ''; try { Evidence::read( $scratch, $bad ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'tampered digest cannot become a verified receipt', 'evidence_invalid' === $code );
	$bad = $ref; $bad['extra'] = true;
	$code = ''; try { Evidence::read( $scratch, $bad ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'extra receipt keys are rejected', 'evidence_invalid' === $code );
	$code = ''; try { Evidence::persist( $scratch, '../outside.json', [] ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'evidence path cannot escape the phase', 'evidence_invalid' === $code );
	require_once $helpers . '/SubscriptionProductSqlFixture.php'; require_once $helpers . '/SubscriptionSqlWorker.php';
	define('ECPAY_B1_CAPTURE_LIBRARY',true); require_once __DIR__ . '/v046_subscription_sql_seed_fault_boundaries.php';
	$sources=\YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture::inspectSources(['core'=>getenv('YS_CORE_ROOT'),'ecpay'=>getenv('YS_ECPAY_ROOT'),'affiliate'=>getenv('YS_AFFILIATE_ROOT')]);
	$selected=array_keys($cases);
	if(count($argv)>1) { if(2!==count($argv) || 1!==preg_match('/\A--case=(P[0-9]+[a-h]?)\z/',$argv[1],$m) || !isset($cases[$m[1]])) { throw new SubscriptionSqlFailure('test_case_invalid'); } $selected=[$m[1]]; }
	foreach($selected as $case) {
		$control=ecpay_b1_run_control($case,$scratch,$sources);
		$check($case . ' actual product capture workers have ordinary successful transport receipts', []===$control['errors']);
		if([]===$control['errors']) {
			$verdict=Evidence::evaluate($case,$control['base'],$control['workers'],$control['artifacts']);
			$check($case . ' independent complete oracle accepts only capture control evidence', true===$verdict['matches'] && 'NOT RUN'===$verdict['sql_execution']);
			echo 'CONTROL_VERDICT ' . json_encode(['case'=>$case,'verdict'=>$verdict,'phase'=>$control['artifacts']['root']],JSON_UNESCAPED_SLASHES)."\n";
			if(true===$verdict['matches']) {
				$fork=ecpay_b1_fork_evidence($control,'identity',static fn(string $kind,string $name,mixed $value):mixed=>$value);
				$check($case.' hand-derived rehashed artifact clone is a valid negative-control baseline',Evidence::evaluate($case,$fork['base'],$fork['workers'],$fork['artifacts'])['matches']);
				$changes=[
					'response'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['product_response']['unexpected']=true; } return $value; },
					'stderr'=>static fn(string $kind,string $name,mixed $value):mixed=>'raw'===$kind && str_ends_with($name,'.stderr') ? $value.'x':$value,
					'sibling'=>static function(string $kind,string $name,mixed $value) use($control):mixed { if('worker'===$kind && 'B'===$name) { $value['readback_receipt'][$control['base']['prefix'].'ys_ec_subscriptions'][1]['gateway_id']='foreign-fixture'; } return $value; },
					'duplicate-statement'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as $s) { if('product'===$s['origin']) { $s['sequence']=count($value['statement_receipts'])+1; $s['kind']='consume'; $value['statement_receipts'][]=$s; break; } } } return $value; },
					'source'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['source_receipt']['sources']['core']['head']=str_repeat('0',40); } return $value; },
					'phase'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['phase']='foreign-phase'; } return $value; },
					'fault-role'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['fault_receipt']['role']='B'; } return $value; },
					'session-extra'=>static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'B'===$name) { $value['session_receipts']['unknown']=true; } return $value; },
				];
				// Independent, fully rehashed contradictions: a valid artifact hash is not SQL proof.
				if('P1'===$case) {
					foreach(['actual-failed','presented-untyped','actual-error-type','actual-affected-type','actual-extra','presented-diverged','kind-untyped','observer-write','setup-unknown-write'] as $break) {
						$changes['dispatch-'.$break]=static function(string $kind,string $name,mixed $value) use($break):mixed {
							if('worker'!==$kind || 'A'!==$name) { return $value; }
							foreach($value['statement_receipts'] as &$s) {
								if('commit'!==$s['kind']) { continue; }
								if('actual-failed'===$break) { $s['actual']=['error'=>'fixture_actual_commit_failed','rows'=>[],'affected'=>false]; $s['presented']=$s['actual']; }
								if('presented-untyped'===$break) { $s['presented']=['unrelated'=>true]; }
								if('actual-error-type'===$break) { $s['actual']['error']=null; $s['presented']=$s['actual']; }
								if('actual-affected-type'===$break) { $s['actual']['affected']='0'; $s['presented']=$s['actual']; }
								if('actual-extra'===$break) { $s['presented']['extra']=true; }
								if('presented-diverged'===$break) { $s['presented']['rows']=[['unrelated'=>'value']]; }
								if(in_array($break,['kind-untyped','observer-write','setup-unknown-write'],true)) {
									$extra=$s; $extra['sequence']=count($value['statement_receipts'])+1; $extra['origin']='setup-unknown-write'===$break?'schema-setup':'observer'; $extra['kind']='kind-untyped'===$break?0:'read-or-setup';
									$extra['sql']='UPDATE unrelated_fixture SET value = 1'; $extra['sql_sha256']=hash('sha256',$extra['sql']); $value['statement_receipts'][]=$extra;
								} break;
							} unset($s); return $value;
						};
					}
				}
				if('P4'===$case) { $changes['ackloss-failed-actual']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as &$s) { if('commit'===$s['kind']) { $s['actual']=['error'=>'fixture_actual_commit_failed','rows'=>[],'affected'=>false]; $value['fault_receipt']['actual_commit']=$s['actual']; } } unset($s); } return $value; }; }
				if('P5'===$case) {
					foreach(['observer','schema-setup','fault-control'] as $origin) { $changes['hidden-commit-'.$origin]=static function(string $kind,string $name,mixed $value) use($origin):mixed { if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as $s) { if('commit'===$s['kind']) { $s['sequence']=count($value['statement_receipts'])+1; $s['origin']=$origin; $s['sent']=true; $s['actual']=['error'=>'','rows'=>[],'affected'=>0]; $s['presented']=$s['actual']; $s['fault']=null; $value['statement_receipts'][]=$s; break; } } } return $value; }; }
				}
				if(in_array($case,['P3','P5','P6'],true)) { $changes['suppressed-presentation']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as &$s) { if(!$s['sent']) { $s['presented']['error']='unrelated_failure'; break; } } unset($s); } return $value; }; }
				if(in_array($case,['P12a','P12b'],true)) {
					foreach(['prior','scope','extra','identity'] as $break) { $changes['release-'.$break]=static function(string $kind,string $name,mixed $value) use($break):mixed {
						if('artifact'===$kind && str_ends_with($name,'-release-receipt.json')) {
							if('prior'===$break) { $value['prior_sha256']=str_repeat('0',64); }
							if('scope'===$break) { $value['scope']='FOREIGN CONTROL'; }
							if('extra'===$break) { $value['unrelated']=true; }
						}
						if('identity'===$break && 'marker'===$kind && str_ends_with($name,'-release.json')) { $value['connection_id']='9199'; }
						return $value;
					}; }
					foreach(['missing','zero-affected','predicate','readback','receipt-hash'] as $break) { $changes['interference-dispatch-'.$break]=static function(string $kind,string $name,mixed $value) use($break):mixed {
						if('worker'!==$kind || 'B'!==$name) { return $value; }
						if('missing'===$break) { $value['statement_receipts']=array_values(array_filter($value['statement_receipts'],static fn(array $s):bool=>'fault-control'!==$s['origin'])); foreach($value['statement_receipts'] as $i=>&$s) { $s['sequence']=$i+1; } unset($s); }
						if('receipt-hash'===$break) { $value['fault_receipt']['interference']['sql_sha256']=str_repeat('0',64); }
						$wrote=false;
						foreach($value['statement_receipts'] as &$s) { if('fault-control'!==$s['origin']) { continue; }
							if(str_starts_with($s['sql'],'UPDATE ')) { $wrote=true; if('zero-affected'===$break) { $s['actual']['affected']=0; $s['presented']=$s['actual']; } if('predicate'===$break) { $s['sql'].=' '; $s['sql_sha256']=hash('sha256',$s['sql']); $value['fault_receipt']['interference']['sql_sha256']=$s['sql_sha256']; } }
							elseif($wrote && 'readback'===$break) { $s['actual']['rows']=[['option_value'=>'unrelated']]; $s['presented']=$s['actual']; }
						} unset($s); return $value;
					}; }
				}
				if('P2'===$case) { $changes['wait-unrelated-sql']=static function(string $kind,string $name,mixed $value):mixed { $sql='SELECT 1'; if('artifact'===$kind && 'p2-wait-observed-receipt.json'===$name) { $value['data']['sql_sha256']=hash('sha256',$sql); } if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as &$s) { if('observer'===$s['origin'] && isset($s['actual']['rows'][0]['blocking_id'])) { $s['sql']=$sql; $s['sql_sha256']=hash('sha256',$sql); } } unset($s); } return $value; }; }
				if('P2'===$case) {
					$changes['wait-marker-identity']=static function(string $kind,string $name,mixed $value):mixed { if('marker'===$kind && 'p2-wait-observed.json'===$name) { $value['connection_id']='9102'; } return $value; };
					$changes['b-response']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'B'===$name) { $value['product_response']['code']='stale_generation'; } return $value; };
					$changes['wait-predicate']=static function(string $kind,string $name,mixed $value):mixed {
						if('artifact'===$kind && 'p2-wait-observed-receipt.json'===$name) { $value['data']['rows'][0]['blocking_id']='9102'; }
						if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as &$s) { if('observer'===$s['origin'] && isset($s['actual']['rows'][0]['blocking_id'])) { $s['actual']['rows'][0]['blocking_id']='9102'; $s['presented']=$s['actual']; } } unset($s); }
						return $value;
					};
				}
				if(in_array($case,['P12a','P12b'],true)) {
					$changes['interference-bytes']=static function(string $kind,string $name,mixed $value) use($case,$control):mixed { if('worker'===$kind && 'B'===$name) { $i=&$value['fault_receipt']['interference']; $i['after_bytes'].=' '; $value['readback_receipt'][$control['base']['prefix'].'options'][1]['option_value']=$i['after_bytes']; } return $value; };
				}
				if('P8'===$case) { $changes['replay-bytes']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { foreach($value['statement_receipts'] as &$s) { if('consume'===$s['kind']) { $s['sql'].=' '; $s['sql_sha256']=hash('sha256',$s['sql']); } } unset($s); } return $value; }; }
				if(in_array($case,['P8','P9','P10'],true)) {
					foreach(['old-id','sequence','reason','poison','duplicate','owner'] as $break) { $changes['replacement-close-'.$break]=static function(string $kind,string $name,mixed $value) use($break):mixed {
						if('worker'!==$kind || 'A'!==$name) { return $value; }
						if('old-id'===$break) { $value['session_receipts']['closes'][0]['identity']['connection_id']='9199'; }
						if('sequence'===$break) { ++$value['session_receipts']['closes'][0]['sequence']; }
						if('reason'===$break) { $value['session_receipts']['closes'][0]['reason']='close'; }
						if('poison'===$break) { $value['session_receipts']['closes'][0]['poisoned']=true; }
						if('duplicate'===$break) { $value['session_receipts']['closes'][]=$value['session_receipts']['closes'][0]; }
						if('owner'===$break) { $value['session_receipts']['identity']['owner_nonce']=str_repeat('f',32); }
						return $value;
					}; }
					if('P8'!==$case) { $changes['forbidden-terminal']=static function(string $kind,string $name,mixed $value) use($case):mixed { if('worker'===$kind && 'A'===$name) { $s=end($value['statement_receipts']); $s['sequence']=count($value['statement_receipts'])+1; $s['origin']='product'; $s['kind']='P9'===$case?'commit':'rollback'; $s['sql']=strtoupper($s['kind']); $s['sql_sha256']=hash('sha256',$s['sql']); $s['actual']=$s['presented']=['error'=>'','rows'=>[],'affected'=>0]; $s['sent']=true; $s['fault']=null; $value['statement_receipts'][]=$s; } return $value; }; }
				}
				if('P1'===$case) {
					foreach(['scalar','numeric-key-object','payment'] as $tamper) {
						$changes['profile-'.$tamper]=static function(string $kind,string $name,mixed $value) use($control,$tamper):mixed {
							if('worker'===$kind && 'B'===$name) {
								$r=&$value['readback_receipt'][$control['base']['prefix'].'ys_ec_subscriptions'][0]; $profile=json_decode($r['fulfillment_profile'],true);
								if('scalar'===$tamper) { $profile['shipping_total']=65; }
								if('numeric-key-object'===$tamper) { $profile['fulfillment_snapshot']['items']=(object)$profile['fulfillment_snapshot']['items']; }
								if('payment'===$tamper) { $profile['fulfillment_snapshot']['service']['payment_method_id']='foreign-fixture'; }
								$r['fulfillment_profile']=json_encode($profile,JSON_UNESCAPED_SLASHES); $r['fulfillment_profile_hash']=hash('sha256',$r['fulfillment_profile']);
							} return $value;
						};
					}
					$changes['projection']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'B'===$name) { $value['projection_receipt']['order_data']['billing_name']='foreign-fixture'; } return $value; };
				}
				if('P4'===$case) { $changes['actual-commit']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['fault_receipt']['actual_commit']=null; } return $value; }; }
				if('P6'===$case) { $changes['poison-before-close']=static function(string $kind,string $name,mixed $value):mixed { if('worker'===$kind && 'A'===$name) { $value['session_receipts']['closes'][0]['poisoned']=false; } return $value; }; }
				if([]!==Evidence::events($case,'A')) { $changes['event-count']=static fn(string $kind,string $name,mixed $value):mixed=>'raw'===$kind && str_ends_with($name,'-a-application.log') ? $value.$value : $value; }
				// Peer fields must reject before A reconstructs a schedule from B's metadata.
				$peerFields='P2'===$case?['connection_id']:(in_array($case,['P12a','P12b'],true)?['interference','after_bytes']:[]);
				foreach($peerFields as $field) {
					foreach(['array','integer','null','missing'] as $variant) {
						$label='peer-'.$field.'-'.$variant;
						$bad=ecpay_b1_fork_evidence($control,$label,static function(string $kind,string $name,mixed $value) use($field,$variant):mixed {
							if('worker'!==$kind || 'B'!==$name) { return $value; }
							if('connection_id'===$field) { $target=&$value['session_receipts']['identity']; $key='connection_id'; }
							elseif('interference'===$field) { $target=&$value['fault_receipt']; $key='interference'; }
							else { $target=&$value['fault_receipt']['interference']; $key='after_bytes'; }
							if('missing'===$variant) { unset($target[$key]); }
							else { $target[$key]=match($variant){'array'=>[],'integer'=>0,'null'=>null}; }
							unset($target); return $value;
						});
						$v=null;
						set_error_handler(static function(int $severity,string $message,string $file,int $line):never { throw new ErrorException($message,0,$severity,$file,$line); });
						try { $v=Evidence::evaluate($case,$bad['base'],$bad['workers'],$bad['artifacts']); }
						catch(Throwable $error) { echo 'PEER_EXCEPTION '.json_encode(['case'=>$case,'field'=>$label,'class'=>get_class($error)],JSON_UNESCAPED_SLASHES)."\n"; }
						finally { restore_error_handler(); }
						$expected='connection_id'===$field?'session_receipt_invalid':'interference_invalid';
						$check($case.' rehashed '.$label.' receives a typed ordinary rejection',null!==$v && false===$v['matches'] && [$expected]===$v['errors'] && 'NOT RUN'===$v['sql_execution']);
					}
				}
				foreach($changes as $label=>$change) {
					$bad=ecpay_b1_fork_evidence($control,$label,$change); $v=Evidence::evaluate($case,$bad['base'],$bad['workers'],$bad['artifacts']);
					$check($case.' rehashed '.$label.' tamper fails a normal independent verdict',false===$v['matches']);
				}
			}
		} else { echo 'CONTROL_FAILURE '.json_encode(['case'=>$case,'errors'=>$control['errors'],'phase'=>$control['artifacts']['root']],JSON_UNESCAPED_SLASHES)."\n"; }
	}
}
echo "subscription SQL evidence provenance: {$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );

/** Preserve original evidence; construct a separate fully rehashed adversarial artifact set. */
function ecpay_b1_fork_evidence(array $control,string $label,callable $change):array {
	$old=$control['artifacts']['root']; $parent=dirname($old).'/negative-'.strtolower($control['base']['case']).'-'.$label; mkdir($parent); $root=$parent.'/'.basename($old); mkdir($root);
	$refs=[];
	foreach(glob($old.'/*') as $path) {
		$name=basename($path); if(!is_file($path) || (!str_ends_with($name,'.log') && !str_ends_with($name,'.stderr'))) { continue; }
		$bytes=$change('raw',$name,file_get_contents($path)); $h=fopen($root.'/'.$name,'x'); fwrite($h,$bytes); fclose($h); $refs[$name]=['path'=>realpath($root.'/'.$name),'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
	}
	foreach(glob($old.'/*-receipt.json') as $path) {
		$name=basename($path); $value=json_decode(file_get_contents($path),true); $refs[$name]=Evidence::persist($root,$name,$change('artifact',$name,$value));
	}
	foreach(glob($old.'/*.json') as $path) {
		$name=basename($path); $value=json_decode(file_get_contents($path),true);
		if(!is_array($value) || !isset($value['stage'],$value['receipt_sha256'])) { continue; }
		$value=$change('marker',$name,$value); $value['receipt_sha256']=$refs[strtolower($control['base']['case']).'-'.$value['stage'].'-receipt.json']['sha256'];
		$refs[$name]=Evidence::persist($root,$name,$value);
	}
	$workers=[]; $workerRefs=[];
	foreach($control['workers'] as $role=>$w) {
		$w=$change('worker',$role,$w);
		foreach($w['diagnostics'] as $key=>$ref) { $w['diagnostics'][$key]=$refs[basename($ref['path'])]; }
		foreach($w['barrier_receipts'] as $i=>$ref) { $w['barrier_receipts'][$i]=$refs[basename($ref['path'])]; }
		$workers[$role]=$w; $workerRefs[$role]=Evidence::persist($root,strtolower($role).'-worker.json',$w);
	}
	return ['base'=>$control['base'],'workers'=>$workers,'artifacts'=>['root'=>$root,'workers'=>$workerRefs]];
}
