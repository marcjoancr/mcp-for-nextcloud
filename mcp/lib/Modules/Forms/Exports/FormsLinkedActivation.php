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
