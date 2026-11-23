<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentPlanRenderer;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class AppointmentGatewayTest extends TestCase
{
    private function gateway(object $service, string $appVersion = '6.6.2', string $sessionUid = 'alice', ?Collection $calendar = null, ?array &$calls = null): AppointmentGateway
    {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('calendar', self::anything())->willReturn(true);
        $apps->method('getAppVersion')->with('calendar')->willReturn($appVersion);
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($sessionUid);
        $session->method('getUser')->willReturn($user);
        $calendars = $this->createMock(ScopedCollectionAccess::class);
        $resolvedCalendar = $calendar ?? new Collection(1, 'personal', 'Personal', 'alice', 'principals/users/alice', true, '/remote.php/dav/calendars/alice/personal/');
        $calendars->method('resolveWritable')->willReturn($resolvedCalendar);
        $calendars->method('visible')->willReturn([$resolvedCalendar]);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
        return new AppointmentGateway(
            $apps,
            $session,
            $calendars,
            static function () use ($service, &$calls): object { $calls[] = 'service'; return $service; },
            static fn (string $token): string => 'https://cloud.example/apps/calendar/appointment/' . rawurlencode($token),
            new PlanState($config),
        );
    }

    public function testNewConfigIsPrivateByDefaultAndPreviewDoesNotWriteOrExposeToken(): void
    {
        $service = new AppointmentConfigServiceFake();
        $calls = [];
        $gateway = $this->gateway($service, calls: $calls);
        $preview = $gateway->previewCreate($this->createArguments(), 'alice', 'calendar_create_appointment');
        self::assertSame('create', $preview['action']);
        self::assertSame('PRIVATE', $preview['after']['visibility']);
        self::assertStringContainsString('anyone who receives its booking URL can book', $preview['scope']);
        self::assertArrayNotHasKey('token', $preview['after']);
        self::assertSame([], $service->created);
        self::assertArrayNotHasKey('link', $preview);
        $result = $gateway->create($this->createArguments() + ['plan_state' => $preview['plan_state']], 'alice', 'calendar_create_appointment');
        self::assertSame('PRIVATE', $service->created[0]['visibility']);
        self::assertSame(1800, $service->created[0]['length']);
        self::assertSame(900, $service->created[0]['increment']);
        self::assertSame(0, $service->created[0]['preparationDuration']);
        self::assertSame(0, $service->created[0]['followupDuration']);
        self::assertSame(0, $service->created[0]['buffer']);
        self::assertNull($service->created[0]['futureLimit']);
        self::assertSame('09:00', (new \DateTimeImmutable('@' . $service->created[0]['availability']['slots']['MO'][0]['start']))->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('H:i'));
        self::assertSame('17:00', (new \DateTimeImmutable('@' . $service->created[0]['availability']['slots']['MO'][0]['end']))->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('H:i'));
        self::assertSame('https://cloud.example/apps/calendar/appointment/native-token', $result['link']);
        self::assertSame(45, $result['length']);
        self::assertSame(15, $result['increment']);
        self::assertSame(10, $result['preparationDuration']);
        self::assertSame(5, $result['followupDuration']);
        self::assertSame(20, $result['buffer']);
        self::assertSame(30, $result['futureLimit']);
        self::assertSame('09:00', $result['availability']['slots']['MO'][0]['start']);
        self::assertArrayNotHasKey('token', $result);
    }

    public function testPublicLinkIsReturnedOnlyAfterExplicitPublicVisibility(): void
    {
        $service = new AppointmentConfigServiceFake();
        $gateway = $this->gateway($service);
        $arguments = $this->createArguments() + ['visibility' => 'PUBLIC'];
        $plan = $gateway->previewCreate($arguments, 'alice', 'calendar_create_appointment');
        self::assertSame('PUBLIC', $plan['after']['visibility']);
        self::assertStringContainsString('Anyone with the URL can book', $plan['scope']);
        $result = $gateway->create($arguments + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_create_appointment');
        self::assertSame('https://cloud.example/apps/calendar/appointment/native-token', $result['link']);
        self::assertArrayNotHasKey('token', $result);
    }

    public function testUnsupportedAppVersionFailsClosedBeforeNativeServiceResolution(): void
    {
        $service = new AppointmentConfigServiceFake();
        $calls = [];
        $gateway = $this->gateway($service, appVersion: '6.7.0', calls: $calls);
        try {
            $gateway->list('alice');
            self::fail('expected closed version gate');
        } catch (ToolFailure) {
            self::assertSame([], $calls);
        }
    }

    public function testSessionIdentityMustMatchAuthenticatedActor(): void
    {
        $service = new AppointmentConfigServiceFake();
        $calls = [];
        $gateway = $this->gateway($service, sessionUid: 'mallory', calls: $calls);
        try {
            $gateway->list('alice');
            self::fail('expected identity refusal');
        } catch (ToolFailure) {
            self::assertSame([], $calls);
        }
    }

    public function testInvalidAvailabilityIsRejectedBeforeResolvingNativeService(): void
    {
        $service = new AppointmentConfigServiceFake();
        $calls = [];
        $gateway = $this->gateway($service, calls: $calls);
        $arguments = $this->createArguments();
        $arguments['availability']['slots']['MO'] = [['start' => '10:00', 'end' => '11:00'], ['start' => '10:30', 'end' => '12:00']];
        $this->expectException(\InvalidArgumentException::class);
        $gateway->previewCreate($arguments, 'alice', 'calendar_create_appointment');
    }

    public function testUpdateAndDeleteAreBoundToNativeOwnerAndPlanState(): void
    {
        $service = new AppointmentConfigServiceFake();
        $gateway = $this->gateway($service);
        $update = $this->createArguments() + ['id' => 17];
        $plan = $gateway->previewUpdate($update, 'alice', 'calendar_update_appointment');
        self::assertSame('update', $plan['action']);
        $updated = $gateway->update($update + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_update_appointment');
        self::assertSame(17, $updated['id']);
        self::assertSame('alice', $service->updated[0]->getUserId());

        $deletePlan = $gateway->previewDelete(['id' => 17], 'alice', 'calendar_delete_appointment');
        self::assertStringContainsString('existing booked events remain', $deletePlan['consequence']);
        $deleted = $gateway->delete(['id' => 17, 'plan_state' => $deletePlan['plan_state']], 'alice', 'calendar_delete_appointment');
        self::assertSame(['deleted' => 17, 'message' => 'Appointment configuration removed. Existing booked events are not removed.'], $deleted);
        self::assertSame([['id' => 17, 'userId' => 'alice']], $service->deleted);
    }

    public function testPartialUpdateChangesOnlySelectedFieldsAndPreservesNativeFields(): void
    {
        $service = new AppointmentConfigServiceFake();
        $gateway = $this->gateway($service);
        $arguments = ['id' => 17, 'visibility' => 'PUBLIC', 'increment' => 30, 'buffer' => 25];
        $plan = $gateway->previewUpdate($arguments, 'alice', 'calendar_update_appointment');
        self::assertSame(['visibility' => 'PUBLIC', 'increment' => 30, 'buffer' => 25], $plan['after']);
        $rendered = (new AppointmentPlanRenderer())->renderPlan('calendar_update_appointment', $plan);
        self::assertStringContainsString('Visibility: PUBLIC', $rendered);
        self::assertStringContainsString('Slot increment (minutes): 30', $rendered);
        self::assertStringContainsString('Buffer between bookings (minutes): 25', $rendered);

        $gateway->update($arguments + ['plan_state' => $plan['plan_state']], 'alice', 'calendar_update_appointment');
        $updated = $service->updated[0];
        self::assertSame('Existing description', $updated->getDescription());
        self::assertSame('Consultation', $updated->getName());
        self::assertSame('Room 4', $updated->getLocation());
        self::assertSame('personal', $updated->getTargetCalendarUri());
        self::assertSame(45 * 60, $updated->getLength());
        self::assertSame(1800, $updated->getIncrement());
        self::assertSame(600, $updated->getPreparationDuration());
        self::assertSame(300, $updated->getFollowupDuration());
        self::assertSame(1500, $updated->getTimeBeforeNextSlot());
        self::assertSame(30 * 86400, $updated->getFutureLimit());
        self::assertSame(['busy-uri'], $updated->getCalendarFreebusyUrisAsArray());
        self::assertTrue($updated->getCreateTalkRoom());
        self::assertSame(['setVisibility', 'setIncrement', 'setTimeBeforeNextSlot'], $updated->setters);
    }

    public function testPartialUpdateValidatesWindowAgainstPreservedEnd(): void
    {
        $gateway = $this->gateway(new AppointmentConfigServiceFake());
        $this->expectException(\InvalidArgumentException::class);
        $gateway->previewUpdate(['id' => 17, 'start' => 1702000000], 'alice', 'calendar_update_appointment');
    }

    public function testPartialUpdateValidatesLengthAgainstPreservedIncrement(): void
    {
        $gateway = $this->gateway(new AppointmentConfigServiceFake());
        $this->expectException(\InvalidArgumentException::class);
        $gateway->previewUpdate(['id' => 17, 'length' => 10], 'alice', 'calendar_update_appointment');
    }

    public function testPartialUpdateValidatesChangedAvailability(): void
    {
        $slots = array_fill_keys(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], []);
        $slots['MO'] = [['start' => '09:00', 'end' => '11:00'], ['start' => '10:00', 'end' => '12:00']];
        $gateway = $this->gateway(new AppointmentConfigServiceFake());
        $this->expectException(\InvalidArgumentException::class);
        $gateway->previewUpdate(['id' => 17, 'availability' => ['timezoneId' => 'UTC', 'slots' => $slots]], 'alice', 'calendar_update_appointment');
    }

    public function testListingHidesOutOfScopeTargetsAndDoesNotGenerateAnEmptyTokenLink(): void
    {
        $service = new AppointmentConfigServiceFake();
        $service->configs = [
            new AppointmentConfigFake(1, 'native-token', 'Visible', 'PRIVATE', 'alice', 'personal'),
            new AppointmentConfigFake(2, 'native-token', 'Outside scope', 'PRIVATE', 'alice', 'out-of-scope'),
            new AppointmentConfigFake(3, '', 'Missing token', 'PRIVATE', 'alice', 'personal'),
            new AppointmentConfigFake(4, 'token', 'Other actor', 'PRIVATE', 'mallory', 'personal'),
        ];
        $gateway = $this->gateway($service);
        $listed = $gateway->list('alice');
        self::assertFalse($listed['truncated']);
        self::assertSame([1, 3], array_column($listed['appointments'], 'id'));
        self::assertArrayHasKey('link', $listed['appointments'][0]);
        self::assertArrayNotHasKey('link', $listed['appointments'][1]);
    }

    public function testListLimitCountsOnlyConfigurationsInsideActorScope(): void
    {
        $service = new AppointmentConfigServiceFake();
        for ($id = 1; $id <= 105; ++$id) {
            $service->configs[] = new AppointmentConfigFake($id, 'token-' . $id, 'Visible ' . $id, 'PRIVATE', 'alice', 'personal');
            $service->configs[] = new AppointmentConfigFake($id + 1000, 'token-hidden', 'Hidden', 'PRIVATE', 'alice', 'out-of-scope');
        }
        $listed = $this->gateway($service)->list('alice');
        self::assertTrue($listed['truncated']);
        self::assertCount(100, $listed['appointments']);
    }

    public function testCannotDeleteAnOwnedConfigWhoseCalendarLeftActorScope(): void
    {
        $service = new AppointmentConfigServiceFake();
        $gateway = $this->gateway($service);
        $this->expectException(ToolFailure::class);
        $gateway->previewDelete(['id' => 19], 'alice', 'calendar_delete_appointment');
    }

    public function testCannotTargetSharedCalendarForAnAppointmentConfig(): void
    {
        $service = new AppointmentConfigServiceFake();
        $calendar = new Collection(2, 'shared', 'Shared', 'bob', 'principals/users/bob', true, '/remote.php/dav/calendars/alice/shared/');
        $gateway = $this->gateway($service, calendar: $calendar);
        $this->expectException(ToolFailure::class);
        $gateway->previewCreate($this->createArguments(), 'alice', 'calendar_create_appointment');
    }

    private function createArguments(): array
    {
        $slots = array_fill_keys(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], []);
        $slots['MO'] = [['start' => '09:00', 'end' => '17:00']];
        return [
            'calendar' => '/remote.php/dav/calendars/alice/personal/',
            'name' => 'Consultation',
            'description' => '',
            'location' => null,
            'availability' => ['slots' => $slots, 'timezoneId' => 'Europe/Madrid'],
            'length' => 30,
            'increment' => 15,
        ];
    }
}

final class AppointmentConfigServiceFake
{
    public array $created = [];
    public array $updated = [];
    public array $deleted = [];
    public array $configs = [];

    public function getAllAppointmentConfigurations(string $userId): array { return $this->configs; }
    public function create(string $name, string $description, ?string $location, string $visibility, string $userId, string $targetCalendarUri, array $availability, int $length, int $increment, int $preparationDuration, int $followupDuration, int $buffer, ?int $dailyMax, ?array $calendarFreeBusyUris, ?int $start, ?int $end, ?int $futureLimit, ?bool $createTalkRoom): AppointmentConfigFake
    {
        $this->created[] = compact('name', 'description', 'location', 'visibility', 'userId', 'targetCalendarUri', 'availability', 'length', 'increment', 'preparationDuration', 'followupDuration', 'buffer', 'dailyMax', 'calendarFreeBusyUris', 'start', 'end', 'futureLimit', 'createTalkRoom');
        return new AppointmentConfigFake(17, 'native-token', $name, $visibility, $userId, $targetCalendarUri);
    }
    public function findByIdAndUser(int $id, string $userId): AppointmentConfigFake { return $this->configs[$id] ?? new AppointmentConfigFake($id, 'native-token', 'Consultation', 'PRIVATE', $userId, $id === 19 ? 'out-of-scope' : 'personal'); }
    public function update(AppointmentConfigFake $config): AppointmentConfigFake { $this->updated[] = $config; $this->configs[$config->getId()] = $config; return $config; }
    public function delete(int $id, string $userId): void { $this->deleted[] = compact('id', 'userId'); }
}

final class AppointmentConfigFake
{
    public array $setters = [];
    private string $description = 'Existing description';
    private ?string $location = 'Room 4';
    private array $availability;
    private int $length = 45 * 60;
    private int $increment = 15 * 60;
    private int $preparationDuration = 10 * 60;
    private int $followupDuration = 5 * 60;
    private int $buffer = 20 * 60;
    private ?int $dailyMax = 6;
    private ?int $start = 1700000000;
    private ?int $end = 1701000000;
    private ?int $futureLimit = 30 * 86400;
    private array $calendarFreebusyUris = ['busy-uri'];
    private bool $createTalkRoom = true;

    public function __construct(private int $id, private string $token, private string $name, private string $visibility, private string $userId, private string $targetCalendarUri)
    {
        $slots = array_fill_keys(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], []);
        $zone = new \DateTimeZone('Europe/Madrid');
        $slots['MO'] = [[
            'start' => (new \DateTimeImmutable('2024-01-01 09:00:00', $zone))->getTimestamp(),
            'end' => (new \DateTimeImmutable('2024-01-01 17:00:00', $zone))->getTimestamp(),
        ]];
        $this->availability = ['timezoneId' => 'Europe/Madrid', 'slots' => $slots];
    }
    public function getId(): int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getLocation(): ?string { return $this->location; }
    public function getVisibility(): string { return $this->visibility; }
    public function getUserId(): string { return $this->userId; }
    public function getTargetCalendarUri(): string { return $this->targetCalendarUri; }
    public function getAvailabilityAsArray(): array { return $this->availability; }
    public function getCalendarFreebusyUrisAsArray(): array { return $this->calendarFreebusyUris; }
    public function getLength(): int { return $this->length; }
    public function getIncrement(): int { return $this->increment; }
    public function getPreparationDuration(): int { return $this->preparationDuration; }
    public function getFollowupDuration(): int { return $this->followupDuration; }
    public function getTimeBeforeNextSlot(): int { return $this->buffer; }
    public function getDailyMax(): ?int { return $this->dailyMax; }
    public function getStart(): ?int { return $this->start; }
    public function getEnd(): ?int { return $this->end; }
    public function getFutureLimit(): ?int { return $this->futureLimit; }
    public function getCreateTalkRoom(): bool { return $this->createTalkRoom; }
    public function setName(string $value): void { $this->setters[] = 'setName'; $this->name = $value; }
    public function setDescription(string $value): void { $this->setters[] = 'setDescription'; $this->description = $value; }
    public function setLocation(?string $value): void { $this->setters[] = 'setLocation'; $this->location = $value; }
    public function setVisibility(string $value): void { $this->setters[] = 'setVisibility'; $this->visibility = $value; }
    public function setUserId(string $value): void { $this->setters[] = 'setUserId'; $this->userId = $value; }
    public function setTargetCalendarUri(string $value): void { $this->setters[] = 'setTargetCalendarUri'; $this->targetCalendarUri = $value; }
    public function setAvailabilityAsArray(array $value): void { $this->setters[] = 'setAvailabilityAsArray'; $this->availability = $value; }
    public function setLength(int $value): void { $this->setters[] = 'setLength'; $this->length = $value; }
    public function setIncrement(int $value): void { $this->setters[] = 'setIncrement'; $this->increment = $value; }
    public function setPreparationDuration(int $value): void { $this->setters[] = 'setPreparationDuration'; $this->preparationDuration = $value; }
    public function setFollowupDuration(int $value): void { $this->setters[] = 'setFollowupDuration'; $this->followupDuration = $value; }
    public function setTimeBeforeNextSlot(int $value): void { $this->setters[] = 'setTimeBeforeNextSlot'; $this->buffer = $value; }
    public function setDailyMax(?int $value): void { $this->setters[] = 'setDailyMax'; $this->dailyMax = $value; }
    public function setCalendarFreeBusyUrisAsArray(array $value): void { $this->setters[] = 'setCalendarFreeBusyUrisAsArray'; $this->calendarFreebusyUris = $value; }
    public function setStart(?int $value): void { $this->setters[] = 'setStart'; $this->start = $value; }
    public function setEnd(?int $value): void { $this->setters[] = 'setEnd'; $this->end = $value; }
    public function setFutureLimit(?int $value): void { $this->setters[] = 'setFutureLimit'; $this->futureLimit = $value; }
    public function setCreateTalkRoom(bool $value): void { $this->setters[] = 'setCreateTalkRoom'; $this->createTalkRoom = $value; }
}
