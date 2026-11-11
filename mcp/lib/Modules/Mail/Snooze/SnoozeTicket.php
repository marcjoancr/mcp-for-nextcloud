<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Snooze;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;
final class SnoozeTicket {
    public function __construct(private PlanState $proof,private ITimeFactory $clock){}
    public function create(array $row,string $action='dispatch',?string $revision=null,?string $operation=null):array {
        $t=$row+['action'=>$action,'revision'=>$revision,'operation'=>$operation,'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>$this->clock->getTime()+25];$t['proof']=$this->sign($t);if(!$this->verify($t))throw new \InvalidArgumentException('Invalid snooze child ticket.');return $t;
    }
    public function verify(array $t):bool {
        if(count($t)!==11||array_diff(array_keys($t),['uid','id','accountId','connectionId','sourceTool','action','revision','operation','nonce','expires_at','proof'])!==[]
            ||!is_string($t['uid']??null)||$t['uid']===''||strlen($t['uid'])>64||!is_string($t['id']??null)||preg_match('/^[a-f0-9]{64}$/D',$t['id'])!==1
            ||!is_int($t['accountId']??null)||$t['accountId']<1||!is_int($t['connectionId']??null)||$t['connectionId']<1||($t['sourceTool']??null)!=='mail_snooze'
            ||!in_array($t['action']??null,['dispatch','wake','audit'],true)||!is_string($t['nonce']??null)||preg_match('/^[a-f0-9]{32}$/D',$t['nonce'])!==1
            ||!is_string($t['proof']??null)||preg_match('/^[a-f0-9]{64}$/D',$t['proof'])!==1||!is_int($t['expires_at']??null)||$t['expires_at']<$this->clock->getTime()||$t['expires_at']>$this->clock->getTime()+25)return false;
        if($t['action']==='wake'){if(!is_string($t['revision'])||preg_match('/^[a-f0-9]{64}$/D',$t['revision'])!==1||!is_string($t['operation'])||strlen($t['operation'])>128||$t['operation']==='')return false;}
        elseif($t['revision']!==null||$t['operation']!==null)return false;
        $proof=$t['proof'];unset($t['proof']);return hash_equals($this->sign($t),$proof);
    }
    private function sign(array $t):string {ksort($t);return $this->proof->of('mail_snooze_ticket',(string)($t['uid']??''),$t);}
}
