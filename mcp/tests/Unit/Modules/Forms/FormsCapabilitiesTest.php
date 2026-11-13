<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\Integration\FormsCapabilities;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class FormsCapabilitiesTest extends TestCase {
    public function testStock542AndLegacyReadCapabilitiesAreDistinct(): void {
        $stock = $this->capabilities('5.4.2')->forUser('alice');
        self::assertTrue($stock['legacyReads']);
        self::assertSame(13, count($stock['questionTypes']));
        self::assertNotContains('datetime', $stock['creatableQuestionTypes']);
        self::assertFalse($stock['guardedWrites']);
        self::assertSame('native_guard_unqualified', $stock['writeUnavailableReason']);
        $legacy = $this->capabilities('5.3.0')->forUser('alice');
        self::assertTrue($legacy['legacyReads']);
        self::assertFalse($legacy['completeMetadata']);
    }

    public function testUnknownVersionNeverEnablesWrites(): void {
        foreach (['', '5.99.0', '5.4.2.2'] as $version) {
            $result = $this->capabilities($version, static fn(): array => ['qualified' => true])->forUser('alice');
            self::assertFalse($result['guardedWrites']);
        }
    }

    public function testSessionMismatchResolvesNoNativeService(): void {
        $resolved = false;
        $caps = $this->capabilities('5.4.2', static function () use (&$resolved): array {
            $resolved = true; return [];
        }, 'bob');
        try { $caps->forUser('alice'); self::fail('Session mismatch admitted'); }
        catch (ToolFailure) { self::assertFalse($resolved); }
    }

    public function testCapabilitiesRevealNoFormOrShareData(): void {
        $result = $this->capabilities('5.4.2')->forUser('alice');
        self::assertSame(['nativeVersion','legacyReads','completeMetadata','guardedWrites','writeUnavailableReason','questionTypes','creatableQuestionTypes','limits'], array_keys($result));
        self::assertSame(100, $result['limits']['pageItems']);
        self::assertSame(1048576, $result['limits']['requestBytes']);
        self::assertStringNotContainsString('alice', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function capabilities(string $version, ?\Closure $guard = null, string $session = 'alice'): FormsCapabilities {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('forms', $user)->willReturn(true);
        $apps->method('getAppVersion')->with('forms')->willReturn($version);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        return new FormsCapabilities($apps, $users, static fn(): string => $session, $guard ?? static fn(): array => []);
    }
}
