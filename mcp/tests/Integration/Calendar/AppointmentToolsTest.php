<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;
use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\VObject\Reader;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;

final class AppointmentToolsTest extends OAuthAccountTestCase
{
    public function testPrivateAppointmentConfigUsesNativeBookingFlowAndCanBeRemoved(): void
    {
        $apps = Server::get(IAppManager::class);
        if (!$apps->isEnabledForUser('calendar', $this->user)) {
            self::markTestSkipped('Calendar app was not provisioned in this integration cell.');
        }
        $version = $apps->getAppVersion('calendar');
        if (!is_string($version) || !AppointmentGateway::supportsVersion($version)) {
            self::markTestSkipped('This Calendar version has not been verified by the appointment adapter.');
        }

        $policy = Server::get(GrantPolicy::class);
        $wasConnected = $policy->connected($this->uid);
        $globalWasEnabled = $policy->globalEnabled();
        $toolNames = ['calendar_list_appointments', 'calendar_create_appointment', 'calendar_update_appointment', 'calendar_delete_appointment', 'calendar_list_appointment_bookings'];
        $mapper = Server::get(ToolGrantsMapper::class);
        $previousGrants = $mapper->forPrincipals(ToolGrantsMapper::USER, [$this->uid])[$this->uid] ?? [];
        $service = Server::get('OCA\\Calendar\\Service\\Appointments\\AppointmentConfigService');
        $createdId = null;
        $appointmentName = null;
        $nativeConfig = null;
        $booking = null;
        $bookingService = null;
        $originalMailService = null;
        $calendarId = null;
        $calendarObjectUrisBeforeBooking = [];
        try {
            $policy->setGlobalEnabled(true);
            $policy->setConnected($this->uid, true);
            Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($this->user));
            $this->user->setEMailAddress($this->uid . '@example.test');
            // Store explicit grants under the policy transaction; grants are per tool and never inferred from module access.
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function () use ($mapper, $toolNames): bool {
                foreach ($toolNames as $tool) { $mapper->set(ToolGrantsMapper::USER, $this->uid, $tool, ToolGrantsMapper::ALLOW); }
                return true;
            });

            $client = new McpClient(LoginFactory::basic($this->uid));
            $availability = ['timezoneId' => 'UTC', 'slots' => array_fill_keys(['MO','TU','WE','TH','FR','SA','SU'], [['start' => '09:00', 'end' => '17:00']])];
            $appointmentName = 'MCP appointment ' . bin2hex(random_bytes(3));
            $arguments = [
                'calendar' => '/remote.php/dav/calendars/' . $this->uid . '/personal/',
                'name' => $appointmentName,
                'availability' => $availability,
                'length' => 30,
                'increment' => 15,
                'preparationDuration' => 2,
                'followupDuration' => 3,
                'buffer' => 4,
                'futureLimit' => 14,
            ];
            $plan = $client->tool('calendar_create_appointment', $arguments);
            self::assertTrue($plan->ok(), $plan->describe());
            $planned = $plan->structured();
            self::assertSame('PRIVATE', $planned['after']['visibility']);
            self::assertStringContainsString('anyone who receives its booking URL can book', $planned['scope']);
            self::assertArrayNotHasKey('link', $planned, 'a bearer booking link is never shown before confirmation');
            self::assertArrayNotHasKey('token', $planned['after']);
            self::assertIsString($planned['plan_state']);

            $created = $client->write('calendar_create_appointment', $arguments + ['plan_state' => $planned['plan_state']]);
            self::assertTrue($created->ok(), $created->describe());
            $config = $created->json();
            $createdId = $config['id'];
            self::assertSame('PRIVATE', $config['visibility']);
            self::assertArrayNotHasKey('token', $config);
            self::assertIsString($config['link']);
            self::assertStringContainsString('/apps/calendar/appointment/', $config['link']);

            $nativeConfig = $service->findByIdAndUser($createdId, $this->uid);
            self::assertSame(1800, $nativeConfig->getLength(), '30 MCP minutes are stored as 1800 native seconds');
            self::assertSame(900, $nativeConfig->getIncrement(), '15 MCP minutes are stored as 900 native seconds');
            self::assertSame(120, $nativeConfig->getPreparationDuration());
            self::assertSame(180, $nativeConfig->getFollowupDuration());
            self::assertSame(240, $nativeConfig->getTimeBeforeNextSlot());
            self::assertSame(14 * 86400, $nativeConfig->getFutureLimit(), 'futureLimit days are stored as native seconds');
            $nativeMondaySlot = $nativeConfig->getAvailabilityAsArray()['slots']['MO'][0];
            self::assertIsInt($nativeMondaySlot['start']);
            self::assertIsInt($nativeMondaySlot['end']);
            self::assertSame('09:00', (new \DateTimeImmutable('@' . $nativeMondaySlot['start']))->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
            self::assertSame('17:00', (new \DateTimeImmutable('@' . $nativeMondaySlot['end']))->setTimezone(new \DateTimeZone('UTC'))->format('H:i'));
            $token = $nativeConfig->getToken();
            self::assertIsString($token);
            self::assertNotSame('', $token);
            $bookingPath = '/apps/calendar/appointment/' . rawurlencode($token);
            self::assertSame($bookingPath, parse_url($config['link'], PHP_URL_PATH));
            // Pin the request to this fixture's loopback host; never follow a redirect from the returned URL.
            $bookingPage = Http::request('GET', Http::BASE . $bookingPath, ['Accept: text/html']);
            self::assertSame(200, $bookingPage['status'], 'the native booking route serves its public booking page');
            self::assertStringContainsString('initial-state-calendar-config', $bookingPage['body']);
            self::assertSame($this->uid, $nativeConfig->getUserId());
            self::assertSame('personal', $nativeConfig->getTargetCalendarUri());

            $updateArgs = ['id' => $createdId, 'name' => $appointmentName . ' updated'];
            $updatePlan = $client->tool('calendar_update_appointment', $updateArgs);
            self::assertTrue($updatePlan->ok(), $updatePlan->describe());
            self::assertSame(['name' => $updateArgs['name']], $updatePlan->structured()['after']);
            $updated = $client->write('calendar_update_appointment', $updateArgs + ['plan_state' => $updatePlan->structured()['plan_state']]);
            self::assertTrue($updated->ok(), $updated->describe());
            $updatedConfig = $updated->json();
            self::assertSame($updateArgs['name'], $updatedConfig['name']);
            self::assertSame($config['description'], $updatedConfig['description']);
            self::assertSame($config['link'], $updatedConfig['link']);
            $nativeConfig = $service->findByIdAndUser($createdId, $this->uid);

            // Exercise the complete native book/confirm path while replacing only MailService with a no-op test double.
            $calendarBackend = Server::get(CalDavBackend::class);
            foreach ($calendarBackend->getCalendarsForUser('principals/users/' . $this->uid) as $calendarRow) {
                if (($calendarRow['uri'] ?? null) === 'personal') { $calendarId = (int)$calendarRow['id']; break; }
            }
            self::assertNotNull($calendarId, 'the appointment target calendar is present');
            $calendarObjectUrisBeforeBooking = array_column($calendarBackend->getCalendarObjects($calendarId), 'uri');
            $bookingService = Server::get('OCA\\Calendar\\Service\\Appointments\\BookingService');
            $mailProperty = new \ReflectionProperty($bookingService, 'mailService');
            $originalMailService = $mailProperty->getValue($bookingService);
            $mailDouble = new class extends \OCA\Calendar\Service\Appointments\MailService {
                /** @var list<array{method:string,recipient:string}> */
                public array $calls = [];
                public function __construct() {}
                public function sendConfirmationEmail(\OCA\Calendar\Db\Booking $booking, \OCA\Calendar\Db\AppointmentConfig $config): void { $this->calls[] = ['method' => 'confirmation', 'recipient' => $booking->getEmail()]; }
                public function sendBookingInformationEmail(\OCA\Calendar\Db\Booking $booking, \OCA\Calendar\Db\AppointmentConfig $config, string $calendar): void { $this->calls[] = ['method' => 'guest', 'recipient' => $booking->getEmail()]; }
                public function sendOrganizerBookingInformationEmail(\OCA\Calendar\Db\Booking $booking, \OCA\Calendar\Db\AppointmentConfig $config, string $calendar): void { $user = \OCP\Server::get(\OCP\IUserManager::class)->get($config->getUserId()); $this->calls[] = ['method' => 'organizer', 'recipient' => $user?->getEMailAddress() ?? '']; }
                public function sendOrganizerBookingInformationNotification(\OCA\Calendar\Db\Booking $booking, \OCA\Calendar\Db\AppointmentConfig $config): void { $this->calls[] = ['method' => 'notification', 'recipient' => $config->getUserId()]; }
            };
            $mailProperty->setValue($bookingService, $mailDouble);
            $slot = new \DateTimeImmutable('tomorrow 09:00:00', new \DateTimeZone('UTC'));
            $booking = $bookingService->book($nativeConfig, $slot->getTimestamp(), $slot->getTimestamp() + 1800, 'UTC', 'MCP integration guest', 'mcp-it-booking@example.test', 'test booking');
            self::assertNotTrue($booking->isConfirmed(), 'a fresh booking still requires confirmation');
            $bookingArgs = ['from' => $slot->format('Y-m-d'), 'to' => $slot->modify('+1 day')->format('Y-m-d'), 'appointment_id' => $createdId];
            $pendingList = $client->tool('calendar_list_appointment_bookings', $bookingArgs);
            self::assertTrue($pendingList->ok(), $pendingList->describe());
            self::assertSame(['pending'], array_column($pendingList->json()['bookings'], 'status'));
            $confirmed = $bookingService->confirmBooking($booking, $nativeConfig);
            self::assertTrue($confirmed->isConfirmed());
            $confirmedList = $client->tool('calendar_list_appointment_bookings', $bookingArgs);
            self::assertTrue($confirmedList->ok(), $confirmedList->describe());
            $bookings = $confirmedList->json()['bookings'];
            self::assertSame(['confirmed'], array_column($bookings, 'status'));
            self::assertSame(['MCP integration guest'], array_column($bookings, 'guest_name'));
            self::assertArrayNotHasKey('token', $bookings[0]);
            self::assertArrayNotHasKey('email', $bookings[0]);
            $appointmentEvents = [];
            $beforeUris = array_fill_keys($calendarObjectUrisBeforeBooking, true);
            foreach ($calendarBackend->getCalendarObjects($calendarId) as $object) {
                $uri = $object['uri'] ?? null;
                if (!is_string($uri) || isset($beforeUris[$uri])) { continue; }
                $stored = $calendarBackend->getCalendarObject($calendarId, $uri);
                if (!is_array($stored) || !is_string($stored['calendardata'] ?? null)) { continue; }
                $eventCalendar = Reader::read($stored['calendardata']);
                if (($eventCalendar->VEVENT->{'X-NC-APPOINTMENT'} ?? null)?->getValue() === $nativeConfig->getToken()) {
                    $appointmentEvents[] = $uri;
                }
            }
            self::assertCount(1, $appointmentEvents, 'native confirmation writes one event marked with this appointment token');
            self::assertSame(['confirmation', 'guest', 'organizer', 'notification'], array_column($mailDouble->calls, 'method'));
            self::assertSame('mcp-it-booking@example.test', $mailDouble->calls[0]['recipient']);
            self::assertSame('mcp-it-booking@example.test', $mailDouble->calls[1]['recipient']);
            self::assertSame($this->uid . '@example.test', $mailDouble->calls[2]['recipient']);
            self::assertSame($this->uid, $mailDouble->calls[3]['recipient']);

            $listed = $client->tool('calendar_list_appointments');
            self::assertTrue($listed->ok(), $listed->describe());
            $listing = $listed->json();
            self::assertSame(false, $listing['truncated']);
            self::assertContains($createdId, array_column($listing['appointments'], 'id'));
            $listedConfig = array_values(array_filter($listing['appointments'], static fn (array $row): bool => $row['id'] === $createdId))[0];
            self::assertSame($config['link'], $listedConfig['link']);

            $deleteArgs = ['id' => $createdId];
            $deletePlan = $client->tool('calendar_delete_appointment', $deleteArgs);
            self::assertTrue($deletePlan->ok(), $deletePlan->describe());
            self::assertStringContainsString('existing booked events remain', $deletePlan->structured()['consequence']);
            $removed = $client->write('calendar_delete_appointment', $deleteArgs + ['plan_state' => $deletePlan->structured()['plan_state']]);
            self::assertTrue($removed->ok(), $removed->describe());
            $createdId = null;
            self::assertSame([], $service->getAllAppointmentConfigurations($this->uid));
            $retiredLink = Http::request('GET', Http::BASE . $bookingPath, ['Accept: text/html']);
            self::assertStringNotContainsString('initial-state-calendar-config', $retiredLink['body'], 'the retired link does not load an appointment');
            self::assertContains($appointmentEvents[0], array_column($calendarBackend->getCalendarObjects($calendarId), 'uri'));
        } finally {
            if ($bookingService !== null && $originalMailService !== null) {
                (new \ReflectionProperty($bookingService, 'mailService'))->setValue($bookingService, $originalMailService);
            }
            if ($calendarId !== null && $nativeConfig !== null) {
                $calendarBackend = Server::get(CalDavBackend::class);
                $before = array_fill_keys($calendarObjectUrisBeforeBooking, true);
                foreach ($calendarBackend->getCalendarObjects($calendarId) as $object) {
                    $uri = $object['uri'] ?? null;
                    if (!is_string($uri) || isset($before[$uri])) { continue; }
                    $stored = $calendarBackend->getCalendarObject($calendarId, $uri);
                    if (!is_array($stored) || !is_string($stored['calendardata'] ?? null)) { continue; }
                    $vcalendar = Reader::read($stored['calendardata']);
                    foreach (['X-NC-APPOINTMENT', 'X-NC-PRE-APPOINTMENT', 'X-NC-POST-APPOINTMENT'] as $marker) {
                        if (($vcalendar->VEVENT->{$marker} ?? null)?->getValue() === $nativeConfig->getToken()) {
                            $calendarBackend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
                            break;
                        }
                    }
                }
            }
            if ($booking !== null) {
                Server::get('OCA\\Calendar\\Db\\BookingMapper')->delete($booking);
            }
            if ($createdId !== null) { $service->delete((int)$createdId, $this->uid); }
            elseif ($appointmentName !== null) {
                foreach ($service->getAllAppointmentConfigurations($this->uid) as $orphan) {
                    if ($orphan->getUserId() === $this->uid && $orphan->getName() === $appointmentName) {
                        $service->delete($orphan->getId(), $this->uid);
                    }
                }
            }
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function () use ($mapper, $previousGrants, $toolNames): bool {
                $changed = false;
                foreach ($toolNames as $tool) {
                    $old = $previousGrants[$tool] ?? null;
                    $mapper->set(ToolGrantsMapper::USER, $this->uid, $tool, $old);
                    $changed = $changed || $old !== ToolGrantsMapper::ALLOW;
                }
                return $changed;
            });
            $policy->setConnected($this->uid, $wasConnected);
            $policy->setGlobalEnabled($globalWasEnabled);
        }
    }
}
