<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Connection;
use OCA\Mcp\Platform\Process\NativeProbeProcess;
use OCA\Mcp\OAuth\ConnectionContext;
class NativeConnectionProbe {
    public function __construct(private NativeProbeProcess $process,private ProbeTicket $tickets,private ConnectionContext $connection,private \Closure $command){}
    public function run(string $uid,int $account,string $protocol,string $revision,string $operation): array {
        $entry=($this->command)();if($entry===null)return ['status'=>'unsupported','category'=>'missing_native_cli'];
        return $this->process->run($entry['command'],$entry['cwd'],$this->tickets->create($uid,$account,$protocol,$this->connection->current(),$revision,$operation),10000);
    }
}
