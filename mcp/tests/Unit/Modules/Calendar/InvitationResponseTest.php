<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Scheduling\InvitationResponse;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\Reader;

final class InvitationResponseTest extends CalendarTestCase {
    public function testResponseUpdatesOnlyTheActorAndProducesAReplyToTheOrganizer(): void {
        $this->seed();
        $service = $this->responder();
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'response' => 'accepted'];
        $plan = $service->preview($args, 'alice');
        self::assertSame('NEEDS-ACTION', $plan['before']);
        self::assertSame('ACCEPTED', $plan['after']);
        $this->assertNoWrites();

        $before = $this->store->objects[1]['event.ics']['data'];
        $newArgs = $args + ['etag' => $plan['etag']];
        $written = self::json($service->execute($newArgs, 'alice'));
        self::assertSame('ACCEPTED', $written['response']);
        self::assertTrue($this->dav->calls[0][1][4]);
        $newEvent = Reader::read($this->store->objects[1]['event.ics']['data']);
        self::assertSame('TENTATIVE', (string)array_values($newEvent->VEVENT->select('ATTENDEE'))[1]['PARTSTAT']);
        $messages = (new Broker())->parseEvent($newEvent, ['mailto:alice@example.invalid'], Reader::read($before));
        self::assertCount(1, $messages);
        self::assertSame('REPLY', $messages[0]->method);
        self::assertSame('mailto:bob@example.invalid', $messages[0]->recipient);
        self::assertSame('mailto:alice@example.invalid', $messages[0]->sender);

        self::assertFalse(self::json($service->execute($args + ['etag' => $written['etag']], 'alice'))['changed']);
        self::assertCount(1, $this->dav->calls);
    }

    public function testSharedCalendarCannotBeUsedToSendAReplyAsItsOwner(): void {
        $this->seed(3);
        $this->expectException(CalendarException::class);
        try {
            $this->responder()->preview(['calendar' => self::TEAM, 'uid' => 'event', 'response' => 'declined'], 'alice');
        } finally {
            $this->assertNoWrites();
        }
    }

    private function seed(int $calendarId = 1): void {
        $this->store->addObject($calendarId, 'event.ics', self::ics(
            "UID:event\nSUMMARY:Invitation\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\n"
            . "ORGANIZER:mailto:bob@example.invalid\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid\n"
            . 'ATTENDEE;PARTSTAT=TENTATIVE:mailto:carla@example.invalid',
        ));
    }

    private function responder(): InvitationResponse {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getEMailAddress')->willReturn('alice@example.invalid');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $app = $this->createMock(IAppConfig::class);
        $app->method('getValueString')->willReturn('yes');
        return new InvitationResponse(
            ScopeStub::access($this->store, $this->scope),
            new EventRepository($this->store, new Classification()),
            $this->store,
            $this->dav,
            new AttendeeResolver($users),
            new Scheduling($app),
        );
    }
}
