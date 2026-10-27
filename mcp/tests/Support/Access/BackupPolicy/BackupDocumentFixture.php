<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Access\BackupPolicy;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\BackupPolicy\BackupSettingsDocument;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** Adapts existing flow fixtures; the real SQL reader is exercised separately against SQLite and Nextcloud. */
final class BackupDocumentFixture {
    public static function location(TestCase $test, ?\OCA\Mcp\Tests\Unit\InMemoryConfig $config = null): BackupLocation {
        return new BackupLocation(self::fromConfig($test, ($config ?? new \OCA\Mcp\Tests\Unit\InMemoryConfig())->appConfig($test)));
    }

    public static function copies(TestCase $test, BackupLocation $location): \OCA\Mcp\Access\BackupPolicy\BackupCopies {
        $mock = static fn (string $type) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $type);
        $apps = $mock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $mock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(static function (string $uid) use ($mock) {
            $user = $mock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        return new \OCA\Mcp\Access\BackupPolicy\BackupCopies($location, $apps, $users);
    }

    public static function plan(TestCase $test, ?\OCA\Mcp\Tests\Unit\InMemoryConfig $config = null): \OCA\Mcp\Modules\Files\Presentation\BackupPlan {
        $location = self::location($test, $config);
        return new \OCA\Mcp\Modules\Files\Presentation\BackupPlan($location, self::copies($test, $location));
    }

	/**
	 * @param TestCase $test owner of the mock
	 * @param IAppConfig $config mutable in-memory fixture, never production configuration
	 * @return BackupSettingsDocument document double reflecting the fixture's current bytes
	 */
	public static function fromConfig(TestCase $test, IAppConfig $config): BackupSettingsDocument {
		$document = (new \ReflectionMethod($test, 'createMock'))->invoke($test, BackupSettingsDocument::class);
		$document->method('read')->willReturnCallback(static fn (): ?string => $config->hasKey('mcp', BackupLocation::KEY, null)
			? $config->getValueString('mcp', BackupLocation::KEY, '', false) : null);
		return $document;
	}
}
