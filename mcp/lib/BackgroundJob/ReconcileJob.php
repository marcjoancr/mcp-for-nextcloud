<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\BackgroundJob;

use OCA\Mcp\Service\Policy\CauseProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * The reconciler of contracts §3.3.4, every 15 minutes: the deferred facts of this process, then the pending recheck
 * causes of a bounded batch of users, each consumed by (cause, gen) through the writer. Registered by the app in
 * appinfo/info.xml and run by the server's own background job runner, so nothing extra is needed. Idempotent: a cause
 * already consumed is not found again. The app stays correct when it does not run, because the preflight of every
 * request of a user and every issuance process that user's causes first.
 */
class ReconcileJob extends TimedJob {
    public const INTERVAL = 900;

    public function __construct(ITimeFactory $time, private CauseProcessor $causes) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    /** @param mixed $argument unused */
    protected function run($argument): void {
        $this->causes->reconcile();
    }
}
