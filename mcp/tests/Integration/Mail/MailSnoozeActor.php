<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IConfig};
final class MailSnoozeActor {
    private static function refused(\Closure $f):bool {try{$f();return false;}catch(\Throwable){return true;}}
    private static function fence():object {return new class{public function authorize(string $uid,array $origin):void{}};}
    public static function run(string $uid,array $in):array {
        if(!class_exists('OCA\\Mail\\Service\\GuardedSnooze'))return ['implementation_available'=>false];
        if($in['scenario']==='erasure')return ['implementation_available'=>true]+MailSnoozeErasureActor::run();
        $db=Server::get(IDBConnection::class);$service=Server::get('OCA\\Mail\\Service\\GuardedSnooze');$store=Server::get('OCA\\Mail\\Snooze\\SnoozeStore');$source=Server::get('OCA\\Mail\\Snooze\\SnoozeSource');$fence=self::fence();
        if($in['scenario']==='contend'){
            if($in['kind']==='owner')return ['refused'=>self::refused(fn()=>$service->cancel($uid,$in['id'],$in['revision'],$fence))];
            return ['refused'=>self::refused(function()use($db,$in):void{$l=\OCA\Mail\Sync\MailboxLease::acquire($db,Server::get(IConfig::class)->getSystemValueString('dbtableprefix','oc_'),$in['mailbox'],Server::get(IConfig::class));$l->release();})];
        }
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$fixture=MailNativeMoveActor::fixture($uid,(int)$account->getId());$entity=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,(int)$account->getId())->getMailAccount();$old=$entity->getSnoozeMailboxId();$entity->setSnoozeMailboxId($fixture['boxes']['destination']);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($entity);
        $id=null;$before=MailNativeMutationActor::safety($uid);$scenario=$in['scenario'];$out=['implementation_available'=>true];
        try{
            if(str_starts_with($scenario,'mcp_'))return $out+MailSnoozeToolActor::run($uid,$fixture,$scenario);
            $at=time()+3600;$preview=$service->preview($uid,$fixture['messages'][0],$at,'UTC',$fence);$key=bin2hex(random_bytes(32));$queued=$service->schedule($uid,$fixture['messages'][0],$at,'UTC',$preview['revision'],$key,1,$fence);$id=$queued['id'];
            $held=fn():bool=>self::refused(fn()=>\OCA\Mail\Snooze\SnoozeHolds::account($db,(int)$account->getId()));
            $inspect=fn(int $box,bool $sync=false):array=>MailNativeMoveActor::inspect($uid,$fixture,$box,$sync);
            if($scenario==='coexist'){$service->place($uid,$id,$fence);return $out+MailSnoozeCoexistActor::run($uid,$fixture,$id);}
            if($scenario==='audit'){
                self::refused(fn()=>$service->review($uid,$id,new class{public function allows(string $uid,array $origin):bool{throw new \RuntimeException('Synthetic policy unavailable');}}));$out['unknown']=$service->status($uid,$id,$fence)['state'];
                $out['blocked']=$service->review($uid,$id,new class{public function allows(string $uid,array $origin):bool{return false;}})['state'];$out['held_after']=$held();return $out+['no_send'=>$before===MailNativeMutationActor::safety($uid)];
            }
            if($scenario==='leases'){
                $calls=[];$during=new class($uid,$id,$queued['revision'],$fixture,$calls){public array $contenders=[];public bool $once=false;public function __construct(private string $uid,private string $id,private string $revision,private array $fixture,array $calls){}public function authorize(string $uid,array $origin):void{if($this->once)return;$this->once=true;$this->contenders[]=MailActor::run($uid,'snooze-native',['scenario'=>'contend','kind'=>'owner','id'=>$this->id,'revision'=>$this->revision])['refused'];foreach(['source','destination']as$key)$this->contenders[]=MailActor::run($uid,'snooze-native',['scenario'=>'contend','kind'=>'mailbox','mailbox'=>$this->fixture['boxes'][$key]])['refused'];}};
                $service->place($uid,$id,$during);return $out+['contenders'=>$during->contenders];
            }
            if(str_starts_with($scenario,'protocol_')){
                $mode=substr($scenario,9);$wakeLeg=str_starts_with($mode,'wake_');$wakeRevision=null;if($wakeLeg){$mode=substr($mode,5);$service->place($uid,$id,$fence);$inspect($fixture['boxes']['destination'],true);$wakeRevision=$service->previewWake($uid,$id,$fence)['wakeRevision'];$request=$source->wake($store->find($uid,$id));}else $request=json_decode($store->find($uid,$id)['placement_request'],true,32,JSON_THROW_ON_ERROR);
                $peer=proc_open([PHP_BINARY,__DIR__.'/snooze-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($peer))throw new \RuntimeException('Synthetic peer unavailable');fwrite($pipes[0],json_encode($request));fclose($pipes[0]);$port=json_decode(fgets($pipes[1]),true)['port'];
                $entity=clone Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$fixture['account_id'])->getMailAccount();$entity->setInboundHost('127.0.0.1');$entity->setInboundPort($port);$entity->setInboundSslMode('none');
                $factory=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory');$client=$factory->getClient(new \OCA\Mail\Account($entity),false);$client->setParam('timeout',2);$client->setParam('read_timeout',2);$moves=Server::get('OCA\\Mail\\Service\\GuardedMessageMutations');$property=new \ReflectionProperty($moves,'clients');$original=$property->getValue($moves);
                $override=new class($client)extends \OCA\Mail\IMAP\IMAPClientFactory{public int $calls=0;public function __construct(private \Horde_Imap_Client_Socket $client){}public function getClient(\OCA\Mail\Account $account,bool $useCache=true):\Horde_Imap_Client_Socket{$this->calls++;return $this->client;}};$property->setValue($moves,$override);
                try{$execute=fn():array=>$wakeLeg?$service->wake($uid,$id,$wakeRevision,$fence):$service->place($uid,$id,$fence);$result=$execute();$execute();$out['native']=$result;$out['clients']=$override->calls;$out['held_after']=$held();$out['cancel_refused']=self::refused(fn()=>$service->cancel($uid,$id,$result['revision'],$fence));}
                finally{$property->setValue($moves,$original);try{$client->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($peer);}
                return $out+json_decode(trim($raw),true,32,JSON_THROW_ON_ERROR);
            }
            if($scenario==='unknown'){
                $store->coordinator()->runOwner($uid,fn()=>$store->update($store->find($uid,$id),['placement_phase'=>'wire_may_start']));$out['before']=$inspect($fixture['boxes']['source']);$service->place($uid,$id,$fence);$state=$service->status($uid,$id,$fence);$out['after']=$inspect($fixture['boxes']['source']);return $out+['state'=>$state['state'],'refused'=>self::refused(fn()=>$service->cancel($uid,$id,$state['revision'],$fence)),'held_after'=>$held()];
            }
            $events=0;
            if($scenario==='event_failure')Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCA\Mail\Events\MessageDeletedEvent::class,function($event)use(&$events,$fixture):void{if($event->getMailbox()->getId()===$fixture['boxes']['source']){$events++;throw new \RuntimeException('Synthetic post-proof event failure');}});
            if($scenario==='holds'){
                $manager=Server::get('OCA\\Mail\\Service\\MailManager');$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$fixture['account_id']);$src=$manager->getMailbox($uid,$fixture['boxes']['source']);$dest=$manager->getMailbox($uid,$fixture['boxes']['destination']);$message=$manager->getMessage($uid,$fixture['messages'][0]);$native=Server::get('OCA\\Mail\\Service\\SnoozeService');$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a,false);$lower=Server::get('OCA\\Mail\\IMAP\\MessageMapper');
                $attempts=[
                    'move'=>fn()=>$manager->moveMessage($a,$src->getName(),$message->getUid(),$a,$dest->getName()),
                    'delete'=>fn()=>$manager->deleteMessageWithClient($a,$src,$message->getUid(),$client),
                    'thread'=>fn()=>$manager->moveThread($a,$src,$a,$dest,$message->getThreadRootId()),
                    'clear'=>fn()=>$manager->clearMailbox($a,$src),
                    'rename'=>fn()=>$manager->renameMailbox($a,$src,'blocked-name'),
                    'stock_snooze'=>fn()=>$native->snoozeMessage($message,time()+3600,$a,$src,$a,$dest),
                    'stock_db'=>fn()=>$native->snoozeMessageDB($message,time()+3600,$src),
                    'lower_move'=>fn()=>$lower->move($client,$src->getName(),$message->getUid(),$dest->getName()),
                    'lower_expunge'=>fn()=>$lower->expunge($client,$src->getName(),$message->getUid()),
                    'lower_flag'=>fn()=>$lower->addFlag($client,$src,[$message->getUid()],'\\Deleted'),
                    'role'=>function()use($a,$src):void{$e=clone $a->getMailAccount();$e->setSnoozeMailboxId($src->getId());Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($e);},
                    'account'=>fn()=>Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,$a->getId()),
                ];$out['holds']=[];try{foreach($attempts as$key=>$attempt)$out['holds'][$key]=self::refused($attempt);}finally{$client->logout();}return $out+['remote'=>$inspect($fixture['boxes']['source'])['remote']];
            }
            $out['placed']=$service->place($uid,$id,$fence);
            if($scenario==='event_failure'){$service->place($uid,$id,$fence);return $out+['events'=>$events,'proof'=>json_decode($store->find($uid,$id)['placement_proof'],true)];}
            if($scenario==='cancel'||$scenario==='cancel_fence'){
                $out['held_before']=$held();$out['before']=$inspect($fixture['boxes']['destination']);
                if($scenario==='cancel_fence'){$revoke=new class{private int $checks=0;public function authorize(string $uid,array $origin):void{if(++$this->checks===3)throw new \RuntimeException('Synthetic precommit withdrawal');}};$out['refused']=self::refused(fn()=>$service->cancel($uid,$id,$out['placed']['revision'],$revoke));$out['state']=$service->status($uid,$id,$fence)['state'];}
                else $out['cancelled']=$service->cancel($uid,$id,$out['placed']['revision'],$fence);
                return $out+['held_after'=>$held(),'after'=>$inspect($fixture['boxes']['destination'])];
            }
            if($scenario==='missing'){
                $q=$db->getQueryBuilder();$r=$q->select('*')->from('mail_mailboxes')->where($q->expr()->eq('id',$q->createNamedParameter($fixture['boxes']['source'])))->executeQuery();try{$original=$r->fetch();}finally{$r->closeCursor();}
                $out['before']=$inspect($fixture['boxes']['destination']);$q=$db->getQueryBuilder();$q->delete('mail_mailboxes')->where($q->expr()->eq('id',$q->createNamedParameter($fixture['boxes']['source'])))->executeStatement();
                try{$out['blocked']=$service->wake($uid,$id,str_repeat('a',64),$fence)['state'];$out['held_after']=$held();}finally{$q=$db->getQueryBuilder();$q->insert('mail_mailboxes');foreach($original as$key=>$value)$q->setValue($key,$q->createNamedParameter($value,$value===null?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL:(is_bool($value)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));$q->executeStatement();}
                return $out+['after'=>$inspect($fixture['boxes']['destination'])];
            }
            if($scenario==='generation'){
                $box=Server::get('OCA\\Mail\\Db\\MailboxMapper')->findById($fixture['boxes']['source']);$generation=[$box->getSyncNewToken(),$box->getSyncChangedToken(),$box->getSyncVanishedToken()];
                $change=function(array $tokens)use($box):void{$box->setSyncNewToken($tokens[0]);$box->setSyncChangedToken($tokens[1]);$box->setSyncVanishedToken($tokens[2]);Server::get('OCA\\Mail\\Db\\MailboxMapper')->update($box);};
                try{$change(array_fill(0,3,base64_encode('V'.((int)$store->find($uid,$id)['source_generation']+1))));$out['before']=$inspect($fixture['boxes']['destination']);$out['blocked']=$service->wake($uid,$id,str_repeat('a',64),$fence)['state'];$out['after']=$inspect($fixture['boxes']['destination']);$out['held_after']=$held();}finally{$change($generation);}return $out;
            }
            $waiting=$service->previewWake($uid,$id,$fence);$out['waiting']=$waiting['readiness'];$inspect($fixture['boxes']['destination'],true);$resolved=$source->resolve($store->find($uid,$id));$out['resolved_id']=$resolved;$out['source_id']=$fixture['messages'][0];
            if($scenario==='cache'){
                $q=$db->getQueryBuilder();$result=$q->select('*')->from('mail_messages')->where($q->expr()->eq('id',$q->createNamedParameter($resolved)))->executeQuery();try{$copy=$result->fetch();}finally{$result->closeCursor();}unset($copy['id']);$q=$db->getQueryBuilder();$q->insert('mail_messages');foreach($copy as$key=>$value)$q->setValue($key,$q->createNamedParameter($value,$value===null?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL:(is_bool($value)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));$q->executeStatement();
                try{$out['duplicate_refused']=self::refused(fn()=>$service->previewWake($uid,$id,$fence));}finally{$q=$db->getQueryBuilder();$q->delete('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter($fixture['boxes']['destination'])))->andWhere($q->expr()->eq('uid',$q->createNamedParameter($copy['uid'])))->andWhere($q->expr()->neq('id',$q->createNamedParameter($resolved)))->executeStatement();}return $out+['state'=>$service->status($uid,$id,$fence)['state']];
            }
            $wake=$service->previewWake($uid,$id,$fence);$out['woken']=$service->wake($uid,$id,$wake['wakeRevision'],$fence);
            $q=$db->getQueryBuilder();$r=$q->selectAlias($q->func()->count('*'),'n')->from('mail_messages_snoozed')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter($fixture['boxes']['destination'])))->executeQuery();try{$out['legacy']=(int)$r->fetchOne();}finally{$r->closeCursor();}
            return $out+['no_send'=>$before===MailNativeMutationActor::safety($uid)];
        }finally{
            if($id!==null){$q=$db->getQueryBuilder();$q->delete('mail_mcp_snoozes')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}
            $entity=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,(int)$account->getId())->getMailAccount();$entity->setSnoozeMailboxId($old);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($entity);MailNativeMoveActor::cleanup($uid,$fixture);
        }
    }
}
