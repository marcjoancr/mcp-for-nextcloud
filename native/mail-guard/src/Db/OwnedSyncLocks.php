<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCA\Mail\Sync\MailboxLease;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** One physical mutex across all native sync criteria; DTO identity also fences shutdown callbacks. */
trait OwnedSyncLocks {
    private array $ownedSync=[];
    private function acquireOwnedSync(Mailbox $box,string $attr):int {
        $id=$box->getId();$entry=$this->ownedSync[$id]??null;
        if($entry!==null&&($entry['box']!==$box||isset($entry['types'][$attr])))throw \OCA\Mail\Exception\MailboxLockedException::from($box);
        if($entry===null){try{$config=\OCP\Server::get(\OCP\IConfig::class);if($config->getSystemValue('dbreplica',[])!==[]||$config->getSystemValue('dbsharding',[])!==[])throw new \RuntimeException('Owned sync requires the primary.');$lease=MailboxLease::acquire($this->db,$config->getSystemValueString('dbtableprefix','oc_'),$id,$config);}catch(\Throwable $e){throw \OCA\Mail\Exception\MailboxLockedException::from($box);} $this->ownedSync[$id]=['box'=>$box,'lease'=>$lease,'types'=>[]];}
        $this->ownedSync[$id]['lease']->assertOwned();$now=$this->timeFactory->getTime();
        try{$this->syncTimestamp($id,$attr,$now);$this->ownedSync[$id]['types'][$attr]=true;return $now;}catch(\Throwable $e){if($this->ownedSync[$id]['types']===[]){$this->ownedSync[$id]['lease']->release();unset($this->ownedSync[$id]);}throw $e;}
    }
    private function syncTimestamp(int $id,string $attr,?int $time):void {
        if(!in_array($attr,['sync_new_lock','sync_changed_lock','sync_vanished_lock'],true))throw new \LogicException('Unknown sync criterion.');
        $q=$this->db->getQueryBuilder();$q->update('mail_mailboxes')->set($attr,$q->createNamedParameter($time,$time===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function releaseOwnedSync(Mailbox $box,string $attr):void {
        $id=$box->getId();$e=$this->ownedSync[$id]??null;if($e===null||$e['box']!==$box||!isset($e['types'][$attr]))return;
        try{$e['lease']->assertOwned();$this->syncTimestamp($id,$attr,null);}catch(\Throwable){/* No metadata or release through a lost connection. */}
        unset($this->ownedSync[$id]['types'][$attr]);if($this->ownedSync[$id]['types']===[]){$e['lease']->release();unset($this->ownedSync[$id]);}
    }
    public function requireSyncLease(Mailbox $box):MailboxLease {$e=$this->ownedSync[$box->getId()]??null;if($e===null||$e['box']!==$box)throw new \RuntimeException('Native synchronization has no owner.');$e['lease']->assertOwned();return $e['lease'];}
    public function currentSyncLease(int $id):?MailboxLease {$e=$this->ownedSync[$id]??null;if($e===null)return null;$e['lease']->assertOwned();return $e['lease'];}
}
