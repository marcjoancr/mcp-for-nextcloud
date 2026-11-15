<?php
/* UNAPPLIED R2 proposal. Native source: Model/Member.php SHA256 4eda69248d7112b212d987f157641ff8ce50aaf220e4b266eb1177e0d5b015d8
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Member
 *
 * @package OCA\Circles\Model
 */
final class Member extends \OCA\Circles\Model\Member
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
    public const LEVEL_NONE = 0;
    public const LEVEL_MEMBER = 1;
    public const LEVEL_MODERATOR = 4;
    public const LEVEL_ADMIN = 8;
    public const LEVEL_OWNER = 9;
    public const TYPE_SINGLE = 0;
    public const TYPE_USER = 1;
    public const TYPE_GROUP = 2;
    public const TYPE_MAIL = 4;
    public const TYPE_CONTACT = 8;
    public const TYPE_CIRCLE = 16;
    public const TYPE_APP = 10000;
    public const ALLOWING_ALL_TYPES = 31;
    public const APP_CIRCLES = 10001;
    public const APP_OCC = 10002;
    public const APP_DEFAULT = 11000;
    // notes array keys
    public const NOTE_MANAGED_BY = 'managed_by';
    public static $TYPE = [0 => 'single', 1 => 'user', 2 => 'group', 4 => 'mail', 8 => 'contact', 16 => 'circle', 10000 => 'app'];
    /**
     * Note: When editing those values, update lib/Application/Capabilities.php
     *
     * @see Capabilities::generateConstantsMember()
     */
    public const STATUS_INVITED = 'Invited';
    public const STATUS_REQUEST = 'Requesting';
    public const STATUS_MEMBER = 'Member';
    public const STATUS_BLOCKED = 'Blocked';
    /**
     * Note: When editing those values, update lib/Application/Capabilities.php
     *
     * @see Capabilities::generateConstantsMember()
     * @var array
     */
    public static $DEF_LEVEL = [1 => 'Member', 4 => 'Moderator', 8 => 'Admin', 9 => 'Owner'];
    public static $DEF_TYPE_MAX = 31;
    /** @var string */
    private $id = '';
    /** @var string */
    private $circleId = '';
    /** @var string */
    private $singleId = '';
    /** @var string */
    private $userId = '';
    /** @var int */
    private $userType = 0;
    /** @var ?Circle */
    private $basedOn;
    /** @var Member */
    private $inheritanceFrom;
    /** @var FederatedUser */
    private $inheritedBy;
    /** @var string */
    private $instance = '';
    /** @var FederatedUser */
    private $invitedBy;
    /** @var RemoteInstance */
    private $remoteInstance;
    /** @var bool */
    private $local = false;
    /** @var int */
    private $level = 0;
    /** @var string */
    private $status = 'Unknown';
    /** @var array */
    private $notes = [];
    /** @var string */
    private $displayName = '';
    /** @var int */
    private $displayUpdate = 0;
    /** @var string */
    private $contactId = '';
    /** @var string */
    private $contactMeta = '';
    /** @var Circle */
    private $circle;
    /** @var int */
    private $joined = 0;
    /** @var Membership[] */
    private $memberships = null;
    /**
     * Member constructor.
     */
    public function __construct()
    {
    }
    /**
     * @param string $id
     *
     * @return $this
     */
    public function setId(string $id): self
    {
        $this->__formsCircleLease->assertActive();
        $this->id = $id;
        return $this;
    }
    /**
     * @return string
     */
    public function getId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->id;
    }
    /**
     * @param string $circleId
     *
     * @return Member
     */
    public function setCircleId(string $circleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleId = $circleId;
        return $this;
    }
    /**
     * @return string
     */
    public function getCircleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleId;
    }
    /**
     * This should replace user_id, user_type and instance; and will use the data from Circle with
     * Config=CFG_SINGLE
     *
     * @param string $singleId
     *
     * @return $this
     */
    public function setSingleId(string $singleId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->singleId = $singleId;
        return $this;
    }
    /**
     * @return string
     */
    public function getSingleId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->singleId;
    }
    /**
     * @param string $userId
     *
     * @return Member
     */
    public function setUserId(string $userId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->userId = $userId;
        if ($this->displayName === '') {
            $this->displayName = $userId;
        }
        return $this;
    }
    /**
     * @return string
     */
    public function getUserId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->userId;
    }
    /**
     * @param int $userType
     *
     * @return Member
     */
    public function setUserType(int $userType): self
    {
        $this->__formsCircleLease->assertActive();
        $this->userType = $userType;
        return $this;
    }
    /**
     * @return int
     */
    public function getUserType(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->userType;
    }
    /**
     * @return int
     * @deprecated 22.0.0 Use `getUserType()` instead
     */
    public function getType(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->getUserType();
    }
    /**
     * @param string $instance
     *
     * @return Member
     */
    public function setInstance(string $instance): self
    {
        $this->__formsCircleLease->assertActive();
        $this->instance = $instance;
        return $this;
    }
    /**
     * @return string
     */
    public function getInstance(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->instance;
    }
    /**
     * @return bool
     */
    public function isLocal(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->getManager()->isLocalInstance($this->getInstance());
    }
    /**
     * @param FederatedUser $invitedBy
     *
     * @return Member
     */
    public function setInvitedBy(\OCA\Circles\Model\FederatedUser $invitedBy): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        $this->invitedBy = $invitedBy;
        return $this;
    }
    /**
     * @return FederatedUser
     */
    public function getInvitedBy(): \OCA\Circles\Model\FederatedUser
    {
        $this->__formsCircleLease->assertActive();
        return $this->invitedBy;
    }
    /**
     * @return bool
     */
    public function hasInvitedBy(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->invitedBy);
    }
    /**
     * @return bool
     */
    public function hasRemoteInstance(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->remoteInstance);
    }
    /**
     * @param RemoteInstance $remoteInstance
     *
     * @return Member
     */
    public function setRemoteInstance(\OCA\Circles\Model\Federated\RemoteInstance $remoteInstance): self
    {
        $this->__formsCircleLease->assertActive();
        $this->remoteInstance = $remoteInstance;
        return $this;
    }
    /**
     * @return RemoteInstance
     */
    public function getRemoteInstance(): \OCA\Circles\Model\Federated\RemoteInstance
    {
        $this->__formsCircleLease->assertActive();
        return $this->remoteInstance;
    }
    /**
     * @return bool
     */
    public function hasBasedOn(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->basedOn);
    }
    /**
     * @param ?Circle $basedOn
     *
     * @return $this
     */
    public function setBasedOn(?\OCA\Circles\Model\Circle $basedOn): self
    {
        $this->__formsCircleLease->assertActive();
        $this->basedOn = $basedOn;
        return $this;
    }
    /**
     * @return ?Circle
     */
    public function getBasedOn(): ?\OCA\Circles\Model\Circle
    {
        $this->__formsCircleLease->assertActive();
        return $this->basedOn;
    }
    /**
     * @return bool
     */
    public function hasInheritedBy(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->inheritedBy);
    }
    /**
     * @param FederatedUser $inheritedBy
     *
     * @return $this
     */
    public function setInheritedBy(\OCA\Circles\Model\FederatedUser $inheritedBy): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritedBy = $inheritedBy;
        return $this;
    }
    /**
     * @return FederatedUser
     */
    public function getInheritedBy(): \OCA\Circles\Model\FederatedUser
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritedBy;
    }
    /**
     * @return bool
     */
    public function hasInheritanceFrom(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->inheritanceFrom);
    }
    /**
     * @param Member $inheritanceFrom
     *
     * @return $this
     */
    public function setInheritanceFrom(\OCA\Circles\Model\Member $inheritanceFrom): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritanceFrom = $inheritanceFrom;
        return $this;
    }
    /**
     * @return Member|null
     */
    public function getInheritanceFrom(): ?\OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritanceFrom;
    }
    /**
     * @param int $level
     *
     * @return Member
     */
    public function setLevel(int $level): self
    {
        $this->__formsCircleLease->assertActive();
        $this->level = $level;
        return $this;
    }
    /**
     * @return int
     */
    public function getLevel(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->level;
    }
    /**
     * @param string $status
     *
     * @return Member
     */
    public function setStatus(string $status): self
    {
        $this->__formsCircleLease->assertActive();
        $this->status = $status;
        return $this;
    }
    /**
     * @return string
     */
    public function getStatus(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->status;
    }
    /**
     * @param array $notes
     *
     * @return Member
     */
    public function setNotes(array $notes): self
    {
        $this->__formsCircleLease->assertActive();
        $this->notes = $notes;
        return $this;
    }
    /**
     * @return array
     */
    public function getNotes(): array
    {
        $this->__formsCircleLease->assertActive();
        return $this->notes;
    }
    /**
     * @param string $key
     *
     * @return string
     */
    public function getNote(string $key): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->get($key, $this->notes);
    }
    /**
     * @param string $key
     *
     * @return array
     */
    public function getNoteArray(string $key): array
    {
        $this->__formsCircleLease->assertActive();
        return $this->getArray($key, $this->notes);
    }
    /**
     * @param string $key
     * @param string $note
     *
     * @return $this
     */
    public function setNote(string $key, string $note): self
    {
        $this->__formsCircleLease->assertActive();
        $this->notes[$key] = $note;
        return $this;
    }
    /**
     * @param string $key
     * @param array $note
     *
     * @return $this
     */
    public function setNoteArray(string $key, array $note): self
    {
        $this->__formsCircleLease->assertActive();
        $this->notes[$key] = $note;
        return $this;
    }
    /**
     * @param string $key
     * @param JsonSerializable $obj
     *
     * @return $this
     */
    public function setNoteObj(string $key, \JsonSerializable $obj): self
    {
        $this->__formsCircleLease->assertActive();
        $this->notes[$key] = $obj;
        return $this;
    }
    /**
     * @param string $displayName
     *
     * @return $this
     */
    public function setDisplayName(string $displayName): self
    {
        $this->__formsCircleLease->assertActive();
        if ($displayName !== '') {
            $this->displayName = $displayName;
        }
        return $this;
    }
    /**
     * @param int $displayUpdate
     *
     * @return Member
     */
    public function setDisplayUpdate(int $displayUpdate): self
    {
        $this->__formsCircleLease->assertActive();
        $this->displayUpdate = $displayUpdate;
        return $this;
    }
    /**
     * @return int
     */
    public function getDisplayUpdate(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->displayUpdate;
    }
    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->displayName;
    }
    /**
     * @param string $contactId
     *
     * @return Member
     */
    public function setContactId(string $contactId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->contactId = $contactId;
        return $this;
    }
    /**
     * @return string
     */
    public function getContactId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->contactId;
    }
    /**
     * @param string $contactMeta
     *
     * @return Member
     */
    public function setContactMeta(string $contactMeta): self
    {
        $this->__formsCircleLease->assertActive();
        $this->contactMeta = $contactMeta;
        return $this;
    }
    /**
     * @return string
     */
    public function getContactMeta(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->contactMeta;
    }
    /**
     * @param Circle $circle
     *
     * @return self
     */
    public function setCircle(\OCA\Circles\Model\Circle $circle): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circle = $circle;
        return $this;
    }
    /**
     * @return Circle
     */
    public function getCircle(): \OCA\Circles\Model\Circle
    {
        $this->__formsCircleLease->assertActive();
        return $this->circle;
    }
    /**
     * @return bool
     */
    public function hasCircle(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->circle);
    }
    /**
     * @param int $joined
     *
     * @return Member
     */
    public function setJoined(int $joined): self
    {
        $this->__formsCircleLease->assertActive();
        $this->joined = $joined;
        return $this;
    }
    /**
     * @return int
     */
    public function getJoined(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->joined;
    }
    /**
     * @return bool
     */
    public function hasMemberships(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->memberships);
    }
    /**
     * @param array $memberships
     *
     * @return self
     */
    public function setMemberships(array $memberships): \OCA\Circles\IEntity
    {
        $this->__formsCircleLease->assertActive();
        $this->memberships = $memberships;
        return $this;
    }
    /**
     * @return Membership[]
     */
    public function getMemberships(): array
    {
        $this->__formsCircleLease->assertActive();
        if (is_null($this->memberships)) {
            $this->getManager()->getMemberships($this);
        }
        return $this->memberships;
    }
    /**
     * @param string $singleId
     * @param bool $detailed
     *
     * @return Membership
     * @throws MembershipNotFoundException
     * @throws RequestBuilderException
     */
    public function getLink(string $singleId, bool $detailed = false): \OCA\Circles\Model\Membership
    {
        $this->__formsCircleLease->assertActive();
        if ($singleId !== '') {
            $this->getManager()->getLink($this, $singleId, $detailed);
        }
        throw new \OCA\Circles\Exceptions\MembershipNotFoundException();
    }
    /**
     * @param string $circleId
     * @param bool $detailed
     *
     * @return Membership
     * @throws MembershipNotFoundException
     * @throws RequestBuilderException
     * @deprecated - use getLink();
     */
    public function getMembership(string $circleId, bool $detailed = false): \OCA\Circles\Model\Membership
    {
        $this->__formsCircleLease->assertActive();
        return $this->getLink($circleId, $detailed);
    }
    /**
     * @param Member $member
     * @param bool $full
     *
     * @return bool
     */
    public function compareWith(\OCA\Circles\Model\Member $member, bool $full = true): bool
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getId() !== $member->getId() || $this->getCircleId() !== $member->getCircleId() || $this->getSingleId() !== $member->getSingleId() || $this->getUserId() !== $member->getUserId() || $this->getUserType() != $member->getUserType() || $this->getInstance() !== $member->getInstance()) {
            return false;
        }
        if ($full && ($this->getLevel() != $member->getLevel() || $this->getStatus() !== $member->getStatus())) {
            return false;
        }
        return true;
    }
    /**
     * @param array $data
     *
     * @return $this
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        $this->__formsCircleLease->assertActive();
        if ($this->get('userId', $data) === '') {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setId($this->get('id', $data));
        $this->setCircleId($this->get('circleId', $data));
        $this->setSingleId($this->get('singleId', $data));
        $this->setUserId($this->get('userId', $data));
        $this->setUserType($this->getInt('userType', $data));
        $this->setInstance($this->get('instance', $data));
        $this->setLevel($this->getInt('level', $data));
        $this->setStatus($this->get('status', $data));
        $this->setDisplayName($this->get('displayName', $data));
        $this->setDisplayUpdate($this->getInt('displayUpdate', $data));
        $this->setNotes($this->getArray('notes', $data));
        $this->setContactId($this->get('contactId', $data));
        $this->setContactMeta($this->get('contactMeta', $data));
        $this->setJoined($this->getInt('joined', $data));
        try {
            /** @var Circle $circle */
            $circle = $this->deserialize($this->getArray('circle', $data), \OCA\Circles\Model\Circle::class);
            $this->setCircle($circle);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var Circle $circle */
            $circle = $this->deserialize($this->getArray('basedOn', $data), \OCA\Circles\Model\Circle::class);
            $this->setBasedOn($circle);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var FederatedUser $invitedBy */
            $invitedBy = $this->deserialize($this->getArray('invitedBy', $data), \OCA\Circles\Model\FederatedUser::class);
            $this->setInvitedBy($invitedBy);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var FederatedUSer $inheritedBy */
            $inheritedBy = $this->deserialize($this->getArray('inheritedBy', $data), \OCA\Circles\Model\Membership::class);
            $this->setInheritedBy($inheritedBy);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws MemberNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'single_id', $data) === '') {
            throw new \OCA\Circles\Exceptions\MemberNotFoundException();
        }
        $this->setId($this->get($prefix . 'member_id', $data));
        $this->setCircleId($this->get($prefix . 'circle_id', $data));
        $this->setSingleId($this->get($prefix . 'single_id', $data));
        $this->setUserId($this->get($prefix . 'user_id', $data));
        $this->setUserType($this->getInt($prefix . 'user_type', $data));
        $this->setInstance($this->get($prefix . 'instance', $data));
        $this->setLevel($this->getInt($prefix . 'level', $data));
        $this->setStatus($this->get($prefix . 'status', $data));
        $this->setDisplayName($this->get($prefix . 'cached_name', $data));
        $this->setNotes($this->getArray($prefix . 'note', $data));
        $this->setContactId($this->get($prefix . 'contact_id', $data));
        $this->setContactMeta($this->get($prefix . 'contact_meta', $data));
        $cachedUpdate = $this->get($prefix . 'cached_update', $data);
        if ($cachedUpdate !== '') {
            $this->setDisplayUpdate(\DateTime::createFromFormat('Y-m-d H:i:s', $cachedUpdate)->getTimestamp());
        }
        $joined = $this->get($prefix . 'joined', $data);
        if ($joined !== '') {
            $this->setJoined(\DateTime::createFromFormat('Y-m-d H:i:s', $joined)->getTimestamp());
        }
        if ($this->getInstance() === '') {
            $this->setInstance($this->getManager()->getLocalInstance());
        }
        $this->getManager()->manageImportFromDatabase($this, $data, $prefix);
        // in case invitedBy is not obtainable from 'invited_by', we reach data from 'note'
        if (!$this->hasInvitedBy()) {
            $invitedByArray = $this->getNoteArray('invitedBy');
            if (!empty($invitedByArray)) {
                try {
                    $invitedBy = $this->__formsCircleLease->model(\OCA\Circles\Model\FederatedUser::class, []);
                    $this->setInvitedBy($invitedBy->import($invitedByArray));
                } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
                }
            }
        }
        return $this;
    }
    /**
     * @return string[]
     * @throws UnknownInterfaceException
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['id' => $this->getId(), 'circleId' => $this->getCircleId(), 'singleId' => $this->getSingleId(), 'userId' => $this->getUserId(), 'userType' => $this->getUserType(), 'instance' => $this->getManager()->fixInstance($this->getInstance()), 'local' => $this->isLocal(), 'level' => $this->getLevel(), 'status' => $this->getStatus(), 'displayName' => $this->getDisplayName(), 'displayUpdate' => $this->getDisplayUpdate(), 'notes' => $this->getNotes(), 'contactId' => $this->getContactId(), 'contactMeta' => $this->getContactMeta(), 'joined' => $this->getJoined()];
        if ($this->hasInvitedBy()) {
            $arr['invitedBy'] = $this->getInvitedBy();
        }
        if ($this->hasBasedOn()) {
            $arr['basedOn'] = $this->getBasedOn();
        }
        if ($this->hasInheritedBy()) {
            $arr['inheritedBy'] = $this->getInheritedBy();
        }
        if ($this->hasInheritanceFrom()) {
            $arr['inheritanceFrom'] = $this->getInheritanceFrom();
        }
        if ($this->hasCircle()) {
            $arr['circle'] = $this->getCircle();
        }
        if ($this->hasMemberships()) {
            $arr['memberships'] = $this->getMemberships();
        }
        if ($this->hasRemoteInstance()) {
            $arr['remoteInstance'] = $this->getRemoteInstance();
        }
        return $arr;
    }
    /**
     * @param int $level
     *
     * @return int
     * @throws ParseMemberLevelException
     */
    public static function parseLevelInt(int $level): int
    {
        if (!array_key_exists($level, self::$DEF_LEVEL)) {
            $all = implode(', ', array_keys(self::$DEF_LEVEL));
            throw new \OCA\Circles\Exceptions\ParseMemberLevelException('Available levels: ' . $all, 121);
        }
        return $level;
    }
    /**
     * @param string $levelString
     *
     * @return int
     * @throws ParseMemberLevelException
     */
    public static function parseLevelString(string $levelString): int
    {
        $levelString = ucfirst(strtolower($levelString));
        $level = array_search($levelString, \OCA\Circles\Model\Member::$DEF_LEVEL);
        if (!$level) {
            $all = implode(', ', array_values(self::$DEF_LEVEL));
            throw new \OCA\Circles\Exceptions\ParseMemberLevelException('Available levels: ' . $all, 121);
        }
        return (int) $level;
    }
    /**
     * @param string $typeString
     *
     * @return int
     * @throws UserTypeNotFoundException
     */
    public static function parseTypeString(string $typeString): int
    {
        $typeString = strtolower($typeString);
        if (array_key_exists($typeString, \OCA\Circles\Model\Member::$TYPE)) {
            return (int) $typeString;
        }
        $type = array_search($typeString, \OCA\Circles\Model\Member::$TYPE);
        if ($type === false) {
            $all = implode(', ', array_values(self::$TYPE));
            throw new \OCA\Circles\Exceptions\UserTypeNotFoundException('Available types: ' . $all);
        }
        return (int) $type;
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
