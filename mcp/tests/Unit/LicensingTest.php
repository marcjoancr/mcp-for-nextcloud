<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicensingTest extends TestCase {
    private static function libraryHeader(string $source): bool {
        // Accept multiple SPDX authors while retaining the original source credit and license requirement.
        if (preg_match('~\A<\?php\s*/\*\s*(?:\* ' . 'SPDX-' . 'FileCopyrightText: [^\r\n]+\r?\n\s*)+\* '
            . 'SPDX-' . 'License-Identifier: AGPL-3\.0-or-later\s*\*/~', $source, $header) !== 1) {
            return false;
        }
        return preg_match('~\* ' . 'SPDX-' . 'FileCopyrightText: 2026 Sintax \(Jhonatan Jaworski\) <contato@sintax\.tech>[ \t]*\r?$~m', $header[0]) === 1;
    }

    public function testLibraryHeaderAllowsMultipleAuthorsAndRequiresOriginalCreditAndExactLicense(): void {
        $original = ' * SPDX-' . 'FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>';
        $contributor = ' * SPDX-' . 'FileCopyrightText: 2026 MCP for Nextcloud contributors';
        $license = ' * SPDX-' . 'License-Identifier: AGPL-3.0-or-later';
        $header = static fn (array $lines): string => "<?php\n/*\n" . implode("\n", $lines) . "\n */\n";
        self::assertTrue(self::libraryHeader($header([$original, $license])));
        self::assertTrue(self::libraryHeader($header([$original, $contributor, $license])));
        self::assertTrue(self::libraryHeader($header([$contributor, $original, $license])));
        self::assertFalse(self::libraryHeader($header([$contributor, $license])));
        self::assertFalse(self::libraryHeader($header([$original, $contributor])));
        self::assertFalse(self::libraryHeader($header([$original, str_replace('AGPL-3.0-or-later', 'MIT', $license)])));
    }

    /** Every library source must declare its copyright and license in its PHP header. */
    public function testEveryLibraryFileHasSpdxHeader(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib', \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            // The tag names are split so the REUSE tool does not read this pattern as a header.
            if (!self::libraryHeader($source)) {
                $missing[] = $file->getPathname();
            }
        }
        $this->assertSame([], $missing, 'Library sources without the SPDX header');
    }
}
