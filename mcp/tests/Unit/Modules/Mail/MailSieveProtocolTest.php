<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailSieveProtocolTest extends TestCase {
    private function load():void {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/Sieve/';
        foreach(['SieveFailure','SieveBudget','BoundedManageSieve']as$c)require_once $root.$c.'.php';
        require_once __DIR__.'/SieveTlsPeer.php';
    }
    public static function malicious():array {return array_map(static fn($s)=>[$s],['greeting_line','greeting_caps','partial','auth_literal','auth_private','referral','names','many_names','multiple_active','literal_large','decimal','literal_zero_progress','second_literal','trailing_no','status_literal','lost_put','lost_active']);}
    #[\PHPUnit\Framework\Attributes\DataProvider('malicious')]
    public function testRealTlsRefusesMalformedPeerWithoutSecretsOrRetry(string $scenario):void {
        $this->load();$peer=new SieveTlsPeer($scenario);$client=null;
        try{$client=new \OCA\Mail\Sieve\BoundedManageSieve($peer->params());$client->scripts();$client->getScript('original');$client->installScript('new','# harmless');$client->setActive('new');self::fail('Malformed peer was admitted: '.$scenario);}
        catch(\OCA\Mail\Sieve\SieveFailure $e){self::assertSame('Sieve protocol refused.',$e->getMessage());self::assertNull($e->getPrevious());}
        finally{$client?->close();$counts=$peer->finish();}
        self::assertLessThanOrEqual(1,$counts['AUTHENTICATE']??0);self::assertLessThanOrEqual(1,$counts['PUTSCRIPT']??0);self::assertLessThanOrEqual(1,$counts['SETACTIVE']??0);self::assertArrayNotHasKey('DELETESCRIPT',$counts);
    }
    public function testRealTlsRoundtripRequiresPositiveLiteralStatus():void {
        $this->load();$peer=new SieveTlsPeer('normal');
        try{$client=new \OCA\Mail\Sieve\BoundedManageSieve($peer->params());self::assertSame('original',$client->scripts()['active']);self::assertSame('# harmless',$client->getScript('original'));$script=str_repeat('# safe\r\n',20000);$client->installScript('candidate',$script);self::assertSame($script,$client->getScript('candidate'));$client->setActive('candidate');self::assertSame('candidate',$client->scripts()['active']);$client->close();}finally{$counts=$peer->finish();}
        self::assertSame(1,$counts['PUTSCRIPT']);self::assertSame(1,$counts['SETACTIVE']);
    }
    public function testStartTlsGreetingAndPostTlsCapabilitiesUseTheSameBudget():void {
        $this->load();$peer=new SieveTlsPeer('starttls');try{$c=new \OCA\Mail\Sieve\BoundedManageSieve($peer->params());self::assertSame('original',$c->scripts()['active']);$c->close();}finally{$counts=$peer->finish();}self::assertSame(1,$counts['STARTTLS']);self::assertSame(1,$counts['AUTHENTICATE']);
    }
    public function testSupportedSaslChallengesAuthenticateOnceOverVerifiedTls():void {
        $this->load();foreach(['login','cram','plain_challenge']as$mode){$peer=new SieveTlsPeer($mode);$c=null;try{$c=new \OCA\Mail\Sieve\BoundedManageSieve($peer->params());self::assertSame('original',$c->scripts()['active']);}finally{$c?->close();$counts=$peer->finish();}self::assertSame(1,$counts['AUTHENTICATE']);self::assertSame(1,$counts['AUTH_VERIFIED']);}
    }
    public function testSlowDripGreetingCannotExtendTheSeventeenSecondDeadline():void {
        $this->load();$peer=new SieveTlsPeer('slow');$start=microtime(true);try{new \OCA\Mail\Sieve\BoundedManageSieve($peer->params());self::fail('Slow greeting admitted');}catch(\OCA\Mail\Sieve\SieveFailure){$elapsed=microtime(true)-$start;self::assertGreaterThan(16,$elapsed);self::assertLessThan(18.5,$elapsed);}finally{$counts=$peer->finish();}self::assertSame(0,$counts['AUTHENTICATE']??0);
    }
    public function testExceptionsDoNotRetainAuthenticationArgumentsWhenPhpCapturesTraceArguments():void {
        $this->load();$before=ini_set('zend.exception_ignore_args','0');try{new \OCA\Mail\Sieve\BoundedManageSieve(['host'=>'invalid://','password'=>'PRIVATE_TRACE_CANARY']);self::fail('Invalid transport admitted');}catch(\OCA\Mail\Sieve\SieveFailure $e){self::assertFalse(str_contains(json_encode($e->getTrace()),'PRIVATE_TRACE_CANARY'),'Authentication arguments leaked into exception trace');}finally{ini_set('zend.exception_ignore_args',$before);}
    }
    public function testConstructorHasBoundedTransportBeforeAuthentication():void {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/Sieve/';
        self::assertFileExists($root.'BoundedManageSieve.php','Stored-credential Sieve constructor must enforce budgets before greeting/authentication.');
        require_once $root.'SieveFailure.php';require_once $root.'SieveBudget.php';require_once $root.'BoundedManageSieve.php';
        $budget=new \OCA\Mail\Sieve\SieveBudget();
        self::assertSame(262144,$budget::SCRIPT);
        foreach(['262145','9999999999999999999999999999','01','-1']as$n){try{$budget->literal($n,262144);self::fail('Unsafe declared literal admitted');}catch(\OCA\Mail\Sieve\SieveFailure $e){self::assertSame('Sieve protocol refused.',$e->getMessage());self::assertNull($e->getPrevious());}}
    }
}
