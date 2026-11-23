<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Exchange;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use OCP\Files\IRootFolder;

final class CalendarExchange {
    public const IMPORT = 'calendar_import_ics';

    public function __construct(
        private ScopedCollectionAccess $access,
        private ObjectStore $store,
        private EventRepository $events,
        private Classification $classification,
        private CalDavWriter $writer,
        private IcsBundle $bundle,
        private IRootFolder $files,
        private FilesAccessGuard $fileAccess,
        private OutputReferenceFilter $references,
        private PlanState $states,
    ) {}

    public function export(array $arguments, string $actor): array {
        $this->references->assertAvailable($actor);
        $calendar = $this->access->resolve($actor, $arguments['calendar']);
        $uids = array_values(array_unique($arguments['uids']));
        if ($uids === [] || count($uids) > IcsBundle::MAX_EVENTS) {
            throw new ToolFailure('Select between 1 and 100 events for iCalendar exchange.');
        }
        $bytes = 0;
        $events = [];
        foreach ($uids as $uid) {
            $row = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
            if ($row === null || $row['deleted']) {
                throw CalendarException::notFound();
            }
            $bytes += strlen($row['data']);
            if ($bytes > IcsBundle::MAX_BYTES) {
                throw new ToolFailure('The iCalendar document exceeds the 1 MiB limit.');
            }
            $parsed = $this->events->parse($row['data']);
            if ($parsed === null || $this->classification->visibility($parsed, $calendar, $actor) !== Classification::FULL) {
                throw CalendarException::notFound();
            }
            $events[] = $this->references->outputCalendar($actor, $parsed);
        }
        return ToolResult::json([
            'filename' => 'events.ics',
            'contentType' => 'text/calendar',
            'calendar' => $calendar->path,
            'count' => count($events),
            'icalendar' => $this->bundle->export($events),
        ]);
    }

    public function preview(array $arguments, string $actor): array {
        return $this->prepare($arguments, $actor)[1];
    }

    public function import(array $arguments, string $actor): array {
        PlanState::require($arguments);
        [$calendar, $plan, $payloads] = $this->prepare($arguments, $actor);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
            throw new PlanChanged($plan);
        }

        $outcomes = [];
        foreach ($plan['events'] as $entry) {
            if ($entry['status'] === 'create') {
                $entry = $this->write($actor, $calendar, $entry, $payloads[$entry['uid']]);
            }
            $outcomes[] = $entry;
        }
        return ToolResult::json(['calendar' => $calendar->path, 'events' => $outcomes]);
    }

    /** @return array{Collection,array<string,mixed>,array<string,string>} */
    private function prepare(array $arguments, string $actor): array {
        $calendar = $this->ownedCalendar($actor, $arguments['calendar']);
        $path = PathGuard::normalize($arguments['path']);
        $data = $this->readSource($path, $actor);
        $entries = [];
        $payloads = [];
        foreach ($this->bundle->importable($data) as $event) {
            $uid = $event['uid'];
            $uri = 'mcp-' . hash('sha256', $uid) . '.ics';
            $byUid = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
            $byUri = $this->store->object($calendar->id, $uri);
            $status = match (true) {
                $byUid !== null && !$byUid['deleted'] => 'already_present',
                $byUid !== null || $byUri !== null => 'conflict',
                default => 'create',
            };
            $entries[] = [
                'uid' => $uid, 'summary' => $event['summary'], 'uri' => $uri,
                'status' => $status, 'excluded_scheduling_or_attachment_data' => $event['stripped'],
            ];
            if ($status === 'create') {
                $payloads[$uid] = $event['calendar']->serialize();
            }
        }
        $plan = [
            'action' => self::IMPORT,
            'source' => $path,
            'calendar' => $calendar->path,
            'events' => $entries,
            'schedule_invitations' => false,
        ];
        $plan[PlanState::ARGUMENT] = $this->states->of(self::IMPORT, $actor, $plan + [
            'calendarId' => $calendar->id,
            'owner' => $calendar->ownerPrincipal,
            'sourceSha256' => hash('sha256', $data),
        ]);
        return [$calendar, $plan, $payloads];
    }

    private function readSource(string $path, string $actor): string {
        $this->fileAccess->assertScopeReadable();
        $root = $this->files->getUserFolder($actor);
        return NodeAccess::run(function () use ($root, $path): string {
            $file = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->fileAccess));
            if ($file->getSize() > IcsBundle::MAX_BYTES) {
                throw new ToolFailure('The iCalendar document exceeds the 1 MiB limit.');
            }
            $etag = $file->getEtag();
            $stream = $file->fopen('rb');
            if (!is_resource($stream)) {
                throw new ToolFailure('The iCalendar source file could not be read.');
            }
            try {
                $data = stream_get_contents($stream, IcsBundle::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if (!is_string($data) || $etag !== NodeAccess::get($root, $path, $this->fileAccess)->getEtag()) {
                throw new ToolFailure('The iCalendar source changed while being read.');
            }
            return $data;
        });
    }

    private function ownedCalendar(string $actor, string $path): Collection {
        $calendar = $this->access->resolveWritable($actor, $path);
        if ($calendar->ownerId !== $actor) {
            throw CalendarException::notFound();
        }
        return $calendar;
    }

    private function write(string $actor, Collection $calendar, array $entry, string $data): array {
        try {
            $live = $this->ownedCalendar($actor, $calendar->path);
            if ($live->id !== $calendar->id || $live->ownerPrincipal !== $calendar->ownerPrincipal) {
                return array_replace($entry, ['status' => 'destination_changed']);
            }
        } catch (ToolFailure) {
            return array_replace($entry, ['status' => 'destination_changed']);
        }
        try {
            $this->writer->put($actor, $calendar->uri, $entry['uri'], $data, false);
        } catch (\RuntimeException) {
            // DAV can report a failed request even when the event was written.
        }
        try {
            $written = $this->store->objectByUid($calendar->id, $entry['uid'], 'VEVENT');
            if ($written === null || $written['deleted'] || $written['uri'] !== $entry['uri']) {
                return array_replace($entry, ['status' => 'unverified']);
            }
            $parsed = $this->events->parse($written['data']);
        } catch (\RuntimeException) {
            return array_replace($entry, ['status' => 'unverified']);
        }
        if ($parsed?->serialize() !== $this->events->parse($data)?->serialize()) {
            return array_replace($entry, ['status' => 'conflict']);
        }
        return array_replace($entry, ['status' => 'created', 'etag' => $written['etag']]);
    }
}
