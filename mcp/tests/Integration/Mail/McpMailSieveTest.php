<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailSieveTest extends IntegrationTestCase {
    public function testDurableNativeInstallAndLostAcknowledgementsNeverReplayEffects():void {
        foreach(['normal'=>'completed','lost_put'=>'put_attempting','lost_active'=>'activation_attempting','failed_readback'=>'activation_attempting','external_before_active'=>'installed','external_after_readback'=>'completed']as$scenario=>$phase){
            $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>$scenario]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['implementation_available']);self::assertSame($phase,$r['phase'],$scenario);self::assertTrue($r['replay']);self::assertTrue($r['no_raw']);self::assertTrue($r['transmit_refused']);self::assertTrue($r['vacation_refused']);self::assertTrue($r['operator_off']);self::assertSame(1,$r['peer']['commands']['PUTSCRIPT']);self::assertSame(in_array($scenario,['lost_put','external_before_active'],true)?0:1,$r['peer']['commands']['SETACTIVE']??0);self::assertArrayNotHasKey('DELETESCRIPT',$r['peer']['commands']);self::assertTrue($r['peer']['original_retained']);self::assertFalse($r['status']['external_cas']);self::assertFalse($r['status']['safe_to_retry']);self::assertFalse($r['status']['history_proven']);
            if($scenario==='external_after_readback')self::assertSame('original_active',$r['status']['observation']);
            if($scenario==='normal'){self::assertTrue($r['stale_refused']);self::assertTrue($r['secret_revision_bumped']);self::assertTrue($r['filters_match']);}
        }
    }
    public function testActualToolsRetainOriginalConnectionWholeAccountAndSourceGrant():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'mcp']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['fpm_unqualified_refused','completed','replayed','private_origin_hidden','gate_denied','other_connection_denied','scope_status_denied','scope_replay_denied','source_grant_denied','operator_off']as$key)self::assertTrue($r[$key],$key);self::assertSame('candidate_active',$r['status']);self::assertSame('candidate_active',$r['scope_restored']);self::assertSame(1,$r['peer']['commands']['PUTSCRIPT']);self::assertSame(1,$r['peer']['commands']['SETACTIVE']);
    }
    public function testLocalSystemFollowCommitRechecksCurrentAuthorityAndRollsBack():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'disable_follow_revoke']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['precommit_reached']);self::assertTrue($r['follow_preserved']);self::assertSame('active_observed',$r['phase']);self::assertSame(1,$r['peer']['commands']['SETACTIVE']);
    }
    public function testRecognizedAbsenceEnableAndSafeDisablePreserveObservedRemoteProof():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'absence_cycle']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['enable_phase','disable_phase']as$k)self::assertSame('completed',$r[$k]);foreach(['following','safe_filters_refused','disabled','operator_off']as$k)self::assertTrue($r[$k],$k);foreach(['enabled_status','disabled_status']as$k)self::assertSame('candidate_active',$r[$k]);self::assertSame(2,$r['peer']['commands']['PUTSCRIPT']);self::assertSame(2,$r['peer']['commands']['SETACTIVE']);self::assertTrue($r['peer']['no_delivery_backend']);
    }
    public function testUnknownExecutableAndCandidateCollisionRefuseBeforeAnyEffect():void {
        foreach(['opaque','collision']as$scenario){$r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>$scenario]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertSame($scenario==='opaque',$r['opaque']);self::assertTrue($r['refused']);self::assertArrayNotHasKey('PUTSCRIPT',$r['peer']['commands']);self::assertArrayNotHasKey('SETACTIVE',$r['peer']['commands']);self::assertTrue($r['operator_off']);}
    }
    public function testNativeProjectionWithdrawalRetainsRemoteProofWithoutPublicReceiptOrReplay():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'native_projection']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertSame(['preview_hidden'=>true,'catch_hidden'=>true,'status_hidden'=>true,'replay_hidden'=>true],array_intersect_key($r,array_flip(['preview_hidden','catch_hidden','status_hidden','replay_hidden'])));self::assertSame('active_observed',$r['private_phase']);self::assertSame(1,$r['peer']['commands']['PUTSCRIPT']);self::assertSame(1,$r['peer']['commands']['SETACTIVE']);self::assertTrue($r['operator_off']);
    }
    public function testActualScopeWithdrawalDuringNativePreviewCannotReuseOuterPolicySnapshot():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'projection_mcp']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['snapshot_materialized','revocation_applied','projection_hidden','no_private_fields','operator_off']as$k)self::assertTrue($r[$k],$k);self::assertArrayNotHasKey('PUTSCRIPT',$r['peer']['commands']);self::assertArrayNotHasKey('SETACTIVE',$r['peer']['commands']);
    }
    public static function readParserModes():array {return [['read_absence_private'],['read_filter_private']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('readParserModes')]
    public function testNativeReadParserErrorsDiscardRawScriptStateAndPreviousTraces(string $mode):void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>$mode]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['refused','fixed_error','no_previous','trace_private','operator_off']as$key)self::assertTrue($r[$key],$mode.':'.$key);self::assertArrayNotHasKey('PUTSCRIPT',$r['peer']['commands']);self::assertArrayNotHasKey('SETACTIVE',$r['peer']['commands']);
    }
    public function testNativeDebugFactoryNeverLogsAuthenticationOrRawProgram():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'native_logger']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['protocol_log_absent']);self::assertTrue($r['operator_off']);
    }
    public function testCapturedNativeErasureQuarantinesAndPurgesSensitiveOrphanCandidates():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-erasure');self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['deleted']);self::assertSame(2,$r['busy']);self::assertTrue($r['quarantined']);self::assertSame(0,$r['after']);self::assertSame(0,$r['accounts']);self::assertTrue($r['unrelated']);
    }
    public function testPhysicalOwnerExcludesNativeFilterAbsenceAndRawReadBuildWrite():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Native fixture only.');$r=MailActor::run(MailActor::ALICE,'sieve-lease',['mode'=>'parent']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach($r as$path=>$value)self::assertTrue($value,$path.' must refuse before its first native read.');
    }
}
