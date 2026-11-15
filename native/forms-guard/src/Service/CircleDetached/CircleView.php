<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
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
final class CircleView extends \OCA\Circles\Model\Circle
{
    public function __construct(array $data)
    {
        \OCA\Forms\Mcp\Service\CircleDetached\RawPage::validate([$data]);
        $this->data = $data;
    }
    /**
     * @param string $singleId
     *
     * @return self
     */
    public function setSingleId(string $singleId): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getSingleId(): string
    {
        return $this->data["id"];
    }
    /**
     * @return string
     * @deprecated - removed in NC23
     */
    public function getUniqueId(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $config
     *
     * @return self
     */
    public function setConfig(int $config): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getConfig(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $flag
     * @param int $test
     *
     * @return bool
     */
    public function isConfig(int $flag, int $test = 0): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $flag
     */
    public function addConfig(int $flag): void
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $flag
     */
    public function remConfig(int $flag): void
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $name
     *
     * @return self
     */
    public function setName(string $name): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getName(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $displayName
     *
     * @return self
     */
    public function setDisplayName(string $displayName): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getDisplayName(): string
    {
        return $this->data["display_name"];
    }
    /**
     * @param string $sanitizedName
     *
     * @return Circle
     */
    public function setSanitizedName(string $sanitizedName): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getSanitizedName(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $source
     *
     * @return Circle
     */
    public function setSource(int $source): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getSource(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param ?Member $owner
     *
     * @return self
     */
    public function setOwner(?\OCA\Circles\Model\Member $owner): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Member
     */
    public function getOwner(): \OCA\Circles\Model\Member
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasOwner(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasMembers(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Member[] $members
     *
     * @return self
     */
    public function setMembers(array $members): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Member[]
     */
    public function getMembers(int $limit = 0): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param array $members
     * @param bool $detailed
     *
     * @return self
     */
    public function setInheritedMembers(array $members, bool $detailed): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Member[] $members
     *
     * @return Circle
     */
    public function addInheritedMembers(array $members): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getInheritedMembers(bool $detailed = false, bool $remote = false): array
    {
        if ($detailed || $remote) {
            throw new \RuntimeException("Extra Circle details require a new owned read");
        }
        if (!isset($this->data["members"]) || !is_array($this->data["members"])) {throw new \RuntimeException("Circle profile does not contain a membership projection");}
        return array_map(static fn(array $row): \OCA\Circles\Model\Member => new MemberView($row), $this->data["members"]);
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
     * @param Member|null $initiator
     *
     * @return Circle
     */
    public function setInitiator(?\OCA\Circles\Model\Member $initiator): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return Member
     */
    public function getInitiator(): \OCA\Circles\Model\Member
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     */
    public function hasInitiator(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Member|null $directInitiator
     *
     * @return $this
     */
    public function setDirectInitiator(?\OCA\Circles\Model\Member $directInitiator): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $instance
     *
     * @return Circle
     */
    public function setInstance(string $instance): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     * @throws OwnerNotFoundException
     */
    public function getInstance(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return bool
     * @throws OwnerNotFoundException
     */
    public function isLocal(): bool
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $population
     *
     * @return Circle
     */
    public function setPopulation(int $population): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getPopulation(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $population
     *
     * @return Circle
     */
    public function setPopulationInherited(int $population): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getPopulationInherited(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param CircleInvitation|null $circleInvitation
     *
     * @return Circle
     */
    public function setCircleInvitation(?\OCA\Circles\Model\CircleInvitation $circleInvitation): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return CircleInvitation|null
     */
    public function getCircleInvitation(): ?\OCA\Circles\Model\CircleInvitation
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param array $settings
     *
     * @return self
     */
    public function setSettings(array $settings): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return array
     */
    public function getSettings(): array
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $description
     *
     * @return self
     */
    public function setDescription(string $description): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getDescription(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    public function getUrl(): string
    {
        return $this->data["url"];
    }
    /**
     * @param int $contactAddressBook
     *
     * @return self
     */
    public function setContactAddressBook(int $contactAddressBook): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getContactAddressBook(): int
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param string $contactGroupName
     *
     * @return self
     */
    public function setContactGroupName(string $contactGroupName): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return string
     */
    public function getContactGroupName(): string
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param int $creation
     *
     * @return self
     */
    public function setCreation(int $creation): self
    {
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @return int
     */
    public function getCreation(): int
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
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
        throw new \RuntimeException("Materialized Circle view has no lazy or mutation authority");
    }
    /**
     * @param Circle $circle
     *
     * @return bool
     * @throws OwnerNotFoundException
     */
    public function compareWith(\OCA\Circles\Model\Circle $circle): bool
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
