<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\Compat\LegacyCatalogContract;
use PHPUnit\Framework\TestCase;

/** A legacy comparison cannot hide changed grants when a new capability is added. */
final class LegacyCatalogContractTest extends TestCase {
    public function testOnlyTheExplicitMailReadAdditionIsAccepted(): void {
        $baseline = ['files' => ['read', 'edit'], 'logs' => ['read']];
        self::assertSame($baseline, LegacyCatalogContract::baseline($baseline, [...$baseline, 'mail' => ['read']]));
        foreach ([$baseline, ['files' => ['read'], 'logs' => ['read'], 'mail' => ['read']],
            [...$baseline, 'mail' => ['read', 'write']], [...$baseline, 'unknown' => ['read']],
            ['mail' => ['read']]] as $current) {
            try { LegacyCatalogContract::baseline($baseline, $current); self::fail('Changed catalog accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        $this->expectException(\UnexpectedValueException::class);
        LegacyCatalogContract::baseline([...$baseline, 'mail' => ['read']], [...$baseline, 'mail' => ['read']]);
    }

    public function testOnlyAnExplicitlyDeniedMailCellIsRemovedAndAllOtherValuesSurvive(): void {
        $legacy = ['1' => ['globalEnabled' => true, 'users' => ['alice' => ['grants' => ['files' => ['read' => false]]]],
            'matrix' => ['alice' => ['eligible' => true, 'connected' => false, 'grants' => ['files' => ['read' => false]]]]]];
        $branch = $legacy;
        $branch['1']['matrix']['alice']['grants']['mail'] = ['read' => false];
        self::assertSame($legacy, LegacyCatalogContract::answers($branch));
        self::assertArrayHasKey('mail', $branch['1']['matrix']['alice']['grants'], 'The input must not be modified');
        $branch['1']['matrix']['alice']['grants']['files']['read'] = true;
        self::assertNotSame($legacy, LegacyCatalogContract::answers($branch), 'Legacy changes must remain visible');
        foreach ([['read' => true], ['read' => 0], ['read' => false, 'write' => false], [], null] as $invalid) {
            $branch['1']['matrix']['alice']['grants']['mail'] = $invalid;
            try { LegacyCatalogContract::answers($branch); self::fail('Unsafe Mail default accepted'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        unset($branch['1']['matrix']['alice']['grants']['mail']);
        $this->expectException(\UnexpectedValueException::class);
        LegacyCatalogContract::answers($branch);
    }
}
