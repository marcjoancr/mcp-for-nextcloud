<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Files\Creation;

use OCA\Mcp\Access\FileWritePolicy\FileTypeDenied;
use OCA\Mcp\Access\FileWritePolicy\FileWritePolicyUnavailable;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileWritePolicyBoundariesTest extends FilesToolsTestCase {
    protected function setUp(): void { parent::setUp(); $this->fileTypeGuard(); }

    public static function writes(): iterable {
        foreach (['files_create' => ['path' => '/Documentos/new.md', 'content' => 'new'],
            'files_upload' => ['path' => '/Documentos/new.bin'],
            'files_checkout' => ['path' => '/Documentos/ata.md'],
            'files_edit' => ['path' => '/Documentos/ata.md', 'content' => 'new'],
            'files_replace' => ['path' => '/Documentos/ata.md', 'old' => 'one', 'new' => 'new'],
            'files_version_restore' => ['path' => '/Documentos/ata.md', 'version' => '1'],
            'files_copy' => ['from' => '/Documentos/ata.md', 'to' => '/Documentos/copied.php'],
            'files_move' => ['from' => '/Documentos/ata.md', 'to' => '/Documentos/moved.php']] as $tool => $args) {
            foreach ([false, true] as $execute) yield $tool . ($execute ? '-execute' : '-preview') => [$tool, $args, $execute];
        }
    }
    #[DataProvider('writes')]
    public function testFinalNamesRefuseBeforeAnyEffects(string $tool, array $args, bool $execute): void {
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => ['txt'], 'allowExtensionless' => false]);
        $this->tree->nodes['/alice/files/Documentos/ata.md']['content'] = 'one';
        try {
            $execute ? $this->json($tool, $args) : $this->plan($tool, $args);
            self::fail('Filename policy was bypassed');
        } catch (FileTypeDenied) {
            self::assertSame([], $this->tree->ops);
            self::assertSame([], $this->store->rows);
        }
    }
    public function testMalformedPresentPolicyNeverWritesAsOff(): void {
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = '';
        $this->expectException(FileWritePolicyUnavailable::class);
        $this->json('files_create', ['path' => '/Documentos/new.md', 'content' => 'new']);
    }
    public function testConfirmationRechecksPolicyAfterAllowedPreview(): void {
        $args = ['path' => '/Documentos/new.md', 'content' => 'new'];
        $this->plan('files_create', $args);
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => [], 'allowExtensionless' => false]);
        $this->expectException(FileTypeDenied::class);
        $this->json('files_create', $args);
    }
}
