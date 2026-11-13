<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Attachments\FormsAnswerUploads;
use OCA\Mcp\Modules\Forms\Attachments\FormsAnswerFiles;
use OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact;
use OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization;
use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCP\IURLGenerator;
use OCA\Mcp\Modules\Forms\Exports\FormsExportJobs;
use OCA\Mcp\Modules\Forms\Exports\FormsExportDestination;
use OCA\Mcp\Modules\Forms\Exports\FormsDownloadAdmission;
/** Private approval manifests never come from tool arguments. Native services resolve after source admission. */
final class FormsNativeGateway implements FormsGateway {
    private array $snapshots=[];
    /** @param \Closure():object $guard */
    public function __construct(private FormsCapabilities $features,private \Closure $guard,
        private FormsAnswerUploads $uploads,private FormsAnswerFiles $files,private FormsFileImpact $impact,
        private FormsNativeAuthorization $authorization,private IURLGenerator $urls,
        private FormsExportJobs $jobs,private FormsExportDestination $destination,private FormsDownloadAdmission $downloads,private \OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal $journal,
        private \OCA\Mcp\Modules\Forms\Reading\FormsListing $listing,private \OCA\Mcp\Modules\Forms\Reading\FormsListingSource $inventory,
        private \OCA\Mcp\Access\Grants\GrantPolicy $policy) {}
    public function capabilities(string $uid):array {return $this->features->forUser($uid);}
    private function native(string $uid):object {
        if(!$this->features->forUser($uid)['guardedWrites'])throw new ToolFailure('The installed Forms runtime has not been qualified for this capability.');
        return ($this->guard)();
    }
    private function key(string $uid,string $action,array $input):string {
        return hash('sha256',json_encode([$uid,$action,FormsOperationJournal::canonical($input)],JSON_THROW_ON_ERROR));
    }
    public function snapshot(string $uid,string $action,array $input):array {
        try {
            $guard=$this->native($uid);
            if(!FormsPublication::supported($action,$guard->capabilities()))throw new FormsBeforeEffect('This Forms workflow is not available on the qualified runtime.');
            $previewOrigin=$this->authorization->capture($uid,$action,$input,[]);$read=$this->source($uid,$guard,$previewOrigin);
            if(in_array($action,['forms_continue_operation','forms_cancel_operation','forms_read_result'],true)) {
                $receipt=$this->journal->get($uid,$input['target_operation_id'],fn(string $tool,array $refs)=>$this->authorization->assertHistory($uid,$tool,$refs));
                $jobId=$receipt['references']['job_id']??throw new ToolFailure('The original operation has no Forms job.');
                $job=$this->jobs->job($uid,$jobId);
                if($action==='forms_continue_operation'&&in_array($job['payload']['tool'],['forms_export_results','forms_link_results_file'],true)&&($guard->capabilities()['file_sinks']??false)!==true)throw new ToolFailure('This Forms runtime does not qualify the original Files write sink.');
                $read=$this->source($uid,$guard,$job['payload']['origin']);$snapshot=$read('snapshot',['id'=>$job['payload']['arguments']['id']]);
                if($snapshot['revision']!==$job['payload']['source_revision'])throw new ToolFailure('The approved Forms job source changed.');
                $snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
                $snapshot['job_id']=$jobId;$snapshot['job_version']=$job['version'];$snapshot['job']=$this->jobs->status($uid,$jobId);
                $snapshot['impact']['job']=$snapshot['job'];
                if($job['payload']['destination']!==null)$snapshot['file_destination']=['plan'=>$job['payload']['destination']['plan']];
                $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;return $snapshot;
            }
            $nativeInput=$input;$nativeInput['_mcp_origin']=$previewOrigin;
            $nativeAction=in_array($action,FormsExportJobs::ACTIONS,true)?'forms_results_metadata':$action;
            $snapshot=$guard->snapshot($uid,$nativeAction,$nativeInput);$snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
            if(isset($input['answers'])) {
                $snapshot['native_answers']=FormsAnswerPreview::validate($guard,$uid,$action,$input,$snapshot['form']['questions']??[],$previewOrigin,$snapshot['revision']);
            }
            if(in_array($action,FormsExportJobs::ACTIONS,true)) {
                if(in_array($action,['forms_export_results','forms_link_results_file'],true))$snapshot['file_destination']=$this->policy->resourceBoundary($uid,fn()=>$this->destination->snapshot($uid,$input));
                if($action==='forms_link_results_file'&&(($snapshot['form']['ownerId']??null)!==$uid||$this->journal->connection()===0||!empty($snapshot['form']['fileId'])))throw new ToolFailure('A new owned association and a persistent original connection are required.');
                $snapshot=$this->jobs->preview($uid,$action,$input,$snapshot,$read);
            }
            if(in_array($action,['forms_update_share','forms_unshare'],true))$snapshot['shares']=$guard->read($uid,'forms_list_shares',['id'=>$input['id']]);
            if($action==='forms_upload_response_files')$snapshot['file_sources']=$this->policy->resourceBoundary($uid,fn()=>$this->uploads->snapshot($uid,$input['paths']));
            if($action==='forms_save_response_file') {
                $refs=$guard->read($uid,'forms_list_response_files',$input);
                $snapshot['file_destination']=$this->policy->resourceBoundary($uid,fn()=>$this->files->snapshotSave($uid,$refs['files'],$input['file_id'],$input['path']));
            }
            $manifest=$snapshot['file_manifest']??[];
            if(!in_array($action,['forms_submit_response','forms_update_own_response','forms_update_response'],true)
                && (($manifest['files']??[])!==[]||isset($manifest['share_impact']['parent'])||($manifest['share_removals']??[])!==[]))$this->policy->resourceBoundary($uid,fn()=>$this->impact->assertVisible($uid,$manifest));
            $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;
            return $snapshot;
        } catch(ToolFailure $e){throw $e;}catch(\Throwable){throw new ToolFailure('The Forms resource, Files access or capability is unavailable.');}
    }
    public function read(string $uid,string $action,array $input):array {
        try {
            if($action==='forms_search')return $this->listing->page($uid,$input,$this->inventory->rows($uid,$input));
            if($action==='forms_read_result') {
                $snapshot=$this->snapshots[$this->key($uid,$action,$input)]??throw new ToolFailure('A current Forms job snapshot is required.');
                $status=$this->jobs->status($uid,$snapshot['job_id']);
                return $status['complete']?$this->jobs->result($uid,$snapshot['job_id'],$input['offset']??0,$input['limit']??50):$status;
            }
            $raw=$this->native($uid)->read($uid,$action,$input);
            if($action==='forms_list_response_files')return ['files'=>$this->policy->resourceBoundary($uid,fn()=>$this->files->listing($uid,$raw['files']??[]))]+array_intersect_key($raw,['_mcp_page'=>true]);
            if(in_array($action,['forms_read_links','forms_get_embed'],true)) {
                $shares=$raw['shares']??$raw;$out=[];
                foreach($shares as $share) {
                    if((int)($share['shareType']??-1)!==3)continue;
                    if($action==='forms_get_embed'&&((int)$share['id']!==$input['share_id']||!in_array('embed',$share['permissions']??[],true)))continue;
                    $token=$share['shareWith'];
                    $share['url']=$this->urls->linkToRouteAbsolute('forms.page.publicLinkView',['hash'=>$token]);
                    $share['embedUrl']=$this->urls->linkToRouteAbsolute('forms.page.embeddedFormView',['hash'=>$token]);
                    $share['embedHtml']='<iframe src="'.htmlspecialchars($share['embedUrl'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" width="750" height="500"></iframe>';
                    $out[]=$share;
                }
                if($action==='forms_get_embed'&&count($out)!==1)throw new ToolFailure(ToolFailure::NOT_FOUND);
                return ['shares'=>$out];
            }
            return $raw;
        } catch(ToolFailure $e){throw $e;}catch(\Throwable){throw new ToolFailure('The Forms resource or capability is unavailable.');}
    }
    public function apply(string $uid,string $action,array $input,string $revision,string $operationKey):array {
        $guard=$this->native($uid);$key=$this->key($uid,$action,$input);
        $snapshot=$this->snapshots[$key]??throw new FormsBeforeEffect('A current private Forms approval manifest is required.');
        if(($snapshot['revision']??null)!==$revision)throw new FormsBeforeEffect('Forms approval revision changed.');
        $origin=$this->authorization->capture($uid,$action,$input,$snapshot);
        $read=$this->source($uid,$guard,$origin);
        if(in_array($action,FormsExportJobs::ACTIONS,true))return $this->jobs->start($uid,$action,$input,$snapshot,$operationKey,$origin,$read);
        if(in_array($action,['forms_continue_operation','forms_cancel_operation'],true)) {
            $jobId=$snapshot['job_id'];$job=$this->jobs->job($uid,$jobId);
            $read=$this->source($uid,$guard,$job['payload']['origin']);
            if($job['version']!==$snapshot['job_version'])throw new FormsBeforeEffect('The approved Forms job step changed.');
            if($action==='forms_cancel_operation')$data=$this->jobs->cancel($uid,$jobId,$operationKey,$snapshot['job_version']);
            elseif($job['state']==='ready'&&$job['payload']['tool']==='forms_download_results')$data=$this->jobs->issueDownload($uid,$jobId,$operationKey,$snapshot['job_version'],fn():array=>$this->downloads->issue($uid,$jobId));
            elseif($job['state']==='ready'&&in_array($job['payload']['tool'],['forms_export_results','forms_link_results_file'],true)) {
                $data=$this->jobs->deliver($uid,$jobId,$read,fn(string $content,array $claimed):array=>$this->policy->resourceBoundary($uid,fn()=>$this->destination->save($uid,$claimed['payload']['arguments'],$claimed['payload']['destination'],$content,$this->fileBoundary($uid,$guard,$claimed['payload']['origin'],array_map(static fn(int $id):array=>['file_id'=>$id],array_values($claimed['payload']['file_ids']??[])),fn()=>$this->jobs->assertDelivery($uid,$claimed,$read)))),$operationKey,$snapshot['job_version']);
            } else $data=$this->jobs->step($uid,$jobId,$read,function(array $submission)use($uid,$guard,$job):array {
                $refs=$guard->read($uid,'forms_list_response_files',['id'=>$job['payload']['arguments']['id'],'submission_id'=>$submission['id']]);
                $visible=$this->policy->resourceBoundary($uid,fn()=>$this->files->listing($uid,$refs['files']??[]));$out=[];
                foreach($visible as $file)$out[$file['file_id']]=['name'=>$file['name'],'url'=>$this->urls->linkToRouteAbsolute('files.View.showFile',['fileid'=>$file['file_id']])];
                return $out;
            },$operationKey,$snapshot['job_version']);
            return ['state'=>$data['complete']?'succeeded':'pending','data'=>$data,'references'=>['job_id'=>$jobId]];
        }
        if($action==='forms_generate_link_token')return ['state'=>'succeeded','data'=>$guard->read($uid,$action,$input),'references'=>[]];
        if(isset($input['answers'])) {
            $input['answers']=$snapshot['native_answers']??throw new FormsBeforeEffect('A validated typed-answer preview is required.');
        }
        $input['_mcp_origin']=$origin;$transfer=null;
        if($action==='forms_upload_response_files') {
            $input['_mcp_sources']=$snapshot['file_sources'];
            $transfer=fn():array=>$this->policy->resourceBoundary($uid,fn()=>$this->uploads->withSources($uid,$snapshot['file_sources'],
                fn(array $sources):array=>$guard->stageFiles($uid,$input['id'],$input['question_id'],$input['share_hash']??'',$sources)));
        } elseif($action==='forms_save_response_file') {
            $transfer=fn():array=>['data'=>$this->policy->resourceBoundary($uid,fn()=>$this->files->save($uid,$snapshot['file_destination'],$this->fileBoundary($uid,$guard,$origin,$snapshot['file_destination']['source']))),'references'=>['form_id'=>$input['id'],'submission_id'=>$input['submission_id'],'file_id'=>$input['file_id']],'files_phase'=>'saved_verified'];
        }
        try {return $this->outcome($guard->apply($uid,$action,$input,$revision,$operationKey,$snapshot['file_manifest']??[],$transfer));}
        catch(\Throwable $failure) {
            if(is_a($failure,'OCA\\Forms\\Mcp\\BeforeEffect'))throw new FormsBeforeEffect('Forms refused the change before any effect. Review current permissions, settings and limits.');
            throw $failure;
        } finally {unset($this->snapshots[$key]);}
    }
    /** Trusted callback is never accepted from tool arguments. Native storage invokes admission after its actual lock. */
    private function fileBoundary(string $uid,object $guard,array $origin,array $sources,?\Closure $deliveryAdmission=null):\Closure {
        if(($guard->capabilities()['file_sinks']??false)!==true)throw new FormsBeforeEffect('This Forms runtime does not qualify Files write sinks.');
        return function(\OCP\Files\Node $node,?string $child,\Closure $filePolicy,\Closure $write,?array $preimage=null)use($uid,$guard,$origin,$sources,$deliveryAdmission):mixed {
            $authorize=fn()=>$this->policy->resourceBoundary($uid,function()use($uid,$origin,$sources,$node,$child,$filePolicy,$preimage,$deliveryAdmission):void {
                $this->authorization->assert($origin);
                if($deliveryAdmission!==null)$deliveryAdmission();
                $impact=['files'=>$sources];
                if($child===null)$impact['files'][]=['file_id'=>(int)$node->getId()];
                else $impact['share_impact']['parent']=['file_id'=>(int)$node->getId(),'create_folder'=>true];
                $this->impact->assertVisible($uid,$impact);$filePolicy();
                if($preimage!==null) {
                    if(!$node instanceof \OCP\Files\File)throw new \RuntimeException('Invalid Forms overwrite destination.');
                    \OCA\Mcp\Modules\Forms\Attachments\FormsFilePreimage::assert($node,$preimage);
                }
            });
            return $guard->fileBoundary($uid,$origin,$node,$child,$authorize,$write);
        };
    }
    private function outcome(array $result):array {
        if(isset($result['phases'])) {
            $result['references']['database']=$result['phases']['database']??'unknown';
            $result['references']['files']=$result['phases']['files']??'unknown';
            $result['references']['notifications']=array_map(static fn(array $event):array=>array_intersect_key($event,array_flip(['id','kind','phase'])),$result['phases']['events']??[]);
        }
        return $result;
    }
    private function source(string $uid,object $guard,array $origin):\Closure {
        return static fn(string $action,array $args):array=>$action==='snapshot'?$guard->snapshot($uid,'forms_results_metadata',$args+['_mcp_origin'=>$origin]):$guard->read($uid,$action,$args+['_mcp_origin'=>$origin]);
    }
    public function lookup(string $uid,string $operationKey,?array $context=null):?array {
        if($context!==null&&in_array($context['tool'],['forms_continue_operation','forms_cancel_operation'],true)) {
            $refs=$context['references'];
            if(!isset($refs['job_id'],$refs['job_version'])||!is_int($refs['job_version']))return null;
            return $this->jobs->recover($uid,$refs['job_id'],$operationKey,$refs['job_version']);
        }
        $id=hash('sha256','forms-job-v1:'.$operationKey);
        $evidence=$this->jobs->admissionEvidence($uid,$id);if($evidence!==null)return $evidence;
        $origin=$context===null?null:$this->authorization->captureHistory($uid,$context['tool'],$context['references']);
        $result=$this->native($uid)->lookup($uid,$operationKey,$origin);
        return $result===null?null:$this->outcome($result);
    }
}
