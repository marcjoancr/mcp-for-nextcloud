<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Composition\ForwardComposer;
use OCA\Mcp\Modules\Mail\Integration\{MailMessageLocator,MailServices};
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler;
use PHPUnit\Framework\TestCase;
final class MailForwardTest extends TestCase {
    public function testForwardUsesReviewedQuoteWithoutReplyParentRecipientsOrImplicitAttachments():void {
        $locator=$this->createMock(MailMessageLocator::class);$locator->method('locate')->willReturn(['account'=>new class{public function getId():int{return 4;}},'message'=>new class{public function getId():int{return 7;}public function getUid():int{return 9;}},'mailbox'=>new class{public function getId():int{return 3;}public function getSyncNewToken():mixed{return null;}public function getSyncChangedToken():mixed{return null;}public function getSyncVanishedToken():mixed{return null;}}]);
        $row=['from'=>['count'=>1,'addresses'=>[['name'=>'Person','email'=>'person@example.test']]],'subject'=>'Source','date'=>'2026-11-08','body'=>['text'=>'Reviewed body','truncated'=>false],'bcc'=>['count'=>1,'addresses'=>[['email'=>'PRIVATE']]],'attachments'=>[['id'=>'2']]];
        $reader=$this->createMock(ReadMessageHandler::class);$reader->method('readRow')->willReturnCallback(static function()use(&$row):array{return $row;});$attachments=$this->createMock(MailAttachmentAccess::class);$attachments->expects(self::never())->method('read');$native=$this->createMock(MailServices::class);$native->expects(self::never())->method('attachmentService');
        $f=new ForwardComposer($locator,static fn(string $uid)=>$reader,$attachments,$native,static fn():bool=>true);
        $p=$f->prepare('alice',4,7,[],false);self::assertStringContainsString('Reviewed body',$p['body_plain']);self::assertSame([],$p['attachments']);self::assertStringNotContainsString('PRIVATE',json_encode($p));self::assertArrayNotHasKey('in_reply_to',$p);
        $row['body']['truncated']=true;self::assertNull($f->prepare('alice',4,7,[],true)['body_plain']);$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$f->prepare('alice',4,7,[],false);
    }
}
