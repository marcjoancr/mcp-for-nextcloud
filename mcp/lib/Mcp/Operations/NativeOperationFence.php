<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
/** DB-only implementation, wrapped by the optional native dependency's typed MutationFence adapter. */
final class NativeOperationFence {
    public function __construct(private OperationStore $store,private array $lease,private array $request,private \Closure $authorize){}
    public function beforeWire(array $binding):bool {
        return $binding===$this->request&&($this->authorize)()&&$this->step('prepared',['phase'=>'wire_may_start']);
    }
    public function recordRemote(array $proof):bool {
        $expected=array_intersect_key($this->request,array_flip(['message_id','mailbox_id','generation','uid','flag','value']));
        if(count($proof)!==7||!in_array($proof['outcome']??null,['verified','already_desired'],true))return false;
        foreach($expected as $key=>$value)if(($proof[$key]??null)!==$value)return false;
        if(count($expected)!==6)return false;
        return $this->step('wire_may_start',['phase'=>'remote_verified','remote'=>$proof]);
    }
    public function beforeCache(array $binding):bool {
        return $binding===$this->request&&($this->authorize)()&&$this->step('remote_verified',['phase'=>'cache_pending']);
    }
    public function recordCache(array $result):bool {
        return count($result)===2&&($result['state']??null)==='updated'&&($result['message_id']??null)===($this->request['message_id']??null)
            &&$this->step('cache_pending',['phase'=>'completed','cache'=>$result]);
    }
    private function step(string $phase,array $refs):bool {
        $row=$this->store->get($this->lease['scope'],$this->lease['id']);
        if(($row['resource_refs']['mutation']['descriptor']??null)!==$this->request)return false;
        return $this->store->checkpointNative($this->lease['scope'],$this->lease['id'],$this->lease['token'],$phase,$refs);
    }
}
