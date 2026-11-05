<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Tags;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\L10n\Translator;

final class TagsMessages {
    public static function creationDenied(): ToolFailure { return new ToolFailure(Translator::t('Native policy does not allow you to create tags.')); }
    public static function tagUnavailable(): ToolFailure { return new ToolFailure(Translator::t('The requested tag is unavailable or not assignable.')); }
    public static function assignmentDenied(): ToolFailure { return new ToolFailure(Translator::t('Native policy does not allow this tag assignment.')); }
    public static function emptyChange(): ToolFailure { return new ToolFailure(Translator::t('Specify at least one tag to add or remove.')); }
    public static function conflictingChange(): ToolFailure { return new ToolFailure(Translator::t('A tag cannot be both added and removed.')); }
    public static function protectedTag(): ToolFailure { return new ToolFailure(Translator::t('This tag cannot be changed through MCP.')); }
    public static function stalePlan(): ToolFailure { return new ToolFailure(Translator::t('Tag state changed after preview; nothing was changed. Preview the current state again.')); }
    public static function userUnavailable(): ToolFailure { return new ToolFailure(Translator::t('The authenticated user is unavailable.')); }
    public static function invalidName(): ToolFailure { return new ToolFailure(Translator::t('Tag names must contain 1 to 64 UTF-8 bytes and no control characters.')); }
    public static function invalidPaths(): ToolFailure { return new ToolFailure(Translator::t('Provide 1 to 20 distinct accessible file paths.')); }
    public static function invalidNames(): ToolFailure { return new ToolFailure(Translator::t('Tag lists must contain at most 100 distinct valid names.')); }
    public static function fileNotWritable(): ToolFailure { return new ToolFailure(Translator::t('This file is not writable.')); }
    public static function listTool(): string { return 'List public, assignable native file tags, optionally including tags on one accessible file.'; }
    public static function createTool(): string { return 'Create or reuse a public, assignable native file tag.'; }
    public static function setTool(): string { return 'Add and/or remove public, assignable native tags on accessible files.'; }
    public static function createPlan(): string { return Translator::t('Create this public, assignable tag? Nothing was changed.'); }
    public static function createReusePlan(): string { return Translator::t('This visible, assignable tag already exists. Nothing was changed.'); }
    public static function editPlan(): string { return Translator::t('Apply these tag changes? Nothing was changed.'); }
}
