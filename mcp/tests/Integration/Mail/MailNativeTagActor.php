<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
use OCA\Mcp\Modules\Mail\Mutations\TagMutationRequest;
/** Disposable protocol actor. Never logs credentials or exposes the native client to product callers. */
final class MailNativeTagActor {
    public static function fault(string $owner,int $accountId,string $mode):array {
        if(!in_array($mode,['metadata','epoch','remote_receipt','cache_receipt'],true))throw new \LogicException('Unknown fault fixture');$f=MailNativeMoveActor::fixture($owner,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$tag=$manager->createTag('C5 fault '.bin2hex(random_bytes(6)),'#123456',$owner);$db=Server::get(\OCP\IDBConnection::class);$safety=MailNativeMutationActor::safety($owner);$args=[];
        try {
            $m=$manager->getMessage($owner,$f['messages'][0]);$b=$manager->getMailbox($owner,$m->getMailboxId());$g=$m->getTagCacheGeneration();$tokens=[$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()];
            $r=['kind'=>'tag','account_id'=>$accountId,'message_id'=>$m->getId(),'mailbox_id'=>$b->getId(),'mailbox'=>$b->getName(),'generation'=>$g,'uid'=>(string)$m->getUid(),'profile_revision'=>'fixture','cache_revision'=>hash('sha256',json_encode($tokens,JSON_THROW_ON_ERROR)),'tag_id'=>$tag->getId(),'imap_label'=>$tag->getImapLabel(),'tag_revision'=>hash('sha256',json_encode(['id'=>(int)$tag->getId(),'owner'=>$tag->getUserId(),'name'=>$tag->getDisplayName(),'label'=>$tag->getImapLabel(),'color'=>$tag->getColor(),'default'=>$tag->getIsDefaultTag()],JSON_THROW_ON_ERROR)),'tag_cache_revision'=>hash('sha256',json_encode(['generation'=>$g,'tag_id'=>$tag->getId(),'value'=>false],JSON_THROW_ON_ERROR)),'value'=>true];
            $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$args=['account_id'=>$accountId,'message_id'=>$m->getId(),'tag_id'=>$tag->getId(),'value'=>true];$p=$journal->plan('mail_set_message_tag',$owner,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];
            $effect=static function()use($journal,$store,$owner,$r,$mode,$db,$tag,$tags,$b):array {
                $lease=$journal->delegateNative($owner,$r);$inner=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$lease,$r,static fn():bool=>true,static fn():bool=>true,TagMutationRequest::remote(...),TagMutationRequest::cache(...));
                $fence=new class($inner,$mode,$db,$tag,$tags,$b) implements \OCA\Mail\Service\MutationFence {
                    public function __construct(private object $inner,private string $mode,private object $db,private object $tag,private object $tags,private object $box){}
                    public function beforePreconditions(array $b):bool{return $this->inner->beforePreconditions($b);}
                    public function beforeWire(array $b):bool{return $this->inner->beforeWire($b);}
                    public function recordRemote(array $p):bool{return $this->mode==='remote_receipt'?false:$this->inner->recordRemote($p);}
                    public function beforeCache(array $b):bool {if(!$this->inner->beforeCache($b))return false;if($this->mode==='metadata'){$t=clone $this->tag;$t->setDisplayName($t->getDisplayName().' changed');$this->tags->update($t);}if($this->mode==='epoch'){$q=$this->db->getQueryBuilder();$q->update('mail_mailboxes')->set('sync_changed_token',$q->createNamedParameter(base64_encode('V'.((int)$b['generation']+1))))->where($q->expr()->eq('id',$q->createNamedParameter($this->box->getId())))->executeStatement();}return true;}
                    public function recordCache(array $p):bool{return $this->mode==='cache_receipt'?false:$this->inner->recordCache($p);}
                };return Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->setTag($owner,$r,$fence);
            };
            $result=$journal->execute('mail_set_message_tag',$owner,$args,$effect);$replay=$journal->execute('mail_set_message_tag',$owner,$args,static fn():array=>throw new \LogicException('No second tag write'));
            // Restore only this fixture's epoch before inspecting its exact cache.
            $q=$db->getQueryBuilder();$q->update('mail_mailboxes')->set('sync_changed_token',$q->createNamedParameter($tokens[1]))->where($q->expr()->eq('id',$q->createNamedParameter($b->getId())))->executeStatement();
            $cache=$tags->exactAssociations()->has($owner,$m->getId(),$tag->getId());$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$accountId);$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);try{$q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->flags();$flags=$client->fetch($b->getName(),$q,['ids'=>new \Horde_Imap_Client_Ids([$m->getUid()])])->first()->getFlags();}finally{$client->logout();}
            return ['operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed'],'cache'=>$cache,'remote'=>in_array($tag->getImapLabel(),$flags,true),'safety_before'=>$safety,'safety_after'=>MailNativeMutationActor::safety($owner)];
        }finally {
            // Remove only the admission created by this disposable test; production uncertainty is never cleared by cleanup.
            if(isset($args['operation_id'])){$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('operation_id',$q->createNamedParameter($args['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter($journal->scopeKey($owner))))->executeStatement();$q=$db->getQueryBuilder();$q->delete('mcp_mail_probe_slots')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',"mutation\0".$owner."\0".$accountId))))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($args['operation_id'])))->executeStatement();}
            $tags->delete($tag);MailNativeMoveActor::cleanup($owner,$f);
        }
    }
    public static function protocol(string $owner,int $accountId,string $mode):array {
        try{return self::probe($owner,$accountId,$mode);}catch(\Throwable $e){return ['fixture_error'=>get_class($e),'at'=>basename($e->getFile()).':'.$e->getLine(),'frames'=>array_map(static fn(array $f):string=>($f['class']??'').($f['function']??'').':'.($f['line']??0),$e->getTrace())];}
    }
    private static function probe(string $owner,int $accountId,string $mode):array {
        $p=proc_open([PHP_BINARY,__DIR__.'/mutation-imap-server.php',$mode,'tag'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new \RuntimeException('Fixture server unavailable');fclose($pipes[0]);$port=json_decode(fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$accountId);$entity=clone $account->getMailAccount();$entity->setInboundHost('127.0.0.1');$entity->setInboundPort($port);$entity->setInboundSslMode('none');$c=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($entity),false);$c->setParam('timeout',2);$c->setParam('read_timeout',2);
        $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$sentinel=random_int(1000000000,2000000000);$args=['account_id'=>$sentinel,'message_id'=>17,'tag_id'=>7,'value'=>true];$plan=$journal->plan('mail_set_message_tag',$owner,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'fixture'];
        $request=['kind'=>'tag','account_id'=>$sentinel,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'fixture','cache_revision'=>'fixture','tag_id'=>7,'imap_label'=>'$reviewed','tag_revision'=>str_repeat('a',64),'tag_cache_revision'=>str_repeat('b',64),'value'=>true];
        try{$effect=function()use($owner,$journal,$store,$request,$c):array{$lease=$journal->delegateNative($owner,$request);$f=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$lease,$request,static fn():bool=>true,static fn():bool=>true,TagMutationRequest::remote(...),TagMutationRequest::cache(...));$native=Server::get('OCA\\Mail\\Service\\GuardedMessageMutations');return $c->setTagGuarded($request,$native->fence($f));};$result=$journal->execute('mail_set_message_tag',$owner,$args,$effect);$replay=$journal->execute('mail_set_message_tag',$owner,$args,static fn():array=>throw new \LogicException('No second keyword write'));}
        finally{try{$c->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
        return ['operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed']]+json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
    }
}
