<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\{PlanChanged,PlanState};
use OCA\Mcp\Mcp\Operations\{OperationJournal,OperationStore};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
use OCA\Mcp\Modules\Mail\Transmission\{MailDeliveryOperations,ScheduleInstant};
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class MailDeliveryOperationsTest extends TestCase {
    public function testScheduleRequiresAnExplicitOffsetConsistentWithTheIanaZone():void {
        self::assertTrue(class_exists(ScheduleInstant::class),'Explicit, timezone-checked scheduling is not implemented.');
        $instant=ScheduleInstant::parse('2026-11-20T15:30:00+01:00','Europe/Madrid',strtotime('2026-11-11T00:00:00Z'));
        self::assertSame(strtotime('2026-11-20T14:30:00Z'),$instant);
        foreach([
            ['2026-11-20T15:30:00+01:00','UTC'],
            ['2027-03-28T02:30:00+01:00','Europe/Madrid'],
            ['2026-11-20T15:30:00','Europe/Madrid'],
            ['tomorrow','Europe/Madrid'],
            ['2026-11-31T15:30:00+01:00','Europe/Madrid'],
        ]as[$text,$zone]){
            try{ScheduleInstant::parse($text,$zone,strtotime('2026-11-11T00:00:00Z'));self::fail('Invalid or shifted scheduled instant accepted.');}
            catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }

    public function testChangedDraftAfterApprovalRefusesBeforeQueueAdmission():void {
        [$service,$gateway]=$this->service();
        $snapshot=['draftId'=>7,'accountId'=>3,'revision'=>str_repeat('a',64),'bodyPlain'=>'Approved body'];
        $gateway->method('preview')->willReturnOnConsecutiveCalls($snapshot,array_replace($snapshot,['revision'=>str_repeat('b',64),'bodyPlain'=>'Changed body']));
        $gateway->expects(self::never())->method('queue');
        $plan=$service->previewSend('alice',['draft_id'=>7]);
        $this->expectException(PlanChanged::class);
        $service->send('alice',['draft_id'=>7,'plan_state'=>$plan['plan_state']]);
    }

    public function testStatusRequiresTheOriginalConnectionAndCurrentSourceSelection():void {
        [$service,$gateway,$connection,$allowed]=$this->service();
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->expects(self::never())->method('status');
        $allowed->tools=[];
        try{$service->read('alice','opaque-id');self::fail('Revoked source tool exposed a delivery.');}catch(ToolFailure){self::assertTrue(true);}
        $allowed->tools=['mail_schedule'=>true];$connection->set(13);
        $this->expectException(ToolFailure::class);$service->read('alice','opaque-id');
    }

    public function testStatusRemovesUnexpectedPrivateFieldsAndNeverDispatches():void {
        [$service,$gateway]=$this->service();
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->method('status')->willReturn(['id'=>'opaque-id','state'=>'uncertain','sentState'=>'not_started','accountId'=>3,'draftId'=>7,'revision'=>str_repeat('a',64),'sendAt'=>123,'timezone'=>'UTC','createdAt'=>100,'bodyPlain'=>'private','credentials'=>'secret']);
        $gateway->expects(self::never())->method('dispatch');
        $result=$service->read('alice','opaque-id');
        self::assertSame('uncertain',$result['delivery']['state']);
        self::assertArrayNotHasKey('bodyPlain',$result['delivery']);self::assertArrayNotHasKey('credentials',$result['delivery']);
        self::assertFalse($result['safe_to_retry']);
    }

    public function testLostQueueAcknowledgementRecoversOnlyTheOriginalOperationWithoutWriting():void {
        self::assertTrue(method_exists(MailDeliveryOperations::class,'recover'),'Queue admission needs read-only recovery when its response checkpoint is lost.');
        [$service,$gateway,,,$journal]=$this->service();
        $id='original-operation-id';$key=hash('sha256',$journal->scopeKey('alice')."\0".$id);
        $gateway->expects(self::once())->method('findByOperation')->with('alice',$key)->willReturn(['id'=>'opaque-id','state'=>'queued','accountId'=>3,'smtp'=>'private']);
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->expects(self::never())->method('queue');$gateway->expects(self::never())->method('dispatch');$gateway->expects(self::never())->method('repairSent');
        self::assertSame(['id'=>'opaque-id','state'=>'queued','accountId'=>3],$service->recover('alice',$id,'mail_schedule'));
    }

    public function testCancellationCannotChangeTheQueueWithoutADurableReservation():void {
        [$service,$gateway]=$this->service();
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->method('status')->willReturn(['id'=>'opaque-id','state'=>'queued','accountId'=>3,'revision'=>str_repeat('a',64)]);
        $gateway->expects(self::never())->method('cancel');
        $plan=$service->previewCancel('alice',['delivery_id'=>'opaque-id']);
        $this->expectException(ToolFailure::class);
        $service->cancel('alice',['delivery_id'=>'opaque-id','plan_state'=>$plan['plan_state']]);
    }

    public function testBasicAndSessionAccessCannotCreateDeferredConsent():void {
        [$service,$gateway,$connection]=$this->service();$connection->clear();$gateway->expects(self::never())->method('preview');
        $this->expectException(ToolFailure::class);$service->previewSend('alice',['draft_id'=>7]);
    }
    public function testAutumnOffsetDisambiguatesTheSameLocalSchedule():void {
        $now=strtotime('2026-10-01T00:00:00Z');
        $early=ScheduleInstant::parse('2026-10-25T02:30:00+02:00','Europe/Madrid',$now);
        $late=ScheduleInstant::parse('2026-10-25T02:30:00+01:00','Europe/Madrid',$now);
        self::assertSame(3600,$late-$early);
    }
    private function service():array {
        self::assertTrue(class_exists(MailDeliveryGateway::class),'The guarded delivery boundary is not implemented.');
        $gateway=$this->createMock(MailDeliveryGateway::class);
        $gateway->method('enabled')->willReturn(true);
        $connection=new ConnectionContext();$connection->set(12);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(strtotime('2026-11-11T00:00:00Z'));
        $proof=new PlanState((new InMemoryConfig())->mock($this));
        $journal=new OperationJournal($this->createMock(OperationStore::class),$proof,$connection,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $allowed=(object)['tools'=>['mail_schedule'=>true,'mail_send'=>true,'mail_sent_repair'=>true]];
        return [new MailDeliveryOperations($gateway,$proof,$journal,$connection,$clock,static fn(string $uid,string $tool):bool=>isset($allowed->tools[$tool])),$gateway,$connection,$allowed,$journal];
    }
}
