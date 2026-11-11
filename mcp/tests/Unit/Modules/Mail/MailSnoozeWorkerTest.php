<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Snooze\{SnoozeTicket,SnoozeWorker};
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailSnoozeWorkerTest extends TestCase {
    private function tickets():SnoozeTicket {$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);return new SnoozeTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);}
    private function row():array{return ['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_snooze'];}
    private function origin():array{return ['accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_snooze','sourceMailboxId'=>7,'snoozeMailboxId'=>8,'operationKeyHash'=>str_repeat('b',64)];}
    public function testUnknownPolicyPreservesConsentAndHasNoEffect():void {
        $ticket=$this->tickets();$gateway=$this->createMock(MailSnoozeGateway::class);$gateway->method('workerContext')->willReturn($this->origin());$gateway->method('originalOperation')->willReturn('original');$gateway->expects(self::never())->method('dispatch');$gateway->expects(self::never())->method('review');
        $worker=new SnoozeWorker($ticket,$gateway,static fn()=>throw new \RuntimeException('Registry unavailable'));self::assertSame('unknown',$worker->run($ticket->create($this->row()))['status']);
    }
    public function testRevocationAtTheLastFenceStopsWire():void {
        $ticket=$this->tickets();$origin=$this->origin();$gateway=$this->createMock(MailSnoozeGateway::class);$gateway->method('workerContext')->willReturn($origin);$gateway->method('originalOperation')->willReturn('original');$gateway->method('denied')->willReturn(new \RuntimeException('Withdrawn'));$wire=0;$checks=0;
        $gateway->method('dispatch')->willReturnCallback(function($uid,$id,$revision,$fence)use($origin,&$wire):array{$fence->authorize($uid,$origin);$wire++;return [];});
        $worker=new SnoozeWorker($ticket,$gateway,static function()use(&$checks):bool{return ++$checks===1;});self::assertSame('unknown',$worker->run($ticket->create($this->row()))['status']);self::assertSame(0,$wire);self::assertSame(2,$checks);
    }
    public function testForgedWakeRevisionAndOriginalConnectionCannotReachNative():void {
        $ticket=$this->tickets();$gateway=$this->createMock(MailSnoozeGateway::class);$gateway->expects(self::never())->method('workerContext');$worker=new SnoozeWorker($ticket,$gateway,static fn()=>true);$t=$ticket->create($this->row(),'wake',str_repeat('c',64),'operation');$t['revision']=str_repeat('d',64);self::assertSame('refused',$worker->run($t)['status']);$t=$ticket->create($this->row());$t['connectionId']=13;self::assertSame('refused',$worker->run($t)['status']);
    }
}
