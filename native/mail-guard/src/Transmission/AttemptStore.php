<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Mail\Drafts\DraftCoordinator;
/** Immutable payload and CAS state changes on the same authoritative owner session. */
final class AttemptStore {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    public function coordinator():DraftCoordinator {return DraftCoordinator::forDatabase($this->db);}
    private function query(array $where,array $columns=['*']):object {
        $q=$this->db->getQueryBuilder();$q->select(...$columns)->from('mail_mcp_deliveries');
        foreach($where as $column=>$value)$q->andWhere($q->expr()->eq($column,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));return $q;
    }
    public function find(string $uid,string $id):array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new \RuntimeException('Delivery unavailable.');
        $r=$this->query(['user_id'=>$uid,'id'=>$id])->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}if($row===false)throw new \RuntimeException('Delivery unavailable.');return $row;
    }
    public function replay(string $uid,string $key):?array {$r=$this->query(['user_id'=>$uid,'operation_key'=>hash('sha256',$key)])->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}return $row===false?null:$row;}
    public function assertFree(string $uid,int $draftId):void {
        $q=$this->query(['user_id'=>$uid,'draft_id'=>$draftId],['id']);$q->andWhere($q->expr()->neq('state',$q->createNamedParameter('cancelled')))->andWhere($q->expr()->neq('state',$q->createNamedParameter('blocked')))->setMaxResults(1);$r=$q->executeQuery();try{$held=$r->fetch()!==false;}finally{$r->closeCursor();}if($held)throw new \RuntimeException('Draft is held by an approved delivery.');
    }
    private function transaction(string $uid,\Closure $effect):mixed {
        $guard=$this->coordinator();$scope=$guard->scope();if($scope->uid!==$uid)throw new \RuntimeException('Delivery owner changed.');$guard->assertNoTransaction();
        $schema=$this->db->createSchema();if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL&&strtolower((string)($schema->getTable($this->config->getSystemValueString('dbtableprefix','oc_').'mail_mcp_deliveries')->getOptions()['engine']??''))!=='innodb')throw new \RuntimeException('Delivery storage is not transactional.');
        $this->db->beginTransaction();try{$scope->assertOwned();$scope->markWrite();$result=$effect();$scope->assertOwned();$this->db->commit();$scope->assertOwned();$guard->assertNoTransaction();return $result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function insert(array $row):void {$this->transaction($row['user_id'],function()use($row):void {$this->assertFree($row['user_id'],(int)$row['draft_id']);$q=$this->db->getQueryBuilder();$q->insert('mail_mcp_deliveries');foreach($row as $key=>$value)$q->setValue($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:($value===null?IQueryBuilder::PARAM_NULL:IQueryBuilder::PARAM_STR)));if($q->executeStatement()!==1)throw new \RuntimeException('Delivery admission unavailable.');});}
    public function transition(array $row,string $to):bool {
        if(!DeliveryState::allows($row['state'],$to))return false;
        return $this->cas($row,'state',$row['state'],$to,$to==='accepted'?['sent_state'=>'pending']:[]);
    }
    public function sent(array $row,string $from,string $to,array $proof=[]):bool {
        if($row['state']!=='accepted'||!in_array([$from,$to],[['pending','attempting'],['attempting','verified'],['attempting','uncertain']],true))return false;
        return $this->cas($row,'sent_state',$from,$to,$proof===[]?[]:['sent_proof'=>json_encode($proof,JSON_THROW_ON_ERROR)]);
    }
    private function cas(array $row,string $field,string $from,string $to,array $extra):bool {
        return $this->transaction($row['user_id'],function()use($row,$field,$from,$to,$extra):bool {
            $q=$this->db->getQueryBuilder();$q->update('mail_mcp_deliveries')->set($field,$q->createNamedParameter($to));foreach($extra as $key=>$value)$q->set($key,$q->createNamedParameter($value));
            foreach(['id'=>$row['id'],'user_id'=>$row['user_id'],'revision'=>$row['revision'],$field=>$from] as $key=>$value)$q->andWhere($q->expr()->eq($key,$q->createNamedParameter($value)));
            return $q->executeStatement()===1;
        });
    }
    public function due(int $limit):array {
        $q=$this->query(['state'=>'queued'],['id','user_id','connection_id','source_tool','account_id']);$q->andWhere($q->expr()->lte('send_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT)))->orderBy('send_at')->addOrderBy('created_at')->setMaxResults(max(1,min(50,$limit)));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        return array_map(static fn(array $r):array=>['id'=>$r['id'],'uid'=>$r['user_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>$r['source_tool'],'accountId'=>(int)$r['account_id']],$rows);
    }
    /** Bounded owner/connection metadata only; no artifact reads and no transmission-gate dependency. */
    public function queuedAfter(string $cursor,int $limit=25):array {
        if($cursor!==''&&preg_match('/^[a-f0-9]{64}$/D',$cursor)!==1)throw new \RuntimeException('Invalid queue audit cursor.');
        $q=$this->query(['state'=>'queued'],['id','user_id','connection_id','source_tool','account_id']);
        if($cursor!=='')$q->andWhere($q->expr()->gt('id',$q->createNamedParameter($cursor)));
        $q->orderBy('id')->setMaxResults(max(1,min(25,$limit)));$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        return array_map(static fn(array $r):array=>['id'=>$r['id'],'uid'=>$r['user_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>$r['source_tool'],'accountId'=>(int)$r['account_id']],$rows);
    }
    public function hasUnresolved(string $uid,int $accountId):bool {
        $q=$this->query(['user_id'=>$uid,'account_id'=>$accountId],['id']);$q->andWhere($q->expr()->neq('state',$q->createNamedParameter('cancelled')))->andWhere($q->expr()->neq('state',$q->createNamedParameter('blocked')))->andWhere($q->expr()->orX($q->expr()->neq('state',$q->createNamedParameter('accepted')),$q->expr()->neq('sent_state',$q->createNamedParameter('verified'))))->setMaxResults(1);
        $r=$q->executeQuery();try{return $r->fetch()!==false;}finally{$r->closeCursor();}
    }
}
