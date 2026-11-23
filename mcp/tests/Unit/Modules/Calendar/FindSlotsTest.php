<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;
use DateTimeImmutable;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityReader;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Scheduling\AvailabilityCheck;
use OCA\Mcp\Modules\Calendar\Scheduling\SlotFinder;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\NullLogger;

final class FindSlotsTest extends CalendarTestCase
{
    public function testSlotsUseLocalWorkHoursAcrossDaylightSavingAndBatchAttendees(): void
    {
        $calls = [];
        $manager = $this->createMock(IManager::class);
        $manager->method('checkAvailability')->willReturnCallback(function($start,$end,$organizer,array $emails) use (&$calls): array {
            $calls[] = $emails; $results=[];
            foreach ($emails as $email) { $r=$this->createMock(IAvailabilityResult::class); $r->method('getAttendeeEmail')->willReturn($email); $r->method('isAvailable')->willReturn(true); $results[]=$r; }
            return $results;
        });
        $finder = $this->finder($manager);
        $result = $this->decode($finder->execute(['from'=>'2026-03-29T00:00:00Z','to'=>'2026-03-30T00:00:00Z','time_zone'=>'Europe/Madrid','workdays'=>[7],'required_attendees'=>['bob'],'optional_attendees'=>['carla'],'limit'=>1], 'alice'));
        self::assertSame('2026-03-29T09:00:00+02:00', $result['slots'][0]['start']);
        self::assertSame([['bob@example.invalid','carla@example.invalid']], $calls);
        self::assertSame('available', $result['slots'][0]['availability']);
    }

    public function testRequiredAttendeeWithoutNativeVerdictProducesUnknownSlot(): void
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('checkAvailability')->willReturn([]);
        $finder = $this->finder($manager);
        $result = $this->decode($finder->execute(['from'=>'2026-03-10T09:00:00Z','to'=>'2026-03-10T11:00:00Z','time_zone'=>'UTC','workdays'=>[2],'required_attendees'=>['bob'],'limit'=>1], 'alice'));
        self::assertSame('unknown', $result['slots'][0]['availability']);
        self::assertSame('unknown', $result['slots'][0]['requiredAttendees'][0]['status']);
    }

    private function finder(IManager $manager): SlotFinder
    {
        $users = $this->createMock(IUserManager::class);
        $emails=['alice'=>'alice@example.invalid','bob'=>'bob@example.invalid','carla'=>'carla@example.invalid'];
        $users->method('get')->willReturnCallback(function($uid) use ($emails): ?IUser {
            if (!isset($emails[$uid])) return null;
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('getDisplayName')->willReturn(ucfirst($uid));
            $user->method('getEMailAddress')->willReturn($emails[$uid]);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        $access = \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope);
        $dates = new EventDates(); $classification = new Classification();
        $repository = new EventRepository($this->store, $classification);
        $organizer = new AvailabilityReader($access,$this->store,$this->store,$repository,$classification,new EventExpander(),$dates);
        return new SlotFinder($organizer,new AvailabilityCheck($manager,$users,new NullLogger()),new AttendeeResolver($users),$dates);
    }
    private function decode(array $result): array { return json_decode($result['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR); }
}
