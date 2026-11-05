<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support;

use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class ConfigFixtureCleanup implements Extension, FinishedSubscriber {
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void {
        $facade->registerSubscriber($this);
    }

    public function notify(Finished $event): void {
        InMemoryConfig::releaseFixtures();
    }
}
