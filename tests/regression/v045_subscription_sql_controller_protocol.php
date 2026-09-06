<?php
/** Pure admission and IPC protocol proof; never a database controller run. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionSqlController as Controller;
use YSCartEcpay\Tests\Live\SubscriptionSqlWorker as Worker;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = (string)(getenv('YS_ECPAY_SQL_HARNESS_HELPER_ROOT') ?: dirname( __DIR__ ) . '/live/helpers');
require_once $helpers . '/SubscriptionSqlSession.php';
require_once $helpers . '/SubscriptionSqlAllocation.php';
require_once $helpers . '/SubscriptionProductSqlFixture.php';
$pass = 0; $fail = 0;
$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail ): void { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$pass : ++$fail; };
$available = is_file( $helpers . '/SubscriptionSqlController.php' ) && is_file( $helpers . '/SubscriptionSqlWorker.php' );
$check( 'controller and worker own typed offline protocol interfaces', $available );
if ( $available ) {
	require_once $helpers . '/SubscriptionSqlController.php';
	require_once $helpers . '/SubscriptionSqlWorker.php';
	$sources = Fixture::inspectSources( [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => (string) getenv( 'YS_ECPAY_ROOT' ), 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ] );
	$heads = array_map( static fn ( array $s ): string => $s['head'], $sources );
	$grant = [ 'kind' => 'offline-design', 'host' => '127.0.0.1', 'port' => 9, 'database' => 'ecpay_offline_fixture', 'user' => 'offline_fixture', 'prefix' => 'ecps_0123456789ab_', 'expires_at' => time() + 3600 ];
	$run = [ 'version' => 1, 'phase' => 'protocol-control', 'cases' => [ 'P1' ], 'allocations' => [ 'P1' => $grant ], 'source_heads' => $heads, 'runtime_sha256' => hash_file( 'sha256', PHP_BINARY ), 'driver' => 'adapter' ];
	$env = [ 'YS_TEST_MYSQL_DSN' => '127.0.0.1:9', 'YS_TEST_MYSQL_DB' => 'ecpay_offline_fixture', 'YS_TEST_MYSQL_USER' => 'offline_fixture', 'YS_ECPAY_SQL_PREFIX' => $grant['prefix'] ];
	$admitted = Controller::admit( $run, $env, $sources );
	$check( 'exact pure admission preserves the packet and never constructs or connects mysqli', $admitted === $run && 0 === Session::connectionAttempts() );
	$forged=$sources; $forged['core']['root']=$sources['ecpay']['root']; $code='';
	try { Controller::admit($run,$env,$forged); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('caller supplied source receipt is freshly verified, not trusted', ''!==$code);
	$multi=$run; $multi['cases']=['P1','P2']; $multi['allocations']['P2']=array_replace($grant,['prefix'=>'ecps_abcdef012345_']);
	$environments=['P1'=>$env,'P2'=>array_replace($env,['YS_ECPAY_SQL_PREFIX'=>'ecps_abcdef012345_'])]; $multiOk=false;
	try { $multiOk=Controller::admit($multi,$environments,$sources)===$multi; } catch(SubscriptionSqlFailure $e) {}
	$check('each allocated case has its own exact environment tuple', $multiOk);
	$badRuns = [
		'extra' => $run + [ 'private' => 'benign-private-marker' ],
		'version-type' => array_replace( $run, [ 'version' => '1' ] ),
		'unknown-case' => array_replace( $run, [ 'cases' => [ 'P0' ] ] ),
		'duplicate-case' => array_replace( $run, [ 'cases' => [ 'P1', 'P1' ] ] ),
		'unknown-driver' => array_replace( $run, [ 'driver' => 'foreign' ] ),
		'native' => array_replace( $run, [ 'driver' => 'wordpress' ] ),
		'phase-traversal' => array_replace( $run, [ 'phase' => '../outside' ] ),
		'runtime' => array_replace( $run, [ 'runtime_sha256' => str_repeat( '0', 64 ) ] ),
		'unknown-allocation' => array_replace( $run, [ 'allocations' => [ 'P1' => $grant, 'P0' => $grant ] ] ),
		'duplicate-prefix' => array_replace( $run, [ 'cases' => [ 'P1','P2' ], 'allocations' => [ 'P1' => $grant, 'P2' => $grant ] ] ),
		'expired' => array_replace( $run, [ 'allocations' => [ 'P1' => array_replace( $grant, [ 'expires_at' => time() - 1 ] ) ] ] ),
		'wrong-source' => array_replace( $run, [ 'source_heads' => array_replace( $heads, [ 'core' => str_repeat( '0', 40 ) ] ) ] ),
	];
	foreach ( $badRuns as $name => $bad ) {
		$code = '';
		try { Controller::admit( $bad, $env, $sources ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
		$check( 'run admission rejects ' . $name . ' without exposing packet data', '' !== $code && ! str_contains( $code, 'benign-private-marker' ) && 0 === Session::connectionAttempts() );
	}
	foreach ( array_keys( $env ) as $name ) {
		$badEnv = $env; unset( $badEnv[$name] ); $code = '';
		try { Controller::admit( $run, $badEnv, $sources ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
		$check( 'environment has no default for ' . $name, 'controller_environment_mismatch' === $code );
	}
	$packet = [ 'version' => 1, 'phase' => 'protocol-control', 'case' => 'P1', 'role' => 'A', 'allocation' => $grant, 'source_heads' => $heads, 'token' => str_repeat( 'T', 32 ) ];
	$check( 'private worker packet validates without logging its token', Worker::validatePacket( $packet ) === $packet );
	foreach ( [ $packet + [ 'password' => 'benign-private-marker' ], array_replace( $packet, [ 'role' => 'controller' ] ), array_replace( $packet, [ 'token' => 'short' ] ), array_replace( $packet, [ 'case' => 'P11' ] ) ] as $index => $bad ) {
		$code = '';
		try { Worker::validatePacket( $bad ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
		$check( 'private worker contract rejects malformed shape ' . $index, 'worker_packet_invalid' === $code );
	}
	$scratch = sys_get_temp_dir() . '/ecpay-b1-protocol-' . bin2hex( random_bytes( 8 ) ); mkdir( $scratch );
	$unadmitted=$run; $unadmitted['phase']='unadmitted'; $code='';
	try { Controller::runOffline($unadmitted,$scratch); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('forged admission cannot create a phase or child', 'controller_admission_required'===$code && !is_dir($scratch.'/unadmitted'));
	$protocol = Controller::runOffline( $admitted, $scratch );
	$check( 'IPC-only controller receives both independent child verdicts, never SQL acceptance', 'IPC ONLY' === ( $protocol['scope'] ?? null ) && 2 === count( $protocol['workers'] ?? [] ) && 'NOT RUN' === ( $protocol['sql_execution'] ?? null ) );
	$childrenOk = true;
	foreach ( $protocol['workers'] ?? [] as $child ) {
		$childrenOk = $childrenOk && 0 === $child['rc'] && 0 === $child['stderr']['bytes'] && 0 === $child['result']['connection_attempts']
			&& in_array( $child['result']['role'], [ 'A','B' ], true ) && 'IPC ONLY' === $child['result']['scope'];
	}
	$check( 'each child has rc0 empty stderr and zero connection attempts', $childrenOk );
	$secretSentinel='offline-private-sentinel-'.bin2hex(random_bytes(8));
	$privatePacket=$packet; $privatePacket['token']=str_pad('PrivateSentinel',32,'S');
	$privateOut=$scratch.'/private-child.stdout'; $privateErr=$scratch.'/private-child.stderr';
	$privateCommand=[PHP_BINARY,'-n',dirname(__DIR__).'/live/live_subscription_product_pair.php','--driver=adapter','--mode=ipc-worker','--role=A'];
	$savedSecret=getenv('YS_TEST_MYSQL_PASSWORD'); putenv('YS_TEST_MYSQL_PASSWORD='.$secretSentinel);
	$privateProcess=proc_open($privateCommand,[0=>['pipe','r'],1=>['file',$privateOut,'x'],2=>['file',$privateErr,'x']],$pipes);
	fwrite($pipes[0],json_encode($privatePacket)); fclose($pipes[0]); $privateRc=proc_close($privateProcess);
	putenv(false===$savedSecret?'YS_TEST_MYSQL_PASSWORD':'YS_TEST_MYSQL_PASSWORD='.$savedSecret);
	$privateBytes=file_get_contents($privateOut).file_get_contents($privateErr).json_encode($privateCommand);
	$check('private stdin token and inherited password sentinel never enter argv or retained output',0===$privateRc && !str_contains($privateBytes,$privatePacket['token']) && !str_contains($privateBytes,$secretSentinel) && 0===filesize($privateErr));
	$supervisor=new ReflectionMethod(Controller::class,'supervise');
	foreach(['partial','missing','wrong-phase','wrong-role','timeout'] as $failure) {
		$body=['version'=>1,'phase'=>'expected','case'=>'P1','role'=>'A','scope'=>'IPC ONLY','token_digest'=>str_repeat('0',64),'connection_attempts'=>0,'sql_execution'=>'NOT RUN'];
		if('wrong-phase'===$failure) { $body['phase']='foreign'; } if('wrong-role'===$failure) { $body['role']='B'; }
		$code='echo '.var_export(json_encode($body),true).';';
		if('partial'===$failure) { $code='echo "{";'; } if('missing'===$failure) { $code=''; } if('timeout'===$failure) { $code='usleep(200000);'; }
		$base=$scratch.'/owned-'.$failure; $command=[PHP_BINARY,'-n','-r',$code];
		$child=proc_open($command,[0=>['pipe','r'],1=>['file',$base.'.stdout.txt','x'],2=>['file',$base.'.stderr.txt','x']],$pipes); fclose($pipes[0]); $caught='';
		try { $supervisor->invoke(null,[['process'=>$child,'base'=>$base,'case'=>'P1','role'=>'A','phase'=>'expected','token_digest'=>str_repeat('0',64),'command'=>$command]],'timeout'===$failure?1:1000); }
		catch(SubscriptionSqlFailure $e) { $caught=$e->getMessage(); }
		finally { if(is_resource($child)) { proc_terminate($child); proc_close($child); } }
		$check('owned IPC supervisor rejects '.$failure.' without hidden stderr',('timeout'===$failure?'worker_timeout':'worker_result_invalid')===$caught && 0===filesize($base.'.stderr.txt'));
	}
	$code = ''; try { Controller::runOffline( $admitted, $scratch ); } catch ( SubscriptionSqlFailure $e ) { $code = $e->getMessage(); }
	$check( 'duplicate phase cannot overwrite controller evidence', 'phase_exists' === $code );
	$path = $scratch . '/protocol-receipt.json'; file_put_contents( $path, json_encode( $protocol, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'PROTOCOL_RECEIPTS ' . json_encode( [ 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ], JSON_UNESCAPED_SLASHES ) . "\n";
	// Native-shaped IPC ONLY: both child receipts are empty and cannot prove SQL execution.
	foreach([['P12a','normal'],['P12b','normal'],['P12a','missing'],['P12a','scope'],['P12a','duplicate']] as [$case,$failure]) {
		$phase='release-'.strtolower($case).'-'.$failure;
		$barrier=\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::createPhase($scratch,$phase);
		foreach([['setup-complete','A',1,'9101'],['a-seam','A',2,'9101'],['b-seam','B',1,'9102']] as [$stage,$role,$sequence,$cid]) {
			if('missing'===$failure && 'b-seam'===$stage) { continue; }
			$payload=['version'=>1,'phase'=>$phase,'case'=>$case,'role'=>$role,'sequence'=>$sequence,'scope'=>'scope'===$failure && 'b-seam'===$stage?'FOREIGN CONTROL':'MYSQLI WORKER','data'=>[]];
			$ref=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),strtolower($case).'-'.$stage.'-receipt.json',$payload);
			$barrier->publish($case,$stage,$role,$cid,$ref);
		}
		if('duplicate'===$failure) {
			$proof=$barrier->awaitBound($case,'b-seam',1);
			$ref=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),strtolower($case).'-release-receipt.json',['version'=>1,'phase'=>$phase,'case'=>$case,'role'=>'controller','sequence'=>1,'scope'=>'MYSQLI INTERFERENCE RELEASE','prior_sha256'=>hash('sha256',json_encode($proof))]);
			$barrier->publish($case,'release','controller','9102',$ref);
		}
		$empty=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),'empty-worker.json',[]);
		$owned=[]; $caught=''; $result=null;
		foreach(['A','B'] as $role) {
			$base=$barrier->path().'/'.$role;
			$body=['version'=>1,'phase'=>$phase,'case'=>$case,'role'=>$role,'scope'=>'MYSQLI WORKER','token_digest'=>str_repeat('a',64),'connection_attempts'=>1,'sql_execution'=>'EXECUTED','receipt'=>$empty];
			$code='$until=hrtime(true)+1000000000; while(!is_file($argv[1]) && hrtime(true)<$until) { usleep(1000); } if(!is_file($argv[1])) { exit(3); } echo stream_get_contents(STDIN);';
			$command=[PHP_BINARY,'-n','-r',$code,$barrier->path().'/'.strtolower($case).'-release.json'];
			$child=proc_open($command,[0=>['pipe','r'],1=>['file',$base.'.stdout.txt','x'],2=>['file',$base.'.stderr.txt','x']],$pipes);
			if(!is_resource($child)) { throw new RuntimeException('release IPC process unavailable'); }
			fwrite($pipes[0],json_encode($body)); fclose($pipes[0]);
			$owned[]=['process'=>$child,'base'=>$base,'case'=>$case,'role'=>$role,'phase'=>$phase,'token_digest'=>str_repeat('a',64),'command'=>$command,'mysql'=>true];
		}
		try { $result=$supervisor->invoke(null,$owned,500,$barrier); }
		catch(SubscriptionSqlFailure $error) { $caught=$error->getMessage(); }
		finally { foreach($owned as $child) { if(is_resource($child['process'])) { proc_terminate($child['process']); proc_close($child['process']); } } }
		$expected=match($failure){'normal'=>'','missing'=>'worker_timeout','scope'=>'controller_release_invalid','duplicate'=>'evidence_exists'};
		$check($case.' owned release pump '.$failure.' is a bounded zero-DB control',$caught===$expected && 0===Session::connectionAttempts());
		if('normal'===$failure && ''===$caught) {
			$release=$barrier->awaitBound($case,'release',1); $prior=$barrier->awaitBound($case,'b-seam',1);
			$check($case.' release binds exact B proof and never claims a parent connection',2===count($result['workers']) && $release['marker']['connection_id']===$prior['marker']['connection_id'] && $release['receipt']['scope']==='MYSQLI INTERFERENCE RELEASE' && $release['receipt']['prior_sha256']===hash('sha256',json_encode($prior)));
		}
	}
}
$check( 'bound barriers have exact phase role and receipt custody', method_exists( \YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::class, 'publish' ) );
if(method_exists(\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::class,'publish')) {
	$barrier=\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::createPhase($scratch,'bound-control');
	$payload=['version'=>1,'phase'=>'bound-control','case'=>'P1','role'=>'A','sequence'=>1,'data'=>['scope'=>'SYNTHETIC IPC ONLY']];
	$ref=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),'p1-setup-complete-receipt.json',$payload);
	$marker=$barrier->publish('P1','setup-complete','A','9101',$ref);
	$check('bound arrival rehashes the separately retained role receipt', $barrier->awaitBound('P1','setup-complete',1)['receipt']===$payload);
	$foreign=\YSCartEcpay\Tests\Live\SubscriptionSqlBarrier::createPhase($scratch,'foreign-receipt-control');
	$foreignPayload=$payload; $foreignPayload['phase']='foreign-receipt-control';
	$foreignRef=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($foreign->path(),'foreign-name.json',$foreignPayload);
	$code=''; try { $foreign->publish('P1','setup-complete','A','9101',$foreignRef); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
	$check('bound publisher rejects a valid digest under the wrong owned artifact name before writing marker', 'barrier_receipt_invalid'===$code && !is_file($foreign->path().'/p1-setup-complete.json'));
	foreach([['P1','setup-complete','A','9101',$ref],['P1','foreign','A','9101',$ref],['P1','b-complete','A','9101',$ref],['P2','a-complete','A','9101',$ref]] as $i=>$args) {
		$code=''; try { $barrier->publish(...$args); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
		$check('duplicate foreign role or out-of-order bound marker rejected '.$i, ''!==$code);
	}
}
echo "subscription SQL controller protocol: {$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
