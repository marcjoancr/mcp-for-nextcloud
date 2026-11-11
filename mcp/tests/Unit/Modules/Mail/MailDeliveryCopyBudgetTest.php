<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDeliveryCopyBudgetTest extends TestCase {
    private function budget(int $bytes=100):object {$p=dirname(__DIR__,5).'/native/mail-guard/src/Transmission/CopyBudget.php';self::assertFileExists($p,'Copy verification must bound native wire allocation before fetching a literal.');require_once dirname(__DIR__,5).'/native/mail-guard/src/Sync/RefreshBudget.php';require_once $p;return new \OCA\Mail\Transmission\CopyBudget($bytes);}
    public function testRejectsOversizedLiteralBeforeAllocation():void {$b=$this->budget();$b->beginResponse();$b->line('* 1 FETCH (UID 1 BODY[] {101}');$this->expectException(\RuntimeException::class);$b->literal(101);}
    public function testRejectsAppendUidRangeBeforeNativeExpansion():void {$b=$this->budget();$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('A1 OK [APPENDUID 42 1:4294967295] accepted');}
    public function testAdmitsOneExactCopyAndPositiveSingleAppendUid():void {$b=$this->budget();$b->expectValidity(42);$b->beginResponse();$b->line('A1 OK [APPENDUID 42 1] accepted');$b->beginResponse();$b->line('* 1 FETCH (UID 1 BODY[] {100}');$b->literal(100);$b->consume(100);$b->line(')');$b->beginResponse();$b->line('A2 OK fetched');self::assertTrue(true);}    public function testAppendUidMustMatchApprovedMailboxGeneration():void {$b=$this->budget();self::assertTrue(method_exists($b,'expectValidity'),'APPENDUID must bind the approved UIDVALIDITY before the native parser drops it.');$b->expectValidity(42);$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('A1 OK [APPENDUID 43 1] accepted');}

    public function testSelectedGenerationCannotChangeAfterAppendProof():void {$b=$this->budget();$b->expectValidity(42);$b->beginResponse();$b->line('A1 OK [APPENDUID 42 1] accepted');$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('* OK [UIDVALIDITY 43] selected');}
    public function testUidCanFollowTheBodyLiteral():void {$b=$this->budget();$b->beginResponse();$b->line('* 1 FETCH (BODY[] {100}');$b->literal(100);$b->consume(100);$accepted=true;try{$b->line(' UID 1)');$b->beginResponse();$b->line('A2 OK fetched');}catch(\RuntimeException){$accepted=false;}self::assertTrue($accepted,'A single valid UID after the body literal must be accepted.');}
    public function testMalformedAndDuplicateUidTrailersAreRefused():void {
        foreach([' UID 0)',' UID 1:2)',' UID 4294967296)',' UID 1 UID 2)',')'] as $trailer){$b=$this->budget();$b->beginResponse();$b->line('* 1 FETCH (BODY[] {100}');$b->literal(100);$refused=false;try{$b->line($trailer);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused,$trailer);}
        $b=$this->budget();$b->beginResponse();$b->line('* 1 FETCH (UID 1 BODY[] {100}');$b->literal(100);$this->expectException(\RuntimeException::class);$b->line(' UID 1)');
    }

}
