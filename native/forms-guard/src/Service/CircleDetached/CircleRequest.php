<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class CircleRequest extends \OCA\Circles\Db\CircleRequest {
private Lease $lease;
public function __construct(Lease $lease) {$this->lease=$lease;$this->configService=$lease->config();}
public function getQueryBuilder(): \OCA\Circles\Db\CoreQueryBuilder {return $this->lease->query();}
/** Fresh pinned native query only; this bridge never hydrates the team result or copies ACL logic. */
public function userTeamQuery(string $uid): CoreQueryBuilder {
    $this->lease->checkpoint();
    $initiator = $this->lease->identity($uid);
    $probe = new \OCA\Circles\Model\Probes\CircleProbe();
    $probe->filterHiddenCircles()->filterBackendCircles();
    $data = new \OCA\Circles\Model\Probes\DataProbe();
    // Native probeCircles delegates to this private builder before parsing models.
    // SourceGate pins its complete source; all virtual query factories remain owned by this lease.
    $builder = new \ReflectionMethod(\OCA\Circles\Db\CircleRequest::class, 'buildProbeCircle');
    if (!$builder->isPrivate() || $builder->isStatic()
        || $builder->getDeclaringClass()->getName() !== \OCA\Circles\Db\CircleRequest::class) {
        throw new \RuntimeException('Circle team query builder is not qualified');
    }
    $query = $builder->invoke($this, $initiator, $probe, $data);
    if (!$query instanceof CoreQueryBuilder) {throw new \RuntimeException('Circle team query escaped its lease');}
    $query->chunk($probe->getItemsOffset(), $probe->getItemsLimit());
    return $query;
}
public function cleanDatabase(bool $shares=false): void {throw new \RuntimeException('Circle read graph cannot write');}
public function uninstall(): void {throw new \RuntimeException('Circle read graph cannot write');}
/**
 * @param string $singleId
 *
 * @return FederatedUser
 * @throws OwnerNotFoundException
 * @throws RequestBuilderException
 * @throws FederatedUserNotFoundException
 */
public function getFederatedUserBySingleId(string $singleId): \OCA\Circles\Model\FederatedUser
{
    $qb = $this->getCircleSelectSql(\OCA\Circles\Db\CoreQueryBuilder::CIRCLE, true);
    $qb->limitToUniqueId($singleId);
    $qb->leftJoinOwner(\OCA\Circles\Db\CoreQueryBuilder::CIRCLE);
    try {
        $circle = $this->getItemFromRequest($qb);
    } catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
        throw new \OCA\Circles\Exceptions\FederatedUserNotFoundException('singleId not found');
    }
    $federatedUser = $this->lease->model(\OCA\Circles\Model\FederatedUser::class);
    $federatedUser->importFromCircle($circle);
    return $federatedUser;
}
/**
 * method that return the single-user Circle based on a FederatedUser.
 *
 * @param IFederatedUser $initiator
 *
 * @return Circle
 * @throws SingleCircleNotFoundException
 * @throws RequestBuilderException
 */
public function getSingleCircle(\OCA\Circles\IFederatedUser $initiator): \OCA\Circles\Model\Circle
{
    $qb = $this->getCircleSelectSql(\OCA\Circles\Db\CoreQueryBuilder::SINGLE, true);
    if ($initiator instanceof \OCA\Circles\Model\FederatedUser) {
        $member = $this->lease->model(\OCA\Circles\Model\Member::class);
        $member->importFromIFederatedUser($initiator);
        $member->setLevel(\OCA\Circles\Model\Member::LEVEL_OWNER);
    } else {
        $member = $this->lease->copyMember($initiator);
    }
    $qb->limitToDirectMembership(\OCA\Circles\Db\CoreQueryBuilder::SINGLE, $member);
    $qb->limitToConfigFlag(\OCA\Circles\Model\Circle::CFG_SINGLE);
    try {
        return $this->getItemFromRequest($qb);
    } catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
        throw new \OCA\Circles\Exceptions\SingleCircleNotFoundException();
    }
}
/**
 * @param Circle $circle
 *
 * @throws InvalidIdException
 */
public function save(\OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Circle $circle
 */
public function edit(\OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Circle $circle
 */
public function update(\OCA\Circles\Model\Circle $circle)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Circle $circle
 *
 * @throws InvalidIdException
 */
public function insertOrUpdate(\OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param string $singleId
 * @param string $displayName
 */
public function updateDisplayName(string $singleId, string $displayName): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Circle $circle
 */
public function updateConfig(\OCA\Circles\Model\Circle $circle)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Circle $circle
 */
public function updateSettings(\OCA\Circles\Model\Circle $circle)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * get data about single Circle.
 *
 * - CircleProbe is used to confirm the visibility of the targeted circle,
 * - DataProbe is used to define the complexity of the data to be returned for each entry of the list
 *
 * @param string $singleId
 * @param IFederatedUser|null $initiator
 * @param CircleProbe $circleProbe
 * @param DataProbe $dataProbe
 *
 * @return Circle
 * @throws CircleNotFoundException
 * @throws RequestBuilderException
 */
public function probeCircle(string $singleId, ?\OCA\Circles\IFederatedUser $initiator, \OCA\Circles\Model\Probes\CircleProbe $circleProbe, \OCA\Circles\Model\Probes\DataProbe $dataProbe): \OCA\Circles\Model\Circle
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * get data about multiple Circles.
 *
 * - CircleProbe is used to define the list of circles to be returned by the method,
 * - DataProbe is used to define the complexity of the data to be returned for each entry of the list
 *
 * @param IFederatedUser|null $initiator
 * @param CircleProbe $circleProbe
 * @param DataProbe $dataProbe
 *
 * @return list<Circle>
 * @throws RequestBuilderException
 */

/**
 * returns details about circles using a list of ids.
 * initiator permissions are respected
 */

/**
 * @param Circle $circle
 */
public function delete(\OCA\Circles\Model\Circle $circle): void
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
public function uninstallAppTables() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromMigrations() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromJobs() {throw new \RuntimeException("Circle read request cannot uninstall");}}
