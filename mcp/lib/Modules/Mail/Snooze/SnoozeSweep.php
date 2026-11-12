<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Snooze;
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
/** Independent rotating metadata/dispatch pages; only an attempted contiguous prefix advances. */
class SnoozeSweep {
    private const AUDIT='mail_snooze_audit_cursor';
    private const DISPATCH='mail_snooze_dispatch_cursor';
    public function __construct(private MailSnoozeGateway $gateway,private SnoozeRunner $runner,private \OCP\IConfig $config,private ?\Closure $nowNs=null){}
    private function now():int {return $this->nowNs!==null?($this->nowNs)():hrtime(true);}
    private function page(string $key,bool $dispatch):array {
        $cursor=$this->config->getAppValue('mcp',$key,'');if($cursor!==''&&preg_match('/^[a-f0-9]{64}$/D',$cursor)!==1)$cursor='';
        $read=fn(string $after):array=>array_slice($dispatch?$this->gateway->due(25,$after):$this->gateway->queuedAfter($after,25),0,25);
        $rows=$read($cursor);if($rows===[]&&$cursor!=='')$rows=$read('');return $rows;
    }
    public function run():void {
        $deadline=$this->now()+55000000000;$due=$this->page(self::DISPATCH,true);$audit=$this->page(self::AUDIT,false);$dispatch=array_fill_keys(array_column($due,'id'),true);$work=[];
        // Neither page can monopolize a short sweep. Dedup preserves each page's order.
        for($i=0;$i<25;$i++)foreach([$due,$audit]as$page)if(isset($page[$i]))$work[$page[$i]['id']]??=$page[$i];
        $attempted=[];
        try{
            foreach($work as$row){
                $remaining=(int)(($deadline-$this->now())/1000000);if($remaining<1000)break;
                try{$this->runner->run($row,isset($dispatch[$row['id']])?'dispatch':'audit',budgetMs:min(20000,$remaining));}catch(\Throwable){/* Unavailable policy/storage preserves consent. */}
                $attempted[$row['id']]=true;
            }
        }finally{
            foreach([self::DISPATCH=>$due,self::AUDIT=>$audit]as$key=>$page){$last=null;foreach($page as$row){if(!isset($attempted[$row['id']]))break;$last=$row['id'];}if($last!==null)$this->config->setAppValue('mcp',$key,$last);elseif($page===[])$this->config->setAppValue('mcp',$key,'');}
        }
    }
}
