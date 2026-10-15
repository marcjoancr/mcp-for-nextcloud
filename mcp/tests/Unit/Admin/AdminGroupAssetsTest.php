<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** T17: every shipped group-editor source string has matching pt_BR/es JSON and browser translations. */
final class AdminGroupAssetsTest extends TestCase {
    public function testGrantOnlyBatchIsUsedOnlyByTheAdminCountingReadModel(): void {
        $lib = dirname(__DIR__, 3) . '/lib';
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib)) as $file) {
            if ($file->getExtension() !== 'php') continue;
            if (preg_match('/->beginManyGrants\s*\(/', file_get_contents($file->getPathname()))) {
                $callers[] = substr($file->getPathname(), strlen($lib) + 1);
            }
        }
        $this->assertSame(['Service/GroupDirectory.php'], $callers, 'Grant-only contexts must never decide access');
        $this->assertStringContainsString('admin counting only, not an access decision',
            file_get_contents($lib . '/Service/Policy/PolicyResolver.php'));
    }

    public function testGroupStringsAndPlaceholdersAreCompleteInBothFormats(): void {
        $app = dirname(__DIR__, 3);
        $source = file_get_contents($app . '/js/admin-groups.js');
        preg_match_all("/t\\('mcp', '([^']+)'/", $source, $script);
        $template = file_get_contents($app . '/templates/admin.php');
        $start = strpos($template, 'id="mcp-group-editor"');
        $block = substr($template, $start, strpos($template, 'id="mcp-block-matrix"') - $start);
        preg_match_all("/\\\$l->t\\('([^']+)'/", $block, $markup);
        $strings = array_unique([...$script[1], ...$markup[1],
            'Unknown', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.',
            'Granted by groups: {groups}']);
        foreach (['pt_BR', 'es'] as $lang) {
            $translations = json_decode(file_get_contents("$app/l10n/$lang.json"), true, flags: JSON_THROW_ON_ERROR)['translations'];
            $browser = file_get_contents("$app/l10n/$lang.js");
            foreach ($strings as $text) {
                $this->assertArrayHasKey($text, $translations, "$lang: $text");
                preg_match_all('/\{\w+\}/', $text, $expected);
                preg_match_all('/\{\w+\}/', $translations[$text], $actual);
                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "$lang: $text placeholders");
                $this->assertStringContainsString(json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ' : ' . json_encode($translations[$text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $browser);
            }
        }
    }
}
