<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Reorganization;

use OCA\Mcp\Access\FileWritePolicy\FileTypeDenied;
use OCA\Mcp\Modules\Files\Reorganization\{FileTypePreflight, ReorganizationLimits};
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;

final class FileWritePolicyTreesTest extends FilesToolsTestCase {
    private function allow(array $extensions): void {
        $this->fileTypeGuard(); sort($extensions);
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => $extensions, 'allowExtensionless' => false]);
    }
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFile('/alice/files/Source/a.md', 'first');
        $this->tree->addFile('/alice/files/Source/Nested/b.md', 'second');
        $this->tree->addFolder('/alice/files/Target');
    }
    public function testFolderCopyAndMoveRefuseEveryBlockedDescendantBeforeEffects(): void {
        $this->tree->addFile('/alice/files/Source/Nested/private.php', 'untrusted');
        $this->allow(['md']);
        foreach (['files_copy', 'files_move'] as $tool) foreach ([false, true] as $execute) {
            try {
                $args = ['from' => '/Source', 'to' => '/Target/tree'];
                $execute ? $this->json($tool, $args) : $this->plan($tool, $args);
                self::fail('Blocked descendant accepted');
            } catch (FileTypeDenied) { self::assertSame([], $this->tree->ops); }
        }
    }
    public function testCopyUsesValidatedListOmitsAddedFilesAndReportsVanishedIdentity(): void {
        $this->allow(['md']);
        $changed = false;
        $this->tree->beforeGet = function (string $path) use (&$changed): void {
            if (!$changed && $path === '/alice/files/Source/Nested/b.md') {
                $changed = true;
                $this->tree->addFile('/alice/files/Source/late.php', 'late');
                unset($this->tree->nodes['/alice/files/Source/a.md']);
            }
        };
        $out = $this->json('files_copy', ['from' => '/Source', 'to' => '/Target/tree']);
        self::assertTrue($out['partial']);
        self::assertContains('/Target/tree/a.md', $out['not_copied']);
        self::assertArrayHasKey('/alice/files/Target/tree/Nested/b.md', $this->tree->nodes);
        self::assertArrayNotHasKey('/alice/files/Target/tree/late.php', $this->tree->nodes);
        self::assertCount(1, $out['conflicts']);
        self::assertEmpty(array_filter($this->tree->ops, fn($op) => str_starts_with($op, 'delete ')));
    }
    public function testCopyPolicyChangeAfterManifestStopsFilesAndKeepsPartialFolder(): void {
        $this->allow(['md']);
        $changed = false;
        $this->tree->beforeGet = function (string $path) use (&$changed): void {
            if (!$changed && $path === '/alice/files/Source/Nested/b.md') { $changed = true; $this->allow([]); }
        };
        $out = $this->json('files_copy', ['from' => '/Source', 'to' => '/Target/tree']);
        self::assertTrue($out['partial']);
        self::assertArrayHasKey('/alice/files/Target/tree', $this->tree->nodes);
        self::assertArrayNotHasKey('/alice/files/Target/tree/a.md', $this->tree->nodes);
        self::assertArrayNotHasKey('/alice/files/Target/tree/Nested/b.md', $this->tree->nodes);
    }
    public function testNewlyInaccessibleManifestItemDoesNotDiscloseItsNameInPartialReceipt(): void {
        $this->allow(['md']);
        $this->tree->beforeGet = function (string $path): void {
            if ($path === '/alice/files/Source/Nested/b.md') $this->tree->nodes[$path]['readable'] = false;
        };
        try {
            $this->json('files_copy', ['from' => '/Source', 'to' => '/Target/tree']);
            self::fail('A private manifest name was disclosed');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            self::assertSame(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound() . ' ' . \OCA\Mcp\Modules\Files\Reorganization\ReorganizationMessages::copyAccessChanged(), $e->getMessage());
            self::assertStringNotContainsString('b.md', $e->getMessage());
            self::assertArrayHasKey('/alice/files/Target/tree', $this->tree->nodes);
            self::assertArrayNotHasKey('/alice/files/Target/tree/Nested/b.md', $this->tree->nodes);
        }
    }

    public function testNewExclusionSettingStopsListedCopyWithNoStalePathsOrCounts(): void {
        $this->allow(['md']);
        $this->tree->beforeGet = function (string $path): void {
            if ($path === '/alice/files/Source/Nested/b.md') {
                $this->config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = '["999"]';
            }
        };
        try {
            $this->json('files_copy', ['from' => '/Source', 'to' => '/Target/tree']);
            self::fail('Copy continued after exclusions changed');
        } catch (\OCA\Mcp\Modules\Files\Reorganization\CopyAccessChanged $e) {
            self::assertStringNotContainsString('b.md', $e->getMessage());
            self::assertStringContainsString('Existing output was retained', $e->getMessage());
            self::assertArrayHasKey('/alice/files/Target/tree', $this->tree->nodes);
            self::assertArrayNotHasKey('/alice/files/Target/tree/Nested/b.md', $this->tree->nodes);
        }
    }

    public function testFileGrowthBeyondCopyBudgetReportsRetainedPartialOutputTruthfully(): void {
        $this->allow(['md']);
        $this->tree->beforeGet = function (string $path): void {
            if ($path === '/alice/files/Source/Nested/b.md') $this->tree->nodes[$path]['size'] = ReorganizationLimits::BYTES + 1;
        };
        $out = $this->json('files_copy', ['from' => '/Source', 'to' => '/Target/tree']);
        self::assertTrue($out['partial']);
        self::assertArrayHasKey('/alice/files/Target/tree', $this->tree->nodes);
        self::assertArrayNotHasKey('/alice/files/Target/tree/Nested/b.md', $this->tree->nodes);
        self::assertStringNotContainsString('nothing was copied', $out['conflicts'][0]['reason']);
        self::assertStringContainsString('retained', $out['conflicts'][0]['reason']);
    }

    public static function ordinaryCopies(): iterable {
        yield 'file with policy off' => ['off', '/Source/a.md', '/Target/copy.md'];
        yield 'file with allowlist' => ['allowlist', '/Source/a.md', '/Target/copy.md'];
        yield 'folder with policy off' => ['off', '/Source', '/Target/tree'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryCopies')]
    public function testOrdinaryCopyChecksReturnedNodeVisibilityBeforePublishingMetadata(string $mode, string $from, string $to): void {
        $this->fileTypeGuard();
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode(['version' => 1, 'mode' => $mode, 'extensions' => ['md'], 'allowExtensionless' => false]);
        $hiddenId = null;
        $mapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(static function (array $ids) use (&$hiddenId): array {
            $tags = [];
            foreach ($ids as $id) $tags[$id] = (string)$id === $hiddenId ? ['999'] : [];
            return $tags;
        });
        $this->visibilityGuard = \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this, $this->config->mock($this), $mapper, $this->visibilityRoot());
        $this->tree->afterCopy = function (string $source, string $target) use (&$hiddenId): void {
            $this->config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = '["999"]';
            $hiddenId = (string)$this->tree->nodes[$target]['id'];
        };
        try {
            $this->json('files_copy', ['from' => $from, 'to' => $to]);
            self::fail('Copy receipt exposed metadata for a newly excluded node');
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            self::assertSame(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound(), $e->getMessage());
            self::assertStringNotContainsString('idAfter', $e->getMessage());
            self::assertStringNotContainsString('access', $e->getMessage());
            self::assertStringNotContainsString('Alice', $e->getMessage());
            self::assertArrayHasKey('/alice/files' . $to, $this->tree->nodes);
            $copiedFile = '/alice/files' . $to . ($from === '/Source' ? '/a.md' : '');
            self::assertSame('first', $this->tree->nodes[$copiedFile]['content']);
            self::assertSame('first', $this->tree->nodes['/alice/files/Source/a.md']['content']);
            self::assertEmpty(array_filter($this->tree->ops, static fn(string $op): bool => str_starts_with($op, 'delete ')));
        }
    }

    public function testAllowedFileCopyRetainsTheNormalReceipt(): void {
        $this->allow(['md']);
        $out = $this->json('files_copy', ['from' => '/Source/a.md', 'to' => '/Target/copy.md']);
        self::assertSame(['from', 'to', 'idBefore', 'idAfter', 'nodes', 'bytes', 'access'], array_keys($out));
        self::assertSame('first', $this->tree->nodes['/alice/files/Target/copy.md']['content']);
    }
    public function testBatchPolicyChecksBeforeMkdirAndUndoRetainsBlockedReceiptForRetry(): void {
        $this->allow(['md']);
        $args = ['moves' => [['from' => '/Source/a.md', 'to' => '/Target/a.php']], 'mkdirs' => ['/New']];
        $plan = $this->plan('files_move_batch', $args);
        self::assertFalse($plan['ok']); self::assertNotEmpty($plan['conflicts']);
        try { $this->json('files_move_batch', $args); self::fail('Batch should refuse'); }
        catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) { self::assertSame([], $this->tree->ops); }
        $this->tree->addFile('/alice/files/Source/old.php', 'original');
        unset($this->fileTypeConfig->stored['mcp']['file_write_policy']);
        $batch = $this->json('files_move_batch', ['moves' => [['from' => '/Source/old.php', 'to' => '/Target/allowed.md']]]);
        $before = $this->tree->ops;
        $this->allow(['md']);
        $out = $this->json('files_undo_batch', ['batch_id' => $batch['batch_id']]);
        self::assertSame(0, $out['undone']); self::assertNotEmpty($out['conflicts']);
        self::assertSame($before, $this->tree->ops);
        self::assertFalse($this->batches->find($batch['batch_id'], 'alice')->isUndone());
        $this->allow(['md', 'php']);
        self::assertSame(1, $this->json('files_undo_batch', ['batch_id' => $batch['batch_id']])['undone']);
    }
    public function testProjectedAliasesCheckIntermediateNamesWithOneSnapshot(): void {
        $this->allow(['md']);
        $conflicts = (new FileTypePreflight($this->visibilityGuard))->moves($this->tree->rootFolder(), [
            ['from' => '/Source/a.md', 'to' => '/Target/intermediate.php'],
            ['from' => '/Target/intermediate.php', 'to' => '/Target/final.md'],
        ], $this->fileTypeGuard()->snapshot());
        self::assertCount(1, $conflicts);
        self::assertSame('/Target/intermediate.php', $conflicts[0]['to']);
        self::assertSame([], $this->tree->ops);
    }
    public function testAggregateProjectedWorkIsBounded(): void {
        $this->allow(['md']);
        $node = $this->tree->node('/alice/files/Source/a.md');
        $check = new FileTypePreflight($this->visibilityGuard);
        $snapshot = $this->fileTypeGuard()->snapshot();
        for ($i = 0; $i < ReorganizationLimits::NODES; $i++) $check->inspect($node, '/Target/a.md', $snapshot);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $check->inspect($node, '/Target/a.md', $snapshot);
    }
}
