<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;
final class MutationTicket {
    public function __construct(private PlanState $proof,private ITimeFactory $clock){}
    public function create(string $uid,int $connection,array $lease,array $request):array {
        if(!MutationRequest::valid($request))throw new \InvalidArgumentException('Invalid native mutation request.');
        $t=['uid'=>$uid,'connection_id'=>$connection,'operation'=>$lease['id'],'lease'=>$lease,'request'=>$request,'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>$this->clock->getTime()+25];
        return $t+['proof'=>$this->sign($uid,$t)];
    }
    public function verify(array $t):bool {
        $keys=['uid','connection_id','operation','lease','request','nonce','expires_at','proof'];
        if(count($t)!==count($keys)||array_diff(array_keys($t),$keys)!==[]||!is_string($t['uid'])||$t['uid']===''||strlen($t['uid'])>64||!is_int($t['connection_id'])||$t['connection_id']<0||!is_array($t['request'])||!MutationRequest::valid($t['request'])||!is_array($t['lease']))return false;
        $lease=$t['lease'];if(count($lease)!==3||array_diff(array_keys($lease),['scope','id','token'])!==[]||($lease['id']??null)!==$t['operation'])return false;
        foreach(['scope','token'] as $key)if(!is_string($lease[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$lease[$key])!==1)return false;
        foreach(['operation','nonce','proof'] as $key)if(!is_string($t[$key])||$t[$key]===''||strlen($t[$key])>128)return false;
        if(!is_int($t['expires_at'])||$t['expires_at']<$this->clock->getTime()||$t['expires_at']>$this->clock->getTime()+25)return false;
        $signature=$t['proof'];unset($t['proof']);return hash_equals($this->sign($t['uid'],$t),$signature);
    }
    private function sign(string $uid,array $t):string {
        $sort=function(array $v)use(&$sort):array{ksort($v);foreach($v as &$i)if(is_array($i))$i=$sort($i);return $v;};
        return $this->proof->of('mail_mutation_ticket',$uid,$sort($t));
    }
}
