<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsBulkTest extends TestCase {
 public function testReviewRegressionsArtifactsPreviewDeadlineAndRetainedCopies():void {$this->probe('bulk-r1-probe.php');}
 public function testSlowPageCommitsCooperativeProgressBeforeHardDeadline():void {$this->probe('bulk-r2-probe.php');}
 private function probe(string $file): void {
  if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}
  $p=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  self::assertSame(0,proc_close($p),$error.' '.$out);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],$out);
 }
 public function testBoundedDeletionAndCompleteRecoveryBeyondLegacyLimits():void {$this->probe('bulk-probe.php');}
 public function testExactManagedFilesAndCompleteRecoveryAcrossPages():void {$this->probe('bulk-files-probe.php');}
 public function testPersistentAuthorityReplaySourceAndByteBoundaries():void {$this->probe('bulk-policy-probe.php');}
 public function testTransferAtomicityRecoveryAndUncertainCopyNeverReplays():void {$this->probe('bulk-transfer-probe.php');}
 public function testBoundedCommentAndManagedShareCleanupRetainsUnrelatedObjects():void {$this->probe('bulk-cleanup-probe.php');}
}
