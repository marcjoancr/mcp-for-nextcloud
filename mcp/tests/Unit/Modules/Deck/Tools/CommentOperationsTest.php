<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Modules\Deck\CommentAccess;
use OCA\Mcp\Modules\Deck\CommentOperations;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CommentOperationsTest extends TestCase {
	private function operations(CommentAccess $access): CommentOperations {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn('test-instance-secret');
		return new CommentOperations($access, $this->createMock(LoggerInterface::class), new PlanState($config));
	}

	/** @return array<string,mixed> */
	private function state(array $overrides = []): array {
		return $overrides + [
			'card' => ['id' => 7, 'boardId' => 3, 'stackId' => 4, 'title' => 'Planning', 'url' => '/deck/3/7'],
			'board' => ['owner'=>'alice','ownerDisplayName'=>'Alice','name'=>'Team board','shared'=>false],
			'recipientHash' => 'opaque-audience-hash',
			'commentIds' => [10, 11],
		];
	}

	public function testPreviewKeepsVerbatimMessageAndDoesNotExposeBoardAudience(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->expects(self::once())->method('commentContext')->with('alice', 7)->willReturn($this->state());
		$plan = $this->operations($access)->preview(['cardId' => 7, 'message' => "**Keep** `exact`\nsecond line"], 'alice');
		self::assertSame("**Keep** `exact`\nsecond line", $plan['commentText']);
		self::assertArrayNotHasKey('recipients', $plan);
		self::assertArrayNotHasKey('boardOwner', $plan);
		self::assertArrayHasKey(PlanState::ARGUMENT, $plan);
	}

	public function testMissingConfirmReturnsPlanWithoutCreating(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturn($this->state());
		$access->expects(self::never())->method('createComment');
		$result = $this->operations($access)->handle(['cardId' => 7, 'message' => 'Exact text'], 'alice');
		self::assertTrue($result['requiresConfirmation']);
		self::assertSame('Exact text', $result['commentText']);
	}

	public function testMatchingConfirmedPlanCreatesCommentOnce(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturn($this->state());
		$preview = $this->operations($access)->preview(['cardId' => 7, 'message' => 'Exact text'], 'alice');
		$access->expects(self::once())->method('createComment')->with('alice', 7, 'Exact text')->willReturn([
			'id' => 12, 'cardId' => 7, 'message' => 'Exact text', 'actorId' => 'alice', 'actorType' => 'users',
			'actorDisplayName' => 'Alice', 'creationDateTime' => '2026-01-01T12:00:00+00:00',
		]);
		$result = $this->operations($access)->handle([
			'cardId' => 7, 'message' => 'Exact text', 'confirm' => true, PlanState::ARGUMENT => $preview[PlanState::ARGUMENT],
		], 'alice');
		self::assertTrue($result['confirmed']);
		self::assertSame(12, $result['comment']['id']);
	}

	public function testStateChangedSincePreviewRefusesWithoutCreating(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturnOnConsecutiveCalls($this->state(), $this->state(['commentIds' => [11, 12]]));
		$operations = $this->operations($access);
		$preview = $operations->preview(['cardId' => 7, 'message' => 'Exact text'], 'alice');
		$access->expects(self::never())->method('createComment');
		$this->expectException(PlanChanged::class);
		$operations->handle(['cardId' => 7, 'message' => 'Exact text', 'confirm' => true,
			PlanState::ARGUMENT => $preview[PlanState::ARGUMENT]], 'alice');
	}

	public function testSharedCardRequiresConfirmSharedBeforeCreating(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturn($this->state(['board' => ['owner'=>'pedro','ownerDisplayName'=>'Pedro','name'=>'Team board','shared'=>true]]));
		$access->expects(self::never())->method('createComment');
		$operations = $this->operations($access);
		$preview = $operations->preview(['cardId' => 7, 'message' => 'Exact text'], 'alice');
		$result = $operations->handle(['cardId' => 7, 'message' => 'Exact text', 'confirm' => true,
			PlanState::ARGUMENT => $preview[PlanState::ARGUMENT]], 'alice');
		self::assertTrue($result['requiresConfirmation']);
		self::assertSame('shared', $result['scope']);
	}

	public function testOwnerOfAclSharedBoardAlsoRequiresSharedConfirmation(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturn($this->state(['board' => [
			'owner'=>'alice','ownerDisplayName'=>'Alice','name'=>'Team board','shared'=>true,
		]]));
		$access->expects(self::never())->method('createComment');
		$operations = $this->operations($access);
		$preview = $operations->preview(['cardId'=>7,'message'=>'Exact text'],'alice');
		$result = $operations->handle(['cardId'=>7,'message'=>'Exact text','confirm'=>true,
			PlanState::ARGUMENT=>$preview[PlanState::ARGUMENT]],'alice');
		self::assertTrue($result['requiresConfirmation']);
		self::assertSame('shared',$result['scope']);
	}

	public function testPostSaveFailureReturnsUncertainAndDoesNotAutomaticallyRetry(): void {
		$access = $this->createMock(CommentAccess::class);
		$access->method('commentContext')->willReturnOnConsecutiveCalls($this->state(), $this->state(), $this->state(['commentIds' => [10, 11, 12]]));
		$access->method('listComments')->willReturn([
			['id' => 12, 'cardId' => 7, 'message' => 'Exact text', 'actorId' => 'alice', 'actorType' => 'users'],
		]);
		$access->expects(self::once())->method('createComment')->with('alice', 7, 'Exact text')->willThrowException(new \RuntimeException('post-save failure'));
		$operations = $this->operations($access);
		$preview = $operations->preview(['cardId' => 7, 'message' => 'Exact text'], 'alice');
		$result = $operations->handle(['cardId' => 7, 'message' => 'Exact text', 'confirm' => true,
			PlanState::ARGUMENT => $preview[PlanState::ARGUMENT]], 'alice');
		self::assertFalse($result['confirmed']);
		self::assertTrue($result['writeObserved']);
		self::assertSame(12, $result['comment']['id']);
	}
}
