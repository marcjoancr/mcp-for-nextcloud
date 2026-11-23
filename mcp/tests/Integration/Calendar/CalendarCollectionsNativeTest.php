<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IUserManager;
use OCP\Server;
use Sabre\VObject\Component\VCalendar;

/** Exercises native MKCALENDAR, share POST, trash lookup and restore through the authenticated MCP boundary. */
final class CalendarCollectionsNativeTest extends OAuthAccountTestCase
{
    public function testCreateAndShareCalendarThroughNativeCalDav(): void
    {
        $backend = Server::get(CalDavBackend::class);
        $recipientId = 'mcp-it-calendar-sharee-' . bin2hex(random_bytes(6));
        $recipient = Server::get(IUserManager::class)->createUser($recipientId, bin2hex(random_bytes(24)));
        self::assertNotFalse($recipient);
        $calendarId = null;
        try {
            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                foreach (['calendar_create_calendar', 'calendar_list_calendar_shares', 'calendar_share_calendar'] as $tool) {
                    $grants->set(ToolGrantsMapper::USER, $this->uid, $tool, 'allow');
                }
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $name = 'MCP calendar share ' . bin2hex(random_bytes(4));
            $args = ['displayName' => $name];
            $plan = $client->tool('calendar_create_calendar', $args);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertArrayHasKey('plan_state', $plan->structured());
            $created = $client->write('calendar_create_calendar', $args + ['plan_state' => $plan->structured()['plan_state']]);
            self::assertTrue($created->ok(), $created->describe());
            $calendar = $created->json()['calendar'];
            $calendarId = $calendar['id'];
            self::assertSame('created', $created->json()['status']);

            $shareArgs = ['calendar' => $calendar['path'], 'shareWith' => $recipientId, 'access' => 'read'];
            $sharePlan = $client->tool('calendar_share_calendar', $shareArgs);
            self::assertTrue($sharePlan->ok(), $sharePlan->describe());
            self::assertSame($recipientId, $sharePlan->structured()['shareWith']);
            $share = $client->write('calendar_share_calendar', $shareArgs + ['plan_state' => $sharePlan->structured()['plan_state']]);
            self::assertTrue($share->ok(), $share->describe());
            self::assertSame('shared', $share->json()['status']);

            $listing = $client->tool('calendar_list_calendar_shares', ['calendar' => $calendar['path']]);
            self::assertTrue($listing->ok(), $listing->describe());
            self::assertSame([$recipientId], array_column($listing->json()['shares'], 'userId'));
            self::assertTrue($listing->json()['shares'][0]['readOnly']);
            $nativeShares = array_values(array_filter($backend->getShares((int)$calendarId), static fn(array $row): bool => ($row['href'] ?? null) === 'principal:principals/users/' . $recipientId));
            self::assertCount(1, $nativeShares);
            self::assertTrue((bool)$nativeShares[0]['readOnly']);
        } finally {
            if ($calendarId !== null) $backend->deleteCalendar((int)$calendarId, true);
            $recipient->delete();
        }
    }

    public function testDeleteThenRestoreRoundTripsThroughNativeCalendarTrash(): void
    {
        $backend = Server::get(CalDavBackend::class);
        $calendarId = null;
        try {
            $calendarId = $backend->createCalendar('principals/users/' . $this->uid, 'mcp-trash-roundtrip-' . bin2hex(random_bytes(4)), [
                '{DAV:}displayname' => 'MCP trash round trip',
            ]);
            $uri = 'mcp-trash-roundtrip.ics';
            $ical = new VCalendar();
            $ical->add('VEVENT', [
                'UID' => 'mcp-trash-roundtrip-' . bin2hex(random_bytes(6)),
                'DTSTART' => new \DateTimeImmutable('tomorrow 10:00:00', new \DateTimeZone('UTC')),
                'DTEND' => new \DateTimeImmutable('tomorrow 11:00:00', new \DateTimeZone('UTC')),
                'SUMMARY' => 'Native trash round trip',
            ]);
            $eventUid = (string)$ical->VEVENT->UID;
            $backend->createCalendarObject((int)$calendarId, $uri, $ical->serialize());

            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                foreach (['calendar_delete_event', 'calendar_list_trashed_events', 'calendar_restore_trashed_event'] as $tool) {
                    $grants->set(ToolGrantsMapper::USER, $this->uid, $tool, 'allow');
                }
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $calendar = '/remote.php/dav/calendars/' . rawurlencode($this->uid) . '/' . rawurlencode((string)$backend->getCalendarById((int)$calendarId)['uri']) . '/';
            $deleteArgs = ['calendar'=>$calendar,'uid'=>$eventUid];
            $deletePlan = $client->tool('calendar_delete_event',$deleteArgs);
            self::assertTrue($deletePlan->ok(),$deletePlan->describe());
            $deleted = $client->write('calendar_delete_event',$deleteArgs + ['etag'=>$deletePlan->structured()['etag']]);
            self::assertTrue($deleted->ok(),$deleted->describe());
            self::assertTrue($deleted->json()['recoverable']);

            $trash = $client->tool('calendar_list_trashed_events');
            self::assertTrue($trash->ok(),$trash->describe());
            $rows = array_values(array_filter($trash->json()['items'],static fn(array $item): bool => ($item['uid'] ?? null) === $eventUid));
            self::assertCount(1,$rows);
            $restoreArgs = ['object_id'=>$rows[0]['object_id']];
            $restorePlan = $client->tool('calendar_restore_trashed_event',$restoreArgs);
            self::assertTrue($restorePlan->ok(),$restorePlan->describe());
            self::assertSame('Native trash round trip',$restorePlan->structured()['summary']);
            self::assertFalse($restorePlan->structured()['scheduling']['sent']);
            $restored = $client->write('calendar_restore_trashed_event',$restoreArgs + ['plan_state'=>$restorePlan->structured()['plan_state']]);
            self::assertTrue($restored->ok(),$restored->describe());
            self::assertTrue($restored->json()['restored']);
            self::assertCount(0,array_filter($backend->getDeletedCalendarObjectsByPrincipal('principals/users/' . $this->uid),static fn(array $row): bool => ($row['id'] ?? null) === ($rows[0]['object_id'] ?? null)));
            $live = $backend->getMultipleCalendarObjects((int)$calendarId,[$uri]);
            self::assertCount(1,$live);
            self::assertSame($eventUid,(string)\Sabre\VObject\Reader::read($live[0]['calendardata'])->VEVENT->UID);
        } finally {
            if ($calendarId !== null) $backend->deleteCalendar((int)$calendarId,true);
        }
    }
}
