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
    public function testMoveTicketSignsBothGenerationsRolesAndOriginalActionAndRejectsNativeOptions():void {
        $clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$t=new MutationTicket(new PlanState((new InMemoryConfig())->mock($this)),$clock);
        $r=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'p','cache_revision'=>'c','action'=>'move','destination_mailbox_id'=>6,'destination_mailbox'=>'Reviewed','destination_generation'=>'43','destination_cache_revision'=>'d','roles_revision'=>'roles','requires_trash_hook'=>false];
        $ticket=$t->create('alice',7,['scope'=>str_repeat('a',64),'id'=>'operation','token'=>str_repeat('b',64)],$r);self::assertTrue($t->verify($ticket));
        foreach(['generation','destination_generation','roles_revision','profile_revision','action','destination_mailbox_id','requires_trash_hook'] as $key){$bad=$ticket;$bad['request'][$key]=match($key){'destination_mailbox_id'=>7,'requires_trash_hook'=>true,'action'=>'trash',default=>'changed'};self::assertFalse($t->verify($bad));}
        foreach(['force_map'=>true,'create'=>true,'command'=>'EXPUNGE','password'=>'fixture-not-secret'] as $key=>$value){$bad=$ticket;$bad['request'][$key]=$value;self::assertFalse($t->verify($bad));}
        self::assertSame('mail_move_message',\OCA\Mcp\Modules\Mail\Mutations\MutationRequest::tool($r));
    }

}
