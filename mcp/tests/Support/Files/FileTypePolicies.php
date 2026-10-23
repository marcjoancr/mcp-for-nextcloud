<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Access\FileWritePolicy\FileWritePolicy;
use OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use PHPUnit\Framework\TestCase;

/** Real policy evaluation over explicit configuration, defaulting only to an absent key. */
final class FileTypePolicies {
    public static function guard(TestCase $test, ?InMemoryAppConfig $config = null): FileTypeGuard {
        if (method_exists($test, 'fileTypeGuard')) {
            return $test->fileTypeGuard();
        }
        return self::fromStore($test, $config ?? new InMemoryAppConfig());
    }

    public static function fromStore(TestCase $test, InMemoryAppConfig $config): FileTypeGuard {
        $db = (new \ReflectionMethod($test, 'createMock'))->invoke($test, \OCP\IDBConnection::class);
        return new FileTypeGuard(new FileWritePolicy($config->worker($test), $db));
    }
}
