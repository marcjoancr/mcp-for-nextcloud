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
use OCP\AppFramework\Utility\ITimeFactory;
/** Conservative encrypted-byte reservations. Failed writes retain their charge; expiry never renews an identity. */
final class FormsSpoolMapper {
    public const JOB_BYTES=134217728;
    public const USER_BYTES=536870912;
    public const INSTANCE_BYTES=4294967296;
    public const RETENTION_SECONDS=604800;
    public const MAX_PARTS=4096;
    public function __construct(private IDBConnection $db,private ITimeFactory $clock,private PolicyStateMapper $mutex) {}
    private function identity(string $job):void {
        if(preg_match('/^[a-f0-9]{64}$/D',$job)!==1)throw new \InvalidArgumentException('Invalid Forms cache identity.');
    }
    private function transaction(callable $work):mixed {
        if($this->db->inTransaction())throw new \RuntimeException('Forms cache accounting requires an independent transaction.');
        $this->db->beginTransaction();
        try {$result=$work();$this->db->commit();return $result;}
        catch(\Throwable $error) {if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    private function row(string $job):?array {
        $this->identity($job);$q=$this->db->getQueryBuilder();
        $r=$q->select('*')->from('mcp_forms_spool')->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeQuery();
        try {$row=$r->fetch();return $row===false?null:$row;}finally{$r->closeCursor();}
    }
    private function lock(string $job):array {
        $this->identity($job);$q=$this->db->getQueryBuilder();
        $n=$q->update('mcp_forms_spool')->set('version',$q->createFunction('version + 1'))
            ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))
            ->andWhere($q->expr()->lt('version',$q->createNamedParameter('9223372036854775806')))->executeStatement();
        if($n!==1)throw new \RuntimeException('Forms cache reservation is unavailable.');
        return $this->row($job)??throw new \RuntimeException('Forms cache reservation is unavailable.');
    }
    private function active(array $row,?string $uid=null):void {
        if(($row['io_token']??'')!==''||!in_array($row['state'],['active','sealed'],true)||(int)$row['expires_at']<=$this->clock->getTime()||($uid!==null&&$row['actor_uid']!==$uid))
            throw new \RuntimeException('Forms result cache expired or is unavailable. Approve a new snapshot.');
    }
    public function reserve(string $job,string $uid):void {
        $this->identity($job);
        if($uid===''||strlen($uid)>64)throw new \InvalidArgumentException('Invalid Forms cache owner.');
        $this->transaction(function()use($job,$uid):void {
            $this->mutex->acquire();$old=$this->row($job);
            if($old!==null){$this->active($old,$uid);return;}
            foreach([null,$uid] as $owner) {
                $q=$this->db->getQueryBuilder();$q->select($q->createFunction('COALESCE(SUM(reserved_bytes),0) AS bytes'),$q->createFunction('COUNT(*) AS jobs'))->from('mcp_forms_spool')
                    ->where($q->expr()->neq('state',$q->createNamedParameter('expired')));
                if($owner!==null)$q->andWhere($q->expr()->eq('actor_uid',$q->createNamedParameter($owner)));
                $r=$q->executeQuery();try{$usage=$r->fetch();}finally{$r->closeCursor();}
                if($usage===false||(int)$usage['bytes']+self::JOB_BYTES>($owner===null?self::INSTANCE_BYTES:self::USER_BYTES)||(int)$usage['jobs']>=($owner===null?4096:256))
                    throw new \RuntimeException('Forms temporary result storage is full. Wait for expired cache cleanup.');
            }
            $q=$this->db->getQueryBuilder();$q->insert('mcp_forms_spool');
            foreach(['job_id'=>$job,'actor_uid'=>$uid,'state'=>'active','version'=>1,'reserved_bytes'=>self::JOB_BYTES,'used_bytes'=>0,'expires_at'=>$this->clock->getTime()+self::RETENTION_SECONDS] as $name=>$value)
                $q->setValue($name,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
            $q->executeStatement();
        });
    }
    public function assertActive(string $job,?string $uid=null):void {$this->active($this->row($job)??throw new \RuntimeException('Forms cache reservation is unavailable.'),$uid);}
    public function charge(string $job,string $key,int $bytes):void {
        $this->identity($key);if($bytes<1||$bytes>self::JOB_BYTES)throw new \RuntimeException('Forms encrypted artifact exceeds the cache quota.');
        $this->transaction(function()use($job,$key,$bytes):void {
            $row=$this->lock($job);$this->active($row);
            $q=$this->db->getQueryBuilder();$r=$q->select('cipher_bytes')->from('mcp_forms_spool_parts')
                ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->andWhere($q->expr()->eq('part_key',$q->createNamedParameter($key)))->executeQuery();
            try{$existing=$r->fetch();}finally{$r->closeCursor();}
            if($existing!==false){if((int)$existing['cipher_bytes']!==$bytes)throw new \RuntimeException('Forms ciphertext reservation changed.');return;}
            if($row['state']!=='active')throw new \RuntimeException('Forms result cache is sealed.');
            if((int)$row['used_bytes']+$bytes>(int)$row['reserved_bytes']||(int)$row['part_count']>=self::MAX_PARTS)
                throw new \RuntimeException('Forms encrypted artifacts exceed the cache quota.');
            $q=$this->db->getQueryBuilder();$q->insert('mcp_forms_spool_parts')
                ->setValue('job_id',$q->createNamedParameter($job))->setValue('part_key',$q->createNamedParameter($key))
                ->setValue('cipher_bytes',$q->createNamedParameter($bytes,IQueryBuilder::PARAM_INT))->executeStatement();
            $q=$this->db->getQueryBuilder();$q->update('mcp_forms_spool')->set('used_bytes',$q->createNamedParameter((int)$row['used_bytes']+$bytes,IQueryBuilder::PARAM_INT))->set('part_count',$q->createNamedParameter((int)$row['part_count']+1,IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();
        });
    }
    /** Durable evidence before any physical allocation; a provider TTL is not a proof of IO completion. */
    public function beginWrite(string $job):string {
        return $this->transaction(function()use($job):string {
            $row=$this->lock($job);$this->active($row);
            if($row['state']!=='active')throw new \RuntimeException('Forms result cache is sealed.');
            $token=bin2hex(random_bytes(32));$q=$this->db->getQueryBuilder();
            $q->update('mcp_forms_spool')->set('io_token',$q->createNamedParameter($token))->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();
            return $token;
        });
    }
    public function finishWrite(string $job,string $token):void {
        $this->transaction(function()use($job,$token):void {
            $row=$this->lock($job);
            if($token===''||!hash_equals($row['io_token'],$token)||$row['state']!=='active')throw new \RuntimeException('Forms physical write ownership changed.');
            $q=$this->db->getQueryBuilder();$q->update('mcp_forms_spool')->set('io_token',$q->createNamedParameter(''))
                ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();
        });
    }
    public function seal(string $job):void {
        $this->transaction(function()use($job):void {
            $this->mutex->acquire();$row=$this->lock($job);$this->active($row);
            if($row['state']==='sealed')return;
            $q=$this->db->getQueryBuilder();$q->update('mcp_forms_spool')->set('state',$q->createNamedParameter('sealed'))
                ->set('reserved_bytes',$q->createNamedParameter((int)$row['used_bytes'],IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();
        });
    }
    public function expiring(int $limit):array {
        $q=$this->db->getQueryBuilder();$r=$q->select('job_id')->from('mcp_forms_spool')
            ->where($q->expr()->orX($q->expr()->eq('state',$q->createNamedParameter('cleaning')),
                $q->expr()->andX($q->expr()->in('state',$q->createNamedParameter(['active','sealed'],IQueryBuilder::PARAM_STR_ARRAY)),$q->expr()->lte('expires_at',$q->createNamedParameter($this->clock->getTime(),IQueryBuilder::PARAM_INT)))))
            ->andWhere($q->expr()->eq('io_token',$q->createNamedParameter('')))->orderBy('expires_at','ASC')->setMaxResults(min(10,max(1,$limit)))->executeQuery();
        try{return array_column($r->fetchAll(),'job_id');}finally{$r->closeCursor();}
    }
    /** Caller holds the same native per-job lock as all physical cache reads/writes. */
    public function retire(string $job):bool {
        return $this->transaction(function()use($job):bool {
            $row=$this->lock($job);if($row['state']==='expired'||$row['io_token']!=='')return false;
            if(in_array($row['state'],['active','sealed'],true)&&(int)$row['expires_at']>$this->clock->getTime())return false;
            $q=$this->db->getQueryBuilder();$q->update('mcp_forms_spool')->set('state',$q->createNamedParameter('cleaning'))->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();return true;
        });
    }
    public function cipherBytes(string $job,string $key):int {
        $this->identity($job);$this->identity($key);$q=$this->db->getQueryBuilder();
        $r=$q->select('cipher_bytes')->from('mcp_forms_spool_parts')->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))
            ->andWhere($q->expr()->eq('part_key',$q->createNamedParameter($key)))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        if($row===false)throw new \RuntimeException('Forms ciphertext reservation is unavailable.');
        return (int)$row['cipher_bytes'];
    }
    public function parts(string $job,int $limit=100):array {
        $this->identity($job);$q=$this->db->getQueryBuilder();$r=$q->select('part_key')->from('mcp_forms_spool_parts')
            ->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->orderBy('part_key','ASC')->setMaxResults(min(self::MAX_PARTS,max(1,$limit)))->executeQuery();
        try{return array_column($r->fetchAll(),'part_key');}finally{$r->closeCursor();}
    }
    public function forget(string $job,string $key):void {
        $this->transaction(function()use($job,$key):void {
            if($this->lock($job)['state']!=='cleaning')throw new \RuntimeException('Forms cache cleanup was not claimed.');
            $this->identity($key);$q=$this->db->getQueryBuilder();$q->delete('mcp_forms_spool_parts')->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))
                ->andWhere($q->expr()->eq('part_key',$q->createNamedParameter($key)))->executeStatement();
        });
    }
    /** Called only after the owned folder and every charged part are verified absent. Tombstone prevents resurrection. */
    public function finish(string $job):void {
        $this->transaction(function()use($job):void {
            $this->mutex->acquire();$row=$this->lock($job);if($row['state']==='expired')return;
            if($row['state']!=='cleaning'||$this->parts($job,1)!==[])throw new \RuntimeException('Forms cache cleanup is incomplete.');
            $q=$this->db->getQueryBuilder();$q->update('mcp_forms_spool')->set('state',$q->createNamedParameter('expired'))
                ->set('reserved_bytes',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('job_id',$q->createNamedParameter($job)))->executeStatement();
        });
    }
}
