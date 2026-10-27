<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use DOMDocument;
use LibXMLError;
use PHPUnit\Framework\TestCase;

/**
 * Validates appinfo/info.xml against the official Nextcloud app schema.
 *
 * The schema is vendored as tests/fixtures/info.xsd, copied verbatim from
 * https://apps.nextcloud.com/schema/apps/info.xsd, so the check is hermetic and runs
 * offline. It is the same schema the app store applies on upload, which is stricter than
 * the parser the server uses: element order and required elements such as <bugs> are part
 * of it, so reordering or dropping a child element fails here too.
 */
final class InfoXmlTest extends TestCase {
    /** Official app schema, vendored in tests/fixtures. */
    private const SCHEMA = __DIR__ . '/../fixtures/info.xsd';

    /** App manifest checked against the schema. */
    private const MANIFEST = __DIR__ . '/../../appinfo/info.xml';

    /** The manifest must be well-formed and satisfy the official schema. */
    public function testInfoXmlIsValidAgainstTheOfficialSchema(): void {
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $document = new DOMDocument();
            $loaded = $document->load(self::MANIFEST);
            $loadErrors = libxml_get_errors();
            libxml_clear_errors();
            $valid = $loaded && $document->schemaValidate(self::SCHEMA);
            $schemaErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        $this->assertTrue($loaded, 'appinfo/info.xml must be well-formed XML: ' . $this->messages($loadErrors));
        $this->assertTrue($valid, 'appinfo/info.xml must satisfy ' . basename(self::SCHEMA) . ': ' . $this->messages($schemaErrors));
    }

    /** @return \SimpleXMLElement the parsed manifest */
    private function manifest(): \SimpleXMLElement {
        $manifest = simplexml_load_file(self::MANIFEST);
        $this->assertInstanceOf(\SimpleXMLElement::class, $manifest);
        return $manifest;
    }

    /**
     * @param string $element name, summary or description
     * @return array<string, string> its text per language, "en" for the untagged one
     */
    private function localized(string $element): array {
        $texts = [];
        foreach ($this->manifest()->{$element} as $node) {
            $texts[(string)($node['lang'] ?? 'en')] = trim((string)$node);
        }
        return $texts;
    }

    /**
     * The store shows the summary and the description in English, Brazilian Portuguese, Spanish and German.
     * The name is not translated: it is a single <name> without lang (see the test below).
     */
    public function testSummaryAndDescriptionComeInEveryLanguageOfTheApp(): void {
        foreach (['summary', 'description'] as $element) {
            $texts = $this->localized($element);
            $this->assertSame(['en', 'pt-br', 'es', 'de'], array_keys($texts), $element);
            foreach ($texts as $lang => $text) {
                $this->assertNotSame('', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('test release', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('versão de teste', $text, "$element ($lang)");
                $this->assertStringNotContainsStringIgnoringCase('dal' . 'comad', $text, "$element ($lang): no customer's data in the store listing");
            }
        }
    }

    /**
     * Exactly one <name>, without a lang attribute. Nextcloud's InfoParser turns repeated
     * <name> elements into an array, and the admin app list then shows that array as raw JSON.
     */
    public function testTheNameIsASingleUntranslatedElement(): void {
        $names = $this->manifest()->name;
        $this->assertCount(1, $names, 'appinfo/info.xml must have exactly one <name>');
        $this->assertNull($names[0]['lang'], '<name> must not carry a lang attribute');
        $this->assertSame('Nextcloud MCP', trim((string)$names[0]));
    }

    /** The description says what the app does, how it keeps the user in control and which clients connect to it. */
    public function testTheDescriptionListsTheModulesTheSafeguardsAndTheClients(): void {
        $expected = [
            'en' => ['Files', 'Notes', 'Calendar', 'Contacts', 'Tasks', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'never deletes'],
            'pt-br' => ['Arquivos', 'Notas', 'Calendário', 'Contatos', 'Tarefas', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'nunca apaga'],
            'es' => ['Archivos', 'Notas', 'Calendario', 'Contactos', 'Tareas', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'nunca borra'],
        ];
        $expected['de'] = ['Dateien', 'Notes', 'Kalender', 'Kontakten', 'Aufgaben', 'Deck', 'Talk', 'Claude', 'ChatGPT', 'Gemini CLI', 'OAuth', 'nicht gelöscht'];
        foreach ($this->localized('description') as $lang => $text) {
            foreach ($expected[$lang] as $needle) {
                $this->assertStringContainsString($needle, $text, "description ($lang)");
            }
            $this->assertStringContainsString('Nextcloud 33–35', $text, "description ($lang) names the supported Nextcloud versions");
        }
    }

    /** Public descriptions must not promise mandatory Files copies after the configurable policy port. */
    public function testSelected121CapabilitiesAndCopyLimitsAppearInEveryStoreLocale(): void {
        $required = [
            'en' => ['64', 'previous names', 'Contact deletion always requires a verified backup', 'no message reading', 'native versions are not guaranteed'],
            'pt-br' => ['64', 'nomes anteriores', 'Excluir contato sempre exige backup verificado', 'sem ler mensagens', 'não há garantia de versão nativa'],
            'es' => ['64', 'nombres anteriores', 'Eliminar contactos siempre exige copia verificada', 'sin leer mensajes', 'no se garantiza una versión nativa'],
            'de' => ['64', 'Frühere Namen', 'Kontakte löschen benötigt immer eine geprüfte Sicherung', 'ohne Nachrichten zu lesen', 'nicht für jede Datei'],
        ];
        $descriptions = $this->localized('description');
        foreach ($required as $locale => $phrases) {
            self::assertArrayHasKey($locale, $descriptions);
            foreach ($phrases as $phrase) {
                self::assertStringContainsString($phrase, $descriptions[$locale], $locale . ': ' . $phrase);
            }
        }
        foreach (['every edit keeps a backup', 'toda edição guarda um backup', 'cada edición guarda una copia'] as $obsolete) {
            foreach ($descriptions as $description) self::assertStringNotContainsString($obsolete, $description);
        }
    }

    public function testStoreMetadataPointsAtThePublicRepository(): void {
        $manifest = $this->manifest();
        $repository = 'https://github.com/marcjoancr/mcp-for-nextcloud';

        $this->assertSame('mcp', (string)$manifest->id);
        $this->assertSame('2.0.0', (string)$manifest->version);
        $this->assertSame($repository, (string)$manifest->website);
        $this->assertSame($repository . '/issues', (string)$manifest->bugs);
        $this->assertSame($repository . '.git', (string)$manifest->repository);
        $this->assertSame('git', (string)$manifest->repository['type']);
        $this->assertSame('Jhonatan Jaworski', (string)$manifest->author);
        $this->assertSame('jhonatan@sintax.tech', (string)$manifest->author['mail']);
        $this->assertSame(['ai', 'integration', 'tools'], array_map('strval', iterator_to_array($manifest->category, false)));
        $this->assertSame('AGPL-3.0-or-later', (string)$manifest->licence);
    }

    /** The two screenshots live in the repository's root screenshots/ folder on main, outside the app package, under the names agreed for the listing. */
    public function testScreenshotsComeFromTheRepository(): void {
        $base = 'https://raw.githubusercontent.com/sintax-tech/mcp-for-nextcloud/main/screenshots/';

        $this->assertSame([$base . 'admin.png', $base . 'plan.png'], array_map('strval', iterator_to_array($this->manifest()->screenshot, false)));
    }

    /** Release profiles are narrower than the historical PHP compatibility test toolchain. */
    public function testDependenciesDeclareForkPlatformMinimums(): void {
        $dependencies = $this->manifest()->dependencies;

        $this->assertSame(['33', '35'], [(string)$dependencies->nextcloud['min-version'], (string)$dependencies->nextcloud['max-version']]);
        $this->assertSame('8.4', (string)$dependencies->php['min-version']);
        $this->assertNull($dependencies->php['max-version']);
        $composer = json_decode((string)file_get_contents(dirname(self::MANIFEST, 2) . '/composer.json'), true);
        $this->assertSame('>=8.2', $composer['require']['php']);
        $this->assertSame('8.2', $composer['config']['platform']['php']);
    }

    /**
     * @param list<LibXMLError> $errors collected by libxml during the last step
     * @return string their messages, one per line, for the failure report
     */
    private function messages(array $errors): string {
        return trim(implode("\n", array_map(static fn (LibXMLError $error): string => trim($error->message), $errors)));
    }
}
