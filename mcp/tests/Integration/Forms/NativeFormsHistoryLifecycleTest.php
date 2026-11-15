<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsHistoryLifecycleTest extends TestCase {
 public function testUnavailableHistorySurvivesRepeatedOwnershipAndRecovery():void {$this->probe('history-lifecycle-probe.php');}
 public function testActualRestoreDestinationUsesCurrentFilesPolicyAtWriteSink():void {$this->probe('history-destination-probe.php');}
 public function testCrossOwnerDestinationUsesActingAccountScopeAndPreservesOriginalBytes():void {$this->probe('history-transfer-destination-probe.php');}
 public function testTargetPrincipalChangeBeforeCommitPreservesPositiveHistoryAndSealedArchive():void {$this->probe('history-transition-probe.php');}
 public function testTargetEnrollmentRequiresAuthorizedSourceOwnershipAndGrantsNoRecipientAccess():void {$this->probe('history-enrollment-probe.php');}
 public function testNativeCreatePermissionRevokedAfterRealLockCannotUseWarmFolderPermissions():void {$this->probe('history-native-acl-probe.php');}
 public function testNativeUiOwnershipWithMcpDisabledPreservesPositiveHistory():void {$this->nativeUi('positive');}
 public function testNativeUiOwnershipWithMcpDisabledPreservesRestoredNullHistory():void {$this->nativeUi('restored');}
 public function testMcpTransferThenNativeUiOwnershipAllowsFreshHistoryAndRecovery():void {$this->nativeUi('transferred');}
 public function testNativeUiLegacyRestoreBridgeUsesExactOriginalGeneration():void {$this->nativeUi('legacy');}
 public function testMissingNativeHistoryMigrationClosesV2AndPreservesV1Controls():void {putenv('MCP_AUTHORING_CASE=nativefloor');try{$this->probe('authoring-history-probe.php');}finally{putenv('MCP_AUTHORING_CASE');}}
 public function testNativeUiMinimalHistoryIgnoresUnrelatedResponseVolume():void {$this->nativeUi('scale');}
 public function testNativeUiAffectedSubmissionBudgetRefusesBeforeOwnership():void {$this->nativeUi('submission-budget');}
 public function testNativeUiAffectedBytesHaveCumulativeOperationBudget():void {$this->nativeUi('byte-budget');}
 private function nativeUi(string $variant):void {putenv('MCP_NATIVE_UI_HISTORY='.$variant);try{$this->probe('history-native-ui-probe.php');}finally{putenv('MCP_NATIVE_UI_HISTORY');}}
 private function probe(string $file):void {
  if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}
  $p=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);self::assertSame(0,$exit,$err.' '.$out);self::assertTrue(json_decode($out,true,64,JSON_THROW_ON_ERROR)['ok'],$out);
 }
}
