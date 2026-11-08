<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Mutations\MutationTicket;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailMutationTicketTest extends TestCase {
    public function testTicketBindsIdentityEffectFenceAndExpiryWithoutCredentials():void {
        $now=1000;$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(static function()use(&$now):int{return $now;});
        $t=new MutationTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $r=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'profile','cache_revision'=>'cache'];
        $ticket=$t->create('alice',7,['scope'=>str_repeat('a',64),'id'=>'operation','token'=>str_repeat('b',64)],$r);self::assertTrue($t->verify($ticket));
        foreach(['uid','connection_id','operation','request','lease','expires_at'] as $key){$bad=$ticket;$bad[$key]=$key==='expires_at'?999:'tampered';self::assertFalse($t->verify($bad));}
        $bad=$ticket;$bad['password']='secret';self::assertFalse($t->verify($bad));
        $now=1026;self::assertFalse($t->verify($ticket));self::assertStringNotContainsString('password',json_encode($ticket));
    }
}
