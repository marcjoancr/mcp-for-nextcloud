<?php
/* UNAPPLIED R2 proposal. Native source: Model/ShareWrapper.php SHA256 863a1246bab4dcf20ec01c3ba295cd247db1906889c3af291bc0f5a076fce26f
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class ShareWrapper
 *
 * @package OCA\Circles\Model
 */
final class ShareWrapper extends \OCA\Circles\Model\ShareWrapper
{
    use \OCA\Circles\Tools\Traits\TArrayTools;
    /**
     * @param JsonSerializable $model
     *
     * @return array
     */
    public function serialize(\JsonSerializable $model): array
    {
        $this->__formsCircleLease->assertActive();
        return json_decode(json_encode($model), true);
    }
    /**
     * @param array $data
     *
     * @return array
     */
    public function serializeArray(array $data): array
    {
        $this->__formsCircleLease->assertActive();
        return json_decode(json_encode($data), true);
    }
    /**
     * @param array $data
     * @param string $class
     *
     * @return IDeserializable
     * @throws InvalidItemException
     */
    public function deserialize(array $data, string $class): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        /** @var IDeserializable $item */
        $item = $this->__formsCircleLease->model($class);
        $item->import($data);
        return $item;
    }
    /**
     * @param array $data
     * @param string $class
     * @param bool $associative
     *
     * @return IDeserializable[]
     */
    public function deserializeArray(array $data, string $class, bool $associative = false): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = [];
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {throw new \RuntimeException('Malformed Circle list entry');}
            try {
                if ($associative) {
                    $arr[$key] = $this->deserialize($entry, $class);
                } else {
                    $arr[] = $this->deserialize($entry, $class);
                }
            } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException $error) {throw new \RuntimeException('Malformed Circle list entry',0,$error);}
        }
        return $arr;
    }
    /**
     * @param string $json
     * @param string $class
     *
     * @return IDeserializable[]
     * @throws InvalidItemException
     */
    public function deserializeList(string $json, string $class): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = [];
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $arr;
        }
        foreach ($data as $entry) {
            $arr[] = $this->deserialize($entry, $class);
        }
        return $arr;
    }
    /**
     * @param string $json
     * @param string $class
     *
     * @return IDeserializable
     * @throws InvalidItemException
     */
    public function deserializeJson(string $json, string $class): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        $data = json_decode($json, true);
        return $this->deserialize($data, $class);
    }
    private string $id = '';
    private int $permissions = 0;
    private string $itemType = '';
    private int $itemSource = 0;
    private string $itemTarget = '';
    private int $fileSource = 0;
    private string $fileTarget = '';
    private string $token = '';
    private int $status = 0;
    private string $providerId = '';
    private \DateTime $shareTime;
    private string $note = '';
    private string $sharedWith = '';
    private string $sharedBy = '';
    private ?\DateTime $expirationDate = null;
    private string $shareOwner = '';
    private int $shareType = 0;
    private int $parent = 0;
    private ?\OCA\Circles\Model\Circle $circle = null;
    private int $childId = 0;
    private string $childFileTarget = '';
    private int $childPermissions = 0;
    private ?\OCA\Circles\Model\FileCacheWrapper $fileCache = null;
    private ?\OCA\Circles\Model\Member $initiator = null;
    private ?\OCA\Circles\Model\Member $owner = null;
    private ?\OCA\Circles\Model\ShareToken $shareToken = null;
    private ?\OCP\Share\IAttributes $attributes = null;
    private bool $hideDownload = false;
    private bool $mailSend = true;
    public function __construct()
    {
        $this->shareTime = new \DateTime();
    }
    public function setId(string $id): self
    {
        $this->__formsCircleLease->assertActive();
        $this->id = $id;
        return $this;
    }
    public function getId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->id;
    }
    public function setPermissions(int $permissions): self
    {
        $this->__formsCircleLease->assertActive();
        $this->permissions = $permissions;
        return $this;
    }
    public function getPermissions(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->permissions;
    }
    public function setItemType(string $itemType): self
    {
        $this->__formsCircleLease->assertActive();
        $this->itemType = $itemType;
        return $this;
    }
    public function getItemType(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->itemType;
    }
    public function setItemSource(int $itemSource): self
    {
        $this->__formsCircleLease->assertActive();
        $this->itemSource = $itemSource;
        return $this;
    }
    public function getItemSource(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->itemSource;
    }
    public function setItemTarget(string $itemTarget): self
    {
        $this->__formsCircleLease->assertActive();
        $this->itemTarget = $itemTarget;
        return $this;
    }
    public function getItemTarget(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->itemTarget;
    }
    public function setFileSource(int $fileSource): self
    {
        $this->__formsCircleLease->assertActive();
        $this->fileSource = $fileSource;
        return $this;
    }
    public function getFileSource(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->fileSource;
    }
    public function setFileTarget(string $fileTarget): self
    {
        $this->__formsCircleLease->assertActive();
        $this->fileTarget = $fileTarget;
        return $this;
    }
    public function getFileTarget(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->fileTarget;
    }
    public function setToken(string $token): self
    {
        $this->__formsCircleLease->assertActive();
        $this->token = $token;
        return $this;
    }
    public function getToken(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->token;
    }
    public function setStatus(int $status): self
    {
        $this->__formsCircleLease->assertActive();
        $this->status = $status;
        return $this;
    }
    public function getStatus(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->status;
    }
    public function setProviderId(string $providerId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->providerId = $providerId;
        return $this;
    }
    public function getProviderId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->providerId;
    }
    public function setShareTime(\DateTime $shareTime): self
    {
        $this->__formsCircleLease->assertActive();
        $this->shareTime = $shareTime;
        return $this;
    }
    public function getShareTime(): \DateTime
    {
        $this->__formsCircleLease->assertActive();
        return $this->shareTime;
    }
    public function setShareNote(string $note): self
    {
        $this->__formsCircleLease->assertActive();
        $this->note = $note;
        return $this;
    }
    public function getShareNote(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->note;
    }
    public function setSharedWith(string $sharedWith): self
    {
        $this->__formsCircleLease->assertActive();
        $this->sharedWith = $sharedWith;
        return $this;
    }
    public function getSharedWith(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->sharedWith;
    }
    public function setSharedBy(string $sharedBy): self
    {
        $this->__formsCircleLease->assertActive();
        $this->sharedBy = $sharedBy;
        return $this;
    }
    public function getExpirationDate(): ?\DateTime
    {
        $this->__formsCircleLease->assertActive();
        return $this->expirationDate;
    }
    public function setExpirationDate(?\DateTime $date): self
    {
        $this->__formsCircleLease->assertActive();
        $this->expirationDate = $date;
        return $this;
    }
    public function getSharedBy(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->sharedBy;
    }
    public function setShareOwner(string $shareOwner): self
    {
        $this->__formsCircleLease->assertActive();
        $this->shareOwner = $shareOwner;
        return $this;
    }
    public function getShareOwner(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->shareOwner;
    }
    public function setShareType(int $shareType): self
    {
        $this->__formsCircleLease->assertActive();
        $this->shareType = $shareType;
        return $this;
    }
    public function getShareType(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->shareType;
    }
    public function setParent(int $parent): self
    {
        $this->__formsCircleLease->assertActive();
        $this->parent = $parent;
        return $this;
    }
    public function getParent(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->parent;
    }
    public function setCircle(\OCA\Circles\Model\Circle $circle): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circle = $circle;
        return $this;
    }
    public function getCircle(): \OCA\Circles\Model\Circle
    {
        $this->__formsCircleLease->assertActive();
        return $this->circle;
    }
    public function hasCircle(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->circle);
    }
    public function setChildId(int $childId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->childId = $childId;
        return $this;
    }
    public function getChildId(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->childId;
    }
    public function setChildFileTarget(string $childFileTarget): self
    {
        $this->__formsCircleLease->assertActive();
        $this->childFileTarget = $childFileTarget;
        return $this;
    }
    public function getChildFileTarget(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->childFileTarget;
    }
    public function setChildPermissions(int $childPermissions): self
    {
        $this->__formsCircleLease->assertActive();
        $this->childPermissions = $childPermissions;
        return $this;
    }
    public function getChildPermissions(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->childPermissions;
    }
    public function setFileCache(\OCA\Circles\Model\FileCacheWrapper $fileCache): self
    {
        $this->__formsCircleLease->assertActive();
        $this->fileCache = $fileCache;
        return $this;
    }
    public function getFileCache(): \OCA\Circles\Model\FileCacheWrapper
    {
        $this->__formsCircleLease->assertActive();
        return $this->fileCache;
    }
    public function hasFileCache(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->fileCache);
    }
    public function setInitiator(\OCA\Circles\Model\Member $initiator): self
    {
        $this->__formsCircleLease->assertActive();
        $this->initiator = $initiator;
        return $this;
    }
    public function getInitiator(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->initiator;
    }
    public function hasInitiator(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->initiator);
    }
    public function setOwner(\OCA\Circles\Model\Member $owner): self
    {
        $this->__formsCircleLease->assertActive();
        $this->owner = $owner;
        return $this;
    }
    public function getOwner(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->owner;
    }
    public function hasOwner(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->owner);
    }
    public function setShareToken(\OCA\Circles\Model\ShareToken $shareToken): self
    {
        $this->__formsCircleLease->assertActive();
        $this->shareToken = $shareToken;
        return $this;
    }
    public function getShareToken(): \OCA\Circles\Model\ShareToken
    {
        $this->__formsCircleLease->assertActive();
        return $this->shareToken;
    }
    public function hasShareToken(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->shareToken);
    }
    public function getAttributes(): ?\OCP\Share\IAttributes
    {
        $this->__formsCircleLease->assertActive();
        return $this->attributes;
    }
    public function setAttributes(?\OCP\Share\IAttributes $attributes): self
    {
        $this->__formsCircleLease->assertActive();
        $this->attributes = $attributes;
        return $this;
    }
    public function getHideDownload(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->hideDownload;
    }
    public function setHideDownload(bool $hideDownload): self
    {
        $this->__formsCircleLease->assertActive();
        $this->hideDownload = $hideDownload;
        return $this;
    }
    public function setMailSend(bool $mailSend): self
    {
        $this->__formsCircleLease->assertActive();
        $this->mailSend = $mailSend;
        return $this;
    }
    public function getMailSend(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->mailSend;
    }
    /**
     * @throws IllegalIDChangeException
     */
    public function getShare(\OCP\Files\IRootFolder $rootFolder, \OCP\IUserManager $userManager, \OCP\IURLGenerator $urlGenerator, bool $nullOnMissingFileCache = false): ?\OCP\Share\IShare
    {
        $this->__formsCircleLease->assertActive();
        $share = new \OC\Share20\Share($rootFolder, $userManager);
        $share->setId($this->getId());
        $share->setPermissions($this->getPermissions());
        $share->setNodeType($this->getItemType());
        $share->setNodeId($this->getFileSource());
        $share->setTarget($this->getFileTarget());
        $share->setProviderId($this->getProviderId());
        $share->setStatus($this->getStatus());
        $share->setToken($this->getToken());
        if ($this->getExpirationDate() !== null) {
            $share->setExpirationDate($this->getExpirationDate());
        }
        $share->setHideDownload($this->getHideDownload());
        $share->setAttributes($this->getAttributes());
        $share->setNote($this->getShareNote());
        $share->setMailSend($this->getMailSend());
        if ($this->hasShareToken()) {
            $password = $this->getShareToken()->getPassword();
            if ($password !== '') {
                $share->setPassword($password);
            }
        }
        $share->setShareTime($this->getShareTime())->setSharedWith($this->getSharedWith())->setSharedBy($this->getSharedBy())->setShareOwner($this->getShareOwner())->setShareType($this->getShareType());
        if ($this->getChildId() > 0) {
            $share->setTarget($this->getChildFileTarget());
            if ($this->getChildPermissions() < $this->getPermissions()) {
                $share->setPermissions($this->getChildPermissions());
            }
        }
        $this->setShareDisplay($share, $urlGenerator);
        if ($this->hasFileCache()) {
            if (!$this->getFileCache()->isAccessible()) {
                return null;
            }
            $share->setNodeCacheEntry(\OC\Files\Cache\Cache::cacheEntryFromData($this->getFileCache()->toCache(), $this->__formsCircleLease->service(\OCP\Files\IMimeTypeLoader::class)));
        } elseif ($nullOnMissingFileCache) {
            return null;
        }
        return $share;
    }
    private function setShareDisplay(\OCP\Share\IShare $share, \OCP\IURLGenerator $urlGenerator): void
    {
        $this->__formsCircleLease->assertActive();
        if (!$this->hasCircle()) {
            return;
        }
        $circle = $this->getCircle();
        if ($circle->isConfig(\OCA\Circles\Model\Circle::CFG_PERSONAL) && $this->hasInitiator() && $circle->getOwner()->getSingleId() !== $this->getInitiator()->getSingleId()) {
            $share->setSharedWithDisplayName(' ');
            return;
        }
        $display = $circle->getDisplayName();
        if ($circle->getSource() === \OCA\Circles\Model\Member::TYPE_CIRCLE) {
            $l10n = $this->__formsCircleLease->circleLocalization();
            $display = $l10n->t('%s (Team owned by %s)', [$display, $circle->getOwner()->getDisplayName()]);
        } else {
            $display .= ' (' . \OCA\Circles\Model\Circle::$DEF_SOURCE[$circle->getSource()] . ')';
        }
        $share->setSharedWithDisplayName($display);
        $icon = $urlGenerator->getAbsoluteURL($urlGenerator->imagePath(\OCA\Circles\AppInfo\Application::APP_ID, 'circles.svg'));
        $share->setSharedWithAvatar($icon);
    }
    /**
     * @param array $data
     *
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getInt('id', $data) === 0) {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $shareTime = new \DateTime();
        $shareTime->setTimestamp($this->getInt('shareTime', $data));
        $this->setId($this->get('id', $data))->setShareType($this->getInt('shareType', $data))->setParent($data['parent'] ?? 0)->setPermissions($this->getInt('permissions', $data))->setHideDownload($this->getBool('hideDownload', $data))->setItemType($this->get('itemType', $data))->setItemSource($this->getInt('itemSource', $data))->setItemTarget($this->get('itemTarget', $data))->setFileSource($this->getInt('fileSource', $data))->setFileTarget($this->get('fileTarget', $data))->setSharedWith($this->get('sharedWith', $data))->setSharedBy($this->get('sharedBy', $data))->setShareOwner($this->get('shareOwner', $data))->setToken($this->get('token', $data))->setShareTime($shareTime)->setShareNote($this->get('note', $data));
        $this->importAttributesFromDatabase($this->get('attributes', $data));
        try {
            $expirationDate = $this->getInt('expiration', $data);
            if ($expirationDate > 0) {
                $this->setExpirationDate((new \DateTime())->setTimestamp($expirationDate));
            }
        } catch (\Exception $e) {
        }
        $this->setChildId($this->getInt('childId', $data))->setChildFileTarget($this->get('childFileTarget', $data))->setChildPermissions($this->getInt('childPermissions', $data))->setProviderId(\OCA\Circles\ShareByCircleProvider::IDENTIFIER)->setStatus(\OCP\Share\IShare::STATUS_ACCEPTED);
        try {
            $circle = $this->__formsCircleLease->model(\OCA\Circles\Model\Circle::class, []);
            $this->setCircle($circle->import($this->getArray('circle', $data)));
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException $e) {
        }
        try {
            $fileCache = $this->__formsCircleLease->model(\OCA\Circles\Model\FileCacheWrapper::class, []);
            $this->setFileCache($fileCache->import($this->getArray('fileCache', $data)));
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            $owner = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
            $this->setOwner($owner->import($this->getArray('owner', $data)));
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            $member = $this->__formsCircleLease->model(\OCA\Circles\Model\Member::class, []);
            $this->setInitiator($member->import($this->getArray('viewer', $data)));
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            $shareToken = $this->__formsCircleLease->model(\OCA\Circles\Model\ShareToken::class, []);
            $this->setShareToken($shareToken->import($this->getArray('shareToken', $data)));
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        return $this;
    }
    /** Preserve native row ancestry and consent; never infer acceptance from successful hydration. */
    private function hydrateDecision(array $data,string $prefix):void {
        if(!array_key_exists($prefix.'parent',$data)||!array_key_exists($prefix.'accepted',$data))throw new \RuntimeException('Circle share decision projection is incomplete');
        $parent=$data[$prefix.'parent']??0;$status=$data[$prefix.'accepted'];
        if((!is_int($parent)&&!is_string($parent))||!preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$parent)||filter_var($parent,FILTER_VALIDATE_INT)===false||$parent<0
            ||(!is_int($status)&&!is_string($status))||!in_array($status,[0,1,2,'0','1','2'],true))throw new \RuntimeException('Circle share decision projection is malformed');
        $this->setParent((int)$parent);$this->setStatus((int)$status);
    }
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        $shareTime = new \DateTime();
        $shareTime->setTimestamp($this->getInt($prefix . 'stime', $data));
        $this->setId($this->get($prefix . 'id', $data))->setShareType($this->getInt($prefix . 'share_type', $data))->setPermissions($this->getInt($prefix . 'permissions', $data))->setItemType($this->get($prefix . 'item_type', $data))->setItemSource($this->getInt($prefix . 'item_source', $data))->setItemTarget($this->get($prefix . 'item_target', $data))->setFileSource($this->getInt($prefix . 'file_source', $data))->setFileTarget($this->get($prefix . 'file_target', $data))->setSharedWith($this->get($prefix . 'share_with', $data))->setSharedBy($this->get($prefix . 'uid_initiator', $data))->setShareOwner($this->get($prefix . 'uid_owner', $data))->setToken($this->get($prefix . 'token', $data))->setShareTime($shareTime)->setShareNote($this->get($prefix . 'note', $data));
        try {
            $expirationDate = $this->get($prefix . 'expiration', $data);
            if ($expirationDate !== '') {
                $this->setExpirationDate(new \DateTime($expirationDate));
            }
        } catch (\Exception $e) {
            $this->__formsCircleLease->service(\Psr\Log\LoggerInterface::class)->warning('could not parse expiration date', ['exception' => $e]);
        }
        $this->importAttributesFromDatabase($this->get('attributes', $data));
        //		if (($password = $this->get('personal_password', $data, '')) !== '') {
        //			$share->setPassword($this->get('personal_password', $data, ''));
        //		} else if (($password = $this->get('password', $data, '')) !== '') {
        //			$share->setPassword($this->get('password', $data, ''));
        //		}
        $this->setChildId($this->getInt($prefix . 'child_id', $data))->setChildFileTarget($this->get($prefix . 'child_file_target', $data))->setChildPermissions($this->getInt($prefix . 'child_permissions', $data))->setProviderId(\OCA\Circles\ShareByCircleProvider::IDENTIFIER);
        $this->hydrateDecision($data,$prefix);
        $this->getManager()->manageImportFromDatabase($this, $data, $prefix);
        return $this;
    }
    /**
     * Load from database format (JSON string) to IAttributes
     * based on \OC\Share20\DefaultShareProvider
     */
    private function importAttributesFromDatabase(string $data): void
    {
        $this->__formsCircleLease->assertActive();
        if ($data === '') {
            return;
        }
        $attributes = new \OC\Share20\ShareAttributes();
        $compressedAttributes = json_decode($data, true);
        if (!is_array($compressedAttributes)) {
            return;
        }
        foreach ($compressedAttributes as $compressedAttribute) {
            $attributes->setAttribute(...$compressedAttribute);
        }
        $this->setHideDownload(!($attributes->getAttribute('permissions', 'download') ?? true));
        $this->setAttributes($attributes);
    }
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['id' => $this->getId(), 'shareType' => $this->getShareType(), 'providerId' => $this->getProviderId(), 'permissions' => $this->getPermissions(), 'attributes' => $this->getAttributes() !== null ? json_encode($this->getAttributes()->toArray()) : null, 'hideDownload' => $this->getHideDownload(), 'itemType' => $this->getItemType(), 'itemSource' => $this->getItemSource(), 'itemTarget' => $this->getItemTarget(), 'fileSource' => $this->getFileSource(), 'fileTarget' => $this->getFileTarget(), 'expiration' => $this->getExpirationDate()?->getTimestamp(), 'status' => $this->getStatus(), 'shareTime' => $this->getShareTime()->getTimestamp(), 'note' => $this->getShareNote(), 'sharedWith' => $this->getSharedWith(), 'sharedBy' => $this->getSharedBy(), 'shareOwner' => $this->getShareOwner(), 'token' => $this->getToken(), 'childId' => $this->getChildId(), 'childFileTarget' => $this->getChildFileTarget(), 'childPermissions' => $this->getChildPermissions()];
        if ($this->hasOwner()) {
            $arr['owner'] = $this->getOwner();
        }
        if ($this->hasCircle()) {
            $arr['circle'] = $this->getCircle();
        }
        if ($this->hasInitiator()) {
            $arr['viewer'] = $this->getInitiator();
        }
        if ($this->hasFileCache()) {
            $arr['fileCache'] = $this->getFileCache();
        }
        if ($this->hasShareToken()) {
            $arr['shareToken'] = $this->getShareToken();
        }
        return $arr;
    }
    private Lease $__formsCircleLease;
    final public function bindCircleLease(Lease $lease): void
    {
        if (isset($this->__formsCircleLease)) {
            throw new \LogicException("Circle model is already bound");
        }
        $this->__formsCircleLease = $lease;
    }
    protected function getManager(): \OCA\Circles\Model\ModelManager
    {
        $this->__formsCircleLease->assertActive();
        return $this->__formsCircleLease->manager();
    }
protected function getList(string $k,array $arr,array $import,array $default=[]): array {
    $this->__formsCircleLease->assertActive();
    if(count($import)!==2||!is_string($import[0])||$import[1]!=='import'){throw new \RuntimeException('Circle import method is not qualified');}
    $rows=$this->getArray($k,$arr,$default);
    \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate($rows);
    $out=[];foreach($rows as $row){$model=$this->__formsCircleLease->model($import[0]);$model->import($row);$out[]=$model;}return $out;
}
}
