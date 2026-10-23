<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck\Execution;
use OCA\Deck\{ArchivedItemException, NoPermissionException, StatusException};
use OCA\Deck\Exceptions\ConflictException;
use OCA\Mcp\Modules\Deck\Execution\{DeckConflictException, DeckRefusalException, DeckUnconfirmedException, DeckErrors, ResultBoundary, PreviewBoundary};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Db\{DoesNotExistException, MultipleObjectsReturnedException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
final class DeckFailureLogTest extends TestCase {
	/**
	 * Enumerate known business refusals and failures that must remain visible to administrators.
	 *
	 * @return array<string, array{Throwable, bool}> Failure and whether it is an expected refusal.
	 */
	public static function failures(): array {
		return [
			'rule' => [new DeckRefusalException('safe rule'), true],
			'conflict' => [new DeckConflictException('safe conflict'), true],
			'acl' => [new NoPermissionException('private ACL'), true],
			'absent' => [new DoesNotExistException('private id'), true],
			'archived' => [new ArchivedItemException('private card'), true],
			'native conflict' => [new ConflictException('private state'), true],
			'status defaults to 500' => [new StatusException('private server failure'), false],
			'ambiguous database result' => [new MultipleObjectsReturnedException('private query'), false],
			'text cannot classify' => [new \RuntimeException('safe rule'), false],
		];
	}

    #[DataProvider('failures')]
    public function testActualBoundariesKeepExpectedRefusalsQuiet(Throwable $failure, bool $expected): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method($expected ? 'debug' : 'warning')->with(
            'MCP Deck plan failed: {tool} ({exception})', ['tool' => 'deck_move_card', 'exception' => $failure::class]);
        $logger->expects(self::never())->method($expected ? 'warning' : 'debug');
        try {
            (new PreviewBoundary($logger))->run('deck_move_card', 'alice', static fn () => throw $failure);
            self::fail('Preview must refuse');
        } catch (ToolFailure $reply) {
            self::assertSame(DeckErrors::messageFor($failure), $reply->getMessage());
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method($expected ? 'debug' : 'error');
        $logger->expects(self::never())->method($expected ? 'error' : 'debug');
        $logger->expects(self::never())->method('warning');
        $result = (new ResultBoundary($logger))->run('deck_read_card', 'deck_read_card', static fn () => throw $failure);
        self::assertTrue($result['isError']);
        self::assertSame(DeckErrors::messageFor($failure), $result['content'][0]['text']);
    }

    public function testPartialRefusalRemainsAWarningAtThePreviewBoundary(): void {
        $failure = DeckRefusalException::stackReceivedCards(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('debug');
        $this->expectException(ToolFailure::class);
        (new PreviewBoundary($logger))->run('deck_delete_stack', 'alice', static fn () => throw $failure);
    }

    public function testPartialAndUncertainWritesStillWarn(): void {
        foreach ([DeckRefusalException::stackReceivedCards(false), new DeckUnconfirmedException('deck_read_card', new NoPermissionException('private'))] as $failure) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('warning');
            $logger->expects(self::never())->method('debug');
            $result = (new ResultBoundary($logger))->run('deck_delete_stack', 'deck_read_card', static fn () => throw $failure);
            self::assertArrayNotHasKey('isError', $result);
        }
    }
}
