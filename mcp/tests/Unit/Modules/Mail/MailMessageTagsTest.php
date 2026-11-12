<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailTagTarget,MailMutationSession};
use OCA\Mcp\Modules\Mail\Tags\MailMessageTags;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class MailMessageTagsTest extends TestCase {
    public function testPreviewShowsOneOwnedTagAndChangedMetadataCannotUseAnOldApproval():void {
        $request=MailTagMutationRequestTest::request();$target=$this->createMock(MailTagTarget::class);$target->method('snapshot')->willReturnOnConsecutiveCalls(['request'=>$request,'before'=>false,'tag'=>['id'=>7,'name'=>'Reviewed','color'=>'#123456']],['request'=>array_replace($request,['tag_revision'=>str_repeat('c',64)]),'before'=>false,'tag'=>['id'=>7,'name'=>'Renamed','color'=>'#123456']]);
        $session=$this->createMock(MailMutationSession::class);$session->expects(self::never())->method('tag');$service=new MailMessageTags($target,new PlanState((new InMemoryConfig())->mock($this)),$session);$args=['account_id'=>4,'message_id'=>17,'tag_id'=>7,'value'=>true];$p=$service->preview('alice',$args);self::assertSame('Reviewed',$p['tag']['name']);self::assertFalse($p['before']);self::assertTrue($p['after']);$this->expectException(PlanChanged::class);$service->set('alice',$args+['plan_state'=>$p['plan_state']]);
    }
}
