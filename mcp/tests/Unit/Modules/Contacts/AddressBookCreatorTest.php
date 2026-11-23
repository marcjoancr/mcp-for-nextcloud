<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookCreator;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use PHPUnit\Framework\TestCase;

final class AddressBookCreatorTest extends TestCase
{
    public function testPreviewDoesNotCreateAndConfirmedCreateReturnsVisibleAddressBook(): void
    {
        $created = false;
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturnCallback(function () use (&$created): array {
            return $created ? [[
                'id' => 9,
                'uri' => 'family',
                'displayName' => 'Family',
                'ownerPrincipal' => 'principals/users/alice',
                'readOnly' => false,
            ]] : [];
        });
        $dav = $this->createMock(ContactDav::class);
        $dav->expects(self::once())->method('createAddressBook')->with('alice', 'family', 'Family')
            ->willReturnCallback(function () use (&$created): DavResult {
                $created = true;
                return new DavResult(201);
            });
        $creator = new AddressBookCreator(new ContactAccess($store), $dav);

        $plan = $creator->preview(['displayName' => 'Family'], 'alice');
        self::assertSame('family', $plan['uri']);
        self::assertFalse($created);
        $result = $creator->execute(['displayName' => 'Family', 'confirm' => true], 'alice');

        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/remote.php/dav/addressbooks/users/alice/family/', $data['addressbook']['path']);
        self::assertSame('Family', $data['addressbook']['name']);
    }

    public function testRefusesANameWhoseAddressBookUriAlreadyExists(): void
    {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 1,
            'uri' => 'family',
            'displayName' => 'Family',
            'ownerPrincipal' => 'principals/users/alice',
            'readOnly' => false,
        ]]);
        $dav = $this->createMock(ContactDav::class);
        $dav->expects(self::never())->method('createAddressBook');
        $creator = new AddressBookCreator(new ContactAccess($store), $dav);

        $this->expectException(\InvalidArgumentException::class);
        $creator->preview(['displayName' => 'Family'], 'alice');
    }
}
