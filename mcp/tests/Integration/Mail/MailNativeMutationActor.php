<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
/** Disposable real-protocol tests. No product hook receives or exposes the native client. */
final class MailNativeMutationActor {
    public static function protocolFault(string $uid,int $accountId,string $mode):array {
        $process=proc_open([PHP_BINARY,__DIR__.'/mutation-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new \RuntimeException('Fixture server unavailable.');fclose($pipes[0]);
        $port=json_decode(fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);$entity=clone $account->getMailAccount();
        $entity->setInboundHost('127.0.0.1');$entity->setInboundPort($port);$entity->setInboundSslMode('none');
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($entity),false);$client->setParam('read_timeout',2);$client->setParam('timeout',2);
        $journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);
        $sentinel=random_int(1000000000,2000000000);
        $r=['account_id'=>$sentinel,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'fixture','cache_revision'=>'fixture'];
        $args=['account_id'=>$sentinel,'message_id'=>17,'flag'=>'seen','value'=>true];$p=$journal->plan('mail_set_message_flag',$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];
        try {
            $effect=function()use($uid,$journal,$store,$r,$client):array{
                $lease=$journal->delegateNative($uid,$r);$fence=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$lease,$r,static fn():bool=>true);
                return $client->setFlagGuarded($r,Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->fence($fence));
            };
            $result=$journal->execute('mail_set_message_flag',$uid,$args,$effect);
            $replay=$journal->execute('mail_set_message_flag',$uid,$args,static fn():array=>throw new \LogicException('Must never run'));
        }finally{try{$client->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
        $server=json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
        return ['operation'=>$result['operation'],'replayed'=>$replay['operation']['replayed'],'stores'=>$server['stores'],'server_applied'=>$server['applied']];
    }
    public static function cacheProof(string $uid,int $messageId):array {
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$m=$manager->getMessage($uid,$messageId);$b=$manager->getMailbox($uid,$m->getMailboxId());
        $map=Server::get('OCA\\Mail\\Db\\MailboxMapper');$original=(bool)$m->getFlagFlagged();
        $snapshot=Server::get(\OCA\Mcp\Modules\Mail\Integration\MailMutationTarget::class)->snapshot($uid,['account_id'=>(int)$b->getAccountId(),'message_id'=>$messageId,'flag'=>'flagged','value'=>!$original]);
        $r=$snapshot['request'];$saved=[$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()];
        $f=new class($b,$map) implements \OCA\Mail\Service\MutationFence {
            public array $remote=[];public bool $completed=false;
            public function __construct(private object $mailbox,private object $mapper){}
            public function beforePreconditions(array $binding):bool{return true;}
            public function beforeWire(array $binding):bool{return true;}
            public function recordRemote(array $proof):bool{$this->remote=$proof;return true;}
            public function beforeCache(array $binding):bool{
                $token=base64_encode('V'.((int)$binding['generation']+1));
                $this->mailbox->setSyncNewToken($token);$this->mailbox->setSyncChangedToken($token);$this->mailbox->setSyncVanishedToken($token);$this->mapper->update($this->mailbox);return true;
            }
            public function recordCache(array $result):bool{$this->completed=true;return true;}
        };
        try {
            $refused=false;try{Server::get('OCA\\Mail\\Service\\GuardedMessageMutations')->setFlag($uid,$r,$f);}catch(\Throwable){$refused=true;}
            $now=$manager->getMessage($uid,$messageId);
            return ['cache_stage_refused'=>$refused&&!$f->completed,'remote_proof_retained'=>($f->remote['outcome']??null)==='verified','old_cache_untouched'=>(bool)$now->getFlagFlagged()===$original];
        }finally{
            $b->setSyncNewToken($saved[0]);$b->setSyncChangedToken($saved[1]);$b->setSyncVanishedToken($saved[2]);$map->update($b);
            $account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$b->getAccountId());$manager->flagMessage($account,$b->getName(),$m->getUid(),'flagged',$original);
        }
    }
    public static function safety(string $uid):array {
        return ['outgoing'=>array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0)),
            'smtp'=>hash('sha256',is_file('/tmp/mcp-mail-probe-smtp.log')?(string)file_get_contents('/tmp/mcp-mail-probe-smtp.log'):'')];
    }
    public static function read(string $uid,int $messageId):array {
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$m=$manager->getMessage($uid,$messageId);$b=$manager->getMailbox($uid,$m->getMailboxId());$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$b->getAccountId());
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);
        try{$q=new \Horde_Imap_Client_Fetch_Query();$q->flags();$flags=$client->fetch($b->getName(),$q,['ids'=>new \Horde_Imap_Client_Ids([$m->getUid()])])->first()->getFlags();return ['remote'=>in_array(\Horde_Imap_Client::FLAG_FLAGGED,$flags,true),'cached'=>(bool)$m->getFlagFlagged(),'seen_remote'=>in_array(\Horde_Imap_Client::FLAG_SEEN,$flags,true),'seen_cached'=>(bool)$m->getFlagSeen()];}
        finally{$client->logout();}
    }
    public static function proof(string $uid,int $accountId): array {
        if(!interface_exists('OCA\\Mail\\Service\\MutationFence'))throw new \RuntimeException('Required native guarded mutation dependency is missing.');
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$factory=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory');$mapper=Server::get('OCA\\Mail\\IMAP\\MessageMapper');
        $name='mcp-c1-'.bin2hex(random_bytes(6));$folder=$manager->createMailbox($account,$name);$c=$factory->getClient($account,false);
        $c->setParam('read_timeout',3);$c->setParam('timeout',3);
        $before=array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));
        $smtp=hash('sha256',is_file('/tmp/mcp-mail-probe-smtp.log')?(string)file_get_contents('/tmp/mcp-mail-probe-smtp.log'):'');
        $fence=static function(?\Closure $before=null):object{return new class($before) implements \OCA\Mail\Service\MutationFence {
            public array $proof=[];
            public function __construct(private ?\Closure $before){}
            public function beforePreconditions(array $binding):bool{return true;}
            public function beforeWire(array $binding):bool{return $this->before===null?true:($this->before)();}
            public function recordRemote(array $proof):bool{$this->proof=$proof;return true;}
            public function beforeCache(array $binding):bool{return true;}
            public function recordCache(array $result):bool{return true;}
        };};
        $read=static function(object $client,string $box,int $id):bool{$q=new \Horde_Imap_Client_Fetch_Query();$q->flags();return in_array(\Horde_Imap_Client::FLAG_FLAGGED,$client->fetch($box,$q,['ids'=>new \Horde_Imap_Client_Ids([$id])])->first()->getFlags(),true);};
        try {
            $remoteUid=$mapper->save($c,$folder,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: guarded\r\nMessage-ID: <duplicate@example.test>\r\n\r\nGuard fixture",[]);
            $c->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);$generation=(string)$c->status($name,\Horde_Imap_Client::STATUS_UIDVALIDITY)['uidvalidity'];
            $r=['account_id'=>$accountId,'message_id'=>1,'mailbox_id'=>$folder->getId(),'mailbox'=>$name,'generation'=>$generation,'uid'=>(string)$remoteUid,'flag'=>'flagged','value'=>true];
            $c->setFlagGuarded($r,$fence());$applied=$read($c,$name,$remoteUid);
            $already=$c->setFlagGuarded($r,$fence())['outcome']==='already_desired';
            $r['value']=false;$c->setFlagGuarded($r,$fence());$cleared=!$read($c,$name,$remoteUid);
            $r['value']=true;$old=$r;$old['generation']=(string)((int)$generation+1);$oldRefused=false;
            try{$c->setFlagGuarded($old,$fence());}catch(\Throwable){$oldRefused=true;}
            $reconnect=false;try{$c->setFlagGuarded($r,$fence(static function()use($c):bool{$c->logout();return true;}));}catch(\Throwable){$reconnect=true;}
            $refused=false;try{$c->setFlagGuarded($r,$fence(static fn():bool=>false));}catch(\Throwable){$refused=true;}
            $untouched=!$read($c,$name,$remoteUid);
            $after=array_map(static fn($m)=>$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));
            return ['applied'=>$applied,'cleared'=>$cleared,'already_desired'=>$already,'old_generation_refused'=>$oldRefused,'reconnect_refused'=>$reconnect,'fence_refused'=>$refused,'wrong_message_untouched'=>$untouched,
                'no_send'=>$before===$after&&$smtp===hash('sha256',is_file('/tmp/mcp-mail-probe-smtp.log')?(string)file_get_contents('/tmp/mcp-mail-probe-smtp.log'):'')];
        }finally{$c->logout();$manager->deleteMailbox($account,$folder);}
    }
}
