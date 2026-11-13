<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;

use PHPUnit\Framework\TestCase;
final class NativeFormsSharePhaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {
            $this->markTestSkipped('Requires disposable native sharing cell');
        }
    }
    private function runCase(string $case): array
    {
        $process = proc_open([PHP_BINARY, '/tmp/share-phase-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['case' => $case]));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok'], $output);
        return $result['result'];
    }
    public function testMutableShareTypeCannotEscapeTheGuardedProvider(): void
    {
        foreach (['routing_email', 'routing_federated', 'routing_all'] as $case) {
            foreach ($this->runCase($case) as $key => $value) {
                self::assertTrue($value, $case . ' ' . $key);
            }
        }
    }
    public function testConcurrentEmptyFolderCannotBecomeManagedShareProperty(): void
    {
        foreach ($this->runCase('concurrent_folder') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testProviderWriteRechecksAuthorityAndTupleAfterNativePreEvents(): void
    {
        foreach (['provider_create_revoke', 'provider_create_mutation', 'provider_delete_revoke'] as $case) {
            foreach ($this->runCase($case) as $key => $value) {
                self::assertTrue($value, $case . ' ' . $key);
            }
        }
    }
    public function testOrdinaryNativeResultsSharingCreatesAndRemovesExactShare(): void
    {
        foreach ($this->runCase('native') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testMcpResultsSharingReportsVerifiedCreationAndRemoval(): void
    {
        foreach ($this->runCase('mcp') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testPersistedShareWithLostAcknowledgementIsUncertainAndNeverAdoptedOrReplayed(): void
    {
        foreach ($this->runCase('lost_ack') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testDeletedFormRetainsExactOwnerEvidenceForShareRemoval(): void
    {
        foreach ($this->runCase('delete_form') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testOriginalAuthorityIsFreshAfterTheActualFolderLock(): void
    {
        foreach (['files', 'forms', 'scope', 'connection', 'incarnation', 'native'] as $mode) {
            foreach ($this->runCase('revoked_' . $mode) as $key => $value) {
                self::assertTrue($value, $mode . ' ' . $key);
            }
        }
    }
    public function testManagedFileDigestsAndCompletedDeletionRemainBoundToShareEffects(): void
    {
        foreach (['with_file', 'delete_with_file'] as $case) {
            foreach ($this->runCase($case) as $key => $value) {
                self::assertTrue($value, $case . ' ' . $key);
            }
        }
    }
    public function testUnknownStorageForeignNativeActorAndUnmanagedShareRefuseBeforeMutation(): void
    {
        foreach (['unknown_storage', 'nonowner', 'unmanaged_share'] as $case) {
            foreach ($this->runCase($case) as $key => $value) {
                self::assertTrue($value, $case . ' ' . $key);
            }
        }
    }
    public function testSessionFenceRejectsUnrelatedTransactionsAndPreservesNativeNesting(): void
    {
        foreach ($this->runCase('outer_transaction') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeGroupFilesShareIsCreatedAndRemoved(): void
    {
        foreach ($this->runCase('group') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testLostPrimaryConnectionRetainsUncertainEvidenceAndReleasesSessionLock(): void
    {
        foreach ($this->runCase('lost_connection') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testUnqualifiedCircleFilesProviderRefusesWithoutChangingNativePermissions(): void
    {
        foreach ($this->runCase('circle') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNewlyAllocatedExcludedTargetIsRefusedUnderItsOwnLock(): void
    {
        foreach ($this->runCase('new_target_excluded') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testOrdinaryNativeSharingWorksWithMcpGloballyDisabled(): void
    {
        foreach ($this->runCase('mcp_disabled') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testExplicitFormDeletionCanRemovePreviouslyVerifiedOrphanShareAfterPartialUnshare(): void
    {
        foreach ($this->runCase('orphan_then_delete') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeActorDisabledWhileWaitingCannotCommitTheNativeShareChange(): void
    {
        foreach ($this->runCase('wait_native') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testChangedPinnedSharingSourceClosesCapabilitiesAndTheSessionFence(): void
    {
        foreach ($this->runCase('source') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testChangedManagedShareIdentityRefusesBeforeFormDeletion(): void
    {
        foreach ($this->runCase('changed_share') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeDeletedUserCleanupRetiresAlreadyRemovedShareMappings(): void
    {
        foreach ($this->runCase('deleted_user_cleanup') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testNativeExpirationPolicyChangedAfterTheFolderLockUsesFreshConfig(): void
    {
        foreach ($this->runCase('revoked_expiration') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testVerifiedAbsentExactShareNeedsOnlyMetadataCleanup(): void
    {
        foreach ($this->runCase('already_removed_share') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testVerifiedFilesShareDoesNotClaimVerifiedNotificationDelivery(): void
    {
        foreach ($this->runCase('event_phases') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testDeletedTargetGroupIsRecheckedAfterTheActualFolderLock(): void
    {
        foreach ($this->runCase('revoked_target_group') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testConcurrentNativeMatchingShareCannotBeAdoptedFromCreateReturnValue(): void
    {
        foreach ($this->runCase('concurrent_tuple') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testPublicLinkChangesRequireNoCollaboratorOrFilesShareGrant(): void
    {
        foreach ($this->runCase('public_link_without_files_grant') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testAdvisoryAcquisitionAckLossReleasesTheSessionWithoutRunningWork(): void
    {
        foreach ($this->runCase('lock_acquire_ack') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testAnUnrelatedAlreadyOwnedAdvisoryLockIsNotBorrowed(): void
    {
        foreach ($this->runCase('foreign_session_lock') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testIncompleteMigrationAndPersistentSessionsAreUnqualified(): void
    {
        foreach ($this->runCase('schema_and_persistent') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
    public function testUnresolvedProviderObjectWithExistingRowKeepsItsExactMapping(): void
    {
        foreach ($this->runCase('unresolved_existing_row') as $key => $value) {
            self::assertTrue($value, $key);
        }
    }
}
