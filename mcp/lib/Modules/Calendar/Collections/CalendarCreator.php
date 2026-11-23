<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Collections;

use OCA\Mcp\Access\ResourceScope\ScopeReader;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\Dav\CalDav\CalendarCollectionWriter;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;

/** Creates one personal CalDAV calendar, refusing a scoped user whose new calendar would be unreachable. */
final class CalendarCreator
{
    public function __construct(
        private ScopedCollectionAccess $access,
        private CalendarCollectionWriter $dav,
        private ScopeReader $scope,
        private PlanState $states,
    ) {}

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function prepare(array $arguments, string $actor, string $tool): array
    {
        $name = trim((string)($arguments['displayName'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw new \InvalidArgumentException('Enter a valid calendar name.');
        }
        $this->scope->assertReadable($actor, 'calendar');
        if ($this->scope->scopeItems($actor, 'calendar') !== null) {
            throw new ToolFailure('Calendar creation is unavailable while an explicit calendar scope is active.');
        }
        $uri = $this->uri($name);
        foreach ($this->access->visible($actor) as $calendar) {
            if ($calendar->ownerId === $actor && $calendar->uri === $uri) {
                throw new \InvalidArgumentException('A calendar with this name already exists.');
            }
        }
        $shown = ['displayName' => $name, 'uri' => $uri];
        return $shown + [
            'path' => '/remote.php/dav/calendars/' . rawurlencode($actor) . '/' . rawurlencode($uri) . '/',
            'action' => 'create_calendar',
            PlanState::ARGUMENT => $this->states->of($tool, $actor, $shown),
        ];
    }

    private function uri(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? '' : $ascii) ?? '');
        $slug = trim(substr($slug, 0, 80), '-');
        return $slug !== '' ? $slug : 'calendar-' . substr(hash('sha256', $name), 0, 12);
    }

    public function preview(array $arguments, string $actor, string $tool): array
    {
        return $this->prepare($arguments, $actor, $tool);
    }

    public function create(array $arguments, string $actor, string $tool): array
    {
        PlanState::require($arguments);
        $plan = $this->prepare($arguments, $actor, $tool);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($this->preview($arguments, $actor, $tool) + ['warning' => 'The calendar plan changed. Review the updated plan.']);
        }
        $this->dav->mkcalendar($actor, $plan['uri'], $plan['displayName']);
        foreach ($this->access->visible($actor) as $calendar) {
            if ($calendar->ownerId === $actor && $calendar->uri === $plan['uri']) {
                return ToolResult::json(['calendar' => $calendar, 'status' => 'created']);
            }
        }
        throw new ToolFailure('The calendar was created but could not be verified.');
    }
}
