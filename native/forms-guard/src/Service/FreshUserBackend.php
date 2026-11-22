<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Independent account-existence decisions without reusing native backend caches. */
final class FreshUserBackend {
    /** Reviewed user_oidc 8.11.0 account-existence implementation. Authentication remains native. */
    private const OIDC_SOURCES = [
        'OCA\\UserOIDC\\User\\Backend' => '44aa43523171bf539f624963ba54f05a68fc4f0ff7b7c598d746f49661f711aa',
        'OCA\\UserOIDC\\Db\\UserMapper' => '91a64cabb4748873dd3225d1bbd2d7f3030bea906828f4a3c2e7c99c1a507bc8',
    ];

    /** @param array<class-string, string> $oidcSources */
    public function __construct(private array $oidcSources = self::OIDC_SOURCES) {}

    public function forUser(object $backend, string $uid): object {
        $fresh = clone $backend;
        if ($backend::class === \OC\User\Database::class && self::property($backend, 'table') === 'users') {
            self::set($fresh, 'cache', new \OCP\Cache\CappedMemoryCache());
        } elseif ($backend::class === 'OCA\\UserOIDC\\User\\Backend') {
            foreach ($this->oidcSources as $class => $expected) {
                $file = (new \ReflectionClass($class))->getFileName();
                if ($file === false || is_link($file) || !hash_equals($expected, hash_file('sha256', $file))) {
                    throw new \RuntimeException('Forms OIDC authority source is unqualified');
                }
            }
            $mapper = self::property($backend, 'userMapper');
            if ($mapper::class !== 'OCA\\UserOIDC\\Db\\UserMapper'
                || self::property($mapper, 'tableName') !== 'user_oidc'
                || self::property($mapper, 'db') !== \OCP\Server::get(\OCP\IDBConnection::class)) {
                throw new \RuntimeException('Forms OIDC account mapper is unqualified');
            }
            $mapper = clone $mapper;
            self::set($mapper, 'userCache', new \OCP\Cache\CappedMemoryCache());
            self::set($fresh, 'userMapper', $mapper);
        } else {
            throw new \RuntimeException('Forms user backend is unqualified');
        }
        if (!$fresh->userExists($uid)) {
            throw new \RuntimeException('Forms principal is unavailable');
        }
        return $fresh;
    }

    private static function property(object $object, string $name): mixed {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private static function set(object $object, string $name, mixed $value): void {
        (new \ReflectionProperty($object, $name))->setValue($object, $value);
    }
}
