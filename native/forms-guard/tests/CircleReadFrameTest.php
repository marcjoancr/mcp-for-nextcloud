<?php

/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\ReadFrame;
final class CircleReadFrameTest extends TestCase
{
    protected function setUp(): void
    {
        $p = dirname(__DIR__) . '/src/Service/CircleDetached/ReadFrame.php';
        self::assertFileExists($p);
        require_once $p;
    }
    public function testGuardRefreshesEveryUseAndCannotEscapeSuccessfulRead(): void
    {
        $calls = 0;
        $leaked = null;
        $value = ReadFrame::within(static function () use (&$calls): void {
            $calls++;
        }, static function (Closure $guard) use (&$leaked): string {
            $leaked = $guard;
            $guard();
            return 'scalar';
        });
        self::assertSame('scalar', $value);
        self::assertSame(3, $calls);
        $this->expectException(RuntimeException::class);
        $leaked();
    }
    public function testExceptionalWorkClosesBeforeRethrow(): void
    {
        $leaked = null;
        try {
            ReadFrame::within(static function (): void {
            }, static function (Closure $guard) use (&$leaked): never {
                $leaked = $guard;
                throw new LogicException('fixture');
            });
            self::fail('Work exception swallowed');
        } catch (LogicException) {
        }
        $this->expectException(RuntimeException::class);
        $leaked();
    }
    public function testFailedAuthorityNeverCallsWork(): void
    {
        $called = false;
        $circleDenied17 = false;
        try {
            ReadFrame::within(static function (): never {
                throw new RuntimeException('revoked');
            }, static function () use (&$called): void {
                $called = true;
            });
        } catch (RuntimeException $circleCaught17) {
            if ($circleCaught17 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught17;
            }
            $circleDenied17 = true;
            self::assertSame('revoked', $circleCaught17->getMessage());
            self::assertFalse($called);
        }
        self::assertTrue($circleDenied17, 'Revocation swallowed');
    }
    public function testNestedExceptionDoesNotCloseOuterGuardOrReviveInner(): void
    {
        $inner = null;
        $outerCalls = 0;
        ReadFrame::within(static function () use (&$outerCalls): void {
            $outerCalls++;
        }, static function (Closure $outer) use (&$inner): void {
            try {
                ReadFrame::within(static function (): void {
                }, static function (Closure $guard) use (&$inner): never {
                    $inner = $guard;
                    throw new LogicException('inner');
                });
            } catch (LogicException) {
            }
            $outer();
            $circleDenied18 = false;
            try {
                $inner();
            } catch (RuntimeException $circleCaught18) {
                if ($circleCaught18 instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $circleCaught18;
                }
                $circleDenied18 = true;
            self::assertSame('Circle read frame ended', $circleCaught18->getMessage());
                self::assertTrue(true);
            }
            self::assertTrue($circleDenied18, 'Inner escaped');
        });
        self::assertSame(3, $outerCalls);
    }
    public function testAuthorityRevokedDuringWorkCannotReturnAResult(): void
    {
        $revoked = false;
        $this->expectException(RuntimeException::class);
        ReadFrame::within(static function () use (&$revoked): void {
            if ($revoked) {
                throw new RuntimeException('changed');
            }
        }, static function () use (&$revoked): array {
            $revoked = true;
            return ['data'];
        });
    }
    /** Execute exact source-derived binding methods against scalar-only test state, never native classes/services. */
    private function bindingHarness(string $file, array $methods): string
    {
        $base = getenv('CIRCLE_BINDING_REFERENCE') ?: dirname(__DIR__) . '/src/Service';
        $tree = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($base . '/' . $file));
        $found = [];
        foreach ((new \PhpParser\NodeFinder())->findInstanceOf($tree, \PhpParser\Node\Stmt\ClassMethod::class) as $m) {
            if (in_array($m->name->toString(), $methods, true)) {
                $found[$m->name->toString()] = $m;
            }
        }
        foreach ($methods as $name) {
            self::assertArrayHasKey($name, $found);
        }
        static $serial = 0;
        $class = 'PureCircleBindingFixture' . ++$serial;
        eval('class ' . $class . ' { public static $current=null; public static $businessFrame=null; public static $circleReadFrame=null; public static $fresh; public static function current():array{return self::$current;} public static function assertFresh():void{(self::$fresh)();}' . (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(array_values($found)) . '}');
        $class::$fresh = static function (): void {
        };
        return $class;
    }
    public function testOriginBindingRejectsAnotherFrameOrOriginAndRestoresOuter(): void
    {
        $class = $this->bindingHarness('OriginContext.php', ['circleReadAuthority']);
        $frame = new stdClass();
        $original = ['verified' => ['uid' => 'owner'], 'origin' => ['connection' => 0]];
        $class::$businessFrame = $frame;
        $class::$current = $original;
        $guard = $class::circleReadAuthority('owner');
        $guard();
        $class::$businessFrame = new stdClass();
        $circleDenied19 = false;
        try {
            $guard();
        } catch (RuntimeException $circleCaught19) {
            if ($circleCaught19 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught19;
            }
            $circleDenied19 = true;
            self::assertSame('Circle original actor frame changed', $circleCaught19->getMessage());
            self::assertTrue(true);
        }
        self::assertTrue($circleDenied19, 'Different frame accepted');
        $class::$businessFrame = $frame;
        $class::$current['origin']['connection'] = 1;
        $circleDenied20 = false;
        try {
            $guard();
        } catch (RuntimeException $circleCaught20) {
            if ($circleCaught20 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught20;
            }
            $circleDenied20 = true;
            self::assertSame('Circle original actor frame changed', $circleCaught20->getMessage());
            self::assertTrue(true);
        }
        self::assertTrue($circleDenied20, 'Different original connection accepted');
        $class::$current = $original;
        $guard();
        self::assertTrue(true);
        $class::$businessFrame = null;
        $this->expectException(RuntimeException::class);
        $guard();
    }
    public function testOriginBindingRequiresMatchingActorAndFreshRevocation(): void
    {
        $class = $this->bindingHarness('OriginContext.php', ['circleReadAuthority']);
        $class::$businessFrame = new stdClass();
        $class::$current = ['verified' => ['uid' => 'owner'], 'origin' => []];
        $circleDenied21 = false;
        try {
            $class::circleReadAuthority('other');
        } catch (RuntimeException $circleCaught21) {
            if ($circleCaught21 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught21;
            }
            $circleDenied21 = true;
            self::assertSame('Circle original actor frame is unavailable', $circleCaught21->getMessage());
            self::assertTrue(true);
        }
        self::assertTrue($circleDenied21, 'Foreign actor captured');
        $guard = $class::circleReadAuthority('owner');
        $class::$fresh = static function (): never {
            throw new RuntimeException('revoked');
        };
        $this->expectException(RuntimeException::class);
        $guard();
    }
    public function testProducerBindingRejectsNestedTokenAndClosedFrame(): void
    {
        $class = $this->bindingHarness('ManagedSharePhase.php', ['circleReadAuthority']);
        $calls = 0;
        $outer = ['token' => new stdClass(), 'uid' => 'owner', 'assert' => static function () use (&$calls): void {
            $calls++;
        }];
        $class::$circleReadFrame = $outer;
        $guard = $class::circleReadAuthority('owner');
        $guard();
        $class::$circleReadFrame = ['token' => new stdClass(), 'uid' => 'owner', 'assert' => static function (): void {
        }];
        $circleDenied22 = false;
        try {
            $guard();
        } catch (RuntimeException $circleCaught22) {
            if ($circleCaught22 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught22;
            }
            $circleDenied22 = true;
            self::assertSame('Native Circle producer frame ended', $circleCaught22->getMessage());
            self::assertTrue(true);
        }
        self::assertTrue($circleDenied22, 'Nested producer accepted');
        $class::$circleReadFrame = $outer;
        $guard();
        self::assertSame(2, $calls);
        $class::$circleReadFrame = null;
        $this->expectException(RuntimeException::class);
        $guard();
    }
    public function testProducerActorLookupIsNullOutsideAndRefreshesInside(): void
    {
        $class = $this->bindingHarness('ManagedSharePhase.php', ['circleReadActor']);
        self::assertNull($class::circleReadActor());
        $calls = 0;
        $class::$circleReadFrame = ['token' => new stdClass(), 'uid' => 'owner', 'assert' => static function () use (&$calls): void {
            $calls++;
        }];
        self::assertSame('owner', $class::circleReadActor());
        self::assertSame(1, $calls);
    }
}
