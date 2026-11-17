<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
/** Native queue ordering owns fairness. Every bounded step has its own original-actor process. */
final class FormsLinkedSweep {
    private const STEP_BUDGET_MS=FormsLinkedExportRunner::CHILD_BUDGET_MS;
    public function __construct(private FormsLinkedExportRunner $runner,private \Closure $pending,private ?\Closure $nowNs=null) {}
    private function now():int {return $this->nowNs===null?hrtime(true):($this->nowNs)();}
    public function run():void {
        if(PHP_INT_SIZE!==8)return;
        $deadline=$this->now()+55000000000;
        try {$ids=($this->pending)(3);}catch(\Throwable){return;}
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>3)return;
        $seen=[];
        foreach($ids as $id) {
            if(!is_string($id)||preg_match('/^[a-f0-9]{64}$/D',$id)!==1||isset($seen[$id]))return;
            $seen[$id]=true;
        }
        foreach($ids as $id) {
            $remaining=(int)(($deadline-$this->now())/1000000);if($remaining<self::STEP_BUDGET_MS)break;
            try {$this->runner->run($id,self::STEP_BUDGET_MS);}catch(\Throwable){/* Durable native state controls subsequent attempts. */}
        }
    }
}
