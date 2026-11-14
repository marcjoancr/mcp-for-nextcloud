<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Db\{FormMapper,SubmissionMapper,Submission};
use OCA\Forms\Mcp\Service\{FormsRevisionFence,LinkedMarkers};
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    $guard=Server::get(\OCA\Forms\Mcp\Guard::class);
    if (!method_exists($guard,'linkedClaim')) {echo json_encode(['ok'=>true,'result'=>['native_linked_api'=>false]]);exit;}
    $uid='guard-owner'; $session=Server::get(\OCP\IUserSession::class); $session->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $config=Server::get(\OCP\IConfig::class);$config->deleteUserValue($uid,'mcp','forms_native_test_ready');
    $db=Server::get(\OCP\IDBConnection::class);$fence=Server::get(FormsRevisionFence::class);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $questionType=str_contains($request['case'],'cleanup_')&&str_contains($request['case'],'question')?'file':'short';
    $id=$api->newForm()->getData()['id'];$question=$api->newQuestion($id,$questionType,null,'Linked fixture question')->getData()['id'];
    require __DIR__.'/origin-fixture.php';formsNativeOrigin($uid,'forms_summarize_results',['id'=>$id]);
    // Issue a real persistent OAuth connection, then retain its exact selected tools in the native proof.
    $clients=Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class);
    if (!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled($clients)) {
        Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],null,true,null);
    }
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $redirect=\OCA\Mcp\OAuth\NativeClient::REDIRECT_URIS[0];$resource='http://localhost/apps/mcp/';
    $tokens=Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $authorization=new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\NativeClient::metadata((string)\OCA\Mcp\OAuth\ClientResolver::nativeGeneration($clients)),$redirect,'linked-fixture',$challenge,$resource,'mcp');
    $code=$tokens->consent($authorization,$uid,['forms_summarize_results'],[]);
    $tokens->exchangeCode(['code'=>$code,'code_verifier'=>$verifier,'client_id'=>\OCA\Mcp\OAuth\NativeClient::CLIENT_ID,'redirect_uri'=>$redirect,'resource'=>$resource]);
    $connection=(int)$db->executeQuery('SELECT MAX(id) FROM *PREFIX*mcp_oauth_tokens WHERE user_id = ?',[$uid])->fetchOne();
    $selection=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$saved=$selection->get($uid,$connection);
    $operations=$request['case']==='file_refs'?['link_results','read_response_files']:['link_results'];
    $selection->save($uid,$connection,['forms_link_results_file','forms_unlink_results_file','forms_summarize_results'],$saved['revision'],['forms_link_results_file'=>$operations]);
    $origin=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsOriginProof::class)->create($uid,$connection,'forms_link_results_file',$operations,['form_id'=>$id,'owner_id'=>$uid,'files_operations'=>['read','edit','create']]);
    $cleanup=null;
    if (str_starts_with($request['case'],'cleanup_')) {
        $targetId=str_contains($request['case'],'cross')?$api->newForm()->getData()['id']:$id;
        $targetQuestion=$targetId===$id?$question:$api->newQuestion($targetId,$questionType,null,'Cleanup target')->getData()['id'];
        $submissionIds=[];
        for ($i=0;$i<2;$i++) {
            $submission=new Submission();$submission->setFormId($targetId);$submission->setUserId($uid);$submission->setTimestamp(time());
            $submissionIds[]=Server::get(SubmissionMapper::class)->insert($submission)->getId();
        }
        $cleanup=['form_id'=>$targetId,'question_id'=>$targetQuestion,'submission_ids'=>$submissionIds];
    }
    $metadata=$guard->deferredRead($origin,'forms_results_metadata',['id'=>$id]);
    $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$name='native-linked-'.bin2hex(random_bytes(6)).'.csv';
    if ($request['case']==='managed_folder') {
        $formsFolder=$root->nodeExists('Forms')?$root->get('Forms'):$root->newFolder('Forms');
        $formsFolder->newFolder($id.' - Linked export');$name='Forms/'.$id.' - Linked export/'.$name;
    }
    if ($cleanup!==null) {
        $formsFolder=$root->nodeExists('Forms')?$root->get('Forms'):$root->newFolder('Forms');
        $targetFolder=$formsFolder->newFolder($cleanup['form_id'].' - Retained cleanup');
        $kept=$targetFolder->newFolder((string)$cleanup['submission_ids'][0]);$ordinary=$targetFolder->newFolder((string)$cleanup['submission_ids'][1]);
        if (str_contains($request['case'],'question')) {
            $kept=$kept->newFolder($cleanup['question_id'].' - Files');$ordinary=$ordinary->newFolder($cleanup['question_id'].' - Files');
        }
        if (str_ends_with($request['case'],'_form')) {$ordinary=$formsFolder->newFolder($cleanup['form_id'].' - Ordinary cleanup');}
        $ordinary->newFile('ordinary.txt','ordinary native cleanup');
        $cleanup['ordinary_path']=ltrim($root->getRelativePath($ordinary->getPath()),'/');
        $name=ltrim($root->getRelativePath($kept->getPath()),'/').'/'.$name;
    }
    $file=$root->newFile($name,'before');
    $receipt=static function($file)use($root):array {return ['file_id'=>(int)$file->getId(),'path'=>ltrim($root->getRelativePath($file->getPath()),'/'),'etag'=>(string)$file->getEtag(),'size'=>(int)$file->getSize(),'sha256'=>hash('sha256',$file->getContent())];};
    $input=['id'=>$id,'format'=>'csv','_mcp_origin'=>$origin,'_mcp_link_approval'=>[
        'arguments'=>['id'=>$id,'format'=>'csv','path'=>$name,'overwrite'=>true,'etag'=>$file->getEtag()],
        'native_runtime'=>LinkedMarkers::runtime(),'schema_digest'=>LinkedMarkers::schemaDigest($metadata),'source_revision'=>$metadata['export_revision'],
        'destination'=>['path'=>$name,'file_id'=>(int)$file->getId(),'overwrite'=>true],
    ]];
    $stockProcess=null;$stockPipes=[];
    $writes=0;$writer=function()use(&$writes,$file,$receipt,$request,$id,&$stockProcess,&$stockPipes):array {
        if ($request['case']==='stock_race') {
            $stockProcess=proc_open([PHP_BINARY,__DIR__.'/linked-stock-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$stockPipes);
            fwrite($stockPipes[0],json_encode(['form_id'=>$id]));fclose($stockPipes[0]);
            if (fgets($stockPipes[1])!=="WAITING\n") {throw new RuntimeException('Stock race did not reach native fence');}
        }
        $writes++;$file->putContent('first verified');return $receipt($file);
    };
    $apply=fn($args,$callback)=>$guard->apply($uid,'forms_link_results_file',$args,$fence->revision(),bin2hex(random_bytes(32)),[],$callback);
    $refused=static function(callable $work):bool {try {$work();return false;}catch(Throwable){return true;}};
    $result=[];$case=$request['case'];
    if ($case==='empty_association') {
        $result['empty_authorized']=$guard->linkedAssociation($origin,$id)===[];
        $result['unsigned_refused']=$refused(fn()=>$guard->linkedAssociation([],$id));
        $other=$api->newForm()->getData()['id'];$result['wrong_form_refused']=$refused(fn()=>$guard->linkedAssociation($origin,$other));
    } elseif ($case==='failed_link') {
        $result['bad_receipt_refused']=$refused(fn()=>$apply($input,function()use($writer):array {$r=$writer();$r['sha256']=str_repeat('0',64);return $r;}));
        $result['no_half_link']=Server::get(FormMapper::class)->findById($id)->getFileId()===null;
        $result['no_marker']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_linked WHERE form_id = ?',[$id])->fetchOne()===0;
        $form=Server::get(FormMapper::class)->findById($id);$form->setFileId($file->getId());$form->setFileFormat('csv');Server::get(FormMapper::class)->update($form);
        $result['ui_adoption_refused']=$refused(fn()=>$apply($input,$writer));$result['only_one_callback']=$writes===1;
    } else {
        $linked=$apply($input,$writer);$association=$linked['references']['association_id'];$claimInfo=$guard->linkedAssociation($origin,$id);
        $source=$guard->deferredRead($origin,'forms_results_metadata',['id'=>$id]);
        $mutate=function()use($id):void {$s=new Submission();$s->setFormId($id);$s->setUserId('guard-owner');$s->setTimestamp(time());Server::get(SubmissionMapper::class)->insert($s);};
        $cycle=static fn($claim)=>['job_id'=>hash('sha256','forms-job-v1:'.hash('sha256','forms-linked-cycle-v1:'.$claim['association_id'].':'.$claim['dirty_generation'])),'generation'=>$claim['dirty_generation']];
        if ($cleanup!==null) {
            if (str_contains($case,'cross') || str_ends_with($case,'_unlinked')) {$api->updateForm($id,['fileId'=>null,'fileFormat'=>null]);}
            $concrete=Server::get(\OC\DB\Connection::class);$logger=new \Doctrine\DBAL\Logging\DebugStack();$oldLogger=$concrete->getConfiguration()->getSQLLogger();
            $concrete->getConfiguration()->setSQLLogger($logger);
            if ($case==='cleanup_lookup_failure') {$db->executeStatement('UPDATE *PREFIX*forms_mcp_linked SET file_id = 0 WHERE association_id = ?',[$association]);}
            try {
                if (str_contains($case,'question')) {
                    $api->deleteQuestion($cleanup['form_id'],$cleanup['question_id']);
                    Server::get(\OCA\Forms\BackgroundJob\DeleteQuestionFoldersJob::class)->run(['formId'=>$cleanup['form_id'],'questionId'=>$cleanup['question_id'],'ownerId'=>$uid]);
                } elseif (str_ends_with($case,'_form')) {$api->deleteForm($cleanup['form_id']);}
                else {
                    foreach ($cleanup['submission_ids'] as $submissionId) {$api->deleteSubmission($cleanup['form_id'],$submissionId);}
                }
            } finally {
                $concrete->getConfiguration()->setSQLLogger($oldLogger);
                if ($case==='cleanup_lookup_failure') {$db->executeStatement('UPDATE *PREFIX*forms_mcp_linked SET file_id = ? WHERE association_id = ?',[$file->getId(),$association]);}
            }
            $result['retained_export_preserved']=$root->nodeExists($name)&&$root->get($name)->getId()===$file->getId()&&$root->get($name)->getContent()==='first verified';
            $result['ordinary_cleanup_completed']=$root->nodeExists($cleanup['ordinary_path'])===($case==='cleanup_lookup_failure');
            $lookups=array_values(array_filter(array_column($logger->queries,'sql'),static fn($sql)=>preg_match('/^SELECT.*file_id.*FROM.*forms_mcp_linked/i',$sql)===1));
            $result['global_bounded_lookup']=$lookups!==[]&&!array_filter($lookups,static fn($sql)=>!str_contains($sql,'LIMIT 100')||str_contains($sql,'form_id')||str_contains($sql,'payload'));
        } elseif ($case==='stock_race') {
            $out=stream_get_contents($stockPipes[1]);$err=stream_get_contents($stockPipes[2]);fclose($stockPipes[1]);fclose($stockPipes[2]);
            $result['stock_completed']=proc_close($stockProcess)===0;$stock=json_decode($out,true,16,JSON_THROW_ON_ERROR);
            $result['no_session_switch']=$stock['ok']&&$stock['null_session'];$result['verified_bytes_preserved']=$file->getContent()==='first verified';
        } elseif ($case==='link') {
            $form=Server::get(FormMapper::class)->findById($id);
            $result=['one_write'=>$writes===1,'native_tuple'=>$form->getFileId()===$file->getId()&&$form->getFileFormat()==='csv',
                'signed_origin'=>$claimInfo['origin']===$origin&&isset($claimInfo['origin']['proof']),'stable_source'=>$source['export_revision']===$metadata['export_revision'],
                'clean_idle'=>$guard->linkedClaim($association)===null,'encrypted'=>!str_contains((string)$db->executeQuery('SELECT payload FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne(),$origin['proof'])];
            $revision=$fence->revision();$guard->linkedStatus($uid,$id);$guard->linkedAssociation($origin,$id);$result['read_only']=$revision===$fence->revision();
            $recovered=$guard->apply($uid,'forms_link_results_file',$input,'0',$linked['operation_key'],[],$writer);
            $result['initial_receipt_no_replay']=$writes===1&&$recovered['references']['association_id']===$association;
        } elseif (in_array($case,['file_refs','no_file_refs'],true)) {
            $s=new Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time());$s=Server::get(SubmissionMapper::class)->insert($s);
            $attachment=$root->newFile('linked-ref-'.bin2hex(random_bytes(6)).'.txt','source attachment');
            $answer=new \OCA\Forms\Db\Answer();$answer->setQuestionId($question);$answer->setSubmissionId($s->getId());$answer->setText('attachment');$answer->setFileId($attachment->getId());Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($answer);
            $claim=$guard->linkedClaim($association);$session->setUser(null);
            $read=fn()=>$guard->linkedRead($claim,'forms_list_response_files',['id'=>$id,'submission_id'=>$s->getId(),'limit'=>1]);
            if ($case==='no_file_refs') {$result['retained_permission_required']=$refused($read);}
            else {
                $refs=$read();$result['exact_reference']=count($refs['files'])===1&&$refs['files'][0]['file_id']===$attachment->getId();
                $result['session_null']=$session->getUser()===null;
                $result['foreign_submission_refused']=$refused(fn()=>$guard->linkedRead($claim,'forms_list_response_files',['id'=>$id,'submission_id'=>2147483647]));
                $saved=$selection->get($uid,$connection);$selection->save($uid,$connection,['forms_link_results_file'],$saved['revision'],['forms_link_results_file'=>['link_results']]);
                $result['fresh_variant_refused']=$refused($read);
            }
        } elseif ($case==='unlink') {
            $unlinkOrigin=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsOriginProof::class)->create($uid,$connection,'forms_unlink_results_file',['link_results'],['form_id'=>$id,'owner_id'=>$uid]);
            $approved=['file_id'=>(int)$file->getId(),'file_format'=>'csv','association_id'=>$association,'version'=>$claimInfo['version']];
            $unlink=fn($approval)=>$guard->apply($uid,'forms_unlink_results_file',['id'=>$id,'_mcp_origin'=>$unlinkOrigin,'_mcp_unlink_approval'=>$approval],$fence->revision(),bin2hex(random_bytes(32)),[]);
            $result['stale_tuple_refused']=$refused(fn()=>$unlink(array_replace($approved,['file_id'=>$file->getId()+1])));
            $result['stale_version_refused']=$refused(fn()=>$unlink(array_replace($approved,['version'=>$approved['version']+1])));
            $out=$unlink($approved);$result['unlinked']=$out['state']==='succeeded'&&Server::get(FormMapper::class)->findById($id)->getFileId()===null;
            $result['preserved']=$file->getContent()==='first verified';$result['disabled']=$guard->linkedClaim($association)===null;
        } elseif ($case==='queue') {
            $before=$claimInfo['dirty_generation'];$api->newSubmission($id,[$question=>['Native answer']]);$after=$guard->linkedAssociation($origin,$id);
            $result['coherent_dirty']=$after['dirty_generation']===$before+1;
            $result['no_stock_job']=!Server::get(\OCP\BackgroundJob\IJobList::class)->has(\OCA\Forms\BackgroundJob\SyncSubmissionsWithLinkedFileJob::class,['form_id'=>$id]);
            $result['pending']=in_array($association,$guard->linkedPending(100),true);
        } elseif ($case==='schema') {
            $api->updateQuestion($id,$question,['text'=>'Changed rendered schema']);$after=$guard->linkedAssociation($origin,$id);
            $result['schema_pending']=$after['dirty_generation']>$claimInfo['dirty_generation'];
            $result['new_source']=$guard->deferredRead($origin,'snapshot',['id'=>$id])['export_revision']!==$metadata['export_revision'];
        } elseif ($case==='ui_relink') {
            $api->updateForm($id,['path'=>$name,'fileFormat'=>'csv']);
            $result['marker_retired']=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='unlinked';
            $result['ui_operational']=Server::get(FormMapper::class)->findById($id)->getFileId()===$file->getId();
            $result['no_suppression']=!$fence->transaction(fn()=>LinkedMarkers::suppress($id));$result['old_claim_disabled']=$guard->linkedClaim($association)===null;
        } elseif (in_array($case,['delete','managed_folder'],true)) {
            if ($case==='managed_folder') {$api->updateForm($id,['fileId'=>null,'fileFormat'=>null]);}
            $api->deleteForm($id);$result['disabled']=$guard->linkedClaim($association)===null;
            $result['marker_retired']=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='unlinked';
            $result['preserved']=$file->getContent()==='first verified';
        } elseif (in_array($case,['child','wrong_root','crashed_child'],true)) {
            $mutate();
            $process=proc_open([PHP_BINARY,__DIR__.'/linked-worker-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            fwrite($pipes[0],json_encode(['association_id'=>$association,'mode'=>$case]));fclose($pipes[0]);
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if (proc_close($process)!==0) {throw new RuntimeException('Isolated fixture failed '.$error.' '.$output);}
            if ($case==='crashed_child') {
                $result=['no_ack'=>$output==='','writing_claim_durable'=>$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='writing',
                    'never_reclaimed'=>$guard->linkedClaim($association)===null,'no_output_effect'=>$file->getContent()==='first verified'];
            } else {$child=json_decode($output,true,16,JSON_THROW_ON_ERROR);$result=$child['result'];}
        } elseif ($case==='marker_exhausted') {
            $count=Server::get(SubmissionMapper::class)->countSubmissions($id);$before=$guard->deferredRead($origin,'snapshot',['id'=>$id])['export_revision'];
            $db->executeStatement('UPDATE *PREFIX*forms_mcp_linked SET dirty_generation = 9223372036854775805, completed_generation = 9223372036854775805 WHERE association_id = ?',[$association]);
            $result['overflow_refused']=$refused($mutate);$result['data_rollback']=Server::get(SubmissionMapper::class)->countSubmissions($id)===$count;
            $result['source_rollback']=$guard->deferredRead($origin,'snapshot',['id'=>$id])['export_revision']===$before;
        } elseif ($case==='lease') {
            $mutate();$claim=$guard->linkedClaim($association);$result['claimed']=$claim!==null;$result['overlap_refused']=$guard->linkedClaim($association)===null;
            $next=$guard->linkedCheckpoint($claim,$cycle($claim));$result['stable_token']=$next['lease_token']===$claim['lease_token']&&$next['version']>$claim['version'];
            $result['old_source_callback']=$guard->linkedRead($claim,'snapshot',['id'=>$id])['export_revision']!==$metadata['export_revision'];
            $result['stale_release_refused']=$refused(fn()=>$guard->linkedRelease($claim));
            $db->executeStatement('UPDATE *PREFIX*forms_mcp_linked SET lease_until = 0 WHERE association_id = ?',[$association]);
            $new=$guard->linkedClaim($association);$result['new_lease']=$new['lease_token']!==$next['lease_token'];
            $result['expired_callback_refused']=$refused(fn()=>$guard->linkedRead($next,'snapshot',['id'=>$id]));
            $forged=$new;$forged['origin']['uid']='guard-other';$result['canonical_reload']=$guard->linkedRead($forged,'snapshot',['id'=>$id])['form']['ownerId']===$uid;
        } elseif ($case==='stock') {
            $mutate();$before=$file->getContent();$session->setUser(null);
            $appConfig=Server::get(\OCP\IAppConfig::class);$enabled=$appConfig->getValueString('mcp','enabled');$appConfig->setValueString('mcp','enabled','no');
            try {
                $job=Server::get(\OCA\Forms\BackgroundJob\SyncSubmissionsWithLinkedFileJob::class);$job->run(['form_id'=>$id]);
                $result=['bytes_preserved'=>$before===$file->getContent(),'session_untouched'=>$session->getUser()===null,'suppressed'=>$fence->transaction(fn()=>LinkedMarkers::suppress($id))];
            } finally {$appConfig->setValueString('mcp','enabled',$enabled);}
        } elseif ($case==='lifecycle') {
            $mutate();$claim=$guard->linkedClaim($association);$form=Server::get(FormMapper::class)->findById($id);$form->setOwnerId('guard-other');Server::get(FormMapper::class)->update($form);
            $result['transfer_paused']=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='paused';
            $result['transfer_suppressed']=$fence->transaction(fn()=>LinkedMarkers::suppress($id));$result['old_read_refused']=$refused(fn()=>$guard->linkedRead($claim,'snapshot',['id'=>$id]));
            $form->setOwnerId($uid);Server::get(FormMapper::class)->update($form);$result['no_auto_reactivation']=$guard->linkedClaim($association)===null;
            $api->updateForm($id,['fileId'=>null,'fileFormat'=>null]);$result['native_unlinked']=Server::get(FormMapper::class)->findById($id)->getFileId()===null;
            $result['retired']=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='unlinked';$result['output_preserved']=$file->getContent()==='first verified';
        } elseif ($case==='revoked') {
            $mutate();$saved=$selection->get($uid,$connection);$selection->save($uid,$connection,['forms_summarize_results'],$saved['revision'],[]);
            $result['revoked_refuses']=$guard->linkedClaim($association)===null;$status=$guard->linkedStatus($uid,$id);$result['durable_paused']=$status['state']==='paused';
            $result['private_status']=!array_intersect(['origin','proof','path','etag','sha256','last_verified'],array_keys($status));
            $saved=$selection->get($uid,$connection);$selection->save($uid,$connection,['forms_link_results_file'],$saved['revision'],['forms_link_results_file'=>['link_results']]);$result['no_reactivation']=$guard->linkedClaim($association)===null;
        } elseif ($case==='restart') {
            $mutate();$claim=$guard->linkedClaim($association);$c=$cycle($claim);$claim=$guard->linkedCheckpoint($claim,$c);$mutate();
            $proof=['job_id'=>$c['job_id'],'actor_uid'=>$uid,'version'=>3,'state'=>'cancelled','sealed'=>true,'delivery_started'=>false,'payload'=>['origin'=>$origin,'tool'=>'forms_link_results_file','native_runtime'=>LinkedMarkers::runtime(),
                'last_step'=>['key'=>hash('sha256','forms-linked-retire-v1:'.$association.':'.$c['generation']),'outcome'=>['state'=>'succeeded','references'=>['job_id'=>$c['job_id']]]]]];
            $result['restart']=$guard->linkedRestart($claim,$c,fn()=>$proof);$result['old_lease_retired']=$refused(fn()=>$guard->linkedRead($claim,'snapshot',['id'=>$id]));
            $new=$guard->linkedClaim($association);$nextCycle=$cycle($new);$new=$guard->linkedCheckpoint($new,$nextCycle);
            $result['unproven_paused']=!$guard->linkedRestart($new,$nextCycle,fn()=>[]);$result['pause_durable']=$guard->linkedClaim($association)===null;
        } elseif ($case==='context') {
            $called=0;$callback=function()use(&$called){$called++;};
            $result['initializer_session_refused']=$refused(fn()=>$guard->initializeLinkedFiles($origin));
            $result['session_refused']=$refused(fn()=>$guard->deferredFileBoundary($origin,$file,null,$callback,$callback));
            $session->setUser(null);$result['outside_cycle_refused']=$refused(fn()=>$guard->deferredFileBoundary($origin,$file,null,$callback,$callback));$result['no_effect']=$called===0;
        } elseif (in_array($case,['sync','uncertain','manual','source_changed'],true)) {
            $mutate();$claim=$guard->linkedClaim($association);$c=$cycle($claim);$claim=$guard->linkedCheckpoint($claim,$c);$revision=$guard->linkedRead($claim,'snapshot',['id'=>$id])['export_revision'];
            $session->setUser(null);$key=hash('sha256','forms-linked-write-v1:'.$association.':'.$c['generation']);$count=0;
            $sink=function()use($guard,$origin,$file,$receipt,&$count,$case):array {
                return $guard->deferredFileBoundary($origin,$file,null,static fn()=>null,function()use($file,$receipt,&$count,$case):array {
                    $count++;$file->putContent('recurring verified');
                    if ($case==='uncertain') {throw new RuntimeException('Injected lost storage acknowledgement');}
                    return $receipt($file);
                });
            };
            $run=fn()=>$guard->syncLinkedFile($origin,$association,$claim['version'],$revision,$key,['lease_token'=>$claim['lease_token'],'active_cycle'=>$c,'destination'=>$input['_mcp_link_approval']['destination']],$sink);
            if (in_array($case,['manual','source_changed'],true)) {
                if ($case==='manual') {$file->putContent('manual edit');} else {$mutate();}
                $result['before_effect_refused']=$refused($run);$result['sink_not_called']=$count===0;
                $result['preserved']=$file->getContent()===($case==='manual'?'manual edit':'first verified');
            } elseif ($case==='uncertain') {
                $result['uncertain']=$refused($run);$result['no_replay']=$refused($run)&&$count===1;
                $result['persisted_uncertain']=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()==='write_uncertain';
                $result['no_auto_claim']=$guard->linkedClaim($association)===null;
            } else {
                // A newer queued generation can exist while this older, still-consistent cycle completes.
                $fence->transaction(fn()=>Server::get(LinkedMarkers::class)->dirty($id));$out=$run();$after=$guard->linkedAssociation($origin,$id);
                $result=['verified'=>$out['sha256']===hash('sha256','recurring verified'),'one_sink'=>$count===1,'pending_survives'=>$after['dirty_generation']>$after['last_completed_generation'],
                    'completion'=>$after['last_completed_generation']===$c['generation'],'session_null'=>$session->getUser()===null,'old_sink_refused'=>$refused($run)];
                $session->setUser(Server::get(\OCP\IUserManager::class)->get($uid));$proof=$guard->lookup($uid,$key,$origin);
                $result['durable_cycle_receipt']=$proof!==null&&$proof['phases']['files']==='saved_verified'&&$proof['references']['generation']===$c['generation'];
            }
        }
    }
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    $errors=[];
    do {$errors[]=get_class($error).': '.$error->getMessage().' @ '.basename($error->getFile()).':'.$error->getLine();$error=$error->getPrevious();}while($error!==null);
    echo json_encode(['ok'=>false,'errors'=>$errors],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
