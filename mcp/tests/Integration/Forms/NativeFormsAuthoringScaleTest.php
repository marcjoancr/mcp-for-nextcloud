<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsAuthoringScaleTest extends TestCase {
 public function testPopulatedFormAuthoringPreservesUnrelatedResponsesAndCompleteRecovery():void {$this->probe('authoring-scale-probe.php');}
 public function testComplete101FileSharingRetainsOriginalUploadFolders():void {$this->probe('authoring-files-probe.php','metadata');}
 public function testComplete101FileQuestionRecoveryPreservesUnmanagedFiles():void {$this->probe('authoring-files-probe.php','question');}
 public function testLateQuestionChildSurvivesWithoutUncheckedFinalCascade():void {$this->probe('authoring-control-probe.php','late_child');}
 public function testAuthoringPreviewBindsAllRequestedFields():void {$this->probe('authoring-control-probe.php','preview_changed');}
 public function testConcurrentNativeEditRetiresUnappliedAuthoringJob():void {$this->probe('authoring-control-probe.php','source_changed');}
 public function testFilesGrantRevocationAfterRealFolderLockPreventsSharing():void {$this->probe('authoring-control-probe.php','lock_files');}
 public function testNativePrincipalRevocationAfterRealFolderLockPreventsSharing():void {$this->probe('authoring-control-probe.php','lock_native');}
 public function testMetadataAuthoringKeepsItsIndependentWriterFloor():void {$this->probe('authoring-control-probe.php','metadata_without_writer');}
 public function testImmediatePostcommitSharingKeepsExactCurrentControlWithoutLeakingToIndependentOrigins():void {$this->probe('authoring-postcommit-control-probe.php');}
 public function testFilesSharingRequiresTheOriginalDynamicallyApprovedGrant():void {$this->probe('authoring-admission-probe.php','missing');}
 public function testPublicLinkEditingIgnoresUnrelatedFilesAndNeverProjectsItsToken():void {$this->probe('authoring-admission-probe.php','public');}
 public function testNativeEditorLockIsCheckedBeforeQuestionRecoveryOrDeletion():void {$this->probe('authoring-native-lock-probe.php');}
 public function testProviderSinkRejectsChangedFileBytesAfterBeforeShareEvent():void {$this->probe('authoring-control-probe.php','lock_provider_content');}
 public function testLegacyNativeHiddenMissingFileRemainsExplicitThroughRecovery():void {$this->probe('authoring-history-probe.php','legacy');}
 public function testQuestionCleanupSupportsSubsequentAuthoringAndCurrentStateRecovery():void {$this->probe('authoring-history-probe.php','cleanup');}
 public function testExistingHiddenFilesRemainIncludedAndRecoverable():void {$this->probe('authoring-history-probe.php','existing');}
 public function testActiveMissingFileRefusesHistoryClassification():void {$this->probe('authoring-history-probe.php','active');}
 public function testForeignFileRefusesHistoryClassification():void {$this->probe('authoring-history-probe.php','foreign');}
 public function testUnmanagedFileStillPreventsSharingAfterHistory():void {$this->probe('authoring-history-probe.php','unmanaged');}
 public function testMovedExistingFileStillRefusesManagedSharing():void {$this->probe('authoring-history-probe.php','moved');}
 public function testPermissionFailureNeverBecomesHistoricalAbsence():void {$this->probe('authoring-history-probe.php','permission');}
 public function testIoFailureNeverBecomesHistoricalAbsence():void {$this->probe('authoring-history-probe.php','io');}
 public function testUnsupportedStorageNeverBecomesHistoricalAbsence():void {$this->probe('authoring-history-probe.php','unsupported');}
 public function testHistoricalTransferNormalizesOnlyUnavailableReferences():void {$this->probe('authoring-history-probe.php','transfer');}
 public function testMissingHistoryIndexFloorClosesV2ButPreservesV1RecoveryAndControl():void {$this->probe('authoring-history-probe.php','floor');}
 private function probe(string $file,string $variant='metadata'):void {
  if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}
  $old=getenv('MCP_AUTHORING_CASE');putenv('MCP_AUTHORING_CASE='.$variant);$p=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);putenv($old===false?'MCP_AUTHORING_CASE':'MCP_AUTHORING_CASE='.$old);self::assertSame(0,$exit,$err.' '.$out);self::assertTrue(json_decode($out,true,64,JSON_THROW_ON_ERROR)['ok'],$out);
 }
}
