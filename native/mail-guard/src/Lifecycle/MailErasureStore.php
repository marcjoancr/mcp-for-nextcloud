<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Lifecycle;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Metadata-only capture and SQL erasure. Never selects frozen artifacts or credentials. */
final class MailErasureStore {
    public function __construct(private IDBConnection $db){}
    private function query(string $table,array $where):object {$q=$this->db->getQueryBuilder();foreach($where as $k=>$v)$q->andWhere($q->expr()->eq($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));return $q;}
    private function rows(object $q):array {$r=$q->executeQuery();try{return $r->fetchAll();}finally{$r->closeCursor();}}
    private function insert(string $table,array $row):void {$q=$this->db->getQueryBuilder();$q->insert($table);foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));$q->executeStatement();}
    public function transaction(\Closure $work):mixed {
        if($this->db->inTransaction())throw new \RuntimeException('Erasure requires its own transaction.');
        $this->db->beginTransaction();try{$out=$work();$this->db->commit();return $out;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function capture(string $uid):string {
        return $this->transaction(function()use($uid):string {
            $id=bin2hex(random_bytes(32));$ids=[];
            foreach(['mail_accounts'=>'id','mail_mcp_deliveries'=>'account_id'] as $table=>$column)foreach($this->rows($this->query($table,['user_id'=>$uid])->select($column)->from($table)) as $row)$ids[(int)$row[$column]]=true;
            $this->insert('mail_mcp_erasure',['id'=>$id,'user_id'=>$uid,'state'=>'prepared','created_at'=>time()]);
            foreach(array_keys($ids) as $account)$this->insert('mail_mcp_erase_accounts',['erasure_id'=>$id,'account_id'=>$account]);
            return $id;
        });
    }
    public function confirm(string $id,string $uid):void {
        $q=$this->query('mail_mcp_erasure',['id'=>$id,'user_id'=>$uid,'state'=>'prepared']);$q->update('mail_mcp_erasure')->set('state',$q->createNamedParameter('pending'))->executeStatement();
    }
    public function preparedAbandoned(string $id):void {$this->transaction(function()use($id):void {if($this->rows($this->query('mail_mcp_erasure',['id'=>$id,'state'=>'prepared'])->select('id')->from('mail_mcp_erasure'))===[])return;$this->forget($id);});}
    private function forget(string $id):void {$this->query('mail_mcp_erase_accounts',['erasure_id'=>$id])->delete('mail_mcp_erase_accounts')->executeStatement();$this->query('mail_mcp_erasure',['id'=>$id])->delete('mail_mcp_erasure')->executeStatement();}
    public function pending(int $limit=25):array {return $this->rows($this->query('mail_mcp_erasure',['state'=>'pending'])->select('id','user_id')->from('mail_mcp_erasure')->orderBy('created_at')->addOrderBy('id')->setMaxResults(max(1,min(25,$limit))));}
    public function assertAccount(string $uid,int $account):void {
        $q=$this->db->getQueryBuilder();$q->select('e.id')->from('mail_mcp_erasure','e')->innerJoin('e','mail_mcp_erase_accounts','a',$q->expr()->eq('a.erasure_id','e.id'))->where($q->expr()->eq('e.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('e.state',$q->createNamedParameter('pending')))->andWhere($q->expr()->eq('a.account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        if($this->rows($q)!==[])throw new \OCA\Mail\Transmission\BeforeWireBlock('Original account consent was erased.');
    }
    /** Caller holds the physical owner lease and the account-removal transaction. */
    public function purgeAccount(string $uid,int $account):void {$this->query('mail_mcp_deliveries',['user_id'=>$uid,'account_id'=>$account])->delete('mail_mcp_deliveries')->executeStatement();}
    /** One bounded atomic batch; no ordinary hold bypass escapes this exact confirmed identity set. */
    public function erase(string $id,string $uid):void {
        $scope=\OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db)->scope();if($scope->uid!==$uid)throw new \RuntimeException('Erasure owner changed.');
        $this->transaction(function()use($id,$uid,$scope):void {
            if($this->rows($this->query('mail_mcp_erasure',['id'=>$id,'user_id'=>$uid,'state'=>'pending'])->select('id')->from('mail_mcp_erasure'))===[])return;
            $targets=$this->rows($this->query('mail_mcp_erase_accounts',['erasure_id'=>$id])->select('account_id')->from('mail_mcp_erase_accounts')->orderBy('account_id')->setMaxResults(25));$scope->markWrite();
            foreach($targets as $target){$account=(int)$target['account_id'];$owners=$this->rows($this->query('mail_accounts',['id'=>$account])->select('user_id')->from('mail_accounts'));
                if($owners!==[]&&$owners[0]['user_id']!==$uid)throw new \RuntimeException('Captured account ownership changed.');
                $this->purgeAccount($uid,$account);
                if($owners!==[]){$this->query('mail_aliases',['account_id'=>$account])->delete('mail_aliases')->executeStatement();$this->query('mail_accounts',['id'=>$account,'user_id'=>$uid])->delete('mail_accounts')->executeStatement();}
                $this->query('mail_mcp_erase_accounts',['erasure_id'=>$id,'account_id'=>$account])->delete('mail_mcp_erase_accounts')->executeStatement();
            }
            if(count($targets)<25)$this->forget($id);$scope->assertOwned();
        });$scope->assertOwned();
    }
}
