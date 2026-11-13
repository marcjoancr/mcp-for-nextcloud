<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Editing\ContentPlan;
use OCA\Mcp\Modules\Files\Editing\ContentWriter;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture;
use OCA\Mcp\Tests\Support\Files\FilesGuards;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Tests\Support\Platform\FileAccess\Locks\FakeLock;
use OCP\Files\Lock\ILock;

final class DeferredContentWriteTest extends FilesToolsTestCase {
    private const PATH = '/Documentos/ata.md';
    private const ABS = '/alice/files/Documentos/ata.md';

    public function testSessionlessWriteRefusesOwnLockBeforeCreatingBackup(): void {
        $this->locks->put(new FakeLock($this->tree->nodes[self::ABS]['id'], ILock::TYPE_USER, 'alice'));
        $guard = FilesGuards::unrestricted($this, $this->configMock, $this->tagMapper, $this->tree->filesystemRoot());
        $locks = $this->locks->service($this);
        $location = BackupDocumentFixture::location($this);
        $backup = new FileBackup($location, BackupDocumentFixture::copies($this, $location),
            $this->apps, $this->users, $this->time, $this->configMock, $guard, $locks);
        $writer = new ContentWriter($this->fileTypeGuard(), $backup, new NodeAccessInfo($this->users), $guard, $locks);
        try {
            $writer->commit($this->tree->rootFolder(), 'alice', $this->tree->node(self::ABS),
                self::PATH, 'replacement', null, '', false, null, null, false);
            self::fail('A sessionless writer cannot use its originator manual lock.');
        } catch (LockWriteFailure) {
            self::assertSame("# Ata\nolá", $this->tree->nodes[self::ABS]['content']);
            self::assertSame([], $this->tree->ops, 'Refuse before allocating a backup.');
        }
    }

    public function testOwnLockTakenDuringBackupStopsSessionlessFinalWrite(): void {
        $guard = FilesGuards::unrestricted($this, $this->configMock, $this->tagMapper, $this->tree->filesystemRoot());
        $backup = $this->createMock(FileBackup::class);
        $backup->expects(self::once())->method('prepare')->willReturnCallback(function (...$args): string {
            self::assertFalse($args[5] ?? true, 'The backup must use the deferred lock policy.');
            $this->locks->put(new FakeLock($this->tree->nodes[self::ABS]['id'], ILock::TYPE_USER, 'alice'));
            return '/Backups/preserved.bak';
        });
        $writer = new ContentWriter($this->fileTypeGuard(), $backup, new NodeAccessInfo($this->users),
            $guard, $this->locks->service($this));
        try {
            $writer->commit($this->tree->rootFolder(), 'alice', $this->tree->node(self::ABS),
                self::PATH, 'replacement', null, '', false, null, null, false);
            self::fail('The final write must recheck the sessionless lock policy.');
        } catch (LockWriteFailure $failure) {
            self::assertSame("# Ata\nolá", $this->tree->nodes[self::ABS]['content']);
            self::assertStringContainsString('/Backups/preserved.bak', $failure->getMessage());
        }
    }

    public function testDeferredPlanDescribesOwnManualLockAsBlocking(): void {
        $this->locks->put(new FakeLock($this->tree->nodes[self::ABS]['id'], ILock::TYPE_USER, 'alice'));
        $info = new NodeAccessInfo($this->users);
        $plan = new ContentPlan(BackupDocumentFixture::plan($this), $info, new SharedWriteGuard($info),
            $this->locks->service($this));
        $interactive = $plan->describe($this->tree->node(self::ABS), 'alice', self::PATH, []);
        $deferred = $plan->describe($this->tree->node(self::ABS), 'alice', self::PATH, [], false);
        self::assertFalse($interactive['lock']['blocking']);
        self::assertTrue($deferred['lock']['blocking']);
    }
}
