<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk\Messaging;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Messaging\MessageSender;
use OCA\Mcp\Modules\Talk\Messaging\SummaryPublishing;
use OCA\Mcp\Modules\Talk\Presentation\TalkPlanRenderer;
use PHPUnit\Framework\TestCase;
use OCP\IConfig;

final class SummaryPublishingTest extends TestCase
{
    public function testPreviewShowsNormalizedTextAndDoesNotSend(): void
    {
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForWriting')->willReturn($this->conversation());
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::never())->method('sendText');
        $publisher = $this->publisher($resolver, $sender);

        $plan = $publisher->preview('alice', ['conversation_token' => 'abcd', 'message' => "  Report\nready  "]);

        self::assertSame("Report\nready", $plan['draft']['message']);
        self::assertSame('Ops room', $plan['conversation']['displayName']);
        self::assertArrayHasKey('plan_state', $plan);
    }

    public function testConfirmationRequiresMatchingTextDestinationActorAndAudience(): void
    {
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForWriting')->willReturn($this->conversation());
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::never())->method('sendText');
        $publisher = $this->publisher($resolver, $sender);
        $args = ['conversation_token' => 'abcd', 'message' => 'Approved summary'];
        $plan = $publisher->preview('alice', $args);

        foreach ([
            ['conversation_token' => 'efgh', 'message' => 'Approved summary'],
            ['conversation_token' => 'abcd', 'message' => 'Changed summary'],
        ] as $changed) {
            $result = $publisher->execute('alice', $changed + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
            self::assertTrue($result['requiresConfirmation']);
        }
        $result = $publisher->execute('bob', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
        self::assertTrue($result['requiresConfirmation']);
    }

    public function testRevokedWritableMembershipRefusesConfirmedSend(): void
    {
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->expects(self::exactly(2))->method('resolveForWriting')->willReturnOnConsecutiveCalls(
            $this->conversation(),
            self::throwException(new \OCA\Mcp\Modules\Talk\Access\ConversationAccessException('not writable')),
        );
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::never())->method('sendText');
        $publisher = $this->publisher($resolver, $sender);
        $args = ['conversation_token' => 'abcd', 'message' => 'Approved summary'];
        $plan = $publisher->preview('alice', $args);

        $this->expectException(\OCA\Mcp\Modules\Talk\Access\ConversationAccessException::class);
        $publisher->execute('alice', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
    }

    public function testSendIsAttemptedOnceAndFailureIsMarkedUncertain(): void
    {
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForWriting')->willReturn($this->conversation());
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::once())->method('sendText')->willThrowException(new \OCA\Mcp\Modules\Talk\Access\ConversationAccessException('delivery uncertain'));
        $publisher = $this->publisher($resolver, $sender);
        $args = ['conversation_token' => 'abcd', 'message' => 'Approved summary'];
        $plan = $publisher->preview('alice', $args);

        $result = $publisher->execute('alice', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
        self::assertTrue($result['uncertain'] ?? false);
    }

    public function testAudienceChangeInvalidatesPlanWithoutDisclosingParticipantIds(): void
    {
        $resolver = $this->createMock(ConversationResolver::class);
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::never())->method('sendText');
        $audience = new SummaryAudience();
        $room = new SummaryRoom();
        $room->type = 2;
        $resolver->method('resolveForWriting')->willReturn($this->conversation($room));
        $publisher = $this->publisher($resolver, $sender, $audience);
        $args = ['conversation_token' => 'abcd', 'message' => 'Approved summary'];
        $plan = $publisher->preview('alice', $args);
        self::assertStringNotContainsString('bob', json_encode($plan));

        $audience->participants[] = new SummaryParticipant('users', 'carol');
        $changed = $publisher->execute('alice', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
        self::assertTrue($changed['requiresConfirmation']);
        self::assertNotSame($plan['plan_state'], $changed['plan_state']);
    }

    public function testRenderedPreviewRetainsCompleteTextAndNeutralizesMarkdown(): void
    {
        $message = str_repeat('a', 250) . "\n<script> end";
        $rendered = TalkPlanRenderer::render('talk_publish_summary', [
            'conversation' => ['displayName' => 'Ops room'],
            'draft' => ['message' => $message],
        ]);

        self::assertIsString($rendered);
        self::assertStringContainsString('end', $rendered);
        self::assertStringNotContainsString('<script>', $rendered);
        self::assertStringContainsString('\\n', $rendered);
    }

    public function testSuccessfulSendInvalidatesTheOldPlanBeforeItCanReplay(): void
    {
        $room = new SummaryRoom();
        $conversation = $this->conversation($room);
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForWriting')->willReturn($conversation);
        $sender = $this->createMock(MessageSender::class);
        $sender->expects(self::once())->method('sendText')->willReturnCallback(
            static function () use ($room): int {
                $room->lastMessageId++;
                return $room->lastMessageId;
            },
        );
        $publisher = $this->publisher($resolver, $sender);
        $args = ['conversation_token' => 'abcd', 'message' => 'Approved summary'];
        $plan = $publisher->preview('alice', $args);

        self::assertTrue($publisher->execute('alice', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']])['sent']);
        $replay = $publisher->execute('alice', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']]);
        self::assertTrue($replay['requiresConfirmation']);
        self::assertNotSame($plan['plan_state'], $replay['plan_state']);
    }

    private function publisher(ConversationResolver $resolver, MessageSender $sender, ?SummaryAudience $audienceFixture = null): SummaryPublishing
    {
        $audienceFixture ??= new SummaryAudience();
        $participantService = static fn(string $uid): SummaryAudience => $audienceFixture;
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
        $talkConstants = static fn(string $uid): array => [
            'actorUsers' => 'users',
            'oneToOneType' => 1,
            'publicType' => 3,
        ];
        return new SummaryPublishing($sender, $resolver, new PlanState($config), $participantService, $talkConstants);
    }

    private function conversation(?SummaryRoom $room = null): Conversation
    {
        return new Conversation($room ?? new SummaryRoom(), new \stdClass());
    }
}

final class SummaryRoom
{
    public int $lastMessageId = 10;
    public int $type = 1;
    public function getToken(): string { return 'abcd'; }
    public function getDisplayName(string $uid): string { return 'Ops room'; }
    public function getType(): int { return $this->type; }
    public function getLastMessageId(): int { return $this->lastMessageId; }
}

final class SummaryParticipant
{
    public function __construct(private string $type, private string $id) {}
    public function getAttendee(): object
    {
        return new class($this->type, $this->id) {
            public function __construct(private string $type, private string $id) {}
            public function getActorType(): string { return $this->type; }
            public function getActorId(): string { return $this->id; }
        };
    }
}

final class SummaryAudience
{
    /** @var list<SummaryParticipant> */
    public array $participants = [];

    public function __construct()
    {
        $this->participants = [new SummaryParticipant('users', 'alice'), new SummaryParticipant('users', 'bob')];
    }

    /** @return list<SummaryParticipant> */
    public function getParticipantsForRoom(object $room): array { return $this->participants; }
}
