<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Contacts;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Server;

/** Real CardDAV deletes and verified Files backups with database locking and no Redis. */
final class ContactBackupLockTest extends OAuthAccountTestCase {
    private ?int $bookId = null;
    private string $bookName = '';
    private string $bookPath = '';

    protected function setUp(): void {
        parent::setUp();
        self::assertTrue(Server::get(IAppManager::class)->isEnabledForUser('contacts'), 'Pinned Contacts must be provisioned');
        self::assertSame('', Server::get(IConfig::class)->getSystemValue('memcache.locking', ''), 'This regression requires no Redis locking');
        self::assertInstanceOf(\OC\Lock\DBLockingProvider::class, Server::get(ILockingProvider::class));
        Server::get(GrantPolicy::class)->setGrant($this->uid, 'contacts', 'delete', true);
        Server::get(GrantPolicy::class)->setConnected($this->uid, true);
        $this->bookName = 'MCP lock ' . bin2hex(random_bytes(5));
        $uri = 'mcp-lock-' . bin2hex(random_bytes(5));
        $this->bookId = (int)Server::get(CardDavBackend::class)->createAddressBook('principals/users/' . $this->uid, $uri,
            ['{DAV:}displayname' => $this->bookName]);
        $this->bookPath = '/remote.php/dav/addressbooks/users/' . rawurlencode($this->uid) . '/' . $uri . '/';
    }

    protected function tearDown(): void {
        try {
            if ($this->bookId !== null) Server::get(CardDavBackend::class)->deleteAddressBook($this->bookId);
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{uri:string, etag:string, data:string} */
    private function plant(string $name): array {
        $data = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$name\r\nFN:$name\r\nX-RETAIN:full original\r\nEND:VCARD\r\n";
        $backend = Server::get(CardDavBackend::class);
        $uri = $name . '.vcf';
        $backend->createCard($this->bookId, $uri, $data);
        return ['uri' => $uri, 'etag' => (string)$backend->getCard($this->bookId, $uri)['etag'], 'data' => $data];
    }

    public function testTwoDeletesInTheSameAddressBookReleaseTheDatabaseMutex(): void {
        $client = new McpClient(LoginFactory::basic($this->uid));
        $paths = [];
        foreach (['First', 'Second'] as $name) {
            $card = $this->plant($name);
            $reply = $client->write('contacts_delete_contact', ['addressbook' => $this->bookPath, 'uri' => $card['uri'], 'etag' => $card['etag']]);
            self::assertTrue($reply->ok(), $reply->describe());
            $payload = $reply->json();
            self::assertTrue($payload['deleted']);
            $paths[] = $payload['backupPath'];
            $copy = Server::get(IRootFolder::class)->getUserFolder($this->uid)->get(ltrim($payload['backupPath'], '/'));
            self::assertSame($card['data'], $copy->getContent());
            self::assertFalse(Server::get(CardDavBackend::class)->getCard($this->bookId, $card['uri']));
        }
        self::assertCount(2, array_unique($paths));
    }

    public function testBackupFailureKeepsTheNativeContact(): void {
        $root = Server::get(IRootFolder::class)->getUserFolder($this->uid);
        $base = $root->newFolder('MCP backups')->newFolder('Contacts');
        $base->newFile($this->bookName, 'blocks the required directory');
        $card = $this->plant('Retained');
        $reply = (new McpClient(LoginFactory::basic($this->uid)))->write('contacts_delete_contact',
            ['addressbook' => $this->bookPath, 'uri' => $card['uri'], 'etag' => $card['etag']]);
        self::assertTrue($reply->isError(), $reply->describe());
        self::assertStringContainsString('not deleted', $reply->text());
        self::assertSame($card['data'], Server::get(CardDavBackend::class)->getCard($this->bookId, $card['uri'])['carddata']);
    }
}
