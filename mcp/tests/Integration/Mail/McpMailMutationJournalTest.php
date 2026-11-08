<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Mcp\Operations\{OperationJournal,OperationStore};
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
/** Separate PHP/DB actors, exercising the same CAS SQL as the signed writer on real MariaDB. */
#[Group('mail')]
final class McpMailMutationJournalTest extends IntegrationTestCase {
    public function testChildProofWinsOverStaleParentAndUncertainClaimBlocksAnotherOperation():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Guard native fixture only');
        $j=Server::get(OperationJournal::class);$s=Server::get(OperationStore::class);$uid=MailActor::ALICE;$account=random_int(1000000000,2000000000);
        $r=['account_id'=>$account,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'fixture','cache_revision'=>'fixture'];
        $args=['account_id'=>$account,'message_id'=>17,'flag'=>'seen','value'=>true];$plan=$j->plan('mail_set_message_flag',$uid,$args,['plan_state'=>'fixture']);$approved=$args+['plan_state'=>'fixture','operation_id'=>$plan['operation_id']];$lease=null;
        $out=$j->execute('mail_set_message_flag',$uid,$approved,function()use($j,$s,$uid,$r,&$lease):array{
            $lease=$j->delegateNative($uid,$r);
            $advance=MailActor::run($uid,'c1-journal-advance',['lease'=>$lease]);self::assertTrue($advance['wire']);self::assertTrue($advance['proof']);
            self::assertFalse($s->finishNative($lease['scope'],$lease['id'],$lease['token'],'prepared','failed_before_effect',[],time()));
            throw new \RuntimeException('Parent lost child response');
        });
        self::assertSame('uncertain',$out['operation']['state']);self::assertSame('remote_verified',$out['operation']['mutation']['phase']);
        self::assertNotNull($s->get($lease['scope'],$lease['id'])['target_key']);self::assertFalse($s->checkpointNative($lease['scope'],$lease['id'],$lease['token'],'remote_verified',['phase'=>'cache_pending']));
        $repeat=$j->execute('mail_set_message_flag',$uid,$approved,static fn():array=>throw new \LogicException('No second writer'));self::assertTrue($repeat['operation']['replayed']);
        $new=$j->plan('mail_set_message_flag',$uid,$args,['plan_state'=>'fresh']);$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $j->execute('mail_set_message_flag',$uid,$args+['operation_id'=>$new['operation_id'],'plan_state'=>'fresh'],static fn():array=>throw new \LogicException('Claim bypassed'));
    }
}
