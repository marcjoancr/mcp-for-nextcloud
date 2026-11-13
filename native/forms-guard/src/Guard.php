<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp;
use OCA\Forms\Mcp\Service\{FormsRevisionFence, ApprovedFormsOperation, NativeActions};
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
        $qualified = $identity && $runtimeQualified;
        return ['protocol'=>1,'qualified'=>$qualified,'version'=>'5.4.2','source'=>'066573fd592513a06f8b8624def8244e911efdfd',
            'database'=>$provider,'actions'=>$qualified ? NativeActions::WRITES : [], 'files_recovery'=>false,'event_delivery'=>'independently_reported'];
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
        if ($action==='forms_submit_response') {$forms->loadFormForSubmission($id,$i['share_hash']??''); return;}
        $permission=match($action) {
            'forms_delete_form','forms_transfer','forms_list_shares','forms_read_links','forms_get_embed','forms_share','forms_update_share','forms_unshare'=>'all',
            'forms_read_response'=>'results',
            'forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses'=>'results_delete',
            'forms_read_settings','forms_read_question','forms_read_own_responses','forms_clone'=>'submit',
            default=>'edit',
        };
        $form=$forms->getFormIfAllowed($id,$permission);
        if (in_array($action,['forms_read_response','forms_update_response','forms_delete_response','forms_delete_responses'],true)) {
            $required=$action==='forms_read_response' ? 'results' : 'results_delete';
            if (!in_array($required,$forms->getPermissions($form),true)) {throw new \RuntimeException('Explicit results permission required');}
        }
        if (($action==='forms_set_lock' && ($i['permanent']??false)) || ($action==='forms_release_lock' && $form->getLockedUntil()===0)) {
            if ($form->getOwnerId()!==$uid) {throw new \RuntimeException('Only the owner can change a permanent lock');}
        }
        if (str_contains($action,'_own_response') && isset($i['submission_id'])) {
            $submission=Server::get(SubmissionMapper::class)->findById($i['submission_id']);
            if ($submission->getFormId()!==$id || $submission->getUserId()!==$uid) {throw new \RuntimeException('Own response is unavailable');}
        }
    }
    public function snapshot(string $uid,string $action,array $input): array {
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
        $after=$this->fence->revision();
        if ($before!==$after) {throw new \RuntimeException('Forms changed during preview');}
        return ['revision'=>$after,'form'=>$form,'config'=>$config,'source'=>'066573fd592513a06f8b8624def8244e911efdfd','summary'=>['form_id'=>$input['id']??null,'action'=>$action],
            'impact'=>['database'=>true,'files'=>'requires_separate_qualification','events'=>in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_share'],true)]];
    }
    public function lookup(string $uid,string $operationKey): ?array {
        $this->actor($uid);
        return $this->operations->lookup($uid,$operationKey);
    }
    public function apply(string $uid,string $action,array $input,string $revision,string $operationKey): array {
        return $this->operations->apply($uid,$action,$input,$revision,$operationKey,
            function () use($uid,$action,$input): void {
                try {$this->authorize($uid,$action,$input);} catch (\Throwable $e) {throw new BeforeEffect($e->getMessage(),0,$e);}
            },
            function () use($action,$input): array {
                try {$this->fileBoundary($action,$input);} catch (\Throwable $e) {throw new BeforeEffect($e->getMessage(),0,$e);}
                return NativeActions::invoke($action,$input);
            });
    }
    private function fileBoundary(string $action,array $i): void {
        // Fail closed until a recovery manifest is verified. Native UI routes remain unaffected.
        if (isset($i['fields']['path']) || in_array($action,['forms_transfer'],true)) {throw new \RuntimeException('Forms file recovery is not qualified');}
        if (!isset($i['id'])) {return;}
        $form=Server::get(FormMapper::class)->findById((int)$i['id']);
        $folder=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormsFolder($form->getOwnerId());
        if ($folder!==null && in_array($action,['forms_delete_form','forms_delete_question','forms_delete_response','forms_delete_own_response','forms_delete_responses','forms_update','forms_update_share','forms_unshare'],true)) {
            foreach ($folder->getDirectoryListing() as $node) {
                if (str_starts_with($node->getName(),$form->getId().' - ')) {throw new \RuntimeException('Forms folder effects require recovery qualification');}
            }
        }
        if (Server::get(\OCA\Forms\Db\UploadedFileMapper::class)->findByFormId($form->getId())!==[] && $action==='forms_delete_form') {
            throw new \RuntimeException('Forms temporary uploaded-file recovery is required');
        }
        if ($form->getFileId() && in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses'],true)) {
            throw new \RuntimeException('Linked export authority is not qualified');
        }
        $fileQuestions=array_filter(Server::get(QuestionMapper::class)->findByForm((int)$i['id'],true),static fn($q)=>$q->getType()==='file');
        if ($fileQuestions!==[] && in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses','forms_delete_question','forms_delete_form','forms_update'],true)) {
            throw new \RuntimeException('Forms uploaded-file recovery is required');
        }
    }
    public function read(string $uid,string $action,array $i): array {
        $this->authorize($uid,$action,$i);
        $forms=Server::get(FormsService::class); $id=(int)($i['id']??0);
        return match($action) {
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
}
