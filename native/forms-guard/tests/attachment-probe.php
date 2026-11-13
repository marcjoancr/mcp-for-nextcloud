<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require (getenv('NEXTCLOUD_ROOT')?:'/var/www/html').'/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Guard;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $in=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$a=$in['args']??[];
    $uid=$in['actor']??(getenv('MCP_FORMS_NATIVE_OWNER')?:'guard-owner');Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $app=new \OCA\Forms\AppInfo\Application();$c=$app->getContainer();$guard=$c->get(Guard::class);$api=$c->get(\OCA\Forms\Controller\ApiController::class);
    $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$db=Server::get(\OCP\IDBConnection::class);
    $result=match($in['action']) {
        'capabilities'=>$guard->capabilities(),
        'fixture'=>(function()use($api){$id=$api->newForm()->getData()['id'];$api->updateForm($id,['title'=>'Attachment isolation '.bin2hex(random_bytes(6)),'submitMultiple'=>true,'allowEditSubmissions'=>true]);$q=$api->newQuestion($id,'file',null,'Attachment')->getData()['id'];return ['id'=>$id,'question_id'=>$q];})(),
        'api'=>$api->{$a['method']}(...$a['input'])->getData(),
        'share_api'=>$c->get(\OCA\Forms\Controller\ShareApiController::class)->{$a['method']}(...$a['input'])->getData(),
        'stage'=>(function()use($a,$guard,$uid){$input=['id'=>$a['id'],'question_id'=>$a['question_id']];$snap=$guard->snapshot($uid,'forms_upload_response_files',$input);$stream=fopen('php://memory','w+b');fwrite($stream,$a['content']??'attachment-canary');rewind($stream);$bytes=$a['content']??'attachment-canary';try{return $guard->apply($uid,'forms_upload_response_files',$input,$snap['revision'],bin2hex(random_bytes(16)),$snap['file_manifest']??null,fn()=>$guard->stageFiles($uid,$a['id'],$a['question_id'],'',[['name'=>'proof.txt','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'stream'=>$stream]]));}finally{fclose($stream);}})(),
        'snapshot'=>$guard->snapshot($uid,$a['action'],$a['input']),
        'apply'=>$guard->apply($uid,$a['action'],$a['input'],$a['revision'],$a['key'],$a['manifest']??null),
        'references'=>$guard->read($uid,'forms_list_response_files',$a),
        'stray'=>(function()use($a,$c,$root){$form=$c->get(\OCA\Forms\Db\FormMapper::class)->findById($a['id']);$path=$c->get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);$folder=$root->nodeExists($path)?$root->get($path):$root->newFolder($path);return $folder->newFile('arbitrary.txt','unmanaged canary')->getId();})(),
        'hash'=>(function()use($root,$a){$nodes=$root->getById($a['file_id']);return $nodes===[]?null:hash('sha256',$nodes[0]->getContent());})(),
        'expire'=>(function()use($db,$a){$db->executeStatement('UPDATE *PREFIX*forms_mcp_uploads SET expires = 1 WHERE handle_hash = ?',[hash('sha256',$a['handle'])]);return true;})(),
        'deferred'=>(function()use($c,$a){$c->get(\OCA\Forms\BackgroundJob\DeleteQuestionFoldersJob::class)->run(['formId'=>$a['id'],'questionId'=>$a['question_id'],'ownerId'=>$a['owner_id']??(getenv('MCP_FORMS_NATIVE_OWNER')?:'guard-owner')]);return true;})(),
        'tamper'=>(function()use($root,$a){$node=$root->getById($a['file_id'])[0];$node->putContent('changed');return true;})(),
        'age_staging'=>(function()use($db,$a){$id=$db->executeQuery('SELECT upload_id FROM *PREFIX*forms_mcp_uploads WHERE handle_hash = ?',[hash('sha256',$a['handle'])])->fetchOne();$db->executeStatement('UPDATE *PREFIX*forms_v2_uploaded_files SET created = 1 WHERE id = ?',[$id]);return (int)$db->executeQuery('SELECT file_id FROM *PREFIX*forms_v2_uploaded_files WHERE id = ?',[$id])->fetchOne();})(),
        'cleanup'=>(function()use($c){$c->get(\OCA\Forms\BackgroundJob\CleanupUploadedFilesJob::class)->run([]);return true;})(),
        'new_file_collision'=>(function()use($root){$folder=$root->newFolder('forms-no-overwrite-'.bin2hex(random_bytes(8)));$file=$folder->newFile('original.txt','keep original');$failed=false;try{\OCA\Forms\Mcp\Service\NewFileStorage::create($folder,'original.txt','replacement');}catch(\Throwable){$failed=true;}return ['refused'=>$failed,'sha256'=>hash('sha256',$file->getContent())];})(),
        'quota'=>(function()use($uid,$a){Server::get(\OCP\IUserManager::class)->get($uid)->setQuota($a['value']);return true;})(),
        'file_shares'=>(function()use($db,$a){return $db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?',[$a['id']])->fetchAll();})(),
        'receipt'=>$db->executeQuery('SELECT receipt FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?',[$a['key']])->fetchOne(),
        'create_user'=>(function()use($a){$manager=Server::get(\OCP\IUserManager::class);if (!$manager->get($a['uid'])) {$manager->createUser($a['uid'],bin2hex(random_bytes(24)));}return true;})(),
        default=>throw new \RuntimeException('Unknown attachment probe action'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch(\Throwable $e) {echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'class'=>$e::class,'trace'=>array_map(static fn($f)=>array_intersect_key($f,array_flip(['file','line','function','class'])),$e->getTrace())],JSON_THROW_ON_ERROR)."\n";}
