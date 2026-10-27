<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Editing;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Public Files flows must consume the live policy rather than a hard-coded default. */
final class BackupPolicyOperationsTest extends FilesToolsTestCase {
    public static function undoReservations(): iterable {
        yield 'undo source' => ['Reserved'];
        yield 'undo destination' => ['Documentos'];
    }

    #[DataProvider('undoReservations')]
    public function testKnownReservationRefusesWholeUndoBeforeAnyMove(string $reserved): void {
        $this->tree->addFile('/alice/files/Documentos/second.md', 'second');
        $receipt = $this->json('files_move_batch', [
            'mkdirs' => ['/Reserved', '/Normal'],
            'moves' => [
                ['from' => '/Documentos/ata.md', 'to' => '/Reserved/ata.md'],
                ['from' => '/Documentos/second.md', 'to' => '/Normal/second.md'],
            ],
        ]);
        $this->config->app['mcp'][BackupLocation::KEY] = json_encode([
            'version' => 1, 'folder' => $reserved, 'reserved' => ['MCP backups', $reserved], 'copies' => true,
        ], JSON_THROW_ON_ERROR);
        $this->tree->ops = [];
        $args = ['batch_id' => $receipt['batch_id']];
        $plan = $this->plan('files_undo_batch', $args);
        self::assertFalse($plan['ok']);
        self::assertNotEmpty($plan['conflicts']);
        $result = $this->json('files_undo_batch', $args);
        self::assertSame(0, $result['undone']);
        self::assertSame([], $this->tree->ops);
        self::assertCount(2, $this->batches->find($receipt['batch_id'], 'alice')->moves);
    }

    public function testUndoKeepsRecordedEmptyDirectoryThatBecameReserved(): void {
        $receipt = $this->json('files_move_batch', [
            'mkdirs' => ['/Empty', '/Normal'],
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Normal/ata.md']],
        ]);
        $this->config->app['mcp'][BackupLocation::KEY] = json_encode([
            'version' => 1, 'folder' => 'Empty', 'reserved' => ['MCP backups', 'Empty'], 'copies' => true,
        ], JSON_THROW_ON_ERROR);
        $args = ['batch_id' => $receipt['batch_id']];
        $plan = $this->plan('files_undo_batch', $args);
        self::assertTrue($plan['ok']);
        self::assertContains('/Empty', $plan['kept_dirs']);
        self::assertNotContains('/Empty', $plan['removed_dirs']);
        $result = $this->json('files_undo_batch', $args);
        self::assertSame(1, $result['undone']);
        self::assertContains('/Empty', $result['kept_dirs']);
        self::assertTrue($this->tree->rootFolder()->nodeExists('Empty'));
        self::assertTrue($this->batches->find($receipt['batch_id'], 'alice')->isUndone());
    }

    public function testPublishedGuideDescribesConfiguredOptionalCopies(): void {
        $guide = implode("\n", $this->module->guideNotes());
        self::assertStringNotContainsString('and copy the file to', $guide);
        self::assertStringContainsString('copies default on', $guide);
        self::assertStringContainsString('configured backup folder', $guide);
    }

    private function settings(bool $copies): void {
        $this->config->app['mcp'][BackupLocation::KEY] = json_encode([
            'version' => 1, 'folder' => '.mcp-backups',
            'reserved' => ['MCP backups', 'Old copies', '.mcp-backups'], 'copies' => $copies,
        ], JSON_THROW_ON_ERROR);
    }

    public static function preferences(): iterable {
        yield 'enabled' => [true];
        yield 'suspended' => [false];
    }

    #[DataProvider('preferences')]
    public function testEditPlanAndReceiptReflectActualCopies(bool $copies): void {
        $this->settings($copies);
        $args = ['path' => '/Documentos/ata.md', 'content' => 'updated'];
        $plan = $this->plan('files_edit', $args);
        self::assertSame($copies ? '/.mcp-backups' : '', $plan['backup']);
        self::assertSame($copies, $plan['recoverable']);
        if (!$copies) {
            self::assertStringContainsString('No MCP pre-write copy', $plan['message']);
        }
        $before = $this->tree->nodes['/alice/files/Documentos/ata.md']['content'];
        $receipt = $this->json('files_edit', $args);
        self::assertSame('updated', $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        if ($copies) {
            self::assertStringStartsWith('/.mcp-backups/', $receipt['backup']);
            self::assertSame($before, $this->tree->node('/alice/files' . $receipt['backup'])->getContent());
        } else {
            self::assertSame('', $receipt['backup']);
            self::assertFalse($this->tree->rootFolder()->nodeExists('.mcp-backups'));
        }
    }

    public static function protectedWrites(): iterable {
        foreach (['Old copies', '.mcp-backups'] as $folder) {
            yield 'edit ' . $folder => ['files_edit', ['path' => '/' . $folder . '/old.txt', 'content' => 'changed']];
            yield 'mkdir ' . $folder => ['files_mkdir', ['path' => '/' . $folder . '/new']];
            yield 'copy ' . $folder => ['files_copy', ['from' => '/Documentos/ata.md', 'to' => '/' . $folder . '/copy.md']];
        }
    }

    #[DataProvider('protectedWrites')]
    public function testHistoricalAndCurrentDestinationsCannotBeWritten(string $tool, array $args): void {
        $this->settings(false);
        foreach (['Old copies', '.mcp-backups'] as $folder) {
            $this->tree->addFile('/alice/files/' . $folder . '/old.txt', 'original');
        }
        self::assertSame(EditingMessages::backupPath(), $this->failure($tool, $args));
        self::assertSame([], $this->tree->ops);
    }

    public function testCorruptSettingsPreventAnOrdinaryWriteWithoutDefaultFallback(): void {
        $this->config->app['mcp'][BackupLocation::KEY] = '{invalid';
        $before = $this->tree->nodes['/alice/files/Documentos/ata.md']['content'];
        self::assertStringContainsString('Backup settings are unavailable', $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'changed']));
        self::assertSame($before, $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        self::assertSame([], $this->tree->ops);
    }
    public function testReservationIntroducedAfterCopyRefusesTheContentWrite(): void {
        $this->settings(true);
        $before = $this->tree->nodes['/alice/files/Documentos/ata.md']['content'];
        $this->tree->afterWrite = function (string $path): void {
            if (str_ends_with($path, '.bak')) {
                $document = json_decode($this->config->app['mcp'][BackupLocation::KEY], true);
                $document['reserved'][] = 'Documentos';
                $this->config->app['mcp'][BackupLocation::KEY] = json_encode($document);
            }
        };
        $message = $this->failure('files_edit', ['path' => '/Documentos/ata.md', 'content' => 'changed']);
        self::assertStringContainsString('/.mcp-backups/', $message);
        self::assertSame($before, $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
    }

    public function testReservationIntroducedAfterEmptyCreationRefusesContent(): void {
        $this->settings(true);
        $this->tree->afterCreate = function (string $path): void {
            if ($path === '/alice/files/Documentos/new.md') {
                $document = json_decode($this->config->app['mcp'][BackupLocation::KEY], true);
                $document['reserved'][] = 'Documentos';
                $this->config->app['mcp'][BackupLocation::KEY] = json_encode($document);
            }
        };
        self::assertSame(EditingMessages::backupPath(), $this->failure('files_create', ['path' => '/Documentos/new.md', 'content' => 'private']));
        self::assertSame('', $this->tree->nodes['/alice/files/Documentos/new.md']['content']);
    }

    public function testSuspendedCheckoutPlanRetainsUploadInstructionsWithoutRecoveryPromise(): void {
        $this->settings(false);
        $plan = $this->plan('files_checkout', ['path' => '/Documentos/ata.md']);
        self::assertSame('', $plan['backup']);
        self::assertFalse($plan['recoverable']);
        self::assertStringContainsString('temporary download and upload links', $plan['message']);
        self::assertStringContainsString('No MCP pre-write copy', $plan['message']);
    }

}
