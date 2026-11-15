<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class MemberRequest extends \OCA\Circles\Db\MemberRequest {
private Lease $lease;
public function __construct(Lease $lease) {$this->lease=$lease;$this->configService=$lease->config();}
public function getQueryBuilder(): \OCA\Circles\Db\CoreQueryBuilder {return $this->lease->query();}
/** Native getInheritedMembers($id, false, 0), ending before hydration. */
public function inheritedUserQuery(string $singleId): CoreQueryBuilder {
    $qb = $this->getMemberSelectSql(null, false);
    $qb->limitToMembersByInheritance(CoreQueryBuilder::MEMBER, $singleId, 0);
    $aliasMembership = $qb->generateAlias(CoreQueryBuilder::MEMBER, CoreQueryBuilder::MEMBERSHIPS);
    $qb->orderBy($aliasMembership . '.inheritance_depth', 'asc');
    return $qb;
}
public function cleanDatabase(bool $shares=false): void {throw new \RuntimeException('Circle read graph cannot write');}
public function uninstall(): void {throw new \RuntimeException('Circle read graph cannot write');}
/**
 * @param Member $member
 *
 * @throws InvalidIdException
 */
public function save(\OCA\Circles\Model\Member $member): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Member $member
 *
 * @throws InvalidIdException
 */
public function update(\OCA\Circles\Model\Member $member): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Member $member
 *
 * @throws InvalidIdException
 * @throws RequestBuilderException
 */
public function insertOrUpdate(\OCA\Circles\Model\Member $member): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param string $singleId
 * @param string $displayName
 * @param string $circleId
 */
public function updateDisplayName(string $singleId, string $displayName, string $circleId = ''): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Member $member
 */
public function delete(\OCA\Circles\Model\Member $member)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param IFederatedUser $federatedUser
 */
public function deleteFederatedUser(\OCA\Circles\IFederatedUser $federatedUser): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param IFederatedUser $federatedUser
 * @param Circle $circle
 */
public function deleteFederatedUserFromCircle(\OCA\Circles\IFederatedUser $federatedUser, \OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 *
 * @param Circle $circle
 */
public function deleteAllFromCircle(\OCA\Circles\Model\Circle $circle)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Member $member
 */
public function updateLevel(\OCA\Circles\Model\Member $member): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @return ?Member old owner or null if old owner was not found
 */
public function promoteToOwner(\OCA\Circles\Model\Member $member): ?\OCA\Circles\Model\Member
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
public function uninstallAppTables() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromMigrations() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromJobs() {throw new \RuntimeException("Circle read request cannot uninstall");}}
