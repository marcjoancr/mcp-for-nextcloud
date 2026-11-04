<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ConversationPlan;
use OCA\Mcp\Modules\Talk\Presentation\PlanMessages;
use Throwable;

final class SummaryPublishing
{
    public const NAME = 'talk_publish_summary';

    /**
     * @param \Closure(string):object $participantService resolves Talk's native ParticipantService for a user
     * @param \Closure(string):array<string,int|string> $talkConstants resolves native Talk constants for a user
     */
    public function __construct(
        private MessageSender $sender,
        private ConversationResolver $resolver,
        private PlanState $states,
        private \Closure $participantService,
        private \Closure $talkConstants,
    ) {}

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function preview(string $userId, array $arguments): array
    {
        $message = MessageText::normalizeMessage($this->message($arguments));
        $conversation = $this->resolver->resolveForWriting($userId, $this->token($arguments));
        $plan = ConversationPlan::payload(self::NAME, $conversation, $userId, ['message' => $message]);
        $plan['plan_state'] = $this->state($userId, $conversation, $message, $this->token($arguments));
        if ($this->isPublic($conversation, $userId)) {
            $plan['visibility_warning'] = PlanMessages::publicConversationVisibilityWarning();
        }
        return $plan;
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    public function execute(string $userId, array $arguments): array
    {
        $message = MessageText::normalizeMessage($this->message($arguments));
        $conversation = $this->resolver->resolveForWriting($userId, $this->token($arguments));
        if (($arguments['confirm'] ?? false) !== true) {
            return $this->preview($userId, $arguments);
        }
        PlanState::require($arguments);
        $expected = $this->state($userId, $conversation, $message, $this->token($arguments));
        if (!PlanState::matches($expected, $arguments)) {
            return $this->preview($userId, $arguments);
        }

        try {
            $messageId = $this->sender->sendText($conversation, $userId, $message);
        } catch (ConversationAccessException) {
            // A failure can happen after Talk committed the message. Never invite an automatic resend.
            return ['uncertain' => true, 'message' => MessageMessages::summaryDeliveryUncertain()];
        }

        return ['conversation_token' => $conversation->token(), 'messageId' => $messageId, 'sent' => true];
    }

    private function state(string $userId, Conversation $conversation, string $message, string $requestedToken): string
    {
        return $this->states->of(self::NAME, $userId, [
            'conversation_token' => $conversation->token(),
            'requested_token' => $requestedToken,
            'display_name' => $conversation->displayName($userId),
            'room_type' => method_exists($conversation->room, 'getType') ? (int) $conversation->room->getType() : null,
            'public_type' => ($this->talkConstants)($userId)['publicType'],
            'last_message_id' => (int) $conversation->room->getLastMessageId(),
            'message' => $message,
            'audience' => $this->audienceFingerprint($userId, $conversation),
        ]);
    }

    private function audienceFingerprint(string $userId, Conversation $conversation): string
    {
        try {
            $service = ($this->participantService)($userId);
            $participants = $service->getParticipantsForRoom($conversation->room);
            if (!is_array($participants) || count($participants) > 1000) {
                throw new \UnexpectedValueException('Unsupported participant list');
            }
            $audience = [];
            $callerFound = false;
            foreach ($participants as $participant) {
                $attendee = $participant->getAttendee();
                $actorType = (string) $attendee->getActorType();
                $actorId = (string) $attendee->getActorId();
                $callerFound = $callerFound || ($actorType === ($this->talkConstants)($userId)['actorUsers'] && $actorId === $userId);
                $audience[] = [$actorType, $actorId];
            }
            if (!$callerFound) {
                throw new \UnexpectedValueException('Caller not present in participant list');
            }
            if (
                $conversation->room->getType() === ($this->talkConstants)($userId)['oneToOneType'] &&
                count($audience) !== 2
            ) {
                throw new \UnexpectedValueException('Incomplete one-to-one participant list');
            }
            usort($audience, static fn(array $a, array $b): int => $a <=> $b);
            return hash('sha256', json_encode($audience, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            throw new ConversationAccessException(ConversationMessages::conversationNotFound(), $e);
        }
    }

    private function isPublic(Conversation $conversation, string $userId): bool
    {
        return $conversation->room->getType() === ($this->talkConstants)($userId)['publicType'];
    }

    /** @param array<string,mixed> $arguments */
    private function message(array $arguments): string
    {
        if (!isset($arguments['message']) || !is_string($arguments['message'])) {
            throw new InvalidArgumentException(MessageMessages::emptyMessage());
        }
        return $arguments['message'];
    }

    /** @param array<string,mixed> $arguments */
    private function token(array $arguments): string
    {
        return (string) ($arguments['conversation_token'] ?? throw new InvalidArgumentException(ConversationMessages::invalidToken()));
    }
}
