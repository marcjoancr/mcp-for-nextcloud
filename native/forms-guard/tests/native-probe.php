<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html').'/lib/base.php';
try {
    \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $uid=getenv('MCP_FORMS_NATIVE_OWNER') ?: 'guard-owner';
    \OCP\Server::get(\OCP\IUserSession::class)->setUser(\OCP\Server::get(\OCP\IUserManager::class)->get($uid));
    $app=new \OCA\Forms\AppInfo\Application();$c=$app->getContainer();
    $api=$c->get(\OCA\Forms\Controller\ApiController::class);
    $db=\OCP\Server::get(\OCP\IDBConnection::class);
    $in=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    $args=$in['args']??[];$action=$in['action'];
    require __DIR__.'/origin-fixture.php';
    if(in_array($action,['snapshot','apply','commit_fault','apply_wait'],true)){$args['input']['_mcp_origin']=$args['input']['_mcp_origin']??formsNativeOrigin($uid,$args['action'],$args['input']);}
    if($action==='fixture') {
        $id=$api->newForm()->getData()['id'];
        $api->updateForm($id,['submitMultiple'=>true,'allowEditSubmissions'=>true,'maxSubmissions'=>$args['max']??0]);
        $q1=$api->newQuestion($id,'short',null,'First')->getData()['id'];
        $q2=$api->newQuestion($id,'short',null,'Second')->getData()['id'];
        $result=['id'=>$id,'q1'=>$q1,'q2'=>$q2];
    } elseif($action==='api') {
        $controller=($in['share']??false)?$c->get(\OCA\Forms\Controller\ShareApiController::class):$api;
        $result=$controller->{$in['method']}(...$args)->getData();
    } elseif($action==='snapshot') {
        $result=$c->get(\OCA\Forms\Mcp\Guard::class)->snapshot($uid,$args['action'],$args['input']);
    } elseif($action==='apply') {
        $result=$c->get(\OCA\Forms\Mcp\Guard::class)->apply($uid,$args['action'],$args['input'],$args['revision'],$args['key']);
    } elseif(in_array($action,['commit_fault','apply_wait'],true)) {
        require __DIR__.'/commit-fault.php';
        $fault=formsCommitFault($db,$action==='apply_wait'?'wait':'commit');$fence=new \OCA\Forms\Mcp\Service\FormsRevisionFence($fault);
        $outbox=new \OCA\Forms\Mcp\Service\FormsEffectOutbox($fault,$fence);
        foreach([\OCA\Forms\Mcp\Service\FormsRevisionFence::class=>$fence,\OCA\Forms\Mcp\Service\FormsEffectOutbox::class=>$outbox] as $class=>$service){
            \OC::$server->registerService($class,static fn()=>$service);$c->registerService($class,static fn()=>$service);
        }
        $guard=new \OCA\Forms\Mcp\Guard($fence,new \OCA\Forms\Mcp\Service\ApprovedFormsOperation($fault,$fence,$outbox));
        $result=$guard->apply($uid,$args['action'],$args['input'],$args['revision'],$args['key']);
    } elseif($action==='count_title') {
        $result=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_forms WHERE title = ?',[$args['title']])->fetchOne();
    } elseif($action==='lookup') {
        $result=$c->get(\OCA\Forms\Mcp\Guard::class)->lookup($uid,$args['key'],formsNativeOrigin($uid,'forms_create'));
    } elseif($action==='count') {
        $result=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$args['id']])->fetchOne();
    } elseif($action==='rollback') {
        $id=$args['id'];$q1=$args['q1'];$q2=$args['q2'];
        $api->newSubmission($id,[$q1=>['old first'],$q2=>['old second']]);
        $sub=$c->get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($id)[0]->getId();
        if($db->getDatabaseProvider()==='postgres') {
            $db->executeStatement("CREATE OR REPLACE FUNCTION forms_guard_fail_answer() RETURNS trigger AS \$\$ BEGIN IF NEW.text = 'reject' THEN RAISE EXCEPTION 'injected storage failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
            $db->executeStatement('CREATE TRIGGER forms_guard_fail_answer BEFORE INSERT ON *PREFIX*forms_v2_answers FOR EACH ROW EXECUTE FUNCTION forms_guard_fail_answer()');
        } else {
            $db->executeStatement("CREATE TRIGGER forms_guard_fail_answer BEFORE INSERT ON *PREFIX*forms_v2_answers FOR EACH ROW BEGIN IF NEW.text = 'reject' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected storage failure'; END IF; END");
        }
        $failed=false;
        try {$api->updateSubmission($id,$sub,[$q1=>['new first'],$q2=>['reject']]);}catch(\Throwable){$failed=true;}
        finally {$db->executeStatement($db->getDatabaseProvider()==='postgres'?'DROP TRIGGER forms_guard_fail_answer ON *PREFIX*forms_v2_answers':'DROP TRIGGER forms_guard_fail_answer');}
        $answers=$c->get(\OCA\Forms\Db\AnswerMapper::class)->findBySubmission($sub);
        $texts=array_map(static fn($a)=>$a->getText(),$answers);sort($texts);
        $result=['failed'=>$failed,'answers'=>$texts];
    } elseif($action==='delay') {
        if($db->getDatabaseProvider()==='postgres') {
            $db->executeStatement('CREATE OR REPLACE FUNCTION forms_guard_delay() RETURNS trigger AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$ LANGUAGE plpgsql');
            $db->executeStatement('CREATE TRIGGER forms_guard_delay BEFORE INSERT ON *PREFIX*forms_v2_submissions FOR EACH ROW EXECUTE FUNCTION forms_guard_delay()');
        } else {$db->executeStatement('CREATE TRIGGER forms_guard_delay BEFORE INSERT ON *PREFIX*forms_v2_submissions FOR EACH ROW SET @forms_guard_delay = SLEEP(1)');}
        $result=true;
    } elseif($action==='undelay') {
        $db->executeStatement($db->getDatabaseProvider()==='postgres'?'DROP TRIGGER forms_guard_delay ON *PREFIX*forms_v2_submissions':'DROP TRIGGER forms_guard_delay');$result=true;
    } elseif($action==='source_drift') {
        $file=(getenv('NEXTCLOUD_ROOT') ?: '/var/www/html').'/custom_apps/forms/lib/Constants.php';
        $original=file_get_contents($file);
        try {file_put_contents($file,$original."\n");$result=$c->get(\OCA\Forms\Mcp\Guard::class)->capabilities()['qualified'];}
        finally {file_put_contents($file,$original);}
    } elseif($action==='origin_missing') {
        $g=$c->get(\OCA\Forms\Mcp\Guard::class);$snap=$g->snapshot($uid,'forms_create',['title'=>'Missing origin']);
        $result=$g->apply($uid,'forms_create',['title'=>'Missing origin'],$snap['revision'],bin2hex(random_bytes(16)));
    } elseif($action==='foreign_actor') {
        $result=$c->get(\OCA\Forms\Mcp\Guard::class)->snapshot('foreign-actor','forms_create',['_mcp_origin'=>formsNativeOrigin($uid,'forms_create')]);
    } elseif($action==='receipt') {
        $result=$db->executeQuery('SELECT receipt, fingerprint FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?',[$args['key']])->fetch();
    } elseif($action==='capabilities') {$result=$c->get(\OCA\Forms\Mcp\Guard::class)->capabilities();}
    else {throw new \InvalidArgumentException('Unknown fixture command');}
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch(\Throwable $error) {echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'class'=>$error::class],JSON_THROW_ON_ERROR)."\n";}
