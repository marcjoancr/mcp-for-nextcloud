<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$restoreScope=false;$oldScope=null;$stage='fixture';$code=0;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$uid='guard-owner';
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));require '/tmp/origin-fixture.php';
    $api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];
    $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
    $home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));
    $recipient='bulk-scope-'.bin2hex(random_bytes(5));Server::get(\OCP\IUserManager::class)->createUser($recipient,bin2hex(random_bytes(24)));
    $native=new \OCA\Forms\Db\Share();$native->setFormId($id);$native->setShareType(0);$native->setShareWith($recipient);$native->setPermissions(['submit','results']);
    $native=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($native);
    $manager=Server::get(\OCP\Share\IManager::class);$share=$manager->newShare();$share->setNode($folder);$share->setShareType(0);$share->setSharedWith($recipient);$share->setSharedBy($uid);$share->setPermissions(1);$share=$manager->createShare($share);
    $db=Server::get(\OCP\IDBConnection::class);$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_shares (form_id,native_share_id,folder_id,files_share_id) VALUES (?,?,?,?)',[$id,$native->getId(),$folder->getId(),$share->getFullId()]);
    formsNativeOrigin($uid,'forms_delete_form',['id'=>$id]);$auth=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);
    $workflow=Server::get(\OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow::class);$guard=Server::get(\OCA\Forms\Mcp\Guard::class);
    $capture=fn(string $tool,array $args,array $snapshot):array=>$auth->capture($uid,$tool,$args,$snapshot);
    $files=fn(array $origin,string $tool,array $manifest)=>$auth->assertFiles($origin,$tool,$manifest);
    $stage='unrestricted native folder-only control';$snapshot=$workflow->preview($uid,'forms_delete_form',['id'=>$id],$guard,$capture,$files);
    if($snapshot['counts']['files']!==0||$snapshot['counts']['managed_shares']!==1)throw new RuntimeException('The case must have no managed files and one real managed share.');
    $oldScope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'files');
    $writer=Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class);$state=Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);
    $writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',[]);$restoreScope=true;
    $stage='excluded folder preview';$deniedPreview=false;
    try {$workflow->preview($uid,'forms_delete_form',['id'=>$id],$guard,$capture,$files);}
    catch(RuntimeException $e){if($e->getMessage()!=='Forms file effect is outside the current Files access')throw $e;$deniedPreview=true;}
    $stage='excluded folder opaque inspection';$deniedInspection=false;
    try {$workflow->inspect($uid,$snapshot['impact']['bulk']['collections']['managed_shares']['cursor'],50,$guard,$capture,$files);}
    catch(RuntimeException $e){if($e->getMessage()!=='Forms file effect is outside the current Files access')throw $e;$deniedInspection=true;}
    echo json_encode(['ok'=>$deniedPreview&&$deniedInspection,'checks'=>['native_folder_only_control'=>true,'excluded_preview_denied'=>$deniedPreview,'excluded_inspection_denied'=>$deniedInspection]],JSON_THROW_ON_ERROR);$code=$deniedPreview&&$deniedInspection?0:1;
} catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);$code=1;}
finally {if($restoreScope)$writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',$oldScope===null?null:$oldScope['items']);}
exit($code);
