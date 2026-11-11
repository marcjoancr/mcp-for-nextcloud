<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;

/** A ticket authorizes one bounded child invocation; persistent queue consent lives elsewhere. */
final class DeliveryTicket {
    public function __construct(private PlanState $proof,private ITimeFactory $clock){}
    public function create(array $row,string $action='dispatch',?string $repairRevision=null):array {
        if(count($row)!==5||!self::identity($row))throw new \InvalidArgumentException('Invalid native delivery identity.');
        if(!in_array($action,['dispatch','repair'],true)||($action==='repair'?(preg_match('/^[a-f0-9]{64}$/D',$repairRevision??'')!==1):$repairRevision!==null))throw new \InvalidArgumentException('Invalid delivery action.');
        $t=$row+['action'=>$action,'repairRevision'=>$repairRevision,'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>$this->clock->getTime()+25];
        return $t+['proof'=>$this->sign($t)];
    }
    public function verify(array $t):bool {
        if(count($t)!==10||array_diff(array_keys($t),['uid','id','accountId','connectionId','sourceTool','action','repairRevision','nonce','expires_at','proof'])!==[]||!self::identity($t)
            ||!in_array($t['action']??null,['dispatch','repair'],true)||(($t['action']??null)==='repair'?(preg_match('/^[a-f0-9]{64}$/D',$t['repairRevision']??'')!==1):($t['repairRevision']??null)!==null)
            ||!is_string($t['nonce']??null)||preg_match('/^[a-f0-9]{32}$/D',$t['nonce'])!==1
            ||!is_string($t['proof']??null)||preg_match('/^[a-f0-9]{64}$/D',$t['proof'])!==1
            ||!is_int($t['expires_at']??null)||$t['expires_at']<$this->clock->getTime()||$t['expires_at']>$this->clock->getTime()+25)return false;
        $signature=$t['proof'];unset($t['proof']);return hash_equals($this->sign($t),$signature);
    }
    private static function identity(array $t):bool {
        return is_string($t['uid']??null)&&$t['uid']!==''&&strlen($t['uid'])<=64
            &&is_string($t['id']??null)&&preg_match('/^[a-f0-9]{64}$/D',$t['id'])===1
            &&is_int($t['accountId']??null)&&$t['accountId']>0&&is_int($t['connectionId']??null)&&$t['connectionId']>0
            &&in_array($t['sourceTool']??null,['mail_send','mail_schedule'],true);
    }
    private function sign(array $t):string {ksort($t);return $this->proof->of('mail_delivery_ticket',$t['uid'],$t);}
}
