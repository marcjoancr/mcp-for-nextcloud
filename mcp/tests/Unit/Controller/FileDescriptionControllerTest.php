<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\BackupPolicy\BackupSettingsDocument;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Controller\FileDescriptionController;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Modules\Files\Descriptions\EmbeddedDescriptionReader;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\ForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class FileDescriptionControllerTest extends TestCase {
    public function testReadGrantIsCheckedForTheAuthenticatedUserBeforeResolvingAnyPath(): void {
        $controller = $this->controller('alice', 'files_get_image_description', false);

        $response = $controller->show('/Photos/private.jpg');

        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'This action is not available.'], $response->getData());
    }

    public function testEditGrantIsCheckedForTheAuthenticatedUserBeforeResolvingAnyPath(): void {
        $controller = $this->controller('bob', 'files_set_image_description', false);

        $response = $controller->update('/Photos/private.jpg', 'caption', 'state');

        self::assertSame(403, $response->getStatus());
        self::assertSame(['error' => 'This action is not available.'], $response->getData());
    }

    public function testReadUsesEffectiveUserGrantWithoutAnMcpConnectionSelection(): void {
        $controller = $this->controllerWithNativeGrantAndBlockedScope('alice', 'files_get_image_description');

        $response = $controller->show('/Photos/private.jpg');

        self::assertSame(404, $response->getStatus());
        self::assertSame(['error' => 'This file is unavailable.'], $response->getData());
    }

    public function testWriteUsesEffectiveUserGrantWithoutAnMcpConnectionSelection(): void {
        $controller = $this->controllerWithNativeGrantAndBlockedScope('alice', 'files_set_image_description');

        $response = $controller->update('/Photos/private.jpg', 'caption', 'state');

        self::assertSame(404, $response->getStatus());
        self::assertSame(['error' => 'This file is unavailable.'], $response->getData());
    }

    public function testMissingFileReturnsGenericNotFoundWithoutLeakingPath(): void {
        $controller = $this->controllerWithResolutionFailure('alice', 'files_get_image_description', new NotFoundException('/Photos/private.jpg'));

        $response = $controller->show('/Photos/private.jpg');

        self::assertSame(404, $response->getStatus());
        self::assertSame(['error' => 'This file is unavailable.'], $response->getData());
        self::assertStringNotContainsString('/Photos/private.jpg', json_encode($response->getData(), JSON_THROW_ON_ERROR));
    }

    public function testForbiddenWriteReturnsSanitizedFailureWithoutLeakingPath(): void {
        $controller = $this->controllerWithResolutionFailure('alice', 'files_set_image_description', new ForbiddenException('Foreign path: /Photos/private.jpg', false));

        $response = $controller->update('/Photos/private.jpg', 'caption', 'state');

        self::assertSame(409, $response->getStatus());
        self::assertSame(['error' => 'No access to this resource in Nextcloud.'], $response->getData());
        self::assertStringNotContainsString('/Photos/private.jpg', json_encode($response->getData(), JSON_THROW_ON_ERROR));
    }

    private function controller(string $uid, string $toolName, bool $grant): FileDescriptionController {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->expects(self::once())->method('within')->with($uid, self::anything())->willReturnCallback(static fn (string $_uid, callable $work) => $work());
        $tools = $this->createMock(ToolRegistry::class);
        $tools->expects(self::once())->method('allowsToolAdministratively')->with($uid, $toolName)->willReturn($grant);
        $tools->expects(self::never())->method('allowsTool');
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->expects(self::never())->method('assertScopeReadable');
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::never())->method('getUserFolder');
        $document = new class extends BackupSettingsDocument {
            public function __construct() {}
            public function read(): ?string { return null; }
        };
        $descriptions = new DescriptionService(
            $rootFolder,
            static fn () => throw new \RuntimeException('The denied action must not resolve metadata.'),
            new BackupLocation($document),
            new EmbeddedDescriptionReader(),
            $this->createMock(NodeAccessInfo::class),
            $this->createMock(SharedWriteGuard::class),
            new \OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter($this->createMock(\OCP\IDBConnection::class)),
        );
        return new FileDescriptionController('mcp', $this->createMock(IRequest::class), $session, $descriptions, $policy, $tools, $visibility);
    }

    private function controllerWithResolutionFailure(string $uid, string $toolName, \Throwable $failure): FileDescriptionController {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('within')->willReturnCallback(static fn (string $_uid, callable $work) => $work());
        $tools = $this->createMock(ToolRegistry::class);
        $tools->method('allowsToolAdministratively')->willReturn(true);
        $tools->expects(self::never())->method('allowsTool');
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->expects(self::once())->method('assertScopeReadable');
        $folder = $this->createMock(Folder::class);
        $folder->expects(self::once())->method('get')->with('Photos/private.jpg')->willThrowException($failure);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::once())->method('getUserFolder')->with($uid)->willReturn($folder);
        $document = new class extends BackupSettingsDocument {
            public function __construct() {}
            public function read(): ?string { return null; }
        };
        $descriptions = new DescriptionService(
            $rootFolder,
            static fn () => throw new \RuntimeException('Metadata should not be resolved after a node lookup failure.'),
            new BackupLocation($document),
            new EmbeddedDescriptionReader(),
            $this->createMock(NodeAccessInfo::class),
            $this->createMock(SharedWriteGuard::class),
            new \OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter($this->createMock(\OCP\IDBConnection::class)),
        );
        return new FileDescriptionController('mcp', $this->createMock(IRequest::class), $session, $descriptions, $policy, $tools, $visibility);
    }

    private function controllerWithNativeGrantAndBlockedScope(string $uid, string $toolName): FileDescriptionController {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('within')->willReturnCallback(static fn (string $_uid, callable $work) => $work());
        $tools = $this->createMock(ToolRegistry::class);
        $tools->expects(self::once())->method('allowsToolAdministratively')->with($uid, $toolName)->willReturn(true);
        $tools->expects(self::never())->method('allowsTool');
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->expects(self::once())->method('assertScopeReadable')->willThrowException(new \OCA\Mcp\Access\Policy\PolicyUnavailable());
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::never())->method('getUserFolder');
        $document = new class extends BackupSettingsDocument {
            public function __construct() {}
            public function read(): ?string { return null; }
        };
        $descriptions = new DescriptionService(
            $rootFolder,
            static fn () => throw new \RuntimeException('Metadata must not resolve after the scope check fails.'),
            new BackupLocation($document),
            new EmbeddedDescriptionReader(),
            $this->createMock(NodeAccessInfo::class),
            $this->createMock(SharedWriteGuard::class),
            new \OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter($this->createMock(\OCP\IDBConnection::class)),
        );
        return new FileDescriptionController('mcp', $this->createMock(IRequest::class), $session, $descriptions, $policy, $tools, $visibility);
    }
}
