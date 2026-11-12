<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Messages\MailMessageFlags;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailMutationSession};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class MailMessageFlagsTest extends TestCase {
    public function testPreviewHasNoEffectAndChangedNativeRevisionRequiresAnotherApproval():void {
        $target=$this->createMock(MailMutationTarget::class);$session=$this->createMock(MailMutationSession::class);$session->expects(self::never())->method('setFlag');
        $request=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'p','cache_revision'=>'c'];
        $target->method('snapshot')->willReturnCallback(static function()use(&$request):array{return ['request'=>$request,'before'=>false];});
        $f=new MailMessageFlags($target,new PlanState((new InMemoryConfig())->mock($this)),$session);$args=['account_id'=>4,'message_id'=>17,'flag'=>'seen','value'=>true];
        $p=$f->preview('alice',$args);self::assertFalse($p['before']);self::assertTrue($p['after']);self::assertStringNotContainsString('profile_revision',json_encode($p));
        $request['profile_revision']='changed';$this->expectException(PlanChanged::class);$f->set('alice',$args+['plan_state'=>$p['plan_state']]);
    }
    public function testDispatchCannotSwitchFromTheApprovedSnapshotToANewerGenerationOrProfile():void {
        $target=$this->createMock(MailMutationTarget::class);$session=$this->createMock(MailMutationSession::class);
        $approved=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'approved','cache_revision'=>'c'];
        $changed=array_replace($approved,['generation'=>'43','profile_revision'=>'different-server','cache_revision'=>'new-cache']);$reads=0;
        $target->method('snapshot')->willReturnCallback(static function()use(&$reads,$approved,$changed):array{$reads++;return ['request'=>$reads<3?$approved:$changed,'before'=>false];});
        $session->method('setFlag')->willReturnCallback(static function(string $uid,array $request)use($approved):array{self::assertSame($approved,$request,'Only the snapshot bound to the approved revision may be dispatched.');return [];});
        $f=new MailMessageFlags($target,new PlanState((new InMemoryConfig())->mock($this)),$session);$a=['account_id'=>4,'message_id'=>17,'flag'=>'seen','value'=>true];
        $p=$f->preview('alice',$a);try{$f->set('alice',$a+['plan_state'=>$p['plan_state']]);}catch(PlanChanged){}
    }
    public function testOnlyExplicitSeenAndFlaggedBooleansAreAccepted():void {
        $target=$this->createMock(MailMutationTarget::class);$target->expects(self::never())->method('snapshot');$session=$this->createMock(MailMutationSession::class);
        $f=new MailMessageFlags($target,new PlanState((new InMemoryConfig())->mock($this)),$session);
        $this->expectException(\InvalidArgumentException::class);$f->preview('alice',['account_id'=>4,'message_id'=>17,'flag'=>'deleted','value'=>true]);
    }
}
