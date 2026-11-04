<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Server;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/** Native metadata and system tags through the authenticated MCP endpoint. */
final class FilesMetadataTagsTest extends ExclusionTestCase {
    private const TOOLS = ['files_get_image_description', 'files_set_image_description', 'files_list_tags', 'files_create_tag', 'files_set_tags'];

    public function testNativeStorageConfirmationAndUserIsolationWorkTogether(): void {
        $snapshot = ToolPermissionFixture::capture([self::ALICE, self::BOB], self::TOOLS);
        $createdTag = null;
        try {
            foreach ([self::ALICE, self::BOB] as $uid) ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
            $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF6kAAAAASUVORK5CYII=', true);
            self::assertIsString($bytes);
            $file = $this->makeFile('home', 'image.png', $bytes);
            $path = $this->path('home', 'image.png');
            $alice = new McpClient(LoginFactory::basic(self::ALICE)); $bob = new McpClient(LoginFactory::basic(self::BOB));
            $before = $this->data($alice->tool('files_get_image_description', ['path' => $path]));
            self::assertFalse($before['stored']);
            $args = ['path' => $path, 'description' => 'Tacos in the interior'];
            $plan = $this->data($alice->tool('files_set_image_description', $args));
            self::assertTrue($plan['requiresConfirmation']);
            self::assertSame('', $this->data($alice->tool('files_get_image_description', ['path' => $path]))['description']);
            self::assertTrue($bob->tool('files_set_image_description', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']])->isError());
            $saved = $this->data($alice->tool('files_set_image_description', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]));
            self::assertSame($args['description'], $saved['description']);
            self::assertSame('files_metadata', $saved['source']);
            $native = Server::get(IFilesMetadataManager::class)->getMetadata((int)$file->getId(), false);
            self::assertSame($args['description'], $native->getString(DescriptionService::KEY));
            self::assertSame($bytes, $file->getContent(), 'metadata edits must preserve image bytes');
            self::assertTrue($bob->tool('files_get_image_description', ['path' => $path])->isError());

            $name = 'Tacos-' . $this->run;
            $tagPlan = $this->data($alice->tool('files_create_tag', ['name' => $name]));
            $tag = $this->data($alice->tool('files_create_tag', ['name' => $name, 'confirm' => true, 'plan_state' => $tagPlan['plan_state']]));
            $createdTag = $tag['tag']['id'];
            $tagArgs = ['paths' => [$path], 'add' => [$name], 'remove' => []];
            $assign = $this->data($alice->tool('files_set_tags', $tagArgs));
            self::assertTrue($bob->tool('files_set_tags', $tagArgs + ['confirm' => true, 'plan_state' => $assign['plan_state']])->isError());
            $this->data($alice->tool('files_set_tags', $tagArgs + ['confirm' => true, 'plan_state' => $assign['plan_state']]));
            $nativeIds = Server::get(ISystemTagObjectMapper::class)->getTagIdsForObjects([(string)$file->getId()], 'files');
            self::assertContains((string)$createdTag, array_map('strval', $nativeIds[(string)$file->getId()]));
            self::assertSame($name, $this->data($alice->tool('files_list_tags', ['path' => $path]))['current'][0]['name']);
            self::assertTrue($bob->tool('files_list_tags', ['path' => $path])->isError());
            $this->tag($file); $this->exclude();
            self::assertTrue($alice->tool('files_get_image_description', ['path' => $path])->isError());
            self::assertTrue($alice->tool('files_list_tags', ['path' => $path])->isError());
        } finally {
            if ($createdTag !== null) Server::get(ISystemTagManager::class)->deleteTags([$createdTag]);
            ToolPermissionFixture::restore($snapshot);
        }
    }

    private function data(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured() ?: $reply->json();
        self::assertIsArray($data, $reply->describe());
        return $data;
    }
}
