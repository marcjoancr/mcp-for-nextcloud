<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class FormsStorageAdmissionTest extends TestCase {
    protected function setUp(): void {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires a disposable native Forms storage cell');}
    }
    private function probe(string $case): array {
        $process = proc_open([PHP_BINARY, getenv('MCP_FORMS_STORAGE_PROBE') ?: '/tmp/storage-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['case' => $case, 'uid' => getenv('MCP_FORMS_NATIVE_OWNER') ?: 'guard-owner'])); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'], $output);
        return $result['result'];
    }
    private function assertRefused(string $case): void {
        foreach ($this->probe($case) as $boundary => $preserved) {self::assertTrue($preserved, $case . ' failed ' . $boundary);}
    }
    public function testUnknownWrapperIsRefusedBeforeRecoveryOrDeletion(): void {$this->assertRefused('unknown_wrapper');}
    public function testUnknownLocalSubclassIsRefusedBeforeRecoveryOrDeletion(): void {$this->assertRefused('unknown_backend');}
    public function testUnknownTransferDestinationIsRefusedBeforeBackupOrOwnershipChange(): void {$this->assertRefused('unknown_destination');}
    public function testUnknownPrivateRecoveryStorageIsRefusedBeforeEffects(): void {$this->assertRefused('private_recovery');}
    public function testUnsupportedRuntimeConfigurationIsRefusedBeforeEffects(): void {$this->assertRefused('configuration');}
    public function testChangedPinnedWrapperSourceIsRefusedBeforeEffects(): void {$this->assertRefused('source_drift');}
    public function testScopeRevocationAfterExclusiveLockPreservesNativeFiles(): void {$this->assertRefused('post_lock_scope');}
    public function testUploadSourceScopeIsRecheckedAfterStreamOpenAndExclusiveLock(): void {$this->assertRefused('post_lock_upload_source');}
    public function testLinkedSharedDestinationRechecksFreshFilesAclAndCanRecoverAfterGrantReturns(): void {
        $result = $this->probe('linked_shared_destination_acl');
        foreach (['positive_sink', 'original_origin_linked_file_accepted', 'wrong_origin_tuple_rejected', 'generic_shared_node_rejected',
            'revoked_origin_linked_file_rejected', 'acl_revocation_rejected', 'acl_preflight_rejected', 'old_output_preserved',
            'restored_sink', 'unscoped_storage_still_rejected'] as $check) {
            self::assertTrue($result[$check] ?? false, 'Linked shared destination failed ' . $check);
        }
    }
    public function testPublicLinkRemovalDoesNotRequireFileStorageAdmission(): void {$this->assertRefused('public_link_without_files');}
    public function testQualifiedStorageSupportsUploadDeleteAndVerifiedRestore(): void {
        $result = $this->probe('positive'); self::assertTrue($result['verified']); self::assertTrue($result['recovery_available']);
    }
}
