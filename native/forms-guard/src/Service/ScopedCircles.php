<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Fresh isolated native read semantics. No warm membership cache or ambient cron session. */
final class ScopedCircles extends \OCA\Forms\Service\CirclesService {
    private readonly \Closure $dispatch;
    public function __construct(string $uid,bool $deferred=false) {$this->dispatch=CircleReadRuntime::scoped($uid,$deferred);}
    public function isCirclesEnabled(): bool {return ($this->dispatch)('isCirclesEnabled',[]);}
    public function getCircle(string $circleId): ?\OCA\Circles\Model\Circle {return ($this->dispatch)('getCircle',['circleId'=>$circleId]);}
    public function isUserInCircle(string $circleId,string $userId): bool {return ($this->dispatch)('isUserInCircle',['circleId'=>$circleId,'userId'=>$userId]);}
    public function getCircleUsers(string $circleId): array {return ($this->dispatch)('getCircleUsers',['circleId'=>$circleId]);}
    public function getUserTeamIds(string $userId): array {return ($this->dispatch)('getUserTeamIds',['userId'=>$userId]);}
}
