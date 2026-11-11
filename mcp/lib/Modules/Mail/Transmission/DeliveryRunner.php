<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
/** Private stdin carries identities and approval only, never MIME or credentials. */
class DeliveryRunner {
    public function __construct(private DeliveryTicket $tickets,private NativeMutationProcess $process,private \Closure $command){}
    public function run(array $row,string $action='dispatch',?string $repairRevision=null,int $budgetMs=20000):array {
        $entry=($this->command)();if($entry===null)return ['status'=>'refused'];
        return $this->process->run($entry['command'],$entry['cwd'],$this->tickets->create($row,$action,$repairRevision),$budgetMs);
    }
}
