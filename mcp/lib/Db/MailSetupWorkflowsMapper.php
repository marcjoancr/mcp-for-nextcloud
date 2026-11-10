<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Db;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
class MailSetupWorkflowsMapper {
    private const TABLE='mcp_mail_setup';
    public function __construct(private IDBConnection $db){}
    public function create(string $scope,string $id,string $kind,?int $target,array $beforeIds,string $digest,string $before,int $time,int $expiry,?string $nativeContext=null): void {
        if(count($beforeIds)>1000)throw new \InvalidArgumentException('Too many setup account references.');
        $row=['scope_key'=>$scope,'operation_id'=>$id,'kind'=>$kind,'target_account_id'=>$target,'before_ids'=>json_encode($beforeIds,JSON_THROW_ON_ERROR),
            'intent_digest'=>$digest,'before_digest'=>$before,'phase'=>'pending','created_at'=>$time,'expires_at'=>$expiry];
        if($nativeContext!==null){if(preg_match('/^[a-f0-9]{64}$/D',$nativeContext)!==1)throw new \InvalidArgumentException('Invalid native setup context.');$row['native_context_id']=$nativeContext;}
        $q=$this->db->getQueryBuilder();$q->insert(self::TABLE);
        foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v,$v===null?IQueryBuilder::PARAM_NULL:(is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)));
        $q->executeStatement();
    }
    public function get(string $scope,string $id): ?array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from(self::TABLE)->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))->executeQuery();
        try{$row=$r->fetch();if($row===false)return null;$row['before_ids']=array_map('intval',json_decode($row['before_ids'],true,16,JSON_THROW_ON_ERROR));
            foreach(['target_account_id','observed_account_id'] as $k)if($row[$k]!==null)$row[$k]=(int)$row[$k];
            $row['native_context_id']??=null;return $row;
        }finally{$r->closeCursor();}
    }
    public function claim(string $scope,string $id,string $completion,int $now): bool {
        $q=$this->db->getQueryBuilder();return $q->update(self::TABLE)->set('phase',$q->createNamedParameter('binding'))->set('completion_id',$q->createNamedParameter($completion))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('phase',$q->createNamedParameter('pending')))->andWhere($q->expr()->gte('expires_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))->executeStatement()===1;
    }
    public function complete(string $scope,string $id,string $completion,int $account): bool {
        $q=$this->db->getQueryBuilder();return $q->update(self::TABLE)->set('phase',$q->createNamedParameter('completed'))->set('observed_account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('phase',$q->createNamedParameter('binding')))->andWhere($q->expr()->eq('completion_id',$q->createNamedParameter($completion)))->executeStatement()===1;
    }
    public function atomic(\Closure $work): mixed {
        $this->db->beginTransaction();try{$r=$work($this->db);$this->db->commit();return $r;}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function pendingForAccount(int $account,int $now): bool {
        $q=$this->db->getQueryBuilder();$r=$q->select('phase','expires_at')->from(self::TABLE)
            ->where($q->expr()->eq('target_account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('phase',$q->createNamedParameter(['pending','binding'],IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery();
        try{foreach($r->fetchAll() as $row)if($row['phase']==='binding'||(int)$row['expires_at']>=$now)return true;return false;}finally{$r->closeCursor();}
    }
    public function compact(int $before): void {
        $q=$this->db->getQueryBuilder();$q->delete(self::TABLE)->where($q->expr()->lt('expires_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('phase',$q->createNamedParameter(['pending','completed'],IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
    }
}
