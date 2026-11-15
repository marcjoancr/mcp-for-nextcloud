<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Optional provider qualification is independent of the Forms/user/group baseline. */
final class CircleRuntime {
    public static function available(string $uid): bool {
        try {
            $core = new FreshCore($uid);
            if (!$core->apps()->isEnabledForUser('circles', $core->user()) || $core->appConfig()->getValueString('circles', 'installed_version') !== '35.0.0') {return false;}
            $root = $core->apps()->getAppPath('circles');
            $sources = json_decode(file_get_contents(dirname(__DIR__) . '/CircleSources.json'), true, 32, JSON_THROW_ON_ERROR);
            foreach ($sources as $path => $hash) {
                $file = $root . '/' . $path;
                if (!is_file($file) || is_link($file) || !hash_equals($hash, hash_file('sha256', $file))) {return false;}
            }
            return Server::get(\OCA\Circles\ShareByCircleProvider::class)::class === \OCA\Circles\ShareByCircleProvider::class;
        } catch (\Throwable) {return false;}
    }
    public static function assertAvailable(string $uid): void {
        if (!self::available($uid)) {throw new \RuntimeException('Native Circle provider source or account access is unqualified');}
    }
}
