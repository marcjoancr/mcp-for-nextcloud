<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailNativeMovesTest extends IntegrationTestCase {
    public function testCacheRevocationGenerationResetAndListenerFailureRetainProofAndNeverRepeat():void {
        if(!in_array(getenv('MCP_IT_MAIL_GUARD'),['1','v2'],true))self::markTestSkipped('Custom native boundary fixture only');
        $id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['cache_reset','source_cache_reset','cache_revoked','event_failure','hook_failure'] as $mode){
            $r=MailActor::run(MailActor::ALICE,'c3-fault',['account_id'=>$id,'mode'=>$mode]);self::assertSame('uncertain',$r['operation']['state']);self::assertTrue($r['replayed']);self::assertTrue($r['no_redispatch']);self::assertTrue($r['no_send']);
            self::assertSame($mode==='hook_failure'?5:4,$r['source_count']);self::assertSame($mode==='event_failure'?false:true,$r['source_cached']);
            self::assertSame(match($mode){'hook_failure'=>'preconditions_may_start','cache_revoked'=>'remote_verified',default=>'cache_pending'},$r['operation']['mutation']['phase']);
            if($mode!=='hook_failure')self::assertSame('moved',$r['operation']['mutation']['remote']['outcome']);
            else self::assertArrayNotHasKey('remote',$r['operation']['mutation']);
            self::assertSame($mode==='event_failure'?[0,1]:($mode==='hook_failure'?[1,0]:[0,0]),$r['event_counts']);
        }
    }
    public function testNativeMovePreservesDuplicateMessageIdsAndRemovesOnlyProvenSourceCache():void {
        if(!in_array(getenv('MCP_IT_MAIL_GUARD'),['1','v2'],true))self::markTestSkipped('Custom native boundary fixture only');
        $id=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];$r=MailActor::run(MailActor::ALICE,'c3-service',['account_id'=>$id]);
        self::assertTrue($r['source_cache_gone']);self::assertSame(4,$r['source_count']);self::assertSame(2,$r['destination_count']);self::assertSame(1,$r['destination_cached_count']);
        self::assertSame('moved',$r['proof']['outcome']);self::assertSame('source_removed',$r['cache']['state']);self::assertSame('native_sync_pending',$r['cache']['destination_state']);self::assertTrue($r['no_send']);
    }
    public function testCommandBoundMoveMappingAndFaultsNeverUseCopyDeleteOrHeuristics():void {
        if(!in_array(getenv('MCP_IT_MAIL_GUARD'),['1','v2'],true))self::markTestSkipped('Custom native boundary fixture only');
        $account=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        $before=['no_move','no_uidplus','nonsticky','source_generation','source_gone','destination_generation_before','destination_missing','destination_nonsticky','read_only_source','unexpected_copyuid'];
        foreach(['normal',...$before,'no','lost_ack','missing_map','wrong_source','wrong_destination','multiple','malformed','zero','duplicate_map','destination_generation_after'] as $mode){
            $r=MailActor::run(MailActor::ALICE,'c3-protocol',['account_id'=>$account,'mode'=>$mode]);
            self::assertSame(in_array($mode,$before,true)?0:1,$r['moves'],json_encode([$mode,$r]));self::assertSame(0,$r['forbidden']);
            self::assertSame($mode==='normal',$r['verified']);self::assertTrue($r['replayed']);self::assertFalse($r['operation']['safe_to_retry']);self::assertSame(in_array($mode,$before,true)?'failed_before_effect':'uncertain',$r['operation']['state']);
            if($mode==='normal'){self::assertSame('43',$r['proof']['destination_generation']);self::assertSame('17',$r['proof']['destination_uid']);}
            else self::assertSame([],$r['proof']);
        }
    }
}
