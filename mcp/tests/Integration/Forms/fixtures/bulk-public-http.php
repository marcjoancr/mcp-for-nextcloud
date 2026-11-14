<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';$checks=[];
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $expectedFile=getenv('MCP_FORMS_BULK_HTTP_EXPECTED');
    if(!$expectedFile||is_link($expectedFile))throw new RuntimeException('An explicit per-attempt evidence file is required.');
    $uid='bulk-http-'.bin2hex(random_bytes(5));
    $expected=['uid'=>$uid,'source_bulk_id'=>null,'restore_bulk_id'=>null,'source_operation_id'=>null,'restore_operation_id'=>null];
    $evidence=@fopen($expectedFile,'x+b');
    if(!is_resource($evidence)||!flock($evidence,LOCK_EX|LOCK_NB)||!chmod($expectedFile,0600))
        throw new RuntimeException('A new writable private evidence file is required before any HTTP effect.');
    $identity=fstat($evidence);
    $saveExpected=static function()use($expectedFile,&$expected,$evidence,$identity):void {
        clearstatcache(true,$expectedFile);$current=@lstat($expectedFile);
        if($current===false||is_link($expectedFile)||$current['dev']!==$identity['dev']||$current['ino']!==$identity['ino'])
            throw new RuntimeException('The current attempt evidence file changed.');
        $bytes=json_encode($expected,JSON_THROW_ON_ERROR);
        if(!rewind($evidence)||!ftruncate($evidence,0)||fwrite($evidence,$bytes)!==strlen($bytes)
            ||!fflush($evidence)||!chmod($expectedFile,0600))
            throw new RuntimeException('The current attempt evidence could not be saved; no further HTTP effect may start.');
        $readback=@file_get_contents($expectedFile);
        if(!is_string($readback)||!hash_equals($bytes,$readback)||json_decode($readback,true,8,JSON_THROW_ON_ERROR)!==$expected)
            throw new RuntimeException('The saved evidence does not match the current HTTP attempt.');
    };
    $saveExpected();
    if(getenv('MCP_FORMS_BULK_HTTP_EVIDENCE_ONLY')==='1') {
        $expected['source_bulk_id']=str_repeat('a',64);$expected['restore_bulk_id']=str_repeat('b',64);
        $expected['source_operation_id']='source-operation';$expected['restore_operation_id']='restore-operation';$saveExpected();
        echo json_encode(['ok'=>true,'evidence_only'=>true],JSON_THROW_ON_ERROR);exit(0);
    }
    $password=bin2hex(random_bytes(24));$user=Server::get(\OCP\IUserManager::class)->createUser($uid,$password);
    Server::get(\OCP\IUserSession::class)->setUser($user);require '/tmp/origin-fixture.php';
    $api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$questions=[];
    for($i=0;$i<11;$i++)$questions[]=$api->newQuestion($id,'short',null,'Bulk field '.$i)->getData()['id'];
    \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$uid,$questions):void {
        for($i=0;$i<103;$i++) {
            $s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time());$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);
            foreach($questions as $j=>$qid) {$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setText('answer-'.$i.'-'.$j);Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);}
        }
    });
    formsNativeOrigin($uid,'forms_delete_responses',['id'=>$id]);
    $rpc=static function(string $name,array $args)use($uid,$password):array {
        $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$uid.':'.$password,
            CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],
            CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
        $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);
        if($http!==200||!is_array($r))throw new RuntimeException('Bulk HTTP transport failed.');
        if(isset($r['error']))return ['isError'=>true,'content'=>[['text'=>'Protocol refused request']]];
        return $r['result'];
    };
    $call=static function(string $name,array $args)use($rpc):array {
        $r=$rpc($name,$args);if($r['isError']??false)throw new RuntimeException('Bulk workflow refused '.$name.': '.($r['content'][0]['text']??''));
        return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    };
    $confirm=static function(string $name,array $args,array $preview)use($call):array {
        return $call($name,$args+['confirm'=>true,'confirm_shared'=>$preview['requiresSharedConfirmation']??false,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']]);
    };
    $db=Server::get(\OCP\IDBConnection::class);
    $count=static fn(string $table,int $form):int=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*'.$table.' WHERE form_id = ?',[$form])->fetchOne();
    $stage='complete initial preview';$args=['id'=>$id];$preview=$call('forms_delete_responses',$args);
    if(($preview['impact']['bulk']['counts']['submissions']??null)!==103||($preview['impact']['bulk']['counts']['answers']??null)!==1133)throw new RuntimeException('Complete scaled deletion impact is missing.');
    $checks['complete_scaled_preview']=true;
    $stage='initial confirmed start';$before=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk')->fetchOne();
    $started=$confirm('forms_delete_responses',$args,$preview);$original=$started['operation']['operation_id'];
    if(($started['operation']['state']??null)!=='pending'||!isset($started['operation']['references']['bulk_id']))throw new RuntimeException('Initial native bulk admission did not become pending.');
    $expected['source_operation_id']=$original;$expected['source_bulk_id']=$started['operation']['references']['bulk_id'];$saveExpected();
    $confirm('forms_delete_responses',$args,$preview);
    if((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk')->fetchOne()!==$before+1||$count('forms_v2_submissions',$id)!==103)throw new RuntimeException('Duplicate start replayed or initial admission deleted rows.');
    $checks['start_and_no_replay']=true;
    $stage='original grant revocation';$policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGrant($uid,'forms','delete_all_responses',false);
    try {if(!($rpc('forms_continue_operation',['target_operation_id'=>$original])['isError']??false))throw new RuntimeException('Revoked original grant admitted a control.');}
    finally {$policy->setGrant($uid,'forms','delete_all_responses',true);}
    $checks['original_grant_denied']=true;
    $drive=static function(string $target)use($call,$confirm):array {
        for($i=0;$i<100;$i++) {
            $args=['target_operation_id'=>$target];$p=$call('forms_continue_operation',$args);$r=$confirm('forms_continue_operation',$args,$p);
            if(!isset($r['data']['bulk_id'],$r['data']['phase'],$r['data']['version']))throw new RuntimeException('Bulk progress was lost in public projection.');
            if(($r['operation']['state']??null)==='succeeded'&&($r['data']['complete']??false))return [$r,$i+1];
            if(($r['operation']['state']??null)!=='pending'||!($r['data']['continuation']??false))throw new RuntimeException('Native bulk step did not honestly remain pending.');
        }
        throw new RuntimeException('Bounded bulk journey did not finish within fixture budget.');
    };
    $stage='all bounded deletion steps';[$deleted,$deleteSteps]=$drive($original);
    if($count('forms_v2_submissions',$id)!==0||$count('forms_v2_questions',$id)!==11)throw new RuntimeException('Scaled deletion lost schema or retained responses.');
    $backup=$deleted['operation']['references']['backup_id']??null;
    if(!is_string($backup)||!str_starts_with($backup,'bulk_v1:'))throw new RuntimeException('Sealed recovery reference missing.');
    $history=$call('forms_get_operation',['operation_id'=>$deleted['operation']['operation_id']]);
    if(($history['references']['backup_id']??null)!==$backup)throw new RuntimeException('Fresh HTTP history lost sealed recovery.');
    $status=$call('forms_read_result',['target_operation_id'=>$original]);
    if(($status['complete']??false)!==true||($status['references']['backup_id']??null)!==$backup)throw new RuntimeException('Current status by original operation lost completion or backup.');
    $checks['all_rows_deleted_and_recoverable']=true;
    $stage='restore preview and admission';$args=['backup_id'=>$backup];$p=$call('forms_restore',$args);$restoring=$confirm('forms_restore',$args,$p);
    $expected['restore_operation_id']=$restoring['operation']['operation_id'];$expected['restore_bulk_id']=$restoring['operation']['references']['bulk_id']??null;$saveExpected();
    $stage='all bounded restoration steps';[$restored,$restoreSteps]=$drive($restoring['operation']['operation_id']);$newId=$restored['operation']['references']['restored_form_id']??null;
    if(!is_int($newId)||$newId===$id||$count('forms_v2_submissions',$newId)!==103||$count('forms_v2_questions',$newId)!==11)throw new RuntimeException('Scaled restoration omitted native rows.');
    $stage='fresh exact attempt and association verification';$oracle=getenv('MCP_FORMS_BULK_HTTP_FINAL_PROBE')?:__DIR__.'/bulk-http-final-proof.php';
    $process=proc_open([PHP_BINARY,$oracle],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Independent oracle could not start.');
    fclose($pipes[0]);$proof=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$proofExit=proc_close($process);
    $verified=json_decode($proof,true,64,JSON_THROW_ON_ERROR);
    if($proofExit!==0||!($verified['ok']??false))throw new RuntimeException('Independent recovery associations or attempt identity differ.');
    $f=$db->executeQuery('SELECT owner_id,state,access_enum,file_id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$newId])->fetch();
    if($f['owner_id']!==$uid||(int)$f['state']!==1||(int)$f['access_enum']!==0||$f['file_id']!==null||$count('forms_v2_shares',$newId)!==0)throw new RuntimeException('Restored form is not new private closed and unlinked.');
    $checks['complete_private_closed_restore']=true;
    echo json_encode(['ok'=>true,'checks'=>$checks,'delete_steps'=>$deleteSteps,'restore_steps'=>$restoreSteps],JSON_THROW_ON_ERROR);
} catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(1);}
