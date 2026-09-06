<?php
/** B2 admission regressions. Every connector exercise must fail before a connection attempt. */
declare(strict_types=1);
use YSCartEcpay\Tests\Live\SubscriptionSqlSession as Session;
use YSCartEcpay\Tests\Live\SubscriptionSqlController as Controller;
use YSCartEcpay\Tests\Live\SubscriptionSqlSchema as Schema;
use YSCartEcpay\Tests\Live\SubscriptionSqlEvidence as Evidence;
use YSCartEcpay\Tests\Live\SubscriptionProductSqlFixture as Fixture;
use YSCartEcpay\Tests\Live\SubscriptionSqlFailure;
$helpers = dirname(__DIR__) . '/live/helpers';
require_once $helpers . '/SubscriptionSqlSession.php';
require_once $helpers . '/SubscriptionSqlController.php';
require_once $helpers . '/SubscriptionSqlSchema.php';
$pass=0; $fail=0;
$check=static function(string $name,bool $ok) use(&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$name."\n"; $ok?++$pass:++$fail; };
$reject=static function(callable $call,string $expected) use($check):void {
    $code=''; try { $call(); } catch(SubscriptionSqlFailure $e) { $code=$e->getMessage(); }
    $check($expected.' before connector', $expected===$code && 0===Session::connectionAttempts());
};
$sources=Fixture::inspectSources(['core'=>(string)getenv('YS_CORE_ROOT'),'ecpay'=>(string)getenv('YS_ECPAY_ROOT'),'affiliate'=>(string)getenv('YS_AFFILIATE_ROOT')]);
$heads=array_map(static fn(array $s):string=>$s['head'],$sources);
$grant=['kind'=>'sql-execution','host'=>'127.0.0.1','port'=>9,'database'=>'ecpay_b2_fixture','user'=>'b2_fixture','prefix'=>'ecps_0123456789ab_','expires_at'=>time()+600];
$context=['case'=>'P1','source_heads'=>$heads,'runtime_sha256'=>hash_file('sha256',PHP_BINARY)];
$env=['YS_TEST_MYSQL_DSN'=>'127.0.0.1:9','YS_TEST_MYSQL_DB'=>'ecpay_b2_fixture','YS_TEST_MYSQL_USER'=>'b2_fixture','YS_ECPAY_SQL_PREFIX'=>$grant['prefix']];
$saved=[]; foreach($env as $key=>$value) { $saved[$key]=getenv($key); putenv($key.'='.$value); }
$saved['YS_TEST_MYSQL_PASSWORD']=getenv('YS_TEST_MYSQL_PASSWORD'); putenv('YS_TEST_MYSQL_PASSWORD');
try {
    $available=method_exists(Session::class,'admitExecution');
    $check('real mysqli admission exists separately from capture and never connects by itself',$available);
    if($available) {
        foreach(['P1','P11a','P11b','P11c','P11d','P11e','P11f','P11g','P11h'] as $case) {
            $admitted=Session::admitExecution($grant,array_replace($context,['case'=>$case]));
            $check($case.' admits exact source runtime and allocated tuple without a secret or connection',$admitted===$grant && 0===Session::connectionAttempts());
        }
        foreach(['P3','P4','P5','P6'] as $case) {
            $admitted=null; $code='';
            try { $admitted=Session::admitExecution($grant,array_replace($context,['case'=>$case])); }
            catch(SubscriptionSqlFailure $error) { $code=$error->getMessage(); }
            $check($case.' terminal allocation admits without credentials or a connection',''===$code && $admitted===$grant && 0===Session::connectionAttempts());
        }
        foreach(['P2','P7','P8','P9','P10','P12a','P12b','P11','unknown'] as $case) { $reject(static fn()=>Session::admitExecution($grant,array_replace($context,['case'=>$case])),'mysql_slice_case_not_authorized'); }
        $reject(static fn()=>Session::admitExecution(array_replace($grant,['kind'=>'offline-design']),$context),'sql_execution_allocation_required');
        $reject(static fn()=>Session::admitExecution(array_replace($grant,['expires_at'=>time()-1]),$context),'allocation_invalid');
        $reject(static fn()=>Session::admitExecution(array_replace($grant,['host'=>'localhost']),$context),'allocation_invalid');
        $reject(static fn()=>Session::admitExecution($grant,$context+['connector'=>'not-a-connector']),'execution_context_invalid');
        $reject(static fn()=>Session::admitExecution($grant,array_replace($context,['runtime_sha256'=>str_repeat('0',64)])),'execution_runtime_mismatch');
        $reject(static fn()=>Session::admitExecution($grant,array_replace($context,['source_heads'=>array_replace($heads,['core'=>str_repeat('0',40)])])),'execution_source_mismatch');
        foreach($env as $key=>$value) { putenv($key.'=wrong'); $reject(static fn()=>Session::admitExecution($grant,$context),'execution_environment_mismatch'); putenv($key.'='.$value); }
        $called=0; $callback=static function() use(&$called):void { ++$called; };
        $reject(static fn()=>Session::connect($grant,$callback,$context),'caller_connector_forbidden');
        $check('caller connector is never invoked',0===$called);
        $reject(static fn()=>Session::connect($grant,null,$context),'execution_password_required');
        $reject(static fn()=>Session::connect($grant),'sql_execution_not_authorized_in_checkpoint');
        $run=['version'=>1,'phase'=>'b2-admission-control','cases'=>['P2'],'allocations'=>['P2'=>$grant],'source_heads'=>$heads,'runtime_sha256'=>$context['runtime_sha256'],'driver'=>'adapter'];
        $admitted=Controller::admit($run,$env,$sources);
        $reject(static fn()=>Controller::runMysql($admitted,sys_get_temp_dir()),'mysql_slice_case_not_authorized');
        $run['cases']=['P1']; $run['allocations']=['P1'=>array_replace($grant,['kind'=>'offline-design'])];
        $admitted=Controller::admit($run,$env,$sources);
        $reject(static fn()=>Controller::runMysql($admitted,sys_get_temp_dir()),'sql_execution_allocation_required');
    }
    $serverReady=method_exists(Schema::class,'validateServer');
    $check('server metadata admission exists and rejects capture labels',$serverReady);
    if($serverReady) {
        $row=['version'=>'8.4.11','database_name'=>'ecpay_b2_fixture','connection_id'=>'9101','transaction_isolation'=>'REPEATABLE-READ','autocommit'=>'1','sql_mode'=>'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','time_zone'=>'+00:00','character_set_connection'=>'utf8mb4','collation_connection'=>'utf8mb4_unicode_ci'];
        $check('literal MySQL84 server session fields admit without connecting',Schema::validateServer([$row],'ecpay_b2_fixture')===$row);
        foreach([['version','8.0.41'],['version','11.8.8-MariaDB'],['database_name','foreign'],['autocommit','0'],['connection_id',null],['character_set_connection','latin1'],['sql_mode','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES']] as [$key,$value]) { $reject(static fn()=>Schema::validateServer([array_replace($row,[$key=>$value])],'ecpay_b2_fixture'),'mysql_server_invalid'); }
        $reject(static fn()=>Schema::validateServer([],'ecpay_b2_fixture'),'mysql_server_invalid');
        Fixture::loadProduct($sources);
        $plan=Schema::plan($grant['prefix']); $contracts=[];
        foreach($plan['ddl']['statements'] as $ddl) { $contract=Schema::ddlContract($ddl); $contracts[$contract['table']]=$contract; }
        $check('finite schema admission covers all six actual DDL shapes',6===count($contracts));
        $sub=$contracts[$grant['prefix'].'ys_ec_subscriptions'];
        $check('profile authority retains nullable LONGTEXT and unsigned non-null generation',
            $sub['columns']['fulfillment_profile']===['type'=>'longtext','nullable'=>true,'default'=>null,'auto_increment'=>false,'on_update'=>false,'collation'=>'utf8mb4_unicode_ci']
            && $sub['columns']['fulfillment_profile_generation']===['type'=>'bigint unsigned','nullable'=>false,'default'=>'0','auto_increment'=>false,'on_update'=>false,'collation'=>null]);
        $check('profile money scale hash length and composite index order remain exact',
            'decimal(12,2)'===$sub['columns']['renewal_shipping_total']['type'] && '0.00'===$sub['columns']['renewal_shipping_total']['default']
            && 'char(64)'===$sub['columns']['fulfillment_profile_hash']['type'] && ['status','next_payment_date']===$sub['indexes']['idx_status_next_payment']['columns']);
        $option=$contracts[$grant['prefix'].'options'];
        $check('options identity value and unique name contract remains explicit',
            $option['indexes']===['PRIMARY'=>['unique'=>true,'columns'=>['option_id']],'option_name'=>['unique'=>true,'columns'=>['option_name']]]
            && 'longtext'===$option['columns']['option_value']['type'] && 'no'===$option['columns']['autoload']['default']);
        $reject(static fn()=>Schema::ddlContract(str_replace('ENGINE=InnoDB','ENGINE=MyISAM',$plan['ddl']['statements'][0])),'schema_ddl_unsupported');
        $reject(static fn()=>Schema::ddlContract(str_replace('title VARCHAR(255)','title GEOMETRY',$plan['ddl']['statements'][0])),'schema_ddl_unsupported');
        $capture=Session::forCapture($grant['prefix'],static function():never { throw new RuntimeException('capture must never dispatch'); });
        $reject(static fn()=>Schema::mysql($capture,true),'mysql_session_required');
        $metadata=Schema::metadataPlan($capture,$grant['database']);
        $reads=[]; foreach($metadata['queries'] as $key=>$sql) { $reads[$key]=['sql'=>$sql,'sql_sha256'=>hash('sha256',$sql),'rows'=>'session'===$key?[$row]:[]]; }
        $reject(static fn()=>Schema::validateMetadata($metadata,$reads),'mysql_columns_invalid');
    }
    $oracle=method_exists(Evidence::class,'evaluateMysql');
    $check('SQL evidence has a separate server-aware evaluator',$oracle);
    if($oracle) {
        $verdict=Evidence::evaluateMysql('P1',[],['A'=>['runtime_receipt'=>['scope'=>'MYSQLI EXECUTION']],'B'=>['runtime_receipt'=>['scope'=>'MYSQLI EXECUTION']]],[]);
        $check('scope relabel cannot manufacture SQL acceptance',false===$verdict['matches'] && 'UNPROVEN'===$verdict['sql_execution']);
        $verdict=Evidence::evaluateMysql('P2',[],[],[]);
        $check('SQL evaluator never widens slice to contention',false===$verdict['matches'] && in_array('mysql_slice_case_not_authorized',$verdict['errors'],true));
    }
    // These literal expectations cover the escaping difference observed in real P1 seed SQL.
    // This is a zero-connection oracle control, not another native SQL execution.
    $quote=new ReflectionMethod(Evidence::class,'quote');
    $json='{"contract_version":1,"contract_kind":"physical"}';
    $check('capture JSON quoting remains unchanged',$quote->invoke(null,$json)==="'".$json."'");
    $check('mysqli JSON quoting matches the real P1 literal',
        $quote->invoke(null,$json,true)==='\'{\"contract_version\":1,\"contract_kind\":\"physical\"}\'');
    foreach([["\0","'\\0'"],["\n","'\\n'"],["\r","'\\r'"],["\\","'\\\\'"],["'","'\\''"],['"',"'\\\"'"],["\x1a","'\\Z'"]] as $i=>[$literal,$expected]) {
        $check('mysqli literal escape '.$i.' is exact',$quote->invoke(null,$literal,true)===$expected);
    }
    $check('mysqli UTF-8 scalar bytes remain unchanged',$quote->invoke(null,'門市',true)==="'門市'");
    // Exercise the actual CLI around admission. Missing credentials and -n prevent any connector.
    $cliRoot=sys_get_temp_dir().'/ecpay-b2-parent-'.bin2hex(random_bytes(8)); mkdir($cliRoot);
    $cliRun=['version'=>1,'phase'=>'b2-parent-control','cases'=>['P1'],'allocations'=>['P1'=>$grant],
        'source_heads'=>$heads,'runtime_sha256'=>$context['runtime_sha256'],'driver'=>'adapter'];
    foreach(['before-admission','after-admission'] as $boundary) {
        $packet=$cliRun;
        if('before-admission'===$boundary) { $packet['source_heads']['ecpay']=str_repeat('0',40); }
        $runFile=$cliRoot.'/'.$boundary.'.json'; file_put_contents($runFile,json_encode($packet,JSON_THROW_ON_ERROR));
        $childEnv=getenv(); unset($childEnv['YS_TEST_MYSQL_PASSWORD']); $childEnv['YS_ECPAY_SQL_RUN']=$runFile;
        $out=$cliRoot.'/'.$boundary.'.stdout.txt'; $err=$cliRoot.'/'.$boundary.'.stderr.txt';
        $child=proc_open([PHP_BINARY,'-n',dirname(__DIR__).'/live/live_subscription_product_pair.php',
            '--driver=adapter','--mode=execute','--phase=b2-parent-control','--evidence-root='.$cliRoot],
            [0=>['pipe','r'],1=>['file',$out,'x'],2=>['file',$err,'x']],$pipes,null,$childEnv);
        if(!is_resource($child)) { throw new RuntimeException('parent control process unavailable'); }
        fclose($pipes[0]); $childRc=proc_close($child); $parent=json_decode((string)file_get_contents($out),true);
        $check($boundary.' CLI failure keeps parent connections at zero',
            2===$childRc && 0===filesize($err) && false===($parent['success']??null) && 0===($parent['connection_attempts']??null));
        if('before-admission'===$boundary) {
            $check('pre-admission rejection still proves SQL NOT RUN',
                'controller_source_mismatch'===($parent['code']??null) && 'NOT RUN'===($parent['sql_execution']??null) && 0===($parent['sql_statements']??null));
        } else {
            $check('admitted execution failure never infers aggregate SQL from parent count',
                'execution_password_required'===($parent['code']??null) && 'UNPROVEN'===($parent['sql_execution']??null)
                && array_key_exists('sql_statements',$parent) && null===$parent['sql_statements']);
        }
    }
    // Hand-derived terminal receipt fragments exercise only the existing oracle methods.
    // They contain no connector/schema proof and can never award SQL acceptance.
    $statements=new ReflectionMethod(Evidence::class,'statements');
    $faultProof=new ReflectionMethod(Evidence::class,'faultProof');
    $id=['connection_id'=>'9101','database'=>$grant['database'],'owner_nonce'=>str_repeat('a',32)];
    $ok=['error'=>'','rows'=>[],'affected'=>0];
    $statement=static function(string $kind,string $sql,?string $fault,?array $actual,array $presented,int $sequence=1) use($id):array {
        return ['sequence'=>$sequence,'origin'=>'product','kind'=>$kind,'sql'=>$sql,'sql_sha256'=>hash('sha256',$sql),
            'before_identity'=>$id,'after_identity'=>$id,'sent'=>null!==$actual,'actual'=>$actual,'presented'=>$presented,'fault'=>$fault];
    };
    $preverify=$statement('session-verify',Session::SESSION_SQL,'precommit-unreadable',null,['error'=>'fixture_precommit_unreadable','rows'=>[],'affected'=>false]);
    $fragments=[
        'P3'=>[$preverify],
        'P4'=>[$statement('commit','COMMIT',null,$ok,['error'=>'fixture_ack_lost','rows'=>[],'affected'=>false])],
        'P5'=>[$statement('commit','COMMIT','commit-suppressed',null,['error'=>'fixture_commit_suppressed','rows'=>[],'affected'=>false])],
        'P6'=>[$preverify,$statement('rollback','ROLLBACK','rollback-suppressed',null,['error'=>'fixture_rollback_suppressed','rows'=>[],'affected'=>false],2)],
    ];
    $oracleCheck=static function(string $label,callable $call,?string $expected=null) use($check):void {
        $code=null; try { $call(); } catch(SubscriptionSqlFailure $error) { $code=$error->getMessage(); }
        $check($label,$code===$expected && 0===Session::connectionAttempts());
    };
    foreach($fragments as $case=>$trace) {
        $oracleCheck($case.' exact terminal actual/presented contract',static fn()=>$statements->invoke(null,$case,'A',$trace,true));
        $oracleCheck($case.' terminal fault cannot move to B',static fn()=>$statements->invoke(null,$case,'B',$trace,true),
            'P4'===$case?'statement_presentation_invalid':'statement_result_invalid');
        $oracleCheck($case.' terminal fault cannot move to P1',static fn()=>$statements->invoke(null,'P1','A',$trace,true),
            'P4'===$case?'statement_presentation_invalid':'statement_result_invalid');
        $bad=$trace; $bad[0]['presented']['error']='unrelated_control_error';
        $oracleCheck($case.' unrelated presented error rejected',static fn()=>$statements->invoke(null,$case,'A',$bad,true),'statement_presentation_invalid');
        $bad=$trace; $bad[0]['kind']='read-or-setup';
        $oracleCheck($case.' terminal fault cannot move to another statement kind',static fn()=>$statements->invoke(null,$case,'A',$bad,true),
            'P4'===$case?'statement_presentation_invalid':'statement_result_invalid');
        $bad=$trace; $bad[0]['origin']='observer';
        $oracleCheck($case.' terminal fault cannot move to an observer',static fn()=>$statements->invoke(null,$case,'A',$bad,true),
            'P4'===$case?'statement_presentation_invalid':'statement_result_invalid');
        if('P4'!==$case) {
            $bad=$trace; $bad[0]['actual']=$ok;
            $oracleCheck($case.' unsent fault cannot claim an actual result',static fn()=>$statements->invoke(null,$case,'A',$bad,true),'statement_result_invalid');
        } else {
            $bad=$trace; $bad[0]['sent']=false; $bad[0]['actual']=null;
            $oracleCheck('P4 ack loss requires a genuinely sent COMMIT',static fn()=>$statements->invoke(null,$case,'A',$bad,true),'statement_result_invalid');
        }
    }
    $ordinary=[$statement('commit','COMMIT','unrelated-fault',$ok,$ok)];
    $oracleCheck('mysqli does not accept an arbitrary fault on ordinary SQL',static fn()=>$statements->invoke(null,'P1','A',$ordinary,true),'mysql_statement_invalid');
    $p6=['fault_receipt'=>['case'=>'P6','role'=>'A','trigger_count'=>2,'triggered'=>['precommit-unreadable','rollback-suppressed'],'actual_commit'=>null,'interference'=>null],
        'session_receipts'=>['identity'=>$id,'replacements'=>0,'closes'=>[['sequence'=>2,'reason'=>'close','identity'=>$id,'poisoned'=>true]],'ready'=>false],
        'statement_receipts'=>$fragments['P6'],'readback_receipt'=>null];
    $oracleCheck('P6 A admits exact poison close with no local readback',static fn()=>$faultProof->invoke(null,'P6','A',$p6,true));
    $bad=$p6; $bad['readback_receipt']=[];
    $oracleCheck('P6 A cannot supply a post-close readback',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'mysql_readback_invalid');
    $bad=$p6; $bad['session_receipts']['closes'][0]['identity']['connection_id']='9102';
    $oracleCheck('P6 close must belong to the original physical session',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'poison_proof_invalid');
    $bad=$p6; $bad['session_receipts']['closes'][0]['reason']='controlled-replacement';
    $oracleCheck('P6 close cannot be relabeled as a replacement',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'poison_proof_invalid');
    $bad=$p6; $bad['session_receipts']['closes'][0]['poisoned']=false;
    $oracleCheck('P6 requires poison before close',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'poison_proof_invalid');
    $bad=$p6; $bad['statement_receipts'][]=$statement('session-verify',Session::SESSION_SQL,null,$ok,$ok,3);
    $oracleCheck('P6 rejects every SQL sequence after close',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'poison_proof_invalid');
    $bad=$p6; $bad['fault_receipt']['triggered'][]='rollback-suppressed'; ++$bad['fault_receipt']['trigger_count'];
    $oracleCheck('P6 rejects duplicate fault triggers',static fn()=>$faultProof->invoke(null,'P6','A',$bad,true),'fault_proof_invalid');
    $observer=['fault_receipt'=>['case'=>'P6','role'=>'B','trigger_count'=>0,'triggered'=>[],'actual_commit'=>null,'interference'=>null],
        'session_receipts'=>['identity'=>array_replace($id,['connection_id'=>'9102']),'replacements'=>0,'closes'=>[],'ready'=>true],
        'statement_receipts'=>[],'readback_receipt'=>[]];
    $oracleCheck('P6 B retains ordinary open observer contract',static fn()=>$faultProof->invoke(null,'P6','B',$observer,true));
    $bad=$observer; $bad['readback_receipt']=null;
    $oracleCheck('P6 B cannot omit the final readback',static fn()=>$faultProof->invoke(null,'P6','B',$bad,true),'mysql_readback_invalid');
    $bad=$observer; $bad['session_receipts']['ready']=false;
    $oracleCheck('P6 closed-session exception cannot move to B',static fn()=>$faultProof->invoke(null,'P6','B',$bad,true),'mysql_server_invalid');
    $bad=$p6; $bad['fault_receipt']=['case'=>'P3','role'=>'A','trigger_count'=>1,'triggered'=>['precommit-unreadable'],'actual_commit'=>null,'interference'=>null];
    $oracleCheck('P6 closed-session exception cannot move to P3',static fn()=>$faultProof->invoke(null,'P3','A',$bad,true),'mysql_server_invalid');
    $failureEvidence=method_exists(Session::class,'failureEvidence');
    $check('post-dispatch failure retains partial schema trace with unproven execution',$failureEvidence);
    if($failureEvidence) {
        // The external transport double is available only with mysqli unloaded (-n).
        // It has no connector; the real Session dispatcher and failure persistence run unchanged.
        $check('failure transport control cannot open a mysqli connection',!class_exists('mysqli',false));
        if(!class_exists('mysqli',false)) {
            final class mysqli {
                public int $affected_rows=0;
                public string $error='';
                public function __construct(private string $ddl,private string $read) {}
                public function query(string $sql):bool {
                    if($sql===$this->ddl) { return true; }
                    if($sql===$this->read) { $this->error='synthetic_metadata_read_failure'; return false; }
                    throw new RuntimeException('unplanned failure-control dispatch');
                }
                public function real_escape_string(string $value):string { return addslashes($value); }
                public function close():bool { return true; }
            }
            $ddl=$plan['ddl']['statements'][0]; $read=$metadata['queries'][$grant['prefix'].'ys_ec_products:columns'];
            $db=Session::fromMysqli(new mysqli($ddl,$read),$grant['prefix']);
            $db->observe(static function() use($db,$ddl,$read):void { $db->query($ddl); $db->get_results($read,'ARRAY_A'); },'schema-setup');
            $db->close();
            $scratch=sys_get_temp_dir().'/ecpay-b2-failure-'.bin2hex(random_bytes(8)); mkdir($scratch);
            $failure=$db->failureEvidence($scratch,'p1-a-failed-worker.json');
            $retained=Evidence::read($scratch,$failure['failure_trace']);
            $check('failed SQL reports actual dispatch count and never NOT RUN or GREEN','UNPROVEN'===$failure['sql_execution'] && 2===$failure['sql_statements']);
            $check('partial schema success and subsequent raw failure both survive the error exit',
                2===count($retained['statement_receipts']) && $retained['statement_receipts'][0]['sql']===$ddl && true===$retained['statement_receipts'][0]['sent']
                && $retained['statement_receipts'][0]['actual']===['error'=>'','rows'=>[],'affected'=>0]
                && $retained['statement_receipts'][1]['sql']===$read && $retained['statement_receipts'][1]['actual']===['error'=>'synthetic_metadata_read_failure','rows'=>[],'affected'=>false]
                && false===$retained['session']['ready'] && 1===count($retained['session']['closes']));
            $capture=Session::forCapture($grant['prefix'],static fn():array=>['error'=>'','rows'=>[],'affected'=>0]);
            $reject(static fn()=>$capture->failureEvidence($scratch,'capture-failure.json'),'mysql_session_required');
            echo 'FAILURE_CONTROL_RECEIPT '.json_encode($failure['failure_trace'],JSON_UNESCAPED_SLASHES)."\n";
        }
    }
} finally { foreach($saved as $key=>$value) { putenv(false===$value?$key:$key.'='.$value); } }
$check('entire B2 admission suite made zero connection attempts',0===Session::connectionAttempts());
echo "subscription MySQL slice admission: {$pass} PASS / {$fail} FAIL\n";
exit($fail>0?1:0);
