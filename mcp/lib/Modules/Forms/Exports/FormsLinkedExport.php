<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
/** One bounded step in an isolated original-actor process. Native markers own ongoing consent and leases. */
final class FormsLinkedExport {
    public function __construct(private FormsLinkedExportAuthority $authority,private FormsExportJobs $jobs) {}
    public static function schemaDigest(array $metadata):string {
        return hash('sha256','forms-linked-schema-v1:'.json_encode(FormsOperationJournal::canonical(
            array_intersect_key($metadata,array_flip(['form','questions','timeZone']))),JSON_THROW_ON_ERROR));
    }
    private static function key(string $id,int $generation):string {return hash('sha256','forms-linked-cycle-v1:'.$id.':'.$generation);}
    private function validate(array $claim,string $id):void {
        $origin=$claim['origin']??[];$uid=$origin['uid']??null;
        if(($claim['association_id']??null)!==$id||($claim['state']??null)!=='active'
            ||!is_string($uid)||$uid===''||($origin['tool']??null)!=='forms_link_results_file'
            ||!is_int($origin['connection']??null)||$origin['connection']<1
            ||($claim['original_owner_uid']??null)!==$uid||($claim['current_owner_uid']??null)!==$uid
            ||($origin['references']['form_id']??null)!==($claim['form_id']??null)
            ||($origin['references']['owner_id']??null)!==$uid
            ||($claim['native_runtime']??null)!==FormsGuardIdentity::INSTALLED_MANIFEST_SHA256
            ||!is_int($claim['dirty_generation']??null)||!is_int($claim['last_completed_generation']??null)
            ||$claim['last_completed_generation']<0||$claim['dirty_generation']<$claim['last_completed_generation']
            ||!is_int($claim['version']??null)||$claim['version']<1)
            throw new ToolFailure('The original linked Forms approval is unavailable.');
    }
    private function arguments(array $claim):array {
        $args=$claim['arguments'];$last=$claim['last_verified'];
        if(($args['id']??null)!==$claim['form_id']||!is_int($last['file_id']??null)||$last['file_id']<1
            ||!is_string($last['path']??null)||!is_string($last['etag']??null))
            throw new ToolFailure('The linked Forms destination identity is unavailable.');
        return array_replace($args,['path'=>$last['path'],'overwrite'=>true,'etag'=>$last['etag']]);
    }
    public function step(string $id):array {
        if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)return ['state'=>'refused'];
        $claim=$this->authority->claim($id);
        if($claim===null)return ['state'=>'idle'];
        $delivery=false;
        try {
            $this->validate($claim,$id);$uid=$claim['origin']['uid'];$args=$this->arguments($claim);
            if($claim['active_cycle']===null&&$claim['dirty_generation']===$claim['last_completed_generation']) {
                $this->authority->release($claim);return ['state'=>'idle'];
            }
            $native=$this->authority->source($claim);
            $read=static function(string $action,array $input)use($native):array {
                $out=$native($action,$input);
                if($action==='snapshot'&&!array_key_exists('export_revision',$out))throw new ToolFailure('Linked Forms requires a qualified per-form result revision.');
                return $out;
            };
            if($claim['active_cycle']===null) {
                $snapshot=$read('snapshot',['id'=>$claim['form_id']]);
                $snapshot=$this->jobs->preview($uid,'forms_link_results_file',$args,$snapshot,$read);
                if(!hash_equals($claim['schema_digest'],self::schemaDigest($snapshot['export_metadata'])))throw new ToolFailure('The approved linked Forms schema changed.');
                $destination=$this->authority->destination($claim);
                if(($destination['overwrite']??null)!==true||($destination['file_id']??null)!==$claim['last_verified']['file_id']
                    ||($destination['path']??null)!==$claim['last_verified']['path']||($destination['etag']??null)!==$claim['last_verified']['etag'])
                    throw new ToolFailure('The linked Forms output changed.');
                $snapshot['file_destination']=$destination;
                $generation=$claim['dirty_generation'];
                $admission=$this->jobs->start($uid,'forms_link_results_file',$args,$snapshot,self::key($id,$generation),$claim['origin'],$read);
                $claim=$this->authority->checkpoint($claim,['job_id'=>$admission['references']['job_id'],'generation'=>$generation]);
                $this->validate($claim,$id);
            }
            $cycle=$claim['active_cycle'];
            if(!is_array($cycle)||!is_int($cycle['generation']??null)||$cycle['generation']<1||$cycle['generation']>$claim['dirty_generation']
                ||($cycle['job_id']??null)!==hash('sha256','forms-job-v1:'.self::key($id,$cycle['generation'])))
                throw new ToolFailure('The linked Forms cycle changed.');
            $job=$this->jobs->job($uid,$cycle['job_id']);$p=$job['payload'];
            if($p['origin']!==$claim['origin']||$p['tool']!=='forms_link_results_file'||$p['arguments']!==$args
                ||($p['metadata']['form']['ownerId']??null)!==$uid)
                throw new ToolFailure('The linked Forms cycle has a different approval.');
            if($job['state']==='working') {
                $step=$p['current_step']??[];
                if(isset($step['key'],$step['approved_version']))$this->jobs->recover($uid,$cycle['job_id'],$step['key'],$step['approved_version']);
                $job=$this->jobs->job($uid,$cycle['job_id']);
                if($job['state']==='working') {$this->authority->release($claim);return ['state'=>'pending'];}
            }
            if($job['state']==='cancelled'&&($job['payload']['last_step']['key']??null)===$this->retirementKey($id,$cycle['generation']))return $this->retire($claim,$job);
            if(in_array($job['state'],['delivering','delivery_uncertain'],true)) {
                $this->authority->pause($claim,'delivery_uncertain');return ['state'=>'write_uncertain'];
            }
            if($job['state']==='ready') {
                $result=$this->jobs->deliver($uid,$cycle['job_id'],$read,
                    function(string $content,array $current)use($claim,&$delivery):array {$delivery=true;return $this->authority->write($claim,$content,$current);},
                    hash('sha256','forms-linked-write-v1:'.$id.':'.$cycle['generation']),$job['version']);
                return ['state'=>'delivered','job_id'=>$cycle['job_id'],'file'=>$result['file']];
            }
            if(!in_array($job['state'],['collecting','assembling'],true))throw new ToolFailure('The linked Forms cycle requires reconciliation.');
            $result=$this->jobs->step($uid,$cycle['job_id'],$read,fn(array $row):array=>$this->authority->files($claim,$row),
                hash('sha256','forms-linked-step-v1:'.$id.':'.$cycle['generation'].':'.$job['version']),$job['version']);
            $this->authority->release($claim);return $result;
        } catch(FormsSourceChanged) {
            if(!$delivery&&isset($claim['active_cycle'])) {
                try {
                    $current=$this->jobs->job($claim['origin']['uid'],$claim['active_cycle']['job_id']);
                    if(in_array($current['state'],['paused','ready','collecting','assembling','working'],true))
                        return $this->retire($claim,$current);
                } catch(\Throwable) {return $this->failed($claim,false);}
            }
            return $this->failed($claim,$delivery);
        } catch(\Throwable) {return $this->failed($claim,$delivery);}
    }
    private function retirementKey(string $id,int $generation):string {return hash('sha256','forms-linked-retire-v1:'.$id.':'.$generation);}
    private function retire(array $claim,array $job):array {
        $cycle=$claim['active_cycle'];
        $this->jobs->cancel($claim['origin']['uid'],$job['job_id'],$this->retirementKey($claim['association_id'],$cycle['generation']),$job['version']);
        return ['state'=>$this->authority->restart($claim,$cycle)?'pending':'paused'];
    }
    private function failed(array $claim,bool $delivery):array {
        try {$this->authority->pause($claim,$delivery?'delivery_uncertain':'admission_failed');}
        catch(\Throwable) {return ['state'=>'unknown'];}
        return ['state'=>$delivery?'write_uncertain':'paused'];
    }
}
