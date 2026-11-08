<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailMailboxMutationsTest extends IntegrationTestCase {
    public function testInterruptedCreateNeverRepeatsOrDeduplicatesByName():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Custom native boundary only');$id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['normal','no','lost_ack','preexisting'] as $mode){$r=MailActor::run(MailActor::ALICE,'c2-protocol',['account_id'=>$id,'mode'=>$mode]);self::assertSame($mode==='preexisting'?0:1,$r['creates']);self::assertSame(0,$r['forbidden']);self::assertTrue($r['replayed']);self::assertSame($mode==='preexisting'?'failed_before_effect':'uncertain',$r['operation']['state']);if($mode==='normal')self::assertSame('created',$r['operation']['mutation']['remote']['outcome']);else self::assertArrayNotHasKey('remote',$r['operation']['mutation']);}
    }
    public function testNativeFoldersPreserveMessagesRolesAndNoSendState():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Custom native boundary only');
        $id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];$r=MailActor::run(MailActor::ALICE,'c2-native',['account_id'=>$id]);
        self::assertTrue($r['created']);self::assertTrue($r['renamed']);self::assertTrue($r['subscribed']);self::assertTrue($r['unsubscribed']);self::assertTrue($r['already_desired']);self::assertTrue($r['roles_preserved']);self::assertTrue($r['message_ids_preserved']);self::assertTrue($r['no_send']);
    }
    public function testCacheRevocationRaceAndEventFailureRetainRemoteProofWithoutReplay():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('V3 native guard only');$id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['cache_revoked','cache_race','event_failure']as$mode){$r=MailActor::run(MailActor::ALICE,'c2-fault',['account_id'=>$id,'mode'=>$mode]);self::assertSame('uncertain',$r['operation']['state']);self::assertSame('created',$r['operation']['mutation']['remote']['outcome']);self::assertTrue($r['replayed']);self::assertSame($mode==='event_failure',$r['cached']);self::assertSame($mode==='event_failure'?1:0,$r['events']);self::assertTrue($r['no_send']);}
    }

}
