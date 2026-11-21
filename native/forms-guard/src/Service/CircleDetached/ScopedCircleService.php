<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Native Circle ACL algorithms over the cold request and current lease actor. */
final class ScopedCircleService extends \OCA\Circles\Service\CircleService {
    public function __construct(private readonly Lease $lease,private readonly ScopedFederatedUserService $actor) {}
    public function getCircle(string $circleId,?\OCA\Circles\Model\Probes\CircleProbe $probe=null): \OCA\Circles\Model\Circle {
        if($probe!==null&&$probe::class!==\OCA\Circles\Model\Probes\CircleProbe::class)throw new \RuntimeException('Circle metadata probe is unqualified');
        return $this->lease->metadataRead(function()use($circleId,$probe):\OCA\Circles\Model\Circle {$this->actor->mustHaveCurrentUser();return $this->lease->circles()->getCircle($circleId,$this->actor->getCurrentUser(),$probe);});
    }
    public function probeCircle(string $circleId,?\OCA\Circles\Model\Probes\CircleProbe $circleProbe=null,?\OCA\Circles\Model\Probes\DataProbe $dataProbe=null): \OCA\Circles\Model\Circle {
        $circleProbe??=new \OCA\Circles\Model\Probes\CircleProbe();
        $dataProbe??=new \OCA\Circles\Model\Probes\DataProbe();
        if($circleProbe::class!==\OCA\Circles\Model\Probes\CircleProbe::class||$dataProbe::class!==\OCA\Circles\Model\Probes\DataProbe::class)throw new \RuntimeException('Circle metadata probe is unqualified');
        return $this->lease->metadataRead(function()use($circleId,$circleProbe,$dataProbe):\OCA\Circles\Model\Circle {$this->actor->mustHaveCurrentUser();return $this->lease->circles()->probeCircle($circleId,$this->actor->getCurrentUser(),$circleProbe,$dataProbe);});
    }
}
