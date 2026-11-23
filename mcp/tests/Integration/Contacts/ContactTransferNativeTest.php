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
use OCP\Files\IRootFolder;
use OCP\Server;
use Sabre\VObject\Reader;

final class ContactTransferNativeTest extends OAuthAccountTestCase {
    public function testCopyGetsANewUidAndMoveVerifiesBothTheOriginalBackupAndTheDestination(): void {
        $backend = Server::get(CardDavBackend::class);
        $principal = 'principals/users/' . $this->uid;
        $source = (int)$backend->createAddressBook($principal, 'mcp-origin', ['{DAV:}displayname' => 'Origin']);
        $target = (int)$backend->createAddressBook($principal, 'mcp-target', ['{DAV:}displayname' => 'Target']);
        $card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:portable\r\nFN:Portable Test\r\nX-RETAIN:custom field\r\nEND:VCARD\r\n";
        try {
            $backend->createCard($source, 'portable.vcf', $card);
            $policy = Server::get(GrantPolicy::class);
            $policy->setGrant($this->uid, 'contacts', 'read', true);
            $policy->setGrant($this->uid, 'contacts', 'delete', true);
            $policy->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_copy_contact', 'allow');
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_move_contact', 'allow');
                return true;
            });
            $base = '/remote.php/dav/addressbooks/users/' . rawurlencode($this->uid) . '/';
            $args = [
                'sourceAddressbook' => $base . 'mcp-origin/',
                'sourceUri' => 'portable.vcf',
                'targetAddressbook' => $base . 'mcp-target/',
            ];
            $client = new McpClient(LoginFactory::basic($this->uid));
            $plan = $client->tool('contacts_copy_contact', $args);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertSame([], $backend->getCards($target));
            $copied = $client->write('contacts_copy_contact', $args + ['plan_state' => $plan->structured()['plan_state']]);
            self::assertTrue($copied->ok(), $copied->describe());
            self::assertTrue($copied->json()['copied'], $copied->describe());
            self::assertNotSame('portable', $copied->json()['uid']);
            self::assertCount(1, $backend->getCards($source));

            $move = $client->tool('contacts_move_contact', $args);
            self::assertTrue($move->ok(), $move->describe());
            $approved = $move->structured();
            $moved = $client->write('contacts_move_contact', $args + ['plan_state' => $approved['plan_state'], 'etag' => $approved['sourceEtag']]);
            self::assertTrue($moved->ok(), $moved->describe());
            $result = $moved->json();
            self::assertTrue($result['sourceDeleted'], $moved->describe());
            self::assertTrue($result['destinationVerified'], $moved->describe());
            self::assertSame([], $backend->getCards($source));
            self::assertCount(2, $backend->getCards($target));
            $stored = Reader::read($backend->getCard($target, $result['uri'])['carddata']);
            self::assertSame('portable', (string)$stored->UID);
            self::assertSame('custom field', (string)$stored->{'X-RETAIN'});
            $root = Server::get(IRootFolder::class)->getUserFolder($this->uid);
            self::assertSame($card, $root->get(ltrim($result['backupPath'], '/'))->getContent());
        } finally {
            $backend->deleteAddressBook($target);
            $backend->deleteAddressBook($source);
        }
    }
}
