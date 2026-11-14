<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';$checks=[];$beforeGlobal=null;$changedGlobal=false;$exitCode=0;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);
    $actors=[];$nonce=bin2hex(random_bytes(5));
    foreach(['owner','writer','outsider','admin'] as $role) {
        $uid='comment-http-'.$nonce.'-'.$role;$password=bin2hex(random_bytes(24));$user=$users->createUser($uid,$password);
        $actors[$role]=['uid'=>$uid,'password'=>$password,'user'=>$user];
    }
    $owner=$actors['owner'];$writer=$actors['writer'];$outsider=$actors['outsider'];$admin=$actors['admin'];
    Server::get(\OCP\IGroupManager::class)->get('admin')->addUser($admin['user']);$session->setUser($owner['user']);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $id=$api->newForm()->getData()['id'];$api->updateForm($id,['allowComments'=>true]);
    $beforeGlobal=$db->executeQuery('SELECT configvalue,type FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['forms','allowComments'])->fetch();
    Server::get(\OCP\IAppConfig::class)->setValueBool('forms','allowComments',true);$changedGlobal=true;
    $share=new \OCA\Forms\Db\Share();$share->setFormId($id);$share->setShareType(0);$share->setShareWith($writer['uid']);$share->setPermissions(['submit']);
    $share=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($share);
    $adminShare=new \OCA\Forms\Db\Share();$adminShare->setFormId($id);$adminShare->setShareType(0);$adminShare->setShareWith($admin['uid']);$adminShare->setPermissions(['submit']);
    Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($adminShare);
    require '/tmp/origin-fixture.php';
    foreach($actors as $actor) {$session->setUser($actor['user']);formsNativeOrigin($actor['uid'],'forms_list_comments',['id'=>$id]);}
    $session->setUser($owner['user']);
    $rpc=static function(array $actor,string $name,array $args) {
        $curl=curl_init('http://127.0.0.1/index.php/apps/mcp/');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],
            CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],
            CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode((string)$body,true);
        if($status!==200||!is_array($data))throw new RuntimeException('Local comments transport failed.');
        if(isset($data['error'])) {
            if(($data['error']['code']??null)===-32602)return ['isError'=>true];
            throw new RuntimeException('Unexpected comments protocol error.');
        }
        return $data['result'];
    };
    $call=static function(array $actor,string $name,array $args)use($rpc):array {
        $result=$rpc($actor,$name,$args);if($result['isError']??false)throw new RuntimeException('Workflow refused '.$name.': '.($result['content'][0]['text']??''));
        return $result['structuredContent']??json_decode($result['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    };
    $deny=static function(array $actor,string $name,array $args)use($rpc):void {
        if(!($rpc($actor,$name,$args)['isError']??false))throw new RuntimeException('Forbidden comment action admitted: '.$name);
    };
    $write=static function(array $actor,string $name,array $args,bool $shared)use($call):array {
        $preview=$call($actor,$name,$args);
        if(!isset($preview['plan_state'],$preview['operation_id'])||($preview['requiresSharedConfirmation']??null)!==$shared)throw new RuntimeException('Comment approval or shared confirmation is missing.');
        $confirmed=$args+['confirm'=>true,'confirm_shared'=>$shared,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']];
        $result=$call($actor,$name,$confirmed);
        if(!in_array($result['operation']['state']??null,['succeeded','partial'],true)||($result['operation']['references']['database']??null)!=='committed')throw new RuntimeException('Native comment database commitment is unproven.');
        return [$result,$confirmed];
    };
    $stage='owner read on private form';$empty=$call($owner,'forms_list_comments',['id'=>$id]);
    if($empty['comments']!==[])throw new RuntimeException('Unexpected fresh form comments.');
    $deny($outsider,'forms_list_comments',['id'=>$id]);$checks['native_access']=true;
    $stage='MCP author add and original duplicate';
    [$added,$confirmation]=$write($writer,'forms_add_comment',['id'=>$id,'message'=>"MCP original ñ\nsecond line"],true);$commentId=$added['operation']['references']['comment_id'];
    $marker=$db->executeQuery('SELECT marker_datetime FROM *PREFIX*comments_read_markers WHERE object_type = ? AND object_id = ? AND user_id = ?',['forms',(string)$id,$writer['uid']])->fetchOne();
    if($marker===false)throw new RuntimeException('Poster read marker missing.');
    $before=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*comments WHERE object_type = ? AND object_id = ?',['forms',(string)$id])->fetchOne();
    $call($writer,'forms_add_comment',$confirmation);
    if($before!==(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*comments WHERE object_type = ? AND object_id = ?',['forms',(string)$id])->fetchOne())throw new RuntimeException('Duplicate comment confirmation repeated the effect.');
    $checks['add_marker_and_no_replay']=true;
    $stage='read without side effects';$fence=Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class);$revision=$fence->revision();
    $markers=$db->executeQuery('SELECT user_id,marker_datetime FROM *PREFIX*comments_read_markers WHERE object_type = ? AND object_id = ? ORDER BY user_id',['forms',(string)$id])->fetchAll();
    $list=$call($owner,'forms_list_comments',['id'=>$id]);
    if(count($list['comments'])!==1||$list['comments'][0]['id']!==$commentId||$list['comments'][0]['canEdit']||$list['comments'][0]['canDelete'])throw new RuntimeException('Owner inherited another author authority.');
    if($revision!==$fence->revision()||$markers!==$db->executeQuery('SELECT user_id,marker_datetime FROM *PREFIX*comments_read_markers WHERE object_type = ? AND object_id = ? ORDER BY user_id',['forms',(string)$id])->fetchAll())throw new RuntimeException('Comments read changed native state.');
    $checks['reads_and_author_projection']=true;
    $stage='owner cannot edit another author';$deny($owner,'forms_update_comment',['id'=>$id,'comment_id'=>$commentId,'message'=>'owner takeover']);$deny($owner,'forms_delete_comment',['id'=>$id,'comment_id'=>$commentId]);$deny($admin,'forms_update_comment',['id'=>$id,'comment_id'=>$commentId,'message'=>'admin takeover']);$deny($admin,'forms_delete_comment',['id'=>$id,'comment_id'=>$commentId]);$checks['strict_author']=true;
    $stage='complete bounded paging';
    $session->setUser($writer['user']);$manager=Server::get(\OCP\Comments\ICommentsManager::class);
    for($i=0;$i<50;$i++) {$c=$manager->create('users',$writer['uid'],'forms',(string)$id);$c->setMessage('Native paging '.$i);$c->setVerb('comment');if(!$manager->save($c))throw new RuntimeException('Native paging seed failed.');}
    $revision=$fence->revision();$page1=$call($owner,'forms_list_comments',['id'=>$id,'limit'=>50]);$page2=$call($owner,'forms_list_comments',['id'=>$id,'limit'=>50,'offset'=>50]);
    if(count($page1['comments'])!==50||!$page1['hasMore']||$page1['nextOffset']!==50||count($page2['comments'])!==1||$page2['hasMore']||$page2['nextOffset']!==null||count(array_unique(array_column(array_merge($page1['comments'],$page2['comments']),'id')))!==51||$revision!==$fence->revision())throw new RuntimeException('Comment paging was truncated, repeated or changed state.');
    $checks['complete_paging_51']=true;
    $stage='MCP original grant revocation';$policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGrant($writer['uid'],'forms','edit_comments',false);
    $deny($writer,'forms_update_comment',['id'=>$id,'comment_id'=>$commentId,'message'=>'revoked']);
    $policy->setGrant($writer['uid'],'forms','edit_comments',true);$checks['grant_revocation']=true;
    $stage='current native access revoked';
    Server::get(\OCA\Forms\Db\ShareMapper::class)->delete($share);
    $deny($writer,'forms_list_comments',['id'=>$id]);$deny($writer,'forms_add_comment',['id'=>$id,'message'=>'revoked native access']);
    $share=new \OCA\Forms\Db\Share();$share->setFormId($id);$share->setShareType(0);$share->setShareWith($writer['uid']);$share->setPermissions(['submit']);
    $share=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($share);$checks['native_revocation']=true;
    $stage='global switch denial';Server::get(\OCP\IAppConfig::class)->setValueBool('forms','allowComments',false);
    $deny($writer,'forms_list_comments',['id'=>$id]);$deny($writer,'forms_add_comment',['id'=>$id,'message'=>'global disabled']);
    Server::get(\OCP\IAppConfig::class)->setValueBool('forms','allowComments',true);$checks['global_switch']=true;
    $stage='stale original comment';$args=['id'=>$id,'comment_id'=>$commentId,'message'=>'stale overwrite'];$preview=$call($writer,'forms_update_comment',$args);
    $session->setUser($writer['user']);$current=$manager->get($commentId);$current->setMessage('Native concurrent edit');if(!$manager->save($current))throw new RuntimeException('Native concurrent edit failed.');
    $rpc($writer,'forms_update_comment',$args+['confirm'=>true,'confirm_shared'=>true,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']]);
    if($db->executeQuery('SELECT message FROM *PREFIX*comments WHERE id = ?',[(int)$commentId])->fetchOne()!=='Native concurrent edit')throw new RuntimeException('Stale confirmation overwrote changed native comment.');
    $checks['stale_confirmation']=true;
    $stage='author update';$write($writer,'forms_update_comment',['id'=>$id,'comment_id'=>$commentId,'message'=>'MCP changed'],true);
    if($db->executeQuery('SELECT message FROM *PREFIX*comments WHERE id = ?',[(int)$commentId])->fetchOne()!=='MCP changed')throw new RuntimeException('Comment edit did not persist.');
    $stage='form switch denial';$session->setUser($owner['user']);$api->updateForm($id,['allowComments'=>false]);$deny($writer,'forms_list_comments',['id'=>$id]);$deny($writer,'forms_add_comment',['id'=>$id,'message'=>'disabled']);$api->updateForm($id,['allowComments'=>true]);$checks['form_switch']=true;
    $stage='author delete and receipt';[$deleted]=$write($writer,'forms_delete_comment',['id'=>$id,'comment_id'=>$commentId],true);
    if($db->executeQuery('SELECT id FROM *PREFIX*comments WHERE id = ?',[(int)$commentId])->fetchOne()!==false)throw new RuntimeException('Deleted comment remains.');
    $history=$call($writer,'forms_get_operation',['operation_id'=>$deleted['operation']['operation_id']]);
    if(($history['references']['comment_id']??null)!==$commentId||str_contains(json_encode($history),'MCP changed'))throw new RuntimeException('Comment history lost target or leaked content.');
    $checks['update_delete_history']=true;
    echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR);
} catch(Throwable $error) {echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);$exitCode=1;}
finally {
    if($changedGlobal) {
        if($beforeGlobal===false)$db->executeStatement('DELETE FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['forms','allowComments']);
        else $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ?,type = ? WHERE appid = ? AND configkey = ?',[$beforeGlobal['configvalue'],$beforeGlobal['type'],'forms','allowComments']);
    }
}
exit($exitCode);
