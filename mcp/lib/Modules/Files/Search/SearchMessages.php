<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Search;

use OCA\Mcp\L10n\Translator;

final class SearchMessages {
    /** @return string notice when full-text search is not available */
    public static function searchNameOnlyNotice(): string {
        return Translator::t('Full-text search is not available. Search was performed by file name only.');
    }

    /** @return string notice when full-text search threw an error and fell back */
    public static function searchFallbackNotice(): string {
        return Translator::t('Full-text search encountered an error; search was performed by file name only.');
    }

    public static function searchNameAndDescriptionNotice(): string {
        return Translator::t('Full-text search is not available. Search used file names and permitted stored descriptions.');
    }

    public static function searchFallbackWithDescriptionNotice(): string {
        return Translator::t('Full-text search encountered an error. Search used file names and permitted stored descriptions.');
    }

    /** @return string description of files_search */
    public static function searchTool(): string {
        return 'Search files by name or indexed content in the user folder on Nextcloud; when permitted, stored file descriptions are searched too.';
    }

    /** @return string description of the search query parameter */
    public static function searchQuery(): string {
        return 'Search term to find in file names or indexed content, and in stored file descriptions when permitted.';
    }

    /** @return string description of the search limit parameter */
    public static function searchLimit(): string {
        return 'Maximum number of results to return (1-100, default 25).';
    }

    /** @return string description of the search mode parameter */
    public static function searchMode(): string {
        return 'Search mode: "auto" uses full-text content search when available, falls back to name search, and includes matching stored descriptions when permitted. Results report content_and_description or name_and_description when descriptions are searched; "name" searches only file names.';
    }
}
