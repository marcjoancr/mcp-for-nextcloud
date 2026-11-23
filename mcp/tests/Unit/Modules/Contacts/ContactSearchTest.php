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
use OCA\Mcp\Modules\Contacts\Directory\AccountDirectory;
use OCA\Mcp\Modules\Contacts\Search\ContactSearch;
use PHPUnit\Framework\TestCase;

final class ContactSearchTest extends TestCase
{
    public function testFiltersByCategoryAndReturnsCategoriesAlongsideStructuredFields(): void
    {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([
            ['id' => 1, 'uri' => 'personal', 'displayName' => 'Personal', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false],
        ]);
        $store->method('cards')->with(1)->willReturn([
            ['uri' => 'a.vcf', 'etag' => 'v1', 'data' => "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:a\r\nFN:Ana Costa\r\nN:Costa;Ana;;;\r\nCATEGORIES:Friends,Clients\r\nEND:VCARD\r\n"],
            ['uri' => 'b.vcf', 'etag' => 'v2', 'data' => "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:b\r\nFN:Bruno Silva\r\nCATEGORIES:Friends\r\nEND:VCARD\r\n"],
        ]);
        $system = $this->createMock(AccountDirectory::class);
        $system->method('bookName')->willReturn(null);

        $result = (new ContactSearch(new ContactAccess($store), $store, new ContactCard(), $system))
            ->execute(['group' => 'clients'], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['contacts']);
        self::assertSame(['Friends', 'Clients'], $data['contacts'][0]['categories']);
        self::assertSame('Costa', $data['contacts'][0]['familyName']);
    }
}
