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
/** Job replacement and its exact encrypted cache debit share one primary transaction. */
final class FormsJobsMapper implements FormsJobStore {
    public function __construct(private IDBConnection $db,private ICrypto $crypto,private FormsSpoolMapper $budget) {}
    public function get(string $id):?array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('mcp_forms_jobs')->where($q->expr()->eq('job_id',$q->createNamedParameter($id)))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        if(!$row)return null;
        $row['version']=(int)$row['version'];$row['updated_at']=(int)$row['updated_at'];
        $row['payload']=json_decode($this->crypto->decrypt($row['payload']),true,64,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function create(array $job):bool {
        $encrypted=$this->crypto->encrypt(json_encode($job['payload'],JSON_THROW_ON_ERROR));
        try {
            return $this->budget->persistSQL($job['job_id'],$job['actor_uid'],strlen($encrypted),function()use($job,$encrypted):bool {
                // The per-job budget row serializes all creators; avoid a caught duplicate INSERT aborting PostgreSQL.
                $q=$this->db->getQueryBuilder();$r=$q->select('job_id')->from('mcp_forms_jobs')->where($q->expr()->eq('job_id',$q->createNamedParameter($job['job_id'])))->executeQuery();
                try{$exists=$r->fetch()!==false;}finally{$r->closeCursor();}
                if($exists)return false;
                $q=$this->db->getQueryBuilder();$q->insert('mcp_forms_jobs');
                foreach($job as $key=>$value) {
                    if($key==='payload')$value=$encrypted;
                    $q->setValue($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
                }
                return $q->executeStatement()===1;
            });
        } catch(Exception $error){if($error->getReason()===Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION)return false;throw $error;}
    }
    public function compareAndSwap(array $expected,array $next):bool {
        $oldContent=$expected['payload'];$newContent=$next['payload'];
        foreach(['current_step','last_step'] as $key){unset($oldContent[$key],$newContent[$key]);}
        return $this->replace($expected,$next,$oldContent===$newContent);
    }
    /** A verified historical receipt may survive cache expiry; no result content or original approval can change. */
    public function acknowledgeLinkedDelivery(array $expected,array $next,array $nativeReceipt):bool {
        if(!in_array($expected['state'],['delivering','delivery_uncertain'],true)||$next['state']!=='delivered'
            ||$next['version']!==$expected['version']+1||isset($expected['payload']['delivered'])||isset($next['payload']['current_step']))return false;
        $control=['state'=>true,'version'=>true,'updated_at'=>true,'payload'=>true];
        if(array_diff_key($expected,$control)!==array_diff_key($next,$control))return false;
        $step=$expected['state']==='delivering'?($expected['payload']['current_step']??[]):($expected['payload']['last_step']??[]);
        if(!is_string($step['key']??null)||!is_int($step['approved_version']??null))return false;
        $file=(new \OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation())->recovered($expected['actor_uid'],$expected,$step['key'],$nativeReceipt);
        if($file===null||($next['payload']['delivered']??null)!==$file
            ||($next['payload']['last_step']??null)!==['key'=>$step['key'],'approved_version'=>$step['approved_version'],
                'outcome'=>\OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation::historicalOutcome($expected,$nativeReceipt,$file)])return false;
        $oldContent=$expected['payload'];$newContent=$next['payload'];
        foreach(['current_step','last_step'] as $key){unset($oldContent[$key],$newContent[$key]);}
        unset($newContent['delivered']);
        if($oldContent!==$newContent)return false;
        return $this->replace($expected,$next,true);
    }
    private function replace(array $expected,array $next,bool $evidenceOnly):bool {
        if($next['actor_uid']!==$expected['actor_uid']||$next['job_id']!==$expected['job_id'])throw new \RuntimeException('Forms job identity changed.');
        $encrypted=$this->crypto->encrypt(json_encode($next['payload'],JSON_THROW_ON_ERROR));
        return $this->budget->persistSQL($expected['job_id'],$expected['actor_uid'],strlen($encrypted),function()use($expected,$next,$encrypted):bool {
            $q=$this->db->getQueryBuilder();return $q->update('mcp_forms_jobs')
                ->set('version',$q->createNamedParameter($next['version'],IQueryBuilder::PARAM_INT))->set('state',$q->createNamedParameter($next['state']))
                ->set('updated_at',$q->createNamedParameter($next['updated_at'],IQueryBuilder::PARAM_INT))->set('payload',$q->createNamedParameter($encrypted))
                ->where($q->expr()->eq('job_id',$q->createNamedParameter($expected['job_id'])))
                ->andWhere($q->expr()->eq('version',$q->createNamedParameter($expected['version'],IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('state',$q->createNamedParameter($expected['state'])))->executeStatement()===1;
        },$evidenceOnly);
    }
}
