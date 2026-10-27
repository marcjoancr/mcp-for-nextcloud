<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Fresh reservation admission, including checkout links issued before the administrator changed destinations. */
final class BackupWriteBoundary {
    /** Recheck inside the mutation callback, after lock and file-type admission. */
    public static function run(string $path, BackupLocation $location, callable $write): mixed {
        self::check($path, $location);
        return $write();
    }

    /** Resolve a mounted node's current actor-relative path, without accepting foreign roots. */
    public static function node(\OCP\Files\Node $node, BackupLocation $location, string $child = ''): void {
        if (!preg_match('~^/[^/]+/files(?:/(.*))?$~D', $node->getPath(), $match)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        self::check('/' . trim(($match[1] ?? '') . ($child === '' ? '' : '/' . $child), '/'), $location);
    }

    public static function writeNode(\OCP\Files\Node $node, BackupLocation $location, callable $write, string $child = ''): mixed {
        self::node($node, $location, $child);
        return $write();
    }

	/**
	 * @param string $path normalized actor-relative write path
	 * @param BackupLocation $location live reserved-name document
	 * @return void
	 * @throws ToolFailure if the path is reserved or reservation metadata cannot be read
	 */
	public static function check(string $path, BackupLocation $location): void {
		if ($location->contains($path)) {
			throw new ToolFailure(EditingMessages::backupPath());
		}
	}

	/**
	 * @param string $path normalized actor-relative path
	 * @param BackupLocation $location live reserved-name document
	 * @return string the admitted path
	 * @throws ToolFailure when a reserved path cannot be written
	 */
	public static function checked(string $path, BackupLocation $location): string {
		self::check($path, $location);
		return $path;
	}

	/**
	 * @param FileBackup $backup live admission service
	 * @param string $path original actor-relative path
	 * @param string $copy verified copy path, or empty for an authorized suspension
	 * @return void
	 * @throws ToolFailure for this reservation refusal, carrying the truthful recovery note
	 */
	public static function afterCopy(FileBackup $backup, string $path, string $copy): void {
		try {
			$backup->assertWritablePath($path);
		} catch (ToolFailure $error) {
			throw new ToolFailure($error->getMessage() . ' ' . EditingMessages::originalKept($copy));
		}
	}
}
