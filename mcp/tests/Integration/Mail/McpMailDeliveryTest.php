<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailDeliveryTest extends IntegrationTestCase {
    private function proof(string $scenario,array $extra=[]):array {
        if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Qualified native delivery fixture only.');
        $r=MailActor::run(MailActor::ALICE,'delivery-native',['scenario'=>$scenario]+$extra);if(isset($r['fixture_error']))self::fail(json_encode($r));self::assertTrue($r['implementation_available'],'Native immutable delivery is required.');return $r;
    }
    public function testAdmissionIsIdempotentPrivateAndProtectsEveryNativeSourcePath():void {$r=$this->proof('admission');foreach(['idempotent','opaque','receipt_private','operation_mismatch_refused','foreign_status_refused','released','smtp_unchanged'] as $key)self::assertTrue($r[$key],$key);foreach($r['holds'] as $key=>$held)self::assertTrue($held,$key);self::assertSame('cancelled',$r['cancelled']);}
    public function testChangedOrMissingAliasRefusesBeforeAdmission():void {$r=$this->proof('stale');self::assertTrue($r['changed_sender_refused']);self::assertTrue($r['missing_sender_refused']);self::assertTrue($r['smtp_unchanged']);}
    public function testMissingAttachmentCannotBeSilentlyOmitted():void {$r=$this->proof('missing_attachment');self::assertTrue($r['missing_attachment_refused']);self::assertTrue($r['smtp_unchanged']);}
    public function testDisabledGateCannotCreateLatentApproval():void {$r=$this->proof('disabled');self::assertTrue($r['disabled_refused']);self::assertSame([],$r['due']);self::assertTrue($r['smtp_unchanged']);}
    public function testCrashedAttemptNeverConstructsAnotherTransport():void {$r=$this->proof('crash');self::assertSame('uncertain',$r['state']);self::assertSame(0,$r['connections']);self::assertTrue($r['cancel_too_late']);}
    public function testRevokedCurrentFenceBlocksBeforeTransport():void {$r=$this->proof('revoked');self::assertSame('blocked',$r['state']);self::assertSame(0,$r['connections']);}
    public function testRawTlsAdapterPreservesFrozenBytesInBothPipeliningModes():void {foreach([false,true] as $pipeline){$r=$this->proof('protocol',['mode'=>'accept','pipelining'=>$pipeline]);self::assertSame('accepted',$r['state']);self::assertSame('pending',$r['sent_state']);self::assertSame(1,$r['smtp_submissions']);self::assertTrue($r['exact_wire_bytes']);self::assertTrue($r['bcc_private']);self::assertTrue($r['cancel_too_late']);}}
    public function testProtocolFaultsAreTerminalAndNeverResubmitEnvelope():void {foreach(['reject_rcpt','reject_data','disconnect_data','ack_lost','timeout_data'] as $mode){$r=$this->proof('protocol',['mode'=>$mode]);self::assertSame('uncertain',$r['state'],$mode);self::assertSame('uncertain',$r['replay_state'],$mode);self::assertTrue($r['replay_no_connection']);self::assertSame(1,$r['smtp_submissions'],$mode);}}
    public function testTwoWorkersAndCancellationShareThePhysicalOwnerLease():void {$r=$this->proof('concurrent');self::assertSame('accepted',$r['state']);self::assertSame([true,true],$r['contenders']);self::assertSame(1,$r['smtp_submissions']);}
    public function testLostAppendAcknowledgementCannotTriggerHordeBinaryRetryOrRepairReplay():void {$r=$this->proof('repair_lost');self::assertSame('uncertain',$r['copy_state']);self::assertSame(1,$r['copy_peer']['appends']);self::assertSame(0,$r['copy_peer']['searches']);self::assertTrue($r['copy_smtp_unchanged']);}
    public function testMalformedCopyProofAndOversizedLiteralRemainUncertainWithoutReplay():void {foreach(['repair_bad_literal','repair_bad_uid','repair_missing_uid'] as $scenario){$r=$this->proof($scenario);self::assertSame('uncertain',$r['copy_state'],$scenario);self::assertSame(1,$r['copy_peer']['appends'],$scenario);self::assertSame(0,$r['copy_peer']['searches'],$scenario);self::assertTrue($r['copy_smtp_unchanged']);}}
    public function testKilledBeforeCheckpointCanSubmitOnlyOnceAfterFreshDispatch():void {if(getenv('MCP_IT_DB')!=='pgsql')self::markTestSkipped('PostgreSQL kill fixture.');$r=$this->proof('kill_before');self::assertSame('queued',$r['state_after_kill']);self::assertSame('accepted',$r['state']);self::assertSame(1,$r['smtp_submissions']);}
    public function testKilledAfterDataAcceptanceBeforeDatabaseCommitCanNeverResubmit():void {if(getenv('MCP_IT_DB')!=='pgsql')self::markTestSkipped('PostgreSQL kill fixture.');$r=$this->proof('kill_after_data');self::assertTrue($r['kill_proof']['acceptance_persistence_blocked']);self::assertTrue($r['kill_proof']['server_accepted']);self::assertSame('uncertain',$r['state_after_kill']);self::assertSame('uncertain',$r['state']);self::assertSame(1,$r['smtp_submissions']);}
    public function testSentRepairCannotConstructSmtpOrAppendAgain():void {$r=$this->proof('repair');self::assertSame('verified',$r['copy_state']);self::assertTrue($r['copy_smtp_unchanged']);self::assertTrue($r['terminal_identity_released'],'Verified delivery must release account and alias administration.');}
}
