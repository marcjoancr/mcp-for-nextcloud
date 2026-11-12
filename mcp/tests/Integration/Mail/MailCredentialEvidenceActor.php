<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
use OCP\{Server,IConfig};
final class MailCredentialEvidenceActor {
    private const TOOLS=['mail_add_account','mail_configure_connection','mail_complete_account_setup','mail_read_connection','mail_get_operation','mail_list_operations'];
    private static function data(ToolReply $reply):array {
        if(!$reply->ok())throw new \RuntimeException('Native credential fixture MCP action failed.');
        return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
    public static function prepare(string $uid,?string $provider=null,bool $builtin=false):array {
        if($uid!==MailActor::ALICE)throw new \RuntimeException('Unexpected credential fixture owner.');
        $config=Server::get(IConfig::class);$snapshot=['tools'=>ToolPermissionFixture::capture([$uid],self::TOOLS),'global'=>$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__'),'values'=>[]];
        foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY] as $key)$snapshot['values'][$key]=$config->getUserValue($uid,'mcp',$key,'__missing__');
        $policy=Server::get(GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
        ToolPermissionFixture::assign($uid,array_fill_keys(self::TOOLS,'allow'));
        $token=bin2hex(random_bytes(12));$email='mcp-credential-'.$token.'@example.test';
        $id=MailActor::run($uid,'native-setup-form',['email'=>$email])['account_id'];
        if($provider!==null){
            if(!in_array($provider,['google','microsoft'],true))throw new \RuntimeException('Invalid fixture provider.');
            $native=Server::get('OCA\\Mail\\Service\\AccountService');$account=$native->find($uid,$id);$entity=$account->getMailAccount();$crypto=Server::get(\OCP\Security\ICrypto::class);
            if($builtin){$entity->setInboundHost('imap.gmail.com');file_put_contents('/tmp/mcp-mail-probe-oauth-sync','enabled');}
            $entity->setAuthMethod('xoauth2');$entity->setOauthAccessToken($crypto->encrypt(bin2hex(random_bytes(32))));$entity->setOauthRefreshToken($crypto->encrypt(bin2hex(random_bytes(32))));$entity->setOauthTokenTtl(time()+3600);$native->update($entity);
            $appConfig=Server::get(\OCP\IAppConfig::class);$snapshot['oauth']=[];
            foreach(['google_oauth_client_id','google_oauth_client_secret','microsoft_oauth_tenant_id','microsoft_oauth_client_id','microsoft_oauth_client_secret'] as $key)$snapshot['oauth'][$key]=$appConfig->getValueString('mail',$key,'');
            if($provider==='google')Server::get('OCA\\Mail\\Integration\\GoogleIntegration')->configure('mcp-fixture',bin2hex(random_bytes(32)));
            else Server::get('OCA\\Mail\\Integration\\MicrosoftIntegration')->configure('mcp-fixture','mcp-fixture',bin2hex(random_bytes(32)));
        }
        $profile=Server::get(MailSettingsGateway::class)->readConnection($uid,$id);
        $client=new McpClient(LoginFactory::basic($uid));$args=['account_id'=>$id,'intent'=>[]];
        $p=self::data($client->tool('mail_configure_connection',$args));
        $begin=self::data($client->tool('mail_configure_connection',$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));
        $state=['uid'=>$uid,'provider'=>$provider,'account_id'=>$id,'email'=>$email,'profile_revision'=>$profile['revision'],'setup_operation_id'=>$begin['operation']['operation_id'],'snapshot'=>$snapshot,
            'outgoing'=>array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0))];
        $scope=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class)->scopeKey($uid);
        $flow=Server::get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class)->get($scope,$state['setup_operation_id']);
        $armed=getenv('MCP_IT_MAIL_CREDENTIAL_CANDIDATE')==='1';
        if($armed){
            if(Server::get(\OCA\Mcp\Modules\Mail\Integration\NativeSetupEvidence::class)->available())throw new \RuntimeException('Qualified credentials must use actual MCP handoff, never fixture arming.');
            $native=Server::get('OCA\\Mail\\Authorization\\SetupEvidence');
            $state['native_context_id']=$native->register($uid,$scope,$state['setup_operation_id'],'configure_connection',$id,[],(int)$flow['expires_at']);
            $begin['url']=Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('mail.page.index',['mcpSetup'=>$state['native_context_id']]);
        }elseif(is_string($flow['native_context_id']??null))$state['native_context_id']=$flow['native_context_id'];
        $state['scope']=$scope;
        $path='/tmp/mcp-credential-'.$token.'.json';file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR));chmod($path,0600);
        return ['fixture'=>$token,'account_id'=>$id,'url'=>$begin['url'],'candidate_fixture_armed'=>$armed,'native_fields'=>[
            'accountName'=>$profile['name'],'emailAddress'=>$profile['email'],'imapHost'=>$profile['imap']['host'],'imapPort'=>$profile['imap']['port'],'imapSslMode'=>$profile['imap']['tls'],'imapUser'=>$profile['imap']['user'],
            'smtpHost'=>$profile['smtp']['host'],'smtpPort'=>$profile['smtp']['port'],'smtpSslMode'=>$profile['smtp']['tls'],'smtpUser'=>$profile['smtp']['user'],'authMethod'=>$profile['auth_method']]];
    }
    private static function state(string $uid,string $token):array {
        if($uid!==MailActor::ALICE||preg_match('/^[a-f0-9]{24}$/D',$token)!==1)throw new \RuntimeException('Invalid credential fixture.');
        $state=json_decode(file_get_contents('/tmp/mcp-credential-'.$token.'.json'),true,512,JSON_THROW_ON_ERROR);
        if($state['uid']!==$uid)throw new \RuntimeException('Credential fixture owner changed.');return $state;
    }
    public static function finish(string $uid,string $token):array {
        $s=self::state($uid,$token);$profile=Server::get(MailSettingsGateway::class)->readConnection($uid,$s['account_id']);
        $client=new McpClient(LoginFactory::basic($uid));$args=['account_id'=>$s['account_id'],'setup_operation_id'=>$s['setup_operation_id'],'intent'=>[]];
        $p=$client->tool('mail_complete_account_setup',$args);
        $capture=MailActor::run($uid,'probe-capture');$noSend=count(array_intersect(['MAIL','RCPT','DATA','BDAT'],$capture['smtp']))===0;
        $outgoing=array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));
        $nativeProof=isset($s['native_context_id'])?Server::get('OCA\\Mail\\Authorization\\SetupEvidence')->observe($uid,$s['native_context_id'],$s['account_id'],$s['scope'],$s['setup_operation_id']):null;
        $completed=false;$replayed=false;$consumed=false;$recovered=false;
        if($p->ok()){
            $plan=self::data($p);$status=self::data($client->tool('mail_get_operation',['operation_id'=>$s['setup_operation_id']]));
            $recovered=isset($status['native_setup']['url'])&&str_contains($status['native_setup']['url'],$s['native_context_id']);
            $confirm=$args+['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];
            $done=self::data($client->tool('mail_complete_account_setup',$confirm));$completed=($done['operation']['state']??null)==='succeeded';
            $again=self::data($client->tool('mail_complete_account_setup',$confirm));$replayed=($again['operation']['replayed']??false)===true;
            $consumed=Server::get('OCA\\Mail\\Authorization\\SetupEvidence')->observe($uid,$s['native_context_id'],$s['account_id'],$s['scope'],$s['setup_operation_id'])===null;
        }
        return ['completed'=>$completed,'replayed'=>$replayed,'consumed'=>$consumed,'recovered'=>$recovered,'proof_protocols'=>$nativeProof['protocols']??[],'native_proof_observed'=>$nativeProof!==null,'profile_unchanged'=>hash_equals($s['profile_revision'],$profile['revision']),'completion_preview_ok'=>$p->ok(),'no_send'=>$noSend&&$outgoing===$s['outgoing']];
    }
    private static function nativeProof(array $s,?string $id=null,?string $source=null,?string $uid=null,?string $scope=null):?array {
        return Server::get('OCA\\Mail\\Authorization\\SetupEvidence')->observe($uid??$s['uid'],$id??$s['native_context_id'],$s['account_id'],$scope??$s['scope'],$source??$s['setup_operation_id']);
    }
    public static function inspect(string $uid,string $token):array {
        $s=self::state($uid,$token);$p=self::nativeProof($s);
        return ['account_revision'=>Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$s['account_id'])->getMailAccount()->getMcpAuthRevision(),'observed'=>$p!==null,'revision'=>$p['revision']??null,'protocols'=>$p['protocols']??[],
            'foreign_owner_refused'=>self::nativeProof($s,null,null,MailActor::BOB)===null,
            'foreign_source_refused'=>self::nativeProof($s,null,'different-source')===null,
            'alternate_observed'=>isset($s['alternate_context'])&&self::nativeProof($s,$s['alternate_context'],$s['alternate_operation_id']??'alternate-'.$token,null,$s['alternate_scope']??null)!==null];
    }
    private static function admitted(string $uid,McpClient $client,array $args,string $tool):array {
        $p=self::data($client->tool($tool,$args));
        $begin=self::data($client->tool($tool,$args+['confirm'=>true,'operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']]));
        $operation=$begin['operation']['operation_id'];$q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
        $r=$q->select('scope_key')->from('mcp_operations')->where($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($operation)))->executeQuery();
        try{$scope=$r->fetchOne();}finally{$r->closeCursor();}
        if(!is_string($scope)||preg_match('/^[a-f0-9]{64}$/D',$scope)!==1)throw new \RuntimeException('Fixture admission scope unavailable.');
        $flow=Server::get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class)->get($scope,$operation);
        $context=$flow['native_context_id']??null;
        if(getenv('MCP_IT_MAIL_CREDENTIAL_CANDIDATE')==='1'){
            if(Server::get(\OCA\Mcp\Modules\Mail\Integration\NativeSetupEvidence::class)->available())throw new \RuntimeException('Qualified primary contexts cannot be fixture armed.');
            $context=Server::get('OCA\\Mail\\Authorization\\SetupEvidence')->register($uid,$scope,$operation,$tool==='mail_add_account'?'create_account':'configure_connection',$args['account_id']??null,$args['intent'],(int)$flow['expires_at']);
            $begin['url']=Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute($tool==='mail_add_account'?'mail.page.setup':'mail.page.index',['mcpSetup'=>$context]);
        }
        if(!is_string($context))throw new \RuntimeException('Native fixture context missing.');
        return ['scope'=>$scope,'operation'=>$operation,'context'=>$context,'url'=>$begin['url']];
    }
    public static function alternate(string $uid,string $token):array {
        $s=self::state($uid,$token);
        $b=self::admitted($uid,new McpClient(LoginFactory::bearer($uid)),['account_id'=>$s['account_id'],'intent'=>[]],'mail_configure_connection');
        $s['alternate_context']=$b['context'];$s['alternate_scope']=$b['scope'];$s['alternate_operation_id']=$b['operation'];
        file_put_contents('/tmp/mcp-credential-'.$token.'.json',json_encode($s,JSON_THROW_ON_ERROR));
        return ['url'=>$b['url'],'scope_differs'=>$s['scope']!==$b['scope']];
    }
    public static function tabsPrepare(string $uid,string $mode):array {
        if(!in_array($mode,['password','state','create'],true))throw new \RuntimeException('Unknown tab fixture mode.');
        $fixture=self::prepare($uid,$mode==='state'?'google':null,$mode==='state');$s=self::state($uid,$fixture['fixture']);$s['tabs_mode']=$mode;
        if($mode==='create'){
            $s['tabs_email']='mcp-tabs-'.$fixture['fixture'].'@example.test';$args=['intent'=>['email'=>$s['tabs_email']]];
            $a=self::admitted($uid,new McpClient(LoginFactory::basic($uid)),$args,'mail_add_account');
            $b=self::admitted($uid,new McpClient(LoginFactory::bearer($uid)),$args,'mail_add_account');
            $s['native_context_id']=$a['context'];$s['setup_operation_id']=$a['operation'];$s['scope']=$a['scope'];
            $s['alternate_context']=$b['context'];$s['alternate_operation_id']=$b['operation'];$s['alternate_scope']=$b['scope'];
            $fixture['url']=$a['url'];$fixture['alternate_url']=$b['url'];$fixture['native_fields']['emailAddress']=$s['tabs_email'];$fixture['native_fields']['authMethod']='xoauth2';
            $fixture['scope_differs']=$a['scope']!==$b['scope'];
        }else{
            $b=self::alternate($uid,$fixture['fixture']);$s=self::state($uid,$fixture['fixture']);$s['tabs_mode']=$mode;
            $fixture['alternate_url']=$b['url'];$fixture['scope_differs']=$b['scope_differs'];
        }
        file_put_contents('/tmp/mcp-credential-'.$fixture['fixture'].'.json',json_encode($s,JSON_THROW_ON_ERROR));return $fixture;
    }
    public static function tabsCreated(string $uid,string $token,int $id):array {
        $s=self::state($uid,$token);$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id);
        if(($s['tabs_email']??null)!==$account->getEmail())throw new \RuntimeException('Unexpected generated tab account.');
        $s['tabs_created'][]=$id;file_put_contents('/tmp/mcp-credential-'.$token.'.json',json_encode($s,JSON_THROW_ON_ERROR));return ['recorded'=>true];
    }
    public static function tabsInspect(string $uid,string $token):array {
        $s=self::state($uid,$token);$native=Server::get('OCA\\Mail\\Authorization\\SetupEvidence');
        $b=$native->describe($uid,$s['alternate_context'],$s['alternate_scope'],$s['alternate_operation_id']);
        $capture=MailActor::run($uid,'probe-capture');$outgoing=array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));
        return ['other_proof'=>$s['tabs_mode']==='create'?false:self::nativeProof($s,$s['alternate_context'],$s['alternate_operation_id'],null,$s['alternate_scope'])!==null,
            'other_target_bound'=>$s['tabs_mode']==='create'&&($b['target_account_id']??null)!==null,
            'no_send'=>array_intersect(['MAIL','RCPT','DATA','BDAT'],$capture['smtp'])===[]&&$outgoing===$s['outgoing']];
    }
    public static function refresh(string $uid,string $token):array {
        $s=self::state($uid,$token);$service=Server::get('OCA\\Mail\\Service\\AccountService');$account=$service->find($uid,$s['account_id']);$entity=$account->getMailAccount();
        $entity->setOauthTokenTtl(time()-120);$service->update($entity);$before=$entity->getMcpAuthRevision();
        $provider=$s['provider']==='google'?'OCA\\Mail\\Integration\\GoogleIntegration':'OCA\\Mail\\Integration\\MicrosoftIntegration';
        $updated=Server::get($provider)->refresh($account);$service->update($updated->getMailAccount());
        return ['revision_advanced'=>$updated->getMailAccount()->getMcpAuthRevision()>$before,'manual_proof_absent'=>self::nativeProof($s)===null];
    }
    public static function expireState(string $uid,string $token,#[\SensitiveParameter] string $state):array {
        $s=self::state($uid,$token);$db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();
        $count=$q->update('mail_mcp_auth_states')->set('expires_at',$q->createNamedParameter(time()-1,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',$q->createNamedParameter(hash('sha256',$state))))->andWhere($q->expr()->eq('context_id',$q->createNamedParameter($s['native_context_id'])))->executeStatement();
        return ['expired'=>$count===1];
    }
    public static function gates(string $uid,string $token):array {
        $s=self::state($uid,$token);$boundary=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);
        $source=$boundary->credentialsSourceVerified();$storage=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailCredentialStorage::class)->verified();
        $native=Server::get('OCA\\Mail\\Authorization\\SetupEvidence');$flow=Server::get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class)->get($s['scope'],$s['setup_operation_id']);
        $same=$native->register($uid,$s['scope'],$s['setup_operation_id'],'configure_connection',$s['account_id'],[],(int)$flow['expires_at'])===$s['native_context_id'];
        $changed=false;try{$native->register($uid,$s['scope'],$s['setup_operation_id'],'configure_connection',$s['account_id'],['name'=>'Changed binding'],(int)$flow['expires_at']);}
        catch(\OCA\Mail\Exception\DraftAdmissionFailure){$changed=true;}
        return ['source'=>$source,'storage'=>$storage,'restored'=>$boundary->credentialsSourceVerified(),'same_registration'=>$same,'changed_binding_refused'=>$changed];
    }
    public static function stateCount(string $uid,string $token):array {
        $s=self::state($uid,$token);if(!isset($s['alternate_context']))throw new \RuntimeException('Expected alternate fixture context.');
        $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();$r=$q->selectAlias($q->func()->count('*'),'n')->from('mail_mcp_auth_states')
            ->where($q->expr()->eq('context_id',$q->createNamedParameter($s['alternate_context'])))->executeQuery();
        try{$count=(int)$r->fetchOne();}finally{$r->closeCursor();}return ['count'=>$count];
    }
    public static function contender(string $uid,string $token,bool $kill=false,bool $name=false):array {
        $s=self::state($uid,$token);$db=Server::get(\OCP\IDBConnection::class);
        if($kill){
            $id=$s['kill_connection']??null;if(!is_int($id)||$id<1)throw new \RuntimeException('Invalid fixture session.');
            if($db->getDatabaseProvider()===\OCP\IDBConnection::PLATFORM_POSTGRES){$r=$db->executeQuery('SELECT pg_terminate_backend(?)',[$id]);try{$killed=in_array($r->fetchOne(),[true,1,'1','t'],true);}finally{$r->closeCursor();}return ['killed'=>$killed];}$db->executeStatement('KILL CONNECTION '.$id);return ['killed'=>true];
        }
        $service=Server::get('OCA\\Mail\\Service\\AccountService');$entity=$service->find($uid,$s['account_id'])->getMailAccount();
        // Native Entity ignores identical values. Fresh native encryption creates a real credential write attempt.
        if($name)$entity->setName($entity->getName().' changed');
        else $entity->setOutboundPassword(Server::get(\OCP\Security\ICrypto::class)->encrypt('mcp-it-probe-password'));
        try{$service->update($entity);return ['credential_write_refused'=>false];}
        catch(\OCA\Mail\Exception\DraftAdmissionFailure){return ['credential_write_refused'=>true];}
    }
    public static function sessionLoss(string $uid,string $token):array {
        $s=self::state($uid,$token);$native=Server::get('OCA\\Mail\\Authorization\\SetupEvidence');$db=Server::get(\OCP\IDBConnection::class);
        $flow=Server::get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class);$p=self::nativeProof($s);
        if($p===null)throw new \RuntimeException('Expected native session-loss proof.');
        $killed=false;$refused=false;
        try{$native->withOwner($uid,function()use($native,$db,$flow,$s,$uid,$p,$token,&$killed):void {
            $flow->atomic(function(\OCP\IDBConnection $actual)use($native,$db,$flow,$s,$uid,$p,$token,&$killed):void {
                $native->assertDatabase($actual);$native->claim($uid,$s['native_context_id'],$s['account_id'],$p['revision'],$s['scope'],$s['setup_operation_id'],'fixture-session-loss');
                $s['kill_connection']=(int)$db->executeQuery($db->getDatabaseProvider()===\OCP\IDBConnection::PLATFORM_POSTGRES?'SELECT pg_backend_pid()':'SELECT CONNECTION_ID()')->fetchOne();
                file_put_contents('/tmp/mcp-credential-'.$token.'.json',json_encode($s,JSON_THROW_ON_ERROR));
                $killed=MailActor::run($uid,'credential-ui-kill',['fixture'=>$token])['killed'];
                $flow->claim($s['scope'],$s['setup_operation_id'],'fixture-session-loss',time());
                $native->assertCompletion($actual,$uid);
            });
        });}catch(\Throwable){$refused=true;}
        // Only independent readback reconnects. Interrupted work above never resumes on a replacement session.
        $db->close();
        $row=$flow->get($s['scope'],$s['setup_operation_id']);$source=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class)->get($uid,$s['setup_operation_id']);
        return ['killed'=>$killed,'refused'=>$refused,'proof_retained'=>self::nativeProof($s)!==null,'workflow_pending'=>$row['phase']==='pending','source_pending'=>$source['state']==='awaiting_user'];
    }
    /** Real native proof + workflow + source SQL roll back together, before final MCP acceptance. */
    public static function guarantees(string $uid,string $token):array {
        $s=self::state($uid,$token);$native=Server::get('OCA\\Mail\\Authorization\\SetupEvidence');$db=Server::get(\OCP\IDBConnection::class);
        $flow=Server::get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class);$ops=Server::get(\OCA\Mcp\Db\OperationsMapper::class);$journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);
        $p=self::nativeProof($s);if($p===null)throw new \RuntimeException('Expected native candidate proof.');
        $outside=false;try{$native->claim($uid,$s['native_context_id'],$s['account_id'],$p['revision'],$s['scope'],$s['setup_operation_id'],'fixture-completion');}catch(\OCA\Mail\Exception\DraftAdmissionFailure){$outside=true;}
        $rolled=false;$contender=false;$nameContender=false;
        try{$native->withOwner($uid,function()use($native,$db,$flow,$ops,$s,$uid,$p,$token,&$contender,&$nameContender):void {
            $flow->atomic(function(\OCP\IDBConnection $actual)use($native,$db,$flow,$ops,$s,$uid,$p,$token,&$contender,&$nameContender):void {
                if($actual!==$db)throw new \LogicException('Different primary wrapper.');
                $native->assertDatabase($actual);$native->claim($uid,$s['native_context_id'],$s['account_id'],$p['revision'],$s['scope'],$s['setup_operation_id'],'fixture-completion');
                if(!$flow->claim($s['scope'],$s['setup_operation_id'],'fixture-completion',time())||!$flow->complete($s['scope'],$s['setup_operation_id'],'fixture-completion',$s['account_id'])
                    ||!$ops->resolveAwaiting($s['scope'],$s['setup_operation_id'],['account_id'=>$s['account_id']],time()))throw new \LogicException('Fixture shared claim failed.');
                $contender=MailActor::run($uid,'credential-ui-contender',['fixture'=>$token])['credential_write_refused'];
                $nameContender=MailActor::run($uid,'credential-ui-name-contender',['fixture'=>$token])['credential_write_refused'];
                $native->assertCompletion($actual,$uid);throw new \RuntimeException('fixture rollback');
            });
        });}catch(\RuntimeException $e){$rolled=$e->getMessage()==='fixture rollback';}
        $row=$flow->get($s['scope'],$s['setup_operation_id']);
        $atomic=$rolled&&self::nativeProof($s)!==null&&$row['phase']==='pending'&&$journal->get($uid,$s['setup_operation_id'])['state']==='awaiting_user';
        $entity=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$s['account_id'])->getMailAccount();$revision=$entity->getMcpAuthRevision();
        $entity->setSignature('Native credential fixture signature');Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($entity);
        return ['concurrent_name_write_refused'=>$nameContender,'concurrent_credential_write_refused'=>$contender,'outside_transaction_refused'=>$outside,'atomic_rollback'=>$atomic,'unrelated_setting_preserves_revision'=>$revision===$entity->getMcpAuthRevision()&&self::nativeProof($s)!==null];
    }
    public static function cleanup(string $uid,string $token):array {
        $s=self::state($uid,$token);if(($s['tabs_mode']??null)==='state'&&is_file('/tmp/mcp-mail-probe-oauth-sync'))unlink('/tmp/mcp-mail-probe-oauth-sync');$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$s['account_id']);
        if($account->getEmail()!==$s['email'])throw new \RuntimeException('Not the generated credential fixture account.');
        foreach($s['tabs_created']??[] as $created){
            $createdAccount=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$created);
            if($createdAccount->getEmail()!==$s['tabs_email'])throw new \RuntimeException('Tab cleanup account changed.');
            Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,$created);
        }
        Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,$s['account_id']);
        ToolPermissionFixture::restore($s['snapshot']['tools']);$config=Server::get(IConfig::class);
        foreach($s['snapshot']['values'] as $key=>$value){if($value==='__missing__')$config->deleteUserValue($uid,'mcp',$key);else$config->setUserValue($uid,'mcp',$key,$value);}
        if($s['snapshot']['global']==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else$config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$s['snapshot']['global']);
        if(isset($s['snapshot']['oauth'])){ $appConfig=Server::get(\OCP\IAppConfig::class);
            foreach($s['snapshot']['oauth'] as $key=>$value){if($value==='')$appConfig->deleteKey('mail',$key);else $appConfig->setValueString('mail',$key,$value);}
        }
        unlink('/tmp/mcp-credential-'.$token.'.json');return ['removed'=>true];
    }
}
