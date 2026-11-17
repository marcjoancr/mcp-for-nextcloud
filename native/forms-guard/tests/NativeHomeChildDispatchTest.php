<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use OCA\Forms\Mcp\Service\NativeHomeChild;
use PHPUnit\Framework\TestCase;

final class NativeHomeGuardFactoryFixture {
    public array $registered = [];
    public function addStorageWrapper(string $name, callable $wrapper, int $priority = 50, array $existingMounts = []): bool {
        $this->registered = [$name, $wrapper, $priority, $existingMounts];
        return true;
    }
}

final class NativeHomeChildDispatchTest extends TestCase {
    protected function setUp(): void {
        require_once __DIR__ . '/../src/Service/NativeHomeChild.php';
        require_once __DIR__ . '/../src/Service/NativeHomeProfile.php';
        require_once __DIR__ . '/../src/Service/NativeHomeJournal.php';
        require_once __DIR__ . '/../src/Service/NativeHomeProcess.php';
        (new ReflectionProperty(\OCA\Forms\Mcp\Service\NativeHomeProfile::class, 'owned'))->setValue(null, null);
    }

    protected function tearDown(): void {
        $property = new ReflectionProperty(NativeHomeChild::class, 'activeStep');
        $property->setValue(null, null);
    }

    public function testOnlyTheBoundBulkStepCanConsumeTheOneShotChildDispatch(): void {
        $envelope = ['uid'=>'actor','job_id'=>str_repeat('a',64),'home_phase'=>[
            'version'=>7,'step_key'=>str_repeat('b',24),'nonce'=>str_repeat('c',64),
        ]];
        NativeHomeChild::armBulkStep($envelope, $envelope['home_phase']['step_key']);

        self::assertTrue(NativeHomeChild::claimBulkStep('actor', str_repeat('a',64), 7, str_repeat('b',24)));
        try {
            NativeHomeChild::claimBulkStep('actor', str_repeat('a',64), 7, str_repeat('b',24));
            self::fail('A child dispatch was consumed more than once');
        } catch (RuntimeException $error) {
            self::assertSame('Unexpected nested Native Home bulk step', $error->getMessage());
        }
    }

    public function testNestedOrMismatchedBulkStepCannotInheritTheChildBypass(): void {
        $envelope = ['uid'=>'actor','job_id'=>str_repeat('a',64),'home_phase'=>[
            'version'=>7,'step_key'=>str_repeat('b',24),'nonce'=>str_repeat('c',64),
        ]];
        NativeHomeChild::armBulkStep($envelope, $envelope['home_phase']['step_key']);

        try {
            NativeHomeChild::claimBulkStep('other', str_repeat('d',64), 8, str_repeat('e',24));
            self::fail('A nested bulk step inherited the child bypass');
        } catch (RuntimeException $error) {
            self::assertSame('Unexpected nested Native Home bulk step', $error->getMessage());
        }
    }

    public function testChildReturnsInitializationReceiptWithoutDispatchingThePublicBulkStep(): void {
        $childSource = file_get_contents(__DIR__ . '/../src/Service/NativeHomeChild.php');
        $guardSource = file_get_contents(__DIR__ . '/../src/Guard.php');
        $operationsSource = file_get_contents(__DIR__ . '/../src/Service/BulkOperations.php');
        self::assertIsString($childSource);
        self::assertIsString($guardSource);
        self::assertIsString($operationsSource);

        $runStart = strpos($childSource, 'public static function run(');
        $initializeStart = strpos($childSource, 'private static function initialize(');
        $installStart = strpos($childSource, 'public static function installStorageGuard(');
        self::assertIsInt($runStart);
        self::assertIsInt($initializeStart);
        self::assertIsInt($installStart);

        $run = substr($childSource, $runStart, $initializeStart - $runStart);
        $initialize = substr($childSource, $initializeStart, $installStart - $initializeStart);
        $guardStart = strpos($guardSource, 'public function bulkStep(');
        $guardEnd = strpos($guardSource, 'public function bulkStatus(', $guardStart);
        self::assertIsInt($guardStart);
        self::assertIsInt($guardEnd);
        $guardStep = substr($guardSource, $guardStart, $guardEnd - $guardStart);
        $homeStepStart = strpos($operationsSource, 'private function nativeHomeStep(');
        $fileStepStart = strpos($operationsSource, 'private function fileStep(');
        self::assertIsInt($homeStepStart);
        self::assertIsInt($fileStepStart);
        $homeStep = substr($operationsSource, $homeStepStart, $fileStepStart - $homeStepStart);

        self::assertSame(1, substr_count($run, 'OriginContext::withBulkControl('));
        self::assertStringContainsString('return $prepared;', $run);
        self::assertStringNotContainsString('bulkStep(', $initialize);
        self::assertStringNotContainsString('armBulkStep(', $initialize);
        self::assertStringNotContainsString('->bulkStep(', $childSource);
        self::assertStringContainsString("'initialized'=>true", $initialize);
        self::assertStringContainsString('$this->store->putMapping($current,\'native_home\',$version,$journal)', $homeStep);
        self::assertStringContainsString('unset($current[\'native_home_claim\'],$current[\'native_home_journal\'])', $homeStep);
        self::assertStringContainsString('$current[\'version\']++', $homeStep);
        self::assertStringContainsString('$this->store->record($id,$key,$dto)', $homeStep);
        self::assertStringContainsString('$current[\'input\']??null)!==($job[\'input\']??null)', $homeStep);
        self::assertStringContainsString('$currentTarget!==$target', $homeStep);
        self::assertStringContainsString('OriginContext::assertFresh()', $homeStep);
        self::assertStringNotContainsString('fileStep(', $homeStep);
        self::assertSame(1, substr_count($guardStep, 'OriginContext::withBulkControl('));
    }

    public function testEntrypointInstallsTheOwnedGuardAtInnermostPriorityBeforeMounts(): void {
        $factory = new NativeHomeGuardFactoryFixture();
        $journal = (new ReflectionClass(\OCA\Forms\Mcp\Service\NativeHomeJournal::class))->newInstanceWithoutConstructor();
        $wrapper = \OCA\Forms\Mcp\Service\NativeHomeProcess::wrapperClosure('recipient', $journal, static function (): void {});

        NativeHomeChild::installStorageGuard($factory, $wrapper);

        self::assertSame('forms_native_home_guard_v1', $factory->registered[0]);
        self::assertSame($wrapper, $factory->registered[1]);
        self::assertSame(PHP_INT_MIN, $factory->registered[2]);
        self::assertSame([], $factory->registered[3]);
    }
}
