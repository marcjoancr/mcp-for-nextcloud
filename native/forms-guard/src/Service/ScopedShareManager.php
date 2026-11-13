<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\Server;
/** Clone the pinned mutable graph without re-registering global native event hooks. */
final class ScopedShareManager
{
    private static function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
    public static function createdHere(\OC\Share20\Manager $manager, string $id): bool
    {
        $factory = (new \ReflectionProperty($manager, 'factory'))->getValue($manager);
        return $factory instanceof SharingProviderFactory && $factory->createdHere($id);
    }
    public static function create(string $uid, \Closure $beforeWrite): \OC\Share20\Manager
    {
        $manager = clone SharingIdentity::manager();
        $core = new FreshCore($uid);
        $config = new SharingConfig($core);
        $users = Server::get(\OCP\IUserManager::class);
        if ($users::class !== \OC\User\Manager::class) {
            throw new \RuntimeException('Native sharing user manager is unqualified');
        }
        $users = clone $users;
        $backends = [];
        foreach ($users->getBackends() as $key => $backend) {
            if ($backend::class !== \OC\User\Database::class || (new \ReflectionProperty($backend, 'table'))->getValue($backend) !== 'users') {
                throw new \RuntimeException('Native sharing user backend is unqualified');
            }
            $backend = clone $backend;
            self::set($backend, 'cache', new \OCP\Cache\CappedMemoryCache());
            $backends[$key] = $backend;
        }
        self::set($users, 'backends', $backends);
        self::set($users, 'cachedUsers', [$uid => $core->user()]);
        self::set($users, 'config', $config);
        $groups = $core->groups();
        self::set($groups, 'userManager', $users);
        $provider = Server::get(\OC\Share20\DefaultShareProvider::class);
        if ($provider::class !== \OC\Share20\DefaultShareProvider::class) {
            throw new \RuntimeException('Native sharing default provider is unqualified');
        }
        $arguments = [];
        foreach ((new \ReflectionClass(\OC\Share20\DefaultShareProvider::class))->getConstructor()->getParameters() as $parameter) {
            $arguments[] = match ($parameter->getName()) {
                'config' => $config,
                'groupManager' => $groups,
                'userManager' => $users,
                'shareManager' => $manager,
                default => (new \ReflectionProperty($provider, $parameter->getName()))->getValue($provider),
            };
        }
        $provider = new SharingDefaultProvider(...$arguments);
        $provider->setWriteBoundary($beforeWrite);
        $factory = new SharingProviderFactory($core->apps(), $provider);
        foreach (['config' => $config, 'appConfig' => $core->appConfig(), 'groupManager' => $groups, 'userManager' => $users, 'factory' => $factory, 'userSession' => new ScopedUserSession($core->user())] as $field => $value) {
            self::set($manager, $field, $value);
        }
        return $manager;
    }
}
