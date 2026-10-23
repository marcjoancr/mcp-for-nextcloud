<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Mcp\Protocol\ServerIdentity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** Installed metadata deliberately different from the checkout manifest. */
final class InstalledApp {
    public const VERSION = '9.8.7-installed-sentinel';
    public const INFO = ['name' => 'mcp-for-cloud', 'version' => self::VERSION];

    public static function identity(TestCase $test): ServerIdentity {
        $apps = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IAppManager::class);
        $apps->method('getAppVersion')->with('mcp')->willReturn(self::VERSION);
        return new ServerIdentity($apps, new \OCA\Mcp\Release\SourceOffer(
            (new \ReflectionMethod($test, 'createMock'))->invoke($test, \OCP\IConfig::class), $apps));
    }
}
