<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Snooze;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Mail\Drafts\DraftCoordinator;
/** Metadata and exact C3 descriptors only. No content, headers or protocol clients. */
final class SnoozeStore {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    public function coordinator():DraftCoordinator {return DraftCoordinator::forDatabase($this->db);}
    private function query(array $where):object {$q=$this->db->getQueryBuilder();$q->select('*')->from('mail_mcp_snoozes');foreach($where as$k=>$v)$q->andWhere($q->expr()->eq($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));return $q;}
    private function one(object $q):?array {$r=$q->setMaxResults(1)->executeQuery();try{$v=$r->fetch();return $v===false?null:$v;}finally{$r->closeCursor();}}
    public function find(string $uid,string $id):array {if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new \RuntimeException('Snooze unavailable.');return $this->one($this->query(['user_id'=>$uid,'id'=>$id]))??throw new \RuntimeException('Snooze unavailable.');}
    public function metadata(string $uid,string $id):array {
        $q=$this->query(['user_id'=>$uid,'id'=>$id]);$q->select('id','user_id','account_id','connection_id','operation_key','revision','created_at','wake_at','timezone','state','placement_phase','wake_phase','source_mailbox_id','source_generation','source_uid','snooze_mailbox_id','current_mailbox_id','current_generation','current_uid','active_source','reason');return $this->one($q)??throw new \RuntimeException('Snooze unavailable.');
    }
    public function replay(string $uid,string $key):?array {return $this->one($this->query(['user_id'=>$uid,'operation_key'=>hash('sha256',$key)]));}
    public function assertAccount(string $uid,int $account):void {(new \OCA\Mail\Lifecycle\MailErasureStore($this->db))->assertAccount($uid,$account);}
    private function transaction(string $uid,\Closure $effect,?\Closure $authorize=null):mixed {
        $guard=$this->coordinator();$scope=$guard->scope();if($scope->uid!==$uid)throw new \RuntimeException('Snooze owner changed.');$guard->assertNoTransaction();
        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL&&strtolower((string)($this->db->createSchema()->getTable($this->config->getSystemValueString('dbtableprefix','oc_').'mail_mcp_snoozes')->getOptions()['engine']??''))!=='innodb')throw new \RuntimeException('Snooze storage must be transactional.');
        $this->db->beginTransaction();try{$scope->assertOwned();$authorize?->__invoke();$scope->markWrite();$out=$effect();$authorize?->__invoke();$scope->assertOwned();$this->db->commit();$scope->assertOwned();return $out;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function insert(array $row,\Closure $authorize):void {$this->transaction($row['user_id'],function()use($row):void {$this->assertAccount($row['user_id'],(int)$row['account_id']);$q=$this->db->getQueryBuilder();$q->insert('mail_mcp_snoozes');foreach($row as$k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:($v===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR)));if($q->executeStatement()!==1)throw new \RuntimeException('Snooze admission unavailable.');},$authorize);}
    /** CAS never reacquires/borrows a physical mailbox lease. Caller retains the ordered pair. */
    public function update(array $row,array $changes,?\Closure $authorize=null):array {
        return $this->transaction($row['user_id'],function()use($row,$changes):array {
            $allowed=['state','placement_phase','wake_phase','current_mailbox_id','current_generation','current_uid','placement_proof','wake_proof','wake_request','active_source','reason'];
            if(array_diff(array_keys($changes),$allowed)!==[])throw new \LogicException('Immutable snooze identity.');
            foreach(['placement_proof','wake_proof','wake_request']as$key)if(isset($changes[$key])&&strlen($changes[$key])>16384)throw new \RuntimeException('Snooze descriptor exceeds limit.');
            $changes['revision']=(int)$row['revision']+1;$q=$this->db->getQueryBuilder();$q->update('mail_mcp_snoozes');foreach($changes as$k=>$v)$q->set($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:($v===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR)));
            foreach(['id','user_id','revision','state','placement_phase','wake_phase']as$k)$q->andWhere($q->expr()->eq($k,$q->createNamedParameter($row[$k])));
            if($q->executeStatement()!==1)throw new \RuntimeException('Snooze state changed.');return array_replace($row,$changes);
        },$authorize);
    }
    public function cancel(array $r,\Closure $authorize):array {if(!SnoozeState::cancellable($r))throw new \RuntimeException('Snooze cancellation is too late or uncertain.');return $this->update($r,['state'=>'cancelled','active_source'=>null],$authorize);}
    public function block(array $r):array {if(!SnoozeState::releasable($r)||!in_array($r['state'],['placing','snoozed'],true))return $r;return $this->update($r,['state'=>'blocked','reason'=>'authorization_or_identity_changed','active_source'=>null]);}
    public function due(int $limit,string $cursor=''):array {if($cursor!==''&&preg_match('/^[a-f0-9]{64}$/D',$cursor)!==1)throw new \RuntimeException('Invalid snooze dispatch cursor.');return $this->page($cursor,max(1,min(25,$limit)),true);}
    public function queuedAfter(string $cursor,int $limit):array {if($cursor!==''&&preg_match('/^[a-f0-9]{64}$/D',$cursor)!==1)throw new \RuntimeException('Invalid snooze audit cursor.');return $this->page($cursor,max(1,min(25,$limit)),false);}
    private function page(string $cursor,int $limit,bool $due):array {
        $q=$this->query([]);$q->select('id','user_id','account_id','connection_id')->andWhere($q->expr()->in('state',$q->createNamedParameter(['placing','snoozed'],IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->neq('placement_phase',$q->createNamedParameter('wire_may_start')))->andWhere($q->expr()->eq('wake_phase',$q->createNamedParameter('prepared')));
        if($due)$q->andWhere($q->expr()->orX($q->expr()->eq('state',$q->createNamedParameter('placing')),$q->expr()->lte('wake_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))));
        $q->andWhere($q->expr()->gt('id',$q->createNamedParameter($cursor)));
        $q->orderBy('id')->setMaxResults($limit);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        return array_map(static fn(array $r):array=>['id'=>$r['id'],'uid'=>$r['user_id'],'accountId'=>(int)$r['account_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>'mail_snooze'],$rows);
    }
}
