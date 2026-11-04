<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Creation;

use OCA\Mcp\Modules\Files\Checkout\CheckoutService;
use OCA\Mcp\Modules\Files\Creation\CreationMessages;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Creation\IncomingFileCreation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture;
use OCA\Mcp\Tests\Support\Files\FilesGuards;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Tests\Support\Files\FileTypePolicies;
use OCA\Mcp\Tests\Support\Identity\FakeUsers;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;

final class IncomingFileCreationTest extends FilesToolsTestCase {
    private IncomingFileCreation $incoming;
    private IRootFolder $rootFolder;
    private NodeAccessInfo $access;

    protected function setUp(): void {
        parent::setUp();
        $config = $this->config->mock($this);
        $this->access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $guard = new SharedWriteGuard($this->access);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(fn (string $uid) => $uid === 'alice'
            ? $this->tree->rootFolder()
            : $this->tree->rootFolder());
        $checkout = new CheckoutService(
            $this->createMock(IURLGenerator::class),
            $config,
            $this->time,
            new TokenHasher($config),
            $this->store,
            $this->apps,
            $this->users,
        );
        $creation = new FileCreation(
            BackupDocumentFixture::location($this, $this->config),
            FileTypePolicies::guard($this),
            $this->access,
            $guard,
            $checkout,
            $this->filenames,
            $this->visibilityGuard ?? FilesGuards::unrestricted($this),
            $this->creationLogger,
            $this->locks->service($this),
        );
        $this->incoming = new IncomingFileCreation($this->rootFolder, $creation, $guard, $this->visibilityGuard);
    }

    public function testPreviewCarriesNormalizedTargetAndFolderIdentityWithoutWritingOrMintingLinks(): void {
        $plan = $this->incoming->preview('alice', '/Documentos/received.bin', 6);

        $this->assertSame('/Documentos/received.bin', $plan['path']);
        $this->assertSame('received.bin', $plan['name']);
        $this->assertSame($this->tree->nodes['/alice/files/Documentos']['id'], $plan['destinationFolderId']);
        $this->assertSame(6, $plan['size']);
        $this->assertSame('personal', $plan['access']['scope']);
        $this->assertFalse($plan['requiresSharedConfirmation']);
        $this->assertArrayNotHasKey('uploadUrl', $plan);
        $this->assertArrayNotHasKey('curl', $plan);
        $this->assertSame([], $this->tree->ops);
        $this->assertSame([], $this->store->rows);
    }

    public function testSavePreservesArbitraryBinaryBytes(): void {
        $bytes = "\x00\xff\x81PDF\r\n";
        $receipt = $this->incoming->save('alice', '/Documentos/scan.pdf', $bytes, false);

        $this->assertSame('/Documentos/scan.pdf', $receipt['path']);
        $this->assertSame(strlen($bytes), $receipt['size']);
        $this->assertSame($bytes, $this->tree->nodes['/alice/files/Documentos/scan.pdf']['content']);
        $this->assertSame(['create /alice/files/Documentos/scan.pdf', 'write /alice/files/Documentos/scan.pdf'], $this->tree->ops);
    }

    public function testRequestedUserMustOwnTheResolvedUserFolder(): void {
        try {
            $this->incoming->preview('bruno', '/Documentos/foreign.pdf', 4);
            $this->fail('a root belonging to Alice must not be used for Bruno');
        } catch (ToolFailure $e) {
            $this->assertSame(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::forbidden(), $e->getMessage());
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testPolicyDeniedAndOutOfScopeDestinationsWriteNothing(): void {
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = json_encode([
            'version' => 1, 'mode' => 'allowlist', 'extensions' => ['txt'], 'allowExtensionless' => false,
        ], JSON_THROW_ON_ERROR);
        try {
            $this->incoming->preview('alice', '/Documentos/blocked.pdf', 1);
            $this->fail('the current Files filename policy must be enforced');
        } catch (\OCA\Mcp\Access\FileWritePolicy\FileTypeDenied) {
        }
        $this->assertSame([], $this->tree->ops);

        $this->tree->addFolder('/alice/files/Outside');
        $limited = $this->limitedIncoming([$this->tree->nodes['/alice/files/Outside']['id']]);
        try {
            $limited->preview('alice', '/Documentos/outside.pdf', 1);
            $this->fail('a destination outside the Files resource scope must be hidden');
        } catch (ToolFailure $e) {
            $this->assertStringStartsWith(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound(), $e->getMessage());
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testSaveRechecksTheCurrentUploadSizeLimit(): void {
        $this->config->app['mcp'][CheckoutService::MAX_BYTES_KEY] = '3';

        try {
            $this->incoming->save('alice', '/Documentos/too-large.pdf', 'four', false);
            $this->fail('bytes over the configured Files upload limit must not be written');
        } catch (ToolFailure $e) {
            $this->assertSame(CreationMessages::uploadTooLarge(3), $e->getMessage());
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testUnavailableFilenamePolicyFailsClosedWithoutWriting(): void {
        $this->fileTypeConfig->stored['mcp']['file_write_policy'] = '';

        try {
            $this->incoming->save('alice', '/Documentos/unavailable.pdf', 'x', false);
            $this->fail('an unreadable Files filename policy must deny the write');
        } catch (\OCA\Mcp\Access\FileWritePolicy\FileWritePolicyUnavailable) {
        }
        $this->assertSame([], $this->tree->ops);
    }

    public function testSaveRefusesAnExistingDestinationWithoutChangingItsBytes(): void {
        $before = $this->tree->nodes['/alice/files/Documentos/ata.md']['content'];
        try {
            $this->incoming->save('alice', '/Documentos/ata.md', "replacement\x00", false);
            $this->fail('save must never replace an existing file');
        } catch (\OCA\Mcp\Modules\Files\Creation\FileExists) {
        }
        $this->assertSame($before, $this->tree->nodes['/alice/files/Documentos/ata.md']['content']);
        $this->assertSame([], $this->tree->ops);
    }

    public function testSharedDestinationRequiresConfirmationBeforeWriting(): void {
        $this->tree->mountPath = '/alice/files/Engineering';
        $this->tree->addFolder('/alice/files/Engineering', ['scope' => 'team']);
        $this->tree->addFolder('/alice/files/Engineering/Inbox', ['scope' => 'team']);
        $this->rootFolder->method('getUserFolder')->willReturn($this->tree->rootFolder());
        $sharedIncoming = $this->incomingForRoot($this->rootFolder);

        $plan = $sharedIncoming->preview('alice', '/Engineering/Inbox/mail.bin', 3);
        $this->assertTrue($plan['requiresSharedConfirmation']);
        $this->assertSame('team', $plan['shared'][0]['scope']);

        $confirmation = $sharedIncoming->save('alice', '/Engineering/Inbox/mail.bin', 'abc', false);
        $this->assertTrue($confirmation['requiresConfirmation']);
        $this->assertSame('/Engineering/Inbox/mail.bin', $confirmation['resource']);
        $this->assertSame([], $this->tree->ops);

        $sharedIncoming->save('alice', '/Engineering/Inbox/mail.bin', 'abc', true);
        $this->assertSame('abc', $this->tree->nodes['/alice/files/Engineering/Inbox/mail.bin']['content']);
    }

    /** @param list<string> $folderIds */
    private function limitedIncoming(array $folderIds): IncomingFileCreation {
        $config = $this->config->mock($this);
        $root = $this->visibilityRoot();
        $backup = BackupDocumentFixture::location($this, $this->config);
        $plain = new \OCA\Mcp\Access\Visibility\VisibilityGuard($backup, $config, $this->tagMapper, $root);
        $scope = \OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes::over($this, $plain, $root, static fn (string $uid) => $folderIds);
        $guard = new \OCA\Mcp\Access\ResourceScope\FilesAccessGuard($backup, $scope, $config, $this->tagMapper, $root);
        return $this->incomingForRoot($this->rootFolder, $guard);
    }

    private function incomingForRoot(IRootFolder $rootFolder, ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $guard = null): IncomingFileCreation {
        $config = $this->config->mock($this);
        $access = $this->access;
        $shared = new SharedWriteGuard($access);
        $checkout = new CheckoutService($this->createMock(IURLGenerator::class), $config, $this->time, new TokenHasher($config), $this->store, $this->apps, $this->users);
        $creation = new FileCreation(BackupDocumentFixture::location($this, $this->config), FileTypePolicies::guard($this), $access, $shared, $checkout, $this->filenames, $guard ?? $this->visibilityGuard, $this->creationLogger, $this->locks->service($this));
        return new IncomingFileCreation($rootFolder, $creation, $shared, $guard ?? $this->visibilityGuard);
    }
}
