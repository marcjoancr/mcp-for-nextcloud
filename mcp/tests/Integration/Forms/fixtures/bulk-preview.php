<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $uid='guard-owner';Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    require '/tmp/origin-fixture.php';$api=Server::get(\OCA\Forms\Controller\ApiController::class);
    $id=$api->newForm()->getData()['id'];$question=$api->newQuestion($id,'file',null,'MCP effect preview')->getData()['id'];
    $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
    $home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
    $path=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
    $folder=$home->newFolder($path);$ids=[];$stray=$folder->newFile('unmanaged-keep.txt','keep');
    $stage='101 real managed files';
    \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$question,$uid,$folder,&$ids):void {
        for($i=0;$i<101;$i++) {
            $file=$folder->newFile('preview-'.$i.'.txt','proof '.$i);$ids[]=(int)$file->getId();
            $s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time());$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);
            $a=new \OCA\Forms\Db\Answer();$a->setQuestionId($question);$a->setSubmissionId($s->getId());$a->setFileId($file->getId());$a->setText($file->getName());Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);
        }
    });
    formsNativeOrigin($uid,'forms_delete_form',['id'=>$id]);
    $auth=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);
    $workflow=Server::get(\OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow::class);$guard=Server::get(\OCA\Forms\Mcp\Guard::class);$seen=[];
    $capture=fn(string $tool,array $input,array $snapshot):array=>$auth->capture($uid,$tool,$input,$snapshot);
    $files=function(array $origin,string $tool,array $manifest)use($auth,&$seen):void {$auth->assertFiles($origin,$tool,$manifest);foreach($manifest['files']??[] as $m)$seen[]=$m['file_id'];};
    $db=Server::get(\OCP\IDBConnection::class);$before=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk')->fetchOne();
    $fence=Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);$revision=$fence->revision();$started=microtime(true);
    $stage='MCP complete effect inspection';
    $snapshot=$workflow->preview($uid,'forms_delete_form',['id'=>$id],$guard,$capture,$files);
    $seconds=microtime(true)-$started;
    $checks=['all_managed_scopes'=>count($seen)===101&&count(array_diff($ids,$seen))===0,'unmanaged_not_selected'=>!in_array((int)$stray->getId(),$seen,true)];
    $stage='three opaque MCP inspection pages';$cursor=$snapshot['impact']['bulk']['collections']['files']['cursor'];$received=[];$pages=0;
    do {$page=$workflow->inspect($uid,$cursor,50,$guard,$capture,$files);$received=array_merge($received,array_column($page['items'],'file_id'));$cursor=$page['nextCursor'];$pages++;}while($cursor!==null&&$pages<5);
    $checks['complete_opaque_inspection']=$pages===3&&$received===$ids;
    $checks['read_only']=$revision===$fence->revision()&&$before===(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk')->fetchOne()&&$stray->getContent()==='keep';
    $stage='original grant revocation';
    $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGrant($uid,'forms','delete_form',false);$denied=false;
    try {try {$workflow->inspect($uid,$snapshot['impact']['bulk']['collections']['files']['cursor'],50,$guard,$capture,$files);}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){$denied=true;}}
    finally {$policy->setGrant($uid,'forms','delete_form',true);}
    $checks['original_grant_revocation']=$denied;
    $stage='native source invalidates inspection cursor';$api->updateForm($id,['description'=>'Changed after effect inspection']);$denied=false;
    try {$workflow->inspect($uid,$snapshot['impact']['bulk']['collections']['files']['cursor'],50,$guard,$capture,$files);}
    catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){$denied=true;}
    $checks['changed_source_refused']=$denied;
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'preview_seconds'=>$seconds],JSON_THROW_ON_ERROR);
} catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(1);}
