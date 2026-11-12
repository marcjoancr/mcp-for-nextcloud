<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
/** Metadata only in the parent; user and connection context exist solely in each signed child. */
class DeliverySweep {
    private const CURSOR='mail_delivery_audit_cursor';
    public function __construct(private MailDeliveryGateway $gateway,private DeliveryRunner $runner,private \OCP\IConfig $config,private ?\Closure $nowNs=null){}
    private function now():int {return $this->nowNs!==null?($this->nowNs)():hrtime(true);}
    public function run():void {
        $deadline=$this->now()+55000000000;
        $cursor=$this->config->getAppValue('mcp',self::CURSOR,'');if($cursor!==''&&preg_match('/^[a-f0-9]{64}$/D',$cursor)!==1)$cursor='';
        $audit=array_slice($this->gateway->queuedAfter($cursor,25),0,25);
        if($audit===[]&&$cursor!=='')$audit=array_slice($this->gateway->queuedAfter('',25),0,25);
        $due=array_slice($this->gateway->due(25),0,25);$work=[];
        // Review the audit page first, and advance only past entries whose child was actually invoked.
        foreach($audit as$row)$work[$row['id']]=['row'=>$row,'audit'=>true,'action'=>'audit'];
        foreach($due as$row){if(isset($work[$row['id']]))$work[$row['id']]['action']='dispatch';else $work[$row['id']]=['row'=>$row,'audit'=>false,'action'=>'dispatch'];}
        $last=null;
        try{
            foreach(array_slice($work,0,50)as$item){
                $remaining=(int)(($deadline-$this->now())/1000000);if($remaining<1000)break;
                try{$this->runner->run($item['row'],$item['action'],budgetMs:min(20000,$remaining));}catch(\Throwable){/* Unknown authorization/storage never invalidates consent here. */}
                if($item['audit'])$last=$item['row']['id'];
            }
        }finally{
            if($last!==null)$this->config->setAppValue('mcp',self::CURSOR,$last);
            elseif($audit===[]&&$cursor!=='')$this->config->setAppValue('mcp',self::CURSOR,'');
        }
    }
}
