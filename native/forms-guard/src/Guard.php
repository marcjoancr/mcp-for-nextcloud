<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp;
use OCA\Forms\Mcp\Service\{FormsRevisionFence, ApprovedFormsOperation, NativeActions, FileRecovery, AnswerFiles, OriginContext, ScopedServices};
use OCA\Forms\Service\{FormsService, ConfigService};
use OCA\Forms\Db\{FormMapper, QuestionMapper, SubmissionMapper};
use OCP\Server;
final class Guard {
    public function __construct(private FormsRevisionFence $fence, private ApprovedFormsOperation $operations) {}
    public function capabilities(): array {
        $identity = SourceIdentity::verify();
        $provider = Server::get(\OCP\IDBConnection::class)->getDatabaseProvider(true);
        $core=Server::get(\OCP\ServerVersion::class)->getVersion();
        $databaseVersion=in_array($provider,['mariadb','postgres'],true) ? (string)Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT VERSION()')->fetchOne() : '';
        $runtimeQualified=PHP_INT_SIZE===8 && array_slice($core,0,3)===[35,0,2]
            && (($provider==='postgres' && str_starts_with($databaseVersion,'PostgreSQL 16.15 '))
                || ($provider==='mariadb' && str_starts_with($databaseVersion,'10.11.19-MariaDB')));
        $config=Server::get(\OCP\IConfig::class);
        $fileLocks=$config->getSystemValue('filelocking.enabled',true)===true && $config->getSystemValue('memcache.locking','')==='' && $config->getSystemValue('filelocking.excluded',[])===[] && $config->getSystemValue('filelocking.debug',false)===false;
        $qualified = $identity && $runtimeQualified && $fileLocks;
        return ['protocol'=>1,'qualified'=>$qualified,'version'=>'5.4.2','source'=>'066573fd592513a06f8b8624def8244e911efdfd',
            'database'=>$provider,'actions'=>$qualified ? NativeActions::WRITES : [], 'files_recovery'=>true,'files_shares'=>$qualified && $provider==='mariadb','event_delivery'=>'independently_reported'];
    }
    private function actor(string $uid): void {
        $user=Server::get(\OCP\IUserSession::class)->getUser();
        if (!$user || $user->getUID()!==$uid || !$user->isEnabled()) { throw new \RuntimeException('Forms actor is unavailable'); }
        if (!$this->capabilities()['qualified']) { throw new \RuntimeException('Forms native guard is not qualified'); }
    }
    private function authorize(string $uid,string $action,array $i): void {
        $this->actor($uid);
        if (strlen(json_encode($i,JSON_THROW_ON_ERROR))>1048576) {throw new \InvalidArgumentException('Forms input exceeds limit');}
        if (str_contains($action,'admin_settings')) {
            if (!Server::get(\OCP\IGroupManager::class)->isAdmin($uid)) {throw new \RuntimeException('Administrator required');}
            return;
        }
        if ($action==='forms_search') {return;}
        if ($action==='forms_restore') { FileRecovery::snapshot($uid,$action,$i); return; }
        if ($action==='forms_create') {
            if (!Server::get(ConfigService::class)->canCreateForms()) {throw new \RuntimeException('Form creation is not allowed');}
            return;
        }
        if ($action==='forms_generate_link_token') {
            if (!Server::get(ConfigService::class)->getAllowPublicLink()) {throw new \RuntimeException('Public links are disabled');}
            return;
        }
        $id=(int)($i['id']??0);
        $forms=Server::get(FormsService::class);
        if (in_array($action,['forms_submit_response','forms_upload_response_files'],true)) {$forms->loadFormForSubmission($id,$i['share_hash']??''); return;}
        $permission=match($action) {
            'forms_delete_form','forms_transfer','forms_list_shares','forms_read_links','forms_get_embed','forms_share','forms_update_share','forms_unshare'=>'all',
            'forms_read_response','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page'=>'results',
            'forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses'=>'results_delete',
            'forms_read_settings','forms_read_question','forms_read_own_responses','forms_clone'=>'submit',
            default=>'edit',
        };
        $form=$forms->getFormIfAllowed($id,$permission);
        if ($action==='forms_update') {
            $allowed=['title','description','expires','isAnonymous','submitMultiple','allowEditSubmissions','showExpiration','submissionMessage','maxSubmissions','confirmationEmailEnabled','confirmationEmailSubject','confirmationEmailBody','confirmationEmailQuestionId','allowComments','access'];
            if (!isset($i['fields']) || !is_array($i['fields']) || $i['fields']===[] || array_diff(array_keys($i['fields']),$allowed)!==[]) {throw new \InvalidArgumentException('Unsupported generic Forms fields');}
        }
        if (in_array($action,['forms_read_response','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page','forms_update_response','forms_delete_response','forms_delete_responses'],true)) {
            $required=in_array($action,['forms_read_response','forms_list_response_files','forms_save_response_file','forms_results_metadata','forms_results_page'],true) ? 'results' : 'results_delete';
            if (!in_array($required,$forms->getPermissions($form),true)) {throw new \RuntimeException('Explicit results permission required');}
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
        if (isset($input['id'])) {
            $entity=Server::get(FormMapper::class)->findById((int)$input['id']);
            $form=$entity->read();
            unset($form['hash']);
            $form['permissions']=Server::get(FormsService::class)->getPermissions($entity);
            $form['questions']=Server::get(FormsService::class)->getQuestions((int)$input['id']);
        }
        $config=Server::get(ConfigService::class)->getAppConfig();
        if (strlen(json_encode([$form,$config],JSON_THROW_ON_ERROR))>1048576) {throw new \RuntimeException('Forms preview exceeds projection budget');}
        $fileManifest=FileRecovery::snapshot($uid,$action,$input);
        $after=$this->fence->revision();
        if ($before!==$after) {throw new \RuntimeException('Forms changed during preview');}
        return ['file_manifest'=>$fileManifest,'revision'=>$after,'form'=>$form,'config'=>$config,'source'=>'066573fd592513a06f8b8624def8244e911efdfd','summary'=>['form_id'=>$input['id']??null,'action'=>$action],
            'impact'=>['database'=>true,'files'=>'requires_separate_qualification','events'=>in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_share'],true)]];
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
            function () use($uid,$action,$input): void {
                try {$this->authorize($uid,$action,$input);} catch (\Throwable $e) {throw new BeforeEffect($e->getMessage(),0,$e);}
            },
            function () use($uid,$action,$input,$fileManifest,$transfer,$operationKey): array {
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
        $forms=Server::get(FormsService::class); $id=(int)($i['id']??0);
        return match($action) {
            'forms_results_metadata'=>$this->resultsMetadata($uid,$id),
            'forms_results_page'=>$this->resultsPage($id,$i),
            'forms_list_response_files'=>['files'=>AnswerFiles::references($id,(int)$i['submission_id'])],
            'forms_read_settings'=>$forms->getForm(Server::get(FormMapper::class)->findById($id)),
            'forms_read_question'=>Server::get(\OCA\Forms\Controller\ApiController::class)->getQuestion($id,$i['question_id'])->getData(),
            'forms_list_shares','forms_read_links','forms_get_embed'=>$forms->getShares($id),
            'forms_read_response'=>Server::get(\OCA\Forms\Controller\ApiController::class)->getSubmission($id,$i['submission_id'])->getData()+['isAnonymous'=>Server::get(FormMapper::class)->findById($id)->getIsAnonymous()],
            'forms_read_own_responses'=>['isAnonymous'=>Server::get(FormMapper::class)->findById($id)->getIsAnonymous(),'submissions'=>Server::get(\OCA\Forms\Service\SubmissionService::class)->getSubmissions($id,$uid,$i['query']??null,min(100,$i['limit']??50),$i['offset']??0)],
            'forms_generate_link_token'=>Server::get(\OCA\Forms\Controller\ShareApiController::class)->generateToken()->getData(),
            'forms_read_admin_settings'=>Server::get(ConfigService::class)->getAppConfig(),
            default=>throw new \InvalidArgumentException('Unsupported Forms native read'),
        };
    }
    /** Workers use verified original authority and a fresh native service graph, never the cron session. */
    public function deferredRead(array $origin,string $action,array $input): array {
        if (!in_array($action,['snapshot','forms_results_metadata','forms_results_page'],true)
            || !in_array($origin['tool']??'',['forms_summarize_results','forms_download_results','forms_export_results','forms_link_results_file'],true)) {
            throw new \RuntimeException('Unsupported deferred Forms read');
        }
        $uid=$origin['uid']??'';
        return OriginContext::within($uid,$origin,$action==='snapshot'?'forms_results_metadata':$action,$input,function()use($uid,$origin,$action,$input):array {
            if (!$this->capabilities()['qualified']) {throw new \RuntimeException('Forms runtime changed');}
            $id=(int)($input['id']??0);
            if ((int)($origin['references']['form_id']??0)!==$id) {throw new \RuntimeException('Original form does not match');}
            $before=$this->fence->revision();$scope=new ScopedServices($uid);$forms=$scope->forms();$form=$forms->getFormIfAllowed($id,'results');
            if (!in_array('results',$forms->getPermissions($form),true) || (isset($origin['references']['owner_id']) && $origin['references']['owner_id']!==$form->getOwnerId())) {throw new \RuntimeException('Original results authority changed');}
            $result=$action==='forms_results_page'?$this->resultsPage($id,$input):$this->resultsMetadata($uid,$id);
            $after=$this->fence->revision();if($before!==$after){throw new \RuntimeException('Results changed during deferred read');}
            return $result+['revision'=>$after];
        });
    }
    private function resultsMetadata(string $uid,int $id): array {
        $form=Server::get(FormMapper::class)->findById($id);
        $result=['form'=>['id'=>$id,'title'=>$form->getTitle(),'isAnonymous'=>(bool)$form->getIsAnonymous(),'ownerId'=>$form->getOwnerId()],
            'questions'=>Server::get(FormsService::class)->getQuestions($id),'submissionCount'=>Server::get(SubmissionMapper::class)->countSubmissions($id),
            'timeZone'=>Server::get(\OCP\IConfig::class)->getUserValue($uid,'core','timezone','UTC')];
        if (strlen(json_encode($result,JSON_THROW_ON_ERROR))>1048576) {throw new \RuntimeException('Forms result metadata exceeds projection budget');}
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
