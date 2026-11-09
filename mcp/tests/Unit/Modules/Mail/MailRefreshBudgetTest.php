<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailRefreshBudgetTest extends TestCase {
    private function budget():object {require_once dirname(__DIR__,5).'/native/mail-guard/src/Sync/RefreshBudget.php';return new \OCA\Mail\Sync\RefreshBudget();}
    public function testRejectsRangesExcessIdsAndOversizedDataBeforeNativeParsing():void {
        $b=$this->budget();$b->line('* SEARCH 1 2 3');self::addToAssertionCount(1);
        foreach(['* SEARCH 1:4294967295','* SEARCH '.implode(' ',range(1,201)),str_repeat('x',32769)]as$line){$refused=false;try{$b->line($line);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);}
        $b=$this->budget();$b->literal(65536);$this->expectException(\RuntimeException::class);$b->literal(65537);
    }
    public function testOnlyClosedNativeResponseGrammarReachesTheParser():void {
        $budget=$this->budget();
        foreach(['* OK [COPYUID 42 1:1000 1:1000]','* OK "[COPYUID" 42 1:1000 1:1000]','* OK ["COPYUID" 42 1:1000 1:1000]','* OK [APPENDUID 42 1:1000]','* OK [MODIFIED 1:1000]','* OK [REFERRAL imap://127.0.0.1:1/]','*  ESEARCH (TAG "1") UID ALL 1:1000','* "ESEARCH" (TAG "1") UID ALL 1:1000','*  VANISHED 1:1000','* "VANISHED" 1:1000']as$line){$refused=false;try{$budget->line($line);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused,$line);}
        // Repeated spaces are accepted only where their complete semantics remain bounded.
        $budget->line('*  SEARCH  1  2');self::addToAssertionCount(1);for($i=0;$i<200;$i++)$budget->line('*  1  FETCH (UID 1 FLAGS ())');$this->expectException(\RuntimeException::class);$budget->line('*  1  FETCH (UID 1 FLAGS ())');
    }
    public function testAggregateBytesCannotExceedTheFixedBudget():void {$b=$this->budget();$b->consume(8388608);self::addToAssertionCount(1);$this->expectException(\RuntimeException::class);$b->consume(1);}
    public function testOnlyReadCommandsAreAdmitted():void {$b=$this->budget();foreach(['CAPABILITY','AUTHENTICATE','EXAMINE','UID SEARCH','UID FETCH','STATUS','LOGOUT']as$c)$b->command($c);self::addToAssertionCount(1);foreach(['SELECT','UID STORE','APPEND','UID MOVE','CLOSE','EXPUNGE']as$c){$refused=false;try{$b->command($c);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused,$c);}}
}
