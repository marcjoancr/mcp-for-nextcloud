<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** This factory is reachable only during the two pinned native read-check methods. */
final class FinalShareReadFactory implements \OCP\Share\IProviderFactory {
    public function __construct(private readonly FinalShareReadProvider $provider,private readonly int $type) {}
    public function getProviderForType($shareType) {
        if ($shareType!==$this->type || !in_array($shareType,[0,1],true)) {throw new \LogicException('Unqualified final sharing provider lookup');}
        return $this->provider;
    }
    public function getProvider($id) {throw new \LogicException('Final sharing provider lookup is closed');}
    public function getAllProviders() {throw new \LogicException('Final sharing provider enumeration is closed');}
    public function registerProvider(string $shareProviderClass):void {throw new \LogicException('Final sharing provider registration is closed');}
}
