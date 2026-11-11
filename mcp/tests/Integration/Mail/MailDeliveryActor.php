<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IConfig,IDBConnection};
/** Synthetic native protocol fixture only; every source and account change is restored. */
final class MailDeliveryActor {
    private static function refused(\Closure $work):bool {try{$work();return false;}catch(\Throwable){return true;}}
    private static function state(string $directory):array {return json_decode((string)file_get_contents($directory.'/state.json'),true,512,JSON_THROW_ON_ERROR);}
    public static function contender(string $uid,array $in):array {
        $service=Server::get('OCA\\Mail\\Service\\GuardedDelivery');$fence=new class {public function authorize(string $uid,int $connection,string $tool,int $account):void {}};
        return ['refused'=>self::refused(fn()=>$in['cancel']?$service->cancel($uid,$in['id'],$in['revision']):$service->dispatch($uid,$in['id'],$fence))];
    }
    public static function child(string $uid,array $in):array {
        $db=Server::get(IDBConnection::class);$r=$db->executeQuery('SELECT pg_backend_pid()');try{$pid=(int)$r->fetchOne();}finally{$r->closeCursor();}
        echo json_encode(['backendPid'=>$pid],JSON_THROW_ON_ERROR)."\n";flush();
        $fence=new class((bool)$in['killBefore']) {public function __construct(private bool $kill){}public function authorize(string $uid,int $connection,string $tool,int $account):void {if($this->kill)posix_kill(getmypid(),9);}};
        return Server::get('OCA\\Mail\\Service\\GuardedDelivery')->dispatch($uid,$in['id'],$fence);
    }
    private static function waitFor(\Closure $condition,string $failure):void {$until=hrtime(true)+10_000_000_000;do{if($condition())return;usleep(10000);}while(hrtime(true)<$until);throw new \RuntimeException($failure);}
    private static function killProof(string $uid,array $row,string $directory,array $before,bool $beforeClaim):array {
        $db=Server::get(IDBConnection::class);if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES)throw new \RuntimeException('Deterministic native kill fixture requires PostgreSQL.');
        if(is_file($directory.'/ack_continue'))unlink($directory.'/ack_continue');
        $process=proc_open([PHP_BINARY,'-d','memory_limit=512M',__DIR__.'/mail-actor.php',$uid,'delivery-child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new \RuntimeException('Delivery crash worker unavailable.');
        fwrite($pipes[0],json_encode(['id'=>$row['id'],'killBefore'=>$beforeClaim],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        try{
            $ready=json_decode((string)fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR);$backend=(int)$ready['backendPid'];$blocked=false;
            if(!$beforeClaim){
                self::waitFor(fn():bool=>count(self::state($directory)['messages'])>count($before['messages']),'Synthetic DATA checkpoint not reached.');
                $db->beginTransaction();$q=$db->getQueryBuilder();$q->select('state')->from('mail_mcp_deliveries')->where($q->expr()->eq('id',$q->createNamedParameter($row['id'])))->forUpdate();$r=$q->executeQuery();try{if($r->fetchOne()!=='attempting')throw new \RuntimeException('Durable attempt checkpoint missing.');}finally{$r->closeCursor();}
                file_put_contents($directory.'/ack_continue','continue');
                self::waitFor(function()use($db,$backend):bool {$clear=$db->executeQuery('SELECT pg_stat_clear_snapshot()');$clear->closeCursor();$r=$db->executeQuery("SELECT wait_event_type, query FROM pg_stat_activity WHERE pid = ?",[$backend]);try{$row=$r->fetch();}finally{$r->closeCursor();}return $row!==false&&$row['wait_event_type']==='Lock'&&str_contains(strtolower($row['query']),'mail_mcp_deliveries');},'Worker did not reach the blocked acceptance persistence checkpoint.');
                $blocked=true;proc_terminate($process,9);
            }
            self::waitFor(function()use($process):bool {return !proc_get_status($process)['running'];},'Killed native worker did not stop.');
            if($db->inTransaction())$db->commit();
            $observed=self::state($directory);$last=end($observed['messages']);return ['killed'=>true,'acceptance_persistence_blocked'=>$blocked,'server_accepted'=>$beforeClaim?false:(bool)$last['acknowledged']];
        }finally{if($db->inTransaction())$db->rollBack();$state=proc_get_status($process);if($state['running'])proc_terminate($process,9);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);if(is_file($directory.'/ack_continue'))unlink($directory.'/ack_continue');}
    }
    public static function run(string $uid,array $in):array {
        if(!class_exists('OCA\\Mail\\Service\\GuardedDelivery'))return ['implementation_available'=>false];
        if($uid!==MailActor::ALICE)throw new \RuntimeException('Unexpected delivery fixture owner.');
        $directory=getenv('MCP_IT_DELIVERY_DIRECTORY');if(!is_string($directory)||$directory===''||!is_file($directory.'/mode.json'))throw new \RuntimeException('Synthetic delivery recorder required.');
        $mode=json_decode((string)file_get_contents($directory.'/mode.json'),true,16,JSON_THROW_ON_ERROR);if(($mode['fixture']??null)!=='mcp-synthetic-delivery-v1'||($mode['port']??33466)!==33466)throw new \RuntimeException('Unexpected synthetic SMTP identity.');
        $scenario=$in['scenario'];$config=Server::get(IConfig::class);$db=Server::get(IDBConnection::class);$accounts=Server::get('OCA\\Mail\\Service\\AccountService');$account=$accounts->findByUserId($uid)[0];$entity=$account->getMailAccount();
        $original=[];foreach(['OutboundHost','OutboundPort','OutboundSslMode','InboundHost','InboundPort','InboundSslMode','Debug'] as $field)$original[$field]=$entity->{'get'.$field}();
        $settings=[];foreach(['app.mail.mcp-transmission.enabled','app.mail.verify-tls-peer','app.mail.debug','app.mail.smtp.timeout'] as $key)$settings[$key]=$config->getSystemValue($key,null);
        $drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$attachments=Server::get('OCA\\Mail\\Service\\Attachment\\AttachmentService');$aliases=Server::get('OCA\\Mail\\Service\\AliasesService');$service=Server::get('OCA\\Mail\\Service\\GuardedDelivery');$store=Server::get('OCA\\Mail\\Transmission\\AttemptStore');$draft=null;$alias=null;$queued=null;$copyPeer=null;$copyPipes=[];
        try{
            if(in_array($scenario,['repair_lost','repair_bad_literal','repair_bad_uid','repair_missing_uid','repair_selected_generation','repair_uid_after','repair_duplicate_uid','repair_changed_during_fetch','repair_reconnect'],true)){
                $copyPeer=proc_open([PHP_BINARY,__DIR__.'/delivery-imap-server.php',match($scenario){'repair_bad_literal'=>'oversized_literal','repair_bad_uid'=>'wrong_appenduid','repair_missing_uid'=>'missing_appenduid','repair_selected_generation'=>'selected_generation','repair_uid_after'=>'uid_after_literal','repair_duplicate_uid'=>'duplicate_uid','repair_changed_during_fetch'=>'generation_during_fetch','repair_reconnect'=>'reconnect_after_append',default=>'lost_ack'}],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$copyPipes);if(!is_resource($copyPeer))throw new \RuntimeException('Synthetic APPEND peer unavailable.');fclose($copyPipes[0]);$port=json_decode(fgets($copyPipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];$entity->setInboundHost('127.0.0.1');$entity->setInboundPort($port);$entity->setInboundSslMode('none');
            }
            $entity->setOutboundHost('localhost');$entity->setOutboundPort(33466);$entity->setOutboundSslMode('ssl');$entity->setDebug(false);$accounts->update($entity);
            $config->setSystemValue('app.mail.mcp-transmission.enabled',true);$config->setSystemValue('app.mail.verify-tls-peer',true);$config->setSystemValue('app.mail.debug',false);$config->setSystemValue('app.mail.smtp.timeout',2);
            $mode['mode']=$scenario==='kill_after_data'?'pause_before_ack':($in['mode']??'accept');$mode['pipelining']=$in['pipelining']??true;file_put_contents($directory.'/mode.json',json_encode($mode,JSON_THROW_ON_ERROR));$before=self::state($directory);
            $alias=$aliases->create($uid,$account->getId(),'approved@example.test','Approved identity');
            $a=$attachments->addFileFromString($uid,'synthetic.txt','text/plain',"Synthetic attachment\0bytes",null,'attachment');
            $m=new \OCA\Mail\Db\LocalMessage();$m->setType(1);$m->setAccountId($account->getId());$m->setAliasId($alias->getId());$m->setSubject('Synthetic immutable delivery');$m->setBodyPlain(".first\n.second\r\nlast");$m->setBodyHtml('');$m->setEditorBody($m->getBodyPlain());$m->setHtml(false);$m->setFailed(true);$m->setMcpDraftOnly(true);$m->setUpdatedAt(time());
            $draft=$drafts->saveMessage($account,$m,[['email'=>'one@example.test']],[],[['email'=>'hidden@example.test']],[['type'=>'local','id'=>$a->getId()]]);
            $preview=$service->preview($uid,$draft->getId());$key=bin2hex(random_bytes(16));$admit=fn()=>$service->queue($uid,$draft->getId(),$preview['revision'],$key,1,'mail_send',0,'UTC');
            if($scenario==='stale'){
                $aliases->update($uid,$alias->getId(),'changed@example.test','Changed identity',null);$changed=self::refused($admit);$aliases->delete($uid,$alias->getId());$alias=null;$missing=self::refused(fn()=>$service->preview($uid,$draft->getId()));
                return ['implementation_available'=>true,'changed_sender_refused'=>$changed,'missing_sender_refused'=>$missing,'smtp_unchanged'=>$before['commands']===self::state($directory)['commands']];
            }
            if($scenario==='missing_attachment'){
                [, $file]=$attachments->getAttachment($uid,$a->getId());$file->delete();return ['implementation_available'=>true,'missing_attachment_refused'=>self::refused($admit),'smtp_unchanged'=>$before['commands']===self::state($directory)['commands']];
            }
            if($scenario==='disabled'){$config->setSystemValue('app.mail.mcp-transmission.enabled',false);return ['implementation_available'=>true,'disabled_refused'=>self::refused($admit),'due'=>$service->due(50),'smtp_unchanged'=>$before['commands']===self::state($directory)['commands']];}
            $queued=$admit();$replayed=$admit();$draft=$drafts->getMessage($draft->getId(),$uid);$out=['implementation_available'=>true,'idempotent'=>$queued['id']===$replayed['id'],'opaque'=>preg_match('/^[a-f0-9]{64}$/D',$queued['id'])===1,'receipt_private'=>!str_contains(json_encode($queued),'Synthetic')&&!isset($queued['artifact'])];
            if($scenario==='admission'){
                $out['operation_mismatch_refused']=self::refused(fn()=>$service->queue($uid,$draft->getId(),$preview['revision'],$key,2,'mail_send',0,'UTC'));
                $out['foreign_status_refused']=self::refused(fn()=>$service->status(MailActor::BOB,$queued['id']));$out['holds']=[];
                $out['holds']['native_update']=self::refused(function()use($drafts,$draft,$account):void {$copy=clone $draft;$copy->setBodyPlain('Changed');$drafts->updateMessage($account,$copy,[['email'=>'one@example.test']],[],[]);});
                $out['holds']['native_delete']=self::refused(fn()=>$drafts->deleteMessage($uid,$draft));$out['holds']['attachment_delete']=self::refused(fn()=>$attachments->deleteAttachment($uid,$a->getId()));
                $out['holds']['alias_delete']=self::refused(fn()=>$aliases->delete($uid,$alias->getId()));$out['holds']['account_delete']=self::refused(fn()=>$accounts->delete($uid,$account->getId()));
                $out['holds']['direct_transmission']=self::refused(fn()=>Server::get('OCA\\Mail\\Service\\MailTransmission')->sendMessage($account,$draft));
                $out['holds']['outbox']=self::refused(fn()=>Server::get('OCA\\Mail\\Service\\OutboxService')->convertDraft($draft,time()));$out['holds']['chain']=self::refused(fn()=>Server::get('OCA\\Mail\\Send\\Chain')->process($account,$draft));
                $out['cancelled']=$service->cancel($uid,$queued['id'],$queued['revision'])['state'];$out['released']=false;try{$drafts->deleteMessage($uid,$drafts->getMessage($draft->getId(),$uid));$out['released']=true;$draft=null;}catch(\Throwable $e){$out['release_error']=get_class($e).': '.$e->getMessage();}
                $out['smtp_unchanged']=$before['commands']===self::state($directory)['commands'];return $out;
            }
            if($scenario==='crash')$store->coordinator()->runOwner($uid,fn()=>$store->transition($store->find($uid,$queued['id']),'attempting'));
            $fence=new class($scenario,$uid,$queued) {
                public bool $called=false;public array $contenders=[];
                public function __construct(private string $scenario,private string $owner,private array $queued){}
                public function authorize(string $uid,int $connection,string $tool,int $account):void {
                    if($uid!==$this->owner||$connection!==1||!in_array($tool,['mail_send','mail_sent_repair'],true)||$account!==$this->queued['accountId'])throw new \RuntimeException('Current authorization mismatch.');
                    if($this->scenario==='revoked')throw new \RuntimeException('Current authorization revoked.');
                    if($this->scenario==='concurrent'&&!$this->called){$this->called=true;foreach([false,true] as $cancel)$this->contenders[]=MailActor::run($uid,'delivery-contender',['id'=>$this->queued['id'],'revision'=>$this->queued['revision'],'cancel'=>$cancel])['refused'];}
                }
            };
            if(in_array($scenario,['kill_before','kill_after_data'],true)){$out['kill_proof']=self::killProof($uid,$queued,$directory,$before,$scenario==='kill_before');$out['state_after_kill']=$service->status($uid,$queued['id'])['state'];}
            $result=$service->dispatch($uid,$queued['id'],$fence);$beforeReplay=self::state($directory);$again=$service->dispatch($uid,$queued['id'],$fence);$after=self::state($directory);
            $out+=['state'=>$result['state'],'sent_state'=>$result['sentState'],'replay_state'=>$again['state'],'replay_no_connection'=>$beforeReplay['connections']===$after['connections'],'smtp_submissions'=>($after['commands']['MAIL']??0)-($before['commands']['MAIL']??0),'connections'=>$after['connections']-$before['connections'],'contenders'=>$fence->contenders,'cancel_too_late'=>self::refused(fn()=>$service->cancel($uid,$queued['id'],$queued['revision']))];
            if($result['state']==='accepted'){
                $row=$store->find($uid,$queued['id']);$artifact=json_decode($row['artifact'],true,512,JSON_THROW_ON_ERROR);$last=end($after['messages']);$out['exact_wire_bytes']=$last['sha256']===$artifact['smtpHash'];$out['bcc_private']=!str_contains($artifact['smtp'],'Bcc:')&&str_contains($artifact['fcc'],'Bcc:');
                if(in_array($scenario,['repair','repair_lost','repair_bad_literal','repair_bad_uid','repair_missing_uid','repair_selected_generation','repair_uid_after','repair_duplicate_uid','repair_changed_during_fetch','repair_reconnect'],true)){$repair=Server::get('OCA\\Mail\\Service\\SentCopyRepair');$plan=$repair->preview($uid,$queued['id']);$out['copy_state']=$repair->repair($uid,$queued['id'],$plan['repairRevision'],$fence)['sentState'];$repair->repair($uid,$queued['id'],$plan['repairRevision'],$fence);$copyTransport=self::state($directory);$out['copy_smtp_unchanged']=$after['commands']===$copyTransport['commands']&&$after['connections']===$copyTransport['connections'];if($scenario==='repair')$out['terminal_identity_released']=!self::refused(function()use($db,$account,$aliases,$uid,$alias):void {\OCA\Mail\Transmission\DeliveryHolds::assertFree($db,'account_id',(int)$account->getId());$aliases->update($uid,$alias->getId(),$alias->getAlias(),'Updated after verified copy',null);});if($copyPeer!==null){$out['copy_peer']=json_decode((string)stream_get_contents($copyPipes[1]),true,16,JSON_THROW_ON_ERROR);fclose($copyPipes[1]);fclose($copyPipes[2]);proc_close($copyPeer);$copyPeer=null;}}
            }
            return $out;
        }finally{
            if($copyPeer!==null){proc_terminate($copyPeer);foreach(array_slice($copyPipes,1) as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($copyPeer);}
            // Test cleanup removes only this randomly keyed synthetic admission, after all assertions' evidence is captured.
            if($queued!==null){$q=$db->getQueryBuilder();$q->delete('mail_mcp_deliveries')->where($q->expr()->eq('id',$q->createNamedParameter($queued['id'])))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}
            if($draft!==null)try{$drafts->deleteMessage($uid,$drafts->getMessage($draft->getId(),$uid));}catch(\OCP\AppFramework\Db\DoesNotExistException){}
            if($alias!==null)$aliases->delete($uid,$alias->getId());foreach($original as $field=>$value)$entity->{'set'.$field}($value);$accounts->update($entity);
            foreach($settings as $key=>$value){if($value===null)$config->deleteSystemValue($key);else $config->setSystemValue($key,$value);}
        }
    }
}
