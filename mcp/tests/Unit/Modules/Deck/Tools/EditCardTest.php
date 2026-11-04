<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use InvalidArgumentException;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Assignment;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Deck\Tools\EditCard;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_edit_card`, including the optimistic lock and the preservation of untouched fields.
 */

final class EditCardTest extends TestCase {
    use DeckToolFixtures;
    /**
     * @param array<string, mixed> $current Current card values.
     * @param \Throwable|null $failure Exception `updateCard` raises, or null for a happy path.
     * @return EditCard Handler under test.
     */
    private function handler(array $current = [], ?\Throwable $failure = null): EditCard {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card($current + ['title' => 'Título atual', 'description' => 'Descrição atual']));
        $expectation = $capabilities->writer->method('updateCard');
        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($this->card($current + ['id' => 7]));
        }
        return $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
    }

    public function testHappyPathSendsTheMergedForm(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $current = $this->card([
            'id' => 7,
            'title' => 'Título atual',
            'description' => 'Descrição atual',
            'duedate' => new \DateTime('2026-01-05 00:00:00', new \DateTimeZone('UTC')),
            'type' => 'note',
            'owner' => 'alice',
            'order' => 3,
        ]);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Novo título', 'Descrição atual', '2026-01-05T00:00:00+00:00', $current->getDone())->willReturn($current);
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'title' => 'Novo título'], new ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testUntouchedFieldsAreNotResentAsEdits(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $current = $this->card(['id' => 7, 'description' => 'Só isso']);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Card', 'Só isso', null, null)->willReturn($current);
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $handler->execute(['cardId' => 7, 'description' => 'Só isso'], new ToolContext('alice'));
    }

    public function testExplicitNullClearsTheDateAndOmissionKeepsIt(): void {
        $current = $this->card(['id' => 7, 'duedate' => new \DateTime('2026-01-05 00:00:00', new \DateTimeZone('UTC'))]);
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Card', '', null, null)->willReturn($current);
        $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter())->execute(['cardId' => 7, 'duedate' => null], new ToolContext('alice'));
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Card', '', '2026-01-05T00:00:00+00:00', null)->willReturn($current);
        $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter())->execute(['cardId' => 7, 'title' => 'Card', 'description' => ''], new ToolContext('alice'));
    }

    public function testStaleLastModifiedIsAConflictAndNothingIsWritten(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'lastModified' => 1700000000]));
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'title' => 'Novo título', 'lastModified' => 1699999999], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict(), $this->text($result));
    }

    public function testMatchingLastModifiedProceeds(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $current = $this->card(['id' => 7, 'lastModified' => 1700000000]);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->willReturn($current);
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'title' => 'Novo título', 'lastModified' => 1700000000], new ToolContext('alice'));
        self::assertArrayNotHasKey('isError', $result);
    }

    public function testCompletedTrueCompletesAnOpenCardAndReportsDoneTimestamp(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $current = $this->card(['id' => 7, 'done' => null, 'stackId' => 10, 'order' => 4]);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with(
            'alice', $current, 'Card', '', null,
            self::callback(static fn(mixed $done): bool => $done instanceof \DateTime),
        )->willReturnCallback(static fn(string $userId, \OCA\Deck\Db\Card $before, string $title, string $description, ?string $duedate, ?\DateTime $done): \OCA\Deck\Db\Card => new \OCA\Deck\Db\Card([
            'id' => 7, 'title' => $title, 'description' => $description, 'done' => $done,
            'stackId' => 10, 'order' => 4,
        ]));
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'completed' => true], new ToolContext('alice')));
        self::assertIsInt($payload['done']);
        self::assertSame(10, $payload['stackId']);
        self::assertSame(4, $payload['order']);
    }

    public function testRepeatedCompletedTrueKeepsExistingTimestamp(): void {
        $timestamp = new \DateTime('2026-01-02 10:00:00', new \DateTimeZone('UTC'));
        $current = $this->card(['id' => 7, 'done' => $timestamp]);
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'completed' => true], new ToolContext('alice')));
        self::assertSame($timestamp->getTimestamp(), $payload['done']);
    }

    public function testRepeatedCompletionOnArchivedBoardStillGoesThroughNativeUpdate(): void {
        $timestamp = new \DateTime('2026-01-02 10:00:00', new \DateTimeZone('UTC'));
        $current = $this->card(['id' => 7, 'done' => $timestamp]);
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->ownership->method('cardBoardIsArchived')->with('alice', 7)->willReturn(true);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Card', '', null, $timestamp)->willReturn($current);
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $handler->execute(['cardId' => 7, 'completed' => true], new ToolContext('alice'));
    }

    public function testCompletionChangeIsRecognizedAfterWriteEvenWithSameSecondLastModified(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $before = $this->card(['id' => 7, 'lastModified' => 1700000000, 'done' => null]);
        $capabilities->cards->method('findCard')->willReturn($before);
        $written = null;
        $capabilities->writer->expects(self::once())->method('updateCard')->willReturnCallback(static function (string $user, \OCA\Deck\Db\Card $card, string $title, string $description, ?string $duedate, ?\DateTimeInterface $done) use (&$written): \OCA\Deck\Db\Card {
            $written = new \OCA\Deck\Db\Card(['id' => 7, 'title' => $title, 'description' => $description, 'done' => $done, 'lastModified' => 1700000000]);
            throw new RuntimeException('write response lost');
        });
        $capabilities->state->method('cardState')->willReturnCallback(static function () use (&$written): \OCA\Deck\Db\Card { return $written; });
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'completed' => true], new ToolContext('alice')));
        self::assertIsInt($payload['done']);
    }

    public function testCompletedFalseReopensACard(): void {
        $current = $this->card(['id' => 7, 'done' => new \DateTime('2026-01-02 10:00:00')]);
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Card', '', null, null)->willReturn($this->card(['id' => 7, 'done' => null]));
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'completed' => false], new ToolContext('alice')));
        self::assertNull($payload['done']);
    }

    public function testStaleLastModifiedRefusesCompletionWithoutWriting(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'lastModified' => 1700000000]));
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'completed' => true, 'lastModified' => 1699999999], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict(), $this->text($result));
    }

    public function testSharedBoardRequiresConfirmationBeforeCompletion(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->cards->expects(self::never())->method('findCard');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->assertSharedConfirmation($handler->execute(['cardId' => 7, 'completed' => true], new ToolContext('alice')));
    }

    public function testEditSchemaAcceptsOnlyBooleanCompletionState(): void {
        $schema = EditCard::definition()->inputSchema;
        self::assertSame('boolean', $schema['properties']['completed']['type']);
    }

    public function testCallWithoutAnyEditableFieldIsAParameterError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->expects(self::never())->method('findCard');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNoFieldToEdit());
        $handler->execute(['cardId' => 7], new ToolContext('alice'));
    }

    public function testLastModifiedAloneIsNotAnEdit(): void {
        $handler = $this->handler();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNoFieldToEdit());
        $handler->execute(['cardId' => 7, 'lastModified' => 1700000000], new ToolContext('alice'));
    }

    public function testInvalidDateIsAParameterError(): void {
        $handler = $this->handler();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalidDuedate());
        $handler->execute(['cardId' => 7, 'duedate' => '31/12/2026'], new ToolContext('alice'));
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willThrowException(new NoPermissionException('Permission denied'));
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'title' => 'Novo'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
        $result = $this->handler([], new StatusException('Operation not allowed. This board is archived.'))->execute(['cardId' => 7, 'title' => 'Novo'], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(), $this->text($result));
    }

    public function testDeckRejectionBecomesTheInvalidMessage(): void {
        $result = $this->handler([], new BadRequestException('title'))->execute(['cardId' => 7, 'title' => 'Novo'], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalid(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $result = $this->handler([], new RuntimeException('lock wait timeout'))->execute(['cardId' => 7, 'title' => 'Novo'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }

    public function testSharedBoardWithoutConfirmationUpdatesNothing(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->expects(self::once())->method('cardOwnership')->with('alice', 7)->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->cards->expects(self::never())->method('findCard');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->assertSharedConfirmation($handler->execute(['cardId' => 7, 'title' => 'Novo título'], new ToolContext('alice')));
    }

    public function testSharedBoardWithConfirmationUpdatesTheCard(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $current = $this->card(['id' => 7, 'title' => 'Título atual', 'description' => 'Descrição atual']);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->writer->expects(self::once())->method('updateCard')->with('alice', $current, 'Novo título', 'Descrição atual', null, null)->willReturn($current);
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'title' => 'Novo título', 'confirm_shared' => true], new ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testOwnershipDeniedIsRefusedBeforeTheCardIsRead(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->cards->expects(self::never())->method('findCard');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'title' => 'Novo título'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    /**
     * @param list<string> $uids Accounts assigned to the card today.
     * @return \OCA\Deck\Db\Card Card as `findCard` reads it, with its assignments embedded.
     */
    private function cardAssignedTo(array $uids): \OCA\Deck\Db\Card {
        return $this->card([
            'id' => 7,
            'stackId' => 10,
            'assignedUsers' => array_map(
                static fn(string $uid): Assignment => new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]),
                $uids,
            ),
        ]);
    }

    private function assignment(string $uid): Assignment {
        return new Assignment(['cardId' => 7, 'participant' => $uid, 'type' => 0]);
    }

    /** @return list<string> uids of the assigned users the payload reports */
    private function assignedUids(array $payload): array {
        return array_column($payload['assignedUsers'], 'uid');
    }

    public function testAssignOnlyEditsTheAssigneesAndTouchesNoOtherField(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->expects(self::never())->method('updateCard');
        $capabilities->assignments->expects(self::once())->method('assignCardUser')->with('alice', 7, 'pedro')->willReturn($this->assignment('pedro'));
        $capabilities->assignments->expects(self::never())->method('unassignCardUser');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'assign' => ['pedro']], new ToolContext('alice')));
        self::assertSame(['ana', 'pedro'], $this->assignedUids($payload));
        self::assertSame(['pedro'], $payload['assigned']);
        self::assertSame([], $payload['unassigned']);
        self::assertSame([], $payload['failed']);
    }

    public function testUnassignRemovesAnAssignedAccount(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['ana', 'pedro']));
        $capabilities->assignments->expects(self::never())->method('validateAssignees');
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $capabilities->assignments->expects(self::once())->method('unassignCardUser')->with('alice', 7, 'pedro')->willReturn($this->assignment('pedro'));
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'unassign' => ['pedro']], new ToolContext('alice')));
        self::assertSame(['ana'], $this->assignedUids($payload));
        self::assertSame(['pedro'], $payload['unassigned']);
    }

    public function testAccountWithoutBoardAccessIsRefusedBeforeAnythingIsWritten(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['pedro']));
        $capabilities->assignments->method('validateAssignees')->willThrowException(new ArgumentValidationException('Invalid argument: assignees', 'assignees', 'each account must exist and have access to the board'));
        $capabilities->writer->expects(self::never())->method('updateCard');
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $capabilities->assignments->expects(self::never())->method('unassignCardUser');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->expectException(ArgumentValidationException::class);
        // The title would be written and `pedro` removed if the refusal came after the writes.
        $handler->execute(
            ['cardId' => 7, 'title' => 'Novo', 'assign' => ['intruso'], 'unassign' => ['pedro']],
            new ToolContext('alice'),
        );
    }

    public function testAnAccountInBothListsIsRefusedBeforeDeckIsTouched(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->expects(self::never())->method('findCard');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        try {
            $handler->execute(['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['pedro']], new ToolContext('alice'));
            self::fail('accepted an account in both lists');
        } catch (ArgumentValidationException $e) {
            self::assertSame('assign', $e->details()['field']);
        }
    }

    public function testUnassigningSomebodyNotAssignedIsIgnoredWithAWarning(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
        $capabilities->assignments->expects(self::never())->method('unassignCardUser');
        $capabilities->writer->expects(self::never())->method('updateCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'unassign' => ['pedro']], new ToolContext('alice')));
        self::assertSame([], $payload['unassigned']);
        self::assertSame(['ana'], $this->assignedUids($payload));
        self::assertCount(1, $payload['warnings']);
        self::assertStringContainsString('pedro', $payload['warnings'][0]);
    }

    public function testAssigningSomebodyAlreadyAssignedIsIgnoredWithAWarning(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['ana']));
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'assign' => ['ana']], new ToolContext('alice')));
        self::assertSame([], $payload['assigned']);
        self::assertCount(1, $payload['warnings']);
        self::assertStringContainsString('ana', $payload['warnings'][0]);
    }

    public function testAFailureInTheMiddleIsAPartialSuccessThatIsNotRolledBack(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo(['ana', 'luis']));
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro'], ['uid' => 'rosa', 'displayName' => 'Rosa']]);
        $capabilities->assignments->method('assignCardUser')->willReturnCallback(function (
            string $user,
            int $card,
            string $uid,
        ): Assignment {
            if ($uid === 'pedro') {
                throw new RuntimeException('SQLSTATE[HY000] secret detail');
            }
            return $this->assignment($uid);
        });
        $capabilities->assignments->expects(self::once())->method('unassignCardUser')->with('alice', 7, 'luis')->willReturn($this->assignment('luis'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning')->with(
            self::anything(),
            self::callback(static fn(array $context): bool => ($context['exception'] ?? null) === RuntimeException::class && !str_contains(json_encode($context), 'secret detail')),
        );
        $handler = $this->deckTool(EditCard::class, $capabilities, $logger, $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'assign' => ['pedro', 'rosa'], 'unassign' => ['luis']], new ToolContext('alice'));
        self::assertArrayNotHasKey('isError', $result);
        $payload = $this->payload($result);
        self::assertSame(['rosa'], $payload['assigned']);
        self::assertSame(['luis'], $payload['unassigned']);
        self::assertSame(
            [['uid' => 'pedro', 'reason' => \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric()]],
            $payload['failed'],
        );
        self::assertSame(['ana', 'rosa'], $this->assignedUids($payload));
        self::assertStringNotContainsString('secret detail', $this->text($result));
    }

    public function testFieldsAndAssigneesCanBeEditedTogetherAndFieldsGoFirst(): void {
        $calls = [];
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $current = $this->cardAssignedTo(['ana']);
        $capabilities->cards->method('findCard')->willReturn($current);
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->method('updateCard')->willReturnCallback(function () use (&$calls, $current) {
            $calls[] = 'update';
            return $current;
        });
        $capabilities->assignments->method('assignCardUser')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'assign';
            return $this->assignment('pedro');
        });
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $handler->execute(['cardId' => 7, 'title' => 'Novo', 'assign' => ['pedro']], new ToolContext('alice'));
        self::assertSame(['update', 'assign'], $calls);
    }

    public function testEmptyListsAloneAreNotAnEdit(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->expects(self::never())->method('findCard');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNoFieldToEdit());
        $handler->execute(['cardId' => 7, 'assign' => [], 'unassign' => []], new ToolContext('alice'));
    }

    public function testStaleLastModifiedAlsoBlocksAnAssigneeOnlyEdit(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->cardAssignedTo([]));
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $handler = $this->deckTool(EditCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'assign' => ['pedro'], 'lastModified' => 1], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorConflict(), $this->text($result));
    }

    public function testPlainFieldEditKeepsThePayloadFreeOfAssigneeKeys(): void {
        $payload = $this->payload($this->handler()->execute(['cardId' => 7, 'title' => 'Novo'], new ToolContext('alice')));
        self::assertArrayNotHasKey('assigned', $payload);
        self::assertArrayNotHasKey('failed', $payload);
    }
}
