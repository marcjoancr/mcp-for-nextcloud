<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\BackgroundJob\DescriptionBackfillJob;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use OCP\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Server;

/** Description search follows native Files writes, MCP writes, sharing, and the description-read grant. */
final class FilesDescriptionSearchTest extends ExclusionTestCase {
	private const TOOLS = ['files_search', 'files_get_image_description', 'files_set_image_description'];

	public function testSearchIndexesNativeDescriptionsAndHonorsNameModeSharingAndRevocation(): void {
		$snapshot = ToolPermissionFixture::capture([self::ALICE, self::BOB], self::TOOLS);
		$appConfig = Server::get(IAppConfig::class);
		$backfillCursor = $appConfig->getValueInt('mcp', DescriptionBackfillJob::CURSOR_KEY, 0, true);
		$appConfig->setValueInt('mcp', DescriptionBackfillJob::CURSOR_KEY, 0, true);
		try {
			self::assertTrue(Server::get(IJobList::class)->has(DescriptionBackfillJob::class, null), 'the backfill is registered for existing installations');
			foreach ([self::ALICE, self::BOB] as $uid) {
				ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
			}
			// Select the newly granted tools even when an earlier test initialized the Basic profile.
			$alice = new McpClient(LoginFactory::basic(self::ALICE));
			$bob = new McpClient(LoginFactory::basic(self::BOB));
			$file = $this->makeFile('home', 'opaque-record.txt', 'no description terms in the body');
			$path = $this->path('home', 'opaque-record.txt');
			$phrase = 'cobalt lighthouse archival phrase';
			$this->nativeWrite($path, $phrase);

			self::assertContains($path, $this->searchPaths($alice, 'lighthouse archival'));
			self::assertNotContains($path, $this->searchPaths($alice, 'lighthouse archival', 'name'));

			// An MCP metadata write is searchable immediately, and clearing it removes only that description match.
			$mcpPhrase = 'violet compass private phrase';
			$this->mcpWrite($alice, $path, $mcpPhrase);
			self::assertContains($path, $this->searchPaths($alice, 'violet compass'));
			$this->mcpWrite($alice, $path, '');
			self::assertNotContains($path, $this->searchPaths($alice, 'violet compass'));

			// A file moved into the reserved backup folder remains excluded after its description was indexed.
			$protectedFile = $this->makeFile('home', 'protected-search-record.txt', 'unrelated body');
			$protectedPath = $this->path('home', 'protected-search-record.txt');
			$this->nativeWrite($protectedPath, 'buried backup secret phrase');
			$this->ensureFolder(self::ALICE, 'MCP backups/' . $this->run);
			$this->later(fn () => $this->deleteQuietly(self::ALICE, 'MCP backups/' . $this->run));
			$backupPath = '/MCP backups/' . $this->run . '/protected-search-record.txt';
			$this->dav()->move($protectedPath, $backupPath);
			self::assertTrue($this->exists(self::ALICE, $backupPath));
			self::assertNotContains($backupPath, $this->searchPaths($alice, 'buried backup secret'));

			// Legacy descriptions have no derived search key; the backfill must make their long-tail words searchable.
			$legacy = $this->makeFile('home', 'old-opaque-record.txt', 'unrelated body');
			$legacyPath = $this->path('home', 'old-opaque-record.txt');
			$this->storeLegacyDescription($legacy, str_repeat('historical filler ', 90) . 'quartz observatory terminology');
			$this->runDescriptionBackfill($alice);
			self::assertContains($legacyPath, $this->searchPaths($alice, 'quartz observatory'));
			self::assertNotContains($legacyPath, $this->searchPaths($bob, 'quartz observatory'), 'private descriptions are invisible to other users');

			// A native read share exposes a match to its recipient. The same metadata stays private to outsiders.
			$shared = $this->makeFile('home', 'shared-opaque.txt', 'unrelated body');
			$sharedPath = $this->path('home', 'shared-opaque.txt');
			$this->nativeWrite($sharedPath, 'shared garnet phrase');
			$share = $this->shareToUser($shared, self::ALICE, self::BOB);
			$sharedPaths = $this->searchPaths($bob, 'shared garnet');
			self::assertNotEmpty($sharedPaths);
			self::assertStringEndsWith('/shared-opaque.txt', $sharedPaths[0]);
			Server::get(\OCP\Share\IManager::class)->deleteShare($share);
			self::assertNotContains($sharedPaths[0], $this->searchPaths($bob, 'shared garnet'), 'revoked shares stop contributing search results');

			// Revoking description reads immediately removes description-derived matches without disabling file search.
			ToolPermissionFixture::assign(self::ALICE, ['files_get_image_description' => 'deny']);
			self::assertNotContains($legacyPath, $this->searchPaths($alice, 'quartz observatory'));

			// Tool selection is connector-specific. A client with files_search but without the description reader
			// must retain name search while description-derived matches stay unavailable.
			ToolPermissionFixture::assign(self::ALICE, ['files_get_image_description' => 'allow']);
			$store = Server::get(ConnectionToolsStore::class);
			$profile = $store->get(self::ALICE, ConnectionToolsStore::BASIC_PROFILE);
			self::assertNotNull($profile);
			$selected = array_values(array_filter($profile['tools'], static fn (string $tool): bool => $tool !== 'files_get_image_description'));
			$store->save(self::ALICE, ConnectionToolsStore::BASIC_PROFILE, $selected, $profile['revision'], $profile['variants']);
			self::assertNotContains($legacyPath, $this->searchPaths($alice, 'quartz observatory'), 'a connector without the description reader cannot search description text');
			self::assertContains($legacyPath, $this->searchPaths($alice, 'old-opaque-record'), 'name search remains available on the same connector');
		} finally {
			$appConfig->setValueInt('mcp', DescriptionBackfillJob::CURSOR_KEY, $backfillCursor, true);
			ToolPermissionFixture::restore($snapshot);
		}
	}

	private function nativeWrite(string $path, string $description): void {
		$credential = $this->login(self::ALICE);
		$url = Http::BASE . '/index.php/apps/mcp/api/files/description?path=' . rawurlencode($path);
		$headers = ['Authorization: ' . $credential->authorization(), 'OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/json'];
		$read = Http::request('GET', $url, $headers);
		self::assertSame(200, $read['status'], $read['body']);
		$state = json_decode($read['body'], true, 512, JSON_THROW_ON_ERROR)['state'];
		$write = Http::request('PUT', Http::BASE . '/index.php/apps/mcp/api/files/description', $headers,
			json_encode(['path' => $path, 'description' => $description, 'state' => $state], JSON_THROW_ON_ERROR));
		self::assertSame(200, $write['status'], $write['body']);
	}

	private function mcpWrite(McpClient $client, string $path, string $description): void {
		$args = ['path' => $path, 'description' => $description];
		$plan = $client->tool('files_set_image_description', $args);
		self::assertTrue($plan->ok(), $plan->describe());
		$data = $plan->structured() ?: $plan->json();
		self::assertTrue($data['requiresConfirmation'] ?? false, $plan->describe());
		$saved = $client->tool('files_set_image_description', $args + ['confirm' => true, 'plan_state' => $data['plan_state']]);
		self::assertTrue($saved->ok(), $saved->describe());
	}

	/** @return list<string> */
	private function searchPaths(McpClient $client, string $query, string $mode = 'auto'): array {
		$reply = $client->tool('files_search', ['query' => $query, 'mode' => $mode]);
		self::assertTrue($reply->ok(), $reply->describe());
		$data = $reply->structured() ?: $reply->json();
		return array_column($data['files'] ?? [], 'path');
	}

	private function storeLegacyDescription(File $file, string $description): void {
		$metadata = Server::get(IFilesMetadataManager::class)->getMetadata((int)$file->getId(), true);
		$metadata->setString(DescriptionService::KEY, $description);
		Server::get(IFilesMetadataManager::class)->saveMetadata($metadata);
	}

	private function runDescriptionBackfill(McpClient $alice): void {
		$job = Server::get(DescriptionBackfillJob::class);
		// The disposable cell is small, but run bounded batches until the seeded legacy record is indexed.
		for ($page = 0; $page < 100; $page++) {
			$job->runBatch();
			$reply = $alice->tool('files_search', ['query' => 'quartz observatory']);
			if ($reply->ok() && str_contains($reply->text(), 'old-opaque-record.txt')) {
				return;
			}
		}
	}
}
