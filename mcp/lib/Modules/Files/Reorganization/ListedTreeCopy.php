<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Modules\Files\Editing\BackupWriteBoundary;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;

use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Access\FileWritePolicy\FileWritePolicyUnavailable;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Copies the validated manifest, never a live directory; partial copies are never removed. */
final class ListedTreeCopy {
    /** Required native authorization, locking and policy services. */
    public function __construct(
        private BackupLocation $backupLocation,
        private \OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard $fileTypes,
        private MoveInspection $inspection,
        private \OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard,
        private ?\OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite $locks,
    ) {
    }

    /**
     * @param Folder $root User root.
     * @param string $userId Authenticated user.
     * @param Node $source Existing source.
     * @param string $to Normalized target.
     * @param array<string,mixed>|null $manifest Whole preflight result, or off.
     * @return array{node:Node|null,listed:bool,partial:bool,created:list<string>,conflicts:list<array{path:string,reason:string}>,not_copied:list<string>} Explicit complete/partial receipt.
     */
    public function execute(Folder $root, string $userId, Node $source, string $to, ?array $manifest): array {
        if (!$source instanceof Folder) {
            $node = $this->native($root, $source, MovePaths::absolute($root, $to), $userId, $to);
            return ['node' => $node, 'listed' => false, 'partial' => false, 'created' => [$to], 'conflicts' => [], 'not_copied' => []];
        }
        $snapshot = $this->fileTypes->snapshot();
        if ($manifest === null && $snapshot->isActive()) {
            $manifest = (new FileTypePreflight($this->visibilityGuard))->inspect($source, $to, $snapshot, true);
        }
        if ($manifest === null) {
            $node = $this->native($root, $source, MovePaths::absolute($root, $to), $userId, $to);
            return ['node' => $node, 'listed' => false, 'partial' => false, 'created' => [$to], 'conflicts' => [], 'not_copied' => []];
        }
        // Fresh complete check before the first effect, using only names from the original list.
        foreach ($manifest['entries'] as $entry) {
            if (!$entry['folder']) {
                $snapshot->assertWritableName($this->name($entry['to']));
            }
        }
        $created = $conflicts = $missing = [];
        $copiedRoot = null;
        $bytes = 0;
        $failedFolder = null;
        foreach ($manifest['entries'] as $index => $entry) {
            // DFS keeps descendants contiguous; skipping a failed subtree is linear, with no live lookup.
            if ($failedFolder !== null && str_starts_with($entry['to'], $failedFolder . '/')) {
                $missing[] = $entry['to'];
                continue;
            }
            $failedFolder = null;
            try {
                $this->assertTreeAccessible($source);
                $node = $this->listed($root, $entry);
                $this->assertTreeAccessible($source);
                $this->assertVisibleNode(NodeAccess::run(fn () => NodeAccess::get($root, dirname($entry['to']))));
                $this->inspection->assertFree($root, $entry['to']);
                $parent = $this->inspection->destination($root, $entry['to']);
                if ($entry['folder']) {
                    $this->fileTypes->snapshot();
                    $copy = NodeAccess::mutate(function () use ($root, $entry, $parent): Folder {
                        $this->listed($root, $entry);
                        return BackupWriteBoundary::run($entry['to'], $this->backupLocation, fn () => $parent->newFolder($this->name($entry['to'])));
                    }, $this->visibilityGuard);
                } else {
                    $bytes += max(0, (int)$node->getSize());
                    if ($bytes > ReorganizationLimits::BYTES) {
                        throw new ToolFailure(ReorganizationMessages::copyBudgetReached());
                    }
                    $copy = $this->native($root, $node, MovePaths::absolute($root, $entry['to']), $userId, $entry['to'], $entry);
                }
                $this->assertVisibleNode($copy);
                $created[] = $entry['to'];
                if ($entry['to'] === $to) {
                    $copiedRoot = $copy;
                }
            } catch (CopyAccessChanged $e) {
                // Already created output stays in place. No stale child path/count is returned.
                throw $e;
            } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
                throw new CopyAccessChanged();
            } catch (\Throwable $e) {
                $conflicts[] = ['path' => $entry['to'], 'reason' => $e instanceof ToolFailure ? $e->getMessage() : ReorganizationMessages::moveFailed()];
                $missing[] = $entry['to'];
                if ($created === []) {
                    throw ($e instanceof ToolFailure ? $e : new ToolFailure(ReorganizationMessages::moveFailed()));
                }
                if ($bytes > ReorganizationLimits::BYTES || $this->stopsCopy($e)) {
                    $missing = array_merge($missing, array_column(array_slice($manifest['entries'], $index + 1), 'to'));
                    break;
                }
                if ($entry['folder']) {
                    $failedFolder = $entry['to'];
                }
            }
        }
        $this->assertTreeAccessible($source);
        if ($copiedRoot !== null) $this->assertVisibleNode($copiedRoot);
        return ['node' => $copiedRoot, 'listed' => true, 'partial' => $conflicts !== [], 'created' => $created, 'conflicts' => $conflicts, 'not_copied' => $missing];
    }

    /**
     * @param \Throwable $failure Failure raised at one copy boundary.
     * @return bool Whether further entries must not acquire locks/read policy/attempt writes.
     */
    private function stopsCopy(\Throwable $failure): bool {
        // NodeAccess::run maps quota exceptions to its safe message, without retaining the exception type.
        return $failure instanceof FileWritePolicyUnavailable
            || $failure instanceof \OCP\Files\NotEnoughSpaceException
            || !($failure instanceof ToolFailure)
            || $failure->getMessage() === FileAccessMessages::insufficientQuota();
    }

    /**
     * @param Folder $root User root, resolving the listed path afresh for this boundary.
     * @param array{source:string,id:int,folder:bool} $entry Original manifest identity, never a substituted live name.
     * @return Node The same identity and kind, still visible at the listed path.
     * @throws ToolFailure When the listed item disappeared, was replaced, renamed or became hidden.
     */
    private function listed(Folder $root, array $entry): Node {
        $relative = $root->getRelativePath($entry['source']);
        if ($relative === null) {
            throw new MoveConflict(ReorganizationMessages::notTheBatchNode());
        }
        // A listed source that disappeared is an item conflict, not an unexpected global failure.
        try {
            $node = NodeAccess::run(fn () => NodeAccess::get($root, $relative));
        } catch (ToolFailure $e) {
            if ($e->getMessage() === FileAccessMessages::forbidden()) throw new CopyAccessChanged();
            throw $e;
        }
        $this->assertVisibleNode($node);
        if ($node->getPath() !== $entry['source'] || (int)$node->getId() !== $entry['id'] || ($node instanceof Folder) !== $entry['folder']) {
            throw new MoveConflict(ReorganizationMessages::notTheBatchNode());
        }
        return $node;
    }

    private function assertTreeAccessible(Node $source): void {
        $this->assertVisibleNode($source);
        if (\OCA\Mcp\Modules\Files\Access\FolderExposureGate::active($this->visibilityGuard)) {
            throw new CopyAccessChanged();
        }
    }

    private function assertVisibleNode(Node $node): void {
        $this->visibilityGuard->clearCache();
        try {
            if (!$node->isReadable() || !$this->visibilityGuard->isVisible($node)) throw new CopyAccessChanged();
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
            throw new CopyAccessChanged();
        }
    }

    /**
     * @param string $path Normalized projected destination.
     * @return string Last path segment, independent of process locale.
     */
    private function name(string $path): string {
        $segments = PathGuard::segments($path);
        return $segments[array_key_last($segments)] ?? '';
    }

    /**
     * @param Folder $root User root for a fresh listed-source resolution inside the sink callback.
     * @param Node $source A file, or a folder only while policy is off at this boundary.
     * @param string $absolute Absolute destination.
     * @param string $userId Authenticated user.
     * @param string $to User-relative final name.
     * @param array{source:string,id:int,folder:bool}|null $identity Listed identity for the final check immediately at the sink.
     * @return Node Result of the official node copy, preserving the existing lock-capture behavior.
     */
    private function native(Folder $root, Node $source, string $absolute, string $userId, string $to, ?array $identity = null): Node {
        $copy = function () use ($root, $source, $absolute, $identity, $to): Node {
            $current = $identity === null ? $source : $this->listed($root, $identity);
            BackupWriteBoundary::node($current, $this->backupLocation);
            return BackupWriteBoundary::run($to, $this->backupLocation, fn () => $current->copy($absolute));
        };
        $write = function () use ($source, $to, $copy): Node {
            if ($source instanceof Folder) {
                if ($this->fileTypes->snapshot()->isActive()) {
                    // A policy enabled after an off preflight cannot fall through to native tree copy.
                    throw new \OCA\Mcp\Access\FileWritePolicy\FileTypeDenied();
                }
                return $copy();
            }
            return $this->fileTypes->writeName($this->name($to), $copy);
        };
        return NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->capture($write, $source, $userId), $this->visibilityGuard);
    }
}
