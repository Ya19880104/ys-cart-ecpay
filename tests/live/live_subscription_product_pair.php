<?php
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlBarrier as Barrier;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
require_once __DIR__ . '/helpers/SubscriptionSqlSession.php';
require_once __DIR__ . '/helpers/SubscriptionSqlAllocation.php';
require_once __DIR__ . '/helpers/SubscriptionSqlBarrier.php';
require_once __DIR__ . '/helpers/SubscriptionProductSqlFixture.php';
$result = [ 'success' => false, 'code' => '', 'connection_attempts' => 0, 'sql_statements' => 0, 'sql_execution' => 'NOT RUN', 'native_wpdb' => 'PREREQUISITE UNSATISFIED' ];
$rc = 2;
try {
	$options = [];
	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( 1 !== preg_match( '/\A--(driver|mode|phase|evidence-root|role)=(.*)\z/s', $argument, $match ) ) { throw new SubscriptionSqlFailure( 'argument_unknown' ); }
		$options[$match[1]] = $match[2];
	}
	if ( ! in_array( $options['driver'] ?? '', [ 'adapter', 'wordpress' ], true ) ) { throw new SubscriptionSqlFailure( 'driver_unknown' ); }
	if ( 'wordpress' === $options['driver'] ) { throw new SubscriptionSqlFailure( 'native_wpdb_prerequisite_unsatisfied' ); }
	if('mysql-worker'===($options['mode']??'')) {
		require_once __DIR__.'/helpers/SubscriptionSqlWorker.php';
		if(!\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::exactKeys($options,['driver','mode','role','phase','evidence-root'])) { throw new SubscriptionSqlFailure('worker_packet_invalid'); }
		$private=stream_get_contents(STDIN,32769); $packet=is_string($private) && strlen($private)<=32768?json_decode($private,true):null;
		if(!\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::exactKeys($packet,['version','phase','case','role','allocation','source_heads','token','runtime_sha256']) || !is_string($packet['runtime_sha256'])) { throw new SubscriptionSqlFailure('worker_packet_invalid'); }
		$runtime=$packet['runtime_sha256']; unset($packet['runtime_sha256'],$private);
		$packet=\YSCartEcpay\Tests\Live\SubscriptionSqlWorker::validatePacket($packet);
		if($options['role']!==$packet['role'] || $options['phase']!==$packet['phase']) { throw new SubscriptionSqlFailure('worker_packet_invalid'); }
		$barrier=Barrier::attach($options['evidence-root'],$options['phase']);
		$session=Session::connect($packet['allocation'],null,['case'=>$packet['case'],'source_heads'=>$packet['source_heads'],'runtime_sha256'=>$runtime]);
		try { $worker=\YSCartEcpay\Tests\Live\SubscriptionSqlWorker::run($packet,$session,null,$barrier); } finally { $session->close(); }
		$base=$barrier->path().'/'.strtolower($packet['case'].'-'.$packet['role']);
		$stderr=$base.'.stderr.txt';
		if(!is_file($stderr) || 0!==filesize($stderr)) { throw new SubscriptionSqlFailure('worker_diagnostics_invalid'); }
		$worker['diagnostics']['stderr']=['path'=>realpath($stderr),'bytes'=>0,'sha256'=>hash_file('sha256',$stderr)];
		$ref=\YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::persist($barrier->path(),strtolower($packet['case'].'-'.$packet['role']).'-worker.json',$worker);
		$protocol=['version'=>1,'phase'=>$packet['phase'],'case'=>$packet['case'],'role'=>$packet['role'],'scope'=>'MYSQLI WORKER','token_digest'=>hash('sha256',$packet['token']),'connection_attempts'=>Session::connectionAttempts(),'sql_execution'=>'EXECUTED','receipt'=>$ref];
		unset($packet); echo json_encode($protocol,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; exit(0);
	}
	if ( 'ipc-worker' === ( $options['mode'] ?? '' ) ) {
		require_once __DIR__ . '/helpers/SubscriptionSqlWorker.php';
		if ( ! \YSCartEcpay\Tests\Live\SubscriptionSqlEvidence::exactKeys( $options, [ 'driver','mode','role' ] ) ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$private = stream_get_contents( STDIN, 32769 );
		if ( ! is_string( $private ) || strlen( $private ) > 32768 ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$packet = \YSCartEcpay\Tests\Live\SubscriptionSqlWorker::validatePacket( json_decode( $private, true ) );
		if ( $options['role'] !== $packet['role'] ) { throw new SubscriptionSqlFailure( 'worker_packet_invalid' ); }
		$protocol = \YSCartEcpay\Tests\Live\SubscriptionSqlWorker::protocol( $packet );
		unset( $packet, $private );
		echo json_encode( $protocol, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		exit( 0 );
	}
	if ( isset( $options['role'] ) ) { throw new SubscriptionSqlFailure( 'argument_unknown' ); }
	if ( ! in_array( $options['mode'] ?? '', [ 'preflight', 'capture-ddl', 'execute' ], true ) ) { throw new SubscriptionSqlFailure( 'mode_unknown' ); }
	if('execute'===$options['mode']) {
		$runPath=getenv('YS_ECPAY_SQL_RUN');
		if(false===$runPath || ''===$runPath || !is_file($runPath)) { throw new SubscriptionSqlFailure('sql_execution_not_authorized_in_checkpoint'); }
		require_once __DIR__.'/helpers/SubscriptionSqlController.php';
		$run=json_decode((string)file_get_contents($runPath),true);
		if(!is_array($run) || !is_array($run['allocations']??null) || ($run['phase']??null)!==($options['phase']??null)) { throw new SubscriptionSqlFailure('controller_packet_invalid'); }
		$sources=Fixture::inspectSources(['core'=>getenv('YS_CORE_ROOT'),'ecpay'=>getenv('YS_ECPAY_ROOT'),'affiliate'=>getenv('YS_AFFILIATE_ROOT')]);
		$environments=[];
		foreach($run['allocations'] as $case=>$allocation) { $allocation=\YSCartEcpay\Tests\Live\SubscriptionSqlAllocation::validate($allocation); $environments[$case]=['YS_TEST_MYSQL_DSN'=>getenv('YS_TEST_MYSQL_DSN'),'YS_TEST_MYSQL_DB'=>getenv('YS_TEST_MYSQL_DB'),'YS_TEST_MYSQL_USER'=>getenv('YS_TEST_MYSQL_USER'),'YS_ECPAY_SQL_PREFIX'=>$allocation['prefix']]; }
		$admitted=\YSCartEcpay\Tests\Live\SubscriptionSqlController::admit($run,$environments,$sources);
		// Parent connection counts cannot prove the aggregate work of admitted workers.
		$result['sql_execution']='UNPROVEN'; $result['sql_statements']=null;
		$execution=\YSCartEcpay\Tests\Live\SubscriptionSqlController::runMysql($admitted,$options['evidence-root']??'');
		echo json_encode(['success'=>true,'code'=>'mysql_slice_complete','connection_attempts'=>Session::connectionAttempts(),'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; exit(0);
	}
	$allocationPath = (string) getenv( 'YS_ECPAY_SQL_ALLOCATION' );
	if ( '' === $allocationPath || ! is_file( $allocationPath ) ) { throw new SubscriptionSqlFailure( 'allocation_required' ); }
	$allocation = \YSCartEcpay\Tests\Live\SubscriptionSqlAllocation::validate( json_decode( (string) file_get_contents( $allocationPath ), true ) );
	$dsn = (string) getenv( 'YS_TEST_MYSQL_DSN' );
	$database = (string) getenv( 'YS_TEST_MYSQL_DB' );
	$user = (string) getenv( 'YS_TEST_MYSQL_USER' );
	$prefix = (string) getenv( 'YS_ECPAY_SQL_PREFIX' );
	if ( '' === $dsn ) { throw new SubscriptionSqlFailure( 'dsn_required' ); }
	if ( '' === $database ) { throw new SubscriptionSqlFailure( 'database_required' ); }
	if ( '' === $user ) { throw new SubscriptionSqlFailure( 'user_required' ); }
	if ( 1 !== preg_match( '/\A127\.0\.0\.1:([1-9][0-9]{0,4})\z/', $dsn, $match ) || (int) $match[1] > 65535 ) { throw new SubscriptionSqlFailure( 'dsn_invalid' ); }
	if ( 1 !== preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $database ) ) { throw new SubscriptionSqlFailure( 'database_invalid' ); }
	Session::assertPrefix( $prefix );
	if ( '127.0.0.1' !== ( $allocation['host'] ?? null ) || (int) $match[1] !== ( $allocation['port'] ?? null )
		|| $database !== ( $allocation['database'] ?? null ) || $user !== ( $allocation['user'] ?? null ) || $prefix !== ( $allocation['prefix'] ?? null ) ) { throw new SubscriptionSqlFailure( 'allocation_mismatch' ); }
	$roots = [ 'core' => (string) getenv( 'YS_CORE_ROOT' ), 'ecpay' => (string) getenv( 'YS_ECPAY_ROOT' ), 'affiliate' => (string) getenv( 'YS_AFFILIATE_ROOT' ) ];
	if ( in_array( '', $roots, true ) ) { throw new SubscriptionSqlFailure( 'pair_root_required' ); }
	$sources = Fixture::inspectSources( $roots );
	$products = Fixture::loadProduct( $sources );
	$helpers = Fixture::helperReceipt( __DIR__ . '/helpers' );
	$barrier = Barrier::createPhase( $options['evidence-root'] ?? '', $options['phase'] ?? '' );
	$result += [ 'sources' => $sources, 'products' => $products, 'helpers' => $helpers, 'evidence_directory' => $barrier->path(), 'allocation_kind' => $allocation['kind'] ];
	$result['success'] = true;
	$result['code'] = 'offline_preflight_ready';
	if ( 'capture-ddl' === $options['mode'] ) {
		$ddl = Fixture::captureDdl( $prefix );
		$path = $barrier->path() . '/ddl-capture.json';
		file_put_contents( $path, json_encode( $ddl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
		$result['code'] = 'ddl_captured_not_executed';
		$result['ddl_path'] = $path;
		$result['ddl_sha256'] = hash_file( 'sha256', $path );
	}
	$rc = 0;
} catch ( SubscriptionSqlFailure $error ) { $result['success'] = false; $result['code'] = $error->getMessage(); }
catch ( Throwable $error ) { $result['success'] = false; $result['code'] = 'harness_failure'; }
$result['connection_attempts'] = Session::connectionAttempts();
if('mysql-worker'===($options['mode']??null) && $result['connection_attempts']>0) {
	$result['sql_execution']='UNPROVEN';
	if(isset($session,$barrier,$packet)) {
		$result['sql_statements']=$session->dispatches;
		try { $result=array_replace($result,$session->failureEvidence($barrier->path(),strtolower($packet['case'].'-'.$packet['role']).'-failed-worker.json')); }
		catch(Throwable $persistenceError) { $result['failure_trace_unavailable']=true; }
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
exit( $rc );
