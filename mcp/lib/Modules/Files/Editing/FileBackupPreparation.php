<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Access\BackupPolicy\BackupCopies;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Files\File;

/** Retains historical preconditions independently of the optional Files copy preference. */
final class FileBackupPreparation {
	/**
	 * @param IAppManager $appManager official per-user enablement
	 * @param IUserManager $userManager acting user resolution
	 * @param LockAwareWrite|null $locks existing write lock checks
	 * @param \OCA\Mcp\Access\BackupPolicy\BackupLocation $location current reserved names
	 * @param BackupCopies $copies effective preference, effective per-actor preference
	 */
	public function __construct(private IAppManager $appManager, private IUserManager $userManager,
		private ?LockAwareWrite $locks, private BackupCopies $copies, private \OCA\Mcp\Access\BackupPolicy\BackupLocation $location) {
	}

	/**
	 * @param File $file original file to overwrite
	 * @param string $path normalized path
	 * @param string $userId actor
	 * @param string|null $etag expected revision
	 * @param bool $asUser whether the write runs with the actor's session
	 * @param \Closure(): string $copy verified original-copy operation
	 * @return string copy path or empty string when administratively suspended with native versioning enabled
	 * @throws ToolFailure if a historical precondition fails or a required copy cannot be verified
	 */
	public function prepare(File $file, string $path, string $userId, ?string $etag, bool $asUser, \Closure $copy): string {
		$user = $this->userManager->get($userId);
		if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
			throw new ToolFailure(EditingMessages::versioningOff());
		}
		if (!$file->isUpdateable()) {
			throw new ToolFailure(FileAccessMessages::forbidden());
		}
		BackupWriteBoundary::check($path, $this->location);
		NodeAccess::checkEtag($file, $etag);
		$this->locks?->assertWritable($file, $userId, $path, $asUser);
		return !$this->copies->required($userId) ? '' : $copy();
	}
}
