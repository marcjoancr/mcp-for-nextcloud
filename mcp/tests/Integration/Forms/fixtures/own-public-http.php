<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
use OCA\Forms\Db\Submission;
use OCA\Forms\Db\SubmissionMapper;
use OCA\Forms\Db\Answer;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Share;
use OCA\Forms\Db\ShareMapper;
$stage='bootstrap';$checks=[];$admin=null;$owner=null;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $nonce=bin2hex(random_bytes(5));$ownerUid='forms-roles-'.$nonce.'-owner';$readerUid='forms-roles-'.$nonce.'-reader';
    $ownerPassword=bin2hex(random_bytes(24));$readerPassword=bin2hex(random_bytes(24));
    $users=Server::get(\OCP\IUserManager::class);$owner=$users->createUser($ownerUid,$ownerPassword);$reader=$users->createUser($readerUid,$readerPassword);
    $session=Server::get(\OCP\IUserSession::class);$session->setUser($owner);
    $container=(new \OCA\Forms\AppInfo\Application())->getContainer();$api=$container->get(\OCA\Forms\Controller\ApiController::class);
    $form=$api->newForm()->getData()['id'];$api->updateForm($form,['submitMultiple'=>true,'allowEditSubmissions'=>true,'confirmationEmailEnabled'=>false]);
    $questions=[];for($i=1;$i<=61;$i++)$questions[]=$api->newQuestion($form,'short',null,'Field '.$i)->getData()['id'];
    $seed=static function(int $id,string $uid,array $questions):array {
        $submission=new Submission();$submission->setFormId($id);$submission->setUserId($uid);$submission->setTimestamp(time());
        $submission=Server::get(SubmissionMapper::class)->insert($submission);$ids=[];
        foreach($questions as $qid) {$answer=new Answer();$answer->setSubmissionId($submission->getId());$answer->setQuestionId($qid);$answer->setText('value-'.$qid);$ids[]=Server::get(AnswerMapper::class)->insert($answer)->getId();}
        return [$submission->getId(),$ids];
    };
    [$ownId,$answerIds]=$seed($form,$readerUid,$questions);[$ownerId]=$seed($form,$ownerUid,[$questions[0]]);
    $share=new Share();$share->setFormId($form);$share->setShareType(0);$share->setShareWith($readerUid);$share->setPermissions(['submit']);
    $share=Server::get(ShareMapper::class)->insert($share);Server::get(ShareMapper::class)->delete($share);
    require '/tmp/origin-fixture.php';
    foreach([[$ownerUid,$owner],[$readerUid,$reader]] as [$uid,$user]) {$session->setUser($user);formsNativeOrigin($uid,'forms_read_own_responses',['id'=>$form]);}
    $session->setUser($owner);
    $rpc=static function(string $uid,string $password,string $name,array $args=[]):array {
        $curl=curl_init('http://127.0.0.1/index.php/apps/mcp/');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],
            CURLOPT_USERPWD=>$uid.':'.$password,CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args===[]?(object)[]:$args]],JSON_THROW_ON_ERROR)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode((string)$body,true);
        if($status!==200||!is_array($data))throw new RuntimeException('Local MCP transport failed.');
        if(isset($data['error'])) {
            if(($data['error']['code']??null)===-32602)return ['isError'=>true];
            throw new RuntimeException('Unexpected MCP protocol error.');
        }
        return $data['result'];
    };
    $call=static function(string $uid,string $password,string $name,array $args=[])use($rpc):array {
        $result=$rpc($uid,$password,$name,$args);if($result['isError']??false)throw new RuntimeException('MCP workflow refused '.$name);
        return $result['structuredContent']??json_decode($result['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    };
    $deny=static function(string $uid,string $password,string $name,array $args=[])use($rpc):void {
        if(!(($rpc($uid,$password,$name,$args)['isError']??false)))throw new RuntimeException('A forbidden MCP workflow was admitted.');
    };
    $stage='own pages after access removal';
    $a=$call($readerUid,$readerPassword,'forms_read_own_response',['id'=>$form,'submission_id'=>$ownId]);
    $b=$call($readerUid,$readerPassword,'forms_read_own_response',['id'=>$form,'submission_id'=>$ownId,'offset'=>50]);
    if(array_column([...$a['answers'],...$b['answers']],'id')!==$answerIds||count($a['answers'])!==50||count($b['answers'])!==11||$b['hasMore'])throw new RuntimeException('Own answer continuation lost rows.');
    $list=$call($ownerUid,$ownerPassword,'forms_read_own_responses',['id'=>$form]);
    if(array_column($list['submissions'],'id')!==[$ownerId])throw new RuntimeException('Owner own-list included another actor.');
    $deny($readerUid,$readerPassword,'forms_read_own_response',['id'=>$form,'submission_id'=>$ownerId]);
    $api->updateForm($form,['state'=>2]);$call($readerUid,$readerPassword,'forms_read_own_response',['id'=>$form,'submission_id'=>$ownId]);$api->updateForm($form,['state'=>0]);
    $checks['own_answers']=61;$checks['removed_access_and_archive']=true;$checks['strict_owner_filter']=true;
    $stage='MCP grant revocation';
    $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGrant($readerUid,'forms','read_own_responses',false);
    $deny($readerUid,$readerPassword,'forms_read_own_response',['id'=>$form,'submission_id'=>$ownId]);$policy->setGrant($readerUid,'forms','read_own_responses',true);$checks['grant_revocation']=true;
    $stage='public schema';
    $public=$api->newForm()->getData()['id'];$api->updateForm($public,['submitMultiple'=>true,'confirmationEmailEnabled'=>false]);
    $choice=$api->newQuestion($public,'multiple',null,'Choice')->getData()['id'];$api->newOption($public,$choice,['A','B']);
    $ranking=$api->newQuestion($public,'ranking',null,'Ranking')->getData()['id'];$api->newOption($public,$ranking,['First','Second']);
    $grid=$api->newQuestion($public,'grid','radio','Grid')->getData()['id'];$api->newOption($public,$grid,['Row'],'row');$api->newOption($public,$grid,['Column'],'column');
    [$protected]=$seed($public,$ownerUid,[$choice]);
    $link=new Share();$link->setFormId($public);$link->setShareType(3);$link->setShareWith(bin2hex(random_bytes(16)));$link->setPermissions(['submit']);$link=Server::get(ShareMapper::class)->insert($link);$hash=$link->getShareWith();
    $schema=$call($readerUid,$readerPassword,'forms_read_submission_form',['share_hash'=>$hash]);
    if($schema['form']['id']!==$public||count($schema['questions'])!==3||isset($schema['form']['ownerId'])||isset($schema['form']['access'])||isset($schema['form']['shares']))throw new RuntimeException('Public schema scope mismatch.');
    $deny($readerUid,$readerPassword,'forms_read_settings',['id'=>$public]);
    $deny($readerUid,$readerPassword,'forms_read_response',['id'=>$public,'submission_id'=>$protected]);
    $answers=[];$expected=[];
    foreach($schema['questions'] as $question) {
        $options=$call($readerUid,$readerPassword,'forms_read_submission_form',['share_hash'=>$hash,'question_id'=>$question['id']])['question']['options'];
        if($question['type']==='multiple') {$answers[]=['question_id'=>$question['id'],'option_ids'=>[$options[0]['id']]];$expected[$question['id']]='A';}
        elseif($question['type']==='ranking') {$ids=array_reverse(array_column($options,'id'));$answers[]=['question_id'=>$question['id'],'option_ids'=>$ids];$expected[$question['id']]=array_map('strval',$ids);}
        elseif($question['type']==='grid') {$row=array_values(array_filter($options,static fn(array $o):bool=>$o['optionType']==='row'))[0]['id'];$column=array_values(array_filter($options,static fn(array $o):bool=>$o['optionType']==='column'))[0]['id'];$answers[]=['question_id'=>$question['id'],'rows'=>[['row_id'=>$row,'column_ids'=>[$column]]]];$expected[$question['id']]=[(string)$row=>(string)$column];}
    }
    $stage='discovered typed submission';$args=['id'=>$public,'share_hash'=>$hash,'answers'=>$answers];$preview=$call($readerUid,$readerPassword,'forms_submit_response',$args);
    $saved=$call($readerUid,$readerPassword,'forms_submit_response',$args+['confirm'=>true,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id'],'confirm_shared'=>$preview['requiresSharedConfirmation']??false]);
    if(!in_array($saved['operation']['state']??null,['succeeded','partial'],true)||($saved['operation']['references']['database']??null)!=='committed')throw new RuntimeException('Typed public submission was not durably committed.');
    $submission=$saved['operation']['references']['submission_id'];$stored=Server::get(AnswerMapper::class)->findBySubmission($submission);if(count($stored)!==3)throw new RuntimeException('Typed public answers missing.');
    foreach($stored as $answer) {$value=$answer->getText();$expectedValue=$expected[$answer->getQuestionId()];if(is_array($expectedValue))$value=json_decode($value,true,64,JSON_THROW_ON_ERROR);if($value!==$expectedValue)throw new RuntimeException('Discovered answer persistence differs.');}
    $checks['public_schema_and_denials']=true;$checks['typed_public_families']=3;
    $stage='current hash validity';Server::get(ShareMapper::class)->delete($link);$deny($readerUid,$readerPassword,'forms_read_submission_form',['share_hash'=>$hash]);$checks['revoked_hash']=true;
    $stage='admin role per tool';$deny($ownerUid,$ownerPassword,'forms_read_admin_settings');$admin=Server::get(\OCP\IGroupManager::class)->get('admin');$admin->addUser($owner);
    $call($ownerUid,$ownerPassword,'forms_read_admin_settings');$admin->removeUser($owner);$deny($ownerUid,$ownerPassword,'forms_read_admin_settings');$call($ownerUid,$ownerPassword,'forms_read_own_responses',['id'=>$form]);
    $checks['admin_role_per_tool']=true;
    echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
finally {if($admin!==null&&$owner!==null)$admin->removeUser($owner);}
