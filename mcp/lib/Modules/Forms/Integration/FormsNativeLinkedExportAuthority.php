<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportAuthority;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedFileSink;
/** Canonical native leases and original signed authority cross the adapter without reconstruction.
 * Callbacks are trusted service wiring, never tool arguments.
 */
final class FormsNativeLinkedExportAuthority implements FormsLinkedExportAuthority {
    public function __construct(private \Closure $native,private \Closure $qualified,private \Closure $authorize,
        private GrantPolicy $policy,private FormsLinkedFileSink $files,private \Closure $retirementEvidence,
        private \Closure $initializeRoot) {}
    private function guard():object {
        if(!(($this->qualified)()))throw new \RuntimeException('The qualified Forms linked runtime is unavailable.');
        return ($this->native)();
    }
    private function original(array $claim):string {
        $origin=$claim['origin']??[];$context=($this->authorize)($origin);$uid=$origin['uid']??null;
        if(!is_string($uid)||$uid===''||($context['uid']??null)!==$uid)throw new \RuntimeException('Original linked Forms authority is unavailable.');
        return $uid;
    }
    public function claim(string $associationId):?array {
        $claim=$this->guard()->linkedClaim($associationId);
        if($claim!==null) { $this->original($claim);($this->initializeRoot)($claim['origin']); }
        return $claim;
    }
    public function source(array $claim):\Closure {
        return function(string $action,array $input)use($claim):array {
            if(!in_array($action,['snapshot','forms_results_metadata','forms_results_page','forms_list_response_files'],true))
                throw new \RuntimeException('Unsupported linked Forms source action.');
            $uid=$this->original($claim);
            return $this->policy->freshWithin($uid,function()use($claim,$action,$input):array {
                $this->original($claim);return $this->guard()->linkedRead($claim,$action,$input);
            });
        };
    }
    public function destination(array $claim):array {
        $uid=$this->original($claim);$this->guard();
        return $this->policy->resourceBoundary($uid,fn():array=>$this->files->snapshot($claim));
    }
    public function checkpoint(array $claim,array $cycle):array {
        $this->original($claim);return $this->guard()->linkedCheckpoint($claim,$cycle);
    }
    public function restart(array $claim,array $cycle):bool {
        $this->original($claim);
        return $this->guard()->linkedRestart($claim,$cycle,fn():array=>($this->retirementEvidence)($claim,$cycle));
    }
    public function release(array $claim):void {$this->guard()->linkedRelease($claim);}
    public function pause(array $claim,string $reason):void {$this->guard()->linkedPause($claim,$reason);}
    public function files(array $claim,array $submission):array {
        $uid=$this->original($claim);$this->guard();
        return $this->policy->resourceBoundary($uid,fn():array=>$this->files->references($claim,$submission,$this->source($claim)));
    }
    public function write(array $claim,string $content,array $job):array {
        $uid=$this->original($claim);$payload=$job['payload'];$cycle=$claim['active_cycle'];
        $key=hash('sha256','forms-linked-write-v1:'.$claim['association_id'].':'.$cycle['generation']);
        if(($job['actor_uid']??null)!==$uid||($job['state']??null)!=='delivering'||($job['job_id']??null)!==$cycle['job_id']
            ||($payload['origin']??null)!==$claim['origin']||($payload['tool']??null)!=='forms_link_results_file'
            ||($payload['current_step']['key']??null)!==$key||!is_string($payload['source_revision']??null))
            throw new \RuntimeException('The linked Forms delivery claim changed.');
        return $this->guard()->syncLinkedFile($claim['origin'],$claim['association_id'],$claim['version'],$payload['source_revision'],$key,
            ['lease_token'=>$claim['lease_token'],'active_cycle'=>$cycle,'destination'=>$payload['destination']],
            fn():array=>$this->policy->resourceBoundary($uid,fn():array=>$this->files->write($claim,$content,$job,$this->source($claim))));
    }
}
