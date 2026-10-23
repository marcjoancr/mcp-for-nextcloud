<?php
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/CirclesService.php
// SHA-256: 5ef53b05da163f08f6976a9f87ebae8f2b70cc42660dc08cbaf3b1e8ae0f99e0


/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Deck\Service;

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Probes\CircleProbe;
use OCP\App\IAppManager;
use OCP\Server;
use Throwable;
/**
 * Wrapper around circles app API since it is not in a public namespace so we need to make sure that
 * having the app disabled is properly handled
 */
class CirclesService
{
    public function __construct(IAppManager $appManager)
    {
    }
    public function isCirclesEnabled(): bool
    {
    }
    public function getCircle(string $circleId): ?Circle
    {
    }
    public function isUserInCircle(string $circleId, string $userId): bool
    {
    }
    /**
     * @param string $userId
     * @return string[] circle single ids
     */
    public function getUserCircles(string $userId): array
    {
    }
}
