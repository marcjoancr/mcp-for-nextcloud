<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Dependencies;

use OCA\Deck\Db\{Acl, Board, Card, CardMapper, Stack, StackMapper};
use OCA\Deck\Service\{BoardService, CardService, PermissionService};
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Modules\Deck\Dependencies\NativeDependencies;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyChange;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyReading;
use PHPUnit\Framework\Attributes\DataProvider;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DependencyWriteGatewayTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\CapabilityNativeFixtures;
	use \OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
	private array $services;
	private NativeDependencies $gateway;
	private array $edges = [];
	private array $fields = [];
	private array $hidden = [];
	private bool $missing = false;
	private bool $edit = true;
	private bool $uncertain = false;
	private int $cardReads = 0;
	private int $readChecks = 0;

	protected function setUp(): void {
		foreach ([CardMapper::class, StackMapper::class, CardService::class, BoardService::class, PermissionService::class, \OCP\IUserManager::class] as $class) {
			$this->services[$class] = $this->createMock($class);
		}
		$this->services[CardMapper::class]->method('find')->willReturnCallback(function ($id): Card {
			$this->cardReads++;
			if ($this->uncertain && $id === 3) { throw new \RuntimeException('uncertain database read'); }
			return new Card(($this->fields[$id] ?? []) + ['id' => $id, 'stackId' => 10, 'title' => 'Card ' . $id]);
		});
		$this->services[CardMapper::class]->method('findBoardId')->willReturn(20);
		$this->services[CardMapper::class]->method('findDependenciesForCards')->willReturnCallback(
			fn (array $ids): array => [$ids[0] => $this->edges[$ids[0]] ?? []]);
		$this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
		$this->services[BoardService::class]->method('findAll')->willReturn([new Board(['id' => 20, 'owner' => 'alice', 'title' => 'Board'])]);
		$this->services[PermissionService::class]->method('checkPermission')->willReturnCallback(function ($mapper, $id, $permission): bool {
			if ($permission === Acl::PERMISSION_READ) { $this->readChecks++; }
			if (in_array($id, $this->hidden, true)) {
				if ($this->missing) { throw new \OCP\AppFramework\Db\DoesNotExistException('missing'); }
				throw new NoPermissionException('private');
			}
			if (!$this->edit && $permission === Acl::PERMISSION_EDIT) { throw new NoPermissionException('reader'); }
			return true;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn ($id) => $this->services[$id]);
		$this->gateway = $this->capabilityNative(NativeDependencies::class, $this->services);
	}

	public function testTraversalLoadsParentsOncePerOperationWithoutCardServiceFind(): void {
		$this->edges = [1 => [2, 3], 2 => [3]];
		$this->services[CardService::class]->expects(self::never())->method('find');
		$this->services[BoardService::class]->expects(self::exactly(2))->method('findAll')->with(-2, false, true);
		$this->services[StackMapper::class]->expects(self::exactly(2))->method('findAll')->with(20);
		for ($i = 0; $i < 2; $i++) {
			$graph = (new \OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph($this->gateway))->read('alice', 1);
			self::assertCount(3, $graph['nodes']);
		}
	}

	public static function readableBudgets(): iterable {
		yield '100 nodes including origin' => [100, 500, 20, 101];
		yield 'remaining edges' => [100, 2, 20, 4];
		yield 'shortest-path depth' => [100, 500, 0, 2];
		yield 'remaining nodes on later adjacency' => [100, 500, 20, 102, true];
		yield 'remaining edges on later adjacency' => [100, 3, 20, 6, true];
		yield 'remaining depth on later adjacency' => [100, 500, 1, 5, true];
	}

	#[DataProvider('readableBudgets')]
	public function testTraversalStopsNativeTargetLookupsAtReadableBudget(int $nodes, int $edges, int $depth, int $reads, bool $later = false): void {
		$this->edges = $later ? [1 => [2, 3], 2 => range(4, 10003)] : [1 => range(2, 10001)];
		try {
			(new DependencyGraph($this->gateway, $nodes, $edges, $depth))->read('alice', 1);
			self::fail('Oversized readable graph accepted');
		} catch (DeckRefusalException) {
			self::assertSame($reads, $this->cardReads, 'Native entities after the first readable overflow must not be resolved');
			self::assertSame($reads, $this->readChecks, 'Native READ checks must stop at the same visible boundary');
		}
	}

	public static function originOperations(): iterable {
		yield 'addition preview or revalidation' => [true, true];
		yield 'removal preview or revalidation' => [false, true];
		yield 'addition read-back' => [true, false];
		yield 'removal read-back' => [false, false];
	}

	#[DataProvider('originOperations')]
	public function testOversizedOriginSnapshotStopsNativeLookupsAndNeverWrites(bool $assign, bool $checkCycle): void {
		$this->edges = [1 => range(2, 10001)];
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		$this->services[CardService::class]->expects(self::never())->method('removeDependentCard');
		try {
			$this->gateway->dependencyChangeState('alice', 1, 2, $assign, $checkCycle);
			self::fail('Oversized origin snapshot accepted');
		} catch (DeckRefusalException) {
			self::assertSame(101, $this->cardReads);
			self::assertSame(101, $this->readChecks);
		}
	}

	public function testOriginAtNodeLimitReturnsCompleteSortedState(): void {
		$this->edges = [1 => array_merge(array_reverse(range(2, 100)), [2])];
		$state = $this->gateway->dependencyChangeState('alice', 1, 2, false);
		self::assertTrue($state['present']);
		self::assertSame(range(2, 100), array_column(DependencyChange::snapshot($state)['relations'], 'id'));
		self::assertCount(99, $state['origin']['card']->getDependentCards());
	}

	public static function overflowingAdditions(): iterable {
		yield 'preview' => [false];
		yield 'native write preflight' => [true];
	}

	#[DataProvider('overflowingAdditions')]
	public function testAdditionRefusesProposedOriginOverflowBeforePlanOrWrite(bool $write): void {
		$this->edges = [1 => range(2, 100)];
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		// Read-back observes current state without reserving a proposed absent relation.
		$observed = $this->gateway->dependencyChangeState('alice', 1, 101, true, false);
		self::assertFalse($observed['present']);
		self::assertNull($observed['graph']);
		$expected = DependencyChange::snapshot($observed);
		// The proposed target has no outgoing relations, so its cycle graph is one node.
		$expected['graph'] = ['nodes' => [$expected['target']], 'edges' => []];
		$this->expectException(DeckRefusalException::class);
		if ($write) {
			$this->gateway->changeDependency('alice', 1, 101, true, $expected);
		} else {
			$this->gateway->dependencyChangeState('alice', 1, 101, true);
		}
	}

	public static function boundaryVisibility(): iterable {
		yield 'readable relations only' => [false];
		yield 'hidden relations do not reserve budget' => [true];
	}

	#[DataProvider('boundaryVisibility')]
	public function testAdditionReachingExactOriginLimitCanBeWrittenAndReadBack(bool $hidden): void {
		$this->hidden = $hidden ? range(1000, 1599) : [];
		$this->edges = [1 => array_merge($this->hidden, range(2, 99))];
		$this->services[CardService::class]->expects(self::once())->method('assignDependentCard')->with(1, 100)
			->willReturnCallback(function (): Card { $this->edges[1][] = 100; return new Card(); });
		$state = $this->gateway->dependencyChangeState('alice', 1, 100, true);
		self::assertFalse($state['present']);
		$this->gateway->changeDependency('alice', 1, 100, true, DependencyChange::snapshot($state));
		$observed = $this->gateway->dependencyChangeState('alice', 1, 100, true, false);
		self::assertTrue($observed['present']);
		self::assertSame(range(2, 100), array_column(DependencyChange::snapshot($observed)['relations'], 'id'));
	}

	public function testExactOriginLimitAllowsAdditionNoopAndPresentOrAbsentRemoval(): void {
		$this->edges = [1 => range(2, 100)];
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		$this->services[CardService::class]->expects(self::once())->method('removeDependentCard')->with(1, 100)
			->willReturnCallback(function (): Card { $this->edges[1] = range(2, 99); return new Card(); });
		foreach ([[100, true], [101, false], [100, false]] as [$targetId, $assign]) {
			$state = $this->gateway->dependencyChangeState('alice', 1, $targetId, $assign);
			$this->gateway->changeDependency('alice', 1, $targetId, $assign, DependencyChange::snapshot($state));
			self::assertSame($assign, $this->gateway->dependencyChangeState('alice', 1, $targetId, $assign, false)['present']);
		}
		self::assertSame(range(2, 99), $this->edges[1]);
	}

	public function testProposedTargetReadIsRequiredBeforeOriginBudgetRefusal(): void {
		$this->edges = [1 => range(2, 100)];
		$this->hidden = [101];
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		$this->expectException(NoPermissionException::class);
		$this->gateway->dependencyChangeState('alice', 1, 101, true);
	}

	public function testHiddenFanoutDoesNotSpendReadableBudgetsOrChangePublicGraphAndSnapshot(): void {
		$this->edges = [1 => [2, 3]];
		$reader = new DependencyReading($this->gateway, $this->createMock(\Psr\Log\LoggerInterface::class), $this->formatterFactory($this->cardFormatter()));
		$public = $reader->handle(['cardId' => 1], 'alice');
		self::assertArrayNotHasKey('isError', $public);
		$state = DependencyChange::snapshot($this->gateway->dependencyChangeState('alice', 1, 2, false));
		$this->hidden = range(1000, 1599);
		$this->edges = [1 => array_merge($this->hidden, [3, 2, 2])];
		foreach ([false, true] as $missing) {
			$this->missing = $missing;
			self::assertSame($public, $reader->handle(['cardId' => 1], 'alice'));
			self::assertSame($state, DependencyChange::snapshot($this->gateway->dependencyChangeState('alice', 1, 2, false)));
		}
	}

	public function testSelfTwoAndThreeNodeCyclesNeverReachNativeWrite(): void {
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		foreach ([[1, 1, []], [1, 2, [2 => [1]]], [1, 2, [2 => [3], 3 => [1]]]] as [$a, $b, $edges]) {
			$this->edges = $edges;
			try { $this->gateway->dependencyChangeState('alice', $a, $b, true); self::fail('Cycle accepted'); }
			catch (DeckRefusalException $e) { self::assertStringContainsString('cycles', $e->getMessage()); }
		}
	}

	public function testHiddenAndMissingRelationsProduceByteIdenticalReadableState(): void {
		foreach (['alice', 'editor'] as $uid) {
            $session=$this->createMock(\OCP\ISession::class);$session->method('get')->willReturn($uid);
            $this->gateway=$this->capabilityNative(NativeDependencies::class,$this->services,$session);
			$this->edges = [1 => [99], 2 => [99], 99 => [1]];
			$this->hidden = [99];
			foreach ([false, true] as $missing) {
				$this->missing = $missing;
				$reader = new \OCA\Mcp\Modules\Deck\Dependencies\DependencyReading($this->gateway, $this->createMock(\Psr\Log\LoggerInterface::class), $this->formatterFactory($this->cardFormatter()));
				$public = $reader->handle(['cardId' => 1], $uid);
				$hidden = DependencyChange::snapshot($this->gateway->dependencyChangeState($uid, 1, 2, true));
				$this->edges = [];
				$absent = DependencyChange::snapshot($this->gateway->dependencyChangeState($uid, 1, 2, true));
				self::assertSame(json_encode($absent), json_encode($hidden));
				self::assertSame($public, $reader->handle(['cardId' => 1], $uid));
				$this->edges = [1 => [99], 2 => [99], 99 => [1]];
			}
		}
	}

	public function testTraversalBoundsRefuseBeforeWrite(): void {
		$this->edges = [];
		for ($i = 2; $i < 24; $i++) { $this->edges[$i] = [$i + 1]; }
		$this->expectException(DeckRefusalException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testUncertainTraversalAbortsWithoutNativeWrite(): void {
		$this->edges = [2 => [3]];
		$this->uncertain = true;
		$this->services[CardService::class]->expects(self::never())->method('assignDependentCard');
		$this->expectException(\RuntimeException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testReadOnlyOriginAndHiddenTargetCannotBeEditedOrBlindlyRemoved(): void {
		$this->services[CardService::class]->expects(self::never())->method('removeDependentCard');
		foreach ([false, true] as $assign) {
			$this->edit = false;
			try { $this->gateway->dependencyChangeState('alice', 1, 2, $assign); self::fail('Read-only write'); }
			catch (NoPermissionException) { self::assertTrue(true); }
			$this->edit = true;
			$this->hidden = [2];
			try { $this->gateway->dependencyChangeState('alice', 1, 2, $assign); self::fail('Blind write'); }
			catch (NoPermissionException) { self::assertTrue(true); }
			$this->hidden = [];
		}
	}

	public function testFreshSnapshotConflictAndActiveTargetAreRequired(): void {
		$before = DependencyChange::snapshot($this->gateway->dependencyChangeState('alice', 1, 2, true));
		$this->edges = [2 => [3]];
		try { $this->gateway->changeDependency('alice', 1, 2, true, $before); self::fail('Stale graph accepted'); }
		catch (DeckConflictException) { self::assertTrue(true); }
		$this->fields[2] = ['archived' => true];
		$this->expectException(DeckRefusalException::class);
		$this->gateway->dependencyChangeState('alice', 1, 2, true);
	}

	public function testOnlyRequestedNativeAssociationChangesAndNoopDoesNotWrite(): void {
		$this->edges = [1 => [3]];
		$this->services[CardService::class]->expects(self::once())->method('assignDependentCard')->with(1, 2)
			->willReturnCallback(function (): Card { $this->edges[1][] = 2; return new Card(); });
		$this->services[CardService::class]->expects(self::once())->method('removeDependentCard')->with(1, 2)
			->willReturnCallback(function (): Card { $this->edges[1] = [3]; return new Card(); });
		foreach ([true, true, false, false] as $assign) {
			$state = $this->gateway->dependencyChangeState('alice', 1, 2, $assign);
			$this->gateway->changeDependency('alice', 1, 2, $assign, DependencyChange::snapshot($state));
		}
		self::assertSame([3], $this->edges[1]);
	}
}
