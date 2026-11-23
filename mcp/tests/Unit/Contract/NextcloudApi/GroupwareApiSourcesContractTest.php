<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/** The additional OCP and DAV surfaces are exact official server files, pinned independently per checked major. */
final class GroupwareApiSourcesContractTest extends TestCase {
    private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';
    private const CLASSES = [
        'OCP\\Accounts\\IAccountManager',
        'OCP\\Calendar\\Resource\\IResourceMetadata',
        'OCP\\Calendar\\Room\\IRoomMetadata',
        'OCP\\Profile\\IProfileManager',
        'OCA\\DAV\\CalDAV\\ResourceBooking\\ResourcePrincipalBackend',
        'OCA\\DAV\\CalDAV\\ResourceBooking\\RoomPrincipalBackend',
        'OCA\\DAV\\DAV\\Sharing\\Plugin',
    ];

    public function testEveryFixtureCarriesSurfacesFromItsPinnedOfficialFiles(): void {
        $pins = json_decode((string)file_get_contents(self::FIXTURES . '/groupware-api-sources.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (NextcloudApiUsage::SOURCES as $major => $sources) {
            $fixture = require self::FIXTURES . '/' . $major . '.php';
            self::assertSame($pins[(string)$major], $fixture['groupwareApiSources'] ?? null, 'source pins for ' . $major);
            self::assertSame($sources['server'], $pins[(string)$major]['server'], 'server release for ' . $major);
            foreach ($pins[(string)$major]['files'] as $path => $source) {
                self::assertSame('https://raw.githubusercontent.com/nextcloud/server/v' . $sources['server'] . '/' . $path, $source['url']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
                self::assertArrayHasKey($path, $fixture['groupwareApiSources']['files']);
            }
            foreach (self::CLASSES as $class) {
                self::assertNotNull($fixture['classes'][$class] ?? null, $class . ' in Nextcloud ' . $major);
            }
        }
    }
}
