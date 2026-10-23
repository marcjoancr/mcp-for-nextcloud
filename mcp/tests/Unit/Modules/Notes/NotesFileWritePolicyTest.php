<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Notes;
use OCA\Mcp\Access\FileWritePolicy\FileTypeDenied;
use OCA\Mcp\Tests\Support\Notes\NotesTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class NotesFileWritePolicyTest extends NotesTestCase {
    public static function mutations(): iterable {
        foreach (['notes_create' => ['title' => 'Created', 'category' => 'New/Category', 'content' => 'new'],
            'notes_edit' => ['content' => 'new', 'title' => 'New'],
            'notes_move' => ['category' => 'New/Category']] as $tool => $args) {
            foreach ([false, true] as $execute) yield $tool . (string)$execute => [$tool, $args, $execute];
        }
    }
    private function deny(): void {
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode(['version' => 1, 'mode' => 'allowlist', 'extensions' => ['txt'], 'allowExtensionless' => false]);
    }
    #[DataProvider('mutations')]
    public function testPlansAndMutationsCheckResolvedMarkdownNames(string $tool, array $args, bool $execute): void {
        $this->deny(); $args += ['id' => $this->id];
        try {
            $execute ? $this->module->call($tool, $args, 'alice') : $this->module->preview($tool, $args, 'alice');
            self::fail('Notes bypassed filename policy');
        } catch (FileTypeDenied) { self::assertSame([], $this->tree->ops); }
    }
    public function testPartialPlanAndConfirmedWriterUseCurrentPolicy(): void {
        $args = ['id' => $this->id, 'append' => 'new', 'title' => 'After'];
        $plan = $this->module->preview('notes_edit', $args, 'alice');
        $this->deny();
        try {
            $this->module->call('notes_edit', $args + ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']], 'alice');
            self::fail('Partial writer bypassed changed policy');
        } catch (FileTypeDenied) {
            self::assertSame([], $this->tree->ops);
            self::assertSame('# Original', $this->tree->nodes['/alice/files/Notes/Work/Before.md']['content']);
        }
        $this->expectException(FileTypeDenied::class);
        $this->module->preview('notes_edit', $args, 'alice');
    }
}
