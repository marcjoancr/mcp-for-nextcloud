<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Lifecycle;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
/** Normal read/use quarantine; cleanup and delivery authorization retain separate exact-ID SQL lanes. */
final class AccountVisibility {
    public function __construct(private IDBConnection $db){}
    /** Apply before LIMIT/output, to a native account ID column from trusted query construction. */
    public function excludePending(IQueryBuilder $query,string $accountColumn):void {
        if(preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?$/D',$accountColumn)!==1)throw new \InvalidArgumentException('Invalid native account column.');
        $pending=$this->db->getQueryBuilder();
        $pending->select('erased_target.account_id')->from('mail_mcp_erase_accounts','erased_target')
            ->leftJoin('erased_target','mail_mcp_erasure','erased_owner',$pending->expr()->eq('erased_target.erasure_id','erased_owner.id'))
            ->where($pending->expr()->eq('erased_owner.state',$query->createNamedParameter('pending')));
        // Account IDs are global, non-null native primary keys. The subquery has no payload projection.
        $query->andWhere($query->expr()->notIn($accountColumn,$query->createFunction($pending->getSQL())));
    }
    /** A fresh existence+owner+visibility proof also denies old objects after the marker is purged. */
    public function assertVisible(string $uid,int $account):void {
        $q=$this->db->getQueryBuilder();$q->select('id')->from('mail_accounts')
            ->where($q->expr()->eq('id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->setMaxResults(1);
        $this->excludePending($q,'id');$r=$q->executeQuery();try{$visible=$r->fetch()!==false;}finally{$r->closeCursor();}
        if(!$visible)throw new DoesNotExistException('Native account unavailable.');
    }
}
