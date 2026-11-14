<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp;
use OCA\Forms\Mcp\Service\{FormsRevisionFence, ApprovedFormsOperation, NativeActions, FileRecovery, AnswerFiles, OriginContext, ScopedServices, ReadPages, ResultSourceRevision, LinkedMarkers, DeferredFileContext};
use OCA\Forms\Service\{FormsService, ConfigService};
use OCA\Forms\Db\{FormMapper, QuestionMapper, SubmissionMapper};
use OCP\Server;
final class Guard {
    public function __construct(private FormsRevisionFence $fence, private ApprovedFormsOperation $operations) {}
    public function capabilities(): array {
        $identity = SourceIdentity::verify();
        $db = Server::get(\OCP\IDBConnection::class);
        $provider = $db->getDatabaseProvider(true);
        $core=Server::get(\OCP\ServerVersion::class)->getVersion();
        $databaseVersion=in_array($provider,['mariadb','postgres'],true) ? (string)Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT VERSION()')->fetchOne() : '';
        $runtimeQualified=PHP_INT_SIZE===8 && array_slice($core,0,3)===[35,0,2]
            && (($provider==='postgres' && str_starts_with($databaseVersion,'PostgreSQL 16.15 '))
                || ($provider==='mariadb' && str_starts_with($databaseVersion,'10.11.19-MariaDB')));
        $config=Server::get(\OCP\IConfig::class);
        $fileLocks=$config->getSystemValue('filelocking.enabled',true)===true && $config->getSystemValue('memcache.locking','')==='' && $config->getSystemValue('filelocking.excluded',[])===[] && $config->getSystemValue('filelocking.debug',false)===false;
        $schemaReady=$db->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113060000'])->fetchOne()!==false;
        $qualified = $schemaReady && $identity && \OCA\Forms\Mcp\Service\SharingIdentity::verify() && $runtimeQualified && $fileLocks && empty($config->getSystemValue('dbreplica', []));
        return ['protocol'=>1,'qualified'=>$qualified,'version'=>'5.4.2','source'=>'066573fd592513a06f8b8624def8244e911efdfd',
            'database'=>$provider,'actions'=>$qualified ? array_merge(NativeActions::WRITES,['forms_read_own_response','forms_read_submission_form']) : [],'own_response_pages'=>$qualified,'public_submission_form'=>$qualified, 'answers_preflight'=>$qualified,'file_sinks'=>$qualified,'files_recovery'=>true,'files_shares'=>$qualified,'files_share_types'=>$qualified?[0,1]:[],'event_delivery'=>'independently_reported'];
    }
    /** Current native principal authority without borrowing a request or worker session. */
    public function assertPrincipal(string $uid, bool $requiresAdmin = false): void {
        if (!$this->capabilities()['qualified']) {
            throw new \RuntimeException('Forms native guard is not qualified');
        }
        (new \OCA\Forms\Mcp\Service\FreshCore($uid))->assertPrincipal($requiresAdmin);
    }
    private function actor(string $uid): void {
        $user=Server::get(\OCP\IUserSession::class)->getUser();
        if (!$user || $user->getUID()!==$uid) { throw new \RuntimeException('Forms actor is unavailable'); }
        $this->assertPrincipal($uid);
    }
    private function authorize(string $uid,string $action,array $i): void {
        $this->actor($uid);
        if (strlen(json_encode($i,JSON_THROW_ON_ERROR))>1048576) {throw new \InvalidArgumentException('Forms input exceeds limit');}
        if (str_contains($action,'admin_settings')) {
            $this->assertPrincipal($uid, true);
            return;
        }
        if ($action==='forms_search') {return;}
        if (in_array($action,ReadPages::ACTIONS,true)) {ReadPages::bounds($i);}
        if ($action==='forms_read_submission_form') {ReadPages::publicForm($uid,$i);return;}
        if ($action==='forms_restore') { FileRecovery::snapshot($uid,$action,$i); return; }
        if (in_array($action,['forms_create','forms_clone'],true)) {
            if (!(new ScopedServices($uid))->get(ConfigService::class)->canCreateForms()) {throw new \RuntimeException('Form creation is not allowed');}
            if ($action==='forms_create') {return;}
        }
        if ($action==='forms_generate_link_token') {
            if (!(new ScopedServices($uid))->get(ConfigService::class)->getAllowPublicLink()) {throw new \RuntimeException('Public links are disabled');}
            return;
        }
        $id=(int)($i['id']??0);
        $forms=(new ScopedServices($uid,false,in_array($action,array_merge(ReadPages::ACTIONS,['forms_results_metadata','forms_results_page']),true)))->forms();
        if (in_array($action,['forms_submit_response','forms_upload_response_files'],true)) {$forms->loadFormForSubmission($id,$i['share_hash']??''); return;}
        $permission=match($action) {
            'forms_delete_form','forms_transfer','forms_list_shares','forms_read_links','forms_get_embed','forms_share','forms_update_share','forms_unshare'=>'all',
            'forms_read_response','forms_read_own_response','forms_read_own_responses','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page'=>'results',
            'forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses'=>'results_delete',
            'forms_read_settings','forms_read_question'=>'submit',
            default=>'edit',
        };
        $form=$forms->getFormIfAllowed($id,$permission);
        if ($action==='forms_list_response_files') {ReadPages::submission($id,(int)($i['submission_id']??0));}
        if ($action==='forms_update') {
            $allowed=['title','description','expires','isAnonymous','submitMultiple','allowEditSubmissions','showExpiration','submissionMessage','maxSubmissions','confirmationEmailEnabled','confirmationEmailSubject','confirmationEmailBody','confirmationEmailQuestionId','allowComments','access'];
            if (!isset($i['fields']) || !is_array($i['fields']) || $i['fields']===[] || array_diff(array_keys($i['fields']),$allowed)!==[]) {throw new \InvalidArgumentException('Unsupported generic Forms fields');}
        }
        if (in_array($action,['forms_read_response','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page','forms_update_response','forms_delete_response','forms_delete_responses'],true)) {
            $required=in_array($action,['forms_read_response','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page'],true) ? 'results' : 'results_delete';
            if (!in_array($required,$forms->getPermissions($form),true)) {
                if ($action==='forms_list_response_files') {ReadPages::submission($id,(int)($i['submission_id']??0),$uid);}
                else {throw new \RuntimeException('Explicit results permission required');}
            }
        }
        if ($action==='forms_set_state' && ($form->getState()===2 || ($i['state']??null)===2) && $form->getOwnerId()!==$uid) {throw new \RuntimeException('Only the owner can archive or unarchive a form');}
        if (($action==='forms_set_lock' && ($i['permanent']??false)) || ($action==='forms_release_lock' && $form->getLockedUntil()===0)) {
            if ($form->getOwnerId()!==$uid) {throw new \RuntimeException('Only the owner can change a permanent lock');}
        }
        if (str_contains($action,'_own_response') && isset($i['submission_id'])) {
            $submission=Server::get(SubmissionMapper::class)->findById($i['submission_id']);
            if ($submission->getFormId()!==$id || $submission->getUserId()!==$uid) {throw new \RuntimeException('Own response is unavailable');}
        }
    }
    public function snapshot(string $uid,string $action,array $input): array {
        return OriginContext::within($uid,$input['_mcp_origin']??[],$action,$input,fn()=> $this->snapshotNative($uid,$action,$input));
    }
    private function snapshotNative(string $uid,string $action,array $input): array {
        $before=$this->fence->revision();
        $this->authorize($uid,$action,$input);
        $form=[];
        if (in_array($action,ReadPages::ACTIONS,true)) {
            $entity=$action==='forms_read_submission_form'?ReadPages::publicForm($uid,$input):Server::get(FormMapper::class)->findById((int)$input['id']);
            $form=ReadPages::metadata($entity);
            OriginContext::assertFresh();$this->authorize($uid,$action,$input);$after=$this->fence->revision();
            if ($before!==$after) {throw new \RuntimeException('Forms changed during preview');}
            return ReadPages::bounded(['file_manifest'=>[],'revision'=>$after,'form'=>$form,'config'=>[],'source'=>'066573fd592513a06f8b8624def8244e911efdfd','summary'=>['form_id'=>$entity->getId(),'action'=>$action],'impact'=>['database'=>false,'files'=>false,'events'=>false]]);
        }
        if (isset($input['id'])) {
            $entity=Server::get(FormMapper::class)->findById((int)$input['id']);
            $form=$entity->read();
            unset($form['hash']);
            $form['permissions']=(new ScopedServices($uid))->forms()->getPermissions($entity);
            $form['questions']=(new ScopedServices($uid))->forms()->getQuestions((int)$input['id']);
        }
        $exportRevision=null;
        if (in_array($action,['forms_results_metadata','forms_results_page'],true) && isset($input['id'])) {
            $metadata=$this->resultsMetadata($uid,(int)$input['id'],null,$entity,$form['questions']);
            $exportRevision=$metadata['export_revision'];
        }
        $config=(new ScopedServices($uid))->get(ConfigService::class)->getAppConfig();
        if (strlen(json_encode([$form,$config],JSON_THROW_ON_ERROR))>1048576) {throw new \RuntimeException('Forms preview exceeds projection budget');}
        $fileManifest=FileRecovery::snapshot($uid,$action,$input);
        $after=$this->fence->revision();
        if ($before!==$after) {throw new \RuntimeException('Forms changed during preview');}
        return ($exportRevision===null?[]:['export_revision'=>$exportRevision])+['file_manifest'=>$fileManifest,'revision'=>$after,'form'=>$form,'config'=>$config,'source'=>'066573fd592513a06f8b8624def8244e911efdfd','summary'=>['form_id'=>$input['id']??null,'action'=>$action],
            'impact'=>['database'=>true,'files'=>'requires_separate_qualification','events'=>($fileManifest['files_share']??false) || in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_share'],true)]];
    }
    /** Read-only semantic admission before the MCP journal reserves an operation. */
    public function preflightAnswers(string $uid, string $action, array $input, string $revision): void {
        if (!in_array($action, ['forms_submit_response', 'forms_update_response', 'forms_update_own_response'], true)) {
            throw new \InvalidArgumentException('Unsupported Forms answer operation');
        }
        OriginContext::within($uid, $input['_mcp_origin'] ?? [], $action, $input, function () use ($uid, $action, $input, $revision): void {
            if ($this->fence->revision() !== $revision) {
                throw new BeforeEffect('Forms preview is stale');
            }
            $this->authorize($uid, $action, $input);
            try {
                $scope = new ScopedServices($uid);
                $forms = $scope->forms();
                $id = (int)$input['id'];
                $form = $forms->loadFormForSubmission($id, $action === 'forms_submit_response' ? ($input['share_hash'] ?? '') : '');
                if ($action === 'forms_submit_response') {
                    if (!$forms->canSubmit($form)) {
                        throw new \OCP\AppFramework\OCS\OCSForbiddenException('Already submitted');
                    }
                    if ($form->getMaxSubmissions() > 0 && Server::get(SubmissionMapper::class)->countSubmissions($id) >= $form->getMaxSubmissions()) {
                        throw new \OCP\AppFramework\OCS\OCSForbiddenException('Maximum number of submissions reached');
                    }
                } else {
                    $submission = Server::get(SubmissionMapper::class)->findById((int)$input['submission_id']);
                    if ($submission->getFormId() !== $id || !$forms->canDeleteResults($form)
                        || ($action === 'forms_update_own_response' && $submission->getUserId() !== $uid)) {
                        throw new \OCP\AppFramework\OCS\OCSForbiddenException('Response is unavailable for editing');
                    }
                }
                if (!isset($input['answers']) || !is_array($input['answers'])) {
                    throw new \InvalidArgumentException('Invalid Forms answers');
                }
                $questions = $forms->getQuestions($id);
                foreach ($questions as $question) {
                    $values = $input['answers'][$question['id']] ?? null;
                    if ($values !== null && !is_array($values)) {
                        throw new \InvalidArgumentException('Invalid Forms answer representation');
                    }
                    if (in_array($question['type'], ['short', 'color'], true) && $values === []) {
                        throw new \InvalidArgumentException('Scalar answers require a value or omission');
                    }
                    if ($values !== null && in_array($question['type'], ['dropdown', 'multiple', 'multiple_unique'], true)) {
                        $options = array_map('intval', array_column($question['options'] ?? [], 'id'));
                        foreach ($values as $value) {
                            if (is_string($value) && !empty($question['extraSettings']['allowOtherAnswer'])
                                && str_starts_with($value, \OCA\Forms\Constants::QUESTION_EXTRASETTINGS_OTHER_PREFIX)) {
                                continue;
                            }
                            if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || !in_array((int)$value, $options, true)) {
                                throw new \InvalidArgumentException('Choice answer is not an option of this question');
                            }
                        }
                    }
                }
                $answers = AnswerFiles::inspect($uid, $id, $input['answers']);
                try {
                    $scope->get(\OCA\Forms\Service\SubmissionService::class)->validateSubmission($questions, $answers, $form->getOwnerId(), $id);
                } catch (\TypeError $error) {
                    throw new \InvalidArgumentException('Invalid Forms answer representation', 0, $error);
                }
            } finally {
                OriginContext::assertFresh();
                $this->authorize($uid, $action, $input);
                if ($this->fence->revision() !== $revision) {
                    throw new BeforeEffect('Forms preview is stale');
                }
            }
        });
    }

    public function lookup(string $uid,string $operationKey,?array $origin=null): ?array {
        $this->actor($uid);
        return OriginContext::within($uid,$origin??[],'lookup',[],fn()=> $this->operations->lookup($uid,$operationKey) ?? FileRecovery::lookup($uid,$operationKey));
    }
    public function apply(string $uid,string $action,array $input,string $revision,string $operationKey,?array $fileManifest=null,?callable $transfer=null): array {
        return OriginContext::within($uid,$input['_mcp_origin']??[],$action,$input,fn()=> $this->applyNative($uid,$action,$input,$revision,$operationKey,$fileManifest,$transfer));
    }
    private function applyNative(string $uid,string $action,array $input,string $revision,string $operationKey,?array $fileManifest=null,?callable $transfer=null): array {
        return $this->operations->apply($uid,$action,$input,$revision,$operationKey,
            function () use($uid,$action,$input,$fileManifest): void {
                try {$this->authorize($uid,$action,$input);OriginContext::assertFresh();OriginContext::assertFiles($action,$fileManifest??[]);} catch (\Throwable $e) {throw new BeforeEffect($e->getMessage(),0,$e);}
            },
            function () use($uid,$action,$input,$fileManifest,$transfer,$operationKey): array {
                if ($action==='forms_link_results_file') {
                    if ($transfer===null) {throw new BeforeEffect('Linked Forms transfer admission is missing');}
                    return Server::get(LinkedMarkers::class)->activate($uid,$input,$transfer);
                }
                if ($action==='forms_unlink_results_file') {return Server::get(LinkedMarkers::class)->unlink($uid,$input);}
                return FileRecovery::run($uid,$action,$input,$fileManifest,$operationKey,function () use($uid,$action,$input,$transfer): array {
                    if (in_array($action,['forms_upload_response_files','forms_save_response_file'],true)) {
                        if ($transfer===null) {throw new BeforeEffect('Forms transfer admission is missing');}
                        return $transfer();
                    }
                    if (in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response'],true)) {
                        $input['answers']=AnswerFiles::resolve($uid,(int)$input['id'],$input['answers']);
                    }
                    return NativeActions::invoke($action,$input);
                });
            });
    }
    /** Server-only callback bridge. Sources are private streams from the MCP Files gate. */
    public function stageFiles(string $uid,int $id,int $questionId,string $shareHash,array $sources): array {
        $this->actor($uid);
        return AnswerFiles::stage($uid,$id,$questionId,$shareHash,$sources);
    }
    public function read(string $uid,string $action,array $i): array {
        if (isset($i['_mcp_origin'])) {return OriginContext::within($uid,$i['_mcp_origin'],$action,$i,fn()=> $this->readNative($uid,$action,$i));}
        return $this->readNative($uid,$action,$i);
    }
    private function readNative(string $uid,string $action,array $i): array {
        $this->authorize($uid,$action,$i);
        if (in_array($action,ReadPages::ACTIONS,true)) {
            $before=$this->fence->revision();$result=ReadPages::read($uid,$action,$i);
            if (OriginContext::active()) {OriginContext::assertFresh();}
            $this->authorize($uid,$action,$i);$after=$this->fence->revision();
            if ($before!==$after) {throw new \RuntimeException('Forms changed during read');}
            return ReadPages::bounded($result+['revision'=>$after]);
        }
        if ($action==='forms_results_metadata') {
            $before=$this->fence->revision();$result=$this->resultsMetadata($uid,(int)$i['id']);
            if (OriginContext::active()) {OriginContext::assertFresh();}
            $this->authorize($uid,$action,$i);$after=$this->fence->revision();
            if ($before!==$after) {throw new \RuntimeException('Forms changed during result metadata read');}
            return $result+['revision'=>$after];
        }
        $forms=(new ScopedServices($uid))->forms(); $id=(int)($i['id']??0);
        return match($action) {
            'forms_results_metadata'=>$this->resultsMetadata($uid,$id),
            'forms_results_page'=>$this->resultsPage($id,$i),
            'forms_read_settings'=>$forms->getForm(Server::get(FormMapper::class)->findById($id)),
            'forms_read_question'=>(new ScopedServices($uid))->get(\OCA\Forms\Controller\ApiController::class)->getQuestion($id,$i['question_id'])->getData(),
            'forms_list_shares','forms_read_links','forms_get_embed'=>$forms->getShares($id),
            'forms_read_response'=>(new ScopedServices($uid))->get(\OCA\Forms\Controller\ApiController::class)->getSubmission($id,$i['submission_id'])->getData()+['isAnonymous'=>Server::get(FormMapper::class)->findById($id)->getIsAnonymous()],
            'forms_generate_link_token'=>(new ScopedServices($uid))->get(\OCA\Forms\Controller\ShareApiController::class)->generateToken()->getData(),
            'forms_read_admin_settings'=>(new ScopedServices($uid))->get(ConfigService::class)->getAppConfig(),
            default=>throw new \InvalidArgumentException('Unsupported Forms native read'),
        };
    }
    /** Workers use verified original authority and a fresh native service graph, never the cron session. */
    public function deferredRead(array $origin,string $action,array $input): array {
        if (!in_array($action,['snapshot','forms_results_metadata','forms_results_page','forms_list_response_files'],true)
            || !in_array($origin['tool']??'',['forms_summarize_results','forms_download_results','forms_export_results','forms_link_results_file'],true)) {
            throw new \RuntimeException('Unsupported deferred Forms read');
        }
        $uid=$origin['uid']??'';
        return OriginContext::within($uid,$origin,$action==='snapshot'?'forms_results_metadata':$action,$input,function()use($uid,$origin,$action,$input):array {
            if (!$this->capabilities()['qualified']) {throw new \RuntimeException('Forms runtime changed');}
            $id=(int)($input['id']??0);
            if ((int)($origin['references']['form_id']??0)!==$id) {throw new \RuntimeException('Original form does not match');}
            $before=$this->fence->revision();$scope=new ScopedServices($uid,true,true);$forms=$scope->forms();$form=$forms->getFormIfAllowed($id,'results');
            if (!in_array('results',$forms->getPermissions($form),true) || (isset($origin['references']['owner_id']) && $origin['references']['owner_id']!==$form->getOwnerId())) {throw new \RuntimeException('Original results authority changed');}
            if ($action==='forms_list_response_files') {
                ReadPages::submission($id,(int)($input['submission_id']??0));
                $result=ReadPages::bounded(ReadPages::read($uid,$action,$input));
            } else {$result=$action==='forms_results_page'?$this->resultsPage($id,$input):$this->resultsMetadata($uid,$id,$scope);}
            OriginContext::assertFresh();
            $after=$this->fence->revision();if($before!==$after){throw new \RuntimeException('Results changed during deferred read');}
            return $result+['revision'=>$after];
        });
    }
    public function linkedPending(int $limit): array {return Server::get(LinkedMarkers::class)->pending($limit);}
    /** Initialize the approved original actor's first Files root in its isolated CLI process. */
    public function initializeLinkedFiles(array $origin): void {
        if (PHP_SAPI!=='cli' || Server::get(\OCP\IUserSession::class)->getUser()!==null) {
            throw new \RuntimeException('Deferred linked Forms requires an isolated null-session CLI process');
        }
        $association=$this->linkedAssociation($origin,(int)($origin['references']['form_id']??0));
        if (($association['state']??null)!=='active') {throw new \RuntimeException('Original linked Forms association is unavailable');}
        DeferredFileContext::initialize($association['original_owner_uid']);
    }
    public function linkedClaim(string $associationId): ?array {return Server::get(LinkedMarkers::class)->claim($associationId);}
    public function linkedCheckpoint(array $claim,array $cycle): array {return Server::get(LinkedMarkers::class)->checkpoint($claim,$cycle);}
    public function linkedRelease(array $claim): void {Server::get(LinkedMarkers::class)->release($claim);}
    public function linkedPause(array $claim,string $reason): void {Server::get(LinkedMarkers::class)->pause($claim,$reason);}
    public function linkedRestart(array $claim,array $cycle,callable $readOnlyEvidence): bool {return Server::get(LinkedMarkers::class)->restart($claim,$cycle,$readOnlyEvidence);}
    public function linkedRead(array $claim,string $action,array $input): array {return Server::get(LinkedMarkers::class)->read($claim,$action,$input);}
    public function linkedAssociation(array $origin,int $formId): array {return Server::get(LinkedMarkers::class)->association($origin,$formId);}
    public function linkedStatus(string $uid,int $formId): array {
        $this->actor($uid);
        return Server::get(LinkedMarkers::class)->status($uid,$formId);
    }
    public function syncLinkedFile(array $origin,string $associationId,int $expectedVersion,string $sourceRevision,string $operationKey,array $privateDestination,callable $write): array {
        return Server::get(LinkedMarkers::class)->sync($origin,$associationId,$expectedVersion,$sourceRevision,$operationKey,$privateDestination,$write);
    }
    public function deferredFileBoundary(array $origin,\OCP\Files\Node $node,?string $child,callable $authorize,callable $write): mixed {
        $uid=$origin['uid']??'';
        DeferredFileContext::assert($uid);
        if ($child!==null || !$node instanceof \OCP\Files\File) {throw new \RuntimeException('Deferred linked Forms requires an existing file');}
        return $this->boundary($uid,$origin,$node,null,\Closure::fromCallable($authorize),\Closure::fromCallable($write),true);
    }
    /** Trusted callback for one exact allocation or content write. No additional Forms transaction is opened. */
    public function fileBoundary(string $uid, array $origin, \OCP\Files\Node $node, ?string $childName, \Closure $authorize, \Closure $work): mixed {
        $this->actor($uid);
        return $this->boundary($uid,$origin,$node,$childName,$authorize,$work,false);
    }
    private function boundary(string $uid,array $origin,\OCP\Files\Node $node,?string $childName,\Closure $authorize,\Closure $work,bool $deferred): mixed {
        if (!in_array($origin['tool'] ?? '', ['forms_save_response_file', 'forms_export_results', 'forms_link_results_file'], true)) {
            throw new \RuntimeException('Unsupported Forms file origin');
        }
        $formId = (int)($origin['references']['form_id'] ?? 0);
        try {
            return OriginContext::within($uid, $origin, $origin['tool'], ['id' => $formId], function () use ($uid, $origin, $node, $childName, $authorize, $work, $formId,$deferred): mixed {
                if ($childName !== null) {
                    if (!$node instanceof \OCP\Files\Folder || $childName === '' || str_contains($childName, '/') || str_contains($childName, '\\')) {
                        throw new \InvalidArgumentException('Invalid Forms destination');
                    }
                    Server::get(\OCP\Files\IFilenameValidator::class)->validateFilename($childName);
                    $path = rtrim($node->getPath(), '/') . '/' . $childName;
                    $expectedId = null;
                    $expectedEtag = null;
                } else {
                    if (!$node instanceof \OCP\Files\File) {
                        throw new \InvalidArgumentException('Forms destination must be a file');
                    }
                    $path = $node->getPath();
                    $expectedId = (int)$node->getId();
                    $expectedEtag = (string)$node->getEtag();
                }
                \OCA\Forms\Mcp\Service\StorageAdmission::node($node);
                \OCA\Forms\Mcp\Service\StorageAdmission::path($path);
                $mount = Server::get(\OCP\Files\Mount\IMountManager::class)->find($path);
                if ($mount === null) {
                    throw new \RuntimeException('Forms destination mount is unavailable');
                }
                $target = $mount->getInternalPath($path);
                $check = function (string $stage='preflight') use ($uid, $origin, $formId, $authorize, $path, $mount,$deferred,$node): void {
                    OriginContext::assertFresh();
                    if ($deferred) {
                        DeferredFileContext::assert($uid);
                        Server::get(LinkedMarkers::class)->assertWriting($origin,$node);
                    } else {$this->actor($uid);}
                    if (Server::get(\OCP\Files\Mount\IMountManager::class)->find($path) !== $mount) {
                        throw new \RuntimeException('Forms destination mount changed');
                    }
                    $forms = (new ScopedServices($uid,$deferred))->forms();
                    $form = $forms->getFormIfAllowed($formId, 'results');
                    if (!in_array('results', $forms->getPermissions($form), true)
                        || (isset($origin['references']['owner_id']) && $origin['references']['owner_id'] !== $form->getOwnerId())) {
                        throw new \RuntimeException('Original Forms results authority changed');
                    }
                    if (isset($origin['references']['submission_id'])) {
                        $submission = Server::get(SubmissionMapper::class)->findById((int)$origin['references']['submission_id']);
                        if ($submission->getFormId() !== $formId) {
                            throw new \RuntimeException('Original Forms response changed');
                        }
                    }
                    $authorize($stage);
                };
                $check();
                $wrapper = null;
                $mount->wrapStorage(function ($mountPath, $storage) use ($target, $expectedId, $expectedEtag, $check, &$wrapper) {
                    $wrapper = new \OCA\Forms\Mcp\Service\MutationBoundaryStorage($storage, $target, $expectedId, $expectedEtag, $check);
                    return $wrapper;
                });
            try {
                return $work();
            } finally {
                if ($wrapper !== null) {
                    $wrapper->enabled = false;
                }
            }
        });
        } catch (BeforeEffect $error) {
            // This sink can follow an already-created empty file or recovery copy.
            throw new \RuntimeException('Forms file authority is unavailable', 0, $error);
        }
    }

    private function resultsMetadata(string $uid,int $id,?ScopedServices $scope=null,?\OCA\Forms\Db\Form $form=null,?array $questions=null): array {
        $scope ??= new ScopedServices($uid,false,true);
        $form ??= Server::get(FormMapper::class)->findById($id);
        $result=['form'=>['id'=>$id,'title'=>$form->getTitle(),'isAnonymous'=>(bool)$form->getIsAnonymous(),'ownerId'=>$form->getOwnerId()],
            'questions'=>$questions??$scope->forms()->getQuestions($id),'submissionCount'=>$scope->get(SubmissionMapper::class)->countSubmissions($id),
            'timeZone'=>Server::get(\OCP\IConfig::class)->getUserValue($uid,'core','timezone','UTC')];
        $result['export_revision']=ResultSourceRevision::token($id,$result);
        // Include the private source envelope in the same metadata byte budget.
        if (strlen(json_encode($result,JSON_THROW_ON_ERROR))>1048576-64) {throw new \RuntimeException('Forms result metadata exceeds projection budget');}
        return $result;
    }
    private function resultsPage(int $id,array $input): array {
        $limit=$input['limit']??50;$after=$input['after_id']??0;
        if (!is_int($limit) || $limit<1 || $limit>100 || !is_int($after) || $after<0) {throw new \InvalidArgumentException('Invalid Forms result page bounds');}
        $qb=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
        $qb->select('id')->from('forms_v2_submissions')->where($qb->expr()->eq('form_id',$qb->createNamedParameter($id,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('id',$qb->createNamedParameter($after,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults($limit+1);
        $rows=$qb->executeQuery()->fetchAll();$more=count($rows)>$limit;$submissions=[];$next=$after;
        foreach (array_slice($rows,0,$limit) as $row) {
            $next=(int)$row['id'];$submissions[]=Server::get(\OCA\Forms\Service\SubmissionService::class)->getSubmission($next);
            if (strlen(json_encode($submissions,JSON_THROW_ON_ERROR))>1048576-1024) {throw new \RuntimeException('Forms result page exceeds projection budget');}
        }
        return ['submissions'=>$submissions,'hasMore'=>$more,'nextId'=>$next,'isAnonymous'=>(bool)Server::get(FormMapper::class)->findById($id)->getIsAnonymous()];
    }
}
