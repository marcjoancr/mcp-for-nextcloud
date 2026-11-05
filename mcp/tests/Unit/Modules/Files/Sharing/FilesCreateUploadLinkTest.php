<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Sharing;

use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Modules\Files\Tools\CreateUploadLink;
use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Tests\Support\Files\Sharing\FakeShares;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Constants;
use OCP\Share\IShare;

final class FilesCreateUploadLinkTest extends FilesToolsTestCase {
    private const PASSWORD = 'Upload-only-#Pass-839';
    private const FOLDER = '/Projetos';

    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files' . self::FOLDER);
    }

    private function grantUploadLink(): void {
        InMemoryConfig::storeToolPolicy($this->configMock, ToolGrantsMapper::USER, 'alice', 'files_create_upload_link', ToolGrantsMapper::ALLOW);
        $this->sharePolicy->setGrant('alice', 'files', 'upload_link', true);
        $state = $this->sharePolicy->withinTool('alice', 'files_create_upload_link', 'files', 'upload_link', fn () => $this->sharePolicy->toolState('alice', 'files_create_upload_link', 'files', 'upload_link'));
        self::assertSame(true, $state['effective'], var_export($state, true));
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function inTool(callable $operation): array {
        return $this->sharePolicy->withinTool('alice', 'files_create_upload_link', 'files', 'upload_link', $operation);
    }

    public function testDefinitionHasAnIndependentGrantAndSafeModes(): void {
        $definition = CreateUploadLink::definition()->toArray();
        self::assertSame(['files', 'upload_link'], [$definition['module'], $definition['operation']]);
        self::assertSame(['preserve', 'set'], $definition['inputSchema']['properties']['password_mode']['enum']);
        self::assertSame(['preserve', 'set', 'remove'], $definition['inputSchema']['properties']['expiry_mode']['enum']);
    }

    public function testPreviewAndExecutionCreateAProtectedCreateOnlyFolderLink(): void {
        $this->grantUploadLink();
        $this->shares->settings['shareApiLinkAllowPublicUpload'] = true;
        $this->linkPasswords = [self::PASSWORD];
        $folder = $this->tree->rootFolder();
        $arguments = ['path' => self::FOLDER];

        $plan = $this->inTool(fn () => $this->shareWriter->planUploadLink($folder, 'alice', $this->writerArguments($arguments)));
        self::assertTrue($plan['uploadOnly']);
        self::assertSame('upload', $plan['after']['permission']);
        self::assertSame(['before' => null, 'after' => 'new', 'required' => true], $plan['password']);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->shares->writes);

        $out = $this->inTool(fn () => $this->shareWriter->applyUploadLink($folder, 'alice', $this->writerArguments($arguments + [PlanState::ARGUMENT => $plan[PlanState::ARGUMENT]])));
        [, $share] = $this->shares->writes[0];
        self::assertSame(Constants::PERMISSION_CREATE, $share->getPermissions());
        self::assertTrue(ShareAccess::isUploadOnlyLink($share));
        self::assertSame(FakeShares::hashOf(self::PASSWORD), $share->getPassword());
        self::assertSame('upload', $out['permission']);
        self::assertSame(self::PASSWORD, $out['password']);
        self::assertSame('ocinternal:' . $share->getId(), $out['shareId']);
    }

    public function testUploadLinkRequiresCreatePermissionOnTheReceivedFolderAtConfirmation(): void {
        $this->grantUploadLink();
        $this->shares->settings['shareApiLinkAllowPublicUpload'] = true;
        $this->tree->addFolder('/alice/files/Received', ['scope' => 'shared', 'incomingShare' => $this->createStub(IShare::class)]);
        $root = $this->tree->rootFolder();
        $args = $this->writerArguments(['path' => '/Received']);
        $plan = $this->inTool(fn () => $this->shareWriter->planUploadLink($root, 'alice', $args));
        $args[PlanState::ARGUMENT] = $plan[PlanState::ARGUMENT];
        $this->tree->nodes['/alice/files/Received']['permissions'] = Constants::PERMISSION_READ | Constants::PERMISSION_SHARE;
        self::assertSame(SharingMessages::shareAboveOwnPermissions(), $this->writerFailure(fn () => $this->inTool(
            fn () => $this->shareWriter->applyUploadLink($root, 'alice', $args),
        )));
        self::assertSame([], $this->shares->writes);
        $this->tree->nodes['/alice/files/Received']['permissions'] |= Constants::PERMISSION_CREATE;
        $result = $this->inTool(fn () => $this->shareWriter->applyUploadLink($root, 'alice', $args));
        self::assertSame('upload', $result['permission']);
        self::assertTrue($result['hasPassword']);
    }

    public function testTheNormalLinkGrantDoesNotAuthorizeAnUploadLink(): void {
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
        self::assertSame(SharingMessages::shareNotGranted(), $this->writerFailure(fn () => $this->inTool(
            fn () => $this->shareWriter->planUploadLink($this->tree->rootFolder(), 'alice', $this->writerArguments(['path' => self::FOLDER])),
        )));
        self::assertSame([], $this->shares->writes);
    }

    public function testNativeUploadPolicyAndFolderBoundaryAreRechecked(): void {
        $this->grantUploadLink();
        self::assertSame(SharingMessages::uploadLinksDisabled(), $this->writerFailure(fn () => $this->inTool(
            fn () => $this->shareWriter->planUploadLink($this->tree->rootFolder(), 'alice', $this->writerArguments(['path' => self::FOLDER])),
        )));
        $this->shares->settings['shareApiLinkAllowPublicUpload'] = true;
        self::assertSame(SharingMessages::uploadLinkFolderOnly(), $this->writerFailure(fn () => $this->inTool(
            fn () => $this->shareWriter->planUploadLink($this->tree->rootFolder(), 'alice', $this->writerArguments(['path' => '/Documentos/ata.md'])),
        )));
    }

    public function testUploadModeRefusesToConvertAnExistingReadLink(): void {
        $this->grantUploadLink();
        $this->shares->settings['shareApiLinkAllowPublicUpload'] = true;
        $this->shares->add(['type' => IShare::TYPE_LINK, 'with' => null, 'node' => $this->tree->nodes['/alice/files' . self::FOLDER]['id'],
            'nodeType' => 'folder', 'nodeObject' => $this->tree->node('/alice/files' . self::FOLDER), 'permissions' => Constants::PERMISSION_READ]);
        self::assertSame(SharingMessages::linkModeConflict(), $this->writerFailure(fn () => $this->inTool(
            fn () => $this->shareWriter->planUploadLink($this->tree->rootFolder(), 'alice', $this->writerArguments(['path' => self::FOLDER])),
        )));
        self::assertSame([], $this->shares->writes);
    }
    private function writerFailure(callable $call): string {
        try {
            $call();
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            return $e->getMessage();
        }
        self::fail('no ToolFailure');
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function writerArguments(array $arguments): array {
        return $arguments + ['with' => 'link', 'permission' => 'view', 'password_mode' => 'preserve'];
    }
}
