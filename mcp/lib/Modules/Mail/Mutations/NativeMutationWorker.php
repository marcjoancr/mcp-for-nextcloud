<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailServices};
use OCA\Mcp\Mcp\Operations\{OperationStore,OperationJournal,NativeOperationFence};
use OCP\AppFramework\Utility\ITimeFactory;
/** Only called by the signed native CLI. The stored lease/effect remains authoritative even if output is lost. */
class NativeMutationWorker {
    public function __construct(private MutationTicket $tickets,private MailMutationTarget $targets,private MailServices $native,private OperationStore $store,
        private OperationJournal $journal,private ITimeFactory $clock,private \Closure $authorize){}
    public function run(array $ticket):array {
        try {
            if(!$this->tickets->verify($ticket)||!($this->authorize)($ticket['uid'],$ticket['connection_id'],MutationRequest::tool($ticket['request'])))return ['status'=>'refused'];
            $uid=$ticket['uid'];$lease=$ticket['lease'];$request=$ticket['request'];
            if(!hash_equals($this->journal->scopeKey($uid),$lease['scope']))return ['status'=>'refused'];
            $row=$this->store->get($lease['scope'],$lease['id']);
            if($row===null||$row['actor_uid']!==$uid||$row['tool_name']!==MutationRequest::tool($request)||$row['state']!=='running'||($row['resource_refs']['mutation']['descriptor']??null)!==$request
                ||($row['resource_refs']['mutation']['phase']??null)!=='prepared'||!hash_equals($row['resource_refs']['mutation']['token_hash']??'',hash('sha256',$lease['token'])))return ['status'=>'refused'];
            $live=function()use($ticket,$uid,$request):bool{return $this->tickets->verify($ticket)&&($this->authorize)($uid,$ticket['connection_id'],MutationRequest::tool($request))&&$this->targets->snapshot($uid,$request)['request']===$request;};
            if(!$live())return ['status'=>'refused'];
            $folder=($request['kind']??null)==='mailbox';$tag=($request['kind']??null)==='tag';
            $fence=new NativeOperationFence($this->store,$lease,$request,$live,$folder?MailboxMutationRequest::remote(...):($tag?TagMutationRequest::remote(...):null),$folder?MailboxMutationRequest::cache(...):($tag?TagMutationRequest::cache(...):null));
            $service=$this->native->guardedMutations($uid);if(($request['kind']??null)==='mailbox')$this->native->guardedMailboxes($uid)->mutate($uid,$request,$service->fence($fence));elseif($tag)$service->setTag($uid,$request,$service->fence($fence));elseif(isset($request['action']))$service->move($uid,$request,$service->fence($fence));else $service->setFlag($uid,$request,$service->fence($fence));
            return ['status'=>$this->store->finishNative($lease['scope'],$lease['id'],$lease['token'],'completed','succeeded',[],$this->clock->getTime())?'completed':'unknown'];
        }catch(\Throwable){return ['status'=>'unknown'];}
    }
}
