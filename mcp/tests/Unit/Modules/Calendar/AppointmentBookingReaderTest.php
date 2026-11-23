<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentBookingReader;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class AppointmentBookingReaderTest extends TestCase {
    public function testOwnerScopeAndCalendarBookingStatesAreEnforcedByTheRead(): void {
        $db = new SqliteDatabase($this, []);
        $db->dbal->executeStatement('CREATE TABLE calendar_appt_configs (id INTEGER PRIMARY KEY, user_id TEXT, target_calendar_uri TEXT, name TEXT, token TEXT)');
        $db->dbal->executeStatement('CREATE TABLE calendar_appt_bookings (id INTEGER PRIMARY KEY, appt_config_id INTEGER, start INTEGER, end INTEGER, confirmed BOOLEAN, display_name TEXT, email TEXT, token TEXT)');
        foreach ([[1, 'alice', 'owned'], [2, 'bob', 'owned'], [3, 'alice', 'out-of-scope']] as [$id, $owner, $uri]) {
            $db->dbal->insert('calendar_appt_configs', ['id' => $id, 'user_id' => $owner, 'target_calendar_uri' => $uri, 'name' => 'Consultation', 'token' => 'link-secret']);
        }
        foreach ([[1, 1, true], [2, 2, true], [3, 3, true], [4, 1, null]] as [$id, $config, $confirmed]) {
            $db->dbal->insert('calendar_appt_bookings', [
                'id' => $id, 'appt_config_id' => $config, 'start' => 1796119200, 'end' => 1796121000,
                'confirmed' => $confirmed, 'display_name' => 'Appointment holder', 'email' => 'secret@example.test', 'token' => 'booking-secret',
            ]);
        }
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $apps->method('getAppVersion')->willReturn('6.6.2');
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $access = $this->createStub(ScopedCollectionAccess::class);
        $access->method('visible')->willReturn([
            new Collection(12, 'owned', 'Calendar', 'alice', 'principals/users/alice', true, '/calendar/owned/'),
            new Collection(13, 'out-of-scope', 'Other', 'bob', 'principals/users/bob', true, '/calendar/other/'),
        ]);
        $gateway = new AppointmentGateway($apps, $session, $access,
            static fn () => throw new \LogicException('The native config service is not needed for the bounded booking read'),
            static fn () => throw new \LogicException('No links should be generated'),
            new PlanState($this->createStub(IConfig::class)));
        $reader = new AppointmentBookingReader($gateway, $db->connection(), new EventDates());

        $result = $reader->list(['from' => '2026-12-01', 'to' => '2026-12-02'], 'alice');
        self::assertSame([1, 4], array_column($result['bookings'], 'id'));
        self::assertSame(['confirmed', 'pending'], array_column($result['bookings'], 'status'));
        self::assertSame(['/calendar/owned/', '/calendar/owned/'], array_column($result['bookings'], 'calendar'));
        foreach (['booking-secret', 'link-secret', 'secret@example.test'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $reader->list(['from' => '2026-12-01', 'to' => '2026-12-02', 'appointment_id' => 2], 'alice')['bookings']);
    }
}
