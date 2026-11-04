<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Human-readable export details; source answers and opaque plan state never enter the preview text. */
final class FormsPlanRenderer {
	/** @param array<string,mixed> $plan */
	public function render(string $tool, array $plan): ?string {
		if ($tool !== 'forms_export_responses') {
			return null;
		}
		$destination = is_array($plan['destination'] ?? null) ? $plan['destination'] : [];
		$path = is_string($destination['path'] ?? null) ? $destination['path'] : '';
		$rows = is_int($plan['rows'] ?? null) ? $plan['rows'] : 0;
		$partial = ($plan['partial'] ?? false) === true;
		$lines = [
			Translator::t('Form ID: %d', [is_int($plan['formId'] ?? null) ? $plan['formId'] : 0]),
			Translator::t('Response page: offset %d, limit %d', [is_int($plan['offset'] ?? null) ? $plan['offset'] : 0, is_int($plan['limit'] ?? null) ? $plan['limit'] : 0]),
			Translator::t('CSV file in Files: %s', [PlanText::inline($path)]),
			Translator::t('Response rows: %d', [$rows]),
		];
		if ($partial) {
			$nextOffset = $plan['nextOffset'] ?? null;
			$lines[] = is_int($nextOffset)
				? Translator::t('This export page is partial; continue with offset %d.', [$nextOffset])
				: Translator::t('This export page is partial; review the omitted and truncated content details.');
		}
		if (($plan['questionsTruncated'] ?? false) === true) {
			$lines[] = Translator::t('Question metadata exceeded the display limit; some answer rows were omitted.');
		}
		if (($plan['answersTruncated'] ?? false) === true) {
			$lines[] = Translator::t('Some submissions exceeded the answer limit; extra answers were omitted.');
		}
		if (($plan['rowOrByteBoundReached'] ?? false) === true) {
			$lines[] = Translator::t('The CSV row or byte limit was reached; some answer rows were omitted.');
		}
		if (is_int($plan['truncatedCells'] ?? null) && $plan['truncatedCells'] > 0) {
			$lines[] = Translator::t('Text was truncated in %d CSV cells.', [$plan['truncatedCells']]);
		}
		if (($plan['fileAnswersOmitted'] ?? false) === true) {
			$lines[] = Translator::t('File answers were omitted from the export.');
		}
		if (($destination['requiresSharedConfirmation'] ?? false) === true) {
			$lines[] = Translator::t('The destination is shared and requires separate confirmation.');
		}
		return implode("\n\n", $lines);
	}
}
