<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\BackgroundJob;

use OCA\Mcp\BackgroundJob\ReconcileJob;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCA\Mcp\Service\Policy\GroupLifecycle;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/** Coordinator clarification 3: the reconciler is a TimedJob of 15 minutes, registered by the app itself. */
final class ReconcileJobTest extends TestCase {
    public function testTheAppRegistersAFifteenMinuteReconcilerThatRunsGroupsBeforeCauses(): void {
        $jobs = array_map('strval', iterator_to_array(simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml')->{'background-jobs'}->job, false));
        $this->assertContains(ReconcileJob::class, $jobs);
        $this->assertSame(900, ReconcileJob::INTERVAL);
        $order = [];
        $causes = $this->createMock(CauseProcessor::class);
        $causes->method('flush')->willReturnCallback(function () use (&$order): void { $order[] = 'flush'; });
        $causes->method('reconcile')->willReturnCallback(function () use (&$order): int { $order[] = 'causes'; return 0; });
        $groups = $this->createMock(GroupLifecycle::class);
        $groups->method('reconcile')->willReturnCallback(function () use (&$order): int { $order[] = 'groups'; return 0; });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_760_000_000);
        $job = new ReconcileJob($time, $causes, $groups);
        $this->assertSame(900, $job->getInterval());
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
        // Deferred facts first; then the pending deletions, so a removal cause of a deleted group is classified with
        // that group already gone; then the causes.
        $this->assertSame(['flush', 'groups', 'causes'], $order);
    }
}
