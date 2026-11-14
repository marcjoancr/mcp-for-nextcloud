<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
/** Native continuation adapters receive only trusted server callbacks, never tool-supplied authority. */
final class FormsBulkWorkflow {
    public const ACTIONS=['forms_delete_form','forms_delete_responses','forms_transfer','forms_restore'];
    public function __construct(private FormsBulkImpactCursor $cursors,private ?\Closure $clock=null) {}
    public static function nativeInput(string $tool,array $input):array {
        if(!in_array($tool,self::ACTIONS,true))throw new ToolFailure('Unknown bounded Forms workflow.');
        if($tool==='forms_restore') {
            if(!is_string($input['backup_id']??null)||preg_match('/^bulk_v1:([a-f0-9]{64})$/D',$input['backup_id'],$m)!==1)
                throw new ToolFailure('A verified bounded Forms recovery reference is required.');
            $input['backup_id']=$m[1];
        }
        return $input;
    }
    /** Inspect every page before approving; retain digests and signed page locators, not an unbounded array. */
    public function preview(string $uid,string $tool,array $input,object $guard,\Closure $capture,\Closure $files,?float $deadline=null):array {
        $deadline??=$this->now()+18;$native=self::nativeInput($tool,$input);
        $origin=$capture($tool,$input,[]);$snapshot=$guard->bulkSnapshot($uid,$tool,$native+['_mcp_origin'=>$origin]);
        if(!is_string($snapshot['revision']??null))throw new ToolFailure('Native Forms preview identity is missing.');
        $sharing=in_array($tool,['forms_delete_form','forms_transfer'],true);
        if(!$sharing)$snapshot['file_manifest']['files_share']=false;
        $origin=$capture($tool,$input,$snapshot);$collections=$sharing?[]:['managed_shares'=>['total'=>0,$tool==='forms_restore'?'not_restored':'not_changed'=>true]];
        foreach($sharing?['files','managed_shares']:['files'] as $kind) {
            $total=$snapshot['counts'][$kind]??null;
            if(!is_int($total)||$total<0||$total>100000)throw new ToolFailure('Native Forms preview count is invalid.');
            $cursor=0;$seen=0;$digest=hash_init('sha256');
            do {
                $this->budget($deadline);
                $page=$guard->bulkPreviewPage($uid,$tool,$native+['_mcp_origin'=>$origin],$snapshot['revision'],$kind,$cursor,100);
                $this->page($page,$snapshot['revision'],$total,$cursor,100,$kind);
                if($tool!=='forms_restore')$files($origin,$tool,$this->manifest($kind,$page['items']));
                $seen+=count($page['items']);
                hash_update($digest,json_encode(FormsOperationJournal::canonical($page['items']),JSON_THROW_ON_ERROR));
                if($seen>$total)throw new ToolFailure('Native Forms preview repeated its effect rows.');
                $cursor=$page['next_cursor']??$cursor;
            } while(!$page['complete']);
            if($seen!==$total)throw new ToolFailure('Native Forms preview omitted approved effects.');
            $collections[$kind]=['total'=>$total,'digest'=>hash_final($digest),
                'cursor'=>$this->cursors->create($uid,$tool,$input,$snapshot['revision'],$kind,0)];
        }
        $this->budget($deadline);
        $snapshot['bulk_action']=$tool;$snapshot['bulk_input']=$native;$snapshot['bulk_origin']=$origin;
        $snapshot['bulk_authority']=['tool'=>$tool,'required_operations'=>$origin['operations'],'files_operations'=>$origin['references']['files_operations']??[]];
        $publicCounts=match($tool){
            'forms_restore'=>array_intersect_key($snapshot['counts'],array_flip(['form','questions','options','submissions','answers','files','comments'])),
            'forms_delete_responses'=>array_intersect_key($snapshot['counts'],array_flip(['submissions','answers','files'])),
            default=>$snapshot['counts'],
        };
        $snapshot['impact']['bulk']=['counts'=>$publicCounts,'destination'=>$snapshot['destination']??null,'collections'=>$collections,
            'inspection'=>'Inspect the complete effect pages with forms_read_operation_impact before confirming.',
            'recovery'=>$snapshot['recovery'],'folders'=>$snapshot['folders'],'linked_outputs'=>$snapshot['linked_outputs']];
        $result=FormsProjection::bounded($snapshot);$this->budget($deadline);return $result;
    }
    /** A cursor never replaces admission: rebuild original authority and compare its current native revision. */
    public function inspect(string $uid,string $cursor,int $limit,object $guard,\Closure $capture,\Closure $files):array {
        $deadline=$this->now()+18;$target=$this->cursors->verify($uid,$cursor);
        if($target['kind']==='managed_shares'&&!in_array($target['tool'],['forms_delete_form','forms_transfer'],true))
            throw new ToolFailure('This operation does not change Files sharing.');
        $snapshot=$this->preview($uid,$target['tool'],$target['input'],$guard,$capture,$files,$deadline);
        if(!hash_equals($target['revision'],$snapshot['revision']))throw new ToolFailure('The Forms effect preview changed. Request a new approval.');
        $page=$guard->bulkPreviewPage($uid,$target['tool'],$snapshot['bulk_input']+['_mcp_origin'=>$snapshot['bulk_origin']],
            $target['revision'],$target['kind'],$target['cursor'],$limit);
        $this->page($page,$target['revision'],$snapshot['counts'][$target['kind']],$target['cursor'],$limit,$target['kind']);
        if($target['tool']!=='forms_restore')$files($snapshot['bulk_origin'],$target['tool'],$this->manifest($target['kind'],$page['items']));
        $allowed=$target['kind']==='files'?['file_id','path','name','size','etag','owner','action','destination','recovery']:
            ['native_share_id','recipient','type','folder_ids','managed_share_ids','action','destination','destination_owner','source_action','permissions'];
        $items=array_map(static fn(array $row):array=>array_intersect_key($row,array_flip($allowed)),$page['items']);
        $next=$page['next_cursor']===null?null:$this->cursors->create($uid,$target['tool'],$target['input'],$target['revision'],$target['kind'],$page['next_cursor']);
        $result=FormsProjection::bounded(['collection'=>$target['kind'],'items'=>$items,'total'=>$page['total'],'hasMore'=>!$page['complete'],'nextCursor'=>$next]);
        $this->budget($deadline);return $result;
    }
    private function page(array $page,string $revision,int $total,int $cursor,int $limit,string $kind):void {
        FormsProjection::bounded($page);
        if($limit<1||$limit>100||($page['revision']??null)!==$revision||($page['total']??null)!==$total
            ||!is_array($page['items']??null)||!array_is_list($page['items'])||count($page['items'])>$limit
            ||!is_bool($page['complete']??null)||!array_key_exists('next_cursor',$page)
            ||($page['complete']?$page['next_cursor']!==null:($page['items']===[]||!is_int($page['next_cursor'])||$page['next_cursor']<=$cursor)))
            throw new ToolFailure('The native Forms effect page is incomplete or changed.');
        $last=$cursor;
        foreach($page['items'] as $row) {
            $id=$kind==='files'?($row['file_id']??null):$this->sharePosition($row);
            if(!is_int($id)||$id<=$last)throw new ToolFailure('Native Forms effect identities repeated or changed.');
            $last=$id;
        }
        if(!$page['complete']&&$page['next_cursor']!==$last)throw new ToolFailure('Native Forms effect continuation skipped a row.');
    }
    private function sharePosition(array $row):int {
        $ids=$row['managed_share_ids']??null;
        if(!is_array($ids)||count($ids)!==1||!is_string($ids[0]??null)||preg_match('/^ocinternal:([1-9][0-9]*)$/D',$ids[0],$m)!==1)
            throw new ToolFailure('A qualified internal Forms share identity is required.');
        $id=filter_var($m[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new ToolFailure('The Forms share cursor exceeds the native integer range.');
        return $id;
    }
    private function manifest(string $kind,array $items):array {
        if($kind==='files') {
            $files=[];foreach($items as $item) {
                if(!is_int($item['file_id']??null)||$item['file_id']<1)throw new ToolFailure('An exact managed file identity is required.');
                $files[]=['file_id'=>$item['file_id']];
            }
            return ['files'=>$files];
        }
        $folders=[];foreach($items as $item)foreach($item['folder_ids']??[] as $id) {
            if(!is_int($id)||$id<1)throw new ToolFailure('An exact managed share folder is required.');
            $folders[]=$id;
        }
        $folders=array_values(array_unique($folders));
        return ['files'=>array_map(static fn(int $id):array=>['file_id'=>$id],$folders),'share_impact'=>['folder_ids'=>$folders]];
    }
    private function now():float {return $this->clock===null?hrtime(true)/1000000000:($this->clock)();}
    private function budget(float $deadline):void {
        if($this->now()>$deadline)throw new ToolFailure('Forms effect inspection exceeded its bounded preview budget.');
    }
    /** Complete native state is separate from pending postcommit notification verification. */
    public static function outcome(array $dto,string $action):array {
        if(!in_array($action,self::ACTIONS,true)||preg_match('/^[a-f0-9]{64}$/D',$dto['bulk_id']??'')!==1
            ||!is_int($dto['version']??null)||$dto['version']<1||!is_array($dto['progress']??null)
            ||!is_bool($dto['next']??null)||!is_array($dto['references']??null)||!is_array($dto['phases']??null))
            throw new ToolFailure('Native Forms bulk evidence is unavailable.');
        $nativeState=$dto['state']??null;
        $state=match($nativeState){'completed','cancelled'=>'succeeded','pending','partial','uncertain','failed_before_effect'=>$nativeState,default=>'uncertain'};
        if($nativeState==='completed'&&(($dto['phase']??null)!=='complete'||($dto['phases']['database']??null)!=='committed'||!in_array($dto['phases']['files']??null,['verified','not_applicable'],true)||$dto['next']))
            throw new ToolFailure('Native Forms completion was not verified.');
        $refs=array_intersect_key($dto['references'],array_flip(['form_id','restored_form_id','backup_format']));
        if(($backup=$dto['references']['backup_id']??null)!==null) {
            if(!is_string($backup)||preg_match('/^[a-f0-9]{64}$/D',$backup)!==1)throw new ToolFailure('Native Forms recovery identity is unavailable.');
            $refs['backup_id']='bulk_v1:'.$backup;
        }
        $refs+=['bulk_id'=>$dto['bulk_id'],'bulk_action'=>$action];
        $data=['bulk_id'=>$dto['bulk_id'],'state'=>$nativeState,'phase'=>$dto['phase'],'version'=>$dto['version'],
            'progress'=>array_intersect_key($dto['progress'],array_flip(['done','total','captured','capture_total'])),
            'retained_files'=>array_intersect_key($dto['retained_files']??[],array_flip(['recovery','copies','evidence_id','backup_sealed'])),
            'complete'=>$dto['phase']==='complete','continuation'=>$dto['next'],'next'=>$dto['next']?'forms_continue_operation':null];
        return FormsProjection::bounded(['state'=>$state,'data'=>$data,'references'=>$refs,'phases'=>array_intersect_key($dto['phases'],array_flip(['database','files','events']))]);
    }
}
