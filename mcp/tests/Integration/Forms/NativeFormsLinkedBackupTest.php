<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;

use PHPUnit\Framework\TestCase;
final class NativeFormsLinkedBackupTest extends TestCase
{
    private function check(string $case): void
    {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {
            $this->markTestSkipped('Requires disposable native cell');
        }
        $process = proc_open([PHP_BINARY, '/tmp/linked-backup-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['case' => $case]));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . ' ' . $out);
        $result = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'], json_encode($result));
        self::assertNotEmpty($result['result']);
        foreach ($result['result'] as $name => $passed) {
            self::assertTrue($passed, $name . ' ' . ($result['diagnostic'] ?? ''));
        }
    }
    public function testNativeLinkedCapabilityRequiresCurrentSchemaQualification(): void
    {
        $this->check('capability');
    }
    public function testIsolatedCopyAdmitsEveryFolderAllocationAndByteWrite(): void
    {
        $this->check('copy');
    }
    public function testIsolatedStreamCopyReachesDestructiveStorageAdmission(): void
    {
        $this->check('copy_stream');
    }
    public function testOriginalConnectionRevokedDuringActualBackupLockWaitPreventsBytes(): void
    {
        $this->check('wait_connection');
    }
    public function testOriginalGrantRevokedDuringActualBackupLockWaitPreventsBytes(): void
    {
        $this->check('wait_grant');
    }
    public function testUnsupportedBackupStorageRefusesBeforeCallback(): void
    {
        $this->check('unknown_storage');
    }
    public function testForeignSessionAndOutsideCycleRefuseBeforeCallbacks(): void
    {
        $this->check('context');
    }
    public function testForeignRootRemainsUntouched(): void
    {
        $this->check('wrong_root');
    }
    public function testBackupFolderAdmissionRefusesAllocationAfterLock(): void
    {
        $this->check('mkdir_refused');
    }
    public function testBackupEmptyFileAdmissionRefusesAllocationAfterLock(): void
    {
        $this->check('empty_refused');
    }
    public function testExistingFileCannotBeAdoptedAfterNativeTouchFallback(): void
    {
        $this->check('empty_collision');
    }
    public function testBackupContentAdmissionRefusesSensitiveBytesAfterLock(): void
    {
        $this->check('content_refused');
    }
    public function testChangedOriginalOutputPreventsBackupBytes(): void
    {
        $this->check('output_changed');
    }
    public function testSourceChangeAfterRecoveryCopyPreservesOriginalOutput(): void
    {
        $this->check('after_source');
    }
    public function testAuthorityChangeAfterRecoveryCopyPreservesOriginalOutput(): void
    {
        $this->check('after_auth');
    }
    public function testInteractiveInitialFolderCreationAdmitsAtActualStorage(): void
    {
        $this->check('interactive_mkdir');
    }
    public function testInteractiveInitialFolderRefusalPreventsAllocation(): void
    {
        $this->check('interactive_refused');
    }
}
