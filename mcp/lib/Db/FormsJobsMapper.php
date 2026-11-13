<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;
use OCA\Mcp\Modules\Forms\Exports\FormsJobStore;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Version CAS is the only job lease. Private payloads include no unencrypted result data. */
final class FormsJobsMapper implements FormsJobStore {
    public function __construct(private IDBConnection $db,private ICrypto $crypto) {}
    public function get(string $id):?array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('mcp_forms_jobs')->where($q->expr()->eq('job_id',$q->createNamedParameter($id)))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        if(!$row)return null;
        $row['version']=(int)$row['version'];$row['updated_at']=(int)$row['updated_at'];
        $row['payload']=json_decode($this->crypto->decrypt($row['payload']),true,64,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function create(array $job):bool {
        $q=$this->db->getQueryBuilder();$q->insert('mcp_forms_jobs');
        foreach($job as $key=>$value) {
            if($key==='payload')$value=$this->crypto->encrypt(json_encode($value,JSON_THROW_ON_ERROR));
            $q->setValue($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
        }
        try{return $q->executeStatement()===1;}catch(Exception $e){if($e->getReason()===Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION)return false;throw $e;}
    }
    public function compareAndSwap(array $expected,array $next):bool {
        $q=$this->db->getQueryBuilder();return $q->update('mcp_forms_jobs')
            ->set('version',$q->createNamedParameter($next['version'],IQueryBuilder::PARAM_INT))
            ->set('state',$q->createNamedParameter($next['state']))
            ->set('updated_at',$q->createNamedParameter($next['updated_at'],IQueryBuilder::PARAM_INT))
            ->set('payload',$q->createNamedParameter($this->crypto->encrypt(json_encode($next['payload'],JSON_THROW_ON_ERROR))))
            ->where($q->expr()->eq('job_id',$q->createNamedParameter($expected['job_id'])))
            ->andWhere($q->expr()->eq('version',$q->createNamedParameter($expected['version'],IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter($expected['state'])))->executeStatement()===1;
    }
}
