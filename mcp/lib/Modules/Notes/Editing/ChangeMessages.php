<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Notes\Editing;
use OCA\Mcp\L10n\Translator;
final class ChangeMessages {
    public static function editModesExclusive(): string {
        return Translator::t('Use only one of content, append or old/new.');
    }
    public static function editNeedsEtag(): string {
        return Translator::t('append and old/new need the etag returned by notes_read or by the plan.');
    }
    public static function changedSinceRead(string $etag): string {
        return Translator::t('The note changed since it was read; nothing was written. Current etag: %s', [$etag]);
    }
    public static function notePlanChanged(): string {
        return Translator::t('The note changed since the plan; check it again.');
    }
    public static function planAppend(): string {
        return Translator::t('Nothing was changed. After your approval the text below is added at the end of the note.');
    }
    public static function planReplace(): string {
        return Translator::t('Nothing was changed. After your approval the passage below is replaced in the note.');
    }
    public static function excerptTruncated(): string {
        return Translator::t('[content truncated]');
    }
    public static function noteTooLarge(int $max): string {
        return Translator::t('Note exceeds the limit of %d bytes.', [$max]);
    }
    public const PARAM_APPEND = 'Text added at the end, on a new line.';
    public const PARAM_OLD = 'Passage to replace; must appear exactly once.';
    public const PARAM_NEW = 'Text that replaces old.';
}
