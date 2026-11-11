<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCP\IDBConnection;
use OCA\Mail\Exception\DraftAdmissionFailure;
/** Held sources and identities stay unavailable to every native mutation path. */
final class DeliveryHolds {
    public static function assertFree(IDBConnection $db,string $column,int $id):void {
        if($column==='account_id')\OCA\Mail\Snooze\SnoozeHolds::account($db,$id);
        if(!in_array($column,['draft_id','account_id','alias_id'],true))throw new \LogicException('Invalid hold selector.');
        $q=$db->getQueryBuilder();$q->select('id')->from('mail_mcp_deliveries')->where($q->expr()->eq($column,$q->createNamedParameter($id,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->neq('state',$q->createNamedParameter('cancelled')))->andWhere($q->expr()->neq('state',$q->createNamedParameter('blocked')))->setMaxResults(1);
        if($column!=='draft_id')$q->andWhere($q->expr()->orX($q->expr()->neq('state',$q->createNamedParameter('accepted')),$q->expr()->neq('sent_state',$q->createNamedParameter('verified'))));
        $r=$q->executeQuery();try{$held=$r->fetch()!==false;}finally{$r->closeCursor();}if($held)throw new DraftAdmissionFailure('An approved delivery holds this native source or identity.');
    }
    public static function owner(IDBConnection $db,int $accountId):string {
        $q=$db->getQueryBuilder();$q->select('user_id')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($accountId,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}if($row===false)throw new DraftAdmissionFailure('Native account unavailable.');return $row['user_id'];
    }
}
