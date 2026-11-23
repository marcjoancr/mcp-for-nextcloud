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

final class VcfRoundTripTest extends OAuthAccountTestCase {
    public function testPreviewImportAndExportWithNativeFilesAndCardDav(): void {
        $backend = Server::get(CardDavBackend::class);
        $book = (int)$backend->createAddressBook('principals/users/' . $this->uid, 'mcp-exchange', ['{DAV:}displayname' => 'MCP exchange']);
        $addressbook = '/remote.php/dav/addressbooks/users/' . rawurlencode($this->uid) . '/mcp-exchange/';
        $source = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:round-trip\r\nFN:Exchange Test\r\nN:Test;Exchange;;;\r\n"
            . "EMAIL;TYPE=WORK:fixture@example.test\r\nX-RETAIN:custom field\r\nEND:VCARD\r\n";
        try {
            Server::get(IRootFolder::class)->getUserFolder($this->uid)->newFile('mcp-exchange.vcf', $source);
            $policy = Server::get(GrantPolicy::class);
            $policy->setGrant($this->uid, 'files', 'read', true);
            $policy->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_import_vcf', 'allow');
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_export_vcf', 'allow');
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $arguments = ['addressbook' => $addressbook, 'path' => '/mcp-exchange.vcf'];
            $plan = $client->tool('contacts_import_vcf', $arguments);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertSame([], $backend->getCards($book));
            self::assertSame('create', $plan->structured()['contacts'][0]['status']);
            $written = $client->write('contacts_import_vcf', $arguments + ['plan_state' => $plan->structured()['plan_state']]);
            self::assertTrue($written->ok(), $written->describe());
            $contact = $written->json()['contacts'][0];
            self::assertSame('created', $contact['status'], $written->describe());
            $native = $backend->getCard($book, $contact['uri']);
            self::assertSame('custom field', (string)Reader::read($native['carddata'])->{'X-RETAIN'});

            $exported = $client->tool('contacts_export_vcf', ['addressbook' => $addressbook, 'uris' => [$contact['uri']]]);
            self::assertTrue($exported->ok(), $exported->describe());
            $card = Reader::read($exported->json()['vcard']);
            self::assertSame('round-trip', (string)$card->UID);
            self::assertSame('WORK', (string)$card->EMAIL['TYPE']);
            self::assertSame('custom field', (string)$card->{'X-RETAIN'});
            $repeat = $client->tool('contacts_import_vcf', $arguments);
            self::assertTrue($repeat->ok(), $repeat->describe());
            self::assertSame('already_present', $repeat->structured()['contacts'][0]['status']);
        } finally {
            $backend->deleteAddressBook($book);
        }
    }

    public function testMcpBatchCategoriesAndVcfReconciliationUseNativeCardDavAndVerifiedBackup(): void {
        $backend = Server::get(CardDavBackend::class);
        $bookUri = 'mcp-reconcile-' . bin2hex(random_bytes(4));
        $book = (int)$backend->createAddressBook('principals/users/' . $this->uid, $bookUri, ['{DAV:}displayname' => 'MCP reconcile fixture']);
        $addressbook = '/remote.php/dav/addressbooks/users/' . rawurlencode($this->uid) . '/' . rawurlencode($bookUri) . '/';
        $sourceName = 'mcp-reconcile-' . bin2hex(random_bytes(4)) . '.vcf';
        $original = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:reconcile-contact\r\nFN:Before reconcile\r\nN:Reconcile;Before;;;\r\n"
            . "TITLE:Local title\r\nTEL;TYPE=CELL:+34 600 000 001\r\nCATEGORIES:Family\r\nX-RETAIN:local-only\r\nEND:VCARD\r\n";
        $source = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:reconcile-contact\r\nFN:After reconcile\r\nEND:VCARD\r\n";
        $backupPath = null;
        $root = Server::get(IRootFolder::class)->getUserFolder($this->uid);
        try {
            $backend->createCard($book, 'reconcile.vcf', $original);
            $root->newFile($sourceName, $source);

            $policy = Server::get(GrantPolicy::class);
            $policy->setGrant($this->uid, 'files', 'read', true);
            $policy->setGrant($this->uid, 'contacts', 'read', true);
            $policy->setGrant($this->uid, 'contacts', 'edit', true);
            $policy->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_update_categories', 'allow');
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'contacts_reconcile_vcf', 'allow');
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));

            $row = $backend->getCard($book, 'reconcile.vcf');
            $categoryArgs = [
                'addressbook' => $addressbook,
                'contacts' => [['uri' => 'reconcile.vcf', 'etag' => $row['etag']]],
                'action' => 'add',
                'category' => 'Work',
            ];
            $categoryPlan = $client->tool('contacts_update_categories', $categoryArgs);
            self::assertTrue($categoryPlan->ok(), $categoryPlan->describe());
            self::assertSame(['Family', 'Work'], $categoryPlan->structured()['contacts'][0]['after']['categories']);
            self::assertSame($original, $backend->getCard($book, 'reconcile.vcf')['carddata'], 'Preview must not write.');
            $categoryWrite = $client->write('contacts_update_categories', $categoryArgs + ['plan_state' => $categoryPlan->structured()['plan_state']]);
            self::assertTrue($categoryWrite->ok(), $categoryWrite->describe());
            self::assertSame('updated', $categoryWrite->json()['contacts'][0]['result']);

            $beforeReconcile = $backend->getCard($book, 'reconcile.vcf')['carddata'];
            $reconcileArgs = ['addressbook' => $addressbook, 'path' => '/' . $sourceName];
            $reconcilePlan = $client->tool('contacts_reconcile_vcf', $reconcileArgs);
            self::assertTrue($reconcilePlan->ok(), $reconcilePlan->describe());
            self::assertSame('update', $reconcilePlan->structured()['contacts'][0]['status']);
            self::assertSame('Before reconcile', (string)Reader::read($beforeReconcile)->FN);
            $reconciled = $client->write('contacts_reconcile_vcf', $reconcileArgs + ['plan_state' => $reconcilePlan->structured()['plan_state']]);
            self::assertTrue($reconciled->ok(), $reconciled->describe());
            $outcome = $reconciled->json()['contacts'][0];
            self::assertSame('updated', $outcome['status']);
            $backupPath = $outcome['backupPath'];
            self::assertSame($beforeReconcile, $root->get(ltrim($backupPath, '/'))->getContent());

            $native = Reader::read($backend->getCard($book, 'reconcile.vcf')['carddata']);
            self::assertSame('After reconcile', (string)$native->FN);
            self::assertSame('Local title', (string)$native->TITLE);
            self::assertSame('+34 600 000 001', (string)$native->TEL);
            self::assertSame('local-only', (string)$native->{'X-RETAIN'});
            self::assertSame(['Family', 'Work'], array_map('strval', $native->CATEGORIES->getParts()));
        } finally {
            if ($root->nodeExists($sourceName)) { $root->get($sourceName)->delete(); }
            if ($backupPath !== null && $root->nodeExists(ltrim($backupPath, '/'))) { $root->get(ltrim($backupPath, '/'))->delete(); }
            $backend->deleteAddressBook($book);
        }
    }
}
