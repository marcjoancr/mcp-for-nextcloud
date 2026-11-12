<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailMoveProofTest extends TestCase {
    private function parse(array $raw):?array {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/IMAP/MoveProof.php';self::assertFileExists($path,'The native MOVE proof boundary must be supplied.');require_once $path;
        return \OCA\Mail\IMAP\MoveProof::copyUid($raw,'9','43');
    }
    public function testOnlyOneCommandBoundMappingForBothExpectedGenerationsIsAccepted():void {
        self::assertSame(['destination_generation'=>'43','source_uid'=>'9','destination_uid'=>'17'],$this->parse(['43','9','17']));
        foreach([[],['43','9'],['44','9','17'],['43','10','17'],['43','9','0'],['43','9:900000000','1:900000000'],['43','9','17,18'],['43','9','4294967296'],['43','9','17','extra'],['43',9.0,'17']] as $raw)self::assertNull($this->parse($raw),'Missing, mismatched, multiple or non-canonical UID mappings are never proof.');
    }
}
