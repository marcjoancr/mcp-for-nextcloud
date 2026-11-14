<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsBulkParityTest extends TestCase {
 public function testHistoricalCommentRecoveryHasBoundedReadableThreadsWithoutReposting():void {$this->probe('bulk-comments-parity-probe.php');}
 public function testTransferStagesExactTargetOwnedSharesBeforeAtomicOwnerSwitch():void {$this->probe('bulk-shares-parity-probe.php');}
 public function testGroupTransferPreservesExactAudience():void {$this->probe('bulk-shares-parity-probe.php','group');}
 public function testOriginalNativeRestrictionStopsTransfer():void {$this->probe('bulk-shares-parity-probe.php','deny_source');}
 public function testTargetNativeRestrictionStopsTransfer():void {$this->probe('bulk-shares-parity-probe.php','deny_target');}
 public function testUnknownShareAcknowledgementNeverReplays():void {$this->probe('bulk-shares-parity-probe.php','provider_unknown');}
 public function testOriginalAuthorityRevokedAtProviderBoundaryStopsTransfer():void {$this->probe('bulk-shares-parity-probe.php','postlock_revoked');}
 public function testMovedEmptyManagedFolderInvalidatesPreview():void {$this->probe('bulk-shares-parity-probe.php','preview_move');}
 public function testCommittedStepReceiptIsReadableFromFreshProcess():void {$this->probe('bulk-step-evidence-probe.php');}
 public function testCancellationAccountsForRetainedShareOnlyStaging():void {$this->probe('bulk-shares-parity-probe.php','cancel_staged');}
 public function testGroupAcceptanceBeyondOnePageIsPreserved():void {$this->probe('bulk-shares-parity-probe.php','group_many');}
 public function testTransferRetiresLinkedOutputAndKeepsNewOwnerSubmissionsUsable():void {$this->probe('bulk-linked-transfer-probe.php');}
 public function testRecoveredOwnershipCannotBypassLiveSourceCommentPolicy():void {$this->probe('bulk-comments-source-probe.php');}
 public function testProviderCannotWidenApprovedShareRestrictions():void {$this->probe('bulk-shares-parity-probe.php','metadata_widen');}
 public function testPostProviderEventCannotWidenApprovedMetadata():void {$this->probe('bulk-shares-parity-probe.php','post_metadata_widen');}
 public function testAutoAcceptanceNeverExposesDeclinedRecipientDuringStaging():void {$this->probe('bulk-shares-parity-probe.php','group_auto_stage');}
 public function testPreviewBindsTargetIncarnationWithoutCommentGuard():void {$this->probe('bulk-target-principal-probe.php','before_start');}
 public function testFileFreeCommitBindsTargetIncarnationWithoutCommentGuard():void {$this->probe('bulk-target-principal-probe.php','before_commit');}
 public function testAutoAcceptancePreservesPendingRejectedAndDeclinedWithoutDuplicates():void {$this->probe('bulk-shares-parity-probe.php','group_auto_complete');}
 public function testAutoAcceptanceUnknownAcknowledgementNeverReplays():void {$this->probe('bulk-shares-parity-probe.php','group_auto_unknown');}
 public function testUnfamiliarAcceptanceRegistrationClosesTransferBeforeEffects():void {$this->probe('bulk-shares-parity-probe.php','registration_gap');}
 public function testAliasedAcceptanceServiceClosesTransferBeforeEffects():void {$this->probe('bulk-shares-parity-probe.php','registration_alias');}
 private function probe(string $file,string $variant='user'):void {
  if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}
  $previous=getenv('MCP_PARITY_CASE');putenv('MCP_PARITY_CASE='.$variant);$p=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);putenv($previous===false?'MCP_PARITY_CASE':'MCP_PARITY_CASE='.$previous);self::assertSame(0,$exit,$error.' '.$out);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],$out);
 }
}
