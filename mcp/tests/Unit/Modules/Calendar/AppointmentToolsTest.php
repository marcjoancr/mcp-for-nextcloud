<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentPlanRenderer;
use OCA\Mcp\Modules\Calendar\Tools\CreateAppointmentConfig;
use OCA\Mcp\Modules\Calendar\Tools\DeleteAppointmentConfig;
use OCA\Mcp\Modules\Calendar\Tools\ListAppointmentBookings;
use OCA\Mcp\Modules\Calendar\Tools\ListAppointmentConfigs;
use OCA\Mcp\Modules\Calendar\Tools\UpdateAppointmentConfig;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use PHPUnit\Framework\TestCase;

final class AppointmentToolsTest extends TestCase
{
    public function testAllAppointmentToolsAreBoundToCalendarAppAndMutationsRequireApproval(): void
    {
        $definitions = [
            ListAppointmentConfigs::definition(),
            ListAppointmentBookings::definition(),
            CreateAppointmentConfig::definition(),
            UpdateAppointmentConfig::definition(),
            DeleteAppointmentConfig::definition(),
        ];
        self::assertSame(['calendar_list_appointments', 'calendar_list_appointment_bookings', 'calendar_create_appointment', 'calendar_update_appointment', 'calendar_delete_appointment'], array_map(static fn($definition) => $definition->name, $definitions));
        foreach ($definitions as $definition) { self::assertSame('calendar', $definition->app); }
        self::assertFalse(CreateAppointmentConfig::definition()->inputSchema['properties']['visibility']['default'] === 'PUBLIC');
        self::assertSame('PRIVATE', CreateAppointmentConfig::definition()->inputSchema['properties']['visibility']['default']);
        self::assertArrayHasKey('plan_state', CreateAppointmentConfig::definition()->inputSchema['properties']);
        self::assertArrayHasKey('plan_state', UpdateAppointmentConfig::definition()->inputSchema['properties']);
        self::assertArrayHasKey('plan_state', DeleteAppointmentConfig::definition()->inputSchema['properties']);
        self::assertSame('edit', UpdateAppointmentConfig::definition()->operation);
        self::assertTrue(DeleteAppointmentConfig::definition()->destructiveHint);
        self::assertFalse($definitions[0]->destructiveHint ?? false);
        self::assertFalse(ListAppointmentBookings::definition()->destructiveHint ?? false);
    }

    public function testAppointmentCompatibilityIsLimitedToQualifiedReleases(): void
    {
        self::assertTrue(AppointmentGateway::supportsVersion('6.6.2'));
        self::assertTrue(AppointmentGateway::supportsVersion('6.6.3'));
        self::assertFalse(AppointmentGateway::supportsVersion('6.6.1'));
        self::assertTrue(AppointmentGateway::supportsVersion('6.6.4'));
        self::assertFalse(AppointmentGateway::supportsVersion('6.6.4-rc.1'));
        self::assertFalse(AppointmentGateway::supportsVersion('6.6.5'));
        self::assertFalse(AppointmentGateway::supportsVersion('6.7.0'));
    }

    public function testCreateInputSchemaValidatesTheNativeWeeklyAvailabilityShape(): void
    {
        $slots = array_fill_keys(['MO','TU','WE','TH','FR','SA','SU'], []);
        $slots['MO'] = [['start' => '09:00', 'end' => '17:00']];
        $validated = ArgumentValidator::validate(CreateAppointmentConfig::definition()->inputSchema, [
            'calendar' => '/remote.php/dav/calendars/alice/personal/',
            'name' => 'Consultation',
            'availability' => ['timezoneId' => 'Europe/Madrid', 'slots' => $slots],
            'length' => 30,
            'increment' => 30,
        ]);
        self::assertSame('PRIVATE', $validated['visibility']);
        self::assertSame('', $validated['description']);
        self::assertSame(0, $validated['buffer']);
    }

    public function testUpdateSchemaRequiresOnlyIdAndDoesNotInjectCreateDefaults(): void
    {
        $definition = UpdateAppointmentConfig::definition();
        self::assertSame(['id'], $definition->inputSchema['required']);
        $validated = ArgumentValidator::validate($definition->inputSchema, ['id' => 17, 'name' => 'Renamed']);
        self::assertSame(['id' => 17, 'name' => 'Renamed'], $validated);
    }

    public function testPreviewRenderingIsInertAndNeverPrintsASecretBookingLink(): void
    {
        $rendered = (new AppointmentPlanRenderer())->renderPlan('calendar_create_appointment', [
            'action' => 'create',
            'after' => [
                'name' => "Consultation\n# forged",
                'visibility' => 'PRIVATE',
                'calendar' => 'Personal',
                'durationMinutes' => 30,
                'availability' => ['timezoneId' => 'UTC', 'slots' => ['MO' => [['start' => '09:00', 'end' => '10:00']]]],
                'link' => 'https://cloud.example/secret-token',
            ],
            'scope' => 'Private profile listing.',
            'consequence' => 'Native booking page.',
            'plan_state' => 'state-secret',
        ]);
        self::assertIsString($rendered);
        self::assertStringNotContainsString("\n# forged", $rendered);
        self::assertStringNotContainsString('secret-token', $rendered);
        self::assertStringNotContainsString('state-secret', $rendered);
        self::assertStringContainsString('09:00', $rendered);
    }
}
