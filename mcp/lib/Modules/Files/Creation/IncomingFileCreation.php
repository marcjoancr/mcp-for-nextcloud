<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/** Creates incoming bytes with the same filename, visibility, scope, sharing, quota and write checks as Files. */
final class IncomingFileCreation implements IncomingFileWriter {
    public function __construct(
        private IRootFolder $rootFolder,
        private FileCreation $creation,
        private SharedWriteGuard $sharedWrites,
        private FilesAccessGuard $visibility,
    ) {}

    /** @return array<string, mixed> a no-write preview of the destination and its current Files access */
    public function preview(string $userId, string $path, int $size): array {
        return $this->safe(function () use ($userId, $path, $size): array {
            $this->visibility->assertScopeReadable();
            $root = $this->userRoot($userId);
            $plan = $this->creation->planUpload($root, $userId, $path, $size);
            $target = $this->creation->target($root, $plan['path']);

            return [
                'path' => $plan['path'],
                'name' => $plan['name'],
                'folder' => $plan['folder'],
                'destinationFolderId' => (int)$target['folder']->getId(),
                'size' => $size,
                'maxBytes' => $plan['uploadMaxBytes'],
                'access' => $plan['access'],
                'shared' => $plan['shared'],
                'requiresSharedConfirmation' => $plan['requiresSharedConfirmation'],
                'recoverable' => true,
            ];
        });
    }

    /** @return array<string, mixed> a file receipt or a shared-write confirmation request */
    public function save(string $userId, string $path, string $content, bool $confirmShared): array {
        return $this->safe(function () use ($userId, $path, $content, $confirmShared): array {
            $this->visibility->assertScopeReadable();
            $root = $this->userRoot($userId);
            $size = strlen($content);
            // planUpload re-reads the active filename policy, destination visibility, collision state, and current
            // quota limit. The target and shared guard below are deliberately resolved again immediately before write.
            $this->creation->planUpload($root, $userId, $path, $size);
            $target = $this->creation->target($root, $path);
            if (($confirmation = $this->sharedWrites->guard($target['folder'], $userId, $target['path'], $confirmShared)) !== null) {
                return $confirmation;
            }
            FileCreation::assertCreatable($target['folder']);
            $file = $this->creation->write($target['folder'], $target['name'], $content, $userId);
            return $this->creation->receipt($target, $file, $userId);
        });
    }

    /** @return Folder the requested user’s own Files root */
    private function userRoot(string $userId): Folder {
        try {
            $root = NodeAccess::run(fn (): Folder => $this->rootFolder->getUserFolder($userId));
            $owner = $root->getOwner();
        } catch (\Throwable) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        if ($owner === null || $owner->getUID() !== $userId) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        return $root;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function safe(callable $operation): mixed {
        try {
            return NodeAccess::run($operation);
        } catch (ArgumentValidationException|ToolFailure|FileExists|
            \OCA\Mcp\Access\FileWritePolicy\FileTypeDenied|
            \OCA\Mcp\Access\FileWritePolicy\FileWritePolicyUnavailable|
            \OCA\Mcp\Access\Policy\PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ToolFailure(CreationMessages::createFailed());
        }
    }
}
