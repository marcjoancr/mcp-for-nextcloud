<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Db\{MailboxMapper,MessageMapper,TagMapper};
use OCA\Mail\IMAP\{IMAPClientFactory,MessageMapper as ImapMapper};
use OCA\Mail\Sync\{CacheSnapshot,RefreshBudget};
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** One owned mailbox. No whole-account sync, events, classifiers, body, draft, outbox or SMTP work. */
final class GuardedMailboxRefresh {
    public function __construct(private AccountService $accounts,private MailboxMapper $boxes,private MessageMapper $messages,private TagMapper $tags,private IMAPClientFactory $clients,private ImapMapper $imap,private CacheSnapshot $snapshots,private IDBConnection $db,private IConfig $config){}
    public function fence(object $implementation):RefreshFence {return new class($implementation) implements RefreshFence {
        public function __construct(private object $inner){}
        public function authorizeRead(array $b):bool{return $this->inner->authorizeRead($b);}
        public function beforeCache(array $b,array $p):bool{return $this->inner->beforeCache($b,$p);}
        public function recordCache(array $p):bool{return $this->inner->recordCache($p);}
    };}
    public function refresh(string $owner,array $r,RefreshFence $fence):array {
        $keys=['kind','account_id','mailbox_id','mailbox','profile_revision','cache_revision','allow_cache_reset'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||$r['kind']!=='refresh'||!is_bool($r['allow_cache_reset']))throw new \InvalidArgumentException('Invalid native refresh request.');foreach(['account_id','mailbox_id']as$k)if(!is_int($r[$k])||$r[$k]<1)throw new \InvalidArgumentException('Invalid native refresh identity.');
        $budget=new RefreshBudget();$this->snapshots->requireStorage();$account=$this->accounts->find($owner,$r['account_id']);$box=$this->boxes->findById($r['mailbox_id']);
        if($account->getUserId()!==$owner||$box->getAccountId()!==$r['account_id']||!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true)||$this->config->getSystemValueBool('app.mail.debug',false)||$account->getMailAccount()->getDebug())throw new \RuntimeException('Unsafe native refresh transport or owner.');
        $lockedBox=$box;$this->boxes->lockForNewSync($lockedBox);
        try {
            $lease=$this->boxes->requireSyncLease($box);$s=$this->target($owner,$r);if($s['needs_reset']&&!$r['allow_cache_reset'])throw new \RuntimeException('Refresh cache needs an approved reset.');
            $client=$this->clients->getClient($account,false);try{$remote=$client->collectRefresh($r,$owner,$this->imap,$fence,$budget);}finally{$client->logout();}
            $status=$remote['status'];$generation=$status['uidvalidity'];$reset=$s['needs_reset']||$s['generation']!==$generation;
            if($reset&&!$r['allow_cache_reset'])throw new \RuntimeException('Observed epoch requires an approved cache reset.');
            $proof=['outcome'=>'observed','mailbox_id'=>$r['mailbox_id'],'generation'=>$generation,'messages'=>$status['messages'],'reset'=>$reset];
            $budget->check();$lease->assertOwned();$this->target($owner,$r);if(!$fence->beforeCache($r,$proof))throw new \RuntimeException('Refresh cache admission revoked.');
            $this->db->beginTransaction();try {
                $lease->assertOwned();$current=$this->snapshots->capture($owner,$r['account_id'],$r['mailbox_id'],true);if($current['fingerprint']!==$r['cache_revision']||$current['mailbox']!==$r['mailbox'])throw new \RuntimeException('Refresh cache was superseded.');$budget->check();
                if($reset&&$current['messages']!==[])$this->messages->deleteByUid($box,...array_column($current['messages'],'uid'));
                // Unknown keys are ignored by the pinned native token parser. R invalidates reviewed plans even for an identical snapshot.
                $token=base64_encode('V'.$generation.',U'.$status['uidnext'].',H'.$status['highestmodseq'].',M'.$status['messages'].',R'.bin2hex(random_bytes(16)));
                $box=$this->boxes->findById($r['mailbox_id']);foreach(['setSyncNewToken','setSyncChangedToken','setSyncVanishedToken']as$set)$box->$set($token);$this->boxes->update($box);
                $version=$this->tags->captureTagSnapshot($account,$box->getId(),$generation,$lease);$known=[];if(!$reset)foreach($current['messages']as$m)$known[$m['uid']]=$m['id'];$insert=[];$update=[];$seen=[];$unseen=0;
                foreach($remote['messages']as$imap){$m=$imap->toDbMessage($box->getId(),$account->getMailAccount());$m->setTagSnapshotGeneration($generation);$m->setTagSnapshotVersion($version);$uid=$m->getUid();$seen[$uid]=true;if(!$m->getFlagSeen())$unseen++;if(isset($known[$uid])){$m->setId($known[$uid]);$update[]=$m;}else $insert[]=$m;}
                if($update!==[])$this->messages->updateBulk($account,true,...$update);if($insert!==[])$this->messages->insertBulk($account,...$insert);
                $vanished=array_values(array_diff(array_keys($known),array_keys($seen)));if($vanished!==[])$this->messages->deleteTagSnapshot($box,$version,...$vanished);
                $budget->check();$lease->assertOwned();$q=$this->db->getQueryBuilder();$q->update('mail_mailboxes')->set('messages',$q->createNamedParameter($status['messages'],IQueryBuilder::PARAM_INT))->set('unseen',$q->createNamedParameter($unseen,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($box->getId(),IQueryBuilder::PARAM_INT)))->executeStatement();
                $final=$this->snapshots->capture($owner,$r['account_id'],$r['mailbox_id'],true);if(count($final['messages'])!==$status['messages'])throw new \RuntimeException('Refresh cache count was not verified.');$observed=$remote['observed_at'];$marker=['observed_at'=>$observed,'generation'=>$generation,'fingerprint'=>$final['fingerprint']];$q=$this->db->getQueryBuilder();$q->update('mail_mailboxes')->set('refresh_snapshot',$q->createNamedParameter(json_encode($marker,JSON_THROW_ON_ERROR)))->where($q->expr()->eq('id',$q->createNamedParameter($box->getId(),IQueryBuilder::PARAM_INT)))->executeStatement();$budget->check();$lease->assertOwned();$this->db->commit();
            }catch(\Throwable $e){$this->db->rollBack();throw $e;}
            $result=['state'=>'refreshed','mailbox_id'=>$r['mailbox_id'],'generation'=>$generation,'messages'=>$status['messages'],'reset'=>$reset,'observed_at'=>$observed,'threads_state'=>'native_rebuild_pending'];if(!$fence->recordCache($result))throw new \RuntimeException('Refresh completion unavailable.');return ['remote'=>$proof,'cache'=>$result];
        }finally{$this->boxes->unlockFromNewSync($lockedBox);}
    }
    private function target(string $owner,array $r):array {$s=$this->snapshots->capture($owner,$r['account_id'],$r['mailbox_id']);if($s['fingerprint']!==$r['cache_revision']||$s['mailbox']!==$r['mailbox'])throw new \RuntimeException('Approved refresh cache changed.');return $s;}
}
