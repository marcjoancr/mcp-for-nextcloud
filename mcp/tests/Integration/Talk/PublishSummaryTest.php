<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Talk;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCA\Talk\Events\AAttendeeRemovedEvent;
use OCA\Talk\Manager;
use OCA\Talk\Service\ParticipantService;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Summary publication requires its own grant and a current, caller-bound preview of the native Talk room. */
#[Group('talk')]
final class PublishSummaryTest extends ExclusionTestCase {
	/** @var array<string, array<string, string>> */
	private array $policySnapshot = [];
	private array $toolPermissionSnapshot = [];

	protected function setUp(): void {
		parent::setUp();
		$this->snapshotPolicy(Fixtures::ALICE, ['grant_talk_read', 'grant_talk_reply', 'grant_talk_publish_summary']);
		$this->snapshotPolicy(Fixtures::BOB, ['grant_talk_read', 'grant_talk_reply', 'grant_talk_publish_summary']);
		$this->toolPermissionSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture([self::ALICE, self::BOB], ['talk_list_conversations', 'talk_read_messages', 'talk_create_group', 'talk_reply', 'talk_publish_summary']);
	}

	protected function tearDown(): void {
		try {
			if ($this->toolPermissionSnapshot !== []) { \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($this->toolPermissionSnapshot); }
			$config = Server::get(IConfig::class);
			foreach ($this->policySnapshot as $uid => $values) {
				foreach ($values as $key => $value) {
					if ($value === '__mcp_it_missing__') {
						$config->deleteUserValue($uid, 'mcp', $key);
					} else {
						$config->setUserValue($uid, 'mcp', $key, $value);
					}
				}
			}
		} finally {
			parent::tearDown();
		}
	}

	public function testSummaryRequiresSeparateGrantAndPreviewConfirmationIsBoundToCallerTextAndRoomState(): void {
		$policy = Server::get(GrantPolicy::class);
		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::ALICE, [
			'talk_list_conversations' => 'allow', 'talk_read_messages' => 'allow', 'talk_create_group' => 'allow',
			'talk_reply' => 'allow', 'talk_publish_summary' => 'allow',
		]);
		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::BOB, [
			'talk_list_conversations' => 'allow', 'talk_read_messages' => 'allow', 'talk_reply' => 'allow', 'talk_publish_summary' => 'allow',
		]);
		$alice = new McpClient(LoginFactory::basic(self::ALICE));
		$bob = new McpClient(LoginFactory::basic(self::BOB));
		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::ALICE, ['talk_publish_summary' => 'deny']);

		$room = $this->payload($alice->write('talk_create_group', [
			'name' => 'mcp summary ' . $this->run,
			'participants' => [self::BOB],
		]));
		$token = (string)$room['conversation_token'];
		$secondRoom = $this->payload($alice->write('talk_create_group', [
			'name' => 'mcp summary alternate ' . $this->run,
			'participants' => [self::BOB],
		]));
		$secondToken = (string)$secondRoom['conversation_token'];
		$secondInitialMessages = array_column($this->messages($alice, $secondToken), 'text');

		$denied = $alice->tool('talk_publish_summary', ['conversation_token' => $token, 'message' => 'Approved summary']);
		self::assertSame(-32602, $denied->body['error']['code'] ?? null,
			'talk.reply does not implicitly grant talk.publish_summary');
		self::assertSame('Unknown tool', $denied->body['error']['message'] ?? null);

		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::ALICE, ['talk_publish_summary' => 'allow', 'talk_reply' => 'deny']);
		$initialMessages = array_column($this->messages($alice, $token), 'text');
		$preview = $this->payload($alice->tool('talk_publish_summary', ['conversation_token' => $token, 'message' => " Approved summary \n"]));
		self::assertSame('Approved summary', $preview['draft']['message'] ?? null, 'preview returns the exact normalized text');
		self::assertSame('mcp summary ' . $this->run, $preview['conversation']['displayName'] ?? null);
		self::assertNotSame('', $preview['plan_state'] ?? '', 'preview returns a confirmation state token');
		self::assertSame($initialMessages, array_column($this->messages($alice, $token), 'text'), 'preview does not post a message');

		$changedText = $alice->tool('talk_publish_summary', [
			'conversation_token' => $token, 'message' => 'Changed summary', 'confirm' => true, 'plan_state' => $preview['plan_state'],
		]);
		self::assertTrue($changedText->ok(), $changedText->describe());
		self::assertSame('Changed summary', ($changedText->structured() ?: $changedText->json())['draft']['message'] ?? null,
			'changed text produces a fresh preview');
		self::assertSame($initialMessages, array_column($this->messages($alice, $token), 'text'), 'changed text does not send');

		$changedRoom = $alice->tool('talk_publish_summary', [
			'conversation_token' => $secondToken, 'message' => $preview['draft']['message'], 'confirm' => true, 'plan_state' => $preview['plan_state'],
		]);
		self::assertTrue($changedRoom->ok(), $changedRoom->describe());
		self::assertSame('mcp summary alternate ' . $this->run, ($changedRoom->structured() ?: $changedRoom->json())['conversation']['displayName'] ?? null,
			'changed destination returns a fresh preview for that room');
		self::assertSame($initialMessages, array_column($this->messages($alice, $token), 'text'), 'changed destination does not send to the original room');
		self::assertSame($secondInitialMessages, array_column($this->messages($alice, $secondToken), 'text'),
			'changed destination does not send to the new room');

		$otherActor = $bob->tool('talk_publish_summary', [
			'conversation_token' => $token, 'message' => $preview['draft']['message'], 'confirm' => true, 'plan_state' => $preview['plan_state'],
		]);
		self::assertTrue($otherActor->ok(), $otherActor->describe());
		$bobPreview = $otherActor->structured() ?: $otherActor->json();
		self::assertNotSame($preview['plan_state'], $bobPreview['plan_state'] ?? null,
			'a different actor receives a new caller-bound preview');
		self::assertSame($initialMessages, array_column($this->messages($alice, $token), 'text'), 'a different actor does not send');

		$confirmed = $alice->tool('talk_publish_summary', [
			'conversation_token' => $token, 'message' => $preview['draft']['message'], 'confirm' => true, 'plan_state' => $preview['plan_state'],
		]);
		self::assertTrue($confirmed->ok(), $confirmed->describe());
		self::assertSame([...$initialMessages, 'Approved summary'], array_column($this->messages($alice, $token), 'text'),
			'the exact previewed summary is posted once through native Talk');

		$staleToken = $this->payload($bob->tool('talk_publish_summary', ['conversation_token' => $token, 'message' => 'Member-approved summary']));
		$beforeActivity = array_column($this->messages($alice, $token), 'text');
		self::assertSame([...$initialMessages, 'Approved summary'], $beforeActivity, 'a member preview also makes no write');
		$activity = 'intervening Talk activity';
		$bob->write('talk_reply', ['conversation_token' => $token, 'message' => $activity]);
		$stale = $bob->tool('talk_publish_summary', [
			'conversation_token' => $token, 'message' => $staleToken['draft']['message'], 'confirm' => true, 'plan_state' => $staleToken['plan_state'],
		]);
		self::assertTrue($stale->ok(), $stale->describe());
		$refreshed = $stale->structured() ?: $stale->json();
		self::assertNotSame($staleToken['plan_state'], $refreshed['plan_state'] ?? null,
			'new Talk activity invalidates an earlier summary preview');
		$beforeRemoval = array_column($this->messages($alice, $token), 'text');
		self::assertSame([...$beforeActivity, $activity], $beforeRemoval, 'stale confirmation does not send the summary');
		$this->removeTalkParticipant($token, self::BOB);
		$afterRemoval = array_column($this->messages($alice, $token), 'text');
		self::assertCount(count($beforeRemoval) + 1, $afterRemoval, 'native Talk records attendee removal as a system event');
		self::assertSame(1, count(array_filter($afterRemoval, static fn (mixed $text): bool => $text === 'Approved summary')));
		$removed = $bob->tool('talk_publish_summary', [
			'conversation_token' => $token, 'message' => $refreshed['draft']['message'], 'confirm' => true, 'plan_state' => $refreshed['plan_state'],
		]);
		self::assertTrue($removed->isError(), 'a removed participant cannot confirm a prior preview');
		self::assertSame($afterRemoval, array_column($this->messages($alice, $token), 'text'),
			'removed participant confirmation does not send a second message');
	}

	/** @return array<string, mixed> */
	private function payload(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		$data = $reply->structured();
		return $data !== [] ? $data : $reply->json();
	}

	/** @return list<array<string, mixed>> */
	private function messages(McpClient $client, string $token): array {
		$data = $this->payload($client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]));
		return $data['messages'] ?? [];
	}

	private function snapshotPolicy(string $uid, array $keys): void {
		$config = Server::get(IConfig::class);
		foreach ($keys as $key) {
			$this->policySnapshot[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__');
		}
	}

	private function removeTalkParticipant(string $token, string $uid): void {
		$room = Server::get(Manager::class)->getRoomForUserByToken($token, self::ALICE);
		$participants = Server::get(ParticipantService::class);
		$participant = $participants->getParticipant($room, $uid, false);
		$participants->removeAttendee($room, $participant, AAttendeeRemovedEvent::REASON_REMOVED);
	}
}
