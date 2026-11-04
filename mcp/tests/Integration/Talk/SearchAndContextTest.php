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
use OCA\Talk\Model\AttendeeMapper;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\Group;

/** Real Talk search and bidirectional context remain conversation-scoped and never update unread state. */
#[Group('talk')]
final class SearchAndContextTest extends ExclusionTestCase
{
    private array $permissionSnapshot = [];
    private array $legacyPolicySnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture(
            [self::ALICE, self::BOB, 'mcp-it-legacy'],
            ['talk_search_messages', 'talk_read_message_context', 'talk_create_group', 'talk_reply'],
        );
    }

    protected function tearDown(): void
    {
        try {
            if ($this->permissionSnapshot !== []) {
                \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($this->permissionSnapshot);
            }
            if ($this->legacyPolicySnapshot !== []) {
                $this->restoreLegacyPolicy();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string, mixed> */
    private function payload(ToolReply $reply): array
    {
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured();
        return $data !== [] ? $data : $reply->json();
    }

    /** @return array{unread:int,lastRead:int} */
    private function nativeReadState(string $uid, string $token): array
    {
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
        self::assertIsArray($row);
        return ['unread' => (int) $row['unread_messages'], 'lastRead' => (int) $row['last_read_message']];
    }

    private function setNativeReadState(string $uid, string $token, int $lastRead, int $unread): void
    {
        $room = \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomForUserByToken($token, $uid);
        $participant = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class)->getParticipant($room, $uid, false);
        $attendee = $participant->getAttendee();
        $attendee->setLastReadMessage($lastRead);
        $attendee->setUnreadMessages($unread);
        \OCP\Server::get(AttendeeMapper::class)->update($attendee);
    }

    /** @return array<string, mixed> */
    private function createRoom(McpClient $client, string $name): array
    {
        return $this->payload($client->write('talk_create_group', ['name' => $name, 'participants' => [self::BOB]]));
    }

    public function testSearchAndContextStayRoomScopedAndPreserveReadState(): void
    {
        foreach ([self::ALICE, self::BOB] as $uid) {
            \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, [
                'talk_search_messages' => 'allow',
                'talk_read_message_context' => 'allow',
                'talk_create_group' => 'allow',
                'talk_reply' => 'allow',
            ]);
        }
        $alice = new McpClient(LoginFactory::basic(self::ALICE));
        $bob = new McpClient(LoginFactory::basic(self::BOB));
        $firstRoom = $this->createRoom($alice, 'search context one ' . $this->run);
        $firstToken = (string) $firstRoom['conversation_token'];
        $beforeMessage = $this->payload($alice->write('talk_reply', ['conversation_token' => $firstToken, 'message' => 'before ' . $this->run]));
        $anchorText = 'needle ' . $this->run;
        $this->payload($alice->write('talk_reply', ['conversation_token' => $firstToken, 'message' => $anchorText]));
        $this->payload($alice->write('talk_reply', ['conversation_token' => $firstToken, 'message' => 'after ' . $this->run]));

        $secondRoom = $this->createRoom($alice, 'search context two ' . $this->run);
        $secondToken = (string) $secondRoom['conversation_token'];
        // Keep the exact query substring in the second room so native Talk search matches it too.
        $secondText = $anchorText . ' in another room';
        $this->payload($alice->write('talk_reply', ['conversation_token' => $secondToken, 'message' => $secondText]));

        $originalReadState = $this->nativeReadState(self::BOB, $firstToken);
        // This disposable server has no live Talk client to advance Bob's read marker; seed a marker
        // at a real earlier message and a matching unread count for the two later user messages.
        $this->setNativeReadState(self::BOB, $firstToken, (int) $beforeMessage['messageId'], 2);
        $this->later(fn () => $this->setNativeReadState(
            self::BOB,
            $firstToken,
            $originalReadState['lastRead'],
            $originalReadState['unread'],
        ));
        $before = $this->nativeReadState(self::BOB, $firstToken);
        self::assertGreaterThan(0, $before['unread'], 'Bob has a seeded unread count before reading');
        self::assertGreaterThan(0, $before['lastRead'], 'Bob’s marker points to an actual earlier message');
        $search = $this->payload($bob->tool('talk_search_messages', [
            'conversation_token' => $firstToken,
            'query' => $anchorText,
            'limit' => 5,
        ]));
        self::assertSame([$anchorText], array_column($search['messages'], 'text'));
        self::assertNull($search['next_offset']);
        self::assertFalse($search['has_more']);
        self::assertFalse($search['pagination_limited']);
        $anchorId = (int) $search['messages'][0]['id'];
        $foreignSearch = $this->payload($bob->tool('talk_search_messages', [
            'conversation_token' => $secondToken,
            'query' => $anchorText,
            'limit' => 5,
        ]));
        self::assertSame([$secondText], array_column($foreignSearch['messages'], 'text'));
        self::assertSame($before, $this->nativeReadState(self::BOB, $firstToken), 'search does not change Talk unread or last-read state');

        $context = $this->payload($bob->tool('talk_read_message_context', [
            'conversation_token' => $firstToken,
            'message_id' => $anchorId,
            'before_limit' => 1,
            'after_limit' => 1,
        ]));
        self::assertSame(['before ' . $this->run, $anchorText, 'after ' . $this->run], array_column($context['messages'], 'text'));
        // The room creation system event is earlier than the explicit "before" message.
        self::assertTrue($context['has_more_before']);
        self::assertFalse($context['has_more_after']);
        self::assertSame($before, $this->nativeReadState(self::BOB, $firstToken), 'search and context do not change Talk unread or last-read state');

        $crossRoomAnchor = $bob->tool('talk_read_message_context', [
            'conversation_token' => $firstToken,
            'message_id' => (int) $foreignSearch['messages'][0]['id'],
            'before_limit' => 1,
            'after_limit' => 1,
        ]);
        self::assertTrue($crossRoomAnchor->isError(), 'an anchor from another conversation is hidden');
    }

    public function testSearchAndContextRefuseConversationsTheUserCannotRead(): void
    {
        $legacyUid = 'mcp-it-legacy';
        \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(self::ALICE, [
            'talk_search_messages' => 'allow', 'talk_read_message_context' => 'allow',
        ]);
        $alice = new McpClient(LoginFactory::basic(self::ALICE));
        $policy = \OCP\Server::get(GrantPolicy::class);
        $this->legacyPolicySnapshot = $this->snapshotPolicy($legacyUid, [GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_talk_create', 'grant_talk_reply']);
        $policy->setEligible($legacyUid, true);
        $policy->setConnected($legacyUid, true);
        foreach (['create', 'reply'] as $operation) {
            $policy->setGrant($legacyUid, 'talk', $operation, true);
        }
        \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($legacyUid, [
            'talk_create_group' => 'allow', 'talk_reply' => 'allow',
        ]);
        $legacy = new McpClient(LoginFactory::basic($legacyUid));
        $privateRoom = $this->payload($legacy->write('talk_create_group', [
            'name' => 'private search ' . $this->run,
            'participants' => [self::BOB],
        ]));
        $token = (string) $privateRoom['conversation_token'];
        $message = $this->payload($legacy->write('talk_reply', ['conversation_token' => $token, 'message' => 'secret ' . $this->run]));

        $search = $alice->tool('talk_search_messages', ['conversation_token' => $token, 'query' => 'secret ' . $this->run]);
        self::assertTrue($search->isError(), 'a nonparticipant cannot search the private conversation');
        $context = $alice->tool('talk_read_message_context', [
            'conversation_token' => $token,
            'message_id' => (int) ($message['messageId'] ?? 0),
        ]);
        self::assertTrue($context->isError(), 'a nonparticipant cannot read private conversation context');
    }

    /** @return array<string, string> */
    private function snapshotPolicy(string $uid, array $keys): array
    {
        $config = \OCP\Server::get(IConfig::class);
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__');
        }
        return $values;
    }

    private function restoreLegacyPolicy(): void
    {
        $config = \OCP\Server::get(IConfig::class);
        foreach ($this->legacyPolicySnapshot as $key => $value) {
            if ($value === '__mcp_it_missing__') {
                $config->deleteUserValue('mcp-it-legacy', 'mcp', $key);
            } else {
                $config->setUserValue('mcp-it-legacy', 'mcp', $key, $value);
            }
        }
    }
}
