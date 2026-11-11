<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailContentBudgetTest extends TestCase {
    private function budget():object {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/';
        self::assertFileExists($root.'Content/ContentBudget.php','Content must have an independent pre-allocation transport budget.');
        require_once $root.'Sync/RefreshBudget.php';require_once $root.'Content/ContentBudget.php';
        return new \OCA\Mail\Content\ContentBudget(42,7);
    }
    public function testRawLiteralCapBeforeAllocation():void {$b=$this->budget();$b->expect('raw');$b->beginResponse();$b->line('* 1 FETCH (UID 7 BODY[] {26214401}');$this->expectException(\RuntimeException::class);$b->literal(26214401);}
    public function testExactRawWithLateUid():void {$b=$this->budget();$b->expect('raw');$b->beginResponse();$b->line('* 1 FETCH (BODY[] {3}');$b->literal(3);$b->consume(3);$b->line(' UID 7)');$b->beginResponse();$b->line('A1 OK fetched');$b->assertFetch();self::assertTrue(true);}
    public function testWrongLateUidRefusesPrivateBytes():void {$b=$this->budget();$b->expect('raw');$b->beginResponse();$b->line('* 1 FETCH (BODY[] {3}');$b->literal(3);$b->consume(3);$this->expectException(\RuntimeException::class);$b->line(' UID 8)');}
    public function testMissingTaggedSuccessCannotReturnBytes():void {$b=$this->budget();$b->expect('raw');$b->beginResponse();$b->line('* 1 FETCH (UID 7 BODY[] {3}');$b->literal(3);$b->consume(3);$b->line(')');$this->expectException(\RuntimeException::class);$b->assertFetch();}
    public function testWireBudgetCheckedBeforeLiteralRead():void {$b=$this->budget();$b->consume(41943030);$b->expect('part','1');$b->beginResponse();$b->line('* 1 FETCH (UID 7 BODY[1] {11}');$this->expectException(\RuntimeException::class);$b->literal(11);}
    public function testReadOnlyCommandClosure():void {foreach(['APPEND','UID STORE','UID COPY','SELECT','CLOSE','NOOP','UID SEARCH','UNSELECT'] as $command){$b=$this->budget();try{$b->command($command);self::fail($command);}catch(\RuntimeException){self::assertTrue(true);}}}
    public function testGenerationFlipRefusedBeforeParser():void {$b=$this->budget();$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('* OK [UIDVALIDITY 43] generation');}
    public function testMetadataNestingRefusedBeforeHordeObjects():void {$b=$this->budget();$b->expect('manifest');$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('* 1 FETCH (UID 7 BODYSTRUCTURE '.str_repeat('(',25));}
    public function testBoundedManifestDoesNotFetchAnyBody():void {$b=$this->budget();$b->expect('manifest');$b->beginResponse();$b->line('* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("NAME" "a(b).txt") NIL NIL "BASE64" 35900000 1) UID 7)');$b->beginResponse();$b->line('A1 OK metadata');$b->assertFetch();self::assertTrue(true);}
    public function testUnsolicitedStructureInRawModeRefused():void {$b=$this->budget();$b->expect('raw');$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('* 1 FETCH (UID 7 BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 3 1))');}
    public function testMimeNodeLimitBeforeConstruction():void {$b=$this->budget();for($i=0;$i<100;$i++){$b->enterMimeNode();$b->leaveMimeNode();}$this->expectException(\RuntimeException::class);$b->enterMimeNode();}
    public function testParameterLimitBeforeDecoder():void {$b=$this->budget();$this->expectException(\RuntimeException::class);$b->parameters(array_fill(0,66,'x'));}
    public function testParameterPairsAreBoundedAcrossBothMimeHeadersOfOnePart():void {$b=$this->budget();$b->enterMimeNode();$p=[];for($i=0;$i<32;$i++){$p[]='x'.$i;$p[]='v';}$b->parameters($p);$this->expectException(\RuntimeException::class);$b->parameters(['filename','x']);}
    public function testAuthenticationMetadataAlsoHasAPreTokenDepthCap():void {$b=$this->budget();$b->beginResponse();$this->expectException(\RuntimeException::class);$b->line('* NAMESPACE '.str_repeat('(',25).'NIL'.str_repeat(')',25));}
}
