<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailDraftConcurrencyTest extends IntegrationTestCase {
    private function proof(string $mode):void {
        if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Qualified native draft lifecycle only.');
        $proof=MailActor::run(MailActor::ALICE,'draft-stale-conversion',['mode'=>$mode]);
        self::assertTrue($proof['refused'],'A cached native converter must refuse a newer persisted draft.');
        self::assertTrue($proof['new_body_retained']);self::assertTrue($proof['new_attachments_retained']);
        self::assertSame(0,$proof['imap_appends']);self::assertTrue($proof['files_unchanged']);self::assertTrue($proof['no_send']);
    }
    public function testBackgroundSnapshotCannotAppendOrDeleteAReviewedLegacyAdoption():void {$this->proof('automatic');}
    public function testExplicitConversionSnapshotCannotDeleteLaterBodyOrAttachmentEdits():void {$this->proof('explicit');}
    private function coordinated(string $mode):array {
        if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Qualified native draft lifecycle only.');
        $r=MailActor::run(MailActor::ALICE,'draft-coordinated',['mode'=>$mode]);self::assertTrue($r['no_send']);return $r;
    }
    public function testOwnerSessionSpansNativeBodyRecipientAndAttachmentUpdate():void {
        $r=$this->coordinated('stage');self::assertTrue($r['fired']);self::assertSame(['write'=>false,'cleanup'=>false],$r['child']);self::assertSame('Parent staged body',$r['body']);self::assertTrue($r['bytes_retained']);self::assertSame(0,$r['imap_appends']);
    }
    public function testOwnerSessionSpansAppendAndLocalCleanup():void {
        $r=$this->coordinated('append');self::assertTrue($r['fired']);self::assertSame(['write'=>false,'cleanup'=>false],$r['child']);self::assertFalse($r['refused']);self::assertFalse($r['exists']);self::assertSame(1,$r['imap_appends']);
    }
    public function testLostPhysicalSessionRefusesBeforeAppendAndPreservesSuccessor():void {
        $r=$this->coordinated('loss_before');self::assertTrue($r['fired']);self::assertTrue($r['child']['write']);self::assertFalse($r['child']['cleanup']);self::assertTrue($r['refused']);self::assertSame('Successor native body',$r['body']);self::assertTrue($r['bytes_retained']);self::assertSame(0,$r['imap_appends']);
    }
    public function testLostPhysicalSessionAfterAppendCannotDeleteSuccessor():void {
        $r=$this->coordinated('loss_after');self::assertTrue($r['fired']);self::assertTrue($r['child']['write']);self::assertFalse($r['child']['cleanup']);self::assertTrue($r['refused']);self::assertSame('Successor native body',$r['body']);self::assertTrue($r['bytes_retained']);self::assertSame(1,$r['imap_appends']);
    }
    public function testProtectedAttachmentCannotBeReassignedOrDeletedByPreviousParent():void {
        $r=$this->coordinated('attachments');self::assertTrue($r['refused']);self::assertTrue($r['wrong_parent']);self::assertTrue($r['orphan_retained']);self::assertTrue($r['bytes_retained']);self::assertSame(0,$r['imap_appends']);
    }

}
