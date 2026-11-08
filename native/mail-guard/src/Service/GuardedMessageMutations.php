<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Db\{MailboxMapper,MessageMapper};
use OCA\Mail\Events\{MessageFlaggedEvent,GuardedMessageFlaggedEvent};
use OCA\Mail\IMAP\IMAPClientFactory;
use OCP\{IDBConnection,IConfig};
use OCP\EventDispatcher\IEventDispatcher;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Native-owned dependency. No remote delete/move, draft flush, outbox or SMTP path exists here. */
final class GuardedMessageMutations {
    public const CONTRACT='mcp-mail-flags-v1';
    public function __construct(private AccountService $accounts,private MailManager $manager,private MailboxMapper $mailboxes,
        private MessageMapper $messages,private IMAPClientFactory $clients,private IDBConnection $db,private IConfig $config,private IEventDispatcher $events){}
    public function setFlag(string $ownerUid,array $request,MutationFence $fence): array {
        $account=$this->accounts->find($ownerUid,$request['account_id']);
        if(!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true)||$this->config->getSystemValueBool('app.mail.debug',false)||$account->getMailAccount()->getDebug())throw new \RuntimeException('Unsafe native transport policy.');
        $this->target($ownerUid,$request);
        $client=$this->clients->getClient($account,false);
        try {$proof=$client->setFlagGuarded($request,$fence);}finally{$client->logout();}
        if(!$fence->beforeCache($request))throw new \RuntimeException('Cache stage admission revoked.');
        // No network I/O under row locks. Native sync/delete statements contend on these same immutable rows.
        $this->db->beginTransaction();
        try {
            foreach([['mail_mailboxes',$request['mailbox_id']],['mail_messages',$request['message_id']]] as [$table,$id]){
                $q=$this->db->getQueryBuilder();$q->select('id')->from($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->forUpdate();
                $r=$q->executeQuery();try{if($r->fetchOne()===false)throw new \RuntimeException('Cache identity disappeared.');}finally{$r->closeCursor();}
            }
            [$message,$mailbox]=$this->target($ownerUid,$request);
            $message->setFlag($request['flag'],$request['value']);$this->messages->update($message);
            $this->db->commit();
        } catch(\Throwable $e){$this->db->rollBack();throw $e;}
        // A crash or listener failure here remains cache_pending; never blindly redispatch this webhook-compatible event.
        $this->events->dispatch(MessageFlaggedEvent::class,new GuardedMessageFlaggedEvent($account,$mailbox,$message,$request['flag'],$request['value']));
        $result=['state'=>'updated','message_id'=>$request['message_id']];
        if(!$fence->recordCache($result))throw new \RuntimeException('Cache completion could not be recorded.');
        return ['remote'=>$proof,'cache'=>$result];
    }
    /** Typed adapter keeps the optional native interface out of callers' mandatory autoload graph. */
    public function fence(object $implementation): MutationFence {
        return new class($implementation) implements MutationFence {
            public function __construct(private object $implementation){}
            public function beforeWire(array $binding): bool {return $this->implementation->beforeWire($binding);}
            public function recordRemote(array $proof): bool {return $this->implementation->recordRemote($proof);}
            public function beforeCache(array $binding): bool {return $this->implementation->beforeCache($binding);}
            public function recordCache(array $result): bool {return $this->implementation->recordCache($result);}
        };
    }
    private function target(string $owner,array $r):array {
        if(array_diff(array_keys($r),['account_id','message_id','mailbox_id','mailbox','generation','uid','flag','value','profile_revision','cache_revision'])!==[]||!in_array($r['flag']??null,['seen','flagged'],true)||!is_bool($r['value']??null))throw new \InvalidArgumentException('Invalid mutation request.');
        $message=$this->manager->getMessage($owner,$r['message_id']);$mailbox=$this->mailboxes->findById($r['mailbox_id']);
        if($message->getMailboxId()!==$mailbox->getId()||$mailbox->getAccountId()!==$r['account_id']||$mailbox->getName()!==$r['mailbox']||(string)$message->getUid()!==$r['uid'])throw new \RuntimeException('Native identity changed.');
        $tokens=[$mailbox->getSyncNewToken(),$mailbox->getSyncChangedToken(),$mailbox->getSyncVanishedToken()];
        if(hash('sha256',json_encode($tokens,JSON_THROW_ON_ERROR))!==$r['cache_revision'])throw new \RuntimeException('Native cache revision changed.');
        foreach($tokens as $token){
            $decoded=is_string($token)?base64_decode($token,true):false;
            if($decoded===false||!preg_match('/(?:^|,)V([1-9][0-9]*)(?:,|$)/D',$decoded,$match)||$match[1]!==$r['generation'])throw new \RuntimeException('Native cache generation changed.');
        }
        return [$message,$mailbox];
    }
    public function move(string $ownerUid,array $request,MutationFence $fence): array {throw new \RuntimeException('MOVE/COPYUID contract not verified.');}
    public function reconcileCache(string $ownerUid,array $receipt,MutationFence $fence): array {throw new \RuntimeException('Event completion cannot be safely replayed.');}
}
