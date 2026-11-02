<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler;
use OCA\Mcp\Modules\Mail\Handlers\ReadThreadHandler;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailBodyTooLargeException;
use OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use PHPUnit\Framework\TestCase;

final class ReadThreadHandlerTest extends TestCase {
	public function testFirstPageIsNewestFirstAndBodyReadsAreBoundedToTheRequestedPage(): void {
		[$handler, $locator, $thread, $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(12, 'root', 200),
			$this->message(13, 'root', 100),
		]);
		$reader->expects($this->exactly(2))->method('readRow')->willReturnCallback(
			static fn (string $userId, array $found, int $maxChars): array => ['id' => (int)$found['message']->getId(), 'body' => ['text' => str_repeat('x', $maxChars)]],
		);

		$result = $handler->handle(['message_id' => 11, 'limit' => 2], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([11, 12], array_column($data['messages'], 'id'));
		self::assertSame(2, $data['count']);
		self::assertTrue($data['hasMore']);
		self::assertSame(12, $data['next_before_message_id']);
		self::assertSame(8000, strlen($data['messages'][0]['body']['text']));
	}

	public function testBeforeMessageIdIsExclusiveAndTheLastPageHasNoNextCursor(): void {
		[$handler, , , $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(12, 'root', 200),
			$this->message(13, 'root', 100),
		]);
		$reader->expects($this->once())->method('readRow')->willReturn(['id' => 13]);

		$result = $handler->handle(['message_id' => 11, 'before_message_id' => 12], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([13], array_column($data['messages'], 'id'));
		self::assertFalse($data['hasMore']);
		self::assertNull($data['next_before_message_id']);
	}

	public function testASeedWithoutThreadRootIsReadAsASingleMessage(): void {
		[$handler, , $thread, $reader] = $this->harness([$this->message(11, null, 300)]);
		$thread->expects($this->never())->method('thread');
		$reader->expects($this->once())->method('readRow')->willReturn(['id' => 11]);

		$result = $handler->handle(['message_id' => 11], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([11], array_column($data['messages'], 'id'));
		self::assertFalse($data['hasMore']);
		self::assertNull($data['next_before_message_id']);
	}

	public function testCursorOutsideTheSeedThreadIsRejectedBeforeAnyBodyRead(): void {
		[$handler, , , $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(12, 'root', 200),
		]);
		$reader->expects($this->never())->method('readRow');

		$this->expectException(ToolFailure::class);
		$handler->handle(['message_id' => 11, 'before_message_id' => 99], 'alice');
	}

	public function testAnomalousCrossAccountCandidateDoesNotAffectReturnedCountsOrCursor(): void {
		[$handler, , , $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(99, 'root', 200, 88),
		]);
		$reader->expects($this->once())->method('readRow')->willReturn(['id' => 11]);

		$result = $handler->handle(['message_id' => 11, 'limit' => 1], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([11], array_column($data['messages'], 'id'));
		self::assertSame(1, $data['count']);
		self::assertFalse($data['hasMore']);
		self::assertNull($data['next_before_message_id']);
	}

	public function testThreadsBeyondTheMetadataBoundAreRefusedBeforeAnyBodyRead(): void {
		$messages = [];
		for ($id = 1; $id <= ReadThreadHandler::MAX_THREAD_MESSAGES + 1; $id++) {
			$messages[] = $this->message($id, 'root', 1000 - $id);
		}
		[$handler, , , $reader] = $this->harness($messages);
		$reader->expects($this->never())->method('readRow');

		$this->expectException(ToolFailure::class);
		$handler->handle(['message_id' => 1], 'alice');
	}

	public function testStaleThreadCandidatesAreDroppedBeforeReadingTheirBodies(): void {
		[$handler, $locator, , $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(12, 'root', 200),
		]);
		$locator->method('locate')->willReturnCallback(function (string $userId, int $id): array {
			if ($id === 12) {
				throw new ToolFailure('not found');
			}
			return $this->located($this->message(11, 'root', 300));
		});
		$reader->expects($this->once())->method('readRow')->willReturn(['id' => 11]);

		$result = $handler->handle(['message_id' => 11], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([11], array_column($data['messages'], 'id'));
		self::assertSame(1, $data['count']);
	}

	public function testOversizedBodyDoesNotBlockUnreadMessagesOrTheNextCursor(): void {
		[$handler, , , $reader] = $this->harness([
			$this->message(11, 'root', 300),
			$this->message(12, 'root', 200),
			$this->message(13, 'root', 100),
		]);
		$reader->method('readRow')->willReturnCallback(static function (string $userId, array $found, int $maxChars): array {
			if ((int)$found['message']->getId() === 11) {
				throw new MailBodyTooLargeException(2 * 1024 * 1024);
			}
			return ['id' => (int)$found['message']->getId(), 'unread' => true, 'body' => ['text' => 'read via PEEK']];
		});
		$reader->method('unavailableRow')->willReturn(['id' => 11, 'body' => null, 'bodyStatus' => 'too_large']);

		$result = $handler->handle(['message_id' => 11, 'limit' => 2], 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

		self::assertSame([11, 12], array_column($data['messages'], 'id'));
		self::assertSame('too_large', $data['messages'][0]['bodyStatus']);
		self::assertTrue($data['messages'][1]['unread']);
		self::assertSame(12, $data['next_before_message_id']);
	}

	public function testToolSchemaDefinesBoundedThreadBodyReadsAndStableCursor(): void {
		$schema = \OCA\Mcp\Modules\Mail\Tools\ReadThread::definition()->inputSchema;

		self::assertSame(['message_id'], $schema['required']);
		self::assertSame(10, $schema['properties']['limit']['maximum']);
		self::assertSame(8000, $schema['properties']['max_chars']['maximum']);
		self::assertArrayHasKey('before_message_id', $schema['properties']);
		self::assertFalse($schema['additionalProperties']);
	}

	/** @return array{ReadThreadHandler, MailMessageLocator&\PHPUnit\Framework\MockObject\MockObject, MailMessageGateway&\PHPUnit\Framework\MockObject\MockObject, ReadMessageHandler&\PHPUnit\Framework\MockObject\MockObject} */
	private function harness(array $threadMessages): array {
		$seed = $threadMessages[0];
		$locator = $this->createMock(MailMessageLocator::class);
		$located = [];
		foreach ($threadMessages as $message) {
			$located[(int)$message->getId()] = $this->located($message);
		}
		$locator->method('locate')->willReturnCallback(static fn (string $userId, int $id): array => $located[$id] ?? throw new ToolFailure('not found'));
		$thread = $this->createMock(MailMessageGateway::class);
		$thread->method('thread')->willReturn($threadMessages);
		$thread->method('mailboxIds')->willReturn([6]);
		$reader = $this->createMock(ReadMessageHandler::class);
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/mail');
		$rows = new MailMessageRows(new \DateTimeZone('UTC'), new MailLinks($urls));
		$answer = new MailMessageAnswer($rows);
		return [new ReadThreadHandler($locator, $thread, $reader, $answer), $locator, $thread, $reader];
	}

	private function located(object $message): array {
		$mailbox = new RecordingDouble('Mailbox', ['getAccountId' => static fn (): int => 5], new \ArrayObject());
		$account = new RecordingDouble('Account', ['getId' => static fn (): int => 5], new \ArrayObject());
		return ['message' => $message, 'mailbox' => $mailbox, 'account' => $account];
	}

	private function message(int $id, ?string $root, int $sentAt, int $mailboxId = 6): object {
		return new RecordingDouble('Message', [
			'getId' => static fn (): int => $id,
			'getMailboxId' => static fn (): int => $mailboxId,
			'getThreadRootId' => static fn (): ?string => $root,
			'getSentAt' => static fn (): int => $sentAt,
	], new \ArrayObject());
	}
}
