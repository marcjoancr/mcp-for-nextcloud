<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Transmission\DeliveryTicket;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailDeliveryTicketTest extends TestCase {
    public function testFreshChildTicketBindsTheEntirePersistentQueueIdentityAndExpires():void {
        self::assertTrue(class_exists(DeliveryTicket::class),'Delivery children need a signed, expiring identity.');
        $now=1000;
        // Capture by reference so the same verifier observes elapsed time, as a running child does.
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(static function()use(&$now):int{return $now;});
        $tickets=new DeliveryTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $row=['uid'=>'alice','id'=>str_repeat('a',64),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];
        $t=$tickets->create($row);self::assertTrue($tickets->verify($t));
        foreach(['uid'=>'bob','id'=>str_repeat('b',64),'accountId'=>4,'connectionId'=>13,'sourceTool'=>'mail_send','expires_at'=>1001]as$key=>$value){
            self::assertFalse($tickets->verify(array_replace($t,[$key=>$value])),$key);
        }
        self::assertFalse($tickets->verify($t+['unexpected'=>true]));
        $repair=$tickets->create($row,'repair',str_repeat('c',64));
        self::assertTrue($tickets->verify($repair));self::assertSame('repair',$repair['action']);self::assertSame(str_repeat('c',64),$repair['repairRevision']);
        self::assertFalse($tickets->verify(array_replace($repair,['action'=>'dispatch'])));
        self::assertFalse($tickets->verify(array_replace($repair,['repairRevision'=>str_repeat('d',64)])));
        $audit=$tickets->create($row,'audit');self::assertTrue($tickets->verify($audit));self::assertFalse($tickets->verify(array_replace($audit,['action'=>'dispatch'])));
        $now=1026;self::assertFalse($tickets->verify($t));
    }
}
