<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Collection is repeatable and read-only. Every durable page belongs to one unchanged native revision. */
final class FormsExportJobs {
    public const ACTIONS=['forms_summarize_results','forms_download_results','forms_export_results','forms_link_results_file'];
    /** @param \Closure(array):array $authorize @param \Closure():int $time */
    public function __construct(private FormsJobStore $store,private FormsSpool $spool,private FormsExportRenderer $renderer,
        private \Closure $authorize,private \Closure $time,private ?\Closure $files=null,private ?\Closure $timer=null) {}
    /** Result content can use a per-form revision while exact user previews retain the global serialization revision. */
    public static function sourceRevision(array $snapshot):string {
        $value=array_key_exists('export_revision',$snapshot)?$snapshot['export_revision']:($snapshot['revision']??null);
        if(!is_string($value)||$value===''||strlen($value)>128)throw new ToolFailure('The Forms result source revision is unavailable.');
        return $value;
    }
    public function preview(string $uid,string $tool,array $args,array $snapshot,callable $native):array {
        $metadata=$native('forms_results_metadata',['id'=>$args['id']]);
        $after=$native('snapshot',['id'=>$args['id']]);
        if($after['revision']!==$snapshot['revision'])throw new ToolFailure('Forms changed during export preview.');
        $projection=new FormsExportSnapshot($metadata,(bool)($args['include_respondents']??false),(bool)($args['include_files']??false));
        $snapshot['export_metadata']=$metadata;
        $snapshot['impact']['complete_results']=['submissions'=>$metadata['submissionCount'],'source_revision'=>self::sourceRevision($snapshot),'columns'=>$projection->approvalColumns(),
            'rows_per_step'=>100,'projection_bytes_per_step'=>1048576,'seconds_per_step'=>20,'output_quota_bytes'=>26214400,'temporary_ciphertext_reservation_bytes'=>134217728,'temporary_retention_seconds'=>604800,
            'identities'=>(bool)($args['include_respondents']??false),'file_references'=>(bool)($args['include_files']??false),'format'=>$args['format']??'summary','order'=>'submission_id_ascending'];
        return $snapshot;
    }
    public function start(string $uid,string $tool,array $args,array $snapshot,string $key,array $origin,callable $native):array {
        if(!in_array($tool,self::ACTIONS,true))throw new \InvalidArgumentException('Unsupported Forms job.');
        $id=hash('sha256','forms-job-v1:'.$key);
        $old=$this->store->get($id);
        if($old!==null){$this->admit($uid,$old);return $this->receipt($id);}
        $after=$native('snapshot',['id'=>$args['id']]);
        if($after['revision']!==$snapshot['revision']||self::sourceRevision($after)!==self::sourceRevision($snapshot))throw new ToolFailure('Forms export source changed before admission.');
        $job=['job_id'=>$id,'actor_uid'=>$uid,'version'=>1,'state'=>'collecting','updated_at'=>($this->time)(),
            'payload'=>['origin'=>$origin,'cache_protocol'=>2,'native_runtime'=>\OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,'tool'=>$tool,'arguments'=>$args,'source_revision'=>self::sourceRevision($snapshot),
                'cache_expires_at'=>($this->time)()+604800,'metadata'=>$snapshot['export_metadata'],'destination'=>$snapshot['file_destination']??null,'done'=>0,'after_id'=>0,'bytes'=>0,'parts'=>[],'file_ids'=>[]]];
        try {$this->spool->reserve($id,$uid);}catch(\Throwable){throw new \OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect('Forms temporary result capacity is unavailable. No result job or Files write was started.');}
        if(!$this->store->create($job))throw new ToolFailure('Forms job admission conflicted; inspect its receipt.');
        return $this->receipt($id);
    }
    private function receipt(string $id):array {return ['state'=>'pending','references'=>['job_id'=>$id],'data'=>['job_id'=>$id,'state'=>'collecting','complete'=>false,'next'=>'forms_continue_operation']];}
    public function job(string $uid,string $id,bool $requireCache=true):array {
        $job=$this->store->get($id);if($job===null)throw new ToolFailure(ToolFailure::NOT_FOUND);
        if($requireCache)$this->admit($uid,$job);else $this->admitEvidence($uid,$job);
        return $job;
    }
    /** Proof of job admission never exposes generated result content or authorizes a resumed effect. */
    public function admissionEvidence(string $uid,string $id):?array {
        $job=$this->store->get($id);if($job===null)return null;$this->admitEvidence($uid,$job);
        return ['state'=>'pending','references'=>['job_id'=>$id]];
    }
    private function admitEvidence(string $uid,array $job):void {
        if($job['actor_uid']!==$uid)throw new ToolFailure(ToolFailure::NOT_FOUND);
        $context=($this->authorize)($job['payload']['origin']);
        if(($context['uid']??null)!==$uid)throw new ToolFailure(ToolFailure::FORBIDDEN);
    }
    private function admit(string $uid,array $job):void {
        $this->admitEvidence($uid,$job);
        if(($job['payload']['cache_protocol']??null)!==2)throw new ToolFailure('The Forms cache protocol changed. Approve a new snapshot.');
        $this->spool->assertActive($job['job_id'],$uid);
        if(($job['payload']['native_runtime']??null)!==\OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity::INSTALLED_MANIFEST_SHA256)throw new ToolFailure('The qualified Forms runtime changed. Approve a new complete snapshot.');
        if(($job['payload']['arguments']['include_files']??false)&&!array_key_exists('file_ids',$job['payload']))throw new ToolFailure('This retained file-reference export requires a new authorized snapshot.');
        if(($job['payload']['file_ids']??[])!==[]) {
            if($this->files===null)throw new ToolFailure('Current Files result authority is unavailable.');
            ($this->files)($uid,array_values($job['payload']['file_ids']));
        }
    }
    public function status(string $uid,string $id):array {return $this->present($this->job($uid,$id));}
    private function present(array $job):array {
        $p=$job['payload'];
        return ['job_id'=>$job['job_id'],'state'=>$job['state'],'done'=>$p['done'],'total'=>$p['metadata']['submissionCount'],
            'source_revision'=>$p['source_revision'],'complete'=>in_array($job['state'],['ready','delivered'],true),
            'cache_expires_at'=>$p['cache_expires_at']??null,'bytes'=>$p['bytes'],'format'=>$p['arguments']['format']??'summary','continuation'=>in_array($job['state'],['collecting','working','assembling'],true),'step_budget_exhausted'=>(bool)($p['step_budget_exhausted']??false)];
    }
    public function step(string $uid,string $id,callable $native,?callable $visibleFiles=null,?string $operationKey=null,?int $approvedVersion=null):array {
        $old=$this->job($uid,$id);$this->approved($old,$operationKey,$approvedVersion);
        if($old['state']==='ready'||$old['state']==='delivered'){$this->spool->seal($id);return $this->present($old);}
        if($old['state']==='working'&&($this->time)()-$old['updated_at']<=60)throw new ToolFailure('Another Forms worker owns the current step.');
        if(!in_array($old['state'],['collecting','working','assembling'],true))throw new ToolFailure('This Forms job cannot continue.');
        $working=$this->claim($old,'working',$operationKey,$approvedVersion);
        if(!$this->store->compareAndSwap($old,$working))throw new ToolFailure('Forms job changed before this step.');
        $start=$this->tick();$p=$working['payload'];$p['step_budget_exhausted']=false;$args=$p['arguments'];$metadata=$p['metadata'];
        try {
            $this->source($p,$native);
            if(!($p['collection_complete']??false)) {
                $keep=array_column($p['parts'],'key');
                foreach(['artifact','summary_artifact'] as $artifact)if(isset($p[$artifact]['key']))$keep[]=$p[$artifact]['key'];
                if(!$this->spool->retain($id,array_values(array_unique($keep)),function()use($id,$working):void {
                    if($this->store->get($id)!==$working)throw new ToolFailure('Forms checkpoint claim changed before cache reclamation.');
                }))throw new FormsExportBudgetExceeded('Obsolete Forms cache needs another bounded cleanup step.');
                $this->deadline($start);
                $limit=100;
                while(true) {
                    try{$page=$native('forms_results_page',['id'=>$args['id'],'after_id'=>$p['after_id'],'limit'=>$limit]);break;}
                    catch(\Throwable $e){if($limit===1)throw $e;$limit=max(1,intdiv($limit,2));}
                }
                if(count($page['submissions'])>100||strlen(json_encode($page,JSON_THROW_ON_ERROR))>1048576)throw new ToolFailure('Native Forms page exceeds its declared budget.');
                $projection=new FormsExportSnapshot($metadata,(bool)($args['include_respondents']??false),(bool)($args['include_files']??false));
                $rows=[];
                foreach($page['submissions'] as $submission) {
                    if((int)$submission['id']<=$p['after_id'])throw new ToolFailure('Native Forms continuation repeated a submission.');
                    $visible=($args['include_files']??false)&&$visibleFiles!==null?$visibleFiles($submission,fn()=>$this->deadline($start)):[];
                    foreach(array_keys($visible) as $fileId)$p['file_ids'][(string)$fileId]=(int)$fileId;
                    $rows[]=$p['tool']==='forms_summarize_results'?$projection->summaryCells($submission):$projection->row($submission,$visible);
                    $this->deadline($start);
                }
                $this->source($p,$native);$this->admit($uid,array_replace($working,['payload'=>$p]));
                if($p['tool']==='forms_summarize_results') {
                    $summary=isset($p['summary_artifact'])?json_decode($this->spool->get($id,$p['summary_artifact']),true,64,JSON_THROW_ON_ERROR):($p['summary']??[]);
                    $summary=$this->aggregate($projection->columns(),$summary,$rows);
                    $content=json_encode($summary,JSON_THROW_ON_ERROR);
                    if(strlen($content)>26214400)throw new ToolFailure('Forms complete-result quota exceeded; no truncated result is published.');
                    $p['summary_artifact']=$this->spool->put($id,$content);$p['bytes']=$p['summary_artifact']['bytes'];unset($p['summary']);
                }
                else {
                    $format=$args['format']??'csv';
                    $header=$p['done']===0?[array_column($projection->columns(),'title'),array_column($projection->columns(),'id')]:[];
                    $part=$this->spool->put($id,$this->renderer->chunk($format,[...$header,...$rows],$header!==[]?2:null));
                    $p['parts'][]=$part;$p['bytes']+=$part['bytes'];
                }
                if($p['bytes']>26214400)throw new ToolFailure('Forms complete-result quota exceeded; no truncated result is published.');
                $p['done']+=count($rows);$p['after_id']=(int)$page['nextId'];
                if(!$page['hasMore']&&$p['done']!==$metadata['submissionCount'])throw new ToolFailure('Forms result coverage does not match the approved snapshot.');
                $p['collection_complete']=!$page['hasMore'];
            }
            $this->deadline($start);
            if($p['collection_complete']&&$p['tool']!=='forms_summarize_results')$this->finalize($id,$p,$start);
            $this->source($p,$native);$this->admit($uid,array_replace($working,['payload'=>$p]));$this->deadline($start);
            if($p['collection_complete']){
                $checkpoint=$working;$checkpoint['version']++;$checkpoint['payload']=$p;$checkpoint['updated_at']=($this->time)();
                if(!$this->store->compareAndSwap($working,$checkpoint))throw new ToolFailure('Forms final checkpoint claim changed.');
                $working=$checkpoint;
                $this->spool->seal($id);$this->source($p,$native);$this->admit($uid,$working);$this->deadline($start);
            }
            return $this->saveStep($working,$p,$p['collection_complete']?'ready':'collecting',$p['collection_complete']?'succeeded':'pending');
        } catch(FormsExportBudgetExceeded) {
            // The page cursor and all completed private parts survive; readiness is never accepted after the deadline.
            $p['step_budget_exhausted']=true;
            return $this->saveStep($working,$p,($p['collection_complete']??false)?'assembling':'collecting','pending');
        } catch(\Throwable $error) {
            $failed=$working;$failed['version']++;$failed['state']='paused';$failed['updated_at']=($this->time)();$failed=$this->accepted($working,$failed,'failed_before_effect');
            $this->store->compareAndSwap($working,$failed);throw $error;
        }
    }
    private function tick():float {return $this->timer!==null?($this->timer)():microtime(true);}
    private function deadline(float $start):void {if($this->tick()-$start>20)throw new FormsExportBudgetExceeded('The bounded Forms step needs continuation.');}
    private function finalize(string $id,array &$p,float $start):void {
        if(isset($p['artifact'])) {
            $this->spool->get($id,$p['artifact']);$this->deadline($start);return;
        }
        $chunks=(function()use($p,$id,$start):\Generator{
            foreach($p['parts'] as $part) {$this->deadline($start);$chunk=$this->spool->get($id,$part);$this->deadline($start);yield $chunk;}
        })();
        $content=$this->renderer->assemble($p['arguments']['format']??'csv',$chunks);$this->deadline($start);
        $p['artifact']=$this->spool->put($id,$content);$p['bytes']=$p['artifact']['bytes'];$this->deadline($start);
    }
    private function saveStep(array $working,array $p,string $state,string $outcome):array {
        $next=$working;$next['version']++;$next['state']=$state;$next['payload']=$p;$next['updated_at']=($this->time)();
        $next=$this->accepted($working,$next,$outcome);
        if(!$this->store->compareAndSwap($working,$next))throw new ToolFailure('Forms worker lost the page claim.');
        return $this->present($next);
    }
    /** Recover durable evidence only. Invalidating a stale read claim prevents a delayed worker from accepting its page. */
    public function recover(string $uid,string $id,string $key,int $version):?array {
        $old=$this->job($uid,$id,false);$p=$old['payload'];
        $matches=static fn(array $step):bool=>($step['key']??null)===$key&&($step['approved_version']??null)===$version;
        if($matches($p['last_step']??[]))return $p['last_step']['outcome'];
        if($matches($p['current_step']??[])) {
            if(($this->time)()-$old['updated_at']<=60)return null;
            $next=$old;$next['version']++;$next['updated_at']=($this->time)();
            $outcome=match($old['state']){'working'=>'failed_before_effect','delivering','issuing'=>'partial',default=>null};
            if($outcome===null)return null;
            $next['state']=match($old['state']){'working'=>($p['collection_complete']??false)?'assembling':'collecting','issuing'=>'ready',default=>'delivery_uncertain'};
            $next=$this->accepted($old,$next,$outcome);
            return $this->store->compareAndSwap($old,$next)?$next['payload']['last_step']['outcome']:null;
        }
        // With no matching claim or accepted receipt, a newer monotonic version proves this approved callback cannot start.
        // The journal pins this same version at reservation and rejects a preflight checkpoint that substitutes another.
        if($old['version']>$version)return ['state'=>'failed_before_effect','references'=>['job_id'=>$id]];
        if($old['version']!==$version)return null;
        $next=$this->claim($old,$old['state'],$key,$version);
        $next=$this->accepted($next,$next,'failed_before_effect');
        return $this->store->compareAndSwap($old,$next)?$next['payload']['last_step']['outcome']:null;
    }
    private function approved(array $old,?string $key,?int $version):void {
        if($key!==null&&$old['version']!==$version)throw new \OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect('The approved Forms job version was invalidated. Preview the current step.');
    }
    private function claim(array $old,string $state,?string $key,?int $version):array {
        $next=$old;$next['version']++;$next['state']=$state;$next['updated_at']=($this->time)();
        if($key!==null)$next['payload']['current_step']=['key'=>$key,'approved_version'=>$version];
        return $next;
    }
    private function accepted(array $working,array $next,string $state):array {
        if(isset($working['payload']['current_step'])) {
            $next['payload']['last_step']=$working['payload']['current_step']+['outcome'=>['state'=>$state,'references'=>['job_id'=>$next['job_id']]]];
            unset($next['payload']['current_step']);
        }
        return $next;
    }
    /** A lost URL is never recovered from retained data. A new explicitly confirmed step may issue another short-lived capability. */
    public function issueDownload(string $uid,string $id,string $key,int $version,callable $issue):array {
        $old=$this->job($uid,$id);$this->approved($old,$key,$version);
        if($old['state']!=='ready')throw new ToolFailure('The complete Forms download is unavailable.');
        $claimed=$this->claim($old,'issuing',$key,$version);
        if(!$this->store->compareAndSwap($old,$claimed))throw new ToolFailure('Another Forms step owns download admission.');
        try {
            $data=$issue();
            $done=$claimed;$done['version']++;$done['state']='ready';$done=$this->accepted($claimed,$done,'succeeded');
            if(!$this->store->compareAndSwap($claimed,$done))throw new ToolFailure('The Forms download acknowledgement was lost.');
            return $this->present($done)+$data;
        } catch(\Throwable $e) {
            $unknown=$claimed;$unknown['version']++;$unknown['state']='ready';$unknown=$this->accepted($claimed,$unknown,'partial');
            $this->store->compareAndSwap($claimed,$unknown);throw $e;
        }
    }
    private function source(array $payload,callable $native):void {
        $current=$native('snapshot',['id'=>$payload['arguments']['id']]);
        if(self::sourceRevision($current)!==$payload['source_revision']||($current['form']['ownerId']??null)!==$payload['metadata']['form']['ownerId'])throw new FormsSourceChanged('Forms source changed. Preview a new complete result snapshot.');
    }
    private function aggregate(array $columns,array $summary,array $rows):array {
        foreach($columns as $index=>$col) {
            if(!isset($col['question_id']))continue;
            $summary[$col['id']]??=['column_id'=>$col['id'],'question_id'=>$col['question_id'],'title'=>$col['title'],'type'=>$col['type'],'answered'=>0,'empty'=>0,'metric'=>$col['kind']==='file'?'attachments_per_response':'native_selections','counts'=>[],'numeric'=>['count'=>0,'sum'=>0,'min'=>null,'max'=>null]];
            $s=&$summary[$col['id']];
            foreach($rows as $row) {
                $values=$row[$index];if($values===[]){$s['empty']++;continue;}
                $s['answered']++;
                foreach($values as $value) {
                $key=hash('sha256',$value);$s['counts'][$key]??=['value'=>$value,'count'=>0];$s['counts'][$key]['count']++;
                if((($col['numeric']??false)||$col['kind']==='file')&&is_numeric($value)) {
                    $number=(float)$value;$s['numeric']['count']++;
                    if(($s['numeric']['status']??null)==='out_of_range'||!is_finite($number)||!is_finite(($s['numeric']['sum']??0)+$number)) {
                        // Exact values/counts above remain available; never persist non-JSON INF/NaN or claim a partial sum.
                        $s['numeric']=array_replace($s['numeric'],['sum'=>null,'min'=>null,'max'=>null,'status'=>'out_of_range']);
                    } else {
                        $s['numeric']['sum']+=$number;
                        $s['numeric']['min']=$s['numeric']['min']===null?$number:min($number,$s['numeric']['min']);
                        $s['numeric']['max']=$s['numeric']['max']===null?$number:max($number,$s['numeric']['max']);
                    }
                }
                }
            }
            unset($s);
        }
        return $summary;
    }
    public function content(string $uid,string $id):string {
        $job=$this->job($uid,$id);if(!in_array($job['state'],['ready','delivered','delivering'],true))throw new ToolFailure('Forms export is not complete.');
        if($job['payload']['tool']==='forms_summarize_results')throw new ToolFailure('This Forms job contains aggregate data.');
        if(isset($job['payload']['artifact']))return $this->spool->get($id,$job['payload']['artifact']);
        $chunks=(function()use($job,$id):\Generator {foreach($job['payload']['parts'] as $part)yield $this->spool->get($id,$part);})();
        return $this->renderer->assemble($job['payload']['arguments']['format']??'csv',$chunks);
    }
    public function result(string $uid,string $id,int $offset,int $limit):array {
        $job=$this->job($uid,$id);if(!in_array($job['state'],['ready','delivered'],true))throw new ToolFailure('Whole-form results are not complete.');
        $items=[];
        $summary=isset($job['payload']['summary_artifact'])?json_decode($this->spool->get($id,$job['payload']['summary_artifact']),true,64,JSON_THROW_ON_ERROR):($job['payload']['summary']??[]);
        foreach($summary as $column) {
            foreach($column['counts'] as $count)$items[]=array_intersect_key($column,array_flip(['column_id','question_id','title','type','metric','answered','empty','numeric']))+$count;
            if($column['counts']===[])$items[]=array_intersect_key($column,array_flip(['column_id','question_id','title','type','metric','answered','empty','numeric']));
        }
        $limit=min(100,max(1,$limit));$page=array_slice($items,max(0,$offset),$limit);$more=$offset+count($page)<count($items);
        return $this->present($job)+['results'=>$page,'hasMore'=>$more,'nextOffset'=>$more?$offset+count($page):null];
    }
    public function cancel(string $uid,string $id,?string $operationKey=null,?int $approvedVersion=null):array {
        $old=$this->job($uid,$id);$this->approved($old,$operationKey,$approvedVersion);
        if(in_array($old['state'],['delivering','delivery_uncertain','delivered'],true))throw new ToolFailure('Delivery already began; cancellation cannot undo it.');
        $next=$this->claim($old,'cancelled',$operationKey,$approvedVersion);$next=$this->accepted($next,$next,'succeeded');
        if(!$this->store->compareAndSwap($old,$next))throw new ToolFailure('Forms job changed before cancellation.');
        $this->spool->seal($id);
        return $this->present($next);
    }
    /** Repeated by the trusted Files boundary after the actual storage lock, before any destination effect. */
    public function assertDelivery(string $uid,array $claimed,callable $native):void {
        $current=$this->job($uid,$claimed['job_id']);
        if($current!==$claimed||$current['state']!=='delivering')throw new ToolFailure('The Forms delivery claim changed before the destination write.');
        $this->source($current['payload'],$native);
    }
    /** A Files/download delivery can never be automatically replayed after its claim. */
    public function deliver(string $uid,string $id,callable $native,callable $write,?string $operationKey=null,?int $approvedVersion=null):array {
        $old=$this->job($uid,$id);$this->approved($old,$operationKey,$approvedVersion);
        if($old['state']==='delivered')return $this->present($old)+['file'=>$old['payload']['delivered']??[]];
        if($old['state']!=='ready')throw new ToolFailure('Forms delivery is not available; inspect the current job.');
        $this->source($old['payload'],$native);$content=$this->content($uid,$id);
        $next=$this->claim($old,'delivering',$operationKey,$approvedVersion);
        if(!$this->store->compareAndSwap($old,$next))throw new ToolFailure('Another Forms worker owns delivery.');
        $writeStarted=false;
        try {
            $this->source($next['payload'],$native);$this->admit($uid,$next);
            $writeStarted=true;$receipt=$write($content,$next);
            $done=$next;$done['version']++;$done['state']='delivered';$done['payload']['delivered']=$receipt;$done=$this->accepted($next,$done,'succeeded');
            if(!$this->store->compareAndSwap($next,$done))throw new ToolFailure('Forms delivery acknowledgement was lost.');
            return $this->present($done)+['file'=>$receipt];
        } catch(\Throwable $e) {
            $uncertain=$next;$uncertain['version']++;$uncertain['state']=$writeStarted?'delivery_uncertain':'paused';$uncertain=$this->accepted($next,$uncertain,$writeStarted?'partial':'failed_before_effect');$this->store->compareAndSwap($next,$uncertain);throw $e;
        }
    }
}
