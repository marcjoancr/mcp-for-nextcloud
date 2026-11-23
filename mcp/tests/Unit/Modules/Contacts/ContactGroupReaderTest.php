<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Groups\ContactGroupReader;
use PHPUnit\Framework\TestCase;

final class ContactGroupReaderTest extends TestCase
{
    public function testListsDistinctCategoriesFromVisibleAddressBooksWithUsageCounts(): void
    {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([
            ['id' => 1, 'uri' => 'personal', 'displayName' => 'Personal', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
            ['id' => 2, 'uri' => 'shared', 'displayName' => 'Shared', 'ownerPrincipal' => 'principals/users/bob', 'readOnly' => true],
        ]);
        $store->method('cards')->willReturnMap([
            [1, [
                ['uri' => 'a.vcf', 'etag' => '1', 'data' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:a\r\nFN:A\r\nCATEGORIES:Friends,Work\r\nEND:VCARD\r\n"],
                ['uri' => 'b.vcf', 'etag' => '2', 'data' => "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:b\r\nFN:B\r\nCATEGORIES:Friends\r\nEND:VCARD\r\n"],
            ]],
            [2, [
                ['uri' => 'c.vcf', 'etag' => '3', 'data' => "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:c\r\nFN:C\r\nCATEGORIES:Clients\r\nEND:VCARD\r\n"],
            ]],
        ]);

        $result = (new ContactGroupReader(new ContactAccess($store), $store, new ContactCard()))->execute([], 'alice');

        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            ['name' => 'Clients', 'contactCount' => 1],
            ['name' => 'Friends', 'contactCount' => 2],
            ['name' => 'Work', 'contactCount' => 1],
        ], $data['groups']);
    }
}
