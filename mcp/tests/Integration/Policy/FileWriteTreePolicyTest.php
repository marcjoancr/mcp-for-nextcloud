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

/** Native tree sinks and recorded undo follow current final-name policy. */
final class FileWriteTreePolicyTest extends OAuthAccountTestCase {
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
        foreach (['create', 'move'] as $operation) $policy->setGrant($this->uid, 'files', $operation, true);
        $this->run = 'policy-' . bin2hex(random_bytes(4));
        $this->folder = Server::get(IRootFolder::class)->getUserFolder($this->uid)->newFolder($this->run);
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

    public function testFolderCopyAndMoveRefuseBlockedDescendantsAndAllowedCopyReturnsManifest(): void {
        $source = $this->folder->newFolder('source');
        $source->newFile('allowed.md', 'original');
        $blocked = $source->newFile('blocked.php', 'untrusted');
        $mcp = new McpClient(LoginFactory::basic($this->uid));
        Server::get(FileWritePolicy::class)->save('allowlist', ['md'], false);
        foreach (['files_copy', 'files_move'] as $tool) foreach ([false, true] as $confirmed) {
            $args = ['from' => '/' . $this->run . '/source', 'to' => '/' . $this->run . '/target'];
            $reply = $confirmed ? $mcp->write($tool, $args) : $mcp->tool($tool, $args);
            self::assertTrue($reply->isError(), $reply->describe());
            self::assertFalse($this->folder->nodeExists('target'));
            self::assertSame('untrusted', $blocked->getContent());
        }
        // Native fixture cleanup only: the connector never deletes a blocked file.
        $blocked->delete();
        $reply = $mcp->write('files_copy', ['from' => '/' . $this->run . '/source', 'to' => '/' . $this->run . '/target']);
        self::assertTrue($reply->ok(), $reply->describe());
        self::assertFalse($reply->json()['partial']);
        self::assertSame([], $reply->json()['conflicts']);
        self::assertSame('original', $this->folder->get('target/allowed.md')->getContent());
    }
    public function testUndoKeepsItsReceiptWhenCurrentPolicyBlocksTheRecordedOldName(): void {
        $folder = $this->folder;
        $folder->newFile('old.php', 'original');
        $mcp = new McpClient(LoginFactory::basic($this->uid));
        $moved = $mcp->write('files_move_batch', ['moves' => [['from' => '/' . $this->run . '/old.php', 'to' => '/' . $this->run . '/allowed.md']]]);
        self::assertTrue($moved->ok(), $moved->describe());
        $id = $moved->json()['batch_id'];
        Server::get(FileWritePolicy::class)->save('allowlist', ['md'], false);
        $undo = $mcp->write('files_undo_batch', ['batch_id' => $id]);
        self::assertTrue($undo->ok(), $undo->describe());
        self::assertSame(0, $undo->json()['undone']); self::assertNotEmpty($undo->json()['conflicts']);
        self::assertTrue($folder->nodeExists('allowed.md')); self::assertFalse($folder->nodeExists('old.php'));
        Server::get(FileWritePolicy::class)->save('allowlist', ['md', 'php'], false);
        $retry = $mcp->write('files_undo_batch', ['batch_id' => $id]);
        self::assertTrue($retry->ok(), $retry->describe()); self::assertSame(1, $retry->json()['undone']);
        self::assertSame('original', $folder->get('old.php')->getContent());
    }
}
