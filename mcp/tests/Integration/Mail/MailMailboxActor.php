<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
final class MailMailboxActor {
    public static function native(string $uid,int $id):array {
        $f=MailNativeMoveActor::fixture($uid,$id);$safe=MailNativeMutationActor::safety($uid);
        try{
            $service=Server::get('OCA\\Mail\\Service\\GuardedMailboxMutations');$roles=$service->metadata($uid,$id)['roles'];
            $run=static function(array $args)use($service,$uid,$id):array {
                $m=$service->metadata($uid,$id);$r=['kind'=>'mailbox','account_id'=>$id]+\OCA\Mcp\Modules\Mail\Mailboxes\MailboxTree::prepare($m,$args)+['inventory_revision'=>$service->revision($m),'profile_revision'=>'fixture'];
                $fence=new class implements \OCA\Mail\Service\MutationFence {
                    public function beforePreconditions(array $b):bool{return true;}
                    public function beforeWire(array $b):bool{return true;}
                    public function recordRemote(array $p):bool{return true;}
                    public function beforeCache(array $b):bool{return true;}
                    public function recordCache(array $p):bool{return true;}
                };
                return $service->mutate($uid,$r,$fence);
            };
            $create=$run(['action'=>'create_mailbox','name'=>'mcp-c3-c2-'.bin2hex(random_bytes(6))]);$newId=$create['cache']['mailboxes'][0]['id'];$f['boxes']['created']=$newId;
            // Explicit native synchronization belongs to fixture setup, never the C2 product path.
            MailNativeMoveActor::inspect($uid,$f,$newId,true);
            $rename=$run(['action'=>'rename_mailbox','mailbox_id'=>$f['boxes']['source'],'name'=>'mcp-c3-renamed-'.bin2hex(random_bytes(6))]);
            $unsub=$run(['action'=>'subscribe_mailbox','mailbox_id'=>$newId,'subscribed'=>false]);$again=$run(['action'=>'subscribe_mailbox','mailbox_id'=>$newId,'subscribed'=>false]);$sub=$run(['action'=>'subscribe_mailbox','mailbox_id'=>$newId,'subscribed'=>true]);
            foreach($f['messages'] as $message)if(Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($uid,$message)->getMailboxId()!==$f['boxes']['source'])throw new \LogicException('Cached source identity changed');
            return ['created'=>$create['remote']['outcome']==='created','renamed'=>$rename['remote']['outcome']==='renamed','subscribed'=>$sub['remote']['outcome']==='subscribed','unsubscribed'=>in_array($unsub['remote']['outcome'],['subscribed','already_desired'],true),'already_desired'=>$again['remote']['outcome']==='already_desired','roles_preserved'=>$roles===$service->metadata($uid,$id)['roles'],'message_ids_preserved'=>true,'no_send'=>$safe===MailNativeMutationActor::safety($uid)];
        }finally{MailNativeMoveActor::cleanup($uid,$f);}
    }
    public static function protocol(string $uid,int $id,string $mode):array {
        $process=proc_open([PHP_BINARY,__DIR__.'/mailbox-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new \RuntimeException('Fixture unavailable');fclose($pipes[0]);$port=json_decode(fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];
        $a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$id);$e=clone $a->getMailAccount();$e->setInboundHost('127.0.0.1');$e->setInboundPort($port);$e->setInboundSslMode('none');$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($e),false);$client->setParam('timeout',2);$client->setParam('read_timeout',2);
        $sentinel=random_int(1000000000,2000000000);$r=['kind'=>'mailbox','account_id'=>$sentinel,'action'=>'create_mailbox','mailbox_id'=>0,'name'=>'','new_name'=>'Dest','delimiter'=>'/','subscribed'=>null,'tree'=>[],'inventory_revision'=>'fixture','profile_revision'=>'fixture'];
        $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$args=['account_id'=>$sentinel,'name'=>'Dest'];$p=$journal->plan('mail_create_mailbox',$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];
        try{
            $effect=function()use($uid,$journal,$store,$r,$client):array{$l=$journal->delegateNative($uid,$r);$f=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$l,$r,static fn():bool=>true,\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::remote(...),\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::cache(...));return $client->mutateMailboxGuarded($r,Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->fence($f));};
            $result=$journal->execute('mail_create_mailbox',$uid,$args,$effect);$replay=$journal->execute('mail_create_mailbox',$uid,$args,static fn():array=>throw new \LogicException('No duplicate CREATE'));
        }finally{try{$client->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
        return ['operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed']]+json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
    }

    public static function fault(string $uid,int $id,string $mode):array {
        $service=Server::get('OCA\\Mail\\Service\\GuardedMailboxMutations');$accounts=Server::get('OCA\\Mail\\Service\\AccountService');$a=$accounts->find($uid,$id);$savedNamespace=$a->getMailAccount()->getPersonalNamespace();$name='mcp-c3-c2fault-'.bin2hex(random_bytes(6));
        $m=$service->metadata($uid,$id);$r=['kind'=>'mailbox','account_id'=>$id]+\OCA\Mcp\Modules\Mail\Mailboxes\MailboxTree::prepare($m,['action'=>'create_mailbox','name'=>$name])+['inventory_revision'=>$service->revision($m),'profile_revision'=>'fixture'];
        $j=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$s=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$args=['account_id'=>$id,'name'=>$name];$p=$j->plan('mail_create_mailbox',$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];$calls=0;$events=0;$safe=MailNativeMutationActor::safety($uid);
        Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCA\Mail\Events\MailboxesSynchronizedEvent::class,static function($event)use(&$events,$mode):void{if($event instanceof \OCA\Mail\Events\GuardedMailboxesSynchronizedEvent){$events++;if($mode==='event_failure')throw new \RuntimeException('Fixture folder event failure');}});
        $admit=static function()use(&$calls,$mode,$a):bool{$calls++;if($calls===2&&$mode==='cache_race'){$a->getMailAccount()->setPersonalNamespace('fixture-changed/');Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($a->getMailAccount());}return !($calls===2&&$mode==='cache_revoked');};
        try{
            $out=$j->execute('mail_create_mailbox',$uid,$args,function()use($j,$s,$uid,$r,$admit,$service):array{$l=$j->delegateNative($uid,$r);$f=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($s,$l,$r,$admit,\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::remote(...),\OCA\Mcp\Modules\Mail\Mutations\MailboxMutationRequest::cache(...));return $service->mutate($uid,$r,Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->fence($f));});
            $replay=$j->execute('mail_create_mailbox',$uid,$args,static fn():array=>throw new \LogicException('No repeat folder effect'));$cached=false;foreach($service->metadata($uid,$id)['folders']as$b)if($b['name']===$name)$cached=true;
            return ['operation'=>$out['operation'],'replayed'=>$replay['operation']['replayed'],'events'=>$events,'cached'=>$cached,'no_send'=>$safe===MailNativeMutationActor::safety($uid)];
        }finally{
            $a=$accounts->find($uid,$id);$a->getMailAccount()->setPersonalNamespace($savedNamespace);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($a->getMailAccount());
            // Exact synthetic disposable folder and admission only; product uncertainty is never cleared here.
            $c=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a,false);try{$c->deleteMailbox($name);}finally{$c->logout();}
            foreach(Server::get('OCA\\Mail\\Db\\MailboxMapper')->findAll($a)as$b)if($b->getName()===$name)Server::get('OCA\\Mail\\Db\\MailboxMapper')->delete($b);
            $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('operation_id',$q->createNamedParameter($p['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter($j->scopeKey($uid))))->executeStatement();
        }
    }

}
