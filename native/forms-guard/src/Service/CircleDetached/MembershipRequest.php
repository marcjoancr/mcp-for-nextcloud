<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class MembershipRequest extends \OCA\Circles\Db\MembershipRequest {
private Lease $lease;
public function __construct(Lease $lease) {$this->lease=$lease;$this->configService=$lease->config();}
public function getQueryBuilder(): \OCA\Circles\Db\CoreQueryBuilder {return $this->lease->query();}
public function cleanDatabase(bool $shares=false): void {throw new \RuntimeException('Circle read graph cannot write');}
public function uninstall(): void {throw new \RuntimeException('Circle read graph cannot write');}
/**
 * @param Membership $membership
 */
public function insert(\OCA\Circles\Model\Membership $membership)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Membership $membership
 */
public function update(\OCA\Circles\Model\Membership $membership)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param string $singleId
 * @param bool $all
 *
 * @return void
 */
public function removeBySingleId(string $singleId, bool $all = false): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param Membership $membership
 */
public function delete(\OCA\Circles\Model\Membership $membership): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param FederatedUser $federatedUser
 */
public function deleteFederatedUser(\OCA\Circles\Model\FederatedUser $federatedUser): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
public function uninstallAppTables() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromMigrations() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromJobs() {throw new \RuntimeException("Circle read request cannot uninstall");}}
