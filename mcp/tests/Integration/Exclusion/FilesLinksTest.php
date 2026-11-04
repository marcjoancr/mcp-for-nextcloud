<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IConfig;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

/** Public read and upload links traverse the live MCP registry and Nextcloud share manager. */
final class FilesLinksTest extends ExclusionTestCase {
    private const TOOLS = ['files_share', 'files_unshare', 'files_create_upload_link'];
    private const EXPIRY_PASSWORD = 'Integration-clear-link-#942';

    public function testReadLinkClearModesAndUploadOnlyGrantAgainstNativePolicy(): void {
        $config = Server::get(IConfig::class);
        $keys = [
            'shareapi_default_expire_date' => 'yes',
            'shareapi_enforce_expire_date' => 'no',
            'shareapi_expire_after_n_days' => '7',
            'shareapi_allow_public_upload' => 'yes',
            'shareapi_enforce_links_password' => 'no',
        ];
        $saved = [];
        foreach ($keys as $key => $value) {
            $saved[$key] = in_array($key, $config->getAppKeys('core'), true) ? $config->getAppValue('core', $key) : null;
            $config->setAppValue('core', $key, $value);
        }
        $permissions = ToolPermissionFixture::capture([self::ALICE, self::BOB], self::TOOLS);
        $policies = Server::get(GrantPolicy::class);
        $readLinkId = null;
        $uploadLinkId = null;
        try {
            foreach ([self::ALICE, self::BOB] as $uid) {
                $policies->setGrant($uid, 'files', 'upload_link', true);
                ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
            }
            $this->makeFolder('home', 'links');
            $file = $this->makeFile('home', 'links/read.txt', 'public read data');
            $path = $this->path('home', 'links/read.txt');
            $folderPath = $this->path('home', 'links');
            $alice = new McpClient(LoginFactory::basic(self::ALICE));
            $bob = new McpClient(LoginFactory::basic(self::BOB));

            $readArgs = ['path' => $path, 'with' => 'link', 'password_mode' => 'set', 'expiry_mode' => 'remove'];
            $readPlan = $this->data($alice->tool('files_share', $readArgs));
            self::assertSame(null, $readPlan['after']['expires']);
            $readCreated = $this->data($alice->tool('files_share', $readArgs + ['confirm' => true, 'plan_state' => $readPlan['plan_state']]));
            $readLinkId = $readCreated['shareId'];
            $manager = Server::get(IManager::class);
            $readShare = $manager->getShareById($readLinkId);
            self::assertSame('yes', $config->getAppValue('core', 'shareapi_default_expire_date'));
            self::assertNull($readShare->getExpirationDate(), 'explicit no-expiry must override a non-enforced native default');
            self::assertTrue(ShareAccess::hasPassword($readShare));
            self::assertTrue($bob->tool('files_unshare', ['shareId' => $readLinkId])->isError(), 'a different user cannot remove Alice\'s link');

            $clearArgs = ['path' => $path, 'with' => 'link', 'password_mode' => 'remove', 'expiry_mode' => 'remove'];
            $clearPlan = $this->data($alice->tool('files_share', $clearArgs));
            self::assertSame(['before' => true, 'after' => 'none', 'required' => false], $clearPlan['password']);
            $cleared = $this->data($alice->tool('files_share', $clearArgs + ['confirm' => true, 'plan_state' => $clearPlan['plan_state']]));
            $readShare = $manager->getShareById($readLinkId);
            self::assertNull($readShare->getPassword());
            self::assertNull($readShare->getExpirationDate());
            self::assertFalse($cleared['hasPassword']);
            self::assertArrayNotHasKey('password', $cleared);

            $uploadArgs = ['path' => $folderPath, 'expiry_mode' => 'remove'];
            $uploadPlan = $this->data($alice->tool('files_create_upload_link', $uploadArgs));
            self::assertTrue($uploadPlan['uploadOnly']);
            self::assertTrue($uploadPlan['password']['required']);
            self::assertStringNotContainsString(self::EXPIRY_PASSWORD, json_encode($uploadPlan, JSON_THROW_ON_ERROR));
            $upload = $this->data($alice->tool('files_create_upload_link', $uploadArgs + ['confirm' => true, 'plan_state' => $uploadPlan['plan_state']]));
            $uploadLinkId = $upload['shareId'];
            $uploadShare = $manager->getShareById($uploadLinkId);
            self::assertSame(\OCP\Constants::PERMISSION_CREATE, $uploadShare->getPermissions());
            self::assertTrue(ShareAccess::isUploadOnlyLink($uploadShare));
            self::assertNotEmpty($uploadShare->getPassword());
            self::assertNull($uploadShare->getExpirationDate());
            self::assertSame('upload', $upload['permission']);
            self::assertArrayHasKey('password', $upload);
            self::assertTrue($bob->tool('files_unshare', ['shareId' => $uploadLinkId])->isError());

            $policies->setGrant(self::ALICE, 'files', 'link', false);
            self::assertTrue($alice->tool('files_unshare', ['shareId' => $readLinkId])->isError(), 'upload_link does not revoke ordinary read links');
            $uploadRemoval = $this->data($alice->tool('files_unshare', ['shareId' => $uploadLinkId]));
            $this->data($alice->tool('files_unshare', ['shareId' => $uploadLinkId, 'confirm' => true, 'plan_state' => $uploadRemoval['plan_state']]));
            self::assertNotContains($uploadLinkId, array_map(static fn (IShare $share): string => $share->getFullId(),
                $manager->getSharesBy(self::ALICE, IShare::TYPE_LINK, $this->node(self::ALICE, $folderPath), false, -1, 0)));
            self::assertSame('public read data', $file->getContent());
        } finally {
            $policies->setGrant(self::ALICE, 'files', 'link', true);
            foreach ([$readLinkId, $uploadLinkId] as $id) {
                if (!is_string($id)) continue;
                try { Server::get(IManager::class)->deleteShare(Server::get(IManager::class)->getShareById($id)); } catch (\Throwable) {}
            }
            ToolPermissionFixture::restore($permissions);
            foreach ($saved as $key => $value) {
                if ($value === null) $config->deleteAppValue('core', $key);
                else $config->setAppValue('core', $key, $value);
            }
        }
    }

    /** @return array<string, mixed> */
    private function data(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured() ?: $reply->json();
        self::assertIsArray($data, $reply->describe());
        return $data;
    }
}
