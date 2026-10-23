<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\FileWritePolicy\FileWritePolicy;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native Notes endpoints, including the fork's partial-edit confirmation. */
#[Group('notes')]
final class NotesFileWritePolicyTest extends OAuthAccountTestCase {
    private Folder $folder;
    private string $run = '';
    /** @var array{policy?:string|null} Original key presence and value, restored after failures too. */
    private array $before = [];

    protected function setUp(): void {
        parent::setUp();
        $config = Server::get(IAppConfig::class);
        $this->before['policy'] = $config->hasKey('mcp', FileWritePolicy::KEY, null) ? $config->getValueString('mcp', FileWritePolicy::KEY, '', false) : null;
        Server::get(FileWritePolicy::class)->save('off', [], false);
        $policy = Server::get(GrantPolicy::class);
        $policy->setConnected($this->uid, true);
        foreach (['create', 'edit', 'move'] as $operation) $policy->setGrant($this->uid, 'notes', $operation, true);
        $this->run = 'policy-' . bin2hex(random_bytes(4));
        $root = Server::get(IRootFolder::class)->getUserFolder($this->uid);
        $notes = $root->nodeExists('Notes') ? $root->get('Notes') : $root->newFolder('Notes');
        self::assertInstanceOf(Folder::class, $notes);
        $this->folder = $notes->newFolder($this->run);
    }
    protected function tearDown(): void {
        try {
            if (array_key_exists('policy', $this->before)) {
                $config = Server::get(IAppConfig::class);
                $before = $this->before['policy'];
                $before === null ? $config->deleteKey('mcp', FileWritePolicy::KEY) : $config->setValueString('mcp', FileWritePolicy::KEY, $before, false);
                $config->clearCache(false);
            }
        } finally {
            // The existing account fixture removes this test's user, files and credentials.
            parent::tearDown();
        }
    }

    public function testResolvedNoteNamesAndPartialConfirmationFollowChangedPolicy(): void {
        $folder = $this->folder;
        $note = $folder->newFile('Original.md', 'original');
        $mcp = new McpClient(LoginFactory::bearer($this->uid));
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
