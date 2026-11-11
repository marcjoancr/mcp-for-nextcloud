<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sieve;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Mail\Drafts\DraftCoordinator;
/** Candidate program is sensitive native data. Metadata queries never materialize it. */
final class SieveStore {
    public function __construct(private IDBConnection $db){}
    public function coordinator():DraftCoordinator{return DraftCoordinator::forDatabase($this->db);}
    private function query(#[\SensitiveParameter] array $where):object {$q=$this->db->getQueryBuilder();$q->select('id','user_id','account_id','connection_id','operation_key','source_tool','approval','revision','state','candidate','candidate_hash','original_name','original_hash','profile','observed_profile','created_at','follow_system')->from('mail_mcp_sieve');foreach($where as$k=>$v)$q->andWhere($q->expr()->eq($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));return $q;}
    private function one(object $q):?array {$r=$q->setMaxResults(1)->executeQuery();try{$v=$r->fetch();return $v===false?null:$v;}finally{$r->closeCursor();}}
    public function find(#[\SensitiveParameter] string $uid,#[\SensitiveParameter] string $id):array {if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new SieveFailure();return $this->one($this->query(['user_id'=>$uid,'id'=>$id]))??throw new SieveFailure();}
    public function replay(#[\SensitiveParameter] string $uid,#[\SensitiveParameter] string $key):?array {return $this->one($this->query(['user_id'=>$uid,'operation_key'=>hash('sha256',$key)]));}
    public static function origin(#[\SensitiveParameter] array $r):array {return ['accountId'=>(int)$r['account_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>$r['source_tool'],'operationKeyHash'=>$r['operation_key']];}
    public static function receipt(#[\SensitiveParameter] array $r):array {return ['id'=>$r['id'],'accountId'=>(int)$r['account_id'],'state'=>$r['state']==='completed'?'completed':'uncertain','phase'=>$r['state'],'revision'=>(string)$r['revision'],'safe_to_retry'=>false,'external_cas'=>false,'original_retained'=>true];}
    private function transaction(#[\SensitiveParameter] string $uid,\Closure $effect,\Closure $authorize):mixed {
        $g=$this->coordinator();$scope=$g->scope();if($scope->uid!==$uid)throw new SieveFailure();$g->assertNoTransaction();$this->db->beginTransaction();try{$authorize();$scope->markWrite();$out=$effect();$authorize();$scope->assertOwned();$this->db->commit();$scope->assertOwned();return $out;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function insert(#[\SensitiveParameter] array $row,\Closure $authorize):void {
        if(strlen($row['program'])>SieveBudget::SCRIPT)throw new SieveFailure();$this->transaction($row['user_id'],function()use($row){(new \OCA\Mail\Lifecycle\MailErasureStore($this->db))->assertAccount($row['user_id'],(int)$row['account_id']);$q=$this->db->getQueryBuilder();$q->insert('mail_mcp_sieve');foreach($row as$k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));if($q->executeStatement()!==1)throw new SieveFailure();},$authorize);
    }
    public function advance(#[\SensitiveParameter] array $row,#[\SensitiveParameter] string $state,\Closure $authorize,#[\SensitiveParameter] string $observedProfile=''):array {
        if($state==='completed'&&preg_match('/^[a-f0-9]{64}$/D',$observedProfile)!==1)throw new SieveFailure();$allowed=['prepared'=>'put_attempting','put_attempting'=>'installed','installed'=>'activation_attempting','activation_attempting'=>'active_observed','active_observed'=>'completed'];if(($allowed[$row['state']]??null)!==$state)throw new SieveFailure();
        return $this->transaction($row['user_id'],function()use($row,$state,$observedProfile){$q=$this->db->getQueryBuilder();$q->update('mail_mcp_sieve')->set('state',$q->createNamedParameter($state))->set('revision',$q->createNamedParameter((int)$row['revision']+1,IQueryBuilder::PARAM_INT));if($state==='completed')$q->set('observed_profile',$q->createNamedParameter($observedProfile));foreach(['id','user_id','revision','state']as$k)$q->andWhere($q->expr()->eq($k,$q->createNamedParameter($row[$k])));if($q->executeStatement()!==1)throw new SieveFailure();return array_replace($row,['state'=>$state,'revision'=>(int)$row['revision']+1,'observed_profile'=>$observedProfile]);},$authorize);
    }
}
