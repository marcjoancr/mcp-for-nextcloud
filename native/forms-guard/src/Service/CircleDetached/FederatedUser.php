<?php
/* UNAPPLIED R2 proposal. Native source: Model/FederatedUser.php SHA256 5f6072450e381fe632b0a0a4727e444ced7525a8150f782f53c6e0babb544313
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class FederatedUser
 *
 * @package OCA\Circles\Model
 */
final class FederatedUser extends \OCA\Circles\Model\FederatedUser
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
    /** @var string */
    private $singleId = '';
    /** @var string */
    private $userId;
    /** @var int */
    private $userType;
    /** @var string */
    private $displayName = '';
    /** @var ?Circle */
    private $basedOn;
    /** @var int */
    private $config = 0;
    /** @var string */
    private $instance;
    /** @var Membership */
    private $inheritance;
    /** @var Membership[] */
    private $memberships = null;
    /**
     * FederatedUser constructor.
     */
    public function __construct()
    {
    }
    /**
     * @param string $userId
     * @param string $instance
     * @param int $type
     * @param string $displayName
     * @param Circle|null $basedOn
     *
     * @return $this
     */
    public function set(string $userId, string $instance = '', int $type = \OCA\Circles\Model\Member::TYPE_USER, string $displayName = '', ?\OCA\Circles\Model\Circle $basedOn = null): self
    {
        $this->__formsCircleLease->assertActive();
        $this->userId = $userId;
        $this->setInstance($instance);
        $this->userType = $type;
        $this->displayName = $displayName === '' ? $userId : $displayName;
        $this->basedOn = $basedOn;
        return $this;
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
     * @param string $userId
     *
     * @return self
     */
    public function setUserId(string $userId): self
    {
        $this->__formsCircleLease->assertActive();
        $this->userId = $userId;
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
     * @return self
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
     * @param string $displayName
     *
     * @return FederatedUser
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
     * @param string $instance
     *
     * @return self
     */
    public function setInstance(string $instance): self
    {
        $this->__formsCircleLease->assertActive();
        if ($instance === '') {
            // TODO: is it needed ?
            $instance = $this->getManager()->getLocalInstance();
        }
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
     * @return bool
     */
    public function hasInheritance(): bool
    {
        $this->__formsCircleLease->assertActive();
        return !is_null($this->inheritance);
    }
    /**
     * @param Membership $inheritance
     *
     * @return $this
     */
    public function setInheritance(\OCA\Circles\Model\Membership $inheritance): self
    {
        $this->__formsCircleLease->assertActive();
        $this->inheritance = $inheritance;
        return $this;
    }
    /**
     * @return Membership
     */
    public function getInheritance(): \OCA\Circles\Model\Membership
    {
        $this->__formsCircleLease->assertActive();
        return $this->inheritance;
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
        $this->setSingleId($this->get('id', $data));
        $this->setUserId($this->get('userId', $data));
        $this->setUserType($this->getInt('userType', $data));
        $this->setDisplayName($this->get('displayName', $data));
        $this->setInstance($this->get('instance', $data));
        //$this->setMemberships($this->getArray('memberships'));
        try {
            /** @var Circle $circle */
            $circle = $this->deserialize($this->getArray('basedOn', $data), \OCA\Circles\Model\Circle::class);
            $this->setBasedOn($circle);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        try {
            /** @var Membership $membership */
            $membership = $this->deserialize($this->getArray('membership', $data), \OCA\Circles\Model\Membership::class);
            $this->setInheritance($membership);
        } catch (\OCA\Circles\Tools\Exceptions\InvalidItemException) {
        }
        return $this;
    }
    /**
     * @param Circle $circle
     *
     * @return FederatedUser
     * @throws OwnerNotFoundException
     */
    public function importFromCircle(\OCA\Circles\Model\Circle $circle): self
    {
        $this->__formsCircleLease->assertActive();
        if (!$circle->hasOwner()) {
            throw new \OCA\Circles\Exceptions\OwnerNotFoundException();
        }
        $this->setSingleId($circle->getSingleId());
        if ($circle->isConfig(\OCA\Circles\Model\Circle::CFG_SINGLE)) {
            $owner = $circle->getOwner();
            $this->set($owner->getUserId(), $owner->getInstance(), $owner->getUserType(), $owner->getDisplayName(), $circle);
        } else {
            $this->set($circle->getDisplayName(), $circle->getInstance(), \OCA\Circles\Model\Member::TYPE_CIRCLE, $circle->getDisplayName(), $circle);
        }
        return $this;
    }
    /**
     * @param array $data
     * @param string $prefix
     *
     * @return IQueryRow
     * @throws FederatedUserNotFoundException
     */
    public function importFromDatabase(array $data, string $prefix = ''): \OCA\Circles\Tools\Db\IQueryRow
    {
        $this->__formsCircleLease->assertActive();
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        if ($this->get($prefix . 'single_id', $data) === '') {
            throw new \OCA\Circles\Exceptions\FederatedUserNotFoundException();
        }
        $this->setSingleId($this->get($prefix . 'single_id', $data));
        $this->setUserId($this->get($prefix . 'user_id', $data));
        $this->setUserType($this->getInt($prefix . 'user_type', $data));
        $this->setDisplayName($this->get($prefix . 'cached_name', $data));
        $this->setInstance($this->get($prefix . 'instance', $data));
        $this->getManager()->manageImportFromDatabase($this, $data, $prefix);
        return $this;
    }
    /**
     * @return string[]
     * @throws UnknownInterfaceException
     */
    public function jsonSerialize(): array
    {
        $this->__formsCircleLease->assertActive();
        $arr = ['id' => $this->getSingleId(), 'userId' => $this->getUserId(), 'userType' => $this->getUserType(), 'displayName' => $this->getDisplayName(), 'instance' => $this->getManager()->fixInstance($this->getInstance())];
        if ($this->hasBasedOn()) {
            $arr['basedOn'] = $this->getBasedOn();
        }
        if ($this->hasInheritance()) {
            $arr['inheritance'] = $this->getInheritance();
        }
        if (!is_null($this->memberships)) {
            $arr['memberships'] = $this->getMemberships();
        }
        return $arr;
    }
    /**
     * @param IFederatedUser $member
     *
     * @return bool
     */
    public function compareWith(\OCA\Circles\IFederatedUser $member): bool
    {
        $this->__formsCircleLease->assertActive();
        $local = $this->getManager()->isLocalInstance($this->getInstance()) && $this->getManager()->isLocalInstance($member->getInstance());
        return !($this->getSingleId() !== $member->getSingleId() || $this->getUserId() !== $member->getUserId() || $this->getUserType() != $member->getUserType() || !$local && $this->getInstance() !== $member->getInstance());
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
