<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
/** Initial delivery writes the file and activates its marker through one native operation. */
final class FormsLinkedActivation {
    /** Durable historical proof acknowledges the original activation; it asserts nothing about current output bytes. */
    public function recovered(string $uid,array $job,string $key,array $receipt):?array {
        $p=$job['payload'];$approval=$p['linked_approval']??[];$refs=$receipt['references']??[];$association=$refs['association_id']??null;
        if(($job['actor_uid']??null)!==$uid||($p['origin']['uid']??null)!==$uid||($p['origin']['connection']??0)<1
            ||($p['origin']['tool']??null)!=='forms_link_results_file'||($p['tool']??null)!=='forms_link_results_file'
            ||($approval['arguments']??null)!==$p['arguments']||($approval['destination']??null)!==$p['destination']
            ||($p['cache_protocol']??null)!==2||!is_string($p['native_runtime']??null)||preg_match('/^[a-f0-9]{64}$/D',$p['native_runtime'])!==1
            ||($approval['native_runtime']??null)!==$p['native_runtime']
            ||($approval['source_revision']??null)!==$p['source_revision']
            ||!is_string($p['linked_schema_digest']??null)||preg_match('/^[a-f0-9]{64}$/D',$p['linked_schema_digest'])!==1
            ||($approval['schema_digest']??null)!==$p['linked_schema_digest']
            ||($receipt['operation_key']??null)!==$key||($receipt['state']??null)!=='succeeded'
            ||($receipt['phases']['database']??null)!=='committed'||($receipt['phases']['files']??null)!=='saved_verified'
            ||($refs['form_id']??null)!==$p['arguments']['id']||($refs['source_revision']??null)!==$p['source_revision']
            ||!is_int($refs['file_id']??null)||$refs['file_id']<1||!is_string($association)||preg_match('/^[a-f0-9]{64}$/D',$association)!==1
            ||($refs['linked_export']??null)!==$association)
            return null;
        if(($p['destination']['overwrite']??false)&&($p['destination']['file_id']??null)!==$refs['file_id'])return null;
        return ['file_id'=>$refs['file_id'],'association_id'=>$association,'historical'=>true];
    }
    public static function historicalOutcome(array $job,array $receipt,array $file):array {
        return ['state'=>'succeeded','references'=>['job_id'=>$job['job_id'],'form_id'=>$job['payload']['arguments']['id'],'file_id'=>$file['file_id'],'linked_export'=>$file['association_id']],
            'phases'=>array_intersect_key($receipt['phases'],array_flip(['database','files','events']))];
    }
    public function activate(string $uid,array $job,object $guard,callable $source,callable $write):array {
        $p=$job['payload'];$approval=$p['linked_approval']??[];$origin=$p['origin'];
        if(($job['actor_uid']??null)!==$uid||($origin['uid']??null)!==$uid||($origin['connection']??0)<1
            ||($origin['tool']??null)!=='forms_link_results_file'
            ||($approval['arguments']??null)!==$p['arguments']||($approval['destination']??null)!==$p['destination']
            ||($approval['native_runtime']??null)!==FormsGuardIdentity::INSTALLED_MANIFEST_SHA256
            ||($approval['source_revision']??null)!==$p['source_revision']
            ||($approval['schema_digest']??null)!==FormsLinkedExport::schemaDigest($p['metadata'])
            ||!is_string($p['current_step']['key']??null))
            throw new \RuntimeException('The initial linked Forms approval changed.');
        $snapshot=$source('snapshot',['id'=>$p['arguments']['id']]);
        if(FormsExportJobs::sourceRevision($snapshot)!==$p['source_revision'])
            throw new \RuntimeException('The initial linked Forms source changed.');
        $input=array_replace($p['arguments'],['_mcp_origin'=>$origin,'_mcp_link_approval'=>$approval]);
        $result=$guard->apply($uid,'forms_link_results_file',$input,$snapshot['revision'],$p['current_step']['key'],[],$write);
        if(($result['phases']['database']??null)!=='committed'||($result['phases']['files']??null)!=='saved_verified'
            ||!is_array($result['data']['file']??null)||!is_string($result['data']['association_id']??null)
            ||preg_match('/^[a-f0-9]{64}$/D',$result['data']['association_id'])!==1)
            throw new \RuntimeException('The initial linked Forms outcome requires reconciliation.');
        return $result['data']['file']+['association_id'=>$result['data']['association_id']];
    }
}
