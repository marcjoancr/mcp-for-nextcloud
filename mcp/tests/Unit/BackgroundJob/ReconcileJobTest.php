<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\BackgroundJob;

use OCA\Mcp\BackgroundJob\ReconcileJob;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/** Coordinator clarification 3: the reconciler is a TimedJob of 15 minutes, registered by the app itself. */
final class ReconcileJobTest extends TestCase {
    public function testTheAppRegistersAFifteenMinuteReconcilerThatRunsTheCauseProcessor(): void {
        $jobs = array_map('strval', iterator_to_array(simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml')->{'background-jobs'}->job, false));
        $this->assertContains(ReconcileJob::class, $jobs);
        $this->assertSame(900, ReconcileJob::INTERVAL);
        $causes = $this->createMock(CauseProcessor::class);
        $causes->expects($this->once())->method('reconcile');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_760_000_000);
        $job = new ReconcileJob($time, $causes);
        $this->assertSame(900, $job->getInterval());
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }
}
