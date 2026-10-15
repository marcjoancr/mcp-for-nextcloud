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

    /** @var \WeakMap<IConfig, IUserConfig>|null the IUserConfig double that serves the same data as each IConfig mock */
    private static ?\WeakMap $userConfigs = null;
    private static ?\WeakMap $stores = null;

    /**
     * A GrantPolicy over an IConfig mock of this class and the IUserConfig double that goes with it
     * (the policy lists flagged users through IUserConfig::searchUsersByValueString).
     */
    public static function policy(IConfig $config, \OCA\Mcp\OAuth\OAuthStore $store): \OCA\Mcp\Service\GrantPolicy {
        $test = self::$stores[$config][1];
        $data = self::$stores[$config][0];
        $mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $db = $mock(\OCP\IDBConnection::class);
        $state = $mock(\OCA\Mcp\Db\PolicyStateMapper::class);
        $revision = 0;
        $state->method('state')->willReturnCallback(static function () use (&$revision): array {
            return ['revision' => (string)$revision, 'lock_seq' => '0', 'auth_epoch' => '0'];
        });
        $state->method('compareAndSet')->willReturnCallback(static function () use (&$revision): void { ++$revision; });
        $users = $mock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($mock(\OCP\IUser::class));
        $appConfig = $mock(\OCP\IAppConfig::class);
        $appConfig->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
        // Setters emit no events yet; a command that does would fail loudly against this mock.
        $events = $mock(\OCA\Mcp\Db\PolicyEventsMapper::class);
        $events->method('append')->willThrowException(new \LogicException('Unexpected policy event'));
        $writer = new \OCA\Mcp\Service\Policy\PolicyWriter($db, $state, $events, $config, self::$userConfigs[$config], $appConfig, $users, $store);
        return new \OCA\Mcp\Service\GrantPolicy($config, self::$userConfigs[$config], $writer);
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
