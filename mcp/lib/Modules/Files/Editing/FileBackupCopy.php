<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Locks\LockMessages;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\ManuallyLockedException;

/** Creates and verifies the original copy in the current configured destination. */
final class FileBackupCopy {
	/**
	 * @param \OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard required privacy and scope preservation
	 * @param LockAwareWrite|null $locks existing lock-aware mutation gateway
	 * @param \OCA\Mcp\Access\BackupPolicy\BackupLocation $location current destination reader
	 */
	public function __construct(private \OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard,
		private ?LockAwareWrite $locks, private \OCA\Mcp\Access\BackupPolicy\BackupLocation $location) {
	}

	/**
	 * @param Folder $root acting user's personal root
	 * @param File $file original to copy
	 * @param string $path normalized original path
	 * @param string $stamp actor-local timestamp
	 * @param string $userId acting user uid
	 * @param bool $asUser whether the write runs in the actor's session
	 * @return string verified copy path
	 * @throws ToolFailure on any copy, verification or lock failure
	 */
	public function copy(Folder $root, File $file, string $path, string $stamp, bool $asUser, string $userId, ?\Closure $mutationBoundary = null): string {
		// The node the copy was working on when it failed, so a lock refusal names the backup and not the original.
		$target = $root;
		try {
			$relative = $this->location->current()['folder'] . dirname($path);
			$folder = $mutationBoundary === null
				? NodeAccess::mutate(fn () => NodeAccess::ensureFolder($root, $relative), $this->visibilityGuard)
				: $this->guardedFolder($root, $relative, $mutationBoundary);
			$target = $folder;
			$base = $file->getName() . '.' . $stamp;
			$name = $base . '.bak';
			for ($i = 2; $folder->nodeExists($name); $i++) {
				$name = $base . '-' . $i . '.bak';
			}
			// Establish privacy while the new backup contains no sensitive bytes.
			$create = fn () => $folder->newFile($name);
			$copy = NodeAccess::mutate(fn () => $mutationBoundary === null ? $create() : $mutationBoundary($folder, $name, static fn () => null, $create), $this->visibilityGuard);
			$target = $copy;
			$this->visibilityGuard->copyHiddenTags($file, $copy);
			$stream = $file->fopen('rb');
			if (!is_resource($stream)) {
				throw new \RuntimeException('Backup source stream unavailable');
			}
			try {
				// The new copy is checked right before the original goes into it: a WebDAV client may have locked it.
				$writeCopy = static fn () => $copy->putContent($stream);
				$put = static fn () => $mutationBoundary === null
					? $writeCopy()
					: $mutationBoundary($copy, null, static fn () => null, $writeCopy);
				NodeAccess::mutate(fn () => $this->locks === null ? $put() : $this->locks->run($put, $copy, $userId, null, $asUser), $this->visibilityGuard);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$valid = $copy->getSize() === $file->getSize();
		} catch (LockWriteFailure $e) {
			throw $e->withNote(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed());
		} catch (ManuallyLockedException $e) {
			// A backup folder locked by files_lock is a lock, and stays one: the checkout answers it with 423.
			$failure = $this->locks?->failure($e, $target, $userId) ?? NodeAccess::lockFailure($e);
			throw ($failure instanceof LockWriteFailure ? $failure : new LockWriteFailure(LockMessages::lockedWithoutDetails()))
				->withNote(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed());
		} catch (\Throwable) {
			$valid = false;
		}
		if (!$valid) {
			throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed());
		}
		return $root->getRelativePath($copy->getPath()) ?? '';
	}

	/** Internal backup paths retain their reserved/exclusion semantics; the trusted caller admits each effect. */
	private function guardedFolder(Folder $root, string $relative, \Closure $boundary): Folder {
		$segments = \OCA\Mcp\Platform\FileAccess\Paths\PathGuard::segments(\OCA\Mcp\Platform\FileAccess\Paths\PathGuard::normalize($relative));
		if (count($segments) > 100) {throw new \RuntimeException('Backup folder depth exceeds its bounded limit.');}
		$folder = $root;
		foreach ($segments as $segment) {
			if ($folder->nodeExists($segment)) {$next = $folder->get($segment);}
			else {$next = NodeAccess::mutate(fn () => $boundary($folder, $segment, static fn () => null, fn () => $folder->newFolder($segment)), $this->visibilityGuard);}
			if (!$next instanceof Folder) {throw new \RuntimeException('Backup path is not a folder.');}
			$folder = $next;
		}
		return $folder;
	}
}
