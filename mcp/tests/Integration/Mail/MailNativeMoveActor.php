<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
final class MailNativeMoveActor {
    public static function protocol(string $uid,int $accountId,string $mode):array {
        $p=proc_open([PHP_BINARY,__DIR__.'/move-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new \RuntimeException('Fixture unavailable');fclose($pipes[0]);
        $port=json_decode(fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);$e=clone $account->getMailAccount();$e->setInboundHost('127.0.0.1');$e->setInboundPort($port);$e->setInboundSslMode('none');
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($e),false);$client->setParam('timeout',2);$client->setParam('read_timeout',2);
        $sentinel=random_int(1000000000,2000000000);
        $r=['account_id'=>$sentinel,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','action'=>'move','destination_mailbox_id'=>6,'destination_mailbox'=>'Dest','destination_generation'=>'43','profile_revision'=>'fixture','cache_revision'=>'fixture','destination_cache_revision'=>'fixture','roles_revision'=>'fixture','requires_trash_hook'=>false];
        $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);
        $args=['account_id'=>$sentinel,'message_id'=>17,'destination_mailbox_id'=>6];$plan=$journal->plan('mail_move_message',$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'fixture'];$verified=false;
        try {
            $effect=function()use($uid,$journal,$store,$r,$client,&$verified):array{
                $l=$journal->delegateNative($uid,$r);$f=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$l,$r,static fn():bool=>true,static fn():bool=>true);
                $result=$client->moveGuarded($r,Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->fence($f));$verified=true;return $result;
            };
            $result=$journal->execute('mail_move_message',$uid,$args,$effect);
            $replay=$journal->execute('mail_move_message',$uid,$args,static fn():array=>throw new \LogicException('Never repeat MOVE'));
        }finally{try{$client->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
        return ['verified'=>$verified,'proof'=>$result['operation']['mutation']['remote']??[],'operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed']]+json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
    }
    public static function fixture(string $uid,int $accountId):array {
        $a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$factory=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory');$mapper=Server::get('OCA\\Mail\\IMAP\\MessageMapper');
        $boxes=[];$c=$factory->getClient($a,false);
        foreach(['source','destination','archive','trash'] as $role){$b=$manager->createMailbox($a,'mcp-c3-'.$role.'-'.bin2hex(random_bytes(6)));$boxes[$role]=$b;}
        try {
            $ids=[];foreach(['source','destination'] as $role){foreach(range(1,$role==='source'?5:1) as $i)$ids[$role][]=$mapper->save($c,$boxes[$role],"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: C3 $i\r\nMessage-ID: <same-c3@example.test>\r\n\r\nPayload $role $i",[]);}
            foreach($boxes as $b)Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($a,$c,$b,Server::get(\Psr\Log\LoggerInterface::class));
            $cache=Server::get('OCA\\Mail\\Db\\MessageMapper');$messages=array_map(static fn($id):int=>(int)$cache->findByUids($boxes['source'],[$id])[0]->getId(),$ids['source']);
            $e=$a->getMailAccount();$saved=['archive'=>$e->getArchiveMailboxId(),'trash'=>$e->getTrashMailboxId()];$e->setArchiveMailboxId($boxes['archive']->getId());$e->setTrashMailboxId($boxes['trash']->getId());Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($e);
            return ['account_id'=>$accountId,'messages'=>$messages,'boxes'=>array_map(static fn($b):int=>(int)$b->getId(),$boxes),'saved_roles'=>$saved];
        }finally{$c->logout();}
    }
    public static function cleanup(string $uid,array $fixture):array {
        $a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$fixture['account_id']);$e=$a->getMailAccount();$e->setArchiveMailboxId($fixture['saved_roles']['archive']);$e->setTrashMailboxId($fixture['saved_roles']['trash']);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($e);
        foreach($fixture['boxes'] as $id){$b=Server::get('OCA\\Mail\\Service\\MailManager')->getMailbox($uid,$id);if(!str_starts_with($b->getName(),'mcp-c3-'))throw new \LogicException('Not a C3 fixture');Server::get('OCA\\Mail\\Service\\MailManager')->deleteMailbox($a,$b);}
        return ['cleaned'=>true];
    }
    public static function inspect(string $uid,array $fixture,int $mailboxId,bool $sync=false):array {
        if(!in_array($mailboxId,$fixture['boxes'],true))throw new \LogicException('Not a fixture folder');
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$b=$manager->getMailbox($uid,$mailboxId);$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$fixture['account_id']);$c=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a,false);
        try{
            if($sync)Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($a,$c,$b,Server::get(\Psr\Log\LoggerInterface::class));
            $q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$remote=[];foreach($c->fetch($b->getName(),$q,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)]) as $m)$remote[]=$m->getUid();
            $cached=Server::get('OCA\\Mail\\Db\\MessageMapper')->findByUids($b,$remote);
            return ['remote'=>$remote,'cached'=>array_map(static fn($m):array=>['id'=>(int)$m->getId(),'uid'=>(string)$m->getUid()],$cached)];
        }finally{$c->logout();}
    }
    public static function service(string $uid,int $accountId):array {
        $fixture=self::fixture($uid,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$boxes=Server::get('OCA\\Mail\\Db\\MailboxMapper');$before=MailNativeMutationActor::safety($uid);
        try {
            $source=$boxes->findById($fixture['boxes']['source']);$dest=$boxes->findById($fixture['boxes']['destination']);$m=$manager->getMessage($uid,$fixture['messages'][0]);
            $rev=static fn($b):string=>hash('sha256',json_encode([$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()],JSON_THROW_ON_ERROR));
            $r=['account_id'=>$accountId,'message_id'=>(int)$m->getId(),'mailbox_id'=>(int)$source->getId(),'mailbox'=>$source->getName(),'generation'=>\OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration::of($source),'uid'=>(string)$m->getUid(),'profile_revision'=>'fixture','cache_revision'=>$rev($source),'action'=>'move','destination_mailbox_id'=>(int)$dest->getId(),'destination_mailbox'=>$dest->getName(),'destination_generation'=>\OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration::of($dest),'destination_cache_revision'=>$rev($dest),'roles_revision'=>'fixture','requires_trash_hook'=>false];
            $f=new class implements \OCA\Mail\Service\MutationFence {
                public array $proof=[];public array $cache=[];
                public function beforePreconditions(array $b):bool{return true;}
                public function beforeWire(array $b):bool{return true;}
                public function recordRemote(array $p):bool{$this->proof=$p;return true;}
                public function beforeCache(array $b):bool{return true;}
                public function recordCache(array $p):bool{$this->cache=$p;return true;}
            };
            Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->move($uid,$r,$f);
            $gone=false;try{$manager->getMessage($uid,$r['message_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$gone=true;}
            $d=self::inspect($uid,$fixture,$dest->getId());$s=self::inspect($uid,$fixture,$source->getId());
            return ['source_cache_gone'=>$gone,'source_count'=>count($s['remote']),'destination_count'=>count($d['remote']),'destination_cached_count'=>count($d['cached']),'proof'=>$f->proof,'cache'=>$f->cache,'no_send'=>$before===MailNativeMutationActor::safety($uid)];
        }finally{self::cleanup($uid,$fixture);}
    }

    public static function fault(string $uid,int $accountId,string $mode):array {
        $fixture=self::fixture($uid,$accountId);$journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$eventCount=0;$hookCount=0;$plan=null;
        $action=$mode==='hook_failure'?'trash':'move';$tool='mail_'.$action.'_message';
        $args=['account_id'=>$accountId,'message_id'=>$fixture['messages'][0]];if($action==='move')$args['destination_mailbox_id']=$fixture['boxes']['destination'];
        $r=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailMutationTarget::class)->snapshot($uid,['action'=>$action]+$args)['request'];
        $events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->addListener(\OCA\Mail\Events\BeforeMessageDeletedEvent::class,static function($event)use(&$hookCount,$r,$mode):void{if($event->getMailbox()->getId()===$r['mailbox_id']){$hookCount++;if($mode==='hook_failure')throw new \RuntimeException('Fixture hook failure');}});
        $events->addListener(\OCA\Mail\Events\MessageDeletedEvent::class,static function($event)use(&$eventCount,$r,$mode):void{if($event->getMailbox()->getId()===$r['mailbox_id']){$eventCount++;if($mode==='event_failure')throw new \RuntimeException('Fixture event failure');}});
        $box=Server::get('OCA\\Mail\\Db\\MailboxMapper')->findById($mode==='source_cache_reset'?$r['mailbox_id']:$r['destination_mailbox_id']);$tokens=[$box->getSyncNewToken(),$box->getSyncChangedToken(),$box->getSyncVanishedToken()];$calls=0;
        $admission=static function()use(&$calls,$mode,$box,$r):bool {
            $calls++;
            if($calls===2&&in_array($mode,['cache_reset','source_cache_reset'],true)){$token=base64_encode('V'.((int)$r[$mode==='source_cache_reset'?'generation':'destination_generation']+1));$box->setSyncNewToken($token);$box->setSyncChangedToken($token);$box->setSyncVanishedToken($token);Server::get('OCA\\Mail\\Db\\MailboxMapper')->update($box);}
            return !($mode==='cache_revoked'&&$calls===2);
        };
        $safe=MailNativeMutationActor::safety($uid);
        try{
            $plan=$journal->plan($tool,$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'fixture'];
            $effect=function()use($uid,$journal,$store,$r,$admission):array{$l=$journal->delegateNative($uid,$r);$f=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$l,$r,$admission,static fn():bool=>true);$native=Server::get('OCA\\Mail\\Service\\GuardedMessageMutations');return $native->move($uid,$r,$native->fence($f));};
            $result=$journal->execute($tool,$uid,$args,$effect);$counts=[$hookCount,$eventCount];
            $replay=$journal->execute($tool,$uid,$args,static fn():array=>throw new \LogicException('No replay'));
            $source=self::inspect($uid,$fixture,$r['mailbox_id']);$dest=self::inspect($uid,$fixture,$r['destination_mailbox_id']);
            $cached=true;try{Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($uid,$r['message_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$cached=false;}
            return ['operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed'],'event_counts'=>$counts,'no_redispatch'=>$counts===[$hookCount,$eventCount],'source_count'=>count($source['remote']),'destination_count'=>count($dest['remote']),'source_cached'=>$cached,'no_send'=>$safe===MailNativeMutationActor::safety($uid)];
        }finally{
            $box->setSyncNewToken($tokens[0]);$box->setSyncChangedToken($tokens[1]);$box->setSyncVanishedToken($tokens[2]);Server::get('OCA\\Mail\\Db\\MailboxMapper')->update($box);
            // Only this synthetic disposable test admission; product uncertainty is never cleared by cleanup.
            if($plan!==null){$q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('operation_id',$q->createNamedParameter($plan['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter($journal->scopeKey($uid))))->executeStatement();}
            self::cleanup($uid,$fixture);
        }
    }

    public static function boundary(string $uid):array {
        $cap=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class);$safe=MailNativeMutationActor::safety($uid);
        return ['before'=>$cap->forUser($uid),'no_send'=>$safe===MailNativeMutationActor::safety($uid)];
    }

    public static function role(string $uid,array $fixture,string $role,int $id):array {
        if(!in_array($role,['archive','trash'],true)||!in_array($id,$fixture['boxes'],true))throw new \LogicException('Not a fixture role');
        $a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$fixture['account_id']);$b=Server::get('OCA\\Mail\\Service\\MailManager')->getMailbox($uid,$id);if($b->getAccountId()!==$a->getId()||!str_starts_with($b->getName(),'mcp-c3-'))throw new \LogicException('Not a fixture mailbox');
        $e=$a->getMailAccount();if($role==='archive')$e->setArchiveMailboxId($id);else $e->setTrashMailboxId($id);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($e);return ['changed'=>true];
    }

}
