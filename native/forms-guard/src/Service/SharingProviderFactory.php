<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** The native factory keeps its identifier/type routing for the scoped default provider. */
final class SharingProviderFactory extends \OC\Share20\ProviderFactory
{
    public function __construct(\OCP\App\IAppManager $apps, private SharingDefaultProvider $provider)
    {
        parent::__construct($apps, \OCP\Server::get(\Psr\Log\LoggerInterface::class));
    }
    public function createdHere(string $id): bool
    {
        return $this->provider->createdHere($id);
    }
    protected function defaultShareProvider(): \OC\Share20\DefaultShareProvider
    {
        return $this->provider;
    }
}
