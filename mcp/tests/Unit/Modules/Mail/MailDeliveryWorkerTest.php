<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Transmission\{DeliveryTicket,DeliveryWorker};
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailDeliveryWorkerTest extends TestCase {
    public function testRevokedSourceBetweenClaimAndWireCannotDispatch():void {
        self::assertTrue(class_exists(DeliveryWorker::class),'Delivery effects need a bounded signed child with fresh live fences.');
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $tickets=new DeliveryTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $row=['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];
        $gateway=$this->createMock(MailDeliveryGateway::class);
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $checks=0;
        $gateway->expects(self::once())->method('dispatch')->willReturnCallback(function($uid,$id,$fence){
            $fence->authorize($uid,12,'mail_schedule',3);
            $fence->authorize($uid,12,'mail_schedule',3);
            self::fail('Revoked source reached SMTP.');
        });
        $worker=new DeliveryWorker($tickets,$gateway,static function()use(&$checks):bool{return ++$checks<3;});
        self::assertSame(['status'=>'unknown'],$worker->run($tickets->create($row)));
        self::assertSame(3,$checks);
    }
    public function testDefiniteRevocationBlocksBeforeWireButNeverDispatches():void {
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $tickets=new DeliveryTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $row=['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];
        $gateway=$this->createMock(MailDeliveryGateway::class);$gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->expects(self::once())->method('blockBeforeWire')->with('alice',$row['id'])->willReturn(['state'=>'blocked']);$gateway->expects(self::never())->method('dispatch');
        self::assertSame(['status'=>'refused'],(new DeliveryWorker($tickets,$gateway,static fn()=>false))->run($tickets->create($row)));
    }
    public function testUnavailablePolicyNeverInvalidatesTheQueuedConsent():void {
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$tickets=new DeliveryTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $row=['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];$gateway=$this->createMock(MailDeliveryGateway::class);
        $gateway->expects(self::never())->method('blockBeforeWire');$gateway->expects(self::never())->method('dispatch');
        $worker=new DeliveryWorker($tickets,$gateway,static fn()=>throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('Synthetic policy read unavailable.'));
        self::assertSame(['status'=>'unknown'],$worker->run($tickets->create($row)));
    }
    public function testRepairChildBindsSourceTupleAndCannotDispatchSmtp():void {
        self::assertTrue(class_exists(DeliveryWorker::class));
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $tickets=new DeliveryTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $row=['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];
        $gateway=$this->createMock(MailDeliveryGateway::class);
        $gateway->method('accessContext')->willReturn(['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule','draftId'=>7]);
        $gateway->expects(self::never())->method('dispatch');
        $gateway->expects(self::once())->method('repairSent')->with('alice',$row['id'],str_repeat('c',64),self::anything())->willReturnCallback(function($uid,$id,$revision,$fence){$fence->authorize($uid,12,'mail_schedule',3);$fence->authorize($uid,12,'mail_sent_repair',3);return [];});
        $worker=new DeliveryWorker($tickets,$gateway,static fn()=>true);
        self::assertSame(['status'=>'completed'],$worker->run($tickets->create($row,'repair',str_repeat('c',64))));
    }
}
