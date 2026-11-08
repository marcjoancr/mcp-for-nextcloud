<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
/** Exercises the dependency's real guard; changing any admission comparison must break these cases. */
final class MailMutationSelectionTest extends TestCase {
    private function guard(): object {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/IMAP/MutationSelection.php';
        self::assertFileExists($path,'The native guard dependency must be supplied.');
        require_once $path;
        return new \OCA\Mail\IMAP\MutationSelection();
    }
    public function testOnlyTheArmedConnectionSelectionAndUidCanDispatch(): void {
        $g=$this->guard();$connection=new \stdClass();
        $g->arm($connection,'INBOX','42',1,9);
        self::assertTrue($g->check($connection,'INBOX','42',1,9));
        foreach([[new \stdClass(),'INBOX','42',1,9],[$connection,'Other','42',1,9],[$connection,'INBOX','43',1,9],[$connection,'INBOX','42',0,9],[$connection,'INBOX','42',1,10]] as $snapshot){
            self::assertFalse($g->check(...$snapshot),'A different connection, folder, generation, access mode or UID must refuse.');
        }
    }
    public function testInvalidationCannotBeRearmedOrRetried(): void {
        $g=$this->guard();$c=new \stdClass();$g->arm($c,'INBOX','42',1,9);$g->invalidate();
        self::assertFalse($g->check($c,'INBOX','42',1,9));
        $this->expectException(\LogicException::class);$g->arm($c,'INBOX','42',1,9);
    }
    public function testWireIsAdmittedOnceAndProofMustRemainOnTheArmedGeneration(): void {
        $g=$this->guard();$c=new \stdClass();$g->arm($c,'INBOX','42',1,9);
        self::assertTrue($g->startWire($c,'INBOX','42',1,9));
        self::assertFalse($g->startWire($c,'INBOX','42',1,9),'No second STORE admission even on the same connection.');
        self::assertTrue($g->check($c,'INBOX','42',1,9));
        self::assertFalse($g->check($c,'INBOX','43',1,9));
    }
}
