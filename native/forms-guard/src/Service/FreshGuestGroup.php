<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Proves that the optional Guests virtual group contributes no authority to this principal. */
final class FreshGuestGroup {
    private const SOURCES = [
        'OCA\\Guests\\GroupBackend' => '72b5092570f97531d0440e44bd67addb6ccde399809939cb3731be880e13536c',
        'OCA\\Guests\\GuestManager' => '6d5d6c8c11e1cd8dd583e4abd06e1e1c2b951987215f4aee3cbce0079240e4b1',
        'OCA\\Guests\\UserBackend' => '6498433aca01d07bbe944e5d86346179b41769e8e8e6b3cc1fd003e560288f3e',
    ];
    /** @param array<class-string,string> $sources */
    public function __construct(private array $sources = self::SOURCES) {}

    public function assertAbsent(object $backend, string $uid): void {
        if ($backend::class !== 'OCA\\Guests\\GroupBackend' || self::property($backend, 'groupName') !== 'guest_app') {
            throw new \RuntimeException('Forms group backend is unqualified');
        }
        foreach ($this->sources as $class => $expected) {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file === false || is_link($file) || !hash_equals($expected, hash_file('sha256', $file))) {
                throw new \RuntimeException('Forms Guests source is unqualified');
            }
        }
        $manager = self::property($backend, 'guestManager');
        if ($manager::class !== 'OCA\\Guests\\GuestManager') {
            throw new \RuntimeException('Forms guest authority is unqualified');
        }
        $users = self::property($manager, 'userBackend');
        if ($users::class !== 'OCA\\Guests\\UserBackend'
            || self::property($users, 'dbConn') !== \OCP\Server::get(\OCP\IDBConnection::class)) {
            throw new \RuntimeException('Forms guest authority is unqualified');
        }
        $fresh = clone $users;
        (new \ReflectionProperty($fresh, 'cache'))->setValue($fresh, new \OCP\Cache\CappedMemoryCache());
        if ($fresh->userExists($uid)) {
            throw new \RuntimeException('Forms guest authority is unqualified');
        }
    }

    private static function property(object $object, string $name): mixed {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
