<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\FileWritePolicy\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native Notes endpoints, including the fork's partial-edit confirmation. */
#[Group('notes')]
final class NotesFileWritePolicyTest extends ExclusionTestCase {
    protected function setUp(): void {
        parent::setUp();
        $config = Server::get(IAppConfig::class);
        $before = $config->hasKey('mcp', FileWritePolicy::KEY, null) ? $config->getValueString('mcp', FileWritePolicy::KEY, '', false) : null;
        $this->later(static function () use ($config, $before): void {
            $before === null ? $config->deleteKey('mcp', FileWritePolicy::KEY) : $config->setValueString('mcp', FileWritePolicy::KEY, $before, false);
            $config->clearCache(false);
        });
        $this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
        Server::get(FileWritePolicy::class)->save('off', [], false);
    }
    public function testResolvedNoteNamesAndPartialConfirmationFollowChangedPolicy(): void {
        $folder = $this->ensureFolder(self::ALICE, 'Notes/' . $this->run);
        $note = $folder->newFile('Original.md', 'original');
        $mcp = $this->mcp(self::ALICE, 'bearer');
        $args = ['id' => (int)$note->getId(), 'append' => 'new', 'title' => 'Renamed'];
        $plan = $mcp->tool('notes_edit', $args);
        self::assertTrue($plan->ok(), $plan->describe());
        $approval = array_intersect_key($plan->structured(), array_flip(['etag', 'plan_state']));
        self::assertCount(2, $approval);
        Server::get(FileWritePolicy::class)->save('allowlist', ['txt'], false);
        foreach (['notes_create' => ['title' => 'Created', 'category' => $this->run . '/new', 'content' => 'new'],
            'notes_edit' => ['id' => (int)$note->getId(), 'content' => 'new', 'title' => 'Renamed'],
            'notes_move' => ['id' => (int)$note->getId(), 'category' => $this->run . '/new']] as $tool => $arguments) {
            foreach ([false, true] as $confirmed) {
                $reply = $confirmed ? $mcp->write($tool, $arguments) : $mcp->tool($tool, $arguments);
                self::assertTrue($reply->isError(), $reply->describe());
            }
        }
        $partial = $mcp->write('notes_edit', $args + $approval);
        self::assertTrue($partial->isError(), $partial->describe());
        self::assertSame('original', $note->getContent()); self::assertFalse($folder->nodeExists('new'));
        Server::get(FileWritePolicy::class)->save('allowlist', ['md'], false);
        $allowed = $mcp->write('notes_edit', $args + $approval);
        self::assertTrue($allowed->ok(), $allowed->describe());
        self::assertSame("original\nnew", $folder->get('Renamed.md')->getContent());
    }
}
