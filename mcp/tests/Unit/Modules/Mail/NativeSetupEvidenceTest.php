<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{NativeSetupEvidence,MailServices,MailNativeBoundary,MailCredentialStorage};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IDBConnection,IConfig};
use PHPUnit\Framework\TestCase;
final class NativeSetupEvidenceTest extends TestCase {
    private function fixture(bool $candidate,bool $inTransaction=false):array {
        $db=$this->createStub(IDBConnection::class);$db->method('inTransaction')->willReturn($inTransaction);$config=$this->createStub(IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2.2');
        $boundary=$this->createStub(MailNativeBoundary::class);$boundary->method('credentialsSourceVerified')->willReturn(false);
        $storage=$this->createStub(MailCredentialStorage::class);$storage->method('verified')->willReturn(true);
        $native=new class($db){
            public ?array $proof=null;
            public int $calls=0;
            public int $claims=0;
            public function __construct(private IDBConnection $db){}
            public function assertDatabase(IDBConnection $db):void {if($db!==$this->db)throw new \LogicException('Wrong wrapper');}
            public function observe(...$args):?array {++$this->calls;return $this->proof;}
            public function claim(...$args):void {++$this->claims;}
        };
        $services=$this->createMock(MailServices::class);
        if($candidate)$services->method('setupEvidence')->willReturn($native);
        else $services->expects(self::never())->method('setupEvidence');
        $args=[$services,$boundary,$storage,$db,$config];
        $adapter=$candidate?$this->getMockBuilder(NativeSetupEvidence::class)->setConstructorArgs($args)->onlyMethods(['available'])->getMock():new NativeSetupEvidence(...$args);
        if($candidate)$adapter->method('available')->willReturn(true);
        return [$adapter,$native,$db];
    }
    public function testUnknownSourceOrUnqualifiedDependencyNeverResolvesNativeService():void {
        [$a]=$this->fixture(false);self::assertFalse($a->available());
        $this->expectException(BeforeEffectFailure::class);$a->observe('alice',str_repeat('a',64),4,str_repeat('b',64),'op');
    }
    public function testMalformedOrSecretBearingNativeResultNeverLeaks():void {
        [$a,$native]=$this->fixture(true);
        $base=['status'=>'native_write_observed','account_id'=>4,'revision'=>7,'kind'=>'password','protocols'=>['smtp'],'saved_at'=>1000,'credential_change'=>'unverified'];
        foreach([$base+['password'=>'PRIVATE'],array_replace($base,['account_id'=>5]),array_replace($base,['revision'=>'7']),array_replace($base,['kind'=>'refresh']),array_replace($base,['protocols'=>[]]),array_replace($base,['credential_change'=>'verified'])] as $proof){
            $native->proof=$proof;
            $rejected=false;
            try{$a->observe('alice',str_repeat('a',64),4,str_repeat('b',64),'op');}
            catch(\RuntimeException $error){$rejected=true;self::assertStringNotContainsString('PRIVATE',$error->getMessage());}
            self::assertTrue($rejected,'Malformed native proof accepted');
        }
        $native->proof=$base;self::assertSame($base,$a->observe('alice',str_repeat('a',64),4,str_repeat('b',64),'op'));
        $native->proof=null;self::assertNull($a->observe('alice',str_repeat('a',64),4,str_repeat('b',64),'op'));
    }
    public function testCompletionCannotInheritAnotherTransaction():void {
        [$a]=$this->fixture(true,true);
        $this->expectException(BeforeEffectFailure::class);$a->withCompletion('alice',static fn():bool=>true);
    }
    public function testDifferentPrimaryWrapperIsRefusedBeforeNativeClaim():void {
        [$a,$native,$db]=$this->fixture(true);$other=$this->createStub(IDBConnection::class);
        try{$a->claim($other,'alice',str_repeat('a',64),4,7,str_repeat('b',64),'op','complete');self::fail('Foreign primary accepted');}catch(BeforeEffectFailure){}
        self::assertSame(0,$native->claims);
        $a->claim($db,'alice',str_repeat('a',64),4,7,str_repeat('b',64),'op','complete');self::assertSame(1,$native->claims);
    }
}
