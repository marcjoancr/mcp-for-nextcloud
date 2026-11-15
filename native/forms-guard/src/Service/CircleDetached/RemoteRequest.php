<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class RemoteRequest extends \OCA\Circles\Db\RemoteRequest {
private Lease $lease;
public function __construct(Lease $lease) {$this->lease=$lease;$this->configService=$lease->config();}
public function getQueryBuilder(): \OCA\Circles\Db\CoreQueryBuilder {return $this->lease->query();}
public function cleanDatabase(bool $shares=false): void {throw new \RuntimeException('Circle read graph cannot write');}
public function uninstall(): void {throw new \RuntimeException('Circle read graph cannot write');}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function save(\OCA\Circles\Model\Federated\RemoteInstance $remote): void
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function update(\OCA\Circles\Model\Federated\RemoteInstance $remote)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function updateItem(\OCA\Circles\Model\Federated\RemoteInstance $remote)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function updateInstance(\OCA\Circles\Model\Federated\RemoteInstance $remote)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function updateType(\OCA\Circles\Model\Federated\RemoteInstance $remote)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remote
 *
 * @throws RemoteUidException
 */
public function updateHref(\OCA\Circles\Model\Federated\RemoteInstance $remote)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
/**
 * @param RemoteInstance $remoteInstance
 */
public function deleteById(\OCA\Circles\Model\Federated\RemoteInstance $remoteInstance)
{
    throw new \RuntimeException("Circle read request cannot mutate");
}
public function uninstallAppTables() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromMigrations() {throw new \RuntimeException("Circle read request cannot uninstall");}public function uninstallFromJobs() {throw new \RuntimeException("Circle read request cannot uninstall");}}
