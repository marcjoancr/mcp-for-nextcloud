<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Contacts;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IUserManager;
use OCP\Server;

final class AddressBookSharingTest extends OAuthAccountTestCase {
    public function testNativeAddressBookCreationAndShareLifecycle(): void {
        $backend = Server::get(CardDavBackend::class);
        $recipient = 'mcp-it-sharee-' . bin2hex(random_bytes(6));
        $sharee = Server::get(IUserManager::class)->createUser($recipient, bin2hex(random_bytes(24)));
        self::assertNotFalse($sharee);
        $bookId = null;
        try {
            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                foreach (['contacts_create_addressbook', 'contacts_list_addressbook_shares', 'contacts_share_addressbook'] as $tool) {
                    $grants->set(ToolGrantsMapper::USER, $this->uid, $tool, 'allow');
                }
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $name = 'MCP share lifecycle ' . bin2hex(random_bytes(4));
            $plan = $client->tool('contacts_create_addressbook', ['displayName' => $name]);
            self::assertTrue($plan->ok(), $plan->describe());
            $created = $client->write('contacts_create_addressbook', ['displayName' => $name]);
            self::assertTrue($created->ok(), $created->describe());
            $book = $created->json()['addressbook'];
            $bookId = $book['id'];
            self::assertSame($plan->structured()['path'], $book['path']);

            $args = ['addressbook' => $book['path'], 'shareWith' => $recipient];
            foreach (['read' => 'shared', 'write' => 'updated', 'remove' => 'removed'] as $access => $status) {
                $preview = $client->tool('contacts_share_addressbook', $args + ['access' => $access]);
                self::assertTrue($preview->ok(), $preview->describe());
                self::assertSame($recipient, $preview->structured()['recipient']['userId']);
                $changed = $client->write('contacts_share_addressbook', $args + ['access' => $access]);
                self::assertTrue($changed->ok(), $changed->describe());
                self::assertSame($status, $changed->json()['status']);
                $listing = $client->tool('contacts_list_addressbook_shares', ['addressbook' => $book['path']]);
                self::assertTrue($listing->ok(), $listing->describe());
                $shares = $listing->json()['shares'];
                if ($access === 'remove') {
                    self::assertSame([], $shares);
                } else {
                    self::assertSame([$recipient], array_column($shares, 'userId'));
                    self::assertSame($access === 'read', $shares[0]['readOnly']);
                }
            }
            self::assertSame([], $backend->getShares($bookId));
        } finally {
            if ($bookId !== null) {
                $backend->deleteAddressBook($bookId);
            }
            $sharee->delete();
        }
    }
}
