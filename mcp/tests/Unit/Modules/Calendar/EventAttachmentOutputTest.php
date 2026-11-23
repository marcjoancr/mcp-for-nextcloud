<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Reader;

/**
 * V10 for Calendar. Event views and plans omit ATTACH, while explicit export applies the Files reference policy.
 * A DAV update to an unrelated field preserves the stored attachments.
 */
final class EventAttachmentOutputTest extends CalendarTestCase {
    private const ATTACH_URL = 'https://cloud.example.com/f/4242';
    private const ATTACH_NAME = 'secret-contract.pdf';

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'event.ics', self::ics(
            "UID:event\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\n"
            . 'ATTACH;FMTTYPE=application/pdf;FILENAME=/Secret/' . self::ATTACH_NAME . ':' . self::ATTACH_URL . "\n"
            . 'ATTACH:https://cloud.example.com/remote.php/dav/files/alice/Secret/' . self::ATTACH_NAME,
            "BEGIN:VEVENT\nUID:event\nRECURRENCE-ID:20261008T120000Z\nDTSTART:20261008T120000Z\nDTEND:20261008T130000Z\n"
            . 'ATTACH:' . self::ATTACH_URL . "\nEND:VEVENT",
        ));
        $this->store->addObject(1, 'invite.ics', self::ics(
            "UID:invite\nSUMMARY:Invitation\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\n"
            . "ORGANIZER:mailto:bob@example.invalid\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid\n"
            . 'ATTACH:' . self::ATTACH_URL,
        ));
        $this->store->addObject(1, 'single.ics', self::ics(
            "UID:single\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\n"
            . 'ATTACH:' . self::ATTACH_URL,
        ));
    }

    private static function assertNoFileReference(array $result, string $context): void {
        $text = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
        self::assertStringNotContainsStringIgnoringCase('ATTACH', $text, $context);
        self::assertStringNotContainsString('cloud.example.com', $text, $context);
        self::assertStringNotContainsString(self::ATTACH_NAME, $text, $context);
        self::assertStringNotContainsString('/f/4242', $text, $context);
    }

    /** @return array<string, array{string, array<string, mixed>}> every event tool with arguments reaching the event */
    public static function tools(): array {
        $event = ['calendar' => self::PERSONAL, 'uid' => 'event'];
        return [
            'list calendars' => ['calendar_list_calendars', []],
            'list events' => ['calendar_list_events', ['from' => '2026-09-01', 'to' => '2026-11-01']],
            'search events' => ['calendar_search_events', ['from' => '2026-10-01', 'to' => '2026-10-05', 'query' => 'Before']],
            'read event' => ['calendar_read_event', $event],
            'find slots' => ['calendar_find_slots', ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z']],
            'respond to an invitation' => ['calendar_respond_to_invitation', ['calendar' => self::PERSONAL, 'uid' => 'invite', 'response' => 'accepted']],
            'list resources' => ['calendar_list_resources', []],
            'check resource availability' => ['calendar_check_resource_availability', ['type' => 'ROOM', 'resource' => self::ROOM, 'start' => '2026-10-01', 'end' => '2026-10-02']],
            'reserve resource' => ['calendar_reserve_resource', ['calendar' => self::PERSONAL, 'uid' => 'single', 'type' => 'ROOM', 'resource' => self::ROOM]],
            'availability' => ['calendar_check_availability', ['calendars' => [self::PERSONAL], 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z']],
            'create' => ['calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Draft', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true]],
            'update' => ['calendar_update_event', $event + ['summary' => 'After']],
            'create external' => ['calendar_create_event_with_external_attendees', ['calendar' => self::PERSONAL, 'summary' => 'Draft', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true, 'external_attendees' => ['guest@example.test']]],
            'update external' => ['calendar_update_event_with_external_attendees', ['calendar' => self::PERSONAL, 'uid' => 'single', 'add_attendee_emails' => ['guest@example.test']]],
            'delete' => ['calendar_delete_event', $event],
            'move' => ['calendar_move_event', $event + ['targetCalendar' => self::WORK]],
            'transfer' => ['calendar_transfer_event', $event + ['targetCalendar' => self::TEAM, 'confirm_shared' => true]],
            'reminders' => ['calendar_set_event_reminders', $event + ['minutes_before' => [30]]],
        ];
    }

    public function testEveryCalendarToolIsCovered(): void {
        $names = array_map(static fn (array $definition): string => $definition['name'], $this->module->definitions());
        sort($names);
        $covered = array_merge(array_column(self::tools(), 0), [
            'calendar_create_calendar', 'calendar_list_calendar_shares', 'calendar_share_calendar',
            'calendar_list_trashed_events', 'calendar_restore_trashed_event',
            'calendar_import_ics', 'calendar_export_ics',
        ]);
        sort($covered);
        self::assertSame($names, $covered, 'a new calendar tool must be added to this proof');
    }

    #[DataProvider('tools')]
    public function testNoToolExposesAttachInThePlanOrTheResult(string $tool, array $arguments): void {
        $first = $this->registry->call($tool, $arguments, 'alice');
        self::assertArrayNotHasKey('isError', $first, $first['content'][0]['text'] ?? '');
        self::assertNoFileReference($first, "$tool without confirm");
        if ($tool === 'calendar_list_events') {
            self::assertStringContainsString('Before', $first['content'][0]['text'], 'the event with ATTACH was listed');
        }
        $definition = \OCA\Mcp\Modules\Calendar\CalendarToolCatalog::definitions()[$tool];
        if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::isWrite($definition)) {
            return;
        }
        if (in_array($tool, ['calendar_update_event_with_external_attendees', 'calendar_respond_to_invitation', 'calendar_reserve_resource'], true)) {
            $arguments['etag'] = $this->store->objects[1][$arguments['uid'] . '.ics']['etag'];
        }
        if (isset($first['structuredContent']['plan_state'])) {
            $arguments['plan_state'] = $first['structuredContent']['plan_state'];
        }
        $confirmed = $this->registry->call($tool, $arguments + ['confirm' => true], 'alice');
        self::assertArrayNotHasKey('isError', $confirmed, $confirmed['content'][0]['text'] ?? '');
        self::assertNoFileReference($confirmed, "$tool confirmed");
    }

    public function testIcsExportAppliesFilesRestrictionsToEventAttachments(): void {
        $root = $this->createStub(\OCP\Files\IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->createStub(\OCP\Files\Folder::class));
        $scope = $this->createStub(\OCA\Mcp\Platform\FileAccess\References\FileReferenceScope::class);
        $scope->method('restricts')->willReturn(true);
        $helpers = \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::class;
        $filter = $helpers::over($this, $root, $helpers::openGuard($this), $this->createStub(\OCP\Share\IManager::class), $scope);
        $access = \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope);
        $classifier = new \OCA\Mcp\Modules\Calendar\Events\Classification();
        $exchange = new \OCA\Mcp\Modules\Calendar\Exchange\CalendarExchange(
            $access, $this->store, new \OCA\Mcp\Modules\Calendar\Events\EventRepository($this->store, $classifier),
            $classifier, $this->dav, new \OCA\Mcp\Modules\Calendar\Exchange\IcsBundle(), $root,
            \OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this), $filter,
            new \OCA\Mcp\Mcp\Confirmation\PlanState($this->createStub(\OCP\IConfig::class)),
        );
        $result = self::json($exchange->export(['calendar' => self::PERSONAL, 'uids' => ['event']], 'alice'));
        self::assertStringContainsString('SUMMARY:Before', $result['icalendar']);
        self::assertNoFileReference($result, 'iCalendar export with restricted Files');
    }

    public function testErrorsAboutTheEventCarryNoAttach(): void {
        $result = $this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T15:00:00Z', 'end' => '2026-10-01T14:00:00Z', 'confirm' => true], 'alice');

        self::assertTrue($result['isError'] ?? false);
        self::assertNoFileReference($result, 'update error');
    }

    public function testUpdatingAnotherFieldKeepsTheAttachInTheDavObject(): void {
        $this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After', 'confirm' => true], 'alice');

        $written = Reader::read($this->store->objects[1]['event.ics']['data']);
        $values = [];
        foreach ($written->select('VEVENT') as $event) {
            foreach ($event->select('ATTACH') as $attach) {
                $values[] = (string)$attach->getValue();
            }
        }
        self::assertContains(self::ATTACH_URL, $values);
        self::assertCount(3, $values);
    }
}
