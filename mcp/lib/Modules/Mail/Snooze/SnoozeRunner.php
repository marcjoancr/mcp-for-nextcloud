<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Snooze;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
class SnoozeRunner {
    public function __construct(private SnoozeTicket $tickets,private NativeMutationProcess $process,private \Closure $command){}
    public function run(array $row,string $action='dispatch',?string $revision=null,?string $operation=null,int $budgetMs=20000):array {$entry=($this->command)();if($entry===null)return ['status'=>'refused'];return $this->process->run($entry['command'],$entry['cwd'],$this->tickets->create($row,$action,$revision,$operation),min(20000,$budgetMs));}
}
