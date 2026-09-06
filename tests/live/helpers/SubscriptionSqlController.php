<?php
/** Offline controller admission and owned IPC supervision; no database handle lives here. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
require_once __DIR__ . '/SubscriptionSqlAllocation.php';
require_once __DIR__ . '/SubscriptionSqlBarrier.php';
require_once __DIR__ . '/SubscriptionSqlEvidence.php';
final class SubscriptionSqlController {
	private static array $admissions = [];
	public static function admit( array $run, array $env, array $sources ): array {
		if ( ! SubscriptionSqlEvidence::exactKeys( $run, [ 'version','phase','cases','allocations','source_heads','runtime_sha256','driver' ] ) || 1 !== $run['version']
			|| 'adapter' !== $run['driver'] || ! is_string( $run['phase'] ) || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,79}\z/', $run['phase'] )
			|| ! is_array( $run['cases'] ) || ! array_is_list( $run['cases'] ) || [] === $run['cases'] || ! is_array( $run['allocations'] )
			|| ! SubscriptionSqlEvidence::exactKeys( $run['source_heads'], [ 'core','ecpay','affiliate' ] ) || ! is_string( $run['runtime_sha256'] )
			|| ! hash_equals( hash_file( 'sha256', PHP_BINARY ), $run['runtime_sha256'] ) ) { throw new SubscriptionSqlFailure( 'controller_packet_invalid' ); }
		foreach ( $run['source_heads'] as $role => $head ) { if ( ! is_string( $head ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/', $head ) || $head !== ( $sources[$role]['head'] ?? null ) ) { throw new SubscriptionSqlFailure( 'controller_source_mismatch' ); } }
		$roots=[];
		foreach(['core','ecpay','affiliate'] as $role) { if(!is_string($sources[$role]['root']??null)) { throw new SubscriptionSqlFailure('controller_source_mismatch'); } $roots[$role]=$sources[$role]['root']; }
		if(SubscriptionProductSqlFixture::inspectSources($roots)!==$sources) { throw new SubscriptionSqlFailure('controller_source_mismatch'); }
		$seen = []; $prefixes = [];
		foreach ( $run['cases'] as $case ) {
			if ( ! is_string( $case ) || ! isset( SubscriptionSqlEvidence::cases()[$case] ) || isset( $seen[$case] ) ) { throw new SubscriptionSqlFailure( 'controller_case_invalid' ); }
			$seen[$case] = true;
			$allocation = SubscriptionSqlAllocation::validate( $run['allocations'][$case] ?? null );
			if ( isset( $prefixes[$allocation['prefix']] ) ) { throw new SubscriptionSqlFailure( 'controller_prefix_reused' ); }
			$prefixes[$allocation['prefix']] = true;
		}
		if ( ! SubscriptionSqlEvidence::exactKeys( $run['allocations'], array_keys( $seen ) ) ) { throw new SubscriptionSqlFailure( 'controller_case_invalid' ); }
		$environments=SubscriptionSqlEvidence::exactKeys($env,$run['cases']) ? $env : (1===count($run['cases']) ? [$run['cases'][0]=>$env]:[]);
		foreach($run['allocations'] as $case=>$allocation) {
			foreach ( [ 'YS_TEST_MYSQL_DSN' => $allocation['host'] . ':' . $allocation['port'], 'YS_TEST_MYSQL_DB' => $allocation['database'], 'YS_TEST_MYSQL_USER' => $allocation['user'], 'YS_ECPAY_SQL_PREFIX' => $allocation['prefix'] ] as $key => $value ) {
				if ( $value !== ( $environments[$case][$key] ?? null ) ) { throw new SubscriptionSqlFailure( 'controller_environment_mismatch' ); }
			}
		}
		self::$admissions[hash('sha256',json_encode($run,JSON_THROW_ON_ERROR))]=['env'=>$env,'sources'=>$sources];
		return $run;
	}
	/** Reuses the B1 run envelope and supervisor; the parent never owns a database handle. */
	public static function runMysql(array $admitted,string $phaseRoot):array {
		$stamp=self::$admissions[hash('sha256',json_encode($admitted,JSON_THROW_ON_ERROR))]??null;
		if(null===$stamp) { throw new SubscriptionSqlFailure('controller_admission_required'); }
		self::admit($admitted,$stamp['env'],$stamp['sources']);
		foreach($admitted['cases'] as $case) {
			SubscriptionSqlEvidence::assertMysqlCase($case);
			if('sql-execution'!==$admitted['allocations'][$case]['kind']) { throw new SubscriptionSqlFailure('sql_execution_allocation_required'); }
		}
		if(false===getenv('YS_TEST_MYSQL_PASSWORD') || ''===getenv('YS_TEST_MYSQL_PASSWORD')) { throw new SubscriptionSqlFailure('execution_password_required'); }
		$helpers=SubscriptionProductSqlFixture::helperReceipt(__DIR__);
		if('CANONICAL'!==$helpers['state'] || $helpers['head']!==$admitted['source_heads']['ecpay']) { throw new SubscriptionSqlFailure('execution_clean_source_required'); }
		if(!extension_loaded('mysqli')) { throw new SubscriptionSqlFailure('mysqli_required'); }
		SubscriptionProductSqlFixture::loadProduct($stamp['sources']);
		$phase=SubscriptionSqlBarrier::createPhase($phaseRoot,$admitted['phase']); $cases=[];
		foreach($admitted['cases'] as $case) {
			$children=[]; $token=bin2hex(random_bytes(16)); $allocation=$admitted['allocations'][$case];
			try {
				foreach('P7'===$case?['A']:['A','B'] as $role) {
					$packet=['version'=>1,'phase'=>$admitted['phase'],'case'=>$case,'role'=>$role,'allocation'=>$allocation,'source_heads'=>$admitted['source_heads'],'token'=>$token,'runtime_sha256'=>$admitted['runtime_sha256']];
					$base=$phase->path().'/'.strtolower($case.'-'.$role);
					$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mysqli','-d','error_reporting=-1','-d','display_errors=stderr','-d','log_errors=0',dirname(__DIR__).'/live_subscription_product_pair.php','--driver=adapter','--mode=mysql-worker','--role='.$role,'--phase='.$admitted['phase'],'--evidence-root='.$phaseRoot];
					$env=getenv();
					foreach(['YS_TEST_MYSQL_DSN'=>$allocation['host'].':'.$allocation['port'],'YS_TEST_MYSQL_DB'=>$allocation['database'],'YS_TEST_MYSQL_USER'=>$allocation['user'],'YS_ECPAY_SQL_PREFIX'=>$allocation['prefix']] as $key=>$value) { $env[$key]=$value; }
					$process=proc_open($command,[0=>['pipe','r'],1=>['file',$base.'.stdout.txt','x'],2=>['file',$base.'.stderr.txt','x']],$pipes,null,$env);
					unset($env);
					if(!is_resource($process)) { throw new SubscriptionSqlFailure('worker_start_failed'); }
					$children[]=['process'=>$process,'base'=>$base,'case'=>$case,'role'=>$role,'phase'=>$admitted['phase'],'token_digest'=>hash('sha256',$token),'command'=>$command,'mysql'=>true,'allocation'=>$allocation];
					$private=json_encode($packet,JSON_THROW_ON_ERROR); $written=fwrite($pipes[0],$private); fclose($pipes[0]);
					if($written!==strlen($private)) { throw new SubscriptionSqlFailure('worker_input_failed'); }
					unset($private,$packet);
				}
				$supervised=self::supervise($children,30000,$phase); $workers=[]; $refs=[];
				foreach($supervised['workers'] as $child) {
					$roleRefs='P7'===$case?$child['result']['receipts']:[$child['result']['role']=>$child['result']['receipt']];
					foreach($roleRefs as $role=>$ref) { $refs[$role]=$ref; $workers[$role]=SubscriptionSqlEvidence::read($phase->path(),$ref); }
				}
				$baseline=['version'=>1,'phase'=>$admitted['phase'],'case'=>$case,'prefix'=>$allocation['prefix'],'source_heads'=>$admitted['source_heads'],'runtime_sha256'=>$admitted['runtime_sha256'],'token_digest'=>hash('sha256',$token),'tables'=>$workers['A']['baseline_receipt']];
				$baseRef=SubscriptionSqlEvidence::persist($phase->path(),strtolower($case).'-baseline.json',$baseline);
				$verdict=SubscriptionSqlEvidence::evaluateMysql($case,$baseline,$workers,['root'=>$phase->path(),'workers'=>$refs]);
				$cases[$case]=['baseline'=>$baseRef,'workers'=>$supervised['workers'],'verdict'=>$verdict];
				SubscriptionSqlEvidence::persist($phase->path(),strtolower($case).'-verdict.json',$cases[$case]);
				if(!$verdict['matches']) { throw new SubscriptionSqlFailure('mysql_slice_oracle_failed'); }
			} finally { unset($token); foreach($children as $child) { if(is_resource($child['process'])) { proc_terminate($child['process']); proc_close($child['process']); } } }
		}
		return ['scope'=>'MYSQLI P1-P12 SLICE','sql_execution'=>'EXECUTED','parent_connection_attempts'=>SubscriptionSqlSession::connectionAttempts(),'cases'=>$cases,'unrun_cases'=>array_values(array_diff(array_keys(SubscriptionSqlEvidence::cases()),array_keys($cases))),'native_wpdb'=>'PREREQUISITE UNSATISFIED'];
	}
	/** Launches only the CLI's IPC echo lane, which does not load product or create a Session. */
	public static function runOffline( array $admitted, string $phaseRoot ): array {
		$stamp=self::$admissions[hash('sha256',json_encode($admitted,JSON_THROW_ON_ERROR))]??null;
		if(null===$stamp) { throw new SubscriptionSqlFailure('controller_admission_required'); }
		self::admit($admitted,$stamp['env'],$stamp['sources']);
		$phase = SubscriptionSqlBarrier::createPhase( $phaseRoot, $admitted['phase'] ?? '' );
		$children = [];
		try {
		foreach ( $admitted['cases'] as $case ) {
			$token = bin2hex( random_bytes( 16 ) );
			foreach ( [ 'A','B' ] as $role ) {
				$packet = [ 'version' => 1, 'phase' => $admitted['phase'], 'case' => $case, 'role' => $role, 'allocation' => $admitted['allocations'][$case], 'source_heads' => $admitted['source_heads'], 'token' => $token ];
				$base = $phase->path() . '/' . strtolower( $case . '-' . $role );
				$command = [ PHP_BINARY, '-n','-d','error_reporting=-1','-d','display_errors=stderr','-d','log_errors=0', dirname( __DIR__ ) . '/live_subscription_product_pair.php', '--driver=adapter', '--mode=ipc-worker', '--role=' . $role ];
				$process = proc_open( $command, [ 0 => [ 'pipe','r' ], 1 => [ 'file',$base . '.stdout.txt','x' ], 2 => [ 'file',$base . '.stderr.txt','x' ] ], $pipes );
				if ( ! is_resource( $process ) ) { throw new SubscriptionSqlFailure( 'worker_start_failed' ); }
				$private = json_encode( $packet, JSON_THROW_ON_ERROR );
				if ( strlen( $private ) !== fwrite( $pipes[0], $private ) ) { fclose( $pipes[0] ); proc_terminate( $process ); proc_close( $process ); throw new SubscriptionSqlFailure( 'worker_input_failed' ); }
				fclose( $pipes[0] ); unset( $private, $packet );
				$children[] = [ 'process' => $process, 'base' => $base, 'case' => $case, 'role' => $role, 'phase'=>$admitted['phase'], 'token_digest' => hash( 'sha256', $token ), 'command' => $command ];
			}
			unset( $token );
		}
		return self::supervise($children,30000);
		} finally { foreach($children as $child) { if(is_resource($child['process'])) { proc_terminate($child['process']); proc_close($child['process']); } } }
	}
	/** Only handles owned proc_open resources; no process-name or foreign-PID termination. */
	private static function supervise(array $children,int $deadlineMs,?SubscriptionSqlBarrier $phase=null):array {
		if($deadlineMs<1 || $deadlineMs>30000) { throw new SubscriptionSqlFailure('worker_deadline_invalid'); }
		$results = []; $until = hrtime( true ) + $deadlineMs*1000000; $released=false;
		foreach ( $children as $child ) {
			do {
				if(null!==$phase && !$released && true===($child['mysql']??false) && 'P2'===$child['case'] && is_file($phase->path().'/p2-wait-observed.json')) {
					if(realpath(dirname($child['base']))!==realpath($phase->path()) || $child['phase']!==basename($phase->path()) || !is_array($child['allocation']??null)) { throw new SubscriptionSqlFailure('controller_release_invalid'); }
					$allocation=SubscriptionSqlAllocation::validate($child['allocation']);
					if('sql-execution'!==$allocation['kind']) { throw new SubscriptionSqlFailure('controller_release_invalid'); }
					$wait=SubscriptionSqlEvidence::p2WaitProof($phase,$allocation['prefix'],$allocation['database'],true);
					$ref=SubscriptionSqlEvidence::persist($phase->path(),'p2-release-receipt.json',['version'=>1,'phase'=>$child['phase'],'case'=>'P2','role'=>'controller','sequence'=>1,'scope'=>'MYSQLI CONTENTION RELEASE','prior_sha256'=>hash('sha256',json_encode($wait))]);
					// A performed the observation; the parent only references that owned identity.
					$phase->publish('P2','release','controller',$wait['marker']['connection_id'],$ref); $released=true;
				}
				if(null!==$phase && !$released && true===($child['mysql']??false) && in_array($child['case'],['P12a','P12b'],true) && is_file($phase->path().'/'.strtolower($child['case']).'-b-seam.json')) {
					if(realpath(dirname($child['base']))!==realpath($phase->path()) || $child['phase']!==basename($phase->path())) { throw new SubscriptionSqlFailure('controller_release_invalid'); }
					$setup=$phase->awaitBound($child['case'],'setup-complete',1); $a=$phase->awaitBound($child['case'],'a-seam',1); $b=$phase->awaitBound($child['case'],'b-seam',1);
					if('MYSQLI WORKER'!==($setup['receipt']['scope']??null) || 'MYSQLI WORKER'!==($a['receipt']['scope']??null) || 'MYSQLI WORKER'!==($b['receipt']['scope']??null)
						|| $setup['marker']['connection_id']!==$a['marker']['connection_id'] || $a['marker']['connection_id']===$b['marker']['connection_id']) { throw new SubscriptionSqlFailure('controller_release_invalid'); }
					$ref=SubscriptionSqlEvidence::persist($phase->path(),strtolower($child['case']).'-release-receipt.json',['version'=>1,'phase'=>$child['phase'],'case'=>$child['case'],'role'=>'controller','sequence'=>1,'scope'=>'MYSQLI INTERFERENCE RELEASE','prior_sha256'=>hash('sha256',json_encode($b))]);
					// This control marker references B's identity; the parent owns no connection.
					$phase->publish($child['case'],'release','controller',$b['marker']['connection_id'],$ref); $released=true;
				}
				$status = proc_get_status( $child['process'] ); if ( ! $status['running'] ) { break; } usleep( 1000 );
			} while ( hrtime( true ) < $until );
			if ( $status['running'] ) { foreach ( $children as $owned ) { if ( is_resource( $owned['process'] ) ) { proc_terminate( $owned['process'] ); proc_close( $owned['process'] ); } } throw new SubscriptionSqlFailure( 'worker_timeout' ); }
			$closed = proc_close( $child['process'] ); $rc = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
			$stdout = (string) file_get_contents( $child['base'] . '.stdout.txt' ); $stderr = (string) file_get_contents( $child['base'] . '.stderr.txt' );
			$result = json_decode( $stdout, true );
			if(true===($child['mysql']??false)) {
				$pair='P7'===$child['case']; $field=$pair?'receipts':'receipt';
				$attempts=$pair || ('A'===$child['role'] && in_array($child['case'],['P8','P9','P10'],true))?2:1;
				if(0!==$rc || ''!==$stderr || !SubscriptionSqlEvidence::exactKeys($result,['version','phase','case','role','scope','token_digest','connection_attempts','sql_execution',$field]) || 1!==$result['version'] || $child['phase']!==$result['phase'] || $child['case']!==$result['case'] || $child['role']!==$result['role'] || $child['token_digest']!==$result['token_digest'] || $attempts!==$result['connection_attempts'] || ($pair?'MYSQLI TWO-HANDLE WORKER':'MYSQLI WORKER')!==$result['scope'] || 'EXECUTED'!==$result['sql_execution'] || !is_array($result[$field]) || ($pair && ('A'!==$child['role'] || 1!==count($children) || !SubscriptionSqlEvidence::exactKeys($result[$field],['A','B'])))) { throw new SubscriptionSqlFailure('worker_result_invalid'); }
				foreach($pair?$result[$field]:[$child['role']=>$result[$field]] as $role=>$ref) {
					$receipt=SubscriptionSqlEvidence::read(dirname($child['base']),$ref);
					if($pair && (1!==($receipt['version']??null) || ($receipt['phase']??null)!==$child['phase'] || ($receipt['case']??null)!=='P7' || ($receipt['role']??null)!==$role || ($receipt['topology']??null)!=='one-worker-two-handles')) { throw new SubscriptionSqlFailure('worker_result_invalid'); }
				}
				$results[]=['rc'=>$rc,'result'=>$result,'command'=>$child['command'],'stdout'=>['path'=>$child['base'].'.stdout.txt','bytes'=>strlen($stdout),'sha256'=>hash('sha256',$stdout)],'stderr'=>['path'=>$child['base'].'.stderr.txt','bytes'=>0,'sha256'=>hash('sha256','')]];
				continue;
			}
			if ( 0 !== $rc || '' !== $stderr || ! SubscriptionSqlEvidence::exactKeys($result,['version','phase','case','role','scope','token_digest','connection_attempts','sql_execution']) || 1!==$result['version'] || $child['phase']!==$result['phase'] || 0!==$result['connection_attempts'] || 'NOT RUN'!==$result['sql_execution'] || 'IPC ONLY' !== ( $result['scope'] ?? null ) || $child['role'] !== ( $result['role'] ?? null ) || $child['case'] !== ( $result['case'] ?? null )
				|| $child['token_digest'] !== ( $result['token_digest'] ?? null ) ) { throw new SubscriptionSqlFailure( 'worker_result_invalid' ); }
			$results[] = [ 'rc' => $rc, 'result' => $result, 'command' => $child['command'], 'stdout' => [ 'path' => $child['base'] . '.stdout.txt', 'bytes' => strlen( $stdout ), 'sha256' => hash( 'sha256', $stdout ) ], 'stderr' => [ 'path' => $child['base'] . '.stderr.txt', 'bytes' => strlen( $stderr ), 'sha256' => hash( 'sha256', $stderr ) ] ];
		}
		$mysql=true===($children[0]['mysql']??false);
		return [ 'scope' => $mysql?'MYSQLI WORKERS':'IPC ONLY', 'sql_execution' => $mysql?'EXECUTED':'NOT RUN', 'workers' => $results ];
	}
}
