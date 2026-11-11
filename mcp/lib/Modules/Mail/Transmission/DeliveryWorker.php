<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
/** One short child. The gateway independently fails closed on native qualification and the system send gate. */
class DeliveryWorker {
    public function __construct(private DeliveryTicket $tickets,private MailDeliveryGateway $gateway,private \Closure $authorize){}
    public function run(array $ticket):array {
        try {
            if(!$this->tickets->verify($ticket))return ['status'=>'refused'];
            $origin=$this->gateway->workerContext($ticket['uid'],$ticket['id']);
            foreach(['accountId','connectionId','sourceTool']as$key)if(($origin[$key]??null)!==$ticket[$key])return ['status'=>'refused'];
            $allows=function(string $uid,int $connection,string $source,int $account)use($ticket):bool {
                if(!$this->tickets->verify($ticket)||$uid!==$ticket['uid']||$connection!==$ticket['connectionId']||$source!==$ticket['sourceTool']||$account!==$ticket['accountId'])throw new \RuntimeException('Delivery audit identity or deadline changed.');
                return ($this->authorize)($uid,$connection,$source,$account,$ticket['action']==='repair');
            };
            $review=new class($allows){public function __construct(private \Closure $live){}public function allows(string $uid,int $connection,string $source,int $account):bool{return ($this->live)($uid,$connection,$source,$account);}};
            if($ticket['action']==='audit'){
                $this->gateway->reviewAuthorization($ticket['uid'],$ticket['id'],$review);
                return ['status'=>'completed'];
            }
            $allowed=$allows($ticket['uid'],$ticket['connectionId'],$ticket['sourceTool'],$ticket['accountId']);
            if(!$allowed){
                if($ticket['action']==='dispatch')$this->gateway->reviewAuthorization($ticket['uid'],$ticket['id'],$review);
                return ['status'=>'refused'];
            }
            $live=function(string $uid,int $connection,string $source,int $account)use($ticket):void {
                if(!$this->tickets->verify($ticket)||$uid!==$ticket['uid']||$connection!==$ticket['connectionId']||$account!==$ticket['accountId']
                    ||!in_array($source,$ticket['action']==='repair'?[$ticket['sourceTool'],'mail_sent_repair']:[$ticket['sourceTool']],true)
                    )throw new \RuntimeException('Delivery child identity or deadline changed.');
                if(!($this->authorize)($uid,$connection,$ticket['sourceTool'],$account,$ticket['action']==='repair'))throw $this->gateway->workerBeforeWireBlock();
                $fresh=$this->gateway->workerContext($uid,$ticket['id']);
                foreach(['accountId','connectionId','sourceTool']as$key)if(($fresh[$key]??null)!==$ticket[$key])throw new \RuntimeException('Delivery origin changed.');
            };
            $fence=new class($live){public function __construct(private \Closure $live){} public function authorize(string $uid,int $connection,string $source,int $account):void {($this->live)($uid,$connection,$source,$account);}};
            if($ticket['action']==='repair')$this->gateway->repairSent($ticket['uid'],$ticket['id'],$ticket['repairRevision'],$fence);
            else $this->gateway->dispatch($ticket['uid'],$ticket['id'],$fence);
            return ['status'=>'completed'];
        }catch(\Throwable){return ['status'=>'unknown'];}
    }
}
