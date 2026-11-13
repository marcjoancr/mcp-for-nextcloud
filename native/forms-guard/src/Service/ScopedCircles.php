<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
/** Deferred Circle access awaits an isolated native Circle session contract. Never borrow cron's Circle context. */
final class ScopedCircles extends \OCA\Forms\Service\CirclesService {
    public function __construct() {}
    public function isCirclesEnabled(): bool {return false;}
    public function getCircle(string $circleId): ?\OCA\Circles\Model\Circle {throw new \RuntimeException('Deferred Circle effects are not qualified');}
    public function isUserInCircle(string $circleId,string $userId): bool {return false;}
    public function getCircleUsers(string $circleId): array {throw new \RuntimeException('Deferred Circle effects are not qualified');}
    public function getUserTeamIds(string $userId): array {return [];}
}
