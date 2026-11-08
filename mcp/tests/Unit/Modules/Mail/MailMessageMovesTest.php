<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Messages\MailMessageMoves;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailMutationSession};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class MailMessageMovesTest extends TestCase {
    public function testApprovalBindsBothGenerationsAndRoleWithoutRetargetingAtDispatch():void {
        $t=$this->createMock(MailMutationTarget::class);$session=$this->createMock(MailMutationSession::class);
        $r=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'p','cache_revision'=>'c','action'=>'move','destination_mailbox_id'=>6,'destination_mailbox'=>'Reviewed','destination_generation'=>'43','destination_cache_revision'=>'d','roles_revision'=>'roles','requires_trash_hook'=>false];
        $live=$r;$reads=0;$t->method('snapshot')->willReturnCallback(static function()use(&$reads,&$live,$r):array{$reads++;return ['request'=>$live];});
        $session->expects(self::once())->method('move')->with('alice',$r)->willReturn(['ok'=>true]);
        $m=new MailMessageMoves($t,new PlanState((new InMemoryConfig())->mock($this)),$session);$a=['account_id'=>4,'message_id'=>17,'destination_mailbox_id'=>6];
        $p=$m->preview('alice',$a,'move');self::assertSame('Reviewed',$p['destination']['name']);self::assertSame('INBOX',$p['source']['name']);
        self::assertSame(['ok'=>true],$m->apply('alice',$a+['plan_state'=>$p['plan_state']],'move'));self::assertSame(2,$reads);
        $live['destination_generation']='44';$this->expectException(PlanChanged::class);$m->apply('alice',$a+['plan_state'=>$p['plan_state']],'move');
    }
}
