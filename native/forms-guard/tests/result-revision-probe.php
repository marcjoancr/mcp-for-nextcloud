<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Db\{FormMapper,Submission,SubmissionMapper,Answer,AnswerMapper,QuestionMapper,OptionMapper};
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\FormsRevisionFence;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$case=$request['case'];
    $db=Server::get(\OCP\IDBConnection::class);$uid='guard-owner';
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $container=(new \OCA\Forms\AppInfo\Application())->getContainer();$api=$container->get(\OCA\Forms\Controller\ApiController::class);$guard=$container->get(Guard::class);$fence=Server::get(FormsRevisionFence::class);
    require __DIR__.'/origin-fixture.php';
    Server::get(\OCP\IConfig::class)->deleteUserValue($uid,'mcp','forms_native_test_ready');
    $id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'short',null,'Result question')->getData()['id'];
    $other=$api->newForm()->getData()['id'];$otherQ=$api->newQuestion($other,'short',null,'Other question')->getData()['id'];
    $origin=formsNativeOrigin($uid,'forms_summarize_results',['id'=>$id]);
    $snapshot=fn()=> $guard->snapshot($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin]);
    $token=fn()=> $snapshot()['export_revision']??null;
    $epoch=fn($form)=> (string)($db->executeQuery('SELECT revision FROM *PREFIX*forms_mcp_result_epoch WHERE form_id = ?',[$form])->fetchOne()?:'0');
    $row=function(int $form,?int $question=null)use($q,$id,$otherQ):int {
        $s=new Submission();$s->setFormId($form);$s->setUserId('guard-owner');$s->setTimestamp(1700000000);$s=Server::get(SubmissionMapper::class)->insert($s);
        $a=new Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($question??($form===$id?$q:$otherQ));$a->setText('before');Server::get(AnswerMapper::class)->insert($a);return $s->getId();
    };
    $refused=function(callable $work):bool{try{$work();return false;}catch(Throwable){return true;}};
    $r=[];$initial=$token();
    if($initial===null){$r=['export_revision_available'=>false];}
    elseif($case==='token') {
        $s=$snapshot();$r=['separate'=>is_string($initial)&&strlen($initial)>0&&strlen($initial)<=128&&$initial!==$s['revision'],'numeric_global'=>ctype_digit($s['revision']),'stable'=>$token()===$initial];
    } elseif($case==='unrelated') {
        $old=$fence->revision();$api->updateForm($other,['title'=>'Unrelated changed']);$row($other);
        $job=$container->get(\OCA\Forms\BackgroundJob\UserDeletedJob::class);$job->run(['owner_id'=>'absent-result-source-owner']);
        $r=['global_advanced'=>$old!==$fence->revision(),'source_same'=>$initial===$token()];
    } elseif($case==='edit') {
        $sid=$row($id);$before=$token();$count=Server::get(SubmissionMapper::class)->countSubmissions($id);
        $a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$aid=$a->getId();$a->setText('changed without changing count');Server::get(AnswerMapper::class)->update($a);$after=$token();
        $r=['answer_changed'=>$before!==$after,'same_count'=>$count===Server::get(SubmissionMapper::class)->countSubmissions($id),'same_answer_id'=>$aid===Server::get(AnswerMapper::class)->findBySubmission($sid)[0]->getId()];
        $s=Server::get(SubmissionMapper::class)->findById($sid);$s->setUserId('anon-source');Server::get(SubmissionMapper::class)->update($s);$r['anonymized']=$after!==$token();
    } elseif($case==='rollback') {
        $sid=$row($id);$before=$token();$e=$epoch($id);
        try{$fence->transaction(function()use($sid){$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$a->setText('rolled back');Server::get(AnswerMapper::class)->update($a);throw new RuntimeException('rollback fixture');});}catch(RuntimeException $error){if($error->getMessage()!=='rollback fixture')throw $error;}
        $r=['rollback_source'=>$before===$token(),'rollback_epoch'=>$e===$epoch($id),'rollback_data'=>Server::get(AnswerMapper::class)->findBySubmission($sid)[0]->getText()==='before'];
        $fence->transaction(function()use($sid){foreach(['first','second']as$text){$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$a->setText($text);Server::get(AnswerMapper::class)->update($a);}});
        $r['once']=(int)$epoch($id)===(int)$e+1;$next=$epoch($id);$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$a->setText('next transaction');Server::get(AnswerMapper::class)->update($a);$r['reset_after_commit']=(int)$epoch($id)===(int)$next+1;
    } elseif($case==='mutations') {
        $otherEpoch=$epoch($other);$sid=$row($id);$r['creation']=$initial!==$token()&&$otherEpoch===$epoch($other);
        foreach(['answer_generic','answer_bulk','submission_generic','submission_bulk','submission_by_id']as$mode){
            $sid=$row($id);$before=$token();
            if($mode==='answer_generic'){Server::get(AnswerMapper::class)->delete(Server::get(AnswerMapper::class)->findBySubmission($sid)[0]);}
            if($mode==='answer_bulk'){Server::get(AnswerMapper::class)->deleteBySubmission($sid);}
            if($mode==='submission_generic'){Server::get(SubmissionMapper::class)->delete(Server::get(SubmissionMapper::class)->findById($sid));}
            if($mode==='submission_bulk'){Server::get(SubmissionMapper::class)->deleteByForm($id);}
            if($mode==='submission_by_id'){Server::get(SubmissionMapper::class)->deleteById(Server::get(FormMapper::class)->findById($id),$sid);}
            $r[$mode]=$before!==$token()&&$otherEpoch===$epoch($other);
        }
        Server::get(SubmissionMapper::class)->deleteByForm($id);$empty=$token();Server::get(SubmissionMapper::class)->deleteByForm($id);Server::get(AnswerMapper::class)->deleteBySubmission(2147483647);$r['empty_delete']=$empty===$token();
        $sid=$row($id);$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];Server::get(AnswerMapper::class)->delete($a);$before=$token();Server::get(AnswerMapper::class)->delete($a);$r['generic_zero_answer']=$before===$token();$s=Server::get(SubmissionMapper::class)->findById($sid);Server::get(SubmissionMapper::class)->delete($s);$before=$token();Server::get(SubmissionMapper::class)->delete($s);$r['generic_zero_submission']=$before===$token();
        // Moving a generic submission or answer invalidates both its previous and new source.
        $sid=$row($id);$target=$row($other);$oldId=$epoch($id);$oldOther=$epoch($other);$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$a->setSubmissionId($target);Server::get(AnswerMapper::class)->update($a);$r['answer_move']=$oldId!==$epoch($id)&&$oldOther!==$epoch($other);
        $oldId=$epoch($id);$oldOther=$epoch($other);$s=Server::get(SubmissionMapper::class)->findById($sid);$s->setFormId($other);Server::get(SubmissionMapper::class)->update($s);$r['submission_move']=$oldId!==$epoch($id)&&$oldOther!==$epoch($other);
    } elseif($case==='schema') {
        $check=function(string $key,callable $work)use(&$r,$token){$before=$token();$work();$r[$key]=$before!==$token();};
        $check('question',function()use($q){$v=Server::get(QuestionMapper::class)->findById($q);$v->setText('Rendered question changed');Server::get(QuestionMapper::class)->update($v);});
        $choice=$api->newQuestion($id,'dropdown',null,'Choice')->getData()['id'];$opt=$api->newOption($id,$choice,['One'])->getData()[0]['id'];
        $check('option',function()use($opt){$v=Server::get(OptionMapper::class)->findById($opt);$v->setText('Rendered option changed');Server::get(OptionMapper::class)->update($v);});
        $check('settings',function()use($choice){$v=Server::get(QuestionMapper::class)->findById($choice);$v->setExtraSettings(['shuffleOptions'=>true]);Server::get(QuestionMapper::class)->update($v);});
        $check('active_question_delete',function()use($q){$v=Server::get(QuestionMapper::class)->findById($q);$v->setOrder(0);Server::get(QuestionMapper::class)->update($v);});
        $check('anonymity',fn()=> $api->updateForm($id,['isAnonymous'=>true]));$check('title',fn()=> $api->updateForm($id,['title'=>'Source title']));
        $before=$token();$config=Server::get(\OCP\IConfig::class);$oldTz=$config->getUserValue($uid,'core','timezone','UTC');try{$config->setUserValue($uid,'core','timezone',$oldTz==='Pacific/Auckland'?'Europe/Madrid':'Pacific/Auckland');$r['timezone']=$before!==$token();}finally{$config->setUserValue($uid,'core','timezone',$oldTz);}
        // Read owner projection directly for transfer because the old actor's origin correctly loses admission.
        $metadata=$guard->read($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin]);$before=\OCA\Forms\Mcp\Service\ResultSourceRevision::token($id,$metadata);$f=Server::get(FormMapper::class)->findById($id);$f->setOwnerId('guard-other');Server::get(FormMapper::class)->update($f);$metadata['form']['ownerId']='guard-other';$r['owner']=$before!==\OCA\Forms\Mcp\Service\ResultSourceRevision::token($id,$metadata);
    } elseif($case==='bookkeeping') {
        $form=Server::get(FormMapper::class)->findById($id);$form->setFileId(987654);$form->setFileFormat('csv');Server::get(FormMapper::class)->update($form);$r['association']=$initial===$token();
        $form=Server::get(FormMapper::class)->findById($id);$form->setLastUpdated(time()+100);Server::get(FormMapper::class)->update($form);$r['marker_like']=$initial===$token();
        $r['global_still_numeric']=ctype_digit($snapshot()['revision']);
    } elseif($case==='readonly') {
        $r['lazy_missing']=$epoch($id)==='0';$count=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_result_epoch')->fetchOne();
        $concrete=Server::get(\OC\DB\Connection::class);$logger=new \Doctrine\DBAL\Logging\DebugStack();$old=$concrete->getConfiguration()->getSQLLogger();$concrete->getConfiguration()->setSQLLogger($logger);
        try{$snapshot();$guard->read($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin]);}finally{$concrete->getConfiguration()->setSQLLogger($old);}
        $sql=array_column($logger->queries,'sql');$r['no_effects']=!array_filter($sql,fn($s)=>preg_match('/\b(INSERT|UPDATE|DELETE|FOR UPDATE|pg_advisory_lock|GET_LOCK)\b/i',$s));$r['no_answers']=!array_filter($sql,fn($s)=>preg_match('/FROM\s+"?oc_forms_v2_answers/i',$s));
        $submissionSql=array_values(array_filter($sql,fn($s)=>preg_match('/FROM\s+"?oc_forms_v2_submissions/i',$s)));$r['scalar_count']=$submissionSql!==[]&&!array_filter($submissionSql,fn($s)=>!str_contains(strtoupper($s),'COUNT('));
        $r['still_lazy']=$count===(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_result_epoch')->fetchOne()&&$epoch($id)==='0';
        $r['sql_captured']=$sql!==[];
    } elseif($case==='deferred') {
        $row($id);$interactive=$snapshot();Server::get(\OCP\IUserSession::class)->setUser(null);$deferred=$guard->deferredRead($origin,'snapshot',['id'=>$id]);$metadata=$guard->deferredRead($origin,'forms_results_metadata',['id'=>$id]);
        $r=['same'=>$interactive['export_revision']===$deferred['export_revision']&&$deferred['export_revision']===$metadata['export_revision'],'global'=>$interactive['revision']===$deferred['revision'],'no_worker_actor'=>Server::get(\OCP\IUserSession::class)->getUser()===null];
    } elseif($case==='schema_deletes') {
        $choice=$api->newQuestion($id,'dropdown',null,'Delete schema')->getData()['id'];$opts=$api->newOption($id,$choice,['One','Two'])->getData();$before=$token();
        Server::get(OptionMapper::class)->delete(Server::get(OptionMapper::class)->findById($opts[0]['id']));$r['generic_option']=$before!==$token();$before=$token();
        Server::get(OptionMapper::class)->deleteByQuestion($choice);$r['bulk_option']=$before!==$token();$before=$token();Server::get(QuestionMapper::class)->delete(Server::get(QuestionMapper::class)->findById($choice));$r['generic_question']=$before!==$token();
        $before=$token();Server::get(QuestionMapper::class)->deleteByForm($id);$r['bulk_question']=$before!==$token();$before=$token();Server::get(QuestionMapper::class)->deleteByForm($other);$r['other_schema']=$before===$token();
    } elseif($case==='native_paths') {
        $source=Server::get(FormMapper::class)->findById($id)->read();$source['title']='epoch-import-'.bin2hex(random_bytes(8));$source['questions']=$guard->read($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin])['questions'];
        $source['submissions']=[['userId'=>$uid,'timestamp'=>1700000000,'answers'=>[['questionId'=>$q,'text'=>'Imported one']]],['userId'=>$uid,'timestamp'=>1700000001,'answers'=>[['questionId'=>$q,'text'=>'Imported two']]]];
        $import=new class(json_encode([$source],JSON_THROW_ON_ERROR)) implements \OCP\UserMigration\IImportSource {
            public function __construct(private string $bytes){}
            public function getFileContents(string $path):string{return $this->bytes;}
            public function getFileAsStream(string $path){throw new \LogicException('Not a stream fixture');}
            public function getFolderListing(string $path):array{return [];}
            public function pathExists(string $path):bool{return $path==='forms/forms.json';}
            public function copyToFolder(\OCP\Files\Folder $destination,string $sourcePath):void{throw new \LogicException('No files in fixture');}
            public function getMigratorVersions():array{return ['forms'=>1];}
            public function getMigratorVersion(string $migrator):?int{return $migrator==='forms'?1:null;}
            public function getOriginalUid():string{return 'guard-owner';}
            public function close():void{}
        };
        $beforeEpochCount=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_result_epoch')->fetchOne();$beforeGlobal=$fence->revision();
        $container->get(\OCA\Forms\FormsMigrator::class)->import(Server::get(\OCP\IUserManager::class)->get($uid),$import,new \Symfony\Component\Console\Output\NullOutput());
        $imported=(int)$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE title = ?',[$source['title']])->fetchOne();
        $r['import_committed']=$imported>0&&$epoch($imported)==='1'&&(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_result_epoch')->fetchOne()===$beforeEpochCount+1;
        $r['import_one_global']=(int)$fence->revision()===(int)$beforeGlobal+1;$r['import_other_source_stable']=$initial===$token();$r['import_answers']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON s.id = a.submission_id WHERE s.form_id = ?',[$imported])->fetchOne()===2;
        $owner='epoch-deleted-'.bin2hex(random_bytes(6));$user=Server::get(\OCP\IUserManager::class)->createUser($owner,bin2hex(random_bytes(32)));$deletedId=$api->newForm()->getData()['id'];$deletedQ=$api->newQuestion($deletedId,'short',null,'Deleted question')->getData()['id'];$row($deletedId,$deletedQ);$form=Server::get(FormMapper::class)->findById($deletedId);$form->setOwnerId($owner);Server::get(FormMapper::class)->update($form);$beforeEpoch=$epoch($deletedId);$user->delete();
        $container->get(\OCA\Forms\BackgroundJob\UserDeletedJob::class)->run(['owner_id'=>$owner]);
        $r['deleted_job_epoch']=(int)$epoch($deletedId)===(int)$beforeEpoch+1;$r['deleted_job_data']=$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$deletedId])->fetchOne()===false&&(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$deletedId])->fetchOne()===0;$r['deleted_job_other_source']=$initial===$token();
    } elseif($case==='unchanged') {
        $sid=$row($id);$before=$token();$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];Server::get(AnswerMapper::class)->update($a);$r['unchanged_answer']=$before===$token();$before=$token();$s=Server::get(SubmissionMapper::class)->findById($sid);Server::get(SubmissionMapper::class)->update($s);$r['unchanged_submission']=$before===$token();
    } elseif($case==='metadata_budget') {
        $metadata=$guard->read($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin]);unset($metadata['export_revision'],$metadata['revision']);$beforeBytes=strlen(json_encode($metadata,JSON_THROW_ON_ERROR));
        $question=Server::get(QuestionMapper::class)->findById($q);$question->setDescription(str_repeat('x',1048576-$beforeBytes));Server::get(QuestionMapper::class)->update($question);
        $r['complete_envelope_refused']=$refused(fn()=> $guard->read($uid,'forms_results_metadata',['id'=>$id,'_mcp_origin'=>$origin]));
    } elseif($case==='overflow') {
        $sid=$row($id);foreach(['-1','9223372036854775806']as$bad){$db->executeStatement('UPDATE *PREFIX*forms_mcp_result_epoch SET revision = ? WHERE form_id = ?',[$bad,$id]);$r['read_refused_'.$bad]=$refused($token);$r['write_refused_'.$bad]=$refused(function()use($sid){$a=Server::get(AnswerMapper::class)->findBySubmission($sid)[0];$a->setText('must roll back');Server::get(AnswerMapper::class)->update($a);});$r['data_rollback_'.$bad]=Server::get(AnswerMapper::class)->findBySubmission($sid)[0]->getText()==='before';}
    } else {throw new RuntimeException('Unknown fixture case');}
    echo json_encode(['ok'=>true,'result'=>$r],JSON_THROW_ON_ERROR);
}catch(Throwable $e){echo json_encode(['ok'=>false,'class'=>$e::class,'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR);exit(1);}
