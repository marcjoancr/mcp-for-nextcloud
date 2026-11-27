<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Db\MailOwnedDraftsMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class MailOwnedDraftsMapperTest extends TestCase {
	public function testRetiredActorPayloadIsNotDecryptedOrReturnedToRecreatedUid(): void {
		[$db, $crypto] = $this->reader(['actor_incarnation' => str_repeat('b', 64), 'version' => '4', 'payload' => 'ciphertext']);
		$crypto->expects(self::never())->method('decrypt');
		$snapshot = (new MailOwnedDraftsMapper($db, $crypto))->load('alice', str_repeat('a', 64));
		self::assertSame(['version' => 4, 'drafts' => []], $snapshot);
	}

	public function testOversizedStoredCiphertextIsRejectedBeforeDecrypt(): void {
		[$db, $crypto] = $this->reader(['actor_incarnation' => str_repeat('a', 64), 'version' => '2', 'payload' => str_repeat('x', MailOwnedDraftsMapper::MAX_CIPHER_BYTES + 1)]);
		$crypto->expects(self::never())->method('decrypt');
		$this->expectException(\RuntimeException::class);
		(new MailOwnedDraftsMapper($db, $crypto))->load('alice', str_repeat('a', 64));
	}

	public function testFailureToInsertReceiptRollsBackTheReleasedDraftSlot(): void {
		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturn('predicate');
		$snapshot = $this->createMock(IQueryBuilder::class);
		$snapshot->method('expr')->willReturn($expression);
		foreach (['update', 'set', 'where', 'andWhere'] as $method) {
			$snapshot->method($method)->willReturnSelf();
		}
		$snapshot->expects(self::once())->method('executeStatement')->willReturn(1);

		$receipt = $this->createMock(IQueryBuilder::class);
		$receipt->method('insert')->with('mcp_mail_owned_attempts')->willReturnSelf();
		$receipt->method('setValue')->willReturnSelf();
		$receipt->expects(self::once())->method('executeStatement')->willThrowException(new \RuntimeException('receipt insert failed'));
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('rollBack');
		$db->expects(self::never())->method('commit');
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($snapshot, $receipt);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturn('ciphertext');

		$this->expectExceptionMessage('receipt insert failed');
		(new MailOwnedDraftsMapper($db, $crypto))->compareAndSwap('alice', str_repeat('a', 64), 1,
			[['owned_draft_id' => str_repeat('a', 32), 'account_id' => 7, 'revision' => 1, 'send_state' => 'uncertain']], 1);
	}

	private function reader(array $row): array {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$builder = $this->createMock(IQueryBuilder::class);
		$builder->method('select')->willReturnSelf();
		$builder->method('from')->willReturnSelf();
		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturn('actor_uid = ?');
		$builder->method('expr')->willReturn($expression);
		$builder->method('where')->willReturnSelf();
		$builder->method('createNamedParameter')->willReturnArgument(0);
		$builder->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($builder);
		$crypto = $this->createMock(ICrypto::class);
		return [$db, $crypto];
	}
}
