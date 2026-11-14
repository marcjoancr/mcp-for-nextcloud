<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
/** Evidence for retiring an abandoned read-only cycle. Unknown/delivered effects never acquire this proof. */
final class FormsLinkedRetirement {
    public function __construct(private FormsJobStore $jobs,private \Closure $sealed) {}
    public function proof(array $claim,array $cycle):array {
        if(($claim['active_cycle']??null)!==$cycle)throw new \RuntimeException('The linked cycle changed.');
        $job=$this->jobs->get($cycle['job_id'])??throw new \RuntimeException('The linked cycle evidence is unavailable.');
        $uid=$claim['origin']['uid'];$payload=$job['payload'];$step=$payload['last_step']??[];
        $arguments=array_replace($claim['arguments'],['path'=>$claim['last_verified']['path'],'overwrite'=>true,'etag'=>$claim['last_verified']['etag']]);
        if(($job['job_id']??null)!==$cycle['job_id']||($job['actor_uid']??null)!==$uid||($job['state']??null)!=='cancelled'
            ||($payload['tool']??null)!=='forms_link_results_file'||($payload['native_runtime']??null)!==FormsGuardIdentity::INSTALLED_MANIFEST_SHA256
            ||($payload['cache_protocol']??null)!==2||($payload['origin']??null)!==$claim['origin']||($payload['arguments']??null)!==$arguments
            ||isset($payload['current_step'])||isset($payload['delivered'])
            ||($step['key']??null)!==hash('sha256','forms-linked-retire-v1:'.$claim['association_id'].':'.$cycle['generation'])
            ||!is_int($step['approved_version']??null)||$step['approved_version']<1||!is_int($job['version']??null)||$job['version']<=$step['approved_version']
            ||($step['outcome']['state']??null)!=='succeeded'||($step['outcome']['references']['job_id']??null)!==$cycle['job_id']
            ||!(($this->sealed)($cycle['job_id'],$uid)))
            throw new \RuntimeException('The read-only linked cycle retirement is not proven.');
        return array_intersect_key($job,array_flip(['job_id','actor_uid','state','version']))+[
            'payload'=>array_intersect_key($payload,array_flip(['origin','tool','native_runtime','cache_protocol','arguments','last_step'])),
            'sealed'=>true,'delivery_started'=>false];
    }
}
