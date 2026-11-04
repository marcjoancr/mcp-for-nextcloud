<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/** Native Talk history must be checked against the same releases as all other consumed Talk APIs. */
final class TalkContextSourcesTest extends TestCase {
    public function testNativeReaderProvenanceMatchesEverySupportedFixture(): void {
        $directory = __DIR__ . '/../../../fixtures/nextcloud-api';
        $pins = json_decode((string)file_get_contents($directory . '/talk-context-sources.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (NextcloudApiUsage::SOURCES as $major => $sources) {
            $fixture = require $directory . '/' . $major . '.php';
            self::assertSame($pins[$major], $fixture['talkContextSources']);
            self::assertSame($sources['server'], $pins[$major]['server']);
            self::assertSame($sources['spreed'], $pins[$major]['spreed']);
            foreach ($pins[$major]['files'] as $path => $source) {
                $app = str_starts_with($path, 'apps/spreed/') ? 'spreed' : 'server';
                self::assertStringStartsWith('https://raw.githubusercontent.com/nextcloud/' . $app . '/v' . $sources[$app] . '/', $source['url']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
            }
        }
    }
}
