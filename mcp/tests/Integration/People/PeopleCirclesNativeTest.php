<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\People;

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Probes\CircleProbe;
use OCA\Circles\Model\Probes\DataProbe;
use OCA\Mcp\Modules\People\Circles\CirclesDirectory;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCP\Accounts\IAccountManager;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IConfig;
use OCP\Profile\IProfileManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

final class PeopleCirclesNativeTest extends OAuthAccountTestCase {
	public function testNativeDirectoryUsesTheActorMembershipAndRealCircleProbe(): void {
		$apps = Server::get(IAppManager::class);
		if (!$this->user instanceof IUser || !$apps->isEnabledForUser('circles', $this->user)) {
			self::markTestSkipped('Circles is not enabled in this integration cell.');
		}

		$users = Server::get(IUserManager::class);
		$memberId = 'mcp-it-circle-member-' . bin2hex(random_bytes(6));
		$outsiderId = 'mcp-it-circle-outsider-' . bin2hex(random_bytes(6));
		$member = $users->createUser($memberId, bin2hex(random_bytes(24)));
		$outsider = $users->createUser($outsiderId, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $member);
		self::assertInstanceOf(IUser::class, $outsider);

		$session = Server::get(IUserSession::class);
		$previousUser = $session->getUser();
		$apps->loadApp('circles');
		$circles = Server::get(CirclesManager::class);
		$circleId = null;
		try {
			$session->setUser($this->user);
			$circles->startSession($circles->getLocalFederatedUser($this->uid), true);
			$createArgs = ['MCP People ' . bin2hex(random_bytes(4)), null, false, true];
			// Circles added createTeamFolder in stable35; keep this fixture from provisioning Files content.
			if ((new \ReflectionMethod($circles, 'createCircle'))->getNumberOfParameters() >= 5) {
				$createArgs[] = false;
			}
			$circle = $circles->createCircle(...$createArgs);
			$circleId = $circle->getSingleId();
			$circles->addMember($circleId, $circles->getLocalFederatedUser($memberId));
			$circles->stopSession();

			$directory = new CirclesDirectory(
				$apps,
				$users,
				$session,
				static fn (): object => Server::get(CirclesManager::class),
				static fn (): object => new CircleProbe(),
				static fn (): object => (new DataProbe())->add(DataProbe::OWNER)->add(DataProbe::MEMBER)->add(DataProbe::INITIATOR),
				Server::get(LoggerInterface::class),
				Server::get(IProfileManager::class),
				new PlanState(Server::get(IConfig::class)),
				Server::get(ISearch::class),
			);

			$session->setUser($this->user);
			$actorTeams = $directory->listTeams($this->uid, 'MCP People', 10);
			self::assertSame([$circleId], array_column($actorTeams['teams'], 'id'));
			$actorMembers = $directory->listMembers($this->uid, $circleId, 10);
			self::assertEqualsCanonicalizing([$this->uid, $memberId], array_column(array_filter(
				$actorMembers['members'], static fn (array $row): bool => $row['type'] === 'user',
			), 'uid'));

			Server::get(GrantPolicy::class)->setConnected($this->uid, true);
			Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
				foreach (['users_filter_team_members', 'users_add_team_member', 'users_remove_team_member'] as $name) {
					Server::get(ToolGrantsMapper::class)->set(ToolGrantsMapper::USER, $this->uid, $name, ToolGrantsMapper::ALLOW);
				}
				return true;
			});
			$client = new McpClient(LoginFactory::basic($this->uid));
			$accounts = Server::get(IAccountManager::class);
			$account = $accounts->getAccount($member);
			$account->setProperty(IAccountManager::PROPERTY_PROFILE_ENABLED, '1', IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
			$account->setProperty(IAccountManager::PROPERTY_ORGANISATION, 'MCP Fixture Studio', IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
			$account->setProperty(IAccountManager::PROPERTY_ROLE, 'MCP Fixture Editor', IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
			$accounts->updateAccount($account);

			$filters = ['team_id' => $circleId, 'organisation' => 'fixture studio', 'professional_role' => 'fixture editor'];
			$visible = $client->tool('users_filter_team_members', $filters);
			self::assertTrue($visible->ok(), $visible->describe());
			self::assertSame([$memberId], array_column($visible->json()['members'], 'uid'));
			self::assertSame('member', $visible->json()['members'][0]['teamRole']);
			self::assertSame('MCP Fixture Editor', $visible->json()['members'][0]['professionalRole']);

			$account->setProperty(IAccountManager::PROPERTY_ROLE, 'MCP Fixture Editor', IAccountManager::SCOPE_PRIVATE, IAccountManager::NOT_VERIFIED);
			$accounts->updateAccount($account);
			$hidden = $client->tool('users_filter_team_members', $filters);
			self::assertTrue($hidden->ok(), $hidden->describe());
			self::assertSame([], $hidden->json()['members']);

			$addArgs = ['team_id' => $circleId, 'user_id' => $outsiderId];
			$addPlan = $client->tool('users_add_team_member', $addArgs);
			self::assertTrue($addPlan->ok(), $addPlan->describe());
			$added = $client->write('users_add_team_member', $addArgs + ['plan_state' => $addPlan->structured()['plan_state']]);
			self::assertTrue($added->ok(), $added->describe());
			self::assertSame($outsiderId, $added->json()['member']['uid']);
			$afterAdd = $directory->listMembers($this->uid, $circleId, 10);
			self::assertContains($outsiderId, array_column(array_filter($afterAdd['members'], static fn (array $row): bool => $row['type'] === 'user'), 'uid'));
			$removeArgs = ['team_id' => $circleId, 'user_id' => $outsiderId];
			$removePlan = $client->tool('users_remove_team_member', $removeArgs);
			self::assertTrue($removePlan->ok(), $removePlan->describe());
			$removed = $client->write('users_remove_team_member', $removeArgs + ['plan_state' => $removePlan->structured()['plan_state']]);
			self::assertTrue($removed->ok(), $removed->describe());
			self::assertSame($outsiderId, $removed->json()['removed']['uid']);
			$afterRemove = $directory->listMembers($this->uid, $circleId, 10);
			self::assertNotContains($outsiderId, array_column(array_filter($afterRemove['members'], static fn (array $row): bool => $row['type'] === 'user'), 'uid'));

			$session->setUser($outsider);
			$outsiderTeams = $directory->listTeams($outsiderId, 'MCP People', 10);
			self::assertSame([], $outsiderTeams['teams']);
		} finally {
			$session->setUser($this->user);
			try {
				$circles->startSession($circles->getLocalFederatedUser($this->uid), true);
				if ($circleId !== null) {
					$circles->destroyCircle($circleId);
				}
			} finally {
				$circles->stopSession();
				$session->setUser($previousUser);
				$member->delete();
				$outsider->delete();
			}
		}
	}
}
