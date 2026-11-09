<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Db;

use OCA\Mcp\Mcp\Operations\OperationStore;
use OCP\IDBConnection;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

final class OperationsMapper implements OperationStore {
    public const TABLE = 'mcp_operations';
    public function __construct(private IDBConnection $db) {}

    public function get(string $scope, string $id): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from(self::TABLE)->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id', $q->createNamedParameter($id)))->executeQuery();
        try { $row = $r->fetch(); return $row === false ? null : $this->decode($row); }
        finally { $r->closeCursor(); }
    }

    public function reserve(array $row): bool {
        $q = $this->db->getQueryBuilder();
        $q->insert(self::TABLE);
        foreach ($row as $key => $value) {
            if ($key === 'resource_refs') $value = json_encode($value, JSON_THROW_ON_ERROR);
            $type = $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
            $q->setValue($key, $q->createNamedParameter($value, $type));
        }
        try { return $q->executeStatement() === 1; }
        catch (Exception $e) {
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) return false;
            throw $e;
        }
    }

    public function checkpoint(string $scope, string $id, array $refs): void {
        $old=$this->directSnapshot($scope,$id);
        $q = $this->db->getQueryBuilder();
        $n = $q->update(self::TABLE)->set('resource_refs', $q->createNamedParameter(json_encode($refs, JSON_THROW_ON_ERROR)))
            ->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('running')))
            ->andWhere($q->expr()->eq('resource_refs',$q->createNamedParameter($old)))->executeStatement();
        if ($n !== 1) throw new \RuntimeException('Operation checkpoint failed.');
    }

    /** Preserve all persisted evidence and the target claim when a direct commit acknowledgement is lost. */
    public function uncertainLocal(string $scope,string $id,string $tool,int $time): bool {
        $q=$this->db->getQueryBuilder();return $q->update(self::TABLE)->set('state',$q->createNamedParameter('uncertain'))->set('updated_at',$q->createNamedParameter($time,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('tool_name',$q->createNamedParameter($tool)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('running')))->executeStatement()===1;
    }

    public function finish(string $scope, string $id, string $state, array $refs, int $time): void {
        $old=$this->directSnapshot($scope,$id);
        $q = $this->db->getQueryBuilder();
        $q->update(self::TABLE)->set('state', $q->createNamedParameter($state))
            ->set('resource_refs', $q->createNamedParameter(json_encode($refs, JSON_THROW_ON_ERROR)))
            ->set('updated_at', $q->createNamedParameter($time, IQueryBuilder::PARAM_INT));
        if ($state !== 'uncertain') $q->set('target_key', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
        $n = $q->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('running')))
            ->andWhere($q->expr()->eq('resource_refs',$q->createNamedParameter($old)))->executeStatement();
        if ($n !== 1) throw new \RuntimeException('Operation completion could not be recorded.');
    }

    private function directSnapshot(string $scope,string $id):string {
        $row=$this->get($scope,$id);
        if($row===null||$row['state']!=='running'||isset($row['resource_refs']['mutation']))throw new \RuntimeException('Direct completion cannot change delegated evidence.');
        return json_encode($row['resource_refs'],JSON_THROW_ON_ERROR);
    }

    /** Token/phase admission plus a whole-receipt CAS prevents stale parents from replacing newer proof. */
    public function checkpointNative(string $scope, string $id, string $token, string $expectedPhase, array $refs): bool {
        $row=$this->get($scope,$id);
        if($row===null||$row['state']!=='running')return false;
        $old=$row['resource_refs'];$mutation=$old['mutation']??null;
        if($expectedPhase==='') {
            if($mutation!==null||($refs['phase']??null)!=='prepared'||!in_array($row['tool_name'],['mail_set_message_tag','mail_set_message_flag','mail_move_message','mail_archive_message','mail_trash_message','mail_restore_message','mail_create_mailbox','mail_rename_mailbox','mail_set_mailbox_subscription'],true)||!\OCA\Mcp\Modules\Mail\Mutations\MutationRequest::valid($refs['descriptor']??[])||\OCA\Mcp\Modules\Mail\Mutations\MutationRequest::tool($refs['descriptor'])!==$row['tool_name'])return false;
            $mutation=['token_hash'=>hash('sha256',$token)];
        } elseif(!is_array($mutation)||!hash_equals($mutation['token_hash']??'',hash('sha256',$token))||($mutation['phase']??null)!==$expectedPhase)return false;
        $next=['prepared'=>(($mutation['descriptor']['requires_trash_hook']??false)?'preconditions_may_start':'wire_may_start'),'preconditions_may_start'=>'wire_may_start','wire_may_start'=>'remote_verified','remote_verified'=>'cache_pending','cache_pending'=>'completed'];
        if($expectedPhase!==''&&($next[$expectedPhase]??null)!==($refs['phase']??null))return false;
        if(array_diff(array_keys($refs),['phase','descriptor','remote','cache','expires_at'])!==[]||($expectedPhase!==''&&(isset($refs['descriptor'])||isset($refs['expires_at']))))return false;
        $mutation=array_replace($mutation,$refs);
        $updated=$old;$updated['mutation']=$mutation;
        return $this->nativeCas($scope,$id,$old,$updated,null,0);
    }

    public function finishNative(string $scope, string $id, string $token, string $expectedPhase, string $state, array $refs, int $time): bool {
        $row=$this->get($scope,$id);$old=$row['resource_refs']??[];$mutation=$old['mutation']??null;
        if($row===null||$row['state']!=='running'||!is_array($mutation)||!hash_equals($mutation['token_hash']??'',hash('sha256',$token))||($mutation['phase']??null)!==$expectedPhase)return false;
        $required=$expectedPhase==='completed'?'succeeded':($expectedPhase==='prepared'?'failed_before_effect':'uncertain');
        if($state!==$required||$refs!==[])return false;
        return $this->nativeCas($scope,$id,$old,$old,$state,$time);
    }

    private function nativeCas(string $scope,string $id,array $old,array $refs,?string $state,int $time): bool {
        $q=$this->db->getQueryBuilder();
        $q->update(self::TABLE)->set('resource_refs',$q->createNamedParameter(json_encode($refs,JSON_THROW_ON_ERROR)));
        if($state!==null){
            $q->set('state',$q->createNamedParameter($state))->set('updated_at',$q->createNamedParameter($time,IQueryBuilder::PARAM_INT));
            if($state!=='uncertain')$q->set('target_key',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL));
        }
        return $q->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter('running')))
            ->andWhere($q->expr()->eq('resource_refs',$q->createNamedParameter(json_encode($old,JSON_THROW_ON_ERROR))))->executeStatement()===1;
    }

    /** Atomic exclusion between an approved repair and orphan cleanup. */
    public function claimPartial(string $scope, string $id, string $state): bool {
        if (!in_array($state, ['repairing', 'cleaning'], true)) throw new \InvalidArgumentException('Invalid partial claim.');
        return $this->transition($scope, $id, 'partial', $state);
    }

    public function settleRepair(string $scope, string $id, array $adopted = []): void {
        $row=$this->get($scope,$id);
        if ($row===null || $row['state']!=='repairing') return;
        // The new receipt is already durable. Only unused snapshots remain owned by this old receipt.
        $ids=array_column($adopted,'attachment_id');
        $refs=$row['resource_refs'];
        $refs['staged']=array_values(array_filter($refs['staged']??[],static fn(array $ref): bool=>!in_array($ref['attachment_id'],$ids,true)));
        $q=$this->db->getQueryBuilder();
        $q->update(self::TABLE)->set('state',$q->createNamedParameter('repaired'))
            ->set('resource_refs',$q->createNamedParameter(json_encode($refs,JSON_THROW_ON_ERROR)))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter('repairing')))->executeStatement();
    }

    private function transition(string $scope, string $id, string $from, string $to): bool {
        $q=$this->db->getQueryBuilder();
        return $q->update(self::TABLE)->set('state',$q->createNamedParameter($to))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter($from)))->executeStatement()===1;
    }

    public function cleanupCandidates(int $before, int $limit): array {
        $q=$this->db->getQueryBuilder();
        $r=$q->select('*')->from(self::TABLE)
            ->where($q->expr()->lt('updated_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('state',$q->createNamedParameter(['partial','repaired','cleaning'],IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('updated_at','ASC')->setMaxResults($limit)->executeQuery();
        try{return array_map($this->decode(...),$r->fetchAll());}finally{$r->closeCursor();}
    }

    public function cleaned(string $scope,string $id): void {
        $q=$this->db->getQueryBuilder();
        $q->update(self::TABLE)->set('state',$q->createNamedParameter('expired'))
            ->set('resource_refs',$q->createNamedParameter('{}'))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))
            ->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->in('state',$q->createNamedParameter(['cleaning','repaired'],IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
    }

    /** Remove private repair metadata after 30 days, retaining non-replay operation keys and public receipts. */
    public function compact(int $before): void {
        $q=$this->db->getQueryBuilder();
        $r=$q->select('*')->from(self::TABLE)
            ->where($q->expr()->lt('updated_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->in('state',$q->createNamedParameter(['succeeded','failed_before_effect','expired','awaiting_user'],IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->neq('actor_uid',$q->createNamedParameter('')))->setMaxResults(100)->executeQuery();
        try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        foreach($rows as $row){
            $refs=array_intersect_key($this->decode($row)['resource_refs'],array_flip(['draft_id','file_id','tag_id','account_id','source_tool','native_state','source','user_expires_at','protocols','mutation']));
            if(isset($refs['mutation']))$refs['mutation']=array_intersect_key($refs['mutation'],array_flip(['phase','remote','cache']));
            $u=$this->db->getQueryBuilder();
            $u->update(self::TABLE)->set('state',$u->createNamedParameter($row['state']==='awaiting_user'?'expired':$row['state']))->set('actor_uid',$u->createNamedParameter(''))
                ->set('effect_digest',$u->createNamedParameter(''))
                ->set('resource_refs',$u->createNamedParameter(json_encode($refs,JSON_THROW_ON_ERROR)))
                ->where($u->expr()->eq('scope_key',$u->createNamedParameter($row['scope_key'])))
                ->andWhere($u->expr()->eq('operation_id',$u->createNamedParameter($row['operation_id'])))->executeStatement();
        }
    }

    public function unresolvedAccount(string $uid,int $account,string $except=''): bool {
        $q=$this->db->getQueryBuilder();$r=$q->select('operation_id','tool_name','target_key','resource_refs')->from(self::TABLE)
            ->where($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->neq('operation_id',$q->createNamedParameter($except)))
            ->andWhere($q->expr()->in('state',$q->createNamedParameter(['running','uncertain','partial','repairing'],IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1001)->executeQuery();
        try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($rows)>1000)return true;
        foreach($rows as $row){$refs=$this->decode($row)['resource_refs'];
            if(($refs['account_id']??null)===$account || $row['target_key']===hash('sha256',$uid."\0account:".$account))return true;
            if(!isset($refs['account_id'])&&in_array($row['tool_name'],['mail_write_draft','mail_attach_files'],true))return true;
        }
        return false;
    }

    public function resolveAwaiting(string $scope,string $id,array $refs,int $time): bool {
        $q=$this->db->getQueryBuilder();return $q->update(self::TABLE)->set('state',$q->createNamedParameter('succeeded'))
            ->set('resource_refs',$q->createNamedParameter(json_encode($refs,JSON_THROW_ON_ERROR)))->set('updated_at',$q->createNamedParameter($time,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter('awaiting_user')))->executeStatement()===1;
    }

    public function list(string $scope, int $limit, int $offset): array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from(self::TABLE)->where($q->expr()->eq('scope_key', $q->createNamedParameter($scope)))
            ->orderBy('created_at', 'DESC')->addOrderBy('operation_id', 'ASC')->setMaxResults($limit)->setFirstResult($offset)->executeQuery();
        try { return array_map($this->decode(...), $r->fetchAll()); } finally { $r->closeCursor(); }
    }

    private function decode(array $row): array {
        $refs = json_decode($row['resource_refs'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($refs)) throw new \UnexpectedValueException('Invalid operation receipt.');
        $row['resource_refs'] = $refs;
        return $row;
    }
}
