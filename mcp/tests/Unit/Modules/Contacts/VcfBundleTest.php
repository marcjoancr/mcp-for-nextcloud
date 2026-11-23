<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Exchange\VcfBundle;
use PHPUnit\Framework\TestCase;

final class VcfBundleTest extends TestCase {
    public function testReadsConcatenatedCardsAndReportsOneBadCardWithoutLosingTheOthers(): void {
        $parser = new VcfBundle(new ContactCard());
        $bundle = "\xEF\xBB\xBF" . self::card('one', 'First')
            . "BEGIN:VCARD\nVERSION:3.0\nFN:Broken\nEND:VCARD\n" . self::card('two', 'Second');
        $rows = $parser->read($bundle);
        self::assertSame([1, 2, 3], array_column($rows, 'index'));
        self::assertSame('one', (string)$rows[0]['card']->UID);
        self::assertNull($rows[1]['card']);
        self::assertSame('two', (string)$rows[2]['card']->UID);
        self::assertSame('keep', (string)$rows[2]['card']->{'X-CUSTOM'});
    }

    public function testRejectsAFileLargerThanTheImportLimit(): void {
        $this->expectException(ToolFailure::class);
        (new VcfBundle(new ContactCard()))->read(str_repeat('x', VcfBundle::MAX_BYTES + 1));
    }

    private static function card(string $uid, string $name): string {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$name\r\nX-CUSTOM:keep\r\nEND:VCARD\r\n";
    }
}
