<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
class NativeMutationRunner {
    public function __construct(private MutationTicket $tickets,private ConnectionContext $context,private NativeMutationProcess $process,private \Closure $command){}
    public function run(string $uid,array $lease,array $request):array {
        $entry=($this->command)();if($entry===null)return ['status'=>'refused'];
        return $this->process->run($entry['command'],$entry['cwd'],$this->tickets->create($uid,$this->context->current(),$lease,$request));
    }
}
