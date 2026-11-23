<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\People;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\People\Circles\CirclesDirectory;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Profile\IProfileManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TeamMembershipToolsTest extends TestCase
{
    /** @return array{CirclesDirectory, object, array<string,IUser>} */
    private function setupDirectory(array $profileRows = [], int $actorLevel = 9, array $visibleIds = ['alice', 'bea', 'ben', 'owner']): array
    {
        $users = [];
        foreach (['alice', 'bea', 'ben', 'owner'] as $uid) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            $users[$uid] = $user;
        }
        $member = static fn (string $uid, string $id, int $level): object => new class($uid, $id, $level) {
            public function __construct(private string $uid, private string $id, private int $level) {}
            public function getUserType(): int { return 1; }
            public function getUserId(): string { return $this->uid; }
            public function getDisplayName(): string { return ucfirst($this->uid); }
            public function getLevel(): int { return $this->level; }
            public function isLocal(): bool { return true; }
            public function getId(): string { return $this->id; }
            public function getStatus(): string { return 'Member'; }
        };
        $circle = new class($member('alice', 'member-alice', $actorLevel), [$member('alice', 'member-alice', $actorLevel), $member('bea', 'member-bea', 4), $member('ben', 'member-ben', 1)]) {
            public int $config = 0;
            public array $members;
            public function __construct(private object $initiator, array $members) { $this->members = $members; }
            public function getSingleId(): string { return 'team-1'; }
            public function getName(): string { return 'Team one'; }
            public function getDisplayName(): string { return 'Team One'; }
            public function isLocal(): bool { return true; }
            public function getInitiator(): object { return $this->initiator; }
            public function getConfig(): int { return $this->config; }
            public function isConfig(int $flag): bool { return ($this->config & $flag) !== 0; }
            public function getMembers(): array { return $this->members; }
        };
        $manager = new class($circle, $member) {
            public int $addCalls = 0;
            public array $removed = [];
            public array $syncSessions = [];
            public function __construct(public object $circle, private \Closure $memberFactory) {}
            public function getLocalFederatedUser(string $uid): object { return (object)['uid' => $uid]; }
            public function startSession(object $user, bool $forceSync = false): void {
                $this->syncSessions[] = $forceSync;
            }
            public function stopSession(): void {}
            public function probeCircle(string $id, object $probe, object $dataProbe): object { return $this->circle; }
            public function addMember(string $circleId, object $user): object {
                ++$this->addCalls;
                $member = ($this->memberFactory)($user->uid, 'member-' . $user->uid, 1);
                $this->circle->members[] = $member;
                return $member;
            }
            public function removeMember(string $memberId): void {
                $this->removed[] = $memberId;
                $this->circle->members = array_values(array_filter($this->circle->members, static fn (object $member): bool => $member->getId() !== $memberId));
            }
        };
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($users['alice']);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
        $profiles = $this->createMock(IProfileManager::class);
        $profiles->method('isProfileEnabled')->willReturn(true);
        $profiles->method('getProfileFields')->willReturnCallback(static fn (IUser $user, IUser $viewer): array => $profileRows[$user->getUID()] ?? []);
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(static function (string $query, array $types, bool $lookup, int $limit, int $offset) use ($visibleIds): array {
            $users = in_array($query, $visibleIds, true) ? ['exact' => ['users' => [['value' => ['shareWith' => $query], 'label' => $query]]]] : [];
            return [$users, false];
        });
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('unit-test-secret');
        $directory = new CirclesDirectory(
            $apps,
            $userManager,
            $session,
            static fn (): object => $manager,
            static fn (): object => new class {
                public function filterHiddenCircles(): self { return $this; }
                public function filterSingleCircles(): self { return $this; }
                public function mustBeMember(): self { return $this; }
            },
            static fn (): object => new \stdClass(),
            $this->createMock(LoggerInterface::class),
            $profiles,
            new PlanState($config),
            $search,
        );
        return [$directory, $manager, $users];
    }

    public function testFiltersVisibleMembersByProfessionalFieldsAndKeepsTeamRoleDistinct(): void
    {
        [$directory] = $this->setupDirectory([
            'bea' => ['organisation' => 'Research', 'role' => 'Engineer', 'email' => 'hidden@example.test'],
            'ben' => ['organisation' => 'Research', 'role' => 'Designer'],
        ]);
        $result = $directory->filterMembers('alice', 'team-1', 'Research', 'engineer', 10);
        self::assertSame('Research', $result['filters']['organisation']);
        self::assertSame('engineer', $result['filters']['professional_role']);
        self::assertSame(3, $result['scanned']);
        self::assertFalse($result['truncated']);
        self::assertSame([[
            'type' => 'user', 'uid' => 'bea', 'displayName' => 'Bea', 'teamRole' => 'moderator',
            'organisation' => 'Research', 'professionalRole' => 'Engineer',
        ]], $result['members']);
        self::assertStringNotContainsString('hidden@example.test', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testFilterMarksItsOwnOutputLimitAsTruncated(): void
    {
        [$directory] = $this->setupDirectory([
            'bea' => ['organisation' => 'Research'],
            'ben' => ['organisation' => 'Research'],
        ]);
        $result = $directory->filterMembers('alice', 'team-1', 'Research', '', 1);
        self::assertCount(1, $result['members']);
        self::assertTrue($result['truncated']);
    }

    public function testAddPreviewRequiresExplicitApprovalAndCallsNativeActorContextApi(): void
    {
        [$directory, $manager] = $this->setupDirectory();
        $args = ['team_id' => 'team-1', 'user_id' => 'owner'];
        $plan = $directory->previewAddMember($args, 'alice', 'users_add_team_member');
        self::assertTrue($plan['requiresConfirmation']);
        self::assertStringContainsString('may grant them access to content shared with this team', $plan['impact']);
        self::assertSame(['uid' => 'owner'], $plan['member']);
        self::assertSame(0, $manager->addCalls);
        $result = $directory->addMember($args + ['plan_state' => $plan['plan_state']], 'alice', 'users_add_team_member');
        self::assertSame('owner', $result['member']['uid']);
        self::assertSame(1, $manager->addCalls);
        self::assertSame([false, true], $manager->syncSessions);
    }

    public function testAddPreviewCannotEnumerateAccountsHiddenFromActorSearch(): void
    {
        [$directory, $manager] = $this->setupDirectory([], 9, ['alice', 'bea', 'ben']);
        $this->expectException(ToolFailure::class);
        try {
            $directory->previewAddMember(['team_id' => 'team-1', 'user_id' => 'owner'], 'alice', 'users_add_team_member');
        } finally {
            self::assertSame(0, $manager->addCalls);
        }
    }

    public function testAddPlanIsInvalidatedWhenTeamMembershipChanges(): void
    {
        [$directory, $manager] = $this->setupDirectory();
        $args = ['team_id' => 'team-1', 'user_id' => 'owner'];
        $plan = $directory->previewAddMember($args, 'alice', 'users_add_team_member');
        $manager->circle->members[] = new class {
            public function getUserType(): int { return 1; }
            public function getLevel(): int { return 1; }
            public function getId(): string { return 'member-newcomer'; }
            public function getUserId(): string { return 'newcomer'; }
            public function getSingleId(): string { return ''; }
            public function getStatus(): string { return 'Member'; }
        };
        $this->expectException(PlanChanged::class);
        $directory->addMember($args + ['plan_state' => $plan['plan_state']], 'alice', 'users_add_team_member');
    }

    public function testAddPreflightUsesNativeCircleAclAndDoesNotPromoteRoles(): void
    {
        [$directory] = $this->setupDirectory([], 1);
        // A member cannot add when the native CFG_FRIEND flag is absent.
        $this->expectException(ToolFailure::class);
        $directory->previewAddMember(['team_id' => 'team-1', 'user_id' => 'ben'], 'alice', 'users_add_team_member');
    }

    public function testFriendInviteAclAllowsMemberButOnlyAddsDefaultMemberRole(): void
    {
        [$directory, $manager] = $this->setupDirectory([], 1);
        $manager->circle->config = 128;
        $args = ['team_id' => 'team-1', 'user_id' => 'owner'];
        $plan = $directory->previewAddMember($args, 'alice', 'users_add_team_member');
        self::assertSame(['teamRole' => 'member'], $plan['proposed']);
        $result = $directory->addMember($args + ['plan_state' => $plan['plan_state']], 'alice', 'users_add_team_member');
        self::assertSame('owner', $result['member']['uid']);
        self::assertSame(1, $manager->circle->members[array_key_last($manager->circle->members)]->getLevel());
    }

    public function testRemoveTargetsDirectMemberAndUsesNativeMemberId(): void
    {
        [$directory, $manager] = $this->setupDirectory();
        $args = ['team_id' => 'team-1', 'user_id' => 'bea'];
        $plan = $directory->previewRemoveMember($args, 'alice', 'users_remove_team_member');
        self::assertStringContainsString('may revoke their access to content shared with this team', $plan['impact']);
        self::assertSame([], $manager->removed);
        $result = $directory->removeMember($args + ['plan_state' => $plan['plan_state']], 'alice', 'users_remove_team_member');
        self::assertSame('bea', $result['removed']['uid']);
        self::assertSame(['member-bea'], $manager->removed);
        self::assertSame([false, true], $manager->syncSessions);
    }
}
