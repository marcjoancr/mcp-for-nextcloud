<?php
/* UNAPPLIED R2 proposal. Native source: Model/Circle.php SHA256 47cbdbac6aa6f3ca8d947f24f43e4770e21a7074dad08d42f9615200d125218f
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Circle
 *
 * ** examples of use of bitwise flags for members management:
 *      CFG_OPEN, CFG_REQUEST, CFG_INVITE, CFG_FRIEND
 *
 * - CFG_OPEN                             => everyone can enter. moderator can add members.
 * - CFG_OPEN | CFG_REQUEST               => anyone can initiate a request to join the circle, moderator can
 *                                           add members
 * - CFG_OPEN | CFG_INVITE                => every one can enter, moderator must send invitation.
 * - CFG_OPEN | CFG_INVITE | CFG_REQUEST  => every one send a request, moderator must send invitation.
 * - CFG_OPEN | CFG_FRIEND                => useless
 * - CFG_OPEN | CFG_FRIEND | *            => useless
 *
 * - CFG_CIRCLE                           => no one can enter, moderator can add members.
 *                                           default config, this is only for code readability.
 * - CFG_INVITE                           => no one can enter, moderator must send invitation.
 * - CFG_FRIEND                           => no one can enter, but all members can add new member.
 * - CFG_REQUEST                          => useless (use CFG_OPEN | CFG_REQUEST)
 * - CFG_FRIEND | CFG_REQUEST             => no one can join the circle, but all members can request a
 *                                           moderator to accept new member
 * - CFG_FRIEND | CFG_INVITE              => no one can join the circle, but all members can add new member.
 *                                           An invitation will be generated
 * - CFG_FRIEND | CFG_INVITE | CFG_REQUEST  => no one can join the circle, but all members can request a
 *                                             moderator to accept new member. An invitation will be generated
 *
 * @package OCA\Circles\Model
 */
final class Circle extends \OCA\Circles\Model\Circle
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
    public const FLAGS_SHORT = 1;
    public const FLAGS_LONG = 2;
    // specific value
    public const CFG_CIRCLE = 0;
    // only for code readability. Circle is locked by default.
    public const CFG_SINGLE = 1;
    // Circle with only one single member.
    public const CFG_PERSONAL = 2;
    // Personal circle, only the owner can see it.
    // bitwise
    public const CFG_SYSTEM = 4;
    // System Circle (not managed by the official front-end). Meaning some config are limited
    public const CFG_VISIBLE = 8;
    // Visible to everyone, if not visible, people have to know its name to be able to find it
    public const CFG_OPEN = 16;
    // Circle is open, people can join
    public const CFG_INVITE = 32;
    // Adding a member generate an invitation that needs to be accepted
    public const CFG_REQUEST = 64;
    // Request to join Circles needs to be confirmed by a moderator
    public const CFG_FRIEND = 128;
    // Members of the circle can invite their friends
    public const CFG_PROTECTED = 256;
    // Password protected to join/request
    public const CFG_NO_OWNER = 512;
    // no owner, only members
    public const CFG_HIDDEN = 1024;
    // hidden from listing, but available as a share entity
    public const CFG_BACKEND = 2048;
    // Fully hidden, only backend Circles
    public const CFG_LOCAL = 4096;
    // Local even on GlobalScale
    public const CFG_ROOT = 8192;
    // Circle cannot be inside another Circle
    public const CFG_CIRCLE_INVITE = 16384;
    // Circle must confirm when invited in another circle
    public const CFG_FEDERATED = 32768;
    // Federated
    public const CFG_MOUNTPOINT = 65536;
    // Generate a Files folder for this Circle
    public const CFG_APP = 131072;
    // Some features are not available to the OCS API (ie. destroying Circle)
    public const CFG_SCIM = 262144;
    // Circle is managed by a SCIM server, not manually
    public static $DEF_CFG_MAX = 524287;
    // settings array keys
    public const SETTING_EXTERNAL_ID = 'external_id';
    /**
     * Note: When editing those values, update lib/Application/Capabilities.php
     *
     * @see Capabilities::getCapabilitiesCircleConstants()
     * @var array
     */
    public static $DEF_CFG = [1 => 'S|Single', 2 => 'P|Personal', 4 => 'Y|System', 8 => 'V|Visible', 16 => 'O|Open', 32 => 'I|Invite', 64 => 'JR|Join Request', 128 => 'F|Friends', 256 => 'PP|Password Protected', 512 => 'NO|No Owner', 1024 => 'H|Hidden', 2048 => 'T|Backend', 4096 => 'L|Local', 8192 => 'T|Root', 16384 => 'CI|Circle Invite', 32768 => 'F|Federated', 65536 => 'M|Mountpoint', 131072 => 'A|App', 262144 => 'SC|SCIM'];
    /**
     * Note: When editing those values, update lib/AppInfo/Capabilities.php
     *
     * @see Capabilities::getCapabilitiesCircleConstants()
     * @var array
     */
    public static $DEF_SOURCE = [1 => 'Nextcloud Account', 2 => 'Nextcloud Group', 4 => 'Email Address', 8 => 'Contact', 16 => 'Team', 10000 => 'Nextcloud App', 10001 => 'Teams App', 10002 => 'Admin Command Line', 11000 => '3rd party app', 11010 => 'Collectives App'];
    public static $DEF_CFG_CORE_FILTER = [1, 2, 4];
    public static $DEF_CFG_SYSTEM_FILTER = [512, 1024, 2048];
    /** @var string */
    private $singleId = '';
    /** @var int */
    private $config = 0;
    /** @var string */
    private $name = '';
    /** @var string */
    private $displayName = '';
    /** @var string */
    private $sanitizedName = '';
    /** @var int */
    private $source = 0;
    /** @var Member */
    private $owner;
    /** @var Member */
    private $initiator;
    /** @var Member */
    private $directInitiator;
    /** @var array */
    private $settings = [];
    /** @var string */
    private $description = '';
    /** @var int */
    private $contactAddressBook = 0;
    /** @var string */
    private $contactGroupName = '';
    /** @var string */
    private $instance = '';
    /** @var int */
    private $population = 0;
    /** @var int */
    private $populationInherited = 0;
    /** @var CircleInvitation|null */
    private $circleInvitation = null;
    //	/** @var bool */
    //	private $hidden = false;
    /** @var int */
    private $creation = 0;
    /** @var Member[] */
    private $members = null;
    /** @var Member[] */
    private $inheritedMembers = null;
    /** @var bool */
    private $detailedInheritedMember = false;
    /** @var Membership[] */
    private $memberships = null;
    /**
     * Circle constructor.
     */
    public function __construct()
    {
    }
    /**
     * @param string $singleId
     *
     * @return self
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
     * @return string
     * @deprecated - removed in NC23
     */
    public function getUniqueId(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->getSingleId();
    }
    /**
     * @param int $config
     *
     * @return self
     */
    public function setConfig(int $config): self
    {
        $this->__formsCircleLease->assertActive();
        $this->config = $config;
        return $this;
    }
    /**
     * @return int
     */
    public function getConfig(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->config;
    }
    /**
     * @param int $flag
     * @param int $test
     *
     * @return bool
     */
    public function isConfig(int $flag, int $test = 0): bool
    {
        $this->__formsCircleLease->assertActive();
        if ($test === 0) {
            $test = $this->getConfig();
        }
        return ($test & $flag) !== 0;
    }
    /**
     * @param int $flag
     */
    public function addConfig(int $flag): void
    {
        $this->__formsCircleLease->assertActive();
        if (!$this->isConfig($flag)) {
            $this->config += $flag;
        }
    }
    /**
     * @param int $flag
     */
    public function remConfig(int $flag): void
    {
        $this->__formsCircleLease->assertActive();
        if ($this->isConfig($flag)) {
            $this->config -= $flag;
        }
    }
    /**
     * @param string $name
     *
     * @return self
     */
    public function setName(string $name): self
    {
        $this->__formsCircleLease->assertActive();
        $this->name = $name;
        return $this;
    }
    /**
     * @return string
     */
    public function getName(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->name;
    }
    /**
     * @param string $displayName
     *
     * @return self
     */
    public function setDisplayName(string $displayName): self
    {
        $this->__formsCircleLease->assertActive();
        $this->displayName = $displayName;
        return $this;
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
     * @param string $sanitizedName
     *
     * @return Circle
     */
    public function setSanitizedName(string $sanitizedName): self
    {
        $this->__formsCircleLease->assertActive();
        $this->sanitizedName = $sanitizedName;
        return $this;
    }
    /**
     * @return string
     */
    public function getSanitizedName(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->sanitizedName;
    }
    /**
     * @param int $source
     *
     * @return Circle
     */
    public function setSource(int $source): self
    {
        $this->__formsCircleLease->assertActive();
        $this->source = $source;
        return $this;
    }
    /**
     * @return int
     */
    public function getSource(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->source;
    }
    /**
     * @param ?Member $owner
     *
     * @return self
     */
    public function setOwner(?\OCA\Circles\Model\Member $owner): self
    {
        $this->__formsCircleLease->assertActive();
        $this->owner = $owner;
        return $this;
    }
    /**
     * @return Member
     */
    public function getOwner(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        return $this->owner;
    }
    /**
     * @return bool
     */
    public function hasOwner(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->owner);
    }
    /**
     * @return bool
     */
    public function hasMembers(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->members);
    }
    /**
     * @param Member[] $members
     *
     * @return self
     */
    public function setMembers(array $members): self
    {
        $this->__formsCircleLease->assertActive();
        $this->members = $members;
        return $this;
    }
    /**
     * @return Member[]
     */
    public function getMembers(int $limit = 0): array
    {
        $this->__formsCircleLease->assertActive();
        if (!$this->hasMembers()) {
            $this->getManager()->getMembers($this, $limit);
        }
        return $this->members;
    }
    /**
     * @param array $members
     * @param bool $detailed
     *
     * @return self
     */
    public function setInheritedMembers(array $members, bool $detailed): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritedMembers = $members;
        $this->detailedInheritedMember = $detailed;
        return $this;
    }
    /**
     * @param Member[] $members
     *
     * @return Circle
     */
    public function addInheritedMembers(array $members): self
    {
        $this->__formsCircleLease->assertActive();
        $knownIds = array_map(fn(\OCA\Circles\Model\Member $member): string => $member->getId(), $this->inheritedMembers);
        foreach ($members as $member) {
            if (!array_key_exists($member->getId(), $knownIds)) {
                $this->inheritedMembers[] = $member;
                $knownIds[] = $member->getId();
            }
        }
        return $this;
    }
    /**
     * if $remote is true, it will returns also details on inherited members from remote+locals Circles.
     * This should be used only if extra details are required (mail address ?) as it will send a request to
     * the remote instance if the circleId is not locally known.
     * because of the resource needed to retrieve this data, $remote=true should not be used on main process !
     *
     * @param bool $detailed
     * @param bool $remote
     *
     * @return Member[]
     * @throws FederatedItemException
     * @throws RemoteInstanceException
     * @throws RemoteNotFoundException
     * @throws RemoteResourceNotFoundException
     * @throws RequestBuilderException
     * @throws UnknownRemoteException
     */
    public function getInheritedMembers(bool $detailed = false, bool $remote = false): array
    {
        $this->__formsCircleLease->assertActive();
        if (is_null($this->inheritedMembers) || $detailed && !$this->detailedInheritedMember) {
            $this->getManager()->getInheritedMembers($this, $detailed);
        }
        if ($remote) {
            $this->getManager()->getRemoteInheritedMembers($this, $detailed);
        }
        return $this->inheritedMembers;
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
        if (!$this->hasMemberships()) {
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
        return $this->getManager()->getLink($this, $singleId, $detailed);
    }
    /**
     * @param Member|null $initiator
     *
     * @return Circle
     */
    public function setInitiator(?\OCA\Circles\Model\Member $initiator): self
    {
        $this->__formsCircleLease->assertActive();
        $this->initiator = $initiator;
        return $this;
    }
    /**
     * @return Member
     */
    public function getInitiator(): \OCA\Circles\Model\Member
    {
        $this->__formsCircleLease->assertActive();
        if (is_null($this->initiator) || $this->initiator->getId() === '' && !is_null($this->directInitiator) && $this->directInitiator->getId() !== '') {
            return $this->directInitiator;
        }
        return $this->initiator;
    }
    /**
     * @return bool
     */
    public function hasInitiator(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->initiator) || !is_null($this->directInitiator);
    }
    /**
     * @param Member|null $directInitiator
     *
     * @return $this
     */
    public function setDirectInitiator(?\OCA\Circles\Model\Member $directInitiator): self
    {
        $this->__formsCircleLease->assertActive();
        $this->directInitiator = $directInitiator;
        return $this;
    }
    /**
     * @param string $instance
     *
     * @return Circle
     */
    public function setInstance(string $instance): self
    {
        $this->__formsCircleLease->assertActive();
        if ($this->isConfig(self::CFG_NO_OWNER)) {
            $this->instance = $instance;
        }
        return $this;
    }
    /**
     * @return string
     * @throws OwnerNotFoundException
     */
    public function getInstance(): string
    {
        $this->__formsCircleLease->assertActive();
        if (!$this->hasOwner()) {
            throw new \OCA\Circles\Exceptions\OwnerNotFoundException('circle has no owner');
        }
        return $this->getOwner()->getInstance();
    }
    /**
     * @return bool
     * @throws OwnerNotFoundException
     */
    public function isLocal(): bool
    {
        $this->__formsCircleLease->assertActive();
        return $this->getManager()->isLocalInstance($this->getInstance());
    }
    /**
     * @param int $population
     *
     * @return Circle
     */
    public function setPopulation(int $population): self
    {
        $this->__formsCircleLease->assertActive();
        $this->population = $population;
        return $this;
    }
    /**
     * @return int
     */
    public function getPopulation(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->population;
    }
    /**
     * @param int $population
     *
     * @return Circle
     */
    public function setPopulationInherited(int $population): self
    {
        $this->__formsCircleLease->assertActive();
        $this->populationInherited = $population;
        return $this;
    }
    /**
     * @return int
     */
    public function getPopulationInherited(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->populationInherited;
    }
    /**
     * @param CircleInvitation|null $circleInvitation
     *
     * @return Circle
     */
    public function setCircleInvitation(?\OCA\Circles\Model\CircleInvitation $circleInvitation): self
    {
        $this->__formsCircleLease->assertActive();
        $this->circleInvitation = $circleInvitation;
        return $this;
    }
    /**
     * @return CircleInvitation|null
     */
    public function getCircleInvitation(): ?\OCA\Circles\Model\CircleInvitation
    {
        $this->__formsCircleLease->assertActive();
        return $this->circleInvitation;
    }
    /**
     * @param array $settings
     *
     * @return self
     */
    public function setSettings(array $settings): self
    {
        $this->__formsCircleLease->assertActive();
        $this->settings = $settings;
        return $this;
    }
    /**
     * @return array
     */
    public function getSettings(): array
    {
        $this->__formsCircleLease->assertActive();
        return $this->settings;
    }
    /**
     * @param string $description
     *
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->__formsCircleLease->assertActive();
        $this->description = $description;
        return $this;
    }
    /**
     * @return string
     */
    public function getDescription(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->description;
    }
    /**
     * @return string
     */
    public function getUrl(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->getManager()->generateLinkToCircle($this->getSingleId());
    }
    /**
     * @param int $contactAddressBook
     *
     * @return self
     */
    public function setContactAddressBook(int $contactAddressBook): self
    {
        $this->__formsCircleLease->assertActive();
        $this->contactAddressBook = $contactAddressBook;
        return $this;
    }
    /**
     * @return int
     */
    public function getContactAddressBook(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->contactAddressBook;
    }
    /**
     * @param string $contactGroupName
     *
     * @return self
     */
    public function setContactGroupName(string $contactGroupName): self
    {
        $this->__formsCircleLease->assertActive();
        $this->contactGroupName = $contactGroupName;
        return $this;
    }
    /**
     * @return string
     */
    public function getContactGroupName(): string
    {
        $this->__formsCircleLease->assertActive();
        return $this->contactGroupName;
    }
    /**
     * @param int $creation
     *
     * @return self
     */
    public function setCreation(int $creation): self
    {
        $this->__formsCircleLease->assertActive();
        $this->creation = $creation;
        return $this;
    }
    /**
     * @return int
     */
    public function getCreation(): int
    {
        $this->__formsCircleLease->assertActive();
        return $this->creation;
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
        if ($this->get('id', $data) === '') {
            throw new \OCA\Circles\Tools\Exceptions\InvalidItemException();
        }
        $this->setSingleId($this->get('id', $data))->setName($this->get('name', $data))->setDisplayName($this->get('displayName', $data))->setSanitizedName($this->get('sanitizedName', $data))->setSource($this->getInt('source', $data))->setConfig($this->getInt('config', $data))->setPopulation($this->getInt('population', $data))->setSettings($this->getArray('settings', $data))->setDescription($this->get('description', $data))->setCreation($this->getInt('creation', $data));
        try {
            /** @var Member $owner */
            $owner = $this->deserialize($this->getArray('owner', $data), \OCA\Circles\Model\Member::class);
            $this->setOwner($owner);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var Member $initiator */
            $initiator = $this->deserialize($this->getArray('initiator', $data), \OCA\Circles\Model\Member::class);
            $this->setInitiator($initiator);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var CircleInvitation $circleInvitation */
            $circleInvitation = $this->deserialize($this->getArray('invitation', $data), \OCA\Circles\Model\CircleInvitation::class);
            $this->setCircleInvitation($circleInvitation);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException $e) {
        }
        return $this;
    }
    /**
     * @return array
     * @throws FederatedItemException
     * @throws RemoteInstanceException
     * @throws RemoteNotFoundException
     * @throws RemoteResourceNotFoundException
     * @throws RequestBuilderException
     * @throws UnknownRemoteException
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['id' => $this->getSingleId(), 'name' => $this->getName(), 'displayName' => $this->getDisplayName(), 'sanitizedName' => $this->getSanitizedName(), 'source' => $this->getSource(), 'population' => $this->getPopulation(), 'populationInherited' => $this->getPopulationInherited(), 'config' => $this->getConfig(), 'description' => $this->getDescription(), 'url' => $this->getUrl(), 'creation' => $this->getCreation(), 'initiator' => $this->hasInitiator() ? $this->getInitiator() : null];
        if ($this->hasOwner()) {
            $arr['owner'] = $this->getOwner();
        }
        if ($this->hasMembers()) {
            $arr['members'] = $this->getMembers();
        }
        if (!is_null($this->inheritedMembers)) {
            $arr['inheritedMembers'] = $this->getInheritedMembers();
        }
        if ($this->hasMemberships()) {
            $arr['memberships'] = $this->getMemberships();
        }
        // settings should only be available to admin
        if ($this->hasInitiator()) {
            $initiatorHelper = new \OCA\Circles\Model\Helpers\MemberHelper($this->getInitiator());
            try {
                $initiatorHelper->mustBeAdmin();
                $arr['settings'] = $this->getSettings();
                $arr['invitationCode'] = $this->getCircleInvitation()?->getInvitationCode();
            } catch (\OCA\Circles\Exceptions\MemberHelperException|\OCA\Circles\Exceptions\MemberLevelException) {
            }
        }
        return $arr;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws CircleNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'unique_id', $data) === '') {
            throw new \OCA\Circles\Exceptions\CircleNotFoundException();
        }
        $this->setSingleId($this->get($prefix . 'unique_id', $data))->setName($this->get($prefix . 'name', $data))->setDisplayName($this->get($prefix . 'display_name', $data))->setSanitizedName($this->get($prefix . 'sanitized_name', $data))->setConfig($this->getInt($prefix . 'config', $data))->setSource($this->getInt($prefix . 'source', $data))->setInstance($this->get($prefix . 'instance', $data))->setSettings($this->getArray($prefix . 'settings', $data))->setContactAddressBook($this->getInt($prefix . 'contact_addressbook', $data))->setContactGroupName($this->get($prefix . 'contact_groupname', $data))->setDescription($this->get($prefix . 'description', $data));
        $creation = $this->get($prefix . 'creation', $data);
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $creation);
        $timestamp = $dateTime ? $dateTime->getTimestamp() : (int) strtotime($creation);
        $this->setCreation($timestamp);
        $this->setPopulation($this->getInt('population', $this->getSettings()));
        $this->setPopulationInherited($this->getInt('populationInherited', $this->getSettings()));
        $this->getManager()->manageImportFromDatabase($this, $data, $prefix);
        // TODO: deprecated in NC27, remove those (17) lines that was needed to finalise migration to 24
        // if password is not hashed (pre-22), hash it and update new settings in DB
        $curr = $this->get('password_single', $this->getSettings());
        if (strlen($curr) >= 1 && strlen($curr) < 64) {
            $hasher = $this->__formsCircleLease->service(\OCP\Security\IHasher::class);
            $circleRequest = $this->__formsCircleLease->service(\OCA\Circles\Db\CircleRequest::class);
            $new = $hasher->hash($curr);
            $settings = $this->getSettings();
            $settings['password_single'] = $new;
            $this->setSettings($settings);
            $circleRequest->updateSettings($this);
        }
        // END deprecated NC27
        return $this;
    }
    /**
     * @param Circle $circle
     *
     * @return bool
     * @throws OwnerNotFoundException
     */
    public function compareWith(\OCA\Circles\Model\Circle $circle): bool
    {
        $this->__formsCircleLease->assertActive();
        if ($this->getSingleId() !== $circle->getSingleId() || $this->getInstance() !== $circle->getInstance() || $this->getConfig() !== $circle->getConfig()) {
            return false;
        }
        if ($this->hasOwner() && (!$circle->hasOwner() || !$this->getOwner()->compareWith($circle->getOwner()))) {
            return false;
        }
        if ($this->hasInitiator() && (!$circle->hasInitiator() || !$this->getInitiator()->compareWith($circle->getInitiator()))) {
            return false;
        }
        return true;
    }
    /**
     * @param Circle $circle
     * @param int $display
     *
     * @return array
     */
    public static function getCircleFlags(\OCA\Circles\Model\Circle $circle, int $display = self::FLAGS_LONG): array
    {
        $config = [];
        foreach (array_keys(\OCA\Circles\Model\Circle::$DEF_CFG) as $def) {
            if ($circle->isConfig($def)) {
                [$short, $long] = explode('|', (string) \OCA\Circles\Model\Circle::$DEF_CFG[$def]);
                switch ($display) {
                    case self::FLAGS_SHORT:
                        $config[] = $short;
                        break;
                    case self::FLAGS_LONG:
                        $config[] = $long;
                        break;
                }
            }
        }
        return $config;
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
