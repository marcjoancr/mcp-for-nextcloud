<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

declare (strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/**
 * Class Member
 *
 * @package OCA\Circles\Model
 */
final class MemberView extends \OCA\Circles\Model\Member
{
    public function __construct(array $data)
    {
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        $this->data = $data;
    }
    /**
     * @param string $id
     *
     * @return $this
     */
    public function setId(string $id): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getId(): string
    {
        return $this->data["member_id"];
    }
    /**
     * @param string $circleId
     *
     * @return Member
     */
    public function setCircleId(string $circleId): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getCircleId(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getSingleId(): string
    {
        return $this->data["single_id"];
    }
    /**
     * @param string $userId
     *
     * @return Member
     */
    public function setUserId(string $userId): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getUserId(): string
    {
        return $this->data["uid"];
    }
    /**
     * @param int $userType
     *
     * @return Member
     */
    public function setUserType(int $userType): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getUserType(): int
    {
        return $this->data["type"];
    }
    /**
     * @return int
     * @deprecated 22.0.0 Use `getUserType()` instead
     */
    public function getType(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $instance
     *
     * @return Member
     */
    public function setInstance(string $instance): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getInstance(): string
    {
        return $this->data["instance"];
    }
    /**
     * @return bool
     */
    public function isLocal(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param FederatedUser $invitedBy
     *
     * @return Member
     */
    public function setInvitedBy(\OCA\Circles\Model\FederatedUser $invitedBy): \OCA\Circles\Model\Member
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return FederatedUser
     */
    public function getInvitedBy(): \OCA\Circles\Model\FederatedUser
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasInvitedBy(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasRemoteInstance(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param RemoteInstance $remoteInstance
     *
     * @return Member
     */
    public function setRemoteInstance(\OCA\Circles\Model\Federated\RemoteInstance $remoteInstance): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return RemoteInstance
     */
    public function getRemoteInstance(): \OCA\Circles\Model\Federated\RemoteInstance
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasBasedOn(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param ?Circle $basedOn
     *
     * @return $this
     */
    public function setBasedOn(?\OCA\Circles\Model\Circle $basedOn): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return ?Circle
     */
    public function getBasedOn(): ?\OCA\Circles\Model\Circle
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasInheritedBy(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param FederatedUser $inheritedBy
     *
     * @return $this
     */
    public function setInheritedBy(\OCA\Circles\Model\FederatedUser $inheritedBy): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return FederatedUser
     */
    public function getInheritedBy(): \OCA\Circles\Model\FederatedUser
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasInheritanceFrom(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Member $inheritanceFrom
     *
     * @return $this
     */
    public function setInheritanceFrom(\OCA\Circles\Model\Member $inheritanceFrom): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Member|null
     */
    public function getInheritanceFrom(): ?\OCA\Circles\Model\Member
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $level
     *
     * @return Member
     */
    public function setLevel(int $level): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getLevel(): int
    {
        return $this->data["level"];
    }
    /**
     * @param string $status
     *
     * @return Member
     */
    public function setStatus(string $status): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getStatus(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param array $notes
     *
     * @return Member
     */
    public function setNotes(array $notes): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return array
     */
    public function getNotes(): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $key
     *
     * @return string
     */
    public function getNote(string $key): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $key
     *
     * @return array
     */
    public function getNoteArray(string $key): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $key
     * @param string $note
     *
     * @return $this
     */
    public function setNote(string $key, string $note): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $key
     * @param array $note
     *
     * @return $this
     */
    public function setNoteArray(string $key, array $note): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $key
     * @param JsonSerializable $obj
     *
     * @return $this
     */
    public function setNoteObj(string $key, \JsonSerializable $obj): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $displayName
     *
     * @return $this
     */
    public function setDisplayName(string $displayName): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $displayUpdate
     *
     * @return Member
     */
    public function setDisplayUpdate(int $displayUpdate): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getDisplayUpdate(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $contactId
     *
     * @return Member
     */
    public function setContactId(string $contactId): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getContactId(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $contactMeta
     *
     * @return Member
     */
    public function setContactMeta(string $contactMeta): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getContactMeta(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Circle $circle
     *
     * @return self
     */
    public function setCircle(\OCA\Circles\Model\Circle $circle): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Circle
     */
    public function getCircle(): \OCA\Circles\Model\Circle
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasCircle(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $joined
     *
     * @return Member
     */
    public function setJoined(int $joined): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getJoined(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasMemberships(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param array $memberships
     *
     * @return self
     */
    public function setMemberships(array $memberships): \OCA\Circles\IEntity
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Membership[]
     */
    public function getMemberships(): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Member $member
     * @param bool $full
     *
     * @return bool
     */
    public function compareWith(\OCA\Circles\Model\Member $member, bool $full = true): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param array $data
     *
     * @return $this
     * @throws InvalidItemException
     */
    public function import(array $data): \OCA\Circles\Tools\IDeserializable
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string[]
     * @throws UnknownInterfaceException
     */
    public function jsonSerialize(): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param JsonSerializable $model
     *
     * @return array
     */
    public function serialize(\JsonSerializable $model): array
    {
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
    }
    /**
     * @param array $data
     *
     * @return array
     */
    public function serializeArray(array $data): array
    {
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
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
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
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
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
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
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
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
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
    }
    /**
     * @param string $k
     * @param array $arr
     *
     * @return bool
     */
    public function validKey(string $k, array $arr): bool
    {
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
    }
    /**
     * @param string $k
     * @param array $arr
     * @param array $import
     * @param array $default
     *
     * @return array
     */
    protected function getList(string $k, array $arr, array $import, array $default = []): array
    {
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
    }
    /**
     * @param string $key
     * @param array $arr
     * @param bool $root
     *
     * @return string
     * @throws ItemNotFoundException
     * @throws UnknownTypeException
     */
    public function typeOf(string $key, array $arr, bool $root = true): string
    {
        throw new \RuntimeException("Materialized Circle view cannot deserialize");
    }
    private array $data;
    final protected function getManager(): \OCA\Circles\Model\ModelManager
    {
        throw new \RuntimeException("Materialized Circle view has no model manager");
    }
}
