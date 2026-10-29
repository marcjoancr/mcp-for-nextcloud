<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;
use OCP\Server;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
final class BackupBoundaryTest extends IntegrationTestCase {
    public function testNestedNamesAndSharedBackupAliasesHaveDistinctVisibility(): void {
        $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-reader');
        $nested = $root->newFolder('l3-ordinary')->newFolder('MCP backups')->newFile('ordinary.txt', 'ordinary nested name');
        $mcp = new McpClient(LoginFactory::basic('l2-reader'));
        $read = $mcp->tool('files_read', ['path' => '/l3-ordinary/MCP backups/ordinary.txt']);
        self::assertTrue($read->ok(), $read->describe()); self::assertStringContainsString('ordinary nested name', $read->text());
        $owner = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner');
        $folder = $owner->nodeExists('MCP backups') ? $owner->get('MCP backups') : $owner->newFolder('MCP backups');
        $private = json_decode(file_get_contents('/tmp/l3-private.json'), true, 512, JSON_THROW_ON_ERROR);
        Server::get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class)->setHiddenTagIds([$private['excludedTag']]);
        $mirrors = $folder->nodeExists('L2') ? $folder->get('L2') : $folder->newFolder('L2');
        $mirror = $mirrors->newFile('excluded.txt.20261029-120000.bak', 'hidden backup sentinel');
        $shares = Server::get(\OCP\Share\IManager::class);
        $share = $shares->createShare($shares->newShare()->setNode($mirror)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith('l2-reader')->setSharedBy('l2-owner')->setPermissions(\OCP\Constants::PERMISSION_READ));
        $refused = $mcp->tool('files_read', ['path' => '/' . basename($share->getTarget())]);
        self::assertTrue($refused->isError(), $refused->describe());
        self::assertSame('Resource not found in Nextcloud.', $refused->text());
        self::assertSame('hidden backup sentinel', $mirror->getContent());
        self::assertSame('ordinary nested name', $nested->getContent());
    }
}
