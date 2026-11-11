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
            $live=function()use($ticket,$uid,$request):bool{return $this->tickets->verify($ticket)&&($this->authorize)($uid,$ticket['connection_id'],MutationRequest::tool($request),fn():bool=>$this->targets->snapshot($uid,$request)['request']===$request,$ticket['lease']['id']);};
            if(!$live())return ['status'=>'refused'];
            $refresh=($request['kind']??null)==='refresh';$folder=($request['kind']??null)==='mailbox';$tag=($request['kind']??null)==='tag';
            $identity=function()use($uid,$lease):bool{$this->journal->privateResources($uid,$lease['id']);return true;};
            $fence=new NativeOperationFence($this->store,$lease,$request,$live,$identity,$refresh?RefreshRequest::remote(...):($folder?MailboxMutationRequest::remote(...):($tag?TagMutationRequest::remote(...):null)),$refresh?RefreshRequest::cache(...):($folder?MailboxMutationRequest::cache(...):($tag?TagMutationRequest::cache(...):null)));
            $service=$refresh?$this->native->guardedRefresh($uid):$this->native->guardedMutations($uid);if($refresh)$service->refresh($uid,$request,$service->fence(new RefreshOperationFence($this->store,$lease,$request,$live,$fence)));else if(($request['kind']??null)==='mailbox')$this->native->guardedMailboxes($uid)->mutate($uid,$request,$service->fence($fence));elseif($tag)$service->setTag($uid,$request,$service->fence($fence));elseif(isset($request['action']))$service->move($uid,$request,$service->fence($fence));else $service->setFlag($uid,$request,$service->fence($fence));
            $identity();
            return ['status'=>$this->store->finishNative($lease['scope'],$lease['id'],$lease['token'],'completed','succeeded',[],$this->clock->getTime())?'completed':'unknown'];
        }catch(\Throwable){return ['status'=>'unknown'];}
    }
}
