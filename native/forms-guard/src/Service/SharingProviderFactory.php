<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** The native factory keeps its identifier/type routing for the scoped default provider. */
final class SharingProviderFactory extends \OC\Share20\ProviderFactory
{
    public function __construct(\OCP\App\IAppManager $apps, private SharingDefaultProvider $provider, private \Closure|CircleBoundaryProvider|null $circles = null)
    {
        parent::__construct($apps, \OCP\Server::get(\Psr\Log\LoggerInterface::class));
    }
    public function getProvider($id)
    {
        if ($id === 'ocinternal') {return $this->provider;}
        if ($id === 'ocCircleShare' && $this->circles !== null) {return $this->circleProvider();}
        throw new \RuntimeException('Native managed share provider is not qualified');
    }
    public function getProviderForType($shareType)
    {
        if (in_array($shareType, [\OCP\Share\IShare::TYPE_USER, \OCP\Share\IShare::TYPE_GROUP], true)) {return $this->provider;}
        if ($shareType === 7 && $this->circles !== null) {return $this->circleProvider();}
        throw new \RuntimeException('Native managed share type is not qualified');
    }
    public function getAllProviders()
    {
        return $this->circles instanceof CircleBoundaryProvider ? [$this->provider,$this->circles] : [$this->provider];
    }
    public function registerProvider(string $shareProviderClass): void
    {
        throw new \RuntimeException('Native managed share provider registration is unavailable');
    }
    public function createdHere(string $id): bool
    {
        return $this->provider->createdHere($id) || ($this->circles instanceof CircleBoundaryProvider && $this->circles->createdHere($id));
    }
    private function circleProvider():CircleBoundaryProvider {
        if($this->circles instanceof CircleBoundaryProvider){return$this->circles;}
        if($this->circles===null){throw new \RuntimeException('Native Circle provider is not qualified');}
        $provider=($this->circles)();if(!$provider instanceof CircleBoundaryProvider){throw new \RuntimeException('Native Circle provider factory is unqualified');}
        return$this->circles=$provider;
    }
    protected function defaultShareProvider(): \OC\Share20\DefaultShareProvider
    {
        return $this->provider;
    }
}
