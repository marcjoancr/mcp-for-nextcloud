<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='fixture';$code=0;$restoreGrant=false;$restoreFiles=false;$restoreSelection=false;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$uid='guard-owner';
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));require '/tmp/origin-fixture.php';
    $id=Server::get(\OCA\Forms\Controller\ApiController::class)->newForm()->getData()['id'];
    formsNativeOrigin($uid,'forms_delete_form',['id'=>$id]);
    $fresh=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsFreshAccess::class);$policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
    $authority=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']];
    $history=['form_id'=>$id,'required_operations'=>['continue_operations'],'files_operations'=>[],'bulk_authority'=>$authority];
    $auth=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);$origins=[];
    foreach(['forms_continue_operation'=>'continue_operations','forms_cancel_operation'=>'cancel_operations','forms_read_result'=>'read_operations'] as $tool=>$operation)$origins[$tool]=$auth->captureHistory($uid,$tool,array_replace($history,['required_operations'=>[$operation]]));
    $checks=[];$stage='native fresh authority control';
    $fresh->assertTool($uid,'forms_continue_operation',['id'=>$id],['historical'=>$history]);$checks['original_authority_control']=true;
    $denied=static function(callable $call):bool {try{$call();return false;}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){return true;}};
    $stage='original Forms grant revocation';$policy->setGrant($uid,'forms','delete_form',false);$restoreGrant=true;
    foreach(['forms_continue_operation'=>'continue_operations','forms_cancel_operation'=>'cancel_operations','forms_read_result'=>'read_operations'] as $tool=>$operation) {
        $refs=array_replace($history,['required_operations'=>[$operation]]);
        $checks[$tool.'_original_grant_denied']=$denied(fn()=>$fresh->assertTool($uid,$tool,['id'=>$id],['historical'=>$refs]));
        $checks[$tool.'_deferred_grant_denied']=$denied(fn()=>$auth->assert($origins[$tool]));
    }
    $policy->setGrant($uid,'forms','delete_form',true);$restoreGrant=false;
    $stage='original Files grant revocation';$policy->setGrant($uid,'files','share',false);$restoreFiles=true;
    $checks['original_files_grant_denied']=$denied(fn()=>$fresh->assertTool($uid,'forms_continue_operation',['id'=>$id],['historical'=>$history]));
    $checks['deferred_original_files_grant_denied']=$denied(fn()=>$auth->assert($origins['forms_continue_operation']));
    $policy->setGrant($uid,'files','share',true);$restoreFiles=false;
    $stage='original selected tool revocation';$selections=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$oldSelection=$selections->get($uid,0);
    $tools=array_values(array_diff($oldSelection['tools'],['forms_delete_form']));$variants=array_intersect_key($oldSelection['variants'],array_flip($tools));
    $selections->save($uid,0,$tools,$oldSelection['revision'],$variants);$restoreSelection=true;
    $checks['original_selection_denied']=$denied(fn()=>$fresh->assertTool($uid,'forms_continue_operation',['id'=>$id],['historical'=>$history]));
    $checks['deferred_original_selection_denied']=$denied(fn()=>$auth->assert($origins['forms_continue_operation']));
    $selection=$selections->get($uid,0);$selections->save($uid,0,$oldSelection['tools'],$selection['revision'],$oldSelection['variants']);$restoreSelection=false;
    $stage='restored original authority';$fresh->assertTool($uid,'forms_continue_operation',['id'=>$id],['historical'=>$history]);$checks['restored_authority_control']=true;
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR);$code=in_array(false,$checks,true)?1:0;
} catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);$code=1;}
finally {
    if($restoreGrant)$policy->setGrant($uid,'forms','delete_form',true);
    if($restoreFiles)$policy->setGrant($uid,'files','share',true);
    if($restoreSelection){$current=$selections->get($uid,0);$selections->save($uid,0,$oldSelection['tools'],$current['revision'],$oldSelection['variants']);}
}
exit($code);
