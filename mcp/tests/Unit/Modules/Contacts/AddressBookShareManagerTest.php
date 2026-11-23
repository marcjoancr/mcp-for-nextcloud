<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Contacts;

use OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookShareManager;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class AddressBookShareManagerTest extends TestCase
{
    public function testOwnerCanPreviewAndConfirmNativeReadOnlyShare(): void
    {
        $shares = [];
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 1,
            'uri' => 'personal',
            'displayName' => 'Personal',
            'ownerPrincipal' => 'principals/users/alice',
            'readOnly' => false,
        ]]);
        $store->method('shares')->with(1)->willReturnCallback(static function () use (&$shares): array { return $shares; });
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $user->method('getDisplayName')->willReturn('Bob Reader');
        $user->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('bob')->willReturn($user);
        $dav = $this->createMock(ContactDav::class);
        $dav->expects(self::once())->method('shareAddressBook')->with('alice', 'personal', 'bob', 'Bob Reader', true)
            ->willReturnCallback(static function () use (&$shares): DavResult {
                $shares[] = [
                    'href' => 'principal:bob',
                    'commonName' => 'Bob Reader',
                    'status' => 1,
                    'readOnly' => true,
                    '{http://owncloud.org/ns}principal' => 'principals/users/bob',
                    '{http://owncloud.org/ns}group-share' => false,
                ];
                return new DavResult(200);
            });
        $manager = new AddressBookShareManager(new ContactAccess($store), $store, $users, $dav, $this->visibleBob());
        $args = ['addressbook' => '/remote.php/dav/addressbooks/users/alice/personal/', 'shareWith' => 'bob', 'access' => 'read'];

        $plan = $manager->preview($args, 'alice');
        self::assertSame('Bob Reader', $plan['recipient']['displayName']);
        self::assertSame('read-only', $plan['access']);
        self::assertSame([], $shares);
        $result = $manager->share($args + ['confirm' => true], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('bob', $data['share']['userId']);
        self::assertTrue($data['share']['readOnly']);
    }

    public function testARecipientHiddenByNativeSharingDoesNotReachUserLookup(): void
    {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 1, 'uri' => 'personal', 'displayName' => 'Personal',
            'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false,
        ]]);
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([['exact' => ['users' => []]], false]);
        $users = $this->createMock(IUserManager::class);
        $users->expects(self::never())->method('get');
        $manager = new AddressBookShareManager(new ContactAccess($store), $store, $users, $this->createMock(ContactDav::class), $search);
        $this->expectException(\InvalidArgumentException::class);
        $manager->preview([
            'addressbook' => '/remote.php/dav/addressbooks/users/alice/personal/', 'shareWith' => 'bob', 'access' => 'write',
        ], 'alice');
    }

    public function testListingSharesOnlyReturnsRecipientIdsAndAccess(): void
    {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 1,
            'uri' => 'personal',
            'displayName' => 'Personal',
            'ownerPrincipal' => 'principals/users/alice',
            'readOnly' => false,
        ]]);
        $store->method('shares')->with(1)->willReturn([[
            'href' => 'principal:bob',
            'commonName' => 'Bob',
            'status' => 1,
            'readOnly' => false,
            '{http://owncloud.org/ns}principal' => 'principals/users/bob',
            '{http://owncloud.org/ns}group-share' => false,
        ]]);
        $users = $this->createMock(IUserManager::class);
        $manager = new AddressBookShareManager(new ContactAccess($store), $store, $users, $this->createMock(ContactDav::class), $this->createMock(ISearch::class));

        $result = $manager->list(['addressbook' => '/remote.php/dav/addressbooks/users/alice/personal/'], 'alice');
        $data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('bob', $data['shares'][0]['userId']);
        self::assertFalse($data['shares'][0]['readOnly']);
        self::assertArrayNotHasKey('email', $data['shares'][0]);
    }

    public function testCanChangeReadShareToWriteAndRemoveWithConfirmation(): void
    {
        $shares = [[
            'href' => 'principal:bob',
            'commonName' => 'Bob Reader',
            'status' => 1,
            'readOnly' => true,
            '{http://owncloud.org/ns}principal' => 'principals/users/bob',
        ]];
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([[
            'id' => 1, 'uri' => 'personal', 'displayName' => 'Personal',
            'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false,
        ]]);
        $store->method('shares')->with(1)->willReturnCallback(static function () use (&$shares): array { return $shares; });
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $user->method('getDisplayName')->willReturn('Bob Reader');
        $user->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('bob')->willReturn($user);
        $dav = $this->createMock(ContactDav::class);
        $calls = [];
        $dav->expects(self::exactly(2))->method('shareAddressBook')
            ->willReturnCallback(static function (string $owner, string $book, string $sharee, string $name, ?bool $readOnly) use (&$shares, &$calls): DavResult {
                $calls[] = [$owner, $book, $sharee, $name, $readOnly];
                if ($readOnly === null) {
                    $shares = [];
                } else {
                    $shares[0]['readOnly'] = $readOnly;
                }
                return new DavResult(200);
            });
        $manager = new AddressBookShareManager(new ContactAccess($store), $store, $users, $dav, $this->visibleBob());
        $base = ['addressbook' => '/remote.php/dav/addressbooks/users/alice/personal/', 'shareWith' => 'bob'];

        $writePlan = $manager->preview($base + ['access' => 'write'], 'alice');
        self::assertSame('read-only', $writePlan['existingAccess']);
        $manager->share($base + ['access' => 'write', 'confirm' => true], 'alice');
        self::assertFalse($shares[0]['readOnly']);

        $removePlan = $manager->preview($base + ['access' => 'remove'], 'alice');
        self::assertSame('remove_share', $removePlan['action']);
        $result = $manager->share($base + ['access' => 'remove', 'confirm' => true], 'alice');
        self::assertSame([], $shares);
        self::assertSame([
            ['alice', 'personal', 'bob', 'Bob Reader', false],
            ['alice', 'personal', 'bob', 'Bob Reader', null],
        ], $calls);
        self::assertSame('removed', json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    private function visibleBob(): ISearch
    {
        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturn([['exact' => ['users' => [['value' => ['shareWith' => 'bob']]]]], false]);
        return $search;
    }
}
