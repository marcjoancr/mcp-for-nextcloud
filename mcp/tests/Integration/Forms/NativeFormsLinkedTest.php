<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsLinkedTest extends TestCase {
    protected function setUp(): void {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires disposable native cell');}
    }
    private function check(string $case): void {
        $process = proc_open([PHP_BINARY, '/tmp/linked-probe.php'], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        fwrite($pipes[0], json_encode(['case'=>$case])); fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err.' '.$out);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'],json_encode($result));
        foreach($result['result'] as $name=>$passed) {self::assertTrue($passed,$name);}
    }
    public function testLinkAssignsVerifiedTupleAtomicallyAndRetainsSignedOrigin(): void {$this->check('link');}
    public function testFailedVerificationAndUiAssociationCannotActivateMarker(): void {$this->check('failed_link');}
    public function testStableLeaseCheckpointCasAndExpiredWorkerRefusal(): void {$this->check('lease');}
    public function testStockWriterSuppressesMarkedTupleWithoutMcpOrActorSession(): void {$this->check('stock');}
    public function testNativeTransferAndUnlinkRetireOldAuthorityAndKeepOutput(): void {$this->check('lifecycle');}
    public function testOriginalRevocationPausesAndCurrentOwnerStatusIsPrivate(): void {$this->check('revoked');}
    public function testReadOnlyCycleRetirementRequiresExactEvidence(): void {$this->check('restart');}
    public function testDeferredSinkRefusesSessionAndWrongFilesystemRoot(): void {$this->check('context');}
    public function testVerifiedSyncReceiptAndDirtyGenerationSurviveOlderCycleCompletion(): void {$this->check('sync');}
    public function testUnknownSinkIsNeverReplayed(): void {$this->check('uncertain');}
    public function testExplicitUnlinkRequiresExactTupleAndVersionAndPreservesFile(): void {$this->check('unlink');}
    public function testNativeSubmissionQueuesOnlyLinkedDirtyGeneration(): void {$this->check('queue');}
    public function testNativeSchemaMutationMakesAssociationPending(): void {$this->check('schema');}
    public function testManualDestinationEditRefusesBeforeTrustedSink(): void {$this->check('manual');}
    public function testSourceChangedBeforeWriteRefusesBeforeTrustedSink(): void {$this->check('source_changed');}
    public function testNativeUiRelinkRetiresMarkerEvenForSameFile(): void {$this->check('ui_relink');}
    public function testNativeDeletionDisablesAutomationAndPreservesOutput(): void {$this->check('delete');}
    public function testCleanIsolatedChildWritesWithNullSessionAndOriginalFilesystemRoot(): void {$this->check('child');}
    public function testForeignDefaultFilesystemRootRefusesBeforeAnySink(): void {$this->check('wrong_root');}
    public function testNativeRecursiveFolderCleanupPreservesExactExportAfterUnlink(): void {$this->check('managed_folder');}
    public function testDeferredResponseFileReferencesRequireRetainedSelectedPermission(): void {$this->check('file_refs');}
    public function testRetainedOriginWithoutFilePermissionCannotReadReferences(): void {$this->check('no_file_refs');}
    public function testStaleStockJobBlockedByInitialActivationFenceCannotWrite(): void {$this->check('stock_race');}
    public function testDeadChildAfterDurableWritingClaimIsNeverReplayed(): void {$this->check('crashed_child');}
    public function testAssociationReadVerifiesOriginForAnUnlinkedForm(): void {$this->check('empty_association');}
    public function testExhaustedDirtyGenerationRefusesAndRollsBackSourceMutation(): void {$this->check('marker_exhausted');}
    public function testResponseCleanupPreservesActiveExportAndDeletesOrdinaryFolder(): void {$this->check('cleanup_response');}
    public function testResponseCleanupPreservesUnlinkedExport(): void {$this->check('cleanup_response_unlinked');}
    public function testQuestionCleanupPreservesActiveExportAndDeletesOrdinaryFolder(): void {$this->check('cleanup_question');}
    public function testQuestionCleanupPreservesUnlinkedExport(): void {$this->check('cleanup_question_unlinked');}
    public function testFormCleanupPreservesAnotherFormsUnlinkedExport(): void {$this->check('cleanup_cross_form');}
    public function testResponseCleanupPreservesAnotherFormsUnlinkedExport(): void {$this->check('cleanup_cross_response');}
    public function testQuestionCleanupPreservesAnotherFormsUnlinkedExport(): void {$this->check('cleanup_cross_question');}
    public function testRetentionLookupFailureKeepsCandidateFolders(): void {$this->check('cleanup_lookup_failure');}
}
