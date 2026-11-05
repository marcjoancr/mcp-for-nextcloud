<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Keeps the legacy matrix intact except for explicitly reviewed additions. */
final class LegacyCatalogContract {
	private const FILES_ADDITIONS = ['description_read', 'description_edit', 'tags_read', 'tags_create', 'tags_edit', 'upload_link'];

	/**
	 * @param array<string, list<string>> $baseline catalog actually read from the released 1.0.8 code
	 * @param array<string, list<string>> $current candidate catalog
	 * @return array<string, list<string>> unchanged baseline operation matrix for before/after comparisons
	 * @throws \UnexpectedValueException on removal, changed operations or an unrecognized addition
	 */
	public static function baseline(array $baseline, array $current): array {
		$expected = $baseline;
		$expected['files'] = [...$baseline['files'], ...self::FILES_ADDITIONS];
		$expected['deck'] = [...$baseline['deck'], 'read_comments', 'comment', 'search'];
		$expected['calendar'] = [...$baseline['calendar'], 'availability'];
		$expected['talk'] = [...$baseline['talk'], 'publish_summary', 'search'];
		if (isset($baseline['mail']) || isset($baseline['forms']) || $current !== [...$expected, 'mail' => ['read', 'search', 'read_message', 'read_attachment', 'save_attachment', 'read_drafts', 'write_drafts', 'delete_drafts'], 'forms' => ['read', 'read_responses', 'summarize_responses', 'export_responses']]) {
			throw new \UnexpectedValueException('Unexpected change to legacy permissions');
		}
		return $baseline;
	}

	/**
	 * @param array<string, array<string, mixed>> $answers branch observations over the complete legacy matrix
	 * @return array<string, array<string, mixed>> all observations unchanged except the explicitly checked new matrix cell
	 * @throws \UnexpectedValueException if any new operation has a default other than explicit Deny
	 */
	public static function answers(array $answers): array {
		foreach ($answers as &$state) {
			foreach ($state['matrix'] as &$row) {
				foreach (self::FILES_ADDITIONS as $operation) {
					if (($row['grants']['files'][$operation] ?? null) !== false) {
						throw new \UnexpectedValueException("Expected Files $operation to be denied after upgrade");
					}
					unset($row['grants']['files'][$operation]);
				}
				if (($row['grants']['mail'] ?? null) !== ['read' => false, 'search' => false, 'read_message' => false, 'read_attachment' => false, 'save_attachment' => false, 'read_drafts' => false, 'write_drafts' => false, 'delete_drafts' => false]
					|| ($row['grants']['forms'] ?? null) !== ['read' => false, 'read_responses' => false, 'summarize_responses' => false, 'export_responses' => false]
					|| ($row['grants']['talk']['publish_summary'] ?? null) !== false
					|| ($row['grants']['talk']['search'] ?? null) !== false
					|| ($row['grants']['deck']['read_comments'] ?? null) !== false
					|| ($row['grants']['deck']['comment'] ?? null) !== false
					|| ($row['grants']['deck']['search'] ?? null) !== false
					|| ($row['grants']['calendar']['availability'] ?? null) !== false) {
					throw new \UnexpectedValueException('Unexpected default in the legacy matrix');
				}
				unset($row['grants']['deck']['search'], $row['grants']['calendar']['availability'], $row['grants']['mail'], $row['grants']['forms'], $row['grants']['deck']['read_comments'], $row['grants']['deck']['comment'], $row['grants']['talk']['publish_summary'], $row['grants']['talk']['search']);
			}
			unset($row);
		}
		unset($state);
		return $answers;
	}
}
