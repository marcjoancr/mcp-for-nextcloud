<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\{IDBConnection, Server};
use OCA\Forms\Db\{Form, Share, FormMapper, ShareMapper};
use OCP\Files\{Folder, IRootFolder};
use OCP\Lock\ILockingProvider;
/** One-shot Files-share intents, drained outside the Forms transaction under its session fence. */
final class ManagedSharePhase
{
    private array $pending = [];
    private ?array $authoringStep = null;
    private array $authoringCallbacks = [];
    private ?string $operation = null;
    private bool $draining = false;
    private array $completed = [];
    private static ?array $nativeActor = null;
    /** Ephemeral producer token and assertion; never serialized into an intent. */
    private static ?array $circleReadFrame = null;
    public function __construct(private IDBConnection $db)
    {
    }
    public function operation(?string $key): void
    {
        $this->operation = $key;
    }
    /** Attach only the exact expected intent in this business frame; independent nested origins do not inherit it. */
    public function authoringStep(callable $work): mixed
    {
        if($this->authoringStep!==null){throw new \LogicException('Nested authoring share step is unavailable');}
        $context=OriginContext::current();$authority=OriginContext::authoringStepAuthority();
        $this->authoringStep=$authority+['form_id'=>(int)$context['input']['id'],'native_share_id'=>$context['input']['share_id']??null,'create'=>$context['file_impact']['share_impact']['create']??false];
        try{return $work();}finally{$this->authoringStep=null;}
    }
    public static function nativeRun(callable $work): mixed
    {
        if (!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['qualified']) {
            throw new \RuntimeException('Native Forms sharing installation is incomplete');
        }
        $previousActor = self::$nativeActor;
        $previousCircleFrame = self::$circleReadFrame;
        try {
            if (OriginContext::active()) {
                $uid=OriginContext::current()['verified']['uid'];
                $assert=OriginContext::circleReadAuthority($uid);
            } else {
                self::$nativeActor = NativeShareRequest::capture();
                $native=self::$nativeActor;$uid=$native['uid'];
                $assert=static function()use($native):void {
                    if(OriginContext::active()){throw new \RuntimeException('Native Circle producer cannot inherit another origin');}
                    NativeShareRequest::assertCurrent($native);
                };
            }
            self::$circleReadFrame=['token'=>new \stdClass(),'uid'=>$uid,'assert'=>$assert];
            return FormsRevisionFence::run(function () use ($work) {
                if (!OriginContext::active()) {
                    NativeShareRequest::assertCurrent(self::$nativeActor ?? []);
                }
                return $work();
            });
        } finally {
            self::$nativeActor = $previousActor;
            self::$circleReadFrame = $previousCircleFrame;
        }
    }
    /** Null outside the actual managed-share producer. Ordinary global/anonymous metadata stays native. */
    public static function circleReadActor(): ?string {
        if(self::$circleReadFrame===null){return null;}
        (self::$circleReadFrame['assert'])();
        return self::$circleReadFrame['uid'];
    }
    public static function circleReadAuthority(string $uid): \Closure {
        $frame=self::$circleReadFrame;
        if($frame===null||$frame['uid']!==$uid){throw new \RuntimeException('Native Circle producer frame is unavailable');}
        return static function()use($frame):void {
            if(self::$circleReadFrame===null||self::$circleReadFrame['token']!==$frame['token']){throw new \RuntimeException('Native Circle producer frame ended');}
            ($frame['assert'])();
        };
    }
    public static function schedule(Form $form, Share $share, bool $create, bool $deletedForm = false): void
    {
        if (!in_array($share->getShareType(), [0, 1, 2, 7], true)) {
            return;
        }
        SharingIdentity::assertType($share->getShareType());
        $self = Server::get(self::class);
        if (!Server::get(FormsRevisionFence::class)->active()) {
            throw new \LogicException('Share intents require the Forms transaction');
        }
        $managed = ManagedShares::existingRows($form->getId(), $share->getId());
        if (!$create && $managed === []) {
            return;
        }
        $context = OriginContext::active() ? OriginContext::current() : null;
        $nativeRequest = self::$nativeActor;
        if ($context === null && $nativeRequest === null) {
            $nativeRequest = NativeShareRequest::capture();
        }
        $key = bin2hex(random_bytes(32));
        $envelope = ['form_id' => $form->getId(), 'owner_id' => $form->getOwnerId(), 'native_share_id' => $share->getId(), 'type' => $share->getShareType(), 'recipient' => $share->getShareWith(), 'create' => $create, 'context' => $context, 'native' => $nativeRequest, 'managed' => $managed, 'deleted_form' => $deletedForm, 'operation_key' => $self->operation, 'intent_key' => $key];
        $step=$self->authoringStep;$sameStep=$step!==null&&($step['matches'])()&&$step['form_id']===$form->getId()&&$step['native_share_id']===$share->getId()&&$step['create']===$create;
        if($sameStep){$envelope['step_callback_required']=true;}
        EffectEnvelope::put($key, $envelope);
        $self->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_share_intents (intent_key,operation_key,form_id,native_share_id,phase) VALUES (?,?,?,?,?)', [$key, $self->operation ?? '', $form->getId(), $share->getId(), 'pending']);
        if($sameStep){$self->authoringCallbacks[$key]=$step['assert'];}
        $self->pending[] = $key;
    }
    public static function removeForDeletedForm(Form $form): void
    {
        foreach (ManagedShares::removalShares($form->getId()) as $share) {
            self::schedule($form, $share, false, true);
        }
    }
    public function completeImpact(string $operation, array $ids): void
    {
        $this->completed[$operation] = $ids;
    }
    public function hasPending(int $formId, int $shareId): bool
    {
        return $this->db->executeQuery('SELECT intent_key FROM *PREFIX*forms_mcp_share_intents WHERE form_id = ? AND native_share_id = ? AND phase = ?', [$formId, $shareId, 'pending'])->fetchOne() !== false;
    }
    public function phase(string $operation): ?string
    {
        $rows = $this->db->executeQuery('SELECT phase FROM *PREFIX*forms_mcp_share_intents WHERE operation_key = ?', [$operation])->fetchAll();
        if ($rows === []) {
            return null;
        }
        foreach ($rows as $row) {
            if ($row['phase'] !== 'verified') {
                return 'native_files_shares_' . $row['phase'];
            }
        }
        return 'native_files_shares_verified';
    }
    /** Only the current provider write is an effect; the complete approved graph remains in the envelope. */
    private function currentImpact(array $envelope, ?Folder $target): array
    {
        $impact = $envelope['context']['file_impact'];
        if (($impact['authoring_effects'] ?? false) !== true) {
            return $impact;
        }
        $impact['files'] = $target === null ? [] : ManagedShares::currentFolderFiles((int)$envelope['form_id'], $target, $impact['files']);
        $impact['share_impact']['folder_ids'] = $target === null ? [] : [(int)$target->getId()];
        $impact['share_impact']['managed_shares'] = $target === null ? [] : array_values(array_filter($impact['share_impact']['managed_shares'] ?? [], static fn(array $row): bool => (int)$row['folder_id'] === (int)$target->getId()));
        return $impact;
    }
    private function assertNative(array $envelope, ?Folder $target = null): Form
    {
        Server::get(FormsRevisionFence::class)->assertSession();
        $impact = $envelope['context'] === null ? [] : $this->currentImpact($envelope, $target);
        if($envelope['step_callback_required']??false){
            $check=$this->authoringCallbacks[$envelope['intent_key']]??throw new \RuntimeException('Immediate authoring authority is unavailable; effect cannot replay');
            if($target!==null){$impact['share_impact']['folder_ids']=array_values(array_unique(array_merge($impact['share_impact']['folder_ids']??[],[(int)$target->getId()])));}
            $check($envelope['context']['file_action'],$impact);
        }
        if (!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['qualified']) {
            throw new \RuntimeException('Forms sharing runtime changed');
        }
        $uid = $envelope['context']['verified']['uid'] ?? $envelope['native']['uid'];
        if (Server::get(\OCP\IUserSession::class)->getUser()?->getUID() !== $uid) {
            throw new \RuntimeException('Original native sharing session changed');
        }
        if ($envelope['context'] !== null) {
            OriginContext::withFileImpact($envelope['context']['file_action'], $impact, fn() => OriginContext::assertFileEffect($target === null ? [] : [(int) $target->getId()]));
        } else {
            NativeShareRequest::assertCurrent($envelope['native']);
        }
        $core = new FreshCore($uid);
        if (!$core->apps()->isEnabledForUser('forms', $core->user())) {
            throw new \RuntimeException('Native Forms access changed');
        }
        try {
            $form = Server::get(FormMapper::class)->findById((int) $envelope['form_id']);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $error) {
            if (!$envelope['deleted_form'] || $envelope['create'] || $envelope['owner_id'] !== $uid) {
                throw $error;
            }
            if ($envelope['context'] !== null && $envelope['context']['action'] !== 'forms_delete_form') {
                throw $error;
            }
            $form = new Form();
            $form->setId((int) $envelope['form_id']);
            $form->setOwnerId($uid);
            $form->setState(0);
        }
        if ($form->getOwnerId() !== $uid || $form->getOwnerId() !== $envelope['owner_id'] || $form->getState() === 2) {
            throw new \RuntimeException('Native sharing owner authority changed');
        }
        try {
            $share = Server::get(ShareMapper::class)->findById((int) $envelope['native_share_id']);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $error) {
            if ($envelope['create']) {
                throw $error;
            }
            return $form;
        }
        if ($share->getFormId() !== $form->getId() || $share->getShareType() !== $envelope['type'] || $share->getShareWith() !== $envelope['recipient'] || in_array('results', $share->getPermissions(), true) !== $envelope['create']) {
            throw new \RuntimeException('Native share intent changed');
        }
        return $form;
    }
    private function shareManager(array $envelope, Folder $folder, ?array $expectedRow = null): \OCP\Share\IManager
    {
        return ScopedShareManager::create($envelope['owner_id'], function (\OCP\Share\IShare $share, bool $create) use ($envelope, $folder, $expectedRow): void {
            $this->assertNative($envelope, $folder);
            StorageAdmission::node($folder);
            if ($create && ($envelope['context']['file_impact']['authoring_effects'] ?? false)) {
                $impact = $this->currentImpact($envelope, $folder);
                FileRecovery::verifyShareFiles($impact['files'], Server::get(IRootFolder::class)->getUserFolder($envelope['owner_id']));
            }
            if ($create !== $envelope['create'] || $share->getShareOwner() !== $envelope['owner_id'] || ($create && $share->getSharedBy() !== $envelope['owner_id']) || $share->getSharedWith() !== $envelope['recipient'] || $share->getShareType() !== $envelope['type'] || $share->getNodeId() !== $folder->getId() || $share->getPermissions() !== 1) {
                throw new \RuntimeException('Approved native Files share tuple changed before persistence');
            }
            if (!$create) {
                ManagedShares::assertIdentity($share, $envelope, $expectedRow ?? []);
            }
        },$envelope['intent_key']);
    }
    private function execute(array $envelope): void
    {
        SharingIdentity::assertType($envelope['type']);
        $form = $this->assertNative($envelope);
        $root = Server::get(IRootFolder::class)->getUserFolder($form->getOwnerId());
        $manager = SharingIdentity::manager();
        if (!$envelope['create']) {
            foreach ($envelope['managed'] as $row) {
                try {
                    $share = $manager->getShareById($row['files_share_id'], onlyValid: false);
                } catch (\OCP\Share\Exceptions\ShareNotFound $error) {
                    if (!$envelope['deleted_form'] || !ManagedShares::isAbsent($row['files_share_id'])) {
                        throw $error;
                    }
                    $this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?', [$row['files_share_id']]);
                    continue;
                }
                $folder = $root->getById((int) $row['folder_id'])[0] ?? null;
                if (!$folder instanceof Folder) {
                    throw new \RuntimeException('Managed share folder is unavailable');
                }
                StorageAdmission::node($folder);
                $folder->lock(ILockingProvider::LOCK_EXCLUSIVE);
                try {
                    $this->assertNative($envelope, $folder);
                    $manager = $this->shareManager($envelope, $folder, $row);
                    $share = $manager->getShareById($row['files_share_id'], onlyValid: false);
                    ManagedShares::assertIdentity($share, $envelope, $row);
                    $event = $this->beginEvents($envelope, 'remove:' . $row['files_share_id']);
                    $manager->deleteShare($share);
                    Server::get(FormsRevisionFence::class)->assertSession();
                    $this->finishEvents($event);
                    if (!ManagedShares::isAbsent($row['files_share_id'])) {
                        throw new \RuntimeException('Files share removal was not verified');
                    }
                    $this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?', [$row['files_share_id']]);
                } finally {
                    $folder->unlock(ILockingProvider::LOCK_EXCLUSIVE);
                }
            }
            return;
        }
        $path = Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        StorageAdmission::userPath($form->getOwnerId(), $path, true);
        $this->assertNative($envelope);
        if ($root->nodeExists($path)) {
            $folder = $root->get($path);
        } else {
            $folder = NewFileStorage::folder($root, $path, fn() => $this->assertNative($envelope), true);
            ManagedShares::registerFolder($form, $folder);
        }
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('Native share target is unavailable');
        }
        $folders=[(int)$folder->getId()=>$folder];
        $impact=$envelope['context']['file_impact']??[];
        if(($impact['authoring_effects']??false)===true){
            foreach($impact['share_impact']['folder_ids']??[] as $folderId){
                $approved=$root->getById((int)$folderId)[0]??null;
                if(!$approved instanceof Folder){throw new \RuntimeException('Approved retained upload folder is unavailable');}
                $folders[(int)$folderId]=$approved;
            }
        }
        foreach($folders as $folder){
        StorageAdmission::node($folder);
        $folder->lock(ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->assertNative($envelope, $folder);
            $manager = $this->shareManager($envelope, $folder);
            ManagedShares::assertCurrentFolder($form, $folder);
            if ($envelope['context'] !== null) {
                FileRecovery::verifyShareFiles($this->currentImpact($envelope, $folder)['files'] ?? [], $root);
            }
            $known = ManagedShares::rows($form->getId(), (int) $envelope['native_share_id']);
            $existingId = null;
            if($envelope['type']===7){
                $existingShares=[];
                foreach(CircleRuntime::managedParents($form->getOwnerId(),$folder,1000) as $parent){
                    if(($parent['circle_id']??null)!==$envelope['recipient']){continue;}
                    if(!is_string($parent['id']??null)||!preg_match('/^[1-9][0-9]*$/D',$parent['id'])){throw new \RuntimeException('Managed Circle parent ID is malformed');}
                    $fullId='ocCircleShare:'.$parent['id'];
                    $existing=$manager->getShareById($fullId,onlyValid:false);
                    if(!$existing instanceof \OCP\Share\IShare||$existing->getFullId()!==$fullId||$existing->getShareType()!==7||$existing->getParent()!==0||$existing->getNodeId()!==(int)$folder->getId()||$existing->getSharedWith()!==$envelope['recipient']||$existing->getShareOwner()!==$form->getOwnerId()||$existing->getPermissions()!==$parent['permissions']||$existing->getTarget()!==$parent['file_target']||$parent['file_source']!==(int)$folder->getId()||$parent['owner_uid']!==$form->getOwnerId()||$parent['share_type']!==7){throw new \RuntimeException('Managed Circle parent hydration changed');}
                    $existingShares[]=$existing;
                }
            }else{
                $existingShares=$manager->getSharesBy($form->getOwnerId(), $envelope['type'], $folder, false, -1, 0, false);
            }
            foreach($existingShares as $existing){
                if($existing->getSharedWith()!==$envelope['recipient']){continue;}
                foreach ($known as $row) {
                    if ($row['files_share_id'] === $existing->getFullId()) {
                        ManagedShares::assertIdentity($existing, $envelope, $row);
                        $existingId = $existing->getFullId();
                        break;
                    }
                }
                if ($existingId !== $existing->getFullId()) {
                    throw new \RuntimeException('Unmanaged matching Files share is unavailable');
                }
            }
            $share = $manager->newShare();
            $share->setShareType($envelope['type']);
            $share->setSharedWith($envelope['recipient']);
            $share->setSharedBy($form->getOwnerId());
            $share->setShareOwner($form->getOwnerId());
            $share->setNode($folder);
            $share->setPermissions(1);
            $this->assertNative($envelope, $folder);
            $event = $this->beginEvents($envelope, 'create:'.$folder->getId());
            $created = $manager->createShare($share);
            Server::get(FormsRevisionFence::class)->assertSession();
            $this->finishEvents($event);
            if ($created->getFullId() !== $existingId && !ScopedShareManager::createdHere($manager, $created->getFullId())) {
                throw new \RuntimeException('Native Files share creation identity is uncertain');
            }
            $created = $manager->getShareById($created->getFullId(), onlyValid: false);
            $row = ['files_share_id' => $created->getFullId(), 'folder_id' => $folder->getId()];
            ManagedShares::assertIdentity($created, $envelope, $row);
            if ($created->getFullId() === $existingId) {
                continue;
            }
            $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_shares (files_share_id,form_id,native_share_id,folder_id) VALUES (?,?,?,?)', [$created->getFullId(), $form->getId(), $envelope['native_share_id'], $folder->getId()]);
        } finally {
            $folder->unlock(ILockingProvider::LOCK_EXCLUSIVE);
        }
        }
    }
    private function beginEvents(array $envelope, string $suffix): string
    {
        $key = hash('sha256', $envelope['intent_key'] . ':' . $suffix);
        $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_effects (effect_key,operation_key,kind,form_id,entity_id,phase,payload_ref) VALUES (?,?,?,?,?,?,?)', [$key, $envelope['operation_key'] ?? '', 'files_share', $envelope['form_id'], $envelope['native_share_id'], 'uncertain', 'legacy']);
        return $key;
    }
    private function finishEvents(string $key): void
    {
        $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ? AND phase = ?', ['attempted_unverified', $key, 'uncertain']);
    }
    public function drain(): void
    {
        if ($this->draining) {
            return;
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('Files share delivery requires autocommit');
        }
        $this->draining = true;
        $nativeFailure = null;
        try {
            $pending = $this->pending;
            $this->pending = [];
            foreach ($pending as $key) {
                if ($this->db->executeStatement('UPDATE *PREFIX*forms_mcp_share_intents SET phase = ? WHERE intent_key = ? AND phase = ?', ['uncertain', $key, 'pending']) !== 1) {
                    unset($this->authoringCallbacks[$key]);continue;
                }
                try {
                    $envelope = EffectEnvelope::get($key);
                    if ($envelope['context'] !== null) {
                        $context = $envelope['context'];
                        OriginContext::within($context['verified']['uid'], $context['origin'], 'effect', $context['input'], fn() => OriginContext::withFileImpact($context['file_action'], $context['file_impact'], function () use ($envelope): void {
                            foreach ($this->completed[$envelope['operation_key']] ?? [] as $fileId) {
                                OriginContext::verifiedDeletion($fileId);
                            }
                            $this->execute($envelope);
                        }));
                    } else {
                        $this->execute($envelope);
                    }
                    Server::get(FormsRevisionFence::class)->assertSession();
                    $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_share_intents SET phase = ? WHERE intent_key = ? AND phase = ?', ['verified', $key, 'uncertain']);
                } catch (\Throwable $error) {
                    if (!OriginContext::active()) {
                        $nativeFailure = $error;
                    }
                    /* Claimed intents never replay. */
                } finally {unset($this->authoringCallbacks[$key]);}
            }
        } finally {
            $this->draining = false;
        }
        if ($nativeFailure !== null) {
            throw new \RuntimeException('Form change committed but Files sharing is uncertain', 0, $nativeFailure);
        }
    }
}
