<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\{CircleReadData, CircleReadConfig, CircleExistingIdentity};
final class CircleReadFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['CircleReadData', 'CircleReadConfig', 'CircleExistingIdentity'] as $class) {
            $file = dirname(__DIR__) . '/src/Service/' . $class . '.php';
            self::assertFileExists($file, 'Missing inert Circle read foundation');
            require_once $file;
        }
    }
    public function testMissingAndDuplicateIdentityDoNotBecomePermissionEvidence(): void
    {
        foreach ([[], [$this->identity(), $this->identity()]] as $rows) {
            $circleDenied9 = false;
            try {
                CircleExistingIdentity::select($rows, 'synthetic-user');
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied9 = true;
            self::assertSame('Existing Circle identity is missing or ambiguous', $e->getMessage());
                self::assertStringContainsString('identity', $e->getMessage());
            }
            self::assertTrue($circleDenied9, 'Ambiguous identity accepted');
        }
    }
    public function testCanonicalIdentityReturnsOnlyBoundDataAndKeepsInputUnchanged(): void
    {
        $row = $this->identity();
        $before = $row;
        $result = CircleExistingIdentity::select([$row], 'synthetic-user');
        self::assertSame($before, $row);
        self::assertSame($row['unique_id'], $result['circle_id']);
        self::assertSame('synthetic-user', $result['uid']);
        self::assertArrayNotHasKey('permissions', $result);
    }
    public function testIdentityCannotAdoptAnotherPrincipalOrMalformedNativeRow(): void
    {
        foreach (['user_id' => 'other', 'single_id' => str_repeat('b', 31), 'source' => 2, 'level' => 8, 'instance' => 'foreign.invalid', 'status' => 'Invited'] as $key => $value) {
            $row = $this->identity();
            $row[$key] = $value;
            $circleDenied10 = false;
            try {
                CircleExistingIdentity::select([$row], 'synthetic-user');
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied10 = true;
            self::assertSame('Circle identity binding is unqualified', $e->getMessage());
                self::assertStringContainsString('identity', $e->getMessage());
            }
            self::assertTrue($circleDenied10, 'Foreign identity accepted');
        }
    }
    public function testLegacySettingsCauseWholePageRefusalBeforeAnyHydration(): void
    {
        $rows = [['unique_id' => 'safe', 'settings' => '{}'], ['nested' => ['g_settings' => '{"password_single":"legacy-value"}']]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migration');
        CircleReadData::page($rows);
    }
    public function testMalformedSettingsAndObjectsCannotDisappearFromPage(): void
    {
        foreach ([['settings' => '{'], ['settings' => 'null'], ['settings' => '{"password_single":1}'], ['nested' => new stdClass()]] as $circleCase => $bad) {
            $circleDenied11 = false;
            try {
                CircleReadData::page([['settings' => '{}'], $bad]);
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied11 = true;
            self::assertSame(['Circle settings are malformed', 'Circle settings are malformed', 'Circle settings are malformed', 'Circle raw data cannot contain an object or resource'][$circleCase], $e->getMessage());
                self::assertNotSame('', $e->getMessage());
            }
            self::assertTrue($circleDenied11, 'Invalid row omitted or accepted');
        }
    }
    public function testClosedPageIsDetachedAndDoesNotRunSerializationHooks(): void
    {
        $value = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new LogicException('Must not run');
            }
        };
        $circleDenied12 = false;
        try {
            CircleReadData::page([['value' => $value]]);
        } catch (RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $circleDenied12 = true;
            self::assertSame('Circle raw data cannot contain an object or resource', $e->getMessage());
            self::assertStringContainsString('object', $e->getMessage());
        }
        self::assertTrue($circleDenied12, 'Object accepted');
        $rows = [['settings' => '{"password_single":"' . str_repeat('a', 64) . '"}', 'nested' => ['count' => 3]]];
        self::assertSame($rows, CircleReadData::page($rows));
    }
    public function testRowsDepthAndCumulativeBytesAreBounded(): void
    {
        $deep = [];
        for ($i = 0; $i < 35; $i++) {
            $deep = ['child' => $deep];
        }
        foreach ([array_fill(0, 101, []), [['a' => str_repeat('x', 600000)], ['b' => str_repeat('x', 600000)]], [['deep' => $deep]]] as $circleCase => $rows) {
            $circleDenied13 = false;
            try {
                CircleReadData::page($rows);
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied13 = true;
            self::assertSame(['Circle row budget exceeded', 'Circle byte budget exceeded', 'Circle depth budget exceeded'][$circleCase], $e->getMessage());
                self::assertStringContainsString('budget', $e->getMessage());
            }
            self::assertTrue($circleDenied13, 'Unbounded page accepted');
        }
    }
    public function testExistingConfigNeedsNoRepairAndRetainsNativePrecedence(): void
    {
        $app = ['loopback_tmp_scheme' => 'https'];
        $system = ['overwrite.cli.url' => 'https://local.invalid', 'trusted_domains' => ['LOCAL.INVALID']];
        $before = [$app, $system];
        $result = CircleReadConfig::resolve($app, $system);
        self::assertSame('local.invalid', $result['local_instance']);
        self::assertSame(['local.invalid'], $result['trusted_domains']);
        self::assertSame($before, [$app, $system]);
        $result = CircleReadConfig::resolve(['loopback_tmp_id' => 'temporary.invalid', 'loopback_cloud_id' => 'explicit.invalid'], []);
        self::assertSame('temporary.invalid', $result['local_instance']);
    }
    public function testMissingAndRepairRequiredConfigRefuseWithoutSubstitutingDefaults(): void
    {
        $cases = [[[], []], [[], ['overwrite.cli.url' => 'https://local.invalid']], [['loopback_tmp_scheme' => 'http'], ['overwrite.cli.url' => 'https://local.invalid']], [['loopback_tmp_scheme' => 'https'], ['overwrite.cli.url' => 'https://local.invalid/subpath']], [['loopback_cloud_id' => 'local.invalid', 'local_cloud_id' => 'legacy.invalid'], []]];
        foreach ($cases as $circleCase => [$app, $system]) {
            $circleDenied14 = false;
            try {
                CircleReadConfig::resolve($app, $system);
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied14 = true;
            self::assertSame(['Circle configuration has no qualified local instance', 'Circle configuration requires native loopback repair', 'Circle configuration requires native loopback repair', 'Circle configuration requires native loopback repair', 'Circle configuration requires native frontal migration'][$circleCase], $e->getMessage());
                self::assertStringContainsString('configuration', $e->getMessage());
            }
            self::assertTrue($circleDenied14, 'Config requiring native write accepted');
        }
    }
    public function testMalformedConfigCannotEnterNativeGetters(): void
    {
        foreach ([['loopback_cloud_id' => new stdClass()], ['loopback_cloud_id' => 'https://not-a-cloud-id.invalid'], ['loopback_cloud_id' => "local.invalid\n"]] as $circleCase => $app) {
            $circleDenied15 = false;
            try {
                CircleReadConfig::resolve($app, []);
            } catch (RuntimeException $e) {
                if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $e;
                }
                $circleDenied15 = true;
            self::assertSame(['Circle configuration is malformed', 'Circle configuration instance is malformed', 'Circle configuration is malformed'][$circleCase], $e->getMessage());
                self::assertStringContainsString('configuration', $e->getMessage());
            }
            self::assertTrue($circleDenied15, 'Malformed config accepted');
        }
    }
    private function identity(): array
    {
        return ['unique_id' => str_repeat('a', 31), 'creation' => '2026-11-15 10:00:00', 'config' => 1, 'source' => 1, 'settings' => '{}', 'member_id' => str_repeat('a', 31), 'circle_id' => str_repeat('a', 31), 'single_id' => str_repeat('a', 31), 'user_id' => 'synthetic-user', 'user_type' => 1, 'level' => 9, 'instance' => '', 'status' => 'Member'];
    }
}
