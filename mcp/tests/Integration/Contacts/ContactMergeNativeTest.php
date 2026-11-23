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

final class ContactMergeNativeTest extends OAuthAccountTestCase {
    public function testMcpMergePreservesTheCardsInNativeBackupsAndVerifiesTheConsolidatedContact(): void {
        $backend = Server::get(CardDavBackend::class);
        $book = (int)$backend->createAddressBook('principals/users/' . $this->uid, 'mcp-merge', ['{DAV:}displayname' => 'Merge fixture']);
        $originals = [
            'primary.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:merge-primary\r\nFN:Merge Test\r\nN:Test;Merge;;;\r\n"
                . "EMAIL;TYPE=WORK:person@example.test\r\nEND:VCARD\r\n",
            'source.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:merge-source\r\nFN:Merge Test\r\nN:Test;Merge;;;\r\n"
                . "TEL;TYPE=WORK:+34 600 123 456\r\nX-RETAIN:from source\r\nEND:VCARD\r\n",
        ];
        try {
            $contacts = [];
            foreach ($originals as $uri => $data) {
                $backend->createCard($book, $uri, $data);
                $contacts[] = ['uri' => $uri, 'etag' => $backend->getCard($book, $uri)['etag']];
            }
            $policy = Server::get(GrantPolicy::class);
            $policy->setGrant($this->uid, 'contacts', 'read', true);
            $policy->setGrant($this->uid, 'contacts', 'delete', true);
            $policy->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                Server::get(ToolGrantsMapper::class)->set(ToolGrantsMapper::USER, $this->uid, 'contacts_merge_contacts', 'allow');
                return true;
            });
            $args = ['groups' => [[
                'addressbook' => '/remote.php/dav/addressbooks/users/' . rawurlencode($this->uid) . '/mcp-merge/',
                'keepUri' => 'primary.vcf',
                'contacts' => $contacts,
            ]]];
            $client = new McpClient(LoginFactory::basic($this->uid));
            $plan = $client->tool('contacts_merge_contacts', $args);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertTrue($plan->structured()['groups'][0]['ready']);
            self::assertCount(2, $backend->getCards($book));

            $merged = $client->write('contacts_merge_contacts', $args + ['plan_state' => $plan->structured()['plan_state']]);
            self::assertTrue($merged->ok(), $merged->describe());
            $result = $merged->json();
            self::assertSame(1, $result['completed'], $merged->describe());
            self::assertSame([], $result['retryGroups']);
            $outcome = $result['groups'][0];
            self::assertSame(['source.vcf'], $outcome['sourcesDeletedUris']);
            $rows = $backend->getCards($book);
            self::assertSame(['primary.vcf'], array_column($rows, 'uri'));
            $card = Reader::read($rows[0]['carddata']);
            self::assertSame('merge-primary', (string)$card->UID);
            self::assertSame('from source', (string)$card->{'X-RETAIN'});
            self::assertSame('WORK', (string)$card->TEL['TYPE']);

            $root = Server::get(IRootFolder::class)->getUserFolder($this->uid);
            foreach ($originals as $uri => $data) {
                self::assertSame($data, $root->get(ltrim($outcome['backupPaths'][$uri], '/'))->getContent());
            }
        } finally {
            $backend->deleteAddressBook($book);
        }
    }
}
