<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Snooze;
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
use OCA\Mcp\Mcp\Operations\ActorRetired;
/** Ticket identity is independently bound to original durable actor/scopes at every native fence. */
class SnoozeWorker {
    public function __construct(private SnoozeTicket $tickets,private MailSnoozeGateway $gateway,private \Closure $authorize){}
    public function run(array $ticket):array {
        try{
            if(!$this->tickets->verify($ticket))return ['status'=>'refused'];$uid=$ticket['uid'];$id=$ticket['id'];$origin=$this->gateway->workerContext($uid,$id);
            foreach(['accountId','connectionId','sourceTool']as$key)if(($origin[$key]??null)!==$ticket[$key])return ['status'=>'refused'];
            $allows=function(string $owner,array $fresh)use($ticket,$origin,$uid):bool {
                if(!$this->tickets->verify($ticket)||$owner!==$uid||$fresh!==$origin)throw new \RuntimeException('Snooze ticket identity or deadline changed.');
                try{$operation=$this->gateway->originalOperation($uid,$origin);}catch(ActorRetired){return false;}
                return ($this->authorize)($uid,$origin,$operation,$ticket);
            };
            $review=new class($allows){public function __construct(private \Closure $live){}public function allows(string $uid,array $origin):bool{return ($this->live)($uid,$origin);}};
            if($ticket['action']==='audit'){$this->gateway->review($uid,$id,$review);return ['status'=>'completed'];}
            if(!$allows($uid,$origin)){$this->gateway->review($uid,$id,$review);return ['status'=>'refused'];}
            $live=function(string $owner,array $fresh)use($allows):void{if(!$allows($owner,$fresh))throw $this->gateway->denied();};
            $fence=new class($live){public function __construct(private \Closure $live){}public function authorize(string $uid,array $origin):void{($this->live)($uid,$origin);}};
            $this->gateway->dispatch($uid,$id,$ticket['revision'],$fence);return ['status'=>'completed'];
        }catch(\Throwable){return ['status'=>'unknown'];}
    }
}
