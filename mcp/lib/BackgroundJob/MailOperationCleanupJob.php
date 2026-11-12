<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\BackgroundJob;
use OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
class MailOperationCleanupJob extends TimedJob {
    public function __construct(ITimeFactory $clock, private MailOperationCleanup $cleanup) {
        parent::__construct($clock);
        $this->setInterval(3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }
    protected function run($argument): void { $this->cleanup->run(); }
}
