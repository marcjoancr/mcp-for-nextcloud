<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class MountRequest extends \OCA\Circles\Db\MountRequest {
public function __construct() {}/**
 * @param Mount $mount
 */
public function save(\OCA\Circles\Model\Mount $mount): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
public function delete(string $token): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
public function hasMountForCircleId(string $circleId): bool
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
public function deleteByCircleId(string $circleId): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param list<string> $paths
 *
 * @return Mount[]
 * @throws RequestBuilderException
 */
public function getForUser(\OCA\Circles\IFederatedUser $federatedUser, array $paths = [], bool $forChildren = false): array
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
public function getQueryBuilder(): \OCA\Circles\Db\CoreQueryBuilder {throw new \RuntimeException("Remote mount is not qualified");} public function uninstall(): void {throw new \RuntimeException("Circle uninstall is not qualified");}public function cleanDatabase(bool $shares=false): void {throw new \RuntimeException('Remote mount is not qualified');}
public function uninstallAppTables() {throw new \RuntimeException('Remote mount is not qualified');}
public function uninstallFromMigrations() {throw new \RuntimeException('Remote mount is not qualified');}
public function uninstallFromJobs() {throw new \RuntimeException('Remote mount is not qualified');}
}
