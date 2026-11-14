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
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
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
        private \OCA\Mcp\Access\Grants\GrantPolicy $policy,private \OCA\Mcp\Modules\Forms\Exports\FormsLinkedFiles $linkedFiles,private \OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportRunner $linkedRunner,private \Closure $commentService,private FormsBulkWorkflow $bulk,private FormsBulkControl $bulkControl,private FormsRecoveryReads $recoveryReads) {}
    public function capabilities(string $uid):array {return $this->features->forUser($uid);}
    private function native(string $uid):object {
        if(!$this->features->forUser($uid)['guardedWrites'])throw new ToolFailure('The installed Forms runtime has not been qualified for this capability.');
        return ($this->guard)();
    }
    private function comments():\OCA\Mcp\Modules\Forms\Comments\FormsComments {return ($this->commentService)();}
    private function key(string $uid,string $action,array $input):string {
        return hash('sha256',json_encode([$uid,$action,FormsOperationJournal::canonical($input)],JSON_THROW_ON_ERROR));
    }
    public function snapshot(string $uid,string $action,array $input):array {
        try {
            $guard=$this->native($uid);
            $nativeCapabilities=$guard->capabilities();
            if(!FormsPublication::supported($action,$nativeCapabilities))throw new FormsBeforeEffect('This Forms workflow is not available on the qualified runtime.');
            if(in_array($action,FormsRecoveryReads::ACTIONS,true)) {
                $snapshot=$this->recoveryReads->snapshot($uid,$action,$input,$guard,
                    fn(string $operation):array=>$this->journal->context($uid,$operation,fn(string $tool,array $refs)=>$this->authorization->assertHistory($uid,$tool,$refs))['references'],
                    fn(string $tool,array $args,array $view):array=>$this->authorization->capture($uid,$tool,$args,$view));
                $snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
                $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;return $snapshot;
            }
            if($this->wantsBulk($action,$input)&&($nativeCapabilities['bulk_lifecycle']??false)===true) {
                $snapshot=$this->bulk->preview($uid,$action,$input,$guard,
                    fn(string $tool,array $args,array $view):array=>$this->authorization->capture($uid,$tool,$args,$view),
                    fn(array $origin,string $tool,array $manifest)=>$this->authorization->assertFiles($origin,$tool,$manifest));
                $snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
                $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;return $snapshot;
            }
            if($action==='forms_restore'&&str_starts_with($input['backup_id']??'','bulk_v1:'))throw new FormsBeforeEffect('Qualified bounded Forms recovery is unavailable.');
            $previewOrigin=$this->authorization->capture($uid,$action,$input,[]);$read=$this->source($uid,$guard,$previewOrigin);
            if(in_array($action,\OCA\Mcp\Modules\Forms\Comments\FormsComments::ACTIONS,true)) {
                $private=array_replace($input,['_mcp_origin'=>$previewOrigin]);
                $snapshot=$guard->commentsSnapshot($uid,$action,$private,fn():array=>$this->comments()->snapshot($uid,$input['id'],$input['comment_id']??null));
                $snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
                $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;return $snapshot;
            }
            if(in_array($action,['forms_continue_operation','forms_cancel_operation','forms_read_result'],true)) {
                $context=$this->journal->context($uid,$input['target_operation_id'],fn(string $tool,array $refs)=>$this->authorization->assertHistory($uid,$tool,$refs));
                if(isset($context['references']['bulk_id'])) {
                    if(($nativeCapabilities['bulk_lifecycle']??false)!==true)throw new ToolFailure('Qualified native Forms bulk control is unavailable.');
                    $origin=$this->controlOrigin($uid,$action,$context['references']);
                    $snapshot=$this->bulkControl->snapshot($uid,$action,$context['references'],$origin,$guard);
                    $snapshot['native_runtime']=FormsGuardIdentity::INSTALLED_MANIFEST_SHA256;
                    $this->snapshots[$this->key($uid,$action,$input)]=$snapshot;return $snapshot;
                }
                $receipt=['references'=>$context['references']];
                $jobId=$receipt['references']['job_id']??throw new ToolFailure('The original operation has no Forms job.');
                $job=$this->jobs->job($uid,$jobId);
                if($action==='forms_continue_operation'&&in_array($job['payload']['tool'],['forms_export_results','forms_link_results_file'],true)&&($guard->capabilities()['file_sinks']??false)!==true)throw new ToolFailure('This Forms runtime does not qualify the original Files write sink.');
                $read=$this->source($uid,$guard,$job['payload']['origin']);$snapshot=$read('snapshot',['id'=>$job['payload']['arguments']['id']]);
                if($action!=='forms_cancel_operation'&&FormsExportJobs::sourceRevision($snapshot)!==$job['payload']['source_revision'])throw new ToolFailure('The approved Forms job source changed.');
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
                if($action==='forms_export_results')$snapshot['file_destination']=$this->policy->resourceBoundary($uid,fn()=>$this->destination->snapshot($uid,$input));
                if($action==='forms_link_results_file') {
                    if(!$this->linkedRunner->available())throw new ToolFailure('The qualified isolated Forms CLI worker is unavailable.');
                    $snapshot['file_destination']=$this->linkedFiles->initialSnapshot($uid,$input);
                }
                if($action==='forms_link_results_file'&&(($snapshot['form']['ownerId']??null)!==$uid||$this->journal->connection()===0||!empty($snapshot['form']['fileId'])))throw new ToolFailure('A new owned association and a persistent original connection are required.');
                $snapshot=$this->jobs->preview($uid,$action,$input,$snapshot,$read);
                if($action==='forms_link_results_file') {
                    $snapshot['linked_approval']=['arguments'=>$input,'native_runtime'=>FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,
                        'source_revision'=>FormsExportJobs::sourceRevision($snapshot),
                        'schema_digest'=>\OCA\Mcp\Modules\Forms\Exports\FormsLinkedExport::schemaDigest($snapshot['export_metadata']),
                        'destination'=>$snapshot['file_destination']];
                    $snapshot['impact']['linked_export']=['updates'=>'Future submissions update this complete export until unlink, revocation, or a source, owner, runtime or destination change.',
                        'recipients'=>$snapshot['file_destination']['audience']['recipients'],'format'=>$input['format'],
                        'respondents'=>(bool)($input['include_respondents']??false),'file_references'=>(bool)($input['include_files']??false),
                        'recovery'=>'Every overwrite requires verified recovery of the old bytes. Unlink preserves the output file.'];
                }
            }
            if($action==='forms_unlink_results_file') {
                if(($snapshot['form']['ownerId']??null)!==$uid||!is_int($snapshot['form']['fileId']??null))
                    throw new ToolFailure('An exact linked form owned by the current actor is required.');
                $status=$guard->linkedStatus($uid,$input['id']);
                $snapshot['unlink_approval']=['file_id'=>$snapshot['form']['fileId'],'file_format'=>$snapshot['form']['fileFormat']];
                if(isset($status['association_id'],$status['version']))$snapshot['unlink_approval']+=['association_id'=>$status['association_id'],'version'=>$status['version']];
                else $snapshot['unlink_approval']['native_ui']=true;
                $snapshot['impact']['linked_export']=['updates'=>'Stop automatic updates and retain the output file.','state'=>$status['state']??'native_ui'];
            }
            if(in_array($action,['forms_update_share','forms_unshare'],true))$snapshot['shares']=$guard->read($uid,'forms_list_shares',['id'=>$input['id']]);
            if($action==='forms_upload_response_files')$snapshot['file_sources']=$this->policy->resourceBoundary($uid,fn()=>$this->uploads->snapshot($uid,$input['paths']));
            if($action==='forms_save_response_file') {
                $refs=$guard->read($uid,'forms_list_response_files',$input+['_mcp_file_id'=>$input['file_id']]);
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
            if(in_array($action,FormsRecoveryReads::ACTIONS,true)) {
                $snapshot=$this->snapshots[$this->key($uid,$action,$input)]??throw new ToolFailure('A current Forms recovery read snapshot is required.');
                return $this->recoveryReads->read($uid,$action,$input,$snapshot,$this->native($uid),
                    fn(string $tool,array $args,array $view):array=>$this->authorization->capture($uid,$tool,$args,$view),
                    fn(array $origin,string $tool,array $manifest)=>$this->authorization->assertFiles($origin,$tool,$manifest));
            }
            if($action==='forms_list_comments') {
                $snapshot=$this->snapshots[$this->key($uid,$action,$input)]??throw new ToolFailure('A current comment snapshot is required.');
                $origin=$this->authorization->capture($uid,$action,$input,$snapshot);
                return $this->native($uid)->commentsRead($uid,array_replace($input,['_mcp_origin'=>$origin]),fn():array=>$this->comments()->read($uid,$input));
            }
            if($action==='forms_search')return $this->listing->page($uid,$input,$this->inventory->rows($uid,$input));
            if($action==='forms_read_result') {
                $snapshot=$this->snapshots[$this->key($uid,$action,$input)]??throw new ToolFailure('A current Forms job snapshot is required.');
                if(isset($snapshot['bulk_id']))return $snapshot['bulk_status']+['references'=>$snapshot['impact']['bulk']['recovery']];
                $status=$this->jobs->status($uid,$snapshot['job_id']);
                return $status['complete']?$this->jobs->result($uid,$snapshot['job_id'],$input['offset']??0,$input['limit']??50):$status;
            }
            $native=$this->native($uid);$raw=$native->read($uid,$action,$input);
            if($action==='forms_read_settings'&&($raw['ownerId']??null)===$uid&&method_exists($native,'linkedStatus'))
                $raw['linkedExport']=$native->linkedStatus($uid,$input['id']);
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
        if(isset($snapshot['bulk_action'])) {
            try {
                if(isset($snapshot['bulk_input'])) {
                    $dto=$guard->bulkStart($uid,$action,$snapshot['bulk_input']+['_mcp_origin'=>$origin],$revision,$operationKey);
                } elseif($action==='forms_cancel_operation') {
                    $dto=$guard->bulkCancel($uid,$snapshot['bulk_id'],$origin,$snapshot['bulk_version'],$operationKey);
                } elseif($action==='forms_continue_operation') {
                    $dto=$guard->bulkStep($uid,$snapshot['bulk_id'],$origin,$snapshot['bulk_version'],$operationKey,
                        fn(string $tool,array $manifest)=>$this->authorization->assertFiles($origin,$tool,$manifest));
                } else throw new FormsBeforeEffect('The approved Forms bulk control is unavailable.');
                return $this->outcome(FormsBulkWorkflow::outcome($dto,$snapshot['bulk_action']));
            } catch(\Throwable $failure) {
                if(is_a($failure,'OCA\\Forms\\Mcp\\BeforeEffect'))throw new FormsBeforeEffect('Forms refused the bounded change before any effect; review a fresh approval.');
                throw $failure;
            } finally {unset($this->snapshots[$key]);}
        }
        if(in_array($action,FormsExportJobs::ACTIONS,true))return $this->jobs->start($uid,$action,$input,$snapshot,$operationKey,$origin,$read);
        if(in_array($action,['forms_continue_operation','forms_cancel_operation'],true)) {
            $jobId=$snapshot['job_id'];$job=$this->jobs->job($uid,$jobId);
            $read=$this->source($uid,$guard,$job['payload']['origin']);
            if($job['version']!==$snapshot['job_version'])throw new FormsBeforeEffect('The approved Forms job step changed.');
            if($action==='forms_cancel_operation')$data=$this->jobs->cancel($uid,$jobId,$operationKey,$snapshot['job_version']);
            elseif($job['state']==='ready'&&$job['payload']['tool']==='forms_download_results')$data=$this->jobs->issueDownload($uid,$jobId,$operationKey,$snapshot['job_version'],fn():array=>$this->downloads->issue($uid,$jobId));
            elseif($job['state']==='ready'&&in_array($job['payload']['tool'],['forms_export_results','forms_link_results_file'],true)) {
                $data=$this->jobs->deliver($uid,$jobId,$read,fn(string $content,array $claimed):array=>$claimed['payload']['tool']==='forms_link_results_file'?(new \OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation())->activate($uid,$claimed,$guard,$read,fn():array=>$this->linkedFiles->initialWrite($uid,$claimed,$content,$read)):$this->policy->resourceBoundary($uid,fn()=>$this->destination->save($uid,$claimed['payload']['arguments'],$claimed['payload']['destination'],$content,$this->fileBoundary($uid,$guard,$claimed['payload']['origin'],array_map(static fn(int $id):array=>['file_id'=>$id],array_values($claimed['payload']['file_ids']??[])),fn()=>$this->jobs->assertDelivery($uid,$claimed,$read)))),$operationKey,$snapshot['job_version']);
            } else $data=$this->jobs->step($uid,$jobId,$read,function(array $submission,?\Closure $checkpoint=null)use($uid,$guard,$job):array {
                $visible=\OCA\Mcp\Modules\Forms\Exports\FormsExportFileReferences::collect(
                    function(int $offset,int $limit)use($uid,$guard,$job,$submission):array {
                        $this->authorization->assert($job['payload']['origin']);
                        return $guard->read($uid,'forms_list_response_files',['id'=>$job['payload']['arguments']['id'],'submission_id'=>$submission['id'],'offset'=>$offset,'limit'=>$limit]);
                    },
                    fn(array $refs):array=>$this->policy->resourceBoundary($uid,fn()=>$this->files->listing($uid,$refs)),$checkpoint);$out=[];
                foreach($visible as $file)$out[$file['file_id']]=['name'=>$file['name'],'url'=>$this->urls->linkToRouteAbsolute('files.View.showFile',['fileid'=>$file['file_id']])];
                return $out;
            },$operationKey,$snapshot['job_version']);
            return ['state'=>$data['complete']||$data['state']==='cancelled'?'succeeded':'pending','data'=>$data,'references'=>['job_id'=>$jobId]];
        }
        if($action==='forms_generate_link_token')return ['state'=>'succeeded','data'=>$guard->read($uid,$action,$input),'references'=>[]];
        if(isset($input['answers'])) {
            $input['answers']=$snapshot['native_answers']??throw new FormsBeforeEffect('A validated typed-answer preview is required.');
        }
        $input['_mcp_origin']=$origin;$transfer=null;
        if($action==='forms_unlink_results_file')$input['_mcp_unlink_approval']=$snapshot['unlink_approval']??throw new FormsBeforeEffect('An exact unlink approval is required.');
        if($action==='forms_upload_response_files') {
            $input['_mcp_sources']=$snapshot['file_sources'];
            $transfer=fn():array=>$this->policy->resourceBoundary($uid,fn()=>$this->uploads->withSources($uid,$snapshot['file_sources'],
                fn(array $sources):array=>$guard->stageFiles($uid,$input['id'],$input['question_id'],$input['share_hash']??'',$sources)));
        } elseif($action==='forms_save_response_file') {
            $transfer=fn():array=>['data'=>$this->policy->resourceBoundary($uid,fn()=>$this->files->save($uid,$snapshot['file_destination'],$this->fileBoundary($uid,$guard,$origin,$snapshot['file_destination']['source']))),'references'=>['form_id'=>$input['id'],'submission_id'=>$input['submission_id'],'file_id'=>$input['file_id']],'files_phase'=>'saved_verified'];
        }
        try {
            if(in_array($action,\OCA\Mcp\Modules\Forms\Comments\FormsComments::ACTIONS,true))
                return $this->outcome($guard->commentsApply($uid,$action,$input,$revision,$operationKey,fn():array=>$this->comments()->apply($uid,$action,$input,$snapshot['comment']??[])));
            return $this->outcome($guard->apply($uid,$action,$input,$revision,$operationKey,$snapshot['file_manifest']??[],$transfer));
        }
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
    private function wantsBulk(string $action,array $input):bool {
        return in_array($action,FormsBulkWorkflow::ACTIONS,true)&&($action!=='forms_restore'||str_starts_with($input['backup_id']??'','bulk_v1:'));
    }
    private function controlOrigin(string $uid,string $action,array $references):array {
        $references['required_operations']=[\OCA\Mcp\Modules\Forms\Execution\FormsCommands::commands()[$action]['operation']];
        $references['files_operations']=[];
        return $this->authorization->captureHistory($uid,$action,$references);
    }
    public function lookup(string $uid,string $operationKey,?array $context=null):?array {
        if(isset($context['references']['bulk_authority'])) {
            $origin=$this->authorization->captureHistory($uid,$context['tool'],$context['references']);
            $result=$this->bulkControl->lookup($uid,$operationKey,$context['tool'],$context['references'],$origin,$this->native($uid));
            return $result===null?null:$this->outcome($result);
        }
        if($context!==null&&in_array($context['tool'],['forms_continue_operation','forms_cancel_operation'],true)) {
            $refs=$context['references'];
            if(!isset($refs['job_id'],$refs['job_version'])||!is_int($refs['job_version']))return null;
            if($context['tool']==='forms_continue_operation') {
                $native=$this->native($uid);
                $committed=$this->jobs->reconcileLinkedDelivery($uid,$refs['job_id'],$operationKey,$refs['job_version'],
                    function(array $original)use($uid,$operationKey,$native):?array {
                        $this->authorization->assert($original);return $native->lookup($uid,$operationKey,$original);
                    });
                if($committed!==null)return $this->outcome($committed);
            }
            $recovered=$this->jobs->recover($uid,$refs['job_id'],$operationKey,$refs['job_version']);
            return $recovered===null?null:$this->outcome($recovered);
        }
        $id=hash('sha256','forms-job-v1:'.$operationKey);
        $evidence=$this->jobs->admissionEvidence($uid,$id);if($evidence!==null)return $evidence;
        $origin=$context===null?null:$this->authorization->captureHistory($uid,$context['tool'],$context['references']);
        $result=$this->native($uid)->lookup($uid,$operationKey,$origin);
        return $result===null?null:$this->outcome($result);
    }
}
