<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Keeps the legacy matrix intact except for the explicitly reviewed Deck, Mail and Forms grants. */
final class LegacyCatalogContract {
	/**
	 * @param array<string, list<string>> $baseline catalog actually read from the released 1.0.8 code
	 * @param array<string, list<string>> $current candidate catalog
	 * @return array<string, list<string>> unchanged baseline operation matrix for before/after comparisons
	 * @throws \UnexpectedValueException on removal, changed operations or an unrecognized addition
	 */
	public static function baseline(array $baseline, array $current): array {
		$expected = $baseline;
		$expected['deck'] = [...$baseline['deck'], 'read_comments', 'comment'];
		if (isset($baseline['mail']) || isset($baseline['forms']) || $current !== [...$expected, 'mail' => ['read', 'search', 'read_message'], 'forms' => ['read']]) {
			throw new \UnexpectedValueException('Unexpected change to legacy permissions');
		}
		return $baseline;
	}

	/**
	 * @param array<string, array<string, mixed>> $answers branch observations over the complete legacy matrix
	 * @return array<string, array<string, mixed>> all observations unchanged except the explicitly checked new matrix cell
	 * @throws \UnexpectedValueException if Mail's new default is anything other than read-only Deny
	 */
	public static function answers(array $answers): array {
		foreach ($answers as &$state) {
			foreach ($state['matrix'] as &$row) {
				if (($row['grants']['mail'] ?? null) !== ['read' => false, 'search' => false, 'read_message' => false]
					|| ($row['grants']['forms'] ?? null) !== ['read' => false]
					|| ($row['grants']['deck']['read_comments'] ?? null) !== false
					|| ($row['grants']['deck']['comment'] ?? null) !== false) {
					throw new \UnexpectedValueException('Unexpected default in the legacy matrix');
				}
				unset($row['grants']['mail'], $row['grants']['forms'], $row['grants']['deck']['read_comments'], $row['grants']['deck']['comment']);
			}
			unset($row);
		}
		unset($state);
		return $answers;
	}
}
