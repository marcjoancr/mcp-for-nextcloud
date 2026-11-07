<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Descriptions;

use OCP\FilesMetadata\Model\IFilesMetadata;

/** Native Files metadata index of candidate words; the stored description decides the actual match. */
final class DescriptionIndex {
    public const KEY = 'mcp-description-terms';

    public static function apply(IFilesMetadata $metadata, string $text): void {
        $metadata->setStringList(self::KEY, self::terms($text), true);
    }

    /** @return list<string> Compact buckets keep the index within native value and metadata size limits. */
    public static function terms(string $text): array {
        $terms = [];
        foreach (self::words($text) as $word) {
            $terms[] = self::bucket($word);
        }
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);
        return $terms;
    }

    /** @return list<string> Unicode words, case-folded and canonically normalized. */
    public static function words(string $text): array {
        $text = \Normalizer::normalize(mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'));
        if ($text === false) {
            return [];
        }
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*/u', $text, $matches);
        return $matches[0];
    }

    public static function bucket(string $word): string {
        return substr(hash('sha256', $word), 0, 8);
    }

    /** @param non-empty-list<string> $words */
    public static function matches(string $description, array $words): bool {
        return str_contains(' ' . implode(' ', self::words($description)) . ' ', ' ' . implode(' ', $words) . ' ');
    }
}
