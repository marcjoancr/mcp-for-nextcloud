<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\BackupPolicy\BackupCopies;
use OCA\Mcp\Access\BackupPolicy\BackupLocation;

/** Describes the copy that this actor's confirmed operation would actually create. */
final class BackupPlan {
	/**
	 * @param BackupLocation $location live destination, canonical live settings
	 * @param BackupCopies $copies live per-actor preference, effective per-actor preference
	 */
	public function __construct(private BackupLocation $location, private BackupCopies $copies) {
	}

	/**
	 * @param array<string, mixed> $plan existing tool-specific plan and confirmations
	 * @param string $uid acting user
	 * @return array<string, mixed> plan with honest copy, recovery and consequence fields
	 */
	public function decorate(array $plan, string $uid): array {
		$settings = $this->location->current();
		if ($this->copies->required($uid)) {
			$plan['backup'] = '/' . $settings['folder'];
			return $plan;
		}
		return array_replace($plan, [
			'backup' => '',
			'recoverable' => false,
			'message' => BackupPlanInstructions::withoutCopy((string)($plan['action'] ?? '')),
			'consequence' => Translator::t('MCP pre-write copies are suspended. Nextcloud versioning follows its own policy and may not retain a version for every file or backend.'),
		]);
	}
}
