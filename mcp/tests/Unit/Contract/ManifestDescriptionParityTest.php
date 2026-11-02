<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Keep the reviewed upstream descriptions except where the 2.2 permission editor supersedes them. */
final class ManifestDescriptionParityTest extends TestCase {
    private const PERMISSIONS = [
        'en' => [
            'group_grants' => 'When groups disagree, a group block takes precedence.',
            'administration' => 'Search for a person to see their effective access and the rule that explains it.',
            'inheritance_reset' => 'Remove the exception to use the inherited rule again.',
            'connection_tools' => 'Disabling an app hides its tools and preserves its saved selections.',
        ],
        'pt-br' => [
            'group_grants' => 'Se dois grupos divergirem, o bloqueio prevalece.',
            'administration' => 'Busque uma pessoa para ver o acesso efetivo e a regra que o explica.',
            'inheritance_reset' => 'Remova a exceção para voltar a herdar a regra.',
            'connection_tools' => 'Desativar um app oculta suas ferramentas e preserva as seleções salvas.',
        ],
        'es' => [
            'group_grants' => 'Si dos grupos discrepan, prevalece el bloqueo.',
            'administration' => 'Busca a una persona para ver su acceso efectivo y la regla que lo explica.',
            'inheritance_reset' => 'Retira la excepción para volver a heredar la regla.',
            'connection_tools' => 'Desactivar una app oculta sus herramientas y conserva las selecciones guardadas.',
        ],
    ];

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function storeLocales(): iterable {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../../fixtures/manifest-1.1.2-descriptions.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['locales'] as $locale => $expected) {
            yield $locale => [$locale, $expected['summary'], array_replace($expected['paragraphs'], self::PERMISSIONS[$locale])];
        }
    }

    /** @param array<string, string> $paragraphs reviewed feature descriptions */
    #[DataProvider('storeLocales')]
    public function testStoreMetadataRetainsReviewedFeatureDescriptions(string $locale, string $summary, array $paragraphs): void {
        $manifest = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
        self::assertNotFalse($manifest);
        $selector = $locale === 'en' ? '[not(@lang)]' : '[@lang="' . $locale . '"]';
        $summaries = $manifest->xpath('summary' . $selector);
        $descriptions = $manifest->xpath('description' . $selector);
        self::assertCount(1, $summaries, $locale . ': exactly one summary must be available');
        self::assertCount(1, $descriptions, $locale . ': exactly one description must be available');
        self::assertSame(self::normalize($summary), self::normalize((string)$summaries[0]), $locale . ': group grants must appear in the summary');
        $description = self::normalize((string)$descriptions[0]);
        foreach ($paragraphs as $feature => $paragraph) {
            self::assertStringContainsString(self::normalize($paragraph), $description, $locale . ': missing or changed feature description: ' . $feature);
        }
    }

    /** Ignore formatting changes while retaining the reviewed wording and safety qualifications. */
    private static function normalize(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
