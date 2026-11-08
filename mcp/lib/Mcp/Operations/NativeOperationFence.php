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
    public function __construct(private OperationStore $store,private array $lease,private array $request,private \Closure $authorize,private ?\Closure $remoteValidator=null,private ?\Closure $cacheValidator=null){}
    public function beforePreconditions(array $binding):bool {
        return ($this->request['requires_trash_hook']??false)===true&&$binding===$this->request&&($this->authorize)()&&$this->step('prepared',['phase'=>'preconditions_may_start']);
    }
    public function beforeWire(array $binding):bool {
        return $binding===$this->request&&($this->authorize)()&&$this->step(($this->request['requires_trash_hook']??false)?'preconditions_may_start':'prepared',['phase'=>'wire_may_start']);
    }
    public function recordRemote(array $proof):bool {
        if($this->remoteValidator!==null){
            return ($this->remoteValidator)($this->request,$proof)&&$this->step('wire_may_start',['phase'=>'remote_verified','remote'=>$proof]);
        }
        if(isset($this->request['action'])){
            $keys=['message_id','mailbox_id','generation','uid','destination_mailbox_id','destination_generation','action'];
            if(count($proof)!==9||($proof['outcome']??null)!=='moved'||!is_string($proof['destination_uid']??null)||preg_match('/^[1-9][0-9]{0,9}$/D',$proof['destination_uid'])!==1||(float)$proof['destination_uid']>4294967295)return false;
            foreach($keys as $key)if(($proof[$key]??null)!==($this->request[$key]??null))return false;
            return $this->step('wire_may_start',['phase'=>'remote_verified','remote'=>$proof]);
        }
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
        if($this->cacheValidator!==null){
            return ($this->cacheValidator)($this->request,$result)&&$this->step('cache_pending',['phase'=>'completed','cache'=>$result]);
        }
        if(isset($this->request['action'])){
            $row=$this->store->get($this->lease['scope'],$this->lease['id']);$remote=$row['resource_refs']['mutation']['remote']??[];
            return count($result)===5&&($result['state']??null)==='source_removed'&&($result['message_id']??null)===$this->request['message_id']
                &&($result['destination_mailbox_id']??null)===$this->request['destination_mailbox_id']&&isset($remote['destination_uid'])&&($result['destination_uid']??null)===$remote['destination_uid']
                &&($result['destination_state']??null)==='native_sync_pending'&&$this->step('cache_pending',['phase'=>'completed','cache'=>$result]);
        }
        return count($result)===2&&($result['state']??null)==='updated'&&($result['message_id']??null)===($this->request['message_id']??null)
            &&$this->step('cache_pending',['phase'=>'completed','cache'=>$result]);
    }
    private function step(string $phase,array $refs):bool {
        $row=$this->store->get($this->lease['scope'],$this->lease['id']);
        if(($row['resource_refs']['mutation']['descriptor']??null)!==$this->request)return false;
        return $this->store->checkpointNative($this->lease['scope'],$this->lease['id'],$this->lease['token'],$phase,$refs);
    }
}
