<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Files\Linking;

use OCA\Deck\Service\BoardService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Modules\Deck\Recipients\BoardRecipientResolver;
use OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinkPreparation;
use OCA\Mcp\Modules\Deck\Files\CardFileNodeAccess;
use OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPlan;
use OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPolicy;
use OCA\Mcp\Modules\Deck\Files\CardFileAccess;
use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinking;
use OCA\Mcp\Modules\Deck\Files\Linking\NativeCardFileLink;
use OCA\Mcp\Modules\Deck\Recipients\RecipientDirectory;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../../../../../Support/Deck/Stubs/deck_stubs.php';

/**
 * Real preparation, policy, existing recipient/HMAC and handler pipeline with controlled native I/O.
 */
final class CardFileLinkTest extends TestCase {
	use \OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;

	/**
	 * Mutable native observations, never application decisions or replacement policy logic.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = [];

	/**
	 * Real tool handler under test.
	 *
	 * @var CardFileLinking
	 */
	private CardFileLinking $handler;

	/**
	 * Number of native creation attempts, including a failure before or after persistence.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Compose the full real pipeline with explicit native reads and effects.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->state = ['visible' => true, 'readable' => true, 'shareable' => true, 'folder' => false,
			'privilegeViolations'=>[], 'checkIntermediate'=>false, 'intermediateChecks'=>[], 'sessionResets'=>0, 'failReset'=>0, 'warmNative'=>false, 'permissions'=>17, 'etag' => 'v1', 'owner' => 'alice', 'boardOwner' => 'alice', 'source' => true,
			'guest' => false, 'guestsEnabled' => false, 'session' => 'alice', 'guestError' => false,
			'enabled' => true, 'disabled' => false, 'groupOnly' => false, 'allowGroups' => true,
			'defaultExpiry' => false, 'enforcedExpiry' => false, 'denied' => '', 'raw' => [],
			'scope' => null, 'tags' => [], 'acls' => [], 'present' => false, 'throw' => '', 'observeError' => false, 'observedId' => '15', 'observedBits' => 1, 'policyError' => '', 'interleave' => false, 'events' => []];
		$gateway = $this->createMock(CardFileAccess::class);
		$gateway->method('cardFileContext')->willReturnCallback(function ($uid, $cardId): array {
			if (!$this->state['source'] || $uid !== 'alice' || $cardId !== 7) {
				throw new RuntimeException('private source detail');
			}
			$this->state['warmNative'] = true;
			return ['card' => $this->card(['id' => 7, 'title' => 'Source', 'stackId' => 2]), 'boardId' => 20,
				'board' => $this->ownershipOf($this->state['boardOwner'])];
		});
		$grants = $this->createMock(GrantPolicy::class);
		$grants->method('within')->willReturnCallback(static fn ($uid, $work) => $work());
		$grants->method('granted')->willReturnCallback(fn ($uid, $module, $operation): bool => $this->state['denied'] !== "$module.$operation");
		$grants->method('scopeItems')->willReturnCallback(fn (): ?array => $this->state['scope']);
		$guard = $this->createMock(FilesAccessGuard::class);
		$guard->method('isVisible')->willReturnCallback(function (): bool {
			$this->state['events'][] = 'file';
            $this->assertUnprivileged('file');
			return $this->state['visible'];
		});
		$guard->method('getHiddenTagIds')->willReturnCallback(fn (): array => $this->state['tags']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturnCallback(fn (): string => $this->state['owner']);
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(99);
		$node->method('getName')->willReturn('visible.txt');
		$node->method('getPath')->willReturn('/alice/files/visible.txt');
		$node->method('getSize')->willReturn(12);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getEtag')->willReturnCallback(fn (): string => $this->state['etag']);
		$node->method('getPermissions')->willReturnCallback(fn():int=>$this->state['permissions']);
		$node->method('isReadable')->willReturnCallback(fn (): bool => $this->state['readable']);
		$node->method('isShareable')->willReturnCallback(fn (): bool => $this->state['shareable']);
		foreach (['delete', 'move', 'copy', 'putContent'] as $method) {
			$node->expects(self::never())->method($method);
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('getRelativePath')->willReturn('/visible.txt');
		$folder->method('getById')->willReturnCallback(fn ($id): array => $id === 99 ? [$this->state['folder'] ? $folder : $node] : []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn ($uid) => $uid === 'alice' ? $user : null);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->state['session'] === 'alice' ? $user : null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(fn (): bool => $this->state['guestsEnabled']);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('unit-secret');
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => $this->state['raw'][$key] ?? $default);
		$shares = $this->createMock(IManager::class);
		foreach (['shareApiEnabled' => 'enabled', 'sharingDisabledForUser' => 'disabled',
			'shareWithGroupMembersOnly' => 'groupOnly', 'allowGroupSharing' => 'allowGroups',
			'shareApiInternalDefaultExpireDate' => 'defaultExpiry', 'shareApiInternalDefaultExpireDateEnforced' => 'enforcedExpiry'] as $method => $field) {
			$shares->method($method)->willReturnCallback(function () use ($method, $field): bool {
				$this->state['events'][] = 'policy';
                    $this->assertUnprivileged('policy');
				if ($this->state['policyError'] === $method) {
					throw new RuntimeException('private policy detail');
				}
				return $this->state[$field];
			});
		}
		$shares->method('getSharesBy')->willReturn([]);
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setShareType', 'setSharedWith', 'setSharedBy', 'setPermissions'] as $method) {
			$share->method($method)->willReturnSelf();
		}
		$share->method('getId')->willReturn('15');
		$share->method('getNodeId')->willReturn(99);
		$share->method('getSharedBy')->willReturn('alice');
		$share->method('getSharedWith')->willReturn('7');
		$share->method('getShareType')->willReturn(IShare::TYPE_DECK);
		$share->method('getPermissions')->willReturn(1);
		$shares->method('newShare')->willReturn($share);
		$shares->method('createShare')->willReturnCallback(function () use ($share): IShare {
			$this->assertUnprivileged('native-create');
            $this->writes++;
			if ($this->state['throw'] === 'before') {
				throw new RuntimeException('private failure detail');
			}
			$this->state['present'] = true;
			if ($this->state['interleave']) {
				$this->state['groupOnly'] = true;
			}
			if ($this->state['throw'] === 'removed-after') { $this->state['present'] = false; throw new RuntimeException('Native hook removed persisted association before lost response'); }
			if ($this->state['throw'] === 'after') {
				throw new RuntimeException('lost native response');
			}
			if ($this->state['throw'] === 'source-hidden') { $this->state['source'] = false; }
			if ($this->state['throw'] === 'hidden') {
				$this->state['visible'] = false;
			}
			return $share;
		});
		$observed = $this->createMock(IShare::class);
		$observed->method('getId')->willReturnCallback(fn (): string => $this->state['observedId']);
		$observed->method('getNodeId')->willReturn(99);
		$observed->method('getSharedBy')->willReturn('alice');
		$observed->method('getSharedWith')->willReturn('7');
		$observed->method('getShareType')->willReturn(IShare::TYPE_DECK);
		$observed->method('getPermissions')->willReturnCallback(fn (): int => $this->state['observedBits']);
		$provider = $this->createMock(DeckShareProvider::class);
		$provider->method('getSharesByPath')->willReturnCallback(function () use ($observed): array {
			if ($this->writes > 0 && $this->state['observeError']) {
				throw new RuntimeException('private observation detail');
			}
			return $this->state['present'] ? [$observed] : [];
		});
		$boards = $this->createMock(BoardService::class);
		$guest = new class(fn (): bool => $this->state['guest'], fn (): bool => $this->state['guestError']) {
			/**
			 * Bind controlled native identity results.
			 *
			 * @param \Closure $guest Native Guest status.
			 * @param \Closure $error Native identity service failure.
			 * @return void
			 */
			public function __construct(private \Closure $guest, private \Closure $error) {}

			/**
			 * Read explicit Guest status without relying on policy overrides.
			 *
			 * @param IUser|string|null $user Native actor supplied by the policy guard.
			 * @return bool Native Guest status.
			 */
			public function isGuest(IUser|string|null $user = null): bool {
				if (($this->error)()) {
					throw new RuntimeException('private guest detail');
				}
				return ($this->guest)();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn ($class) => match ($class) {
			BoardService::class => $boards, DeckShareProvider::class => $provider, 'OCA\\Guests\\GuestManager' => $guest,
			default => throw new RuntimeException('Unexpected service'),
		});
		$nativeSession = $this->createMock(\OCP\ISession::class);
		$nativeSession->method('get')->willReturn('alice');
		$services = new DeckCaller(static fn()=> $boards, $nativeSession);
		$native = new NativeCardFileLink($shares, $services, static fn()=> $provider);
		$directory = $this->createMock(RecipientDirectory::class);
		$directory->method('acls')->willReturnCallback(function (): array {
			$this->state['events'][] = 'recipients';
			return $this->state['acls'];
		});
		$directory->method('user')->willReturnCallback(function (string $uid): IUser {
			$recipient = $this->createMock(IUser::class);
			$recipient->method('getUID')->willReturn($uid);
			$recipient->method('getDisplayName')->willReturn($uid);
			return $recipient;
		});
		$directory->method('group')->willReturn([]);
		$directory->method('circle')->willReturn([]);
		$plans = new PlanState($config);
		$logger = $this->createMock(LoggerInterface::class);
        $clear = function ():void { $this->state['warmNative'] = false; };
		$recipients = new BoardRecipientResolver($gateway->cardFileContext(...), $grants, $directory, $plans, $logger, $clear);
		$policies = new CardLinkPolicy($shares, $config, $apps, $users, $session, static fn()=> $guest);
		$ownership = $this->createMock(NodeAccessInfo::class);
		$ownership->method('describe')->willReturnCallback(fn (): array => ['scope' => $this->state['owner'] === 'alice' ? NodeAccessInfo::PERSONAL : NodeAccessInfo::SHARED,
			'owner' => $this->state['owner'], 'ownerDisplayName' => $this->state['owner'], 'sharedBy' => $this->state['owner']]);
		$shared = new SharedWriteGuard($ownership);
		$prepare = new CardFileLinkPreparation($gateway->cardFileContext(...), $grants, $guard, new CardFileNodeAccess($guard, $root), $policies, $native, $ownership, $recipients, $clear);
		$this->handler = new CardFileLinking($logger,
			static fn (): array => ['prepare' => $prepare, 'native' => $native, 'plans' => new CardLinkPlan($plans), 'shared' => $shared], function (): void {
                $this->state['sessionResets']++;
                if ($this->state['sessionResets'] === $this->state['failReset']) { throw new RuntimeException('private reset failure'); }
                $this->state['warmNative'] = false;
            });
	}

	/**
	 * Generate an original preview and its matching confirmed arguments.
	 *
	 * @return array<string, mixed> State-bound confirmation for the controlled visible file.
	 */
    private function assertUnprivileged(string $stage): void {
        if ($this->state['checkIntermediate']) {
            $this->state['intermediateChecks'][] = $stage;
            if ($this->state['warmNative']) { $this->state['privilegeViolations'][] = $stage; }
        }
    }

    public function testPlainTargetWithUnrelatedCircleBoardNeverAuthorizesOrCreatesPrivileged(): void {
        $this->state['checkIntermediate'] = true;
        $arguments = $this->confirmed();
        self::assertTrue($this->payload($this->handler->handle($arguments, 'alice'))['confirmed']);
        self::assertContains('file', $this->state['intermediateChecks']);
        self::assertContains('policy', $this->state['intermediateChecks']);
        self::assertContains('native-create', $this->state['intermediateChecks']);
        self::assertSame([], $this->state['privilegeViolations'], 'No intermediate authorization or native create may inherit Circle privilege');
    }

    public function testIntermediatePrivilegeIsClearedBeforeDeniedFileGuestOrCorePolicy(): void {
        foreach (['file', 'guest', 'policy'] as $denial) {
            $this->state['checkIntermediate'] = true;
            $this->state['visible'] = $denial !== 'file';
            $this->state['guestsEnabled'] = $denial === 'guest';
            $this->state['guest'] = $denial === 'guest';
            $this->state['groupOnly'] = $denial === 'policy';
            self::assertTrue($this->handler->handle(['cardId'=>7,'fileId'=>99], 'alice')['isError']);
            self::assertSame(0, $this->writes);
            self::assertFalse($this->state['warmNative']);
            self::assertSame([], $this->state['privilegeViolations'], $denial . ' admission ran privileged');
        }
    }

    public function testPersistRemoveAndThrowRemainsUncertainWhenNoAssociationCanBeObserved(): void {
        $args = $this->confirmed();
        $this->state['throw'] = 'removed-after';
        $reply = $this->handler->handle($args, 'alice');
        self::assertArrayNotHasKey('isError', $reply);
        $result = $this->payload($reply);
        self::assertFalse($result['confirmed']);
        self::assertArrayNotHasKey('file', $result);
        self::assertArrayNotHasKey('card', $result);
        self::assertSame(1, $this->writes);
        self::assertFalse($this->state['present']);
    }

    public function testWholePreviewAndExecutionDropNativePrivilegeAfterReadback(): void {
        $args = $this->confirmed();
        self::assertFalse($this->state['warmNative'], 'Preview must clear privilege left by source reads');
        self::assertSame(2, $this->state['sessionResets']);
        self::assertTrue($this->payload($this->handler->handle($args, 'alice'))['confirmed']);
        self::assertFalse($this->state['warmNative'], 'Final native readback must not leave privilege active');
        self::assertSame(4, $this->state['sessionResets']);
    }

    public function testNativeSessionResetFailurePreventsWriteOrReturnsUncertainAfterPersistence(): void {
        $args = $this->confirmed();
        $this->state['failReset'] = 3;
        self::assertTrue($this->handler->handle($args, 'alice')['isError']);
        self::assertSame(0, $this->writes);
        $this->state['failReset'] = 6;
        $result = $this->payload($this->handler->handle($args, 'alice'));
        self::assertFalse($result['confirmed']);
        self::assertArrayNotHasKey('file', $result);
        self::assertTrue($this->state['present']);
        self::assertSame(1, $this->writes);
    }

	private function confirmed(): array {
		$args = ['cardId' => 7, 'fileId' => 99];
		return $args + ['confirm' => true, 'plan_state' => $this->handler->preview($args, 'alice')['plan_state']];
	}

	/**
	 * Prove preview is read-only and a fresh matching confirmation creates exactly once.
	 *
	 * @return void
	 */
	public function testPreviewConfirmAndDuplicateAreDistinct(): void {
		$args = $this->confirmed();
		self::assertSame(0, $this->writes);
		$preview = $this->payload($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice'));
		self::assertTrue($preview['requiresConfirmation']);
		self::assertSame(1, $preview['recipients']['total']);
		self::assertArrayNotHasKey('acls', $preview);
		self::assertSame(0, $this->writes);
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertTrue($result['confirmed']);
		self::assertSame(1, $result['permissions']);
		self::assertSame(1, $this->writes);
		self::assertTrue($this->handler->handle($args, 'alice')['isError']);
		self::assertSame(1, $this->writes);
	}

	/**
	 * Isolate each strict denial and native visibility prerequisite before creation.
	 *
	 * @return iterable<string, array{string, mixed}> One changed native observation per case.
	 */
	public static function denials(): iterable {
		foreach (['visible', 'readable', 'shareable', 'source', 'enabled'] as $field) {
			yield $field => [$field, false];
		}
		foreach (['folder', 'disabled', 'groupOnly', 'enforcedExpiry'] as $field) {
			yield $field => [$field, true];
		}
		foreach (['deck.edit', 'files.read', 'files.share'] as $grant) {
			yield $grant => ['denied', $grant];
		}
		yield 'session mismatch' => ['session', 'other'];
	}

	/**
	 * Assert each denial blocks a previously signed confirmation without writing or exposing native detail.
	 *
	 * @param string $field Native state to change.
	 * @param mixed $value Denied observation.
	 * @return void
	 */
	#[DataProvider('denials')]
	public function testEveryDenialPreventsCreation(string $field, mixed $value): void {
		$args = $this->confirmed();
		$this->state[$field] = $value;
		$reply = $this->handler->handle($args, 'alice');
		self::assertTrue($reply['isError']);
		self::assertStringNotContainsString('private', json_encode($reply));
		self::assertSame(0, $this->writes);
	}

	/**
	 * Guests are always refused even without group-only overrides; absence and identification failure differ.
	 *
	 * @return void
	 */
	public function testExplicitGuestIdentityIsUnconditional(): void {
		foreach ([false, true] as $hideUsers) {
			$this->state['guestsEnabled'] = true;
			$this->state['guest'] = true;
			$this->state['groupOnly'] = $hideUsers;
			self::assertTrue($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice')['isError']);
		}
		$this->state['guest'] = false;
		$this->state['groupOnly'] = false;
		$this->state['guestError'] = true;
		self::assertTrue($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice')['isError']);
		$this->state['guestsEnabled'] = false;
		self::assertTrue($this->payload($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice'))['requiresConfirmation']);
		self::assertSame(0, $this->writes);
	}

	/**
	 * Group and circle structures are denied under allow_group_sharing=no, including empty audiences.
	 *
	 * @return void
	 */
	public function testGroupPolicyUsesStructuralAclNotCurrentCount(): void {
		$this->state['allowGroups'] = false;
		foreach ([1, 7, 6] as $type) {
			$this->state['acls'] = [['type' => $type, 'participant' => 'empty', 'edit' => false, 'share' => false, 'manage' => false]];
			self::assertTrue($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice')['isError']);
		}
		$this->state['acls'] = [];
		self::assertTrue($this->payload($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice'))['requiresConfirmation']);
		self::assertSame(0, $this->writes);
	}

	/**
	 * A permitted but changed scope, tag policy, etag or ineffective policy flag invalidates the prior HMAC.
	 *
	 * @return void
	 */
	public function testAllowedStateChangesRequireANewPlan(): void {
		foreach (['permissions'=>19, 'acls'=>[['type'=>0,'participant'=>'bob','edit'=>false,'share'=>false,'manage'=>false]], 'etag' => 'v2', 'scope' => ['new'], 'tags' => [8], 'raw' => ['shareapi_enforce_internal_expire_date' => 'yes']] as $field => $value) {
			$before = $this->state[$field];
			$args = $this->confirmed();
			$this->state[$field] = $value;
			try {
				$this->handler->handle($args, 'alice');
				self::fail('A changed snapshot needs a new plan');
			} catch (PlanChanged $changed) {
				self::assertNotSame($args['plan_state'], $changed->plan['plan_state']);
			}
			$this->state[$field] = $before;
		}
		self::assertSame(0, $this->writes);
	}

	/**
	 * Reshare-off blocks a received file even when its native SHARE bit remains available.
	 *
	 * @return void
	 */
	public function testReshareOffIsNotInferredOnlyFromPermissions(): void {
		$this->state['owner'] = 'bob';
		$this->state['raw']['shareapi_allow_resharing'] = 'no';
		self::assertTrue($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice')['isError']);
		self::assertSame(0, $this->writes);
	}

	/**
	 * A post-write row without a returned creation ID is uncertain, never attributed by likeness.
	 *
	 * @return void
	 */
	public function testLostResponseAndUnreadableResultNeverEncourageRecreation(): void {
		$args = $this->confirmed();
		$this->state['throw'] = 'after';
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertFalse($result['confirmed']);
		self::assertArrayNotHasKey('file', $result);
		self::assertSame(1, $this->writes);
		self::assertTrue($this->handler->handle($args, 'alice')['isError']);
		self::assertSame(1, $this->writes);
	}

	/**
	 * A clean native failure remains an error; an unreadable or hidden post-write state is uncertain.
	 *
	 * @return void
	 */
	public function testFailureBeforeWriteAndVisibilityLossHaveDifferentOutcomes(): void {
		$args = $this->confirmed();
		$this->state['throw'] = 'before';
		self::assertFalse($this->payload($this->handler->handle($args, 'alice'))['confirmed']);
		self::assertFalse($this->state['present']);
		$this->state['throw'] = 'hidden';
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertFalse($result['confirmed']);
		self::assertArrayNotHasKey('file', $result);
	}

	/**
	 * Observation identity or permissions cannot be inferred from the successful creation response alone.
	 *
	 * @return void
	 */
    public function testSourceReadLossAfterPersistenceReturnsUncertainWithoutStaleMetadata(): void {
        $args = $this->confirmed();
        $this->state['throw'] = 'source-hidden';
        $result = $this->payload($this->handler->handle($args, 'alice'));
        self::assertFalse($result['confirmed']);
        self::assertArrayNotHasKey('file', $result);
        self::assertArrayNotHasKey('card', $result);
        self::assertTrue($this->state['present']);
        self::assertSame(1, $this->writes);
    }

	public function testReturnedIdAndObservedReadOnlyIdentityMustAgree(): void {
		$args = $this->confirmed();
		$this->state['observedId'] = '16';
		self::assertFalse($this->payload($this->handler->handle($args, 'alice'))['confirmed']);
		self::assertSame(1, $this->writes);
	}

	/**
	 * Unreadable native post-write state never emits pre-write metadata or an optimistic success.
	 *
	 * @return void
	 */
	public function testUnreadableObservationIsExplicitlyUncertain(): void {
		$args = $this->confirmed();
		$this->state['observeError'] = true;
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertFalse($result['confirmed']);
		self::assertArrayNotHasKey('file', $result);
		self::assertSame(1, $this->writes);
	}

	/**
	 * Cross-user or cross-target replay cannot create a link from a valid original HMAC.
	 *
	 * @return void
	 */
	public function testReplayAndMissingStateCannotWrite(): void {
		$args = $this->confirmed();
		self::assertTrue($this->handler->handle($args, 'bob')['isError']);
		foreach (['cardId', 'fileId'] as $field) {
			$bad = $args;
			$bad[$field]++;
			self::assertTrue($this->handler->handle($bad, 'alice')['isError']);
		}
		unset($args['plan_state']);
		try {
			$this->handler->handle($args, 'alice');
			self::fail('A confirm without state must be refused');
		} catch (\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException) {
			self::assertSame(0, $this->writes);
		}
	}

	/**
	 * The shared-board flag never substitutes for the signed plan, grants or explicit confirmation.
	 *
	 * @return void
	 */
	public function testSharedBoardStillNeedsBothConfirmationsAndState(): void {
		$this->state['boardOwner'] = 'bob';
		$args = $this->confirmed();
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertSame('shared', $result['scope']);
		self::assertSame(0, $this->writes);
		self::assertTrue($this->payload($this->handler->handle($args + ['confirm_shared' => true], 'alice'))['confirmed']);
		self::assertSame(1, $this->writes);
	}

	/**
	 * Captured ownership classification requires file confirmation without imposing UPDATE on READ+SHARE.
	 *
	 * @return void
	 */
	public function testSharedFileOnOwnBoardRequiresTheExistingSharedGate(): void {
		$this->state['owner'] = 'bob';
		$args = $this->confirmed();
		$result = $this->payload($this->handler->handle($args, 'alice'));
		self::assertSame('shared', $result['scope']);
		self::assertSame('/visible.txt', $result['resource']);
		self::assertSame(0, $this->writes);
		self::assertTrue($this->payload($this->handler->handle($args + ['confirm_shared' => true], 'alice'))['confirmed']);
		self::assertSame(1, $this->writes);
	}

	/**
	 * Recipient expansion cannot precede Files/strict policy authorization, including explicit Guest denial.
	 *
	 * @return void
	 */
	public function testAuthorizationOrderPrecedesNativeCircleSideEffects(): void {
		$this->confirmed();
		self::assertLessThan(array_search('recipients', $this->state['events'], true), array_search('file', $this->state['events'], true));
		self::assertLessThan(array_search('recipients', $this->state['events'], true), array_search('policy', $this->state['events'], true));
		$this->state['events'] = [];
		$this->state['guestsEnabled'] = true;
		$this->state['guest'] = true;
		self::assertTrue($this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice')['isError']);
		self::assertNotContains('recipients', $this->state['events']);
	}

	/**
	 * Every unreadable core getter fails closed before recipient expansion or mutation.
	 *
	 * @return void
	 */
	public function testUnreadableCorePolicyIsNeverTreatedAsDisabledRestriction(): void {
		foreach (['shareApiEnabled', 'sharingDisabledForUser', 'shareWithGroupMembersOnly',
			'allowGroupSharing', 'shareApiInternalDefaultExpireDate', 'shareApiInternalDefaultExpireDateEnforced'] as $method) {
			$this->state['policyError'] = $method;
			$this->state['events'] = [];
			$result = $this->handler->handle(['cardId' => 7, 'fileId' => 99], 'alice');
			self::assertTrue($result['isError']);
			self::assertStringNotContainsString('private', json_encode($result));
			self::assertNotContains('recipients', $this->state['events']);
		}
		self::assertSame(0, $this->writes);
	}

	/**
	 * Model the declared gap after the final snapshot: no test claims a native atomic policy transaction.
	 *
	 * This is a controlled boundary interleaving, not a proof of a core database race or external refresh.
	 *
	 * @return void
	 */
	public function testPolicyChangeInsideNativeCreateRemainsADeclaredWindow(): void {
		$args = $this->confirmed();
		$this->state['interleave'] = true;
		self::assertTrue($this->payload($this->handler->handle($args, 'alice'))['confirmed']);
		self::assertTrue($this->state['groupOnly']);
		self::assertSame(1, $this->writes);
	}

}
