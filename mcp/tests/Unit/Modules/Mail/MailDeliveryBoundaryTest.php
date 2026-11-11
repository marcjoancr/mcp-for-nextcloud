<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailRuntimePairs,NativeGuardManifest};
use PHPUnit\Framework\TestCase;
final class MailDeliveryBoundaryTest extends TestCase {
    public function testNewDeliveryArtifactRetainsThePreviouslyAcceptedMetadataBoundary():void {
        self::assertTrue(defined(NativeGuardManifest::class.'::LEGACY_METADATA_DATA'),'The accepted v10 metadata source must remain verifiable after installing a newer dependency.');
        $accepted=json_decode((string)file_get_contents(dirname(__DIR__,5).'/native/mail-guard/metadata-v10-manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('mcp-mail-mutations-v10',$accepted['contract']);
        self::assertSame($accepted,NativeGuardManifest::LEGACY_METADATA_DATA);
    }
    public function testProviderEligibilityIsSharedWithCapabilityMetadata():void {
        $services=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailServices::class);$services->method('isEnabledFor')->willReturn(true);
        $services->expects(self::never())->method('guardedDelivery');$services->expects(self::never())->method('smtpFactory');
        $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);$boundary->method('runtimeMatches')->willReturn(true);$boundary->method('deliveryVerified')->willReturn(true);
        $config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2.2');
        foreach([\OCP\IDBConnection::PLATFORM_MYSQL=>false,\OCP\IDBConnection::PLATFORM_POSTGRES=>true]as$provider=>$expected){
            $db=$this->createMock(\OCP\IDBConnection::class);$db->method('getDatabaseProvider')->willReturn($provider);
            $gateway=new \OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway($services,$boundary,$config,new \OCA\Mcp\Modules\Mail\Integration\MailGateway(new FakeMailApp(),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()),$db);self::assertSame($expected,$gateway->available('alice'));
        }
    }
    public function testNewNativeRuntimeDoesNotExtendUnqualifiedCorePairs():void {
        self::assertTrue(MailRuntimePairs::matches('35.0.2.2','5.12.5.11'));
        self::assertFalse(MailRuntimePairs::matches('34.0.5','5.12.5.11'));
        self::assertFalse(MailRuntimePairs::matches('35.0.3','5.12.5.11'));
    }
    public function testInternalMetadataLaneCanRecordAppWithdrawalWithoutOpeningOrdinaryUserAccess():void {
        $native=new class {public bool $reviewed=false;public function context(string $uid,string $id):array{return ['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];}public function review(string $uid,string $id,object $fence):array{$this->reviewed=true;return ['state'=>'blocked'];}};
        $services=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailServices::class);$services->method('isEnabledFor')->willReturn(false);$services->method('deliveryAuthorization')->willReturn($native);$services->expects(self::never())->method('guardedDelivery');
        $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);$boundary->method('runtimeMatches')->willReturn(true);$boundary->method('deliveryVerified')->willReturn(true);
        $config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2.2');$db=$this->createMock(\OCP\IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_POSTGRES);
        $gateway=new \OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway($services,$boundary,$config,new \OCA\Mcp\Modules\Mail\Integration\MailGateway(new FakeMailApp(),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()),$db);
        self::assertFalse($gateway->available('alice'));self::assertSame(3,$gateway->workerContext('alice',str_repeat('a',64))['accountId']);self::assertSame('blocked',$gateway->reviewAuthorization('alice',str_repeat('a',64),new \stdClass())['state']);self::assertTrue($native->reviewed);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$gateway->status('alice',str_repeat('a',64));
    }
    public function testInternalMetadataLaneStillRequiresQualifiedSourceRuntimeAndPostgres():void {
        foreach([[false,true,\OCP\IDBConnection::PLATFORM_POSTGRES],[true,false,\OCP\IDBConnection::PLATFORM_POSTGRES],[true,true,\OCP\IDBConnection::PLATFORM_MYSQL]] as [$source,$runtime,$provider]){
            $services=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailServices::class);$services->expects(self::never())->method('deliveryAuthorization');
            $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);$boundary->method('deliveryVerified')->willReturn($source);$boundary->method('runtimeMatches')->willReturn($runtime);
            $config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValueString')->willReturn('35.0.2.2');$db=$this->createMock(\OCP\IDBConnection::class);$db->method('getDatabaseProvider')->willReturn($provider);
            $gateway=new \OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway($services,$boundary,$config,new \OCA\Mcp\Modules\Mail\Integration\MailGateway(new FakeMailApp(),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()),$db);
            try{$gateway->workerContext('alice',str_repeat('a',64));self::fail('Unqualified metadata worker resolved.');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){self::assertTrue(true);}
        }
    }

}
