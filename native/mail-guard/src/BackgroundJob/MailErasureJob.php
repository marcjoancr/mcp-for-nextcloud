<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\BackgroundJob;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
final class MailErasureJob extends TimedJob {
    public function __construct(ITimeFactory $time,private \OCA\Mail\Lifecycle\MailErasureService $erasure){parent::__construct($time);$this->setInterval(60);}
    protected function run($argument):void {$this->erasure->retry();}
}
