<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use OCA\Forms\Mcp\Service\NativeHomeProfile;
use PHPUnit\Framework\TestCase;

final class NativeHomeProviderOrderTest extends TestCase {
    private const BASELINE = [
        'OC\\Files\\Mount\\CacheMountProvider',
        'OCA\\Files_Sharing\\MountProvider',
        'OCA\\Files_Sharing\\External\\MountProvider',
        'OCA\\Files_External\\Config\\ConfigAdapter',
    ];

    private const MARIADB = [
        'OC\\Files\\Mount\\CacheMountProvider',
        'OCA\\Files_External\\Config\\ConfigAdapter',
        'OCA\\Files_Sharing\\MountProvider',
        'OCA\\Files_Sharing\\External\\MountProvider',
    ];

    private function profile(): array {
        return json_decode(file_get_contents(__DIR__ . '/../src/Service/NativeHomeSources.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function testProfileListsOnlyTheTwoObservedProviderOrders(): void {
        $profile = $this->profile();
        self::assertSame([
            'providers' => [self::BASELINE, self::MARIADB],
        ], $profile['registrations']['provider_orders'] ?? []);
        self::assertSame([
            'apps/files_external/lib/AppInfo/Application.php' => '3903bc358392bce11338355b77ee1f8fa01eef3d8b7794be26af0af9ab017cbb',
            'apps/files_sharing/lib/AppInfo/Application.php' => '70a3ec6a2680db8d729aa44ed8528b52c26cd167086f5b7728882a3720ece040',
        ], array_intersect_key($profile['sources'] ?? [], array_flip([
            'apps/files_external/lib/AppInfo/Application.php',
            'apps/files_sharing/lib/AppInfo/Application.php',
        ])));
    }

    public function testOnlyTheTwoPinnedProviderOrdersAreAccepted(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeProfile.php';
        $method = new ReflectionMethod(NativeHomeProfile::class, 'providerOrderMatches');
        $rows = array_map(static fn(string $class): array => ['class' => $class], self::BASELINE);
        $orders = [self::BASELINE, self::MARIADB];

        self::assertTrue($method->invoke(null, $rows, self::BASELINE, $orders));
        self::assertTrue($method->invoke(null, $rows, self::MARIADB, $orders));
        self::assertFalse($method->invoke(null, $rows, self::MARIADB, $orders, self::BASELINE));
        self::assertFalse($method->invoke(null, $rows, [self::BASELINE[0], self::BASELINE[1], self::BASELINE[3], self::BASELINE[2]], $orders));
        self::assertFalse($method->invoke(null, $rows, [self::BASELINE[0], self::BASELINE[1], self::BASELINE[2], 'OCA\\Unknown\\MountProvider'], $orders));
        self::assertFalse($method->invoke(null, $rows, [self::BASELINE[0], self::BASELINE[1], self::BASELINE[2]], $orders));
    }
}
