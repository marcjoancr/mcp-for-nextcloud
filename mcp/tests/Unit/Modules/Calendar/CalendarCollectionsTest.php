<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Calendar\Collections\CalendarCreator;
use OCA\Mcp\Modules\Calendar\Collections\CalendarShareManager;
use OCA\Mcp\Platform\Dav\CalDav\CalendarCollectionWriter;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

final class CalendarCollectionsTest extends CalendarTestCase
{
    public function testCreatePersonalCalendarIsStateBoundAndReadBackThroughNativeCollectionAccess(): void
    {
        $this->dav->selftestMode = true;
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('fixture-secret');
        $states = new PlanState($config);
        $creator = new CalendarCreator(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->dav, $this->scope, $states);
        $args = ['displayName' => 'Project Notes'];
        $plan = $creator->preview($args, 'alice', 'calendar_create_calendar');
        self::assertSame('project-notes', $plan['uri']);
        self::assertArrayHasKey('plan_state', $plan);
        self::assertSame([], array_filter($this->store->calendars[self::ALICE], static fn (array $calendar): bool => $calendar['uri'] === 'project-notes'));

        $created = $creator->create($args + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_create_calendar');

        $data = json_decode($created['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('created', $data['status']);
        self::assertSame('project-notes', $data['calendar']['uri']);
    }

    public function testSharePlanIsBoundToCurrentAclAndWritesNativeShare(): void
    {
        $this->store->shares[1] = [];
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob');
        $bob->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('bob')->willReturn($bob);
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([['exact' => ['users' => [['value' => ['shareWith' => 'bob']]]]], false]);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('fixture-secret');
        $dav = $this->createMock(CalendarCollectionWriter::class);
        $dav->expects(self::once())->method('shareCalendar')->with('alice', 'personal', 'bob', 'Bob', true)->willReturnCallback(function () {
            $this->store->addShare(1, 'principals/users/bob', true);
            return new DavResult(200);
        });
        $manager = new CalendarShareManager(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store, $users, $search, $dav, new PlanState($config));
        $args = ['calendar' => self::PERSONAL, 'shareWith' => 'bob', 'access' => 'read'];
        $plan = $manager->preview($args, 'alice', 'calendar_share_calendar');
        self::assertSame('read', $plan['access']);
        self::assertArrayHasKey('plan_state', $plan);

        $manager->share($args + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_share_calendar');

        self::assertSame([['principal' => 'principals/users/bob', 'readOnly' => true]], $this->store->sharesOf(1));
    }

    public function testShareConfirmationIsRejectedAfterAclChanges(): void
    {
        $this->store->shares[1] = [];
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob');
        $bob->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('bob')->willReturn($bob);
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([['exact' => ['users' => [['value' => ['shareWith' => 'bob']]]]], false]);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('fixture-secret');
        $dav = $this->createMock(CalendarCollectionWriter::class);
        $dav->expects(self::never())->method('shareCalendar');
        $manager = new CalendarShareManager(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store, $users, $search, $dav, new PlanState($config));
        $args = ['calendar' => self::PERSONAL, 'shareWith' => 'bob', 'access' => 'read'];
        $plan = $manager->preview($args, 'alice', 'calendar_share_calendar');
        $this->store->addShare(1, 'principals/users/bob', false);

        try {
            $manager->share($args + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_share_calendar');
            self::fail('stale share plans must be rejected');
        } catch (PlanChanged $changed) {
            self::assertArrayHasKey('plan_state', $changed->plan);
        }
    }
}
