<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Cold native service facade; every identity is reconstructed from this lease. */
final class ScopedFederatedUserService extends \OCA\Circles\Service\FederatedUserService {
    public function __construct(private readonly Lease $lease) {}
    public function initCurrentUser(string $defaultUser=''): void {
        if($defaultUser!==''&&$defaultUser!==$this->lease->actorUid()){throw new \RuntimeException('Native Circle default actor changed');}
        $this->lease->identity($this->lease->actorUid());
    }
    public function getCurrentUser(): ?\OCA\Circles\Model\FederatedUser {return $this->lease->identity($this->lease->actorUid());}
    public function mustHaveCurrentUser(): void {$this->getCurrentUser();}
    public function getLocalFederatedUser(string $userId,bool $check=true,bool $generate=false): \OCA\Circles\Model\FederatedUser {
        if(!$check||$generate){throw new \RuntimeException('Native Circle recipient enrollment is unavailable');}
        return $this->lease->identity($userId);
    }
}
