<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Forms;

use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\FormsReader;

/** Module and safe representative plan for every Forms write. The contract never resolves native dependencies. */
final class FormsWritePlanFixtures {
	/** @return array{FormsModule,array<string,array<string,mixed>>} */
	public static function create(): array {
		$reader = new class implements FormsReader {
			public function list(string $userId): array { return []; }
			public function read(string $userId, int $formId): ?array { return null; }
		};
		return [FormsModule::withReader($reader), [
			'forms_export_responses' => [
				'formId' => 21, 'offset' => 0, 'limit' => 50, 'rows' => 2,
				'partial' => true, 'nextOffset' => 50, 'skippedRows' => 0, 'truncatedCells' => 0,
				'fileAnswersOmitted' => true, 'questionsTruncated' => false, 'answersTruncated' => false,
				'rowOrByteBoundReached' => false,
				'destination' => ['path' => 'exports/Quarterly.csv', 'requiresSharedConfirmation' => false],
			],
		]];
	}
}
