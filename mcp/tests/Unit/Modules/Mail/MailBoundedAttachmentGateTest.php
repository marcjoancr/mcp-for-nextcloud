<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Integration\{MailContentReader,MailServices,MailMessageLocator};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
final class MailBoundedAttachmentGateTest extends TestCase {
    public function testUnqualifiedCurrentGenerationNeverFallsBackToLegacyFetch():void {
        self::assertTrue(method_exists(MailContentReader::class,'mustUseBounded'),'Current native generation must fail closed, not fall back to the legacy parser.');
        $reader=$this->createMock(MailContentReader::class);$reader->method('available')->willReturn(false);$reader->method('mustUseBounded')->willReturn(true);
        $locator=$this->createMock(MailMessageLocator::class);$locator->expects(self::never())->method('locate');$services=$this->createMock(MailServices::class);$services->expects(self::never())->method('clientFactory');
        $access=new MailAttachmentAccess($locator,$services,content:$reader);$this->expectException(ToolFailure::class);$access->list('alice',7);
    }
    public function testGenuinelyOlderArtifactKeepsLegacyBehavior():void {
        $reader=$this->createMock(MailContentReader::class);$reader->method('available')->willReturn(false);$reader->method('mustUseBounded')->willReturn(false);
        $locator=$this->createMock(MailMessageLocator::class);$locator->expects(self::once())->method('locate')->willThrowException(new ToolFailure('Legacy locator reached.'));
        $access=new MailAttachmentAccess($locator,$this->createMock(MailServices::class),content:$reader);$this->expectExceptionMessage('Legacy locator reached.');$access->list('alice',7);
    }
    public function testVersionMetadataNeverTreatsAnUnqualifiedNewArtifactAsOld():void {
        foreach(['5.12.5'=>false,'5.12.5.10'=>false,'5.12.5.12'=>true,'5.13.0'=>true,'broken'=>true] as $version=>$expected){$apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('getAppVersion')->willReturn($version);$b=new \OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary($apps);self::assertSame($expected,$b->currentContentGeneration(),$version);}
    }
    public function testCurrentDisplayUnavailableCannotReachLegacyManager():void {
        $reader=$this->createMock(MailContentReader::class);$reader->method('available')->willReturn(false);$reader->method('mustUseBounded')->willReturn(true);
        $services=$this->createMock(MailServices::class);$services->expects(self::never())->method('mailManager');
        $body=new \OCA\Mcp\Modules\Mail\Integration\MailBodyReader($services,new \OCA\Mcp\Modules\Mail\Integration\MailGateway(new FakeMailApp(),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),content:$reader);$this->expectException(ToolFailure::class);$body->read('alice',new \stdClass(),new \stdClass(),new \stdClass());
    }
    public function testThreadReaderPreservesItsIndependentOriginatingGrant():void {
        $reader=$this->createMock(MailContentReader::class);$reader->method('available')->willReturn(true);$reader->expects(self::once())->method('readBody')->with('alice',7,'mail_read_thread')->willReturn(['body_plain'=>'text','body_html'=>'','attachments'=>[],'reply_to'=>[]]);
        $services=$this->createMock(MailServices::class);$services->expects(self::never())->method('mailManager');$message=new class {public function getId():int {return 7;}};
        $body=new \OCA\Mcp\Modules\Mail\Integration\MailBodyReader($services,new \OCA\Mcp\Modules\Mail\Integration\MailGateway(new FakeMailApp(),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),content:$reader,sourceTool:'mail_read_thread');self::assertSame('text',$body->read('alice',new \stdClass(),new \stdClass(),$message)->getPlainBody());
    }
}
