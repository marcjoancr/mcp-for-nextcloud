<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsBulkControlAuthorityTest extends TestCase {
 public function testContinueGrantRevokedWhileWaitingForNativeTransaction():void {$this->probe('continue_grant');}
 public function testContinueSelectionRevokedWhileWaitingForSessionFence():void {$this->probe('continue_selection_advisory');}
 public function testCancellationGrantRevokedWhileWaitingForNativeTransaction():void {$this->probe('cancel_grant');}
 public function testCancellationSelectionRevokedWhileWaitingForNativeTransaction():void {$this->probe('cancel_selection');}
 public function testContinueAuthorityRevokedWhileWaitingForSourceRow():void {$this->probe('continue_grant_source');}
 public function testOriginalGrantStillRequiredAfterWait():void {$this->probe('continue_original_grant');}
 public function testOriginalSelectionStillRequiredAfterWait():void {$this->probe('continue_original_selection');}
 public function testCurrentControlRevokedAtActualFileLockRetainsUnknownClaim():void {$this->probe('continue_grant_file');}
 public function testExplicitPrivateOriginalFilesGrantRevokedAtActualFileLockRetainsRecovery():void {$this->probe('continue_scope_file');}
 public function testCurrentControlCompletes103ResponseRecoveryWithoutSourceWriter():void {$this->probe('1','bulk-probe.php','MCP_BULK_CURRENT_CONTROL');}
 public function testCurrentControlPreserves103GroupConsentWithinDeadline():void {$this->probe('group_many_control','bulk-shares-parity-probe.php','MCP_PARITY_CASE');}
 public function testMismatchedSignedControlCannotBorrowStoredAuthority():void {$this->probe('mismatch');}
 public function testCurrentControlRecheckedAfterTransferTargetRowWait():void {$this->probe('continue_grant_file_target');}
 public function testCurrentControlRecheckedAfterTransferRemapRowWait():void {$this->probe('continue_grant_file_remap');}
 public function testOriginalFileResourceScopeWithdrawnDuringActualFileWait():void {$this->probe('continue_resource_file');}
 public function testIndependentOriginFramesDoNotInheritBulkControl():void {$this->probe('frame','bulk-control-frame-probe.php');}
 public function testZeroCommentSourceRequiresQualifiedWriter():void {$this->probe('delete','bulk-comments-fallback-probe.php','MCP_COMMENTS_FALLBACK_CASE');}
 public function testResponseSnapshotRequiresQualifiedCommentWriter():void {$this->probe('edit','bulk-comments-fallback-probe.php','MCP_COMMENTS_FALLBACK_CASE');}
 private function probe(string $variant,string $file='bulk-control-authority-probe.php',string $variable='MCP_CONTROL_CASE'):void {
  if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable PostgreSQL native cell');}
  $previous=getenv($variable);putenv($variable.'='.$variant);$p=proc_open([PHP_BINARY,'/tmp/'.$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);putenv($previous===false?$variable:$variable.'='.$previous);self::assertSame(0,$exit,$error.' '.$out);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],$out);
 }
}
