<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\Config\IUserConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** Builds an IConfig mock backed by an array, standing in for Nextcloud's persisted config. */
final class InMemoryConfig {
    public array $app = [];
    public array $user = [];
    /** @var array<string, string|int> system values served by getSystemValueString and getSystemValueInt */
    public array $system = [];
    /** @var list<array{string, string, string}> every [scope, app, key] read or written ('app' or 'user') */
    public array $accessed = [];
    /** Number of getUserValueForUsers calls. */
    public int $batchCalls = 0;
    /** Number of getUsersForUserValue calls, the flagged-user search of Nextcloud 31. */
    public int $legacySearches = 0;
    /** @var array<string, array{id:string, kind:string, status:string, payload:array, createdAt:int, attempts:int}> policy events by id */
    public array $events = [];
    /** Clock of the policy writer and of its events. */
    public int $now = 1_800_000_000;
    /** Policy revision of the writer built by policy(). */
    public int $revision = 0;
    /** @var (\Closure(string):bool)|null which uids the writer's user manager knows; null knows every uid */
    public ?\Closure $userExists = null;

    /** @var \WeakMap<IConfig, IUserConfig>|null the IUserConfig double that serves the same data as each IConfig mock */
    private static ?\WeakMap $userConfigs = null;
    private static ?\WeakMap $stores = null;
    private static ?\WeakMap $writers = null;
    private static ?\WeakMap $states = null;

    /** @return \OCA\Mcp\Db\PolicyStateMapper the state the writer of $config reads, for a controller under test */
    public static function state(IConfig $config): \OCA\Mcp\Db\PolicyStateMapper {
        self::writer($config);
        return self::$states[$config];
    }

    /**
     * A GrantPolicy over an IConfig mock of this class and the IUserConfig double that goes with it
     * (the policy lists flagged users through IUserConfig::searchUsersByValueString).
     */
    public static function policy(IConfig $config, \OCA\Mcp\OAuth\OAuthStore $store): \OCA\Mcp\Service\GrantPolicy {
        return new \OCA\Mcp\Service\GrantPolicy($config, self::$userConfigs[$config], self::writer($config, $store));
    }

    /**
     * The policy writer over an IConfig mock of this class: one per IConfig, so the policy, the logs gate and the
     * controller of a test share its revision ($revision) and its events ($events, appended at $now). The database
     * is a mock without transactions: atomicity and rollback are proven against SQLite and the real server.
     */
    public static function writer(IConfig $config, ?\OCA\Mcp\OAuth\OAuthStore $store = null): \OCA\Mcp\Service\Policy\PolicyWriter {
        self::$writers ??= new \WeakMap();
        self::$states ??= new \WeakMap();
        if (isset(self::$writers[$config])) {
            return self::$writers[$config];
        }
        $test = self::$stores[$config][1];
        $data = self::$stores[$config][0];
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $db = $mock(\OCP\IDBConnection::class);
        $state = $mock(\OCA\Mcp\Db\PolicyStateMapper::class);
        $state->method('state')->willReturnCallback(static function () use ($data): array {
            return ['revision' => (string)$data->revision, 'lock_seq' => '0', 'auth_epoch' => '0'];
        });
        $state->method('compareAndSet')->willReturnCallback(static function (string $expected) use ($data): void {
            if ($expected !== (string)$data->revision) {
                throw new \OCA\Mcp\Service\Policy\PolicyConflict();
            }
            ++$data->revision;
        });
        $users = $mock(\OCP\IUserManager::class);
        $user = $mock(\OCP\IUser::class);
        $users->method('get')->willReturnCallback(static fn (string $uid) => $data->userExists === null || ($data->userExists)($uid) ? $user : null);
        $appConfig = $mock(\OCP\IAppConfig::class);
        $appConfig->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
        self::$states[$config] = $state;
        $events = $mock(\OCA\Mcp\Db\PolicyEventsMapper::class);
        $events->method('append')->willReturnCallback(static function (string $id, string $kind, array $payload) use ($data): void {
            $data->events[$id] = ['id' => $id, 'kind' => $kind, 'status' => 'pending', 'payload' => $payload, 'createdAt' => $data->now, 'attempts' => 0];
        });
        $events->method('find')->willReturnCallback(static fn (string $id): ?array => $data->events[$id] ?? null);
        $time = $mock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(static fn (): int => $data->now);
        $store ??= new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore();
        return self::$writers[$config] = new \OCA\Mcp\Service\Policy\PolicyWriter($db, $state, $events, $config,
            self::$userConfigs[$config], $appConfig, $users, $store, $time);
    }

    public function mock(TestCase $test): IConfig {
        $config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IConfig::class);
        $userConfig = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IUserConfig::class);
        self::$userConfigs ??= new \WeakMap();
        self::$userConfigs[$config] = $userConfig;
        self::$stores ??= new \WeakMap();
        self::$stores[$config] = [$this, $test];
        $userConfig->method('searchUsersByValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $caseInsensitive = false): \Generator {
            $this->accessed[] = ['user', $app, $key];
            yield from array_keys(array_filter($this->user, static fn (array $apps) => ($apps[$app][$key] ?? null) === $value));
        });
        $config->method('getSystemValueString')->willReturnCallback(function ($key, $default = '') {
            return (string)($this->system[$key] ?? $default);
        });
        $config->method('getSystemValueInt')->willReturnCallback(function ($key, $default = 0) {
            return (int)($this->system[$key] ?? $default);
        });
        $config->method('getAppValue')->willReturnCallback(function ($app, $key, $default = '') {
            $this->accessed[] = ['app', $app, $key];
            return $this->app[$app][$key] ?? $default;
        });
        $config->method('setAppValue')->willReturnCallback(function ($app, $key, $value): void {
            $this->accessed[] = ['app', $app, $key];
            $this->app[$app][$key] = $value;
        });
        $config->method('deleteAppValue')->willReturnCallback(function ($app, $key): void {
            $this->accessed[] = ['app', $app, $key];
            unset($this->app[$app][$key]);
        });
        $config->method('getUserValue')->willReturnCallback(function ($uid, $app, $key, $default = '') {
            $this->accessed[] = ['user', $app, $key];
            return $this->user[$uid][$app][$key] ?? $default;
        });
        $config->method('getUserValueForUsers')->willReturnCallback(function ($app, $key, $uids) {
            $this->accessed[] = ['user', $app, $key];
            $this->batchCalls++;
            $out = [];
            foreach ($uids as $uid) {
                if (isset($this->user[$uid][$app][$key])) {
                    $out[$uid] = $this->user[$uid][$app][$key];
                }
            }
            return $out;
        });
        $config->method('getUsersForUserValue')->willReturnCallback(function ($app, $key, $value): array {
            $this->accessed[] = ['user', $app, $key];
            $this->legacySearches++;
            return array_keys(array_filter($this->user, static fn (array $apps) => ($apps[$app][$key] ?? null) === $value));
        });
        $config->method('deleteUserValue')->willReturnCallback(function ($uid, $app, $key): void {
            $this->accessed[] = ['user', $app, $key];
            unset($this->user[$uid][$app][$key]);
        });
        $config->method('setUserValue')->willReturnCallback(function ($uid, $app, $key, $value): void {
            $this->accessed[] = ['user', $app, $key];
            $this->user[$uid][$app][$key] = $value;
        });
        return $config;
    }
}
