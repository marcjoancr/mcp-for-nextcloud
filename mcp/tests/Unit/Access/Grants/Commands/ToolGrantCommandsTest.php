<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Commands;

use OCA\Mcp\Access\Grants\Commands\ToolGrantCommands;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;
use OCA\Mcp\Tests\Support\Access\Policy\GroupLifecycleDatabase;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ToolGrantCommandsTest extends TestCase {
    use GroupLifecycleDatabase;

    protected function setUp(): void {
        $this->lifecycleDatabase();
    }

    public function testGroupPreviewCanBeCommittedAgainstTheSameRevisionAndLifecycle(): void {
        $data = new InMemoryConfig();
        $config = $data->mock($this);
        $data->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['staff' => ['alice']])];
        $data->user['alice'] = ['mcp' => []];
        $policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
        InMemoryConfig::groupPolicy($config, 'staff', ['notes' => ['edit']]);
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('classify')->willReturn(['trusted' => true, 'exists' => true]);
        $classifier->method('members')->willReturn(['alice']);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        $oauth = new OAuthStore($this->db);
        $authUsers = new AuthUsersMapper($this->db);
        $commands = new ToolGrantCommands(
            $this->writer->transaction,
            $this->toolGrants,
            $events,
            $this->grants,
            $this->life,
            $classifier,
            $this->createMock(IUserManager::class),
            $time,
            $policy,
            $oauth,
            $authUsers,
            new CredentialCuts($authUsers, $this->state, $this->rechecks),
            new LifecycleHolds($this->life),
        );
        $cells = [['toolName' => 'notes_edit', 'module' => 'notes', 'operation' => 'edit', 'decision' => 'deny']];

        $preview = $commands->previewGroup($this->revision(), 'root', 'staff', $cells);
        $saved = $commands->setGroup($preview['revision'], 'root', 'staff', $cells, $preview['previewId']);

        self::assertSame(1, $preview['impact']['affectedUsers']);
        self::assertSame('group_deny', $preview['impact']['tools']['notes_edit']['source']);
        self::assertFalse($preview['impact']['tools']['notes_edit']['effective']);
        self::assertTrue($saved['changed']);
        self::assertSame(['notes_edit' => 'deny'], $this->toolGrants->forPrincipals(ToolGrantsMapper::GROUP, ['staff'])['staff']);
        self::assertSame('active', $this->life->get('staff')['state']);
    }

    public function testVariantGroupPreviewUsesConcreteOperationAccessForImpactAndReceipt(): void {
        $data = new InMemoryConfig();
        $config = $data->mock($this);
        $data->groupBackends = [new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static fn (): array => ['staff' => ['alice']])];
        $data->user['alice'] = ['mcp' => []];
        $policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
        InMemoryConfig::groupPolicy($config, 'staff', ['files' => ['link']]);
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('classify')->willReturn(['trusted' => true, 'exists' => true]);
        $classifier->method('members')->willReturn(['alice']);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        $oauth = new OAuthStore($this->db);
        $authUsers = new AuthUsersMapper($this->db);
        $commands = new ToolGrantCommands(
            $this->writer->transaction,
            $this->toolGrants,
            $events,
            $this->grants,
            $this->life,
            $classifier,
            $this->createMock(IUserManager::class),
            $time,
            $policy,
            $oauth,
            $authUsers,
            new CredentialCuts($authUsers, $this->state, $this->rechecks),
            new LifecycleHolds($this->life),
        );
        $cells = [['toolName' => 'files_share', 'module' => 'files', 'operation' => 'share',
            'decision' => 'deny', 'grantAnyOf' => ['link', 'share']]];

        $preview = $commands->previewGroup($this->revision(), 'root', 'staff', $cells);

        self::assertSame(1, $preview['impact']['affectedUsers']);
        self::assertFalse($preview['impact']['tools']['files_share']['effective']);
        $saved = $commands->setGroup($preview['revision'], 'root', 'staff', $cells, $preview['previewId']);
        self::assertTrue($saved['changed']);
        self::assertSame(['files_share' => 'deny'], $this->toolGrants->forPrincipals(ToolGrantsMapper::GROUP, ['staff'])['staff']);
    }

    public function testGroupServiceNeutralizationHoldsOtherGroupThatKeepsMemberEligible(): void {
        $staffGen = bin2hex(random_bytes(16));
        $otherGen = bin2hex(random_bytes(16));
        $this->writer->transaction->runInternal([], static fn () => null, function () use ($staffGen, $otherGen): bool {
            $this->life->put('staff', 'active', $staffGen, '0');
            $this->life->put('other', 'active', $otherGen, '0');
            $this->toolGrants->set(ToolGrantsMapper::GROUP, 'staff', ToolGrantsMapper::SERVICE, 'allow');
            return true;
        });

        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('projectedToolStates')->willReturn(['alice' => [ToolGrantsMapper::SERVICE => [
            'effective' => true, 'source' => 'group', 'groupIds' => ['other'],
        ]]]);
        $policy->method('toolStatesForUsers')->willReturn(['alice' => [ToolGrantsMapper::SERVICE => [
            'effective' => true, 'source' => 'group', 'groupIds' => ['staff'],
        ]]]);
        $policy->method('projectedGroupToolStates')->willReturn([ToolGrantsMapper::SERVICE => [
            'effective' => false, 'source' => 'default', 'groupIds' => [],
        ]]);
        $policy->expects(self::atLeastOnce())->method('lifecyclesForUsers')->with(['alice' => ['other']])
            ->willReturn([['groupId' => 'other', 'gen' => $otherGen]]);

        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('classify')->willReturn(['trusted' => true, 'exists' => true]);
        $classifier->method('members')->willReturn(['alice']);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($this->now);
        $events = new PolicyEventsMapper($this->db, $time);
        $oauth = new OAuthStore($this->db);
        $authUsers = new AuthUsersMapper($this->db);
        $commands = new ToolGrantCommands($this->writer->transaction, $this->toolGrants, $events, $this->grants,
            $this->life, $classifier, $this->createMock(IUserManager::class), $time, $policy, $oauth, $authUsers,
            new CredentialCuts($authUsers, $this->state, $this->rechecks), new LifecycleHolds($this->life));
        $cells = [['toolName' => ToolGrantsMapper::SERVICE, 'module' => '_system', 'operation' => 'eligible', 'decision' => 'neutral']];

        $preview = $commands->previewGroup($this->revision(), 'root', 'staff', $cells);
        $saved = $commands->setGroup($preview['revision'], 'root', 'staff', $cells, $preview['previewId']);

        self::assertTrue($saved['changed']);
        self::assertSame([ToolGrantsMapper::SERVICE => 'neutral'], $this->toolGrants->forPrincipals(ToolGrantsMapper::GROUP, ['staff'])['staff']);
        self::assertNotSame($otherGen, $this->life->get('other')['gen']);
    }
}
