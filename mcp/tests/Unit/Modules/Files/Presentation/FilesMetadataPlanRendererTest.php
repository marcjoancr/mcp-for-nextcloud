<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\FilesPlanRenderer;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

final class FilesMetadataPlanRendererTest extends TestCase {
    protected function tearDown(): void { Translator::reset(); }

    public function testTagEditShowsExactBeforeAndAfterAndSharedConfirmation(): void {
        $body = FilesPlanRenderer::render('files_set_tags', [
            'changes' => [[
                'path' => '/Shared/meal.md', 'current' => ['Old'], 'add' => ['Tacos'], 'remove' => ['Old'],
                'shared' => ['scope' => 'shared', 'ownerDisplayName' => 'Marta', 'sharedBy' => 'Marta'],
            ]],
        ]);

        self::assertNotNull($body);
        self::assertStringContainsString('**/Shared/meal.md**', $body);
        self::assertStringContainsString('Before: "Old"', $body);
        self::assertStringContainsString('After: "Tacos"', $body);
        self::assertStringContainsString('Marta', $body);
        self::assertStringContainsString('extra confirmation', $body);
    }

    public function testTagAndDescriptionTextIsEscapedAndDescriptionsShowExactEmptyValue(): void {
        $before = "A [meal]\n<b>older</b>";
        $body = FilesPlanRenderer::render('files_set_image_description', [
            'path' => '/Photos/dish.jpg', 'current_description' => $before, 'description' => '', 'source' => 'embedded_exif',
        ]);

        self::assertNotNull($body);
        self::assertStringContainsString('**/Photos/dish.jpg**', $body);
        self::assertStringContainsString('Before: ' . \OCA\Mcp\Mcp\Confirmation\PlanText::quoted($before), $body);
        self::assertStringContainsString('After: ""', $body);
        self::assertStringNotContainsString('<b>', $body);
        self::assertStringNotContainsString("\n<b>", $body);
    }

    public function testDescriptionAndTagPreviewsRenderInCurrentLocale(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = FilesPlanRenderer::render('files_set_tags', [
            'changes' => [['path' => '/Comida.md', 'current' => [], 'add' => ['Tacos'], 'remove' => [], 'shared' => null]],
        ]);
        self::assertNotNull($body);
        self::assertStringContainsString('Antes:', $body);
        self::assertStringContainsString('Depois:', $body);
    }
}
