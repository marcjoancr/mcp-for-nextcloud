<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;
use OCP\Server;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
final class DavUpgradeTest extends IntegrationTestCase {
    public function testSeededDavObjectsAndMandatoryDeletionBackup(): void {
        $private = json_decode(file_get_contents('/tmp/l3-private.json'), true, 512, JSON_THROW_ON_ERROR);
        $mcp = new McpClient(LoginFactory::basic('l2-owner'));
        $calendar = '/remote.php/dav/calendars/l2-owner/l2-vevent/';
        $read = $mcp->tool('calendar_list_events', ['calendar' => $calendar, 'from' => '2026-11-01', 'to' => '2026-11-02']);
        self::assertTrue($read->ok(), $read->describe()); self::assertStringContainsString('L2 VEVENT', $read->text());
        $write = $mcp->write('calendar_update_event', ['calendar' => $calendar, 'uid' => 'l2-VEVENT', 'summary' => 'L3 event']);
        self::assertTrue($write->ok(), $write->describe());
        $cal = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
        self::assertStringContainsString('SUMMARY:L3 event', $cal->getCalendarObject($private['dav']['VEVENT']['id'], 'fixture.ics')['calendardata']);
        $book = '/remote.php/dav/addressbooks/users/l2-owner/l2-contacts/';
        $read = $mcp->tool('contacts_read_contact', ['addressbook' => $book, 'uri' => 'fixture.vcf']);
        self::assertTrue($read->ok(), $read->describe()); self::assertStringContainsString('L2 synthetic contact', $read->text());
        $backend = Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
        $card = $backend->getCard($private['dav']['VCARD']['id'], 'fixture.vcf');
        $config = Server::get(\OCP\IAppConfig::class);
        self::assertFalse($config->hasKey('mcp', 'backup_settings', null));
        try {
            Server::get(\OCA\Mcp\Access\BackupPolicy\BackupSettingsWriter::class)->save(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'], 'MCP backups', false);
            $delete = $mcp->write('contacts_delete_contact', ['addressbook' => $book, 'uri' => 'fixture.vcf', 'etag' => (string)$card['etag']]);
            self::assertTrue($delete->ok(), $delete->describe()); self::assertTrue($delete->json()['deleted']);
            $copy = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner')->get($delete->json()['backupPath']);
            self::assertSame($card['carddata'], $copy->getContent());
            self::assertFalse($backend->getCard($private['dav']['VCARD']['id'], 'fixture.vcf'));
        } finally {
            $config->deleteKey('mcp', 'backup_settings'); $config->clearCache(false);
        }
    }
}
