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
        private \Closure $authorize,private \Closure $time) {}
    public function preview(string $uid,string $tool,array $args,array $snapshot,callable $native):array {
        $metadata=$native('forms_results_metadata',['id'=>$args['id']]);
        $after=$native('snapshot',['id'=>$args['id']]);
        if($after['revision']!==$snapshot['revision'])throw new ToolFailure('Forms changed during export preview.');
        new FormsExportSnapshot($metadata,(bool)($args['include_respondents']??false),(bool)($args['include_files']??false));
        $snapshot['export_metadata']=$metadata;
        $snapshot['impact']['complete_results']=['submissions'=>$metadata['submissionCount'],'source_revision'=>$snapshot['revision'],
            'rows_per_step'=>100,'projection_bytes_per_step'=>1048576,'seconds_per_step'=>20,'output_quota_bytes'=>26214400,
            'identities'=>(bool)($args['include_respondents']??false),'file_references'=>(bool)($args['include_files']??false),'format'=>$args['format']??'summary','order'=>'submission_id_ascending'];
        return $snapshot;
    }
    public function start(string $uid,string $tool,array $args,array $snapshot,string $key,array $origin,callable $native):array {
        if(!in_array($tool,self::ACTIONS,true))throw new \InvalidArgumentException('Unsupported Forms job.');
        $id=hash('sha256','forms-job-v1:'.$key);
        $old=$this->store->get($id);
        if($old!==null){$this->admit($uid,$old);return $this->receipt($id);}
        $after=$native('snapshot',['id'=>$args['id']]);
        if($after['revision']!==$snapshot['revision'])throw new ToolFailure('Forms export source changed before admission.');
        $job=['job_id'=>$id,'actor_uid'=>$uid,'version'=>1,'state'=>'collecting','updated_at'=>($this->time)(),
            'payload'=>['origin'=>$origin,'tool'=>$tool,'arguments'=>$args,'source_revision'=>$snapshot['revision'],
                'metadata'=>$snapshot['export_metadata'],'destination'=>$snapshot['file_destination']??null,'done'=>0,'after_id'=>0,'bytes'=>0,'parts'=>[],'summary'=>[]]];
        if(!$this->store->create($job))throw new ToolFailure('Forms job admission conflicted; inspect its receipt.');
        return $this->receipt($id);
    }
    private function receipt(string $id):array {return ['state'=>'pending','references'=>['job_id'=>$id],'data'=>['job_id'=>$id,'state'=>'collecting','complete'=>false,'next'=>'forms_continue_operation']];}
    public function job(string $uid,string $id):array {
        $job=$this->store->get($id);if($job===null)throw new ToolFailure(ToolFailure::NOT_FOUND);$this->admit($uid,$job);return $job;
    }
    private function admit(string $uid,array $job):void {
        if($job['actor_uid']!==$uid)throw new ToolFailure(ToolFailure::NOT_FOUND);
        $context=($this->authorize)($job['payload']['origin']);
        if(($context['uid']??null)!==$uid)throw new ToolFailure(ToolFailure::FORBIDDEN);
    }
    public function status(string $uid,string $id):array {return $this->present($this->job($uid,$id));}
    private function present(array $job):array {
        $p=$job['payload'];
        return ['job_id'=>$job['job_id'],'state'=>$job['state'],'done'=>$p['done'],'total'=>$p['metadata']['submissionCount'],
            'source_revision'=>$p['source_revision'],'complete'=>in_array($job['state'],['ready','delivered'],true),
            'bytes'=>$p['bytes'],'format'=>$p['arguments']['format']??'summary','continuation'=>in_array($job['state'],['collecting','working'],true)];
    }
    public function step(string $uid,string $id,callable $native,?callable $visibleFiles=null):array {
        $old=$this->job($uid,$id);
        if($old['state']==='ready'||$old['state']==='delivered')return $this->present($old);
        if($old['state']==='working'&&($this->time)()-$old['updated_at']<=60)throw new ToolFailure('Another Forms worker owns the current step.');
        if(!in_array($old['state'],['collecting','working'],true))throw new ToolFailure('This Forms job cannot continue.');
        $working=$old;$working['version']++;$working['state']='working';$working['updated_at']=($this->time)();
        if(!$this->store->compareAndSwap($old,$working))throw new ToolFailure('Forms job changed before this step.');
        $start=microtime(true);$p=$working['payload'];$args=$p['arguments'];$metadata=$p['metadata'];
        try {
            $this->source($p,$native);
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
                $visible=($args['include_files']??false)&&$visibleFiles!==null?$visibleFiles($submission):[];
                $rows[]=$p['tool']==='forms_summarize_results'?$projection->summaryCells($submission):$projection->row($submission,$visible);
                if(microtime(true)-$start>20)throw new ToolFailure('Forms step exceeded its time budget; no page was accepted.');
            }
            $this->source($p,$native);$this->admit($uid,$working);
            if($p['tool']==='forms_summarize_results')$p['summary']=$this->aggregate($projection->columns(),$p['summary'],$rows);
            else {
                $format=$args['format']??'csv';
                $header=$p['done']===0?[array_column($projection->columns(),'title'),array_column($projection->columns(),'id')]:[];
                $part=$this->spool->put($id,$this->renderer->chunk($format,[...$header,...$rows]));
                $p['parts'][]=$part;$p['bytes']+=$part['bytes'];
            }
            if($p['bytes']>26214400||strlen(json_encode($p['summary'],JSON_THROW_ON_ERROR))>26214400)throw new ToolFailure('Forms complete-result quota exceeded; no truncated result is published.');
            $p['done']+=count($rows);$p['after_id']=(int)$page['nextId'];
            if(!$page['hasMore']&&$p['done']!==$metadata['submissionCount'])throw new ToolFailure('Forms result coverage does not match the approved snapshot.');
            $next=$working;$next['version']++;$next['state']=$page['hasMore']?'collecting':'ready';$next['payload']=$p;$next['updated_at']=($this->time)();
            if(!$this->store->compareAndSwap($working,$next))throw new ToolFailure('Forms worker lost the page claim.');
            return $this->present($next);
        } catch(\Throwable $error) {
            $failed=$working;$failed['version']++;$failed['state']='paused';$failed['updated_at']=($this->time)();
            $this->store->compareAndSwap($working,$failed);throw $error;
        }
    }
    private function source(array $payload,callable $native):void {
        $current=$native('snapshot',['id'=>$payload['arguments']['id']]);
        if($current['revision']!==$payload['source_revision']||($current['form']['ownerId']??null)!==$payload['metadata']['form']['ownerId'])throw new ToolFailure('Forms source changed. Preview a new complete result snapshot.');
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
                    $number=(float)$value;$s['numeric']['count']++;$s['numeric']['sum']+=$number;
                    $s['numeric']['min']=$s['numeric']['min']===null?$number:min($number,$s['numeric']['min']);
                    $s['numeric']['max']=$s['numeric']['max']===null?$number:max($number,$s['numeric']['max']);
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
        $chunks=(function()use($job,$id):\Generator {foreach($job['payload']['parts'] as $part)yield $this->spool->get($id,$part);})();
        return $this->renderer->assemble($job['payload']['arguments']['format']??'csv',$chunks);
    }
    public function result(string $uid,string $id,int $offset,int $limit):array {
        $job=$this->job($uid,$id);if(!in_array($job['state'],['ready','delivered'],true))throw new ToolFailure('Whole-form results are not complete.');
        $items=[];
        foreach($job['payload']['summary'] as $column) {
            foreach($column['counts'] as $count)$items[]=array_intersect_key($column,array_flip(['column_id','question_id','title','type','metric','answered','empty','numeric']))+$count;
            if($column['counts']===[])$items[]=array_intersect_key($column,array_flip(['column_id','question_id','title','type','metric','answered','empty','numeric']));
        }
        $limit=min(100,max(1,$limit));$page=array_slice($items,max(0,$offset),$limit);$more=$offset+count($page)<count($items);
        return $this->present($job)+['results'=>$page,'hasMore'=>$more,'nextOffset'=>$more?$offset+count($page):null];
    }
    public function cancel(string $uid,string $id):array {
        $old=$this->job($uid,$id);
        if(in_array($old['state'],['delivering','delivery_uncertain','delivered'],true))throw new ToolFailure('Delivery already began; cancellation cannot undo it.');
        $next=$old;$next['version']++;$next['state']='cancelled';$next['updated_at']=($this->time)();
        if(!$this->store->compareAndSwap($old,$next))throw new ToolFailure('Forms job changed before cancellation.');
        return $this->present($next);
    }
    /** A Files/download delivery can never be automatically replayed after its claim. */
    public function deliver(string $uid,string $id,callable $native,callable $write):array {
        $old=$this->job($uid,$id);
        if($old['state']==='delivered')return $this->present($old)+['file'=>$old['payload']['delivered']??[]];
        if($old['state']!=='ready')throw new ToolFailure('Forms delivery is not available; inspect the current job.');
        $this->source($old['payload'],$native);$content=$this->content($uid,$id);
        $next=$old;$next['version']++;$next['state']='delivering';$next['updated_at']=($this->time)();
        if(!$this->store->compareAndSwap($old,$next))throw new ToolFailure('Another Forms worker owns delivery.');
        try {
            $this->source($next['payload'],$native);$this->admit($uid,$next);
            $receipt=$write($content,$next);
            $done=$next;$done['version']++;$done['state']='delivered';$done['payload']['delivered']=$receipt;
            if(!$this->store->compareAndSwap($next,$done))throw new ToolFailure('Forms delivery acknowledgement was lost.');
            return $this->present($done)+['file'=>$receipt];
        } catch(\Throwable $e) {
            $uncertain=$next;$uncertain['version']++;$uncertain['state']='delivery_uncertain';$this->store->compareAndSwap($next,$uncertain);throw $e;
        }
    }
}
