<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Talk;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IConfig;
use OCA\Talk\Model\AttendeeMapper;
use PHPUnit\Framework\Attributes\Group;

/** Real Talk history cursors remain room-bound and reading pages never changes participant unread state. */
#[Group('talk')]
final class MessagePaginationTest extends ExclusionTestCase {
    private array $toolPermissionSnapshot = [];

    protected function setUp(): void {
        parent::setUp();
        $this->toolPermissionSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture([self::ALICE, self::BOB, 'mcp-it-legacy'], ['talk_list_conversations', 'talk_read_messages', 'talk_create_group', 'talk_reply']);
    }

    protected function tearDown(): void {
        try {
            if ($this->toolPermissionSnapshot !== []) { \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($this->toolPermissionSnapshot); }
        } finally {
            parent::tearDown();
        }
    }

    /** @param list<string> $keys @return array<string,string> */
    private function snapshotPolicy(string $uid, array $keys): array {
        $config = \OCP\Server::get(IConfig::class);
        $values = [];
        foreach ($keys as $key) { $values[$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__'); }
        return $values;
    }

    /** @param array<string,string> $snapshot */
    private function restorePolicy(string $uid, array $snapshot): void {
        $config = \OCP\Server::get(IConfig::class);
        foreach ($snapshot as $key => $value) {
            if ($value === '__mcp_it_missing__') { $config->deleteUserValue($uid, 'mcp', $key); }
            else { $config->setUserValue($uid, 'mcp', $key, $value); }
        }
    }

    /** @return array<string, mixed> */
    private function payload(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured();
        return $data !== [] ? $data : $reply->json();
    }

    /** @return array{McpClient, McpClient, string, list<string>} */
    private function conversationWithMessages(): array {
        foreach ([self::ALICE, self::BOB] as $uid) {
            \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, [
                'talk_list_conversations' => 'allow', 'talk_read_messages' => 'allow', 'talk_create_group' => 'allow', 'talk_reply' => 'allow',
            ]);
        }
        $alice = new McpClient(LoginFactory::basic(self::ALICE));
        $bob = new McpClient(LoginFactory::basic(self::BOB));
        $room = $this->payload($alice->write('talk_create_group', ['name' => 'mcp history ' . $this->run, 'participants' => [self::BOB]]));
        $token = (string)$room['conversation_token'];
        $texts = [];
        for ($index = 1; $index <= 5; $index++) {
            $texts[] = 'history ' . $index . ' ' . $this->run;
            $this->payload($alice->write('talk_reply', ['conversation_token' => $token, 'message' => end($texts)]));
        }
        return [$alice, $bob, $token, $texts];
    }

    /** @return array{unread:int,lastRead:int} Read Talk's native attendee state without invoking a read-marker API. */
    private function nativeReadState(string $uid, string $token): array {
        $query = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
        $result = $query->select('a.unread_messages', 'a.last_read_message')
            ->from('talk_rooms', 'r')
            ->innerJoin('r', 'talk_attendees', 'a', $query->expr()->eq('a.room_id', 'r.id'))
            ->where($query->expr()->eq('r.token', $query->createNamedParameter($token)))
            ->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter('users')))
            ->andWhere($query->expr()->eq('a.actor_id', $query->createNamedParameter($uid)))
            ->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();
        self::assertIsArray($row, 'the native Talk attendee exists for this room/user');
        return ['unread' => (int)$row['unread_messages'], 'lastRead' => (int)$row['last_read_message']];
    }

    private function setNativeReadState(string $uid, string $token, int $lastRead, int $unread): void {
        $room = \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomForUserByToken($token, $uid);
        $participant = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class)->getParticipant($room, $uid, false);
        $attendee = $participant->getAttendee();
        $attendee->setLastReadMessage($lastRead);
        $attendee->setUnreadMessages($unread);
        \OCP\Server::get(AttendeeMapper::class)->update($attendee);
    }

    /** @return array<string, mixed> */
    private function conversation(McpClient $client, string $token): array {
        $data = $this->payload($client->tool('talk_list_conversations'));
        foreach ($data['conversations'] ?? [] as $row) {
            if (($row['token'] ?? null) === $token) { return $row; }
        }
        self::fail('The conversation is present in the participant listing');
    }

    public function testExclusivePagesStayAnchoredAndDoNotChangeUnreadCounts(): void {
        [$alice, $bob, $token, $texts] = $this->conversationWithMessages();
        $history = $this->payload($alice->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 5]));
        $originalState = $this->nativeReadState(self::BOB, $token);
        $oldestMessageId = (int)$history['messages'][0]['id'];
        // Seed a meaningful native marker/count: this disposable test server does not simulate a live Talk client.
        $this->setNativeReadState(self::BOB, $token, $oldestMessageId, 4);
        $this->later(fn() => $this->setNativeReadState(self::BOB, $token, $originalState['lastRead'], $originalState['unread']));
        $unreadBefore = $this->conversation($bob, $token)['unreadMessages'];
        self::assertGreaterThan(0, $unreadBefore, 'Bob starts with unread messages in the native attendee state');
        $nativeBefore = $this->nativeReadState(self::BOB, $token);
        self::assertGreaterThan(0, $nativeBefore['lastRead'], 'the seeded read marker identifies an actual earlier message');
        self::assertSame($unreadBefore, $nativeBefore['unread']);
        $policy = \OCP\Server::get(GrantPolicy::class);
        $policySnapshot = $this->snapshotPolicy(self::BOB, ['grant_talk_read']);
        \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::BOB, ['talk_read_messages' => 'deny']);
        try {
            $denied = $bob->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 2]);
            self::assertSame(-32602, $denied->body['error']['code'] ?? null, 'the MCP catalog refuses talk_read_messages when talk.read is denied');
            self::assertSame('Unknown tool', $denied->body['error']['message'] ?? null);
            self::assertSame($nativeBefore, $this->nativeReadState(self::BOB, $token), 'a denied request leaves native Talk state unchanged');
        } finally {
            $this->restorePolicy(self::BOB, $policySnapshot);
            \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::BOB, ['talk_read_messages' => 'allow']);
        }

        $first = $this->payload($bob->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 2]));
        self::assertSame(array_slice($texts, 3, 2), array_column($first['messages'], 'text'));
        self::assertCount(2, $first['messages']);
        self::assertNotNull($first['next_before_message_id']);

        // New activity after page one must not shift this conversation's older-page cursor.
        $laterText = 'later activity ' . $this->run;
        $this->payload($alice->write('talk_reply', ['conversation_token' => $token, 'message' => $laterText]));
        $unreadAfterPost = $this->conversation($bob, $token)['unreadMessages'];
        $nativeAfterPost = $this->nativeReadState(self::BOB, $token);
        self::assertSame($unreadAfterPost, $nativeAfterPost['unread']);
        $freshFirstPage = $this->payload($bob->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 1]));
        self::assertSame([$laterText], array_column($freshFirstPage['messages'], 'text'), 'the later reply is present on a fresh first page');
        self::assertSame($nativeAfterPost, $this->nativeReadState(self::BOB, $token), 'a fresh first page does not update native read state');
        $older = $this->payload($bob->tool('talk_read_messages', [
            'conversation_token' => $token,
            'limit' => 2,
            'before_message_id' => (int)$first['next_before_message_id'],
        ]));
        self::assertSame(array_slice($texts, 1, 2), array_column($older['messages'], 'text'));
        self::assertNotContains($laterText, array_column($older['messages'], 'text'));
        self::assertSame($unreadAfterPost, $this->conversation($bob, $token)['unreadMessages'], 'reading an older page leaves Talk unread state unchanged');
        self::assertSame($nativeAfterPost, $this->nativeReadState(self::BOB, $token), 'reading pages leaves unread count and last-read message ID unchanged');

        $pages = [$first, $older];
        $cursor = $older['next_before_message_id'];
        for ($pageNumber = 0; $cursor !== null && $pageNumber < 10; $pageNumber++) {
            $page = $this->payload($bob->tool('talk_read_messages', [
                'conversation_token' => $token,
                'limit' => 2,
                'before_message_id' => (int)$cursor,
            ]));
            $pages[] = $page;
            $cursor = $page['next_before_message_id'];
            self::assertSame($unreadAfterPost, $this->conversation($bob, $token)['unreadMessages']);
            self::assertSame($nativeAfterPost, $this->nativeReadState(self::BOB, $token), 'every page preserves unread count and last-read message ID');
        }
        self::assertNull($cursor, 'pagination reaches the terminal cursor within the bounded history fixture');
        $allIds = [];
        $allUserMessages = [];
        foreach (array_reverse($pages) as $page) {
            foreach ($page['messages'] as $message) {
                $allIds[] = $message['id'];
                if (is_string($message['text'])) { $allUserMessages[] = $message['text']; }
            }
        }
        self::assertCount(count(array_unique($allIds)), $allIds, 'exclusive pages contain no duplicate native message IDs');
        self::assertSame($texts, $allUserMessages, 'the anchored pages include every original user message in order but exclude later activity');

        $alicePage = $this->payload($alice->tool('talk_read_messages', [
            'conversation_token' => $token,
            'limit' => 2,
            'before_message_id' => (int)$first['next_before_message_id'],
        ]));
        self::assertSame(array_slice($texts, 1, 2), array_column($alicePage['messages'], 'text'), 'each participant gets the same room history page');
        self::assertGreaterThanOrEqual(0, $this->conversation($alice, $token)['unreadMessages']);
    }

    public function testPrivateRoomAndForeignRoomCursorDoNotCrossParticipants(): void {
        $legacyUid = 'mcp-it-legacy';
        foreach ([self::ALICE, self::BOB] as $uid) {
            \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, ['talk_list_conversations' => 'allow', 'talk_read_messages' => 'allow', 'talk_create_group' => 'allow', 'talk_reply' => 'allow']);
        }
        $alice = new McpClient(LoginFactory::basic(self::ALICE));
        $bob = new McpClient(LoginFactory::basic(self::BOB));
        $policy = \OCP\Server::get(GrantPolicy::class);
        $legacyPolicySnapshot = $this->snapshotPolicy($legacyUid, [GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_talk_create', 'grant_talk_reply']);
        $this->later(fn() => $this->restorePolicy($legacyUid, $legacyPolicySnapshot));
        $policy->setEligible($legacyUid, true);
        $policy->setConnected($legacyUid, true);
        foreach (['create', 'reply'] as $operation) { $policy->setGrant($legacyUid, 'talk', $operation, true); }
        \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($legacyUid, ['talk_create_group' => 'allow', 'talk_reply' => 'allow', 'talk_read_messages' => 'allow']);
        $legacy = new McpClient(LoginFactory::basic($legacyUid));
        $privateRoom = $this->payload($legacy->write('talk_create_group', ['name' => 'private history ' . $this->run, 'participants' => [self::BOB]]));
        $privateToken = (string)$privateRoom['conversation_token'];
        $this->payload($legacy->write('talk_reply', ['conversation_token' => $privateToken, 'message' => 'private ' . $this->run]));
        $privateRead = $this->payload($legacy->tool('talk_read_messages', ['conversation_token' => $privateToken, 'limit' => 10]));
        self::assertNotEmpty($privateRead['messages']);
        $foreignCursor = (string)$privateRead['messages'][0]['id'];

        $aliceRead = $alice->tool('talk_read_messages', ['conversation_token' => $privateToken, 'limit' => 10]);
        self::assertTrue($aliceRead->isError(), 'a nonparticipant cannot read a private room');
        [, $participant, $sharedToken] = $this->conversationWithMessages();
        $foreign = $participant->tool('talk_read_messages', [
            'conversation_token' => $sharedToken,
            'limit' => 10,
            'before_message_id' => (int)$foreignCursor,
        ]);
        $missing = $participant->tool('talk_read_messages', [
            'conversation_token' => $sharedToken,
            'limit' => 10,
            'before_message_id' => 2147483647,
        ]);
        self::assertTrue($foreign->isError(), 'a message cursor from another room is refused');
        self::assertSame($missing->fingerprint(), $foreign->fingerprint(), 'a foreign cursor is indistinguishable from an unknown ID');
        self::assertStringNotContainsString('private ' . $this->run, $foreign->raw);
    }
}
