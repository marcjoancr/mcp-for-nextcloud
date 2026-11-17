<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use OCA\Forms\Mcp\Service\NativeHomeProfile;
use PHPUnit\Framework\TestCase;

final class NativeHomeEventCallbackBoundFixture {}

final class NativeHomeEventCallbackScopeFixture {
    public static function make(): Closure {
        return Closure::bind(function (): void {}, new NativeHomeEventCallbackBoundFixture(), self::class);
    }

    public static function makeWithCapture(): Closure {
        $unexpected = true;
        return Closure::bind(function () use ($unexpected): void {}, new NativeHomeEventCallbackBoundFixture(), self::class);
    }
}

final class NativeHomeEventCallbackTest extends TestCase {
    private function profile(): array {
        return json_decode(file_get_contents(__DIR__ . '/../src/Service/NativeHomeSources.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function testProfilePinsMcpFileInvalidationCallbacksAtExactSlots(): void {
        $profile = $this->profile();
        $events = $profile['registrations']['events'];
        $mcpEvents = [
            'OCP\\Files\\Events\\Node\\NodeCreatedEvent' => [
                ['row_index' => 2, 'position' => 0, 'class' => 'OCA\\Mcp\\Access\\Visibility\\VisibilityGuard', 'bound_class' => 'OCA\\Mcp\\Access\\ResourceScope\\FilesAccessGuard', 'start' => 91, 'end' => 91,
                    'sha256' => 'ed2bf0c144e7918137b8eaa91010fe6e2acc26a18dfca29a20d6a838144c1c83'],
            ],
            'OCP\\Files\\Events\\Node\\NodeWrittenEvent' => [
                ['row_index' => 6, 'position' => 0, 'class' => 'OCA\\Mcp\\Access\\Visibility\\VisibilityGuard', 'bound_class' => 'OCA\\Mcp\\Access\\ResourceScope\\FilesAccessGuard', 'start' => 91, 'end' => 91,
                    'sha256' => 'ed2bf0c144e7918137b8eaa91010fe6e2acc26a18dfca29a20d6a838144c1c83'],
            ],
            'OCP\\Files\\Events\\Node\\NodeDeletedEvent' => [
                ['row_index' => 5, 'position' => 0, 'class' => 'OCA\\Mcp\\Access\\Visibility\\VisibilityGuard', 'bound_class' => 'OCA\\Mcp\\Access\\ResourceScope\\FilesAccessGuard', 'start' => 91, 'end' => 91,
                    'sha256' => 'ed2bf0c144e7918137b8eaa91010fe6e2acc26a18dfca29a20d6a838144c1c83'],
                ['row_index' => 6, 'position' => 1, 'class' => 'OCA\\Mcp\\Access\\ResourceScope\\FilesScope', 'bound_class' => 'OCA\\Mcp\\Access\\ResourceScope\\FilesScope', 'start' => 50, 'end' => 50,
                    'sha256' => '13dc244c6876e9622f8f59a5703c159818db5b28fa01727bc68907e29c47a278'],
            ],
        ];
        $sourcePins = $profile['sources'];
        foreach ($mcpEvents as $event => $rows) {
            self::assertCount(count($rows) + ($event === 'OCP\\Files\\Events\\Node\\NodeCreatedEvent' ? 2 : ($event === 'OCP\\Files\\Events\\Node\\NodeWrittenEvent' ? 6 : 5)), $events[$event]);
            foreach ($rows as $row) {
                $descriptor = $events[$event][$row['row_index']];
                self::assertSame(PHP_INT_MIN, $descriptor['priority']);
                self::assertSame($row['position'], $descriptor['position']);
                self::assertSame('callable', $descriptor['kind']);
                self::assertSame($row['class'], $descriptor['class']);
                self::assertSame('{closure}', $descriptor['method']);
                self::assertSame($row['sha256'], $descriptor['source_sha256']);
                self::assertSame($row['sha256'], $descriptor['class_sha256']);
                if ($row['bound_class'] !== $row['class']) {
                    self::assertSame($row['bound_class'], $descriptor['capture_shape']['bound_class']);
                    self::assertSame('9572f4e15e429444333bc3fa172315617abe22e1269eb9360c6f4064d831ae7d', $descriptor['bound_class_sha256']);
                }
                self::assertSame($row['start'], $descriptor['method_start']);
                self::assertSame($row['end'], $descriptor['method_end']);
                self::assertSame(['bound_class' => $row['bound_class']], $descriptor['capture_shape']);
            }
        }
        self::assertSame('ed2bf0c144e7918137b8eaa91010fe6e2acc26a18dfca29a20d6a838144c1c83', $sourcePins['custom_apps/mcp/lib/Access/Visibility/VisibilityGuard.php']);
        self::assertSame('13dc244c6876e9622f8f59a5703c159818db5b28fa01727bc68907e29c47a278', $sourcePins['custom_apps/mcp/lib/Access/ResourceScope/FilesScope.php']);
    }

    public function testPinnedClosureCanHaveDistinctScopeAndBoundClasses(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeProfile.php';
        $callback = new ReflectionMethod(NativeHomeProfile::class, 'callback');
        $callback->setAccessible(true);
        $listener = NativeHomeEventCallbackScopeFixture::make();
        $file = (new ReflectionClass(NativeHomeEventCallbackScopeFixture::class))->getFileName();
        $reflection = new ReflectionFunction($listener);
        $expected = [
            'class' => NativeHomeEventCallbackScopeFixture::class,
            'method' => '{closure}',
            'kind' => 'closure',
            'class_sha256' => hash_file('sha256', $file),
            'source_sha256' => hash_file('sha256', $file),
            'bound_class_sha256' => hash_file('sha256', (new ReflectionClass(NativeHomeEventCallbackBoundFixture::class))->getFileName()),
            'method_start' => $reflection->getStartLine(),
            'method_end' => $reflection->getEndLine(),
            'capture_shape' => ['bound_class' => NativeHomeEventCallbackBoundFixture::class],
        ];

        $callback->invoke(null, $listener, $expected);
        $expected['capture_shape']['bound_class'] = NativeHomeEventCallbackScopeFixture::class;
        $this->expectException(RuntimeException::class);
        $callback->invoke(null, $listener, $expected);
    }

    public function testEventSlotsRejectUnknownExtraAndReorderedListeners(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeProfile.php';
        $matches = new ReflectionMethod(NativeHomeProfile::class, 'eventSlotMatches');
        $slot = ['priority' => PHP_INT_MIN, 'position' => 1];

        self::assertTrue($matches->invoke(null, $slot, PHP_INT_MIN, 1));
        self::assertFalse($matches->invoke(null, null, PHP_INT_MIN, 2));
        self::assertFalse($matches->invoke(null, $slot, PHP_INT_MIN, 2));
        self::assertFalse($matches->invoke(null, $slot, 0, 1));
    }

    public function testPinnedClosureRejectsUnexpectedCaptures(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeProfile.php';
        $callback = new ReflectionMethod(NativeHomeProfile::class, 'callback');
        $callback->setAccessible(true);
        $listener = NativeHomeEventCallbackScopeFixture::makeWithCapture();
        $file = (new ReflectionClass(NativeHomeEventCallbackScopeFixture::class))->getFileName();
        $reflection = new ReflectionFunction($listener);
        $expected = [
            'class' => NativeHomeEventCallbackScopeFixture::class,
            'method' => '{closure}',
            'kind' => 'callable',
            'class_sha256' => hash_file('sha256', $file),
            'source_sha256' => hash_file('sha256', $file),
            'bound_class_sha256' => hash_file('sha256', (new ReflectionClass(NativeHomeEventCallbackBoundFixture::class))->getFileName()),
            'method_start' => $reflection->getStartLine(),
            'method_end' => $reflection->getEndLine(),
            'capture_shape' => ['bound_class' => NativeHomeEventCallbackBoundFixture::class],
        ];

        $this->expectException(RuntimeException::class);
        $callback->invoke(null, $listener, $expected);
    }
}
