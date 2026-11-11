<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IConfig};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\ResourceScopeWriter;
use OCA\Mcp\Db\{ResourceScopeMapper,PolicyStateMapper,ToolGrantsMapper};
use OCA\Mcp\OAuth\{ConnectionToolsStore,OAuthStore,TokenHasher,TokenService};
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
/** Real HTTP request paused by a synthetic TLS peer after actual IMAP work; no shipping-service overrides. */
final class MailProjectionHttpActor {
    private static function scope(string $uid,?array $items):void {Server::get(ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'],'admin',$uid,'mail',$items);}
    private static function state(string $dir):array {return json_decode((string)file_get_contents($dir.'/state.json'),true,16,JSON_THROW_ON_ERROR);}
    private static function data(ToolReply $reply):array {if(!$reply->ok())throw new \RuntimeException('Unchanged-authority HTTP control refused');return $reply->structured()!==[]?$reply->structured():$reply->json();}
    private static function wait(\Closure $ready):void {$end=hrtime(true)+6_000_000_000;do{if($ready())return;usleep(1000);}while(hrtime(true)<$end);throw new \RuntimeException('Native barrier not reached');}
    private static function request(string $uid,string $token,string $tool,array $args,string $canary,string $dir,string $kind,\Closure $revoke):array {
        $barrier=bin2hex(random_bytes(16));file_put_contents($dir.'/arm.json',json_encode(['kind'=>$kind,'token'=>$barrier],JSON_THROW_ON_ERROR),LOCK_EX);
        $p=proc_open([PHP_BINARY,__DIR__.'/projection-http-client.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new \RuntimeException('HTTP contender unavailable');
        fwrite($pipes[0],json_encode(compact('uid','token','tool','canary')+['arguments'=>$args],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        try {
            self::wait(fn()=>is_file($dir.'/reached.json')&&(json_decode((string)file_get_contents($dir.'/reached.json'),true)['token']??null)===$barrier);
            $revoke();file_put_contents($dir.'/release',$barrier,LOCK_EX);
            $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$p=null;
            if($exit!==0||$error!=='')throw new \RuntimeException('HTTP contender failed');
            return json_decode($out,true,16,JSON_THROW_ON_ERROR);
        } finally {
            file_put_contents($dir.'/release',$barrier,LOCK_EX);unlink($dir.'/arm.json');
            if(is_resource($p)){proc_terminate($p);foreach(array_slice($pipes,1)as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}
        }
    }
    public static function run(string $uid,array $in):array {
        MailCleanHttpActor::assertClean();$config=Server::get(IConfig::class);
        if($uid!==MailActor::ALICE||$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false)||!in_array($in['scenario']??null,['preview','effect'],true))throw new \RuntimeException('Closed synthetic projection fixture required');
        $scenario=$in['scenario'];$host=getenv('MCP_IT_PROJECTION_UPSTREAM_HOST');$port=getenv('MCP_IT_PROJECTION_UPSTREAM_PORT');
        if(!is_string($host)||!preg_match('/^mcp-it-[a-z0-9-]+-imap$/D',$host)||$port!=='31143')throw new \RuntimeException('Explicit synthetic IMAP destination required');
        $dir='/tmp/mcp-projection-'.bin2hex(random_bytes(8));if(!mkdir($dir,0700))throw new \RuntimeException('Private barrier directory unavailable');
        $proxy=proc_open([PHP_BINARY,__DIR__.'/projection-imap-proxy.php',$dir,'/tmp/mcp-mail-probe.crt','/tmp/mcp-mail-probe.key',$host,$port],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proxy))throw new \RuntimeException('TLS peer unavailable');fclose($pipes[0]);stream_set_timeout($pipes[1],3);$listen=json_decode((string)fgets($pipes[1]),true,8,JSON_THROW_ON_ERROR)['port'];
        $accounts=Server::get('OCA\\Mail\\Service\\AccountService');$template=$accounts->findByUserId($uid)[0]->getMailAccount();
        $entity=new \OCA\Mail\Db\MailAccount();
        // Existing disposable-server credentials remain encrypted and only in native process memory.
        foreach(['UserId','InboundUser','InboundPassword','OutboundUser','OutboundPassword','AuthMethod','EditorMode','SignatureMode']as$field)$entity->{'set'.$field}($template->{'get'.$field}());
        $entity->setName('Retained projection fixture');$entity->setEmail('projection-'.bin2hex(random_bytes(6)).'@example.test');
        $entity->setInboundHost('127.0.0.1');$entity->setInboundPort($listen);$entity->setInboundSslMode('ssl');
        $entity->setOutboundHost('localhost');$entity->setOutboundPort(33466);$entity->setOutboundSslMode('ssl');$entity->setDebug(false);
        $entity=$accounts->save($entity);$accountId=(int)$entity->getId();$account=$accounts->find($uid,$accountId);
        $tools=['mail_send','mail_set_message_flag','mail_get_operation'];$permissions=ToolPermissionFixture::capture([$uid],[ToolGrantsMapper::SERVICE,...$tools]);$oldScope=Server::get(ResourceScopeMapper::class)->forModule($uid,'mail');
        $global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__absent');$settings=[];foreach([GrantPolicy::ELIGIBLE_KEY,GrantPolicy::CONNECTED_KEY]as$key)$settings[$key]=$config->getUserValue($uid,'mcp',$key,'__absent');
        $policy=Server::get(GrantPolicy::class);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$folder=null;$draft=null;$connection=null;$client=null;$out=[];
        $smtp=MailNativeMutationActor::safety($uid)['smtp'];$canary='PRIVATE_PROJECTION_'.bin2hex(random_bytes(12));
        try {
            $folder=$manager->createMailbox($account,'mcp-projection-'.bin2hex(random_bytes(8)));$entity=$account->getMailAccount();$entity->setSentMailboxId($folder->getId());$accounts->update($entity);$account=$accounts->find($uid,$accountId);
            $policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);ToolPermissionFixture::assign($uid,array_fill_keys([ToolGrantsMapper::SERVICE,...$tools],'allow'));self::scope($uid,['account:'.$accountId]);sleep(3);
            $credential=LoginFactory::bearer($uid);$token=substr($credential->authorization(),7);$row=Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash($token));$connection=(int)$row['id'];$selections=Server::get(ConnectionToolsStore::class);$selection=$selections->get($uid,$connection);$selections->save($uid,$connection,$tools,$selection['revision']);$http=new McpClient($credential);
            if($scenario==='preview'){
                $m=new \OCA\Mail\Db\LocalMessage();$m->setType(1);$m->setAccountId($accountId);$m->setSubject('Synthetic projection');$m->setBodyPlain($canary);$m->setBodyHtml('');$m->setEditorBody($canary);$m->setHtml(false);$m->setFailed(true);$m->setMcpDraftOnly(true);$m->setUpdatedAt(time());
                $draft=$drafts->saveMessage($account,$m,[['email'=>'projection@example.test']],[],[],[]);$args=['draft_id'=>$draft->getId()];
                $control=$http->tool('mail_send',$args);self::data($control);if(!str_contains($control->raw,$canary))throw new \RuntimeException('Private preview control missing');
                $r=self::request($uid,$token,'mail_send',$args,$canary,$dir,'STATUS',fn()=>self::scope($uid,[]));
                $out=['scenario'=>$scenario,'unchanged_authority_control'=>true,'public_denied'=>!$r['ok'],'private_canary_absent'=>!$r['canary'],'response_sha256'=>$r['response_sha256']];
            } else {
                $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);
                $remote=Server::get('OCA\\Mail\\IMAP\\MessageMapper')->save($client,$folder,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: projection\r\nMessage-ID: <".bin2hex(random_bytes(12))."@example.test>\r\n\r\nSynthetic projection\r\n",[]);
                Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($account,$client,$folder,Server::get(\Psr\Log\LoggerInterface::class));$message=Server::get('OCA\\Mail\\Db\\MessageMapper')->findByUids($folder,[$remote])[0];$client->logout();$client=null;
                $args=['account_id'=>$accountId,'message_id'=>(int)$message->getId(),'flag'=>'flagged','value'=>true];$plan=self::data($http->tool('mail_set_message_flag',$args));$args+=['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];
                $before=self::state($dir);$r=self::request($uid,$token,'mail_set_message_flag',$args,$canary,$dir,'UID STORE',function()use($selections,$uid,$connection):void{$selection=$selections->get($uid,$connection);$selections->save($uid,$connection,[],$selection['revision']);});$after=self::state($dir);
                $store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$scope=hash('sha256',$uid."\0".$connection);$stored=$store->get($scope,$plan['operation_id']);
                $replay=$http->tool('mail_set_message_flag',$args);$afterReplay=self::state($dir);$same=$store->get($scope,$plan['operation_id']);$proof=$stored['resource_refs']['mutation']??[];
                $selection=$selections->get($uid,$connection);$selections->save($uid,$connection,$tools,$selection['revision']);$status=self::data($http->tool('mail_get_operation',['operation_id'=>$plan['operation_id']]));
                $out=['scenario'=>$scenario,'unchanged_authority_control'=>true,'public_denied'=>!$r['ok'],'receipt_absent'=>!$r['operation_exposed'],'operation_id'=>$plan['operation_id'],'state'=>$stored['state']??null,'native_phase'=>$proof['phase']??null,'remote_outcome'=>$proof['remote']['outcome']??null,'durable_same_after_denied_replay'=>$stored===$same,'replay_denied'=>!$replay->ok(),'store_count'=>$after['stores']-$before['stores'],'no_second_store'=>$after['stores']===$afterReplay['stores'],'original_status_restored'=>($status['operation']['operation_id']??null)===$plan['operation_id'],'response_sha256'=>$r['response_sha256']];
            }
            $state=self::state($dir);$out+=['barriers'=>$state['barriers'],'released'=>$state['released'],'timeouts'=>$state['timed_out'],'no_smtp'=>$smtp===MailNativeMutationActor::safety($uid)['smtp'],'operator_false'=>!$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false),'proof_directory'=>$dir];
        } finally {
            if($client!==null)$client->logout();
            // Retain this synthetic account/folder/draft and all journal/native proofs; never bypass uncertainty holds.
            if($connection!==null)Server::get(TokenService::class)->revokeGrant($connection,$uid);
            self::scope($uid,$oldScope['items']??null);ToolPermissionFixture::restore($permissions);
            if($global==='__absent')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else$config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
            foreach($settings as$key=>$value){if($value==='__absent')$config->deleteUserValue($uid,'mcp',$key);else$config->setUserValue($uid,'mcp',$key,$value);}
            proc_terminate($proxy);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proxy);
            if($errors!=='')throw new \RuntimeException('Synthetic TLS peer failed');
        }
        $out+=['restored'=>true,'retained_account_id'=>$accountId,'retained_folder_id'=>$folder?->getId()];
        file_put_contents($dir.'/result.json',json_encode($out,JSON_THROW_ON_ERROR)."\n",LOCK_EX);return $out;
    }
}
