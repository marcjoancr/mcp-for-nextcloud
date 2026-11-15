<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class ActivityService extends \OCA\Circles\Service\ActivityService {
public function __construct() {}/**
 * @param Circle $circle
 */
public function onCircleCreation(\OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param Circle $circle
 */
public function onCircleDestruction(\OCA\Circles\Model\Circle $circle): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param Circle $circle
 * @param Member $member
 * @param int $eventType
 */
public function onMemberNew(\OCA\Circles\Model\Circle $circle, \OCA\Circles\Model\Member $member, int $eventType): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param Circle $circle
 * @param Member $member
 * @param int $eventType
 */
public function onMemberRemove(\OCA\Circles\Model\Circle $circle, \OCA\Circles\Model\Member $member, int $eventType): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param Circle $circle
 * @param Member $member
 * @param int $level
 */
public function onMemberLevel(\OCA\Circles\Model\Circle $circle, \OCA\Circles\Model\Member $member, int $level): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
/**
 * @param Circle $circle
 * @param Member $member
 */
public function onMemberOwner(\OCA\Circles\Model\Circle $circle, \OCA\Circles\Model\Member $member): void
{
    throw new \RuntimeException("Circle event dependency method is not qualified");
}
public function onShareNew(\OCA\Circles\Model\Circle $getCircle, \OCA\Circles\Model\Federated\FederatedEvent $federatedEvent): void
{
}
}
