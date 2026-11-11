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
            $gateway=new \OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway($services,$boundary,$config,$db);self::assertSame($expected,$gateway->available('alice'));
        }
    }
    public function testNewNativeRuntimeDoesNotExtendUnqualifiedCorePairs():void {
        self::assertTrue(MailRuntimePairs::matches('35.0.2.2','5.12.5.11'));
        self::assertFalse(MailRuntimePairs::matches('34.0.5','5.12.5.11'));
        self::assertFalse(MailRuntimePairs::matches('35.0.3','5.12.5.11'));
    }
}
