<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\Server;
final class SharingIdentity
{
    public static function verify(): bool
    {
        try {
            $config = Server::get(\OCP\IConfig::class);
            $options = $config->getSystemValue('dbdriveroptions', []);
            if (!empty($config->getSystemValue('dbpersistent', false)) || !is_array($options) || !empty($options[\PDO::ATTR_PERSISTENT])) {
                return false;
            }
            $sources = json_decode(file_get_contents(dirname(__DIR__) . '/SharingSources.json'), true, 16, JSON_THROW_ON_ERROR);
            foreach ($sources as $class => $hash) {
                $file = (new \ReflectionClass($class))->getFileName();
                if ($file === false || is_link($file) || !hash_equals($hash, hash_file('sha256', $file))) {
                    return false;
                }
            }
            return Server::get(\OCP\IDBConnection::class)::class === \OC\DB\ConnectionAdapter::class;
        } catch (\Throwable) {
            return false;
        }
    }
    public static function assertType(int $type): void
    {
        if (!in_array($type, [0, 1], true)) {
            throw new \RuntimeException('Native Files share provider is not qualified');
        }
        $manager = self::manager();
        $factory = (new \ReflectionProperty($manager, 'factory'))->getValue($manager);
        if ($factory::class !== \OC\Share20\ProviderFactory::class || $factory->getProviderForType($type)::class !== \OC\Share20\DefaultShareProvider::class) {
            throw new \RuntimeException('Native Files share provider is not qualified');
        }
    }
    public static function manager(): \OCP\Share\IManager
    {
        if (!self::verify()) {
            throw new \RuntimeException('Native sharing source is unqualified');
        }
        $manager = Server::get(\OCP\Share\IManager::class);
        if ($manager::class !== \OC\Share20\Manager::class) {
            throw new \RuntimeException('Native share manager is unqualified');
        }
        return $manager;
    }
}
