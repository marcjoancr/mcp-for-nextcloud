<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailSieveProgramTest extends TestCase {
    private function program():\OCA\Mail\Sieve\SieveProgram {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/Sieve/';self::assertFileExists($root.'SieveProgram.php');foreach(['SieveFailure','SieveBudget','SieveProgram']as$f)require_once $root.$f.'.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/IMAP/TagKeywords.php';
        $fixture=dirname(__DIR__,3).'/fixtures/mail-sieve/';foreach(['constants','SieveUtils','ImapFlag','FilterBuilder','OutOfOfficeState','OutOfOfficeParser']as$f)require_once $fixture.$f.'.php';
        $flags=new \OCA\Mail\IMAP\ImapFlag();return new \OCA\Mail\Sieve\SieveProgram(new \OCA\Mail\Service\MailFilter\FilterBuilder($flags),new \OCA\Mail\Service\OutOfOffice\OutOfOfficeParser(),$flags);
    }
    private function filter():array{return [['name'=>'Review','enable'=>true,'priority'=>1,'operator'=>'allof','tests'=>[['field'=>'subject','operator'=>'contains','values'=>['SYNTHETIC']]],'actions'=>[['type'=>'keep'],['type'=>'stop']]]];}
    public function testNativeJsonCommentsAreNeverExecutableAuthority():void {
        $p=$this->program();$old=$p->parse("# retained opaque comment\r\n/* private comment */\r\n",['test@example.invalid']);self::assertFalse($old['opaque']);$b=$p->build($old,$this->filter(),null,['test@example.invalid'],[],false);self::assertFalse($p->parse($b['script'],['test@example.invalid'])['opaque']);self::assertStringContainsString($old['untouched'],$b['script']);
        foreach([str_replace('keep;','redirect "other@example.invalid";',$b['script']),$b['script'].$b['script'],$b['script'].'reject "no";',$b['script'].'require "include";',$b['script'].'notify "mailto:other@example.invalid";',substr($b['script'],0,-52)]as$s)self::assertTrue($p->parse($s,['test@example.invalid'])['opaque']);
    }
    public function testSafePathRejectsFinalVacationRedirectAndUnknownExecutable():void {
        $p=$this->program();$old=$p->parse('',[]);$filters=$this->filter();$filters[0]['actions']=[['type'=>'redirect','recipient'=>'other@example.invalid']];
        foreach([[$old,$filters,null],[$p->parse('include "external";',[]),[],null],[$old,[],['version'=>1,'enabled'=>true,'start'=>'2026-11-11T00:00:00Z','end'=>'2026-11-12T00:00:00Z','subject'=>'Away','message'=>'private']]]as[$o,$f,$a]){try{$p->build($o,$f,$a,[],['copy','vacation','date','relational'],false);self::fail('Emitting/opaque candidate admitted');}catch(\OCA\Mail\Sieve\SieveFailure){self::assertTrue(true);}}
    }
    public function testAbsenceDisablePreservesCommentsButNotUnknownOrEmittingRemainder():void {
        $p=$this->program();$rec=['test@example.invalid'];$a=['version'=>1,'enabled'=>true,'start'=>'2026-11-11T00:00:00+00:00','end'=>'2026-11-12T00:00:00+00:00','subject'=>'Re: ${subject}','message'=>'Away'];$enabled=$p->build($p->parse('# retained',$rec),[],$a,$rec,['vacation','date','relational','variables'],true);$parsed=$p->parse($enabled['script'],$rec);self::assertFalse($parsed['opaque']);$a['enabled']=false;$disabled=$p->build($parsed,null,$a,$rec,[],false);self::assertFalse($disabled['emits']);self::assertFalse($p->parse($disabled['script'],$rec)['opaque']);self::assertStringContainsString('# retained',$disabled['script']);self::assertStringNotContainsString('vacation :days',$disabled['script']);
    }
    public function testUnsupportedExtensionsAndMalformedAbsenceStayClosed():void {
        $p=$this->program();$f=$this->filter();$f[0]['actions']=[['type'=>'redirect','recipient'=>'other@example.invalid']];
        try{$p->build($p->parse('',[]),$f,null,[],[],true);self::fail('Missing copy extension admitted');}catch(\OCA\Mail\Sieve\SieveFailure){self::assertTrue(true);}
        foreach([['subject'=>"line\nname"],['message'=>'${private}'],['start'=>'2026-02-31T00:00:00Z'],['end'=>'2026-11-10T00:00:00Z']]as$change){$a=array_replace(['version'=>1,'enabled'=>true,'start'=>'2026-11-11T00:00:00Z','end'=>'2026-11-12T00:00:00Z','subject'=>'Away','message'=>'Away'],$change);try{$p->validateAbsence($a);self::fail('Malformed absence admitted');}catch(\OCA\Mail\Sieve\SieveFailure){self::assertTrue(true);}}
    }
    public function testStrictInputDoesNotUseNativeIgnoredOperatorsOrFlagFallback():void {
        $p=$this->program();$f=$this->filter();$invalid=[];$x=$f;$x[0]['name']="safe\r\nredirect";$invalid[]=$x;$x=$f;$x[0]['tests'][0]['operator']='contains :comparator "x"';$invalid[]=$x;$x=$f;$x[0]['actions']=[['type'=>'execute','command'=>'x']];$invalid[]=$x;$x=$f;$x[0]['actions']=[['type'=>'addflag','flag'=>'\\Deleted']];$invalid[]=$x;$x=$f;$x[0]['tests'][0]['values']=[str_repeat('x',257)];$invalid[]=$x;$x=$f;$x[0]['actions']=[['type'=>'addflag','flag'=>'unsafe(keyword)']];$invalid[]=$x;$x=$f;$x[0]['actions']=[['type'=>'addflag','flag'=>str_repeat('á',40)]];$invalid[]=$x;
        foreach($invalid as$x){try{$p->validateFilters($x);self::fail('Unsafe native definition admitted');}catch(\OCA\Mail\Sieve\SieveFailure){self::assertTrue(true);}}
        $f[0]['actions']=[['type'=>'addflag','flag'=>'Needs review'],['type'=>'addflag','flag'=>'\\Seen']];$b=$p->build($p->parse('',[]),$f,null,[],['imap4flags'],false);self::assertStringContainsString('addflag "$needs_review";',$b['script']);self::assertFalse($p->parse($b['script'],[])['opaque']);
    }
}
