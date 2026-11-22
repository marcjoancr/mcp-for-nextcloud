<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Server;

/** Fresh qualified native objects scoped to one authority decision. */
final class FreshCore {
    private \OCP\IUser $user;
    private \OCP\IGroupManager $groups;
    private \OC\AppConfig $appConfig;
    private \OCP\Config\IUserConfig $userConfig;
    private \OCP\App\IAppManager $apps;

    public function __construct(string $uid) {
        self::verifySources();
        if (!empty(Server::get(\OCP\IConfig::class)->getSystemValue('dbreplica', []))) {
            throw new \RuntimeException('Forms principal database replicas are unqualified');
        }
        $this->appConfig = self::copy(Server::get(\OCP\IAppConfig::class), \OC\AppConfig::class);
        $cache = self::property($this->appConfig, 'localCache');
        foreach (['fastCache', 'lazyCache', 'valueTypes', 'configLexiconDetails', 'strictnessApplied'] as $field) {
            self::set($this->appConfig, $field, []);
        }
        self::set($this->appConfig, 'fastLoaded', false);
        self::set($this->appConfig, 'lazyLoaded', false);
        self::set($this->appConfig, 'appVersionsCache', null);
        self::set($this->appConfig, 'localCache', new FreshConfigCache($cache));

        $this->userConfig = self::copy(Server::get(\OCP\Config\IUserConfig::class), \OC\Config\UserConfig::class);
        self::set($this->userConfig, 'fastCache', new \OCP\Cache\CappedMemoryCache());
        self::set($this->userConfig, 'lazyCache', new \OCP\Cache\CappedMemoryCache());

        $original = Server::get(\OCP\IUserManager::class)->get($uid);
        if ($original === null || $original::class !== \OC\User\User::class || $original->getUID() !== $uid) {
            throw new \RuntimeException('Forms principal is unavailable');
        }
        $originalBackend = $original->getBackend();
        if ($originalBackend === null) {
            throw new \RuntimeException('Forms user backend is unqualified');
        }
        $backend = (new FreshUserBackend())->forUser($originalBackend, $uid);
        $this->user = clone $original;
        self::set($this->user, 'backend', $backend);
        self::set($this->user, 'enabled', null);
        self::set($this->user, 'userConfig', $this->userConfig);
        if (!$this->user->isEnabled()) {
            throw new \RuntimeException('Forms principal is disabled');
        }

        $this->groups = self::copy(Server::get(\OCP\IGroupManager::class), \OC\Group\Manager::class);
        $backends = [];
        foreach ($this->groups->getBackends() as $groupBackend) {
            if ($groupBackend::class === 'OCA\\Guests\\GroupBackend') {
                (new FreshGuestGroup())->assertAbsent($groupBackend, $uid);
                continue;
            }
            if ($groupBackend::class !== \OC\Group\Database::class) {
                throw new \RuntimeException('Forms group backend is unqualified');
            }
            $connection = self::property($groupBackend, 'dbConn');
            if ($connection !== null && $connection !== Server::get(\OCP\IDBConnection::class)) {
                throw new \RuntimeException('Forms group database is unqualified');
            }
            $fresh = clone $groupBackend;
            self::set($fresh, 'groupCache', []);
            $backends[] = $fresh;
        }
        self::set($this->groups, 'backends', $backends);
        self::set($this->groups, 'cachedGroups', []);
        self::set($this->groups, 'cachedUserGroups', []);
        self::set($this->groups, 'userSession', new ScopedUserSession($this->user));

        $this->apps = self::copy(Server::get(\OCP\App\IAppManager::class), \OC\App\AppManager::class);
        self::set($this->apps, 'appConfig', $this->appConfig);
        self::set($this->apps, 'enabledAppsCache', []);
        self::set($this->apps, 'groupManager', $this->groups);
        self::set($this->apps, 'userSession', new ScopedUserSession($this->user));
    }

    public function assertPrincipal(bool $requiresAdmin = false, bool $requiresMcp = true): void {
        if (($this->user->getBackend()::class === 'OCA\\UserOIDC\\User\\Backend' && !$this->apps->isEnabledForUser('user_oidc', $this->user))
            || !$this->apps->isEnabledForUser('forms', $this->user) || ($requiresMcp && !$this->apps->isEnabledForUser('mcp', $this->user))) {
            throw new \RuntimeException('Forms principal app access is unavailable');
        }
        if ($requiresAdmin && !$this->groups->isAdmin($this->user->getUID())) {
            throw new \RuntimeException('Administrator required');
        }
    }

    public function user(): \OCP\IUser {return $this->user;}
    public function groups(): \OCP\IGroupManager {return $this->groups;}
    public function appConfig(): \OCP\IAppConfig {return $this->appConfig;}
    public function userConfig(): \OCP\Config\IUserConfig {return $this->userConfig;}
    public function apps(): \OCP\App\IAppManager {return $this->apps;}

    private static function copy(object $object, string $expectedClass): object {
        if ($object::class !== $expectedClass) {
            throw new \RuntimeException('Forms native authority implementation is unqualified');
        }
        return clone $object;
    }

    private static function property(object $object, string $name): mixed {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private static function set(object $object, string $name, mixed $value): void {
        (new \ReflectionProperty($object, $name))->setValue($object, $value);
    }

    private static function verifySources(): void {
        $sources = json_decode(file_get_contents(dirname(__DIR__).'/PrincipalSources.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($sources as $class => $expected) {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file === false || is_link($file) || !hash_equals($expected, hash_file('sha256', $file))) {
                throw new \RuntimeException('Forms native authority source is unqualified');
            }
        }
    }
}
