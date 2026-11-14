<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\{FormsRevisionFence,OriginContext};
$oldGlobal=null;$failed=false;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $global=Server::get(\OCP\IAppConfig::class);$oldGlobal=$global->getValueBool('forms','allowComments',false);$global->setValueBool('forms','allowComments',true);
    $users=Server::get(\OCP\IUserManager::class);$owner=$users->get('guard-owner');
    $uid='collaborator-'.bin2hex(random_bytes(6));$other=$users->createUser($uid,bin2hex(random_bytes(24)));
    $session=Server::get(\OCP\IUserSession::class);$session->setUser($owner);
    $container=(new \OCA\Forms\AppInfo\Application())->getContainer();$api=$container->get(\OCA\Forms\Controller\ApiController::class);
    $form=$api->newForm()->getData();$id=$form['id'];$api->updateForm($id,['allowComments'=>true]);
    $container->get(\OCA\Forms\Controller\ShareApiController::class)->newShare($id,0,$uid,['submit']);
    $manager=Server::get(\OCP\Comments\ICommentsManager::class);$fence=Server::get(FormsRevisionFence::class);$db=Server::get(\OCP\IDBConnection::class);
    $session->setUser($other);$comment=$manager->create('users',$uid,'forms',(string)$id);$comment->setMessage('collaborator')->setVerb('comment');$manager->save($comment);
    $session->setUser($owner);$refused=0;
    foreach (['edit','delete'] as $action) {try {if($action==='edit'){$comment->setMessage('owner takeover');$manager->save($comment);}else{$manager->delete($comment->getId());}}catch(Throwable){$refused++;}}
    $checks=['owner_author_rule'=>$refused===2,'owner_no_change'=>$db->executeQuery('SELECT message FROM *PREFIX*comments WHERE id = ?',[(int)$comment->getId()])->fetchOne()==='collaborator'];
    $admins=Server::get(\OCP\IGroupManager::class)->get('admin');$wasAdmin=$admins->inGroup($owner);$admins->addUser($owner);
    $adminRefused=false;try {$manager->delete($comment->getId());}catch(Throwable){$adminRefused=true;}
    finally {if (!$wasAdmin) {$admins->removeUser($owner);}}
    $checks['administrator_author_rule']=$adminRefused;
    $escape=$manager->create('users','guard-owner','forms',(string)$id);$escape->setMessage('guarded object')->setVerb('comment');$manager->save($escape);
    $escape->setObject('comments_probe',(string)$id);$escapeRefused=false;
    try {$fence->transaction(fn()=> $manager->save($escape));}catch(Throwable){$escapeRefused=true;}
    $checks['existing_form_cannot_escape_fence']=$escapeRefused && $db->executeQuery('SELECT object_type FROM *PREFIX*comments WHERE id = ?',[(int)$escape->getId()])->fetchOne()==='forms';
    $db->executeStatement('UPDATE *PREFIX*comments SET object_type = ? WHERE id = ?',['forms',(int)$escape->getId()]);
    $session->setUser($other);$comment=$manager->get($comment->getId());$comment->setMessage('author edit');$checks['author_edit']=$manager->save($comment)===true;
    $session->setUser($owner);$child=$manager->create('users','guard-owner','forms',(string)$id);$child->setMessage('reply')->setVerb('comment')->setParentId($comment->getId());
    $checks['parent_metadata_other_author']=$manager->save($child)===true && $manager->get($comment->getId())->getChildrenCount()===1;
    $delivered=0;Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCP\Comments\Events\CommentAddedEvent::class,function($e)use(&$delivered){if($e->getComment()->getObjectType()==='forms'){$delivered++;throw new RuntimeException('ambiguous fake handler');}});
    $uncertain=$manager->create('users','guard-owner','forms',(string)$id);$uncertain->setMessage('one shot')->setVerb('comment');$manager->save($uncertain);
    Server::get(\OCA\Forms\Mcp\Comments\CommentsOutbox::class)->drain();
    $checks['unknown_no_replay']=$delivered===1 && $db->executeQuery('SELECT phase FROM *PREFIX*forms_mcp_effects WHERE kind = ? AND entity_id = ?',['comment',(int)$uncertain->getId()])->fetchOne()==='uncertain';
    $before=$delivered;
    $fence->transaction(function()use($manager,$fence,$db,$id){
        $c=$manager->create('users','guard-owner','forms',(string)$id);$c->setMessage('revoked before event')->setVerb('comment');$manager->save($c);
        $fence->afterTransaction(fn()=> $db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET allow_comments = FALSE WHERE id = ?',[$id]));
    });
    $checks['postcommit_native_access_rechecked']=$delivered===$before;
    $db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET allow_comments = TRUE WHERE id = ?',[$id]);
    $before=$delivered;
    require __DIR__.'/origin-fixture.php';
    Server::get(\OCP\IConfig::class)->deleteUserValue('guard-owner','mcp','forms_native_test_ready');
    $wrongInput=['id'=>$id,'fields'=>['title'=>'Unrelated approved operation']];$wrongOrigin=formsNativeOrigin('guard-owner','forms_update',$wrongInput);$wrongRefused=false;
    try {OriginContext::within('guard-owner',$wrongOrigin,'forms_update',$wrongInput,function()use($manager,$id){$c=$manager->create('users','guard-owner','forms',(string)$id);$c->setMessage('unapproved comment')->setVerb('comment');$manager->save($c);});}catch(Throwable){$wrongRefused=true;}
    $checks['actual_sql_requires_original_comment_action']=$wrongRefused;
    $otherForm=$api->newForm()->getData()['id'];$api->updateForm($otherForm,['allowComments'=>true]);
    $wrongInput=['id'=>$id,'message'=>'wrong form'];$wrongOrigin=formsNativeOrigin('guard-owner','forms_add_comment',$wrongInput);$wrongFormRefused=false;
    try {OriginContext::within('guard-owner',$wrongOrigin,'forms_add_comment',$wrongInput,function()use($manager,$otherForm){$c=$manager->create('users','guard-owner','forms',(string)$otherForm);$c->setMessage('unapproved form')->setVerb('comment');$manager->save($c);});}catch(Throwable){$wrongFormRefused=true;}
    $checks['actual_sql_requires_original_form']=$wrongFormRefused;
    $api->deleteForm($otherForm);
    $before=$delivered;
    $input=['id'=>$id,'message'=>'revoked MCP event'];$origin=formsNativeOrigin('guard-owner','forms_add_comment',$input);$input['_mcp_origin']=$origin;
    $guard=Server::get(\OCA\Forms\Mcp\Guard::class);$snapshot=$guard->commentsSnapshot('guard-owner','forms_add_comment',$input);
    $outcome=$guard->commentsApply('guard-owner','forms_add_comment',$input,$snapshot['revision'],bin2hex(random_bytes(16)),function()use($manager,$fence,$id){
        $c=$manager->create('users','guard-owner','forms',(string)$id);$c->setMessage('revoked MCP event')->setVerb('comment');$manager->save($c);
        $fence->afterTransaction(fn()=>Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant('guard-owner','forms','comment',false));
        return ['id'=>$c->getId()];
    });
    $checks['original_mcp_grant_rechecked']=$delivered===$before && $outcome['phases']['database']==='committed';
    Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant('guard-owner','forms','comment',true);
    $selection=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$saved=$selection->get('guard-owner',0);
    $input['_mcp_origin']=formsNativeOrigin('guard-owner','forms_add_comment',$input);
    $snapshot=$guard->commentsSnapshot('guard-owner','forms_add_comment',$input);$before=$delivered;
    $guard->commentsApply('guard-owner','forms_add_comment',$input,$snapshot['revision'],bin2hex(random_bytes(16)),function()use($manager,$fence,$selection,$saved,$id){
        $c=$manager->create('users','guard-owner','forms',(string)$id);$c->setMessage('revoked selected connection')->setVerb('comment');$manager->save($c);
        $fence->afterTransaction(fn()=> $selection->save('guard-owner',0,[],$saved['revision']));return ['id'=>$c->getId()];
    });
    $checks['original_connection_selection_rechecked']=$before===$delivered;
    $selection->save('guard-owner',0,$saved['tools'],$selection->get('guard-owner',0)['revision'],$saved['variants']);
    $api->deleteForm($id);
    $checks['bulk_form_cleanup']=$db->executeQuery('SELECT id FROM *PREFIX*comments WHERE object_type = ? AND object_id = ?',['forms',(string)$id])->fetchOne()===false;
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);$failed=true;}finally{if($oldGlobal!==null){Server::get(\OCP\IAppConfig::class)->setValueBool('forms','allowComments',$oldGlobal);}}
if($failed){exit(1);}
