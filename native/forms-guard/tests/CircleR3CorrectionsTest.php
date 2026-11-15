<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\{LocalEventState, EventModes, RemoteJoin};
foreach (['LocalEventState', 'EventModes', 'RemoteJoin'] as $name) {
    $file = dirname(__DIR__) . '/src/Service/CircleDetached/' . $name . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}
final class CircleR3CorrectionsTest extends TestCase
{
    private function key(): string
    {
        return hash('sha256', $this->name());
    }
    public function testDuplicateIntentCannotRepeatVerificationAcrossStateObjects(): void
    {
        $key = $this->key();
        $calls = 0;
        $a = new LocalEventState($key);
        $b = new LocalEventState($key);
        $a->admit($key, ['native-event', $key], static function () use (&$calls): void {
            $calls++;
        });
        try {
            $b->admit($key, ['native-event', $key], static function () use (&$calls): void {
                $calls++;
            });
            self::fail('Duplicate verification accepted');
        } catch (LogicException) {
        }
        self::assertSame(1, $calls);
    }
    public function testVerificationExceptionPoisonsRetryAndManage(): void
    {
        $key = $this->key();
        $calls = 0;
        $state = new LocalEventState($key);
        $circleDenied3 = false;
        try {
            $state->admit($key, ['native-event', $key], static function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('fixture interruption');
            });
        } catch (RuntimeException $circleCaught3) {
            if ($circleCaught3 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught3;
            }
            $circleDenied3 = true;
            self::assertSame('fixture interruption', $circleCaught3->getMessage());
        }
        self::assertTrue($circleDenied3, 'Exception swallowed');
        try {
            $state->admit($key, ['native-event', $key], static function () use (&$calls): void {
                $calls++;
            });
            self::fail('Retry accepted');
        } catch (LogicException) {
        }
        try {
            $state->claimManage();
            self::fail('Manage admitted after verify failure');
        } catch (LogicException) {
        }
        self::assertSame(1, $calls);
    }
    public function testCaughtNestedAdmissionStillPoisonsOuterAdmission(): void
    {
        $key = $this->key();
        $state = new LocalEventState($key);
        $this->expectException(LogicException::class);
        $state->admit($key, ['native-event', $key], static function () use ($state, $key): void {
            try {
                $state->admit($key, ['native-event', $key], static function (): void {
                });
            } catch (LogicException) {
            }
        });
    }
    public function testManageCannotPrecedeVerification(): void
    {
        $state = new LocalEventState($this->key());
        $this->expectException(LogicException::class);
        $state->claimManage();
    }
    public function testCompletedIntentCannotVerifyManageOrResultAgain(): void
    {
        $key = $this->key();
        $state = new LocalEventState($key);
        $state->admit($key, ['native-event', $key], static function (): void {
        });
        $state->claimManage();
        $state->done();
        self::assertSame(['local.invalid' => []], $state->claimResult('local.invalid', []));
        $state->over();
        foreach ([fn() => $state->admit($key, ['native-event', $key], static function (): void {
        }), fn() => $state->claimManage(), fn() => $state->claimResult('local.invalid', [])] as $call) {
            try {
                $call();
                self::fail('Completed intent replayed');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
    public function testOrdinaryNativeFlagsAreAcceptedWithoutMutation(): void
    {
        $incoming = [false, false, false, false];
        EventModes::incoming(...$incoming);
        self::assertSame([false, false, false, false], $incoming);
        $configured = [false, true, false, false];
        EventModes::configured(...$configured);
        self::assertSame([false, true, false, false], $configured);
    }
    public function testExplicitForceSyncIsNotAnOrdinaryNativeEvent(): void
    {
        $this->expectException(RuntimeException::class);
        EventModes::incoming(true, false, false, false);
    }
    public function testMissingAsyncFlagRefusesAfterNativeConfiguration(): void
    {
        $this->expectException(RuntimeException::class);
        EventModes::configured(false, false, false, false);
    }
    public function testAbsentUnselectedAndAllNullRemoteJoinsAreAccepted(): void
    {
        RemoteJoin::assertAbsent(['member_id' => 'local'], 'm_r_');
        $row = [];
        foreach (['id', 'type', 'interface', 'uid', 'instance', 'href', 'item', 'creation'] as $key) {
            $row['m_r_' . $key] = null;
        }
        RemoteJoin::assertAbsent($row, 'm_r_');
        self::assertCount(8, $row);
    }
    public function testPresentRemoteDataRefusesBeforeAllocation(): void
    {
        $this->expectException(RuntimeException::class);
        RemoteJoin::assertAbsent(['m_r_id' => 1], 'm_r_');
    }
    public function testPartialNullRemoteJoinIsMalformed(): void
    {
        $this->expectException(RuntimeException::class);
        RemoteJoin::assertAbsent(['m_r_id' => null], 'm_r_');
    }
    public function testDifferentIntentsForSameEventBindingRemainIndependent(): void
    {
        $first = hash('sha256', $this->name() . 'first');
        $second = hash('sha256', $this->name() . 'second');
        $calls = 0;
        foreach ([$first, $second] as $key) {
            $state = new LocalEventState($key);
            $state->admit($key, ['same-form', 'same-event-binding'], static function () use (&$calls): void {
                $calls++;
            });
        }
        self::assertSame(2, $calls);
    }
    public function testSameIntentCannotRetargetToAnotherEventBinding(): void
    {
        $key = $this->key();
        $state = new LocalEventState($key);
        $calls = 0;
        $state->admit($key, ['first-event'], static function () use (&$calls): void {
            $calls++;
        });
        try {
            (new LocalEventState($key))->admit($key, ['different-event'], static function () use (&$calls): void {
                $calls++;
            });
            self::fail('Intent retargeting admitted');
        } catch (LogicException) {
        }
        self::assertSame(1, $calls);
    }
    public function testPoisonedManageAndResultNeverReplay(): void
    {
        $key = $this->key();
        $state = new LocalEventState($key);
        $state->admit($key, ['event'], static function (): void {
        });
        $state->claimManage();
        $state->poison();
        foreach ([fn() => $state->claimManage(), fn() => $state->done(), fn() => $state->claimResult('local.invalid', [])] as $call) {
            try {
                $call();
                self::fail('Poisoned phase progressed');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
}
