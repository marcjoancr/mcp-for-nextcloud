<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;
use OCP\Server;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCA\Mcp\Modules\Tasks\Hierarchy\TaskHierarchyMessages;
final class TasksBoundaryTest extends IntegrationTestCase {
    public function testNumericParentsDepthBoundaryCyclesAndCrossCalendarDenialsPersist(): void {
        $backend = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $create = fn ($uri) => (int)$backend->createCalendar('principals/users/l2-owner', $uri, ['{DAV:}displayname' => $uri,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VTODO'])]);
        $id = $create('l3-hierarchy'); $other = $create('l3-other');
        $ics = fn ($uid, $parent = '') => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//L3//EN\r\nBEGIN:VTODO\r\nUID:$uid\r\nSUMMARY:Parent $uid\r\nDTSTAMP:20261029T100000Z\r\n" . ($parent === '' ? '' : "RELATED-TO;RELTYPE=PARENT:$parent\r\n") . "END:VTODO\r\nEND:VCALENDAR\r\n";
        for ($i = 0; $i < 63; $i++) $backend->createCalendarObject($id, "$i.ics", $ics((string)(1000 + $i), $i ? (string)(999 + $i) : ''));
        $backend->createCalendarObject($other, 'other.ics', $ics('foreign'));
        $mcp = new McpClient(LoginFactory::basic('l2-owner')); $calendar = '/remote.php/dav/calendars/l2-owner/l3-hierarchy/';
        $plan = $mcp->tool('tasks_create_task', ['calendar' => $calendar, 'summary' => 'Depth 64 child', 'parent' => '1062']);
        self::assertTrue($plan->ok(), $plan->describe());
        self::assertStringContainsString('Parent 1062', $plan->text());
        $child = $mcp->write('tasks_create_task', ['calendar' => $calendar, 'summary' => 'Depth 64 child', 'parent' => '1062']);
        self::assertTrue($child->ok(), $child->describe()); $uid = $child->json()['uid'];
        $tooDeep = $mcp->write('tasks_create_task', ['calendar' => $calendar, 'summary' => 'Must not exist', 'parent' => $uid]);
        self::assertTrue($tooDeep->isError(), $tooDeep->describe()); self::assertSame(TaskHierarchyMessages::tooDeep(64), $tooDeep->text());
        $cycle = $mcp->write('tasks_edit_task', ['calendar' => $calendar, 'uid' => '1000', 'parent' => $uid]);
        self::assertTrue($cycle->isError(), $cycle->describe()); self::assertSame(TaskHierarchyMessages::loop(), $cycle->text());
        $cross = $mcp->write('tasks_create_task', ['calendar' => $calendar, 'summary' => 'Must not exist', 'parent' => 'foreign']);
        self::assertTrue($cross->isError(), $cross->describe()); self::assertSame(TaskHierarchyMessages::parentNotFound(), $cross->text());
        self::assertCount(64, $backend->getCalendarObjects($id));
        self::assertStringNotContainsString('RELATED-TO', $backend->getCalendarObject($id, '0.ics')['calendardata']);
        $listed = $mcp->tool('tasks_list_tasks', ['calendar' => $calendar, 'parent' => '1062']);
        self::assertSame([$uid], array_column($listed->json()['tasks'], 'uid'));
    }
}
