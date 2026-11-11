<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IUserManager};
/** Only randomly named synthetic accounts, local SQL and core deletion events. No transport. */
final class MailErasureActor {
    private static function count(string $table,string $uid):int {
        $q=Server::get(IDBConnection::class)->getQueryBuilder();$q->selectAlias($q->createFunction('COUNT(*)'),'n')->from($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)));$r=$q->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }
    public static function account(string $uid):object {
        $a=new \OCA\Mail\Db\MailAccount();foreach(['userId'=>$uid,'name'=>'Synthetic erasure','email'=>'erase@example.test','inboundHost'=>'127.0.0.1','inboundPort'=>1,'inboundSslMode'=>'none','inboundUser'=>'synthetic','inboundPassword'=>'synthetic-encrypted-placeholder','outboundHost'=>'127.0.0.1','outboundPort'=>1,'outboundSslMode'=>'none','outboundUser'=>'synthetic','outboundPassword'=>'synthetic-encrypted-placeholder'] as $key=>$value)$a->{'set'.ucfirst($key)}($value);
        return Server::get('OCA\\Mail\\Db\\MailAccountMapper')->insert($a);
    }
    private static function queue(string $uid,int $account,string $state,string $sent):string {
        $db=Server::get(IDBConnection::class);$id=bin2hex(random_bytes(32));$row=['id'=>$id,'user_id'=>$uid,'account_id'=>$account,'draft_id'=>random_int(100000000,200000000),'operation_key'=>hash('sha256',$id),'revision'=>$id,'content_revision'=>$id,'connection_id'=>1,'source_tool'=>'mail_send','send_at'=>0,'timezone'=>'UTC','created_at'=>time(),'state'=>$state,'sent_state'=>$sent,'artifact'=>'{"smtp":"synthetic private body","fcc":"synthetic private body"}','sent_proof'=>'{}'];$q=$db->getQueryBuilder();$q->insert('mail_mcp_deliveries');foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v));$q->executeStatement();return $id;
    }

    private static function independent():object {$config=Server::get(\OC\SystemConfig::class);return new \OC\DB\ConnectionAdapter((new \OC\DB\ConnectionFactory($config))->getConnection((string)$config->getValue('dbtype',''),[]));}
    private static function retry():void {if(class_exists('OCA\\Mail\\Lifecycle\\MailErasureService')){ $job=new \OCA\Mail\BackgroundJob\MailErasureJob(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),Server::get('OCA\\Mail\\Lifecycle\\MailErasureService'));$job->start(Server::get(\OCP\BackgroundJob\IJobList::class));}}
    private static function failedChild():array {
        $uid='mcp-it-erasure-'.bin2hex(random_bytes(8));$user=Server::get(IUserManager::class)->createUser($uid,bin2hex(random_bytes(24)));$a=self::account($uid);self::queue($uid,$a->getId(),'queued','not_started');$user->getHome();
        $backend=new class extends \OC\User\Database {public function __construct(){}public function deleteUser($uid){return false;}};
        (new \ReflectionProperty(\OC\User\User::class,'backend'))->setValue($user,$backend);
        $deleted=$user->delete();self::retry();return ['uid'=>$uid,'deleted'=>$deleted,'accounts'=>self::count('mail_accounts',$uid),'payloads'=>self::count('mail_mcp_deliveries',$uid),'deleted_flag'=>Server::get(\OCP\IConfig::class)->getUserValue($uid,'core','deleted','')];
    }
    public static function run(array $in):array {
        if($in['scenario']==='insert-for'){$uid=$in['uid'];if(!preg_match('/^mcp-it-erasure-[a-f0-9]{16}$/D',$uid))throw new \RuntimeException('Unexpected capture fixture owner.');try{self::account($uid);return ['refused'=>false];}catch(\Throwable){return ['refused'=>true];}}
        if($in['scenario']==='failed-child')return self::failedChild();
        if($in['scenario']==='failed'){
            $r=MailActor::run(MailActor::ALICE,'erasure-native',['scenario'=>'failed-child']);if(isset($r['fixture_error']))return $r;$uid=$r['uid'];if(!preg_match('/^mcp-it-erasure-[a-f0-9]{16}$/D',$uid))throw new \RuntimeException('Invalid synthetic user.');
            try{self::retry();self::account($uid);$r['after_accounts']=self::count('mail_accounts',$uid);$r['after_payloads']=self::count('mail_mcp_deliveries',$uid);return $r;}finally{self::clear($uid);Server::get(IUserManager::class)->get($uid)?->delete();}
        }

        $uid='mcp-it-erasure-'.bin2hex(random_bytes(8));$db=Server::get(IDBConnection::class);$users=Server::get(IUserManager::class);$user=$users->createUser($uid,bin2hex(random_bytes(24)));if($user===false)throw new \RuntimeException('Synthetic user unavailable.');$out=[];$privacy=null;
        try {
            if($in['scenario']==='ordinary'){
                foreach([['cancelled','not_started'],['blocked','not_started'],['accepted','verified'],['queued','not_started'],['attempting','not_started'],['uncertain','not_started'],['accepted','pending']] as [$state,$sent]){
                    $a=self::account($uid);self::queue($uid,$a->getId(),$state,$sent);$refused=false;try{Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,$a->getId());}catch(\Throwable){$refused=true;}
                    $out[$state.':'.$sent]=['refused'=>$refused,'accounts'=>self::count('mail_accounts',$uid),'payloads'=>self::count('mail_mcp_deliveries',$uid)];self::clear($uid);
                }return $out;
            }
            if($in['scenario']==='capture'){
                $a=self::account($uid);self::queue($uid,$a->getId(),'queued','not_started');$events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
                $events->dispatchTyped(new \OCP\User\Events\BeforeUserDeletedEvent($user));$r=MailActor::run(MailActor::ALICE,'erasure-native',['scenario'=>'insert-for','uid'=>$uid]);
                $events->dispatchTyped(new \OCP\User\Events\UserDeletedEvent(clone $user));self::retry();$out=['crossing_refused'=>$r['refused'],'unmatched_accounts'=>self::count('mail_accounts',$uid),'unmatched_payloads'=>self::count('mail_mcp_deliveries',$uid)];
                $user->delete();$out['accounts']=self::count('mail_accounts',$uid);$out['payloads']=self::count('mail_mcp_deliveries',$uid);$out['job_registered']=Server::get(\OCP\BackgroundJob\IJobList::class)->has('OCA\\Mail\\BackgroundJob\\MailErasureJob',null);return $out;
            }
            $a=self::account($uid);$ids=[];foreach([['cancelled','not_started'],['blocked','not_started'],['queued','not_started'],['attempting','not_started'],['uncertain','not_started'],['accepted','pending'],['accepted','attempting'],['accepted','verified'],['accepted','uncertain']] as [$state,$sent])$ids[]=self::queue($uid,$a->getId(),$state,$sent);
            // Include an already missing original account: cleanup must not depend on account enumeration alone.
            self::queue($uid,$a->getId()+100000000,'queued','not_started');
            $other=self::count('mail_accounts',MailActor::BOB);
            if(in_array($in['scenario'],['busy','reentrant'],true)){
                $privacy=MailErasurePrivacyActor::prepare($uid,$a);
                $independent=self::independent();$r=$independent->executeQuery('SELECT pg_backend_pid()');try{$pid=(int)$r->fetchOne();}finally{$r->closeCursor();}$r=$db->executeQuery('SELECT pg_backend_pid()');try{$different=$pid!==(int)$r->fetchOne();}finally{$r->closeCursor();}
                $config=Server::get(\OCP\IConfig::class);$lease=null;
                try {
                    if($in['scenario']==='busy'){$lease=\OCA\Mail\Drafts\DraftOwnerLease::acquire($independent,$config->getSystemValueString('dbtableprefix','oc_'),$uid,$config);$deleted=$user->delete();}
                    else $deleted=\OCA\Mail\Drafts\DraftCoordinator::forDatabase($db)->runOwner($uid,function()use($user):bool {$deleted=$user->delete();self::retry();return $deleted;});
                    $out=['deleted'=>$deleted,'independent'=>$different,'busy_accounts'=>self::count('mail_accounts',$uid),'busy_payloads'=>self::count('mail_mcp_deliveries',$uid)];if($in['scenario']==='busy')self::retry();$out['still_payloads']=self::count('mail_mcp_deliveries',$uid);
                }finally{$lease?->release();$independent->close();}
                // The pending marker must refuse dispatch itself, even after its original owner worker exits.
                $q=$db->getQueryBuilder();$q->select('id')->from('mail_mcp_deliveries')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('account_id',$q->createNamedParameter($a->getId())))->andWhere($q->expr()->eq('state',$q->createNamedParameter('queued')));$r=$q->executeQuery();try{$id=$r->fetchOne();}finally{$r->closeCursor();}
                $old=$config->getSystemValueBool('app.mail.mcp-transmission.enabled',false);$config->setSystemValue('app.mail.mcp-transmission.enabled',true);
                try{$out['worker_state']=Server::get('OCA\\Mail\\Service\\GuardedDelivery')->dispatch($uid,$id,new class{public function authorize(string $uid,int $connection,string $tool,int $account):void {}})['state'];}finally{$config->setSystemValue('app.mail.mcp-transmission.enabled',$old);}
                $store=Server::get('OCA\\Mail\\Transmission\\AttemptStore');$row=$store->find($uid,$id);$row['id']=bin2hex(random_bytes(32));$row['operation_key']=$row['id'];$row['draft_id']=(int)$row['draft_id']+200000000;$row['state']='queued';$out['admission_refused']=false;
                try{$store->coordinator()->runOwner($uid,fn()=>$store->insert($row));}catch(\OCA\Mail\Transmission\BeforeWireBlock){$out['admission_refused']=true;}
                $new=$users->createUser($uid,bin2hex(random_bytes(24)));$a=self::account($uid);self::queue($uid,$a->getId(),'queued','not_started');
                $out['privacy']=MailErasurePrivacyActor::observe($uid,$a,$privacy);
                self::retry();self::retry();Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCP\User\Events\UserDeletedEvent($user));
                $out['new_accounts']=self::count('mail_accounts',$uid);$out['new_payloads']=self::count('mail_mcp_deliveries',$uid);$out['privacy']+=MailErasurePrivacyActor::after($uid,$a,$privacy);$user=$new;return $out;
            }
            $deleted=$user->delete();
            $out=['deleted'=>$deleted,'accounts'=>self::count('mail_accounts',$uid),'payloads'=>self::count('mail_mcp_deliveries',$uid),'unrelated'=>self::count('mail_accounts',MailActor::BOB)===$other];
            $out['replay_missing']=Server::get('OCA\\Mail\\Service\\GuardedDelivery')->findByOperation($uid,$ids[0])===null;
            if($in['scenario']==='recreated'){
                $new=$users->createUser($uid,bin2hex(random_bytes(24)));$a=self::account($uid);self::queue($uid,$a->getId(),'queued','not_started');
                Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCP\User\Events\UserDeletedEvent($user));Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCP\User\Events\UserDeletedEvent(clone $user));
                if(class_exists('OCA\\Mail\\Lifecycle\\MailErasureService'))Server::get('OCA\\Mail\\BackgroundJob\\MailErasureJob')->start(Server::get(\OCP\BackgroundJob\IJobList::class));
                $out['new_accounts']=self::count('mail_accounts',$uid);$out['new_payloads']=self::count('mail_mcp_deliveries',$uid);$user=$new;
            }
            return $out;
        }finally{if($privacy!==null)MailErasurePrivacyActor::clean($privacy);self::clear($uid);if($users->userExists($uid))$user->delete();}
    }
    private static function clear(string $uid):void {foreach(['mail_mcp_deliveries','mail_accounts'] as $table){$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}}
}
