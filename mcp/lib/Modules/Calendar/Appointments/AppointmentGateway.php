<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Appointments;

use DateTimeZone;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Throwable;

/** Actor-bound adapter for the Calendar app's native appointment configuration service. */
final class AppointmentGateway
{
    private const VERIFIED_VERSIONS = ['6.6.2', '6.6.3', '6.6.4'];

    /**
     * @param \Closure(): object $nativeServiceFactory lazily resolves Calendar's AppointmentConfigService
     * @param \Closure(string): string $linkFactory builds the native shareable booking URL from its token
     */
    public function __construct(
        private IAppManager $apps,
        private IUserSession $session,
        private ScopedCollectionAccess $calendars,
        private \Closure $nativeServiceFactory,
        private \Closure $linkFactory,
        private PlanState $planState,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $actor): array
    {
        $service = $this->service($actor);
        try {
            $configs = $service->getAllAppointmentConfigurations($actor);
            if (!is_array($configs)) {
                throw new \RuntimeException();
            }
            $out = [];
            $visibleCount = 0;
            $visibleCalendars = $this->ownedCalendarsByUri($actor);
            foreach ($configs as $config) {
                if (!is_object($config) || $this->get($config, 'getUserId') !== $actor) { continue; }
                $uri = $this->get($config, 'getTargetCalendarUri');
                if (!is_string($uri) || !isset($visibleCalendars[$uri])) { continue; }
                if ($visibleCount < 100) { $out[] = $this->project($config, $actor, true); }
                ++$visibleCount;
            }
            return ['appointments' => $out, 'truncated' => $visibleCount > 100];
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure('Could not read appointment configurations.', 0, $e);
        }
    }

    /** @return array<string, Collection> Calendars eligible for this actor's appointment book. */
    public function bookingCalendars(string $actor): array
    {
        $this->assertReady($actor);
        return $this->ownedCalendarsByUri($actor);
    }

    public static function supportsVersion(string $version): bool
    {
        return in_array($version, self::VERIFIED_VERSIONS, true);
    }

    /** @param array<string, mixed> $arguments */
    public function previewCreate(array $arguments, string $actor, string $tool): array
    {
        $this->assertReady($actor);
        $normalized = $this->normalize($arguments, false);
        $calendar = $this->calendar($actor, $normalized['calendar']);
        return $this->plan($tool, $actor, 'create', $normalized, null, $calendar);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function create(array $arguments, string $actor, string $tool): array
    {
        PlanState::require($arguments);
        $normalized = $this->normalize($arguments, false);
        $calendar = $this->calendar($actor, $normalized['calendar']);
        $plan = $this->plan($tool, $actor, 'create', $normalized, null, $calendar);
        if (!PlanState::matches($plan['plan_state'], $arguments)) {
            throw new PlanChanged($this->previewCreate($arguments, $actor, $tool) + ['warning' => 'The configuration changed after its preview.']);
        }
        try {
            $config = $this->service($actor)->create(
                $normalized['name'],
                $normalized['description'],
                $normalized['location'],
                $normalized['visibility'],
                $actor,
                $calendar->uri,
                $this->availabilityToNative($normalized['availability']),
                $this->minutesToSeconds($normalized['length']),
                $this->minutesToSeconds($normalized['increment']),
                $this->minutesToSeconds($normalized['preparationDuration']),
                $this->minutesToSeconds($normalized['followupDuration']),
                $this->minutesToSeconds($normalized['buffer']),
                $normalized['dailyMax'],
                [],
                $normalized['start'],
                $normalized['end'],
                $normalized['futureLimit'] === null ? null : $normalized['futureLimit'] * 86400,
                false,
            );
            return $this->project($config, $actor, true);
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure('Could not create the appointment configuration.', 0, $e);
        }
    }

    /** @param array<string, mixed> $arguments */
    public function previewUpdate(array $arguments, string $actor, string $tool): array
    {
        $this->assertReady($actor);
        $service = $this->service($actor);
        $config = $this->ownedConfig($service, $this->id($arguments), $actor);
        $normalized = $this->normalizeUpdate($arguments, $config, $actor);
        $calendar = $this->calendar($actor, $normalized['calendar']);
        return $this->plan($tool, $actor, 'update', $normalized, $config, $calendar);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function update(array $arguments, string $actor, string $tool): array
    {
        PlanState::require($arguments);
        $service = $this->service($actor);
        $config = $this->ownedConfig($service, $this->id($arguments), $actor);
        $normalized = $this->normalizeUpdate($arguments, $config, $actor);
        $calendar = $this->calendar($actor, $normalized['calendar']);
        $plan = $this->plan($tool, $actor, 'update', $normalized, $config, $calendar);
        if (!PlanState::matches($plan['plan_state'], $arguments)) {
            throw new PlanChanged($this->previewUpdate($arguments, $actor, $tool) + ['warning' => 'The configuration changed after its preview.']);
        }
        try {
            $fields = $normalized['fields'];
            if (isset($fields['name'])) { $config->setName($normalized['name']); }
            if (isset($fields['description'])) { $config->setDescription($normalized['description']); }
            if (isset($fields['location'])) { $config->setLocation($normalized['location']); }
            if (isset($fields['visibility'])) { $config->setVisibility($normalized['visibility']); }
            if (isset($fields['calendar'])) { $config->setTargetCalendarUri($calendar->uri); }
            if (isset($fields['availability'])) { $config->setAvailabilityAsArray($this->availabilityToNative($normalized['availability'])); }
            if (isset($fields['length'])) { $config->setLength($this->minutesToSeconds($normalized['length'])); }
            if (isset($fields['increment'])) { $config->setIncrement($this->minutesToSeconds($normalized['increment'])); }
            if (isset($fields['preparationDuration'])) { $config->setPreparationDuration($this->minutesToSeconds($normalized['preparationDuration'])); }
            if (isset($fields['followupDuration'])) { $config->setFollowupDuration($this->minutesToSeconds($normalized['followupDuration'])); }
            if (isset($fields['buffer'])) { $config->setTimeBeforeNextSlot($this->minutesToSeconds($normalized['buffer'])); }
            if (isset($fields['dailyMax'])) { $config->setDailyMax($normalized['dailyMax']); }
            if (isset($fields['start'])) { $config->setStart($normalized['start']); }
            if (isset($fields['end'])) { $config->setEnd($normalized['end']); }
            if (isset($fields['futureLimit'])) { $config->setFutureLimit($normalized['futureLimit'] === null ? null : $normalized['futureLimit'] * 86400); }
            return $this->project($service->update($config), $actor, true);
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure('Could not update the appointment configuration.', 0, $e);
        }
    }

    /** @param array<string, mixed> $arguments */
    public function previewDelete(array $arguments, string $actor, string $tool): array
    {
        $this->assertReady($actor);
        $id = $this->id($arguments);
        $config = $this->ownedConfig($this->service($actor), $id, $actor);
        return $this->plan($tool, $actor, 'delete', ['id' => $id], $config, null);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function delete(array $arguments, string $actor, string $tool): array
    {
        PlanState::require($arguments);
        $id = $this->id($arguments);
        $service = $this->service($actor);
        $config = $this->ownedConfig($service, $id, $actor);
        $plan = $this->plan($tool, $actor, 'delete', ['id' => $id], $config, null);
        if (!PlanState::matches($plan['plan_state'], $arguments)) {
            throw new PlanChanged($this->previewDelete($arguments, $actor, $tool) + ['warning' => 'The configuration changed after its preview.']);
        }
        try {
            $service->delete($id, $actor);
            return ['deleted' => $id, 'message' => 'Appointment configuration removed. Existing booked events are not removed.'];
        } catch (Throwable $e) {
            throw new ToolFailure('Could not remove the appointment configuration.', 0, $e);
        }
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function plan(string $tool, string $actor, string $action, array $values, ?object $current, ?Collection $calendar): array
    {
        $currentState = $current === null ? null : $this->state($current);
        if ($currentState !== null) {
            $currentState['calendarFreeBusyUris'] = $this->get($current, 'getCalendarFreebusyUrisAsArray');
        }
        $fingerprint = [
            'action' => $action,
            'arguments' => $values,
            'current' => $currentState,
            'calendar' => $calendar === null ? null : ['id' => $calendar->id, 'uri' => $calendar->uri, 'name' => $calendar->name],
        ];
        $after = null;
        if ($action === 'create') {
            $after = $this->planValues($values, $calendar);
            $after['linkCreatedAfterConfirmation'] = true;
        } elseif ($action === 'update') {
            $after = [];
            foreach ($values['fields'] as $field => $_) {
                $key = $field === 'length' ? 'durationMinutes' : $field;
                $after[$key] = $field === 'calendar' ? $calendar?->name : $values[$field];
            }
        }
        $before = $current === null ? null : $this->state($current);
        if ($current !== null && $action === 'update') {
            $targetUri = $this->get($current, 'getTargetCalendarUri');
            $before['calendar'] = is_string($targetUri)
                ? ($this->ownedCalendarsByUri($actor)[$targetUri]->name ?? $targetUri)
                : '';
        }
        return [
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'scope' => ($values['visibility'] ?? $this->get($current, 'getVisibility', 'PRIVATE')) === 'PUBLIC'
                ? 'Anyone with the URL can book this appointment; it is listed on your public profile.'
                : 'The configuration is not listed on your public profile, but anyone who receives its booking URL can book.',
            'consequence' => $action === 'delete' ? 'Future bookings stop; existing booked events remain.' : 'Nextcloud owns the public booking page and handles reservations.',
            'plan_state' => $this->planState->of($tool, $actor, $fingerprint),
        ];
    }

    /** @param array<string, mixed> $values */
    private function planValues(array $values, ?Collection $calendar): array
    {
        return [
            'name' => $values['name'],
            'description' => $values['description'],
            'location' => $values['location'],
            'visibility' => $values['visibility'],
            'calendar' => $calendar?->name,
            'durationMinutes' => $values['length'],
            'increment' => $values['increment'],
            'preparationDuration' => $values['preparationDuration'],
            'followupDuration' => $values['followupDuration'],
            'buffer' => $values['buffer'],
            'dailyMax' => $values['dailyMax'],
            'start' => $values['start'],
            'end' => $values['end'],
            'futureLimit' => $values['futureLimit'],
            'availability' => $values['availability'],
        ];
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function normalize(array $raw, bool $updating): array
    {
        $id = $updating ? $this->id($raw) : null;
        $calendar = $raw['calendar'] ?? null;
        $name = $raw['name'] ?? null;
        $description = $raw['description'] ?? '';
        $location = $raw['location'] ?? null;
        $availability = $raw['availability'] ?? null;
        $length = $raw['length'] ?? null;
        $increment = $raw['increment'] ?? null;
        $visibility = $raw['visibility'] ?? 'PRIVATE';
        if (!is_string($calendar) || $calendar === '' || !is_string($name) || trim($name) === '' || mb_strlen($name) > 100
            || !is_string($description) || mb_strlen($description) > 1000
            || (!is_string($location) && $location !== null) || (is_string($location) && mb_strlen($location) > 255)
            || !is_array($availability) || !is_int($length) || !is_int($increment)
            || !in_array($visibility, ['PRIVATE', 'PUBLIC'], true)) {
            throw new \InvalidArgumentException('Invalid appointment configuration.');
        }
        if ($length < 5 || $length > 720 || $increment < 5 || $increment > $length) {
            throw new \InvalidArgumentException('Invalid appointment duration or slot increment.');
        }
        $this->validateAvailability($availability);
        try {
            new DateTimeZone($availability['timezoneId']);
        } catch (Throwable) {
            throw new \InvalidArgumentException('Invalid appointment timezone.');
        }
        $preparation = $raw['preparationDuration'] ?? 0;
        $followup = $raw['followupDuration'] ?? 0;
        $buffer = $raw['buffer'] ?? 0;
        $dailyMax = $raw['dailyMax'] ?? null;
        $start = $raw['start'] ?? null;
        $end = $raw['end'] ?? null;
        $futureLimit = $raw['futureLimit'] ?? null;
        foreach ([$preparation, $followup, $buffer] as $value) {
            if (!is_int($value) || $value < 0 || $value > 1440) { throw new \InvalidArgumentException('Invalid appointment buffer.'); }
        }
        if (($dailyMax !== null && (!is_int($dailyMax) || $dailyMax < 1 || $dailyMax > 100))
            || ($start !== null && !is_int($start)) || ($end !== null && !is_int($end))
            || (($start === null) !== ($end === null)) || ($start !== null && $end <= $start)
            || ($futureLimit !== null && (!is_int($futureLimit) || $futureLimit < 1 || $futureLimit > 365))) {
            throw new \InvalidArgumentException('Invalid appointment date or booking limit.');
        }
        return [
            'id' => $id,
            'calendar' => $calendar,
            'name' => trim($name),
            'description' => $description,
            'location' => $location,
            'availability' => $availability,
            'length' => $length,
            'increment' => $increment,
            'visibility' => $visibility,
            'preparationDuration' => $preparation,
            'followupDuration' => $followup,
            'buffer' => $buffer,
            'dailyMax' => $dailyMax,
            'start' => $start,
            'end' => $end,
            'futureLimit' => $futureLimit,
        ];
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function normalizeUpdate(array $raw, object $current, string $actor): array
    {
        $id = $this->id($raw);
        $uri = $this->get($current, 'getTargetCalendarUri');
        $currentCalendar = is_string($uri) ? ($this->ownedCalendarsByUri($actor)[$uri] ?? null) : null;
        if (!$currentCalendar instanceof Collection) { throw new ToolFailure(ToolFailure::NOT_FOUND); }

        $base = [
            'calendar' => $currentCalendar->path,
            'name' => $this->get($current, 'getName'),
            'description' => $this->get($current, 'getDescription'),
            'location' => $this->get($current, 'getLocation'),
            'availability' => $this->availabilityFromNative($this->get($current, 'getAvailabilityAsArray')),
            'length' => $this->secondsToMinutes($this->get($current, 'getLength')),
            'increment' => $this->secondsToMinutes($this->get($current, 'getIncrement')),
            'visibility' => $this->get($current, 'getVisibility'),
            'preparationDuration' => $this->secondsToMinutes($this->get($current, 'getPreparationDuration')),
            'followupDuration' => $this->secondsToMinutes($this->get($current, 'getFollowupDuration')),
            'buffer' => $this->secondsToMinutes($this->get($current, 'getTimeBeforeNextSlot')),
            'dailyMax' => $this->get($current, 'getDailyMax'),
            'start' => $this->get($current, 'getStart'),
            'end' => $this->get($current, 'getEnd'),
            'futureLimit' => $this->secondsToDays($this->get($current, 'getFutureLimit')),
        ];
        $fields = array_intersect_key($raw, $base);
        if ($fields === []) { throw new \InvalidArgumentException('Choose at least one appointment field to update.'); }
        $normalized = $this->normalize(array_replace($base, $fields), false);
        $normalized['id'] = $id;
        $normalized['fields'] = array_fill_keys(array_keys($fields), true);
        return $normalized;
    }

    /** @param array<string, mixed> $availability */
    private function validateAvailability(array $availability): void
    {
        $days = ['FR', 'MO', 'SA', 'SU', 'TH', 'TU', 'WE'];
        $keys = array_keys($availability);
        sort($keys);
        if ($keys !== ['slots', 'timezoneId'] || !is_string($availability['timezoneId'] ?? null) || !is_array($availability['slots'] ?? null)) {
            throw new \InvalidArgumentException('Invalid weekly availability.');
        }
        $dayKeys = array_keys($availability['slots']);
        sort($dayKeys);
        if ($dayKeys !== $days) { throw new \InvalidArgumentException('Invalid weekly availability days.'); }
        foreach ($days as $day) {
            $slots = $availability['slots'][$day];
            if (!is_array($slots) || count($slots) > 12) { throw new \InvalidArgumentException('Invalid weekly time slots.'); }
            $previousEnd = -1;
            foreach ($slots as $slot) {
                if (!is_array($slot)) { throw new \InvalidArgumentException('Invalid weekly time slot.'); }
                $slotKeys = array_keys($slot);
                sort($slotKeys);
                if ($slotKeys !== ['end', 'start'] || !is_string($slot['start']) || !is_string($slot['end'])
                    || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $slot['start'])
                    || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $slot['end'])) {
                    throw new \InvalidArgumentException('Invalid weekly time slot.');
                }
                $start = $this->minutes($slot['start']);
                $end = $this->minutes($slot['end']);
                if ($end <= $start || $start < $previousEnd) { throw new \InvalidArgumentException('Overlapping or reversed weekly time slots.'); }
                $previousEnd = $end;
            }
        }
    }

    private function minutes(string $time): int { return ((int)substr($time, 0, 2) * 60) + (int)substr($time, 3, 2); }

    private function minutesToSeconds(int $minutes): int { return $minutes * 60; }
    private function secondsToMinutes(mixed $seconds): int
    {
        if (!is_int($seconds) || $seconds < 0 || $seconds % 60 !== 0) { throw new \UnexpectedValueException('Calendar returned an invalid appointment duration.'); }
        return intdiv($seconds, 60);
    }
    private function secondsToDays(mixed $seconds): ?int
    {
        if ($seconds === null) { return null; }
        if (!is_int($seconds) || $seconds < 0 || $seconds % 86400 !== 0) { throw new \UnexpectedValueException('Calendar returned an invalid future booking limit.'); }
        return intdiv($seconds, 86400);
    }

    /** Convert weekly HH:mm slots to Calendar's timestamp representation on a stable reference week. */
    private function availabilityToNative(array $availability): array
    {
        $zone = new DateTimeZone($availability['timezoneId']);
        $weekdays = ['MO' => 0, 'TU' => 1, 'WE' => 2, 'TH' => 3, 'FR' => 4, 'SA' => 5, 'SU' => 6];
        foreach ($weekdays as $day => $offset) {
            $date = (new \DateTimeImmutable('2024-01-01 00:00:00', $zone))->modify('+' . $offset . ' days')->format('Y-m-d');
            foreach ($availability['slots'][$day] as &$slot) {
                foreach (['start', 'end'] as $edge) {
                    $point = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $slot[$edge], $zone);
                    if (!$point instanceof \DateTimeImmutable) { throw new \InvalidArgumentException('Invalid weekly time slot.'); }
                    $slot[$edge] = $point->getTimestamp();
                }
            }
            unset($slot);
        }
        return $availability;
    }

    /** Convert native timestamp slots back to the stable MCP HH:mm representation. */
    private function availabilityFromNative(mixed $availability): array
    {
        if (!is_array($availability) || !is_string($availability['timezoneId'] ?? null) || !is_array($availability['slots'] ?? null)) {
            throw new \UnexpectedValueException('Calendar returned invalid weekly availability.');
        }
        $zone = new DateTimeZone($availability['timezoneId']);
        foreach ($availability['slots'] as $day => &$slots) {
            if (!is_array($slots)) { throw new \UnexpectedValueException('Calendar returned invalid weekly availability.'); }
            foreach ($slots as &$slot) {
                foreach (['start', 'end'] as $edge) {
                    if (!is_int($slot[$edge] ?? null)) { throw new \UnexpectedValueException('Calendar returned invalid weekly availability.'); }
                    $slot[$edge] = (new \DateTimeImmutable('@' . $slot[$edge]))->setTimezone($zone)->format('H:i');
                }
            }
            unset($slot);
        }
        unset($slots);
        return $availability;
    }

    private function id(array $arguments): int
    {
        $id = $arguments['id'] ?? null;
        if (!is_int($id) || $id < 1) { throw new \InvalidArgumentException('Invalid appointment id.'); }
        return $id;
    }

    private function assertReady(string $actor): void
    {
        $user = $this->session->getUser();
        if ($actor === '' || $user === null || $user->getUID() !== $actor) {
            throw new ToolFailure('Appointment configuration is unavailable for this account.');
        }
        try {
            if (!$this->apps->isEnabledForUser('calendar', $user)) {
                throw new ToolFailure('Appointment booking is unavailable.');
            }
            $version = $this->apps->getAppVersion('calendar');
            if (!is_string($version) || !self::supportsVersion($version)) {
                throw new ToolFailure('Appointment booking is unavailable on this Calendar version.');
            }
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure('Appointment booking is unavailable.', 0, $e);
        }
    }

    private function service(string $actor): object
    {
        $this->assertReady($actor);
        try {
            $service = ($this->nativeServiceFactory)();
            foreach (['getAllAppointmentConfigurations', 'findByIdAndUser', 'create', 'update', 'delete'] as $method) {
                if (!is_object($service) || !method_exists($service, $method)) { throw new \RuntimeException(); }
            }
            return $service;
        } catch (Throwable $e) {
            throw new ToolFailure('Appointment booking is unavailable.', 0, $e);
        }
    }

    private function calendar(string $actor, string $path): Collection
    {
        try {
            $calendar = $this->calendars->resolveWritable($actor, $path, 'VEVENT');
            if ($calendar->ownerId !== $actor) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
            return $calendar;
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure(ToolFailure::NOT_FOUND, 0, $e);
        }
    }


    /** @return array<string, Collection> owner calendars in the caller's active event scope, keyed by native URI */
    private function ownedCalendarsByUri(string $actor): array
    {
        $out = [];
        foreach ($this->calendars->visible($actor, 'VEVENT') as $calendar) {
            if ($calendar instanceof Collection && $calendar->ownerId === $actor) { $out[$calendar->uri] = $calendar; }
        }
        return $out;
    }

    private function ownedConfig(object $service, int $id, string $actor): object
    {
        try {
            $config = $service->findByIdAndUser($id, $actor);
            if (!is_object($config) || $this->get($config, 'getUserId') !== $actor) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
            $uri = $this->get($config, 'getTargetCalendarUri');
            if (!is_string($uri) || !isset($this->ownedCalendarsByUri($actor)[$uri])) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
            return $config;
        } catch (ToolFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolFailure(ToolFailure::NOT_FOUND, 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function state(object $config): array
    {
        return [
            'id' => $this->get($config, 'getId'),
            'name' => $this->get($config, 'getName'),
            'description' => $this->get($config, 'getDescription'),
            'location' => $this->get($config, 'getLocation'),
            'visibility' => $this->get($config, 'getVisibility'),
            'targetCalendarUri' => $this->get($config, 'getTargetCalendarUri'),
            'availability' => $this->availabilityFromNative($this->get($config, 'getAvailabilityAsArray')),
            'length' => $this->secondsToMinutes($this->get($config, 'getLength')),
            'increment' => $this->secondsToMinutes($this->get($config, 'getIncrement')),
            'preparationDuration' => $this->secondsToMinutes($this->get($config, 'getPreparationDuration')),
            'followupDuration' => $this->secondsToMinutes($this->get($config, 'getFollowupDuration')),
            'buffer' => $this->secondsToMinutes($this->get($config, 'getTimeBeforeNextSlot')),
            'dailyMax' => $this->get($config, 'getDailyMax'),
            'start' => $this->get($config, 'getStart'),
            'end' => $this->get($config, 'getEnd'),
            'futureLimit' => $this->secondsToDays($this->get($config, 'getFutureLimit')),
            'createTalkRoom' => $this->get($config, 'getCreateTalkRoom'),
        ];
    }

    /** @return array<string, mixed> */
    private function project(object $config, string $actor, bool $includeLink, ?array $visibleCalendars = null): array
    {
        if ($this->get($config, 'getUserId') !== $actor) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
        $uri = $this->get($config, 'getTargetCalendarUri');
        $visibleCalendars ??= $this->ownedCalendarsByUri($actor);
        if (!is_string($uri) || !isset($visibleCalendars[$uri])) { throw new ToolFailure(ToolFailure::NOT_FOUND); }
        $state = $this->state($config);
        $out = $state + [
            'id' => $this->get($config, 'getId'),
            'targetCalendarUri' => $this->get($config, 'getTargetCalendarUri'),
        ];
        if ($includeLink) {
            $token = $this->get($config, 'getToken');
            if (is_string($token) && $token !== '') {
                try { $out['link'] = ($this->linkFactory)($token); }
                catch (Throwable) { /* Native URL generation is retriable via a later list. */ }
            }
        }
        return $out;
    }

    private function get(object $object, string $method, mixed $default = null): mixed
    {
        // Calendar's AppointmentConfig is an OCP Entity: its getters are magic methods declared by @method tags,
        // so method_exists() is false even though these explicit calls are part of the verified native contract.
        return match ($method) {
            'getId' => $object->getId(),
            'getToken' => $object->getToken(),
            'getName' => $object->getName(),
            'getDescription' => $object->getDescription(),
            'getLocation' => $object->getLocation(),
            'getVisibility' => $object->getVisibility(),
            'getUserId' => $object->getUserId(),
            'getTargetCalendarUri' => $object->getTargetCalendarUri(),
            'getAvailabilityAsArray' => $object->getAvailabilityAsArray(),
            'getLength' => $object->getLength(),
            'getIncrement' => $object->getIncrement(),
            'getPreparationDuration' => $object->getPreparationDuration(),
            'getFollowupDuration' => $object->getFollowupDuration(),
            'getTimeBeforeNextSlot' => $object->getTimeBeforeNextSlot(),
            'getDailyMax' => $object->getDailyMax(),
            'getStart' => $object->getStart(),
            'getEnd' => $object->getEnd(),
            'getFutureLimit' => $object->getFutureLimit(),
            'getCreateTalkRoom' => $object->getCreateTalkRoom(),
            default => $default,
        };
    }
}
