<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Tags\MailTagCreation;
use OCA\Mcp\Modules\Mail\Integration\MailTagGateway;
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Db\{OperationsMapper,MailProbeSlotsMapper};
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailTagCreationTest extends TestCase {
    public function testReviewedCreationCheckpointsIdentityAndRepeatsWithoutNativeInsert():void {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000(),new \OCA\Mcp\Migration\Version002700Date20261108000000()]);$db=$sql->connection();$states=new PlanState((new InMemoryConfig())->mock($this));$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$journal=new OperationJournal(new OperationsMapper($db),$states,new ConnectionContext(),$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $gateway=$this->createMock(MailTagGateway::class);$prepared=['name'=>'Reviewed','color'=>'#123456','imap_label'=>'$reviewed','inventory_revision'=>'before'];$gateway->method('prepare')->willReturn($prepared);$gateway->expects(self::once())->method('insert')->with('alice',$prepared)->willReturn(7);$gateway->method('read')->willReturn(['tag_id'=>7,'name'=>'Reviewed','color'=>'#123456','imap_label'=>'$reviewed']);
        $service=new MailTagCreation($gateway,$states,$journal,$db,new MailProbeSlotsMapper($db),$clock,static fn():bool=>true);$args=['name'=>'Reviewed','color'=>'#123456'];$plan=$service->preview($args,'alice');self::assertSame('all_owned_accounts',$plan['scope']);self::assertSame('Reviewed',$plan['after']['name']);$plan=$journal->plan('mail_create_tag','alice',$args,$plan);$args+=['plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']];
        $r=$journal->execute('mail_create_tag','alice',$args,fn():array=>$service->write($args,'alice'));self::assertSame('succeeded',$r['operation']['state']);self::assertSame(7,$r['operation']['resource_refs']['tag_id']);self::assertArrayNotHasKey('imap_label',$r['tag']);self::assertTrue($journal->execute('mail_create_tag','alice',$args,static fn():array=>throw new \LogicException('Never twice'))['operation']['replayed']);
    }
    public function testChangedInventoryRequiresFreshReviewBeforeAnyInsert():void {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000(),new \OCA\Mcp\Migration\Version002700Date20261108000000()]);$db=$sql->connection();$states=new PlanState((new InMemoryConfig())->mock($this));$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$j=new OperationJournal(new OperationsMapper($db),$states,new ConnectionContext(),$clock, new \OCA\Mcp\Tests\Support\OperationActors());$g=$this->createMock(MailTagGateway::class);$g->method('prepare')->willReturnOnConsecutiveCalls(['name'=>'Reviewed','color'=>'#123456','imap_label'=>'$reviewed','inventory_revision'=>'old'],['name'=>'Reviewed','color'=>'#123456','imap_label'=>'$reviewed','inventory_revision'=>'changed']);$g->expects(self::never())->method('insert');$s=new MailTagCreation($g,$states,$j,$db,new MailProbeSlotsMapper($db),$clock,static fn():bool=>true);$args=['name'=>'Reviewed','color'=>'#123456'];$p=$s->preview($args,'alice');$this->expectException(PlanChanged::class);$s->write($args+['plan_state'=>$p['plan_state']],'alice');
    }
}
