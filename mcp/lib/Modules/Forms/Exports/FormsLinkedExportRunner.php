<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
/** Only the opaque association enters a fresh child. Native SQL retains its own original approval. */
final class FormsLinkedExportRunner {
    public function __construct(private NativeMutationProcess $process,private \Closure $command) {}
    public function run(string $associationId,int $budgetMs=20000):array {
        if(preg_match('/^[a-f0-9]{64}$/D',$associationId)!==1)return ['status'=>'refused'];
        try {
            $entry=($this->command)();
            if(!is_array($entry)||!is_array($entry['command']??null)||!array_is_list($entry['command'])||$entry['command']===[]
                ||!is_string($entry['cwd']??null)||!str_starts_with($entry['cwd'],'/'))return ['status'=>'refused'];
            foreach($entry['command'] as $arg)if(!is_string($arg)||$arg==='')return ['status'=>'refused'];
        } catch(\Throwable) {return ['status'=>'refused'];}
        try {return $this->process->run($entry['command'],$entry['cwd'],['association_id'=>$associationId],max(1,min(20000,$budgetMs)));}
        catch(\Throwable) {return ['status'=>'unknown'];}
    }
}
