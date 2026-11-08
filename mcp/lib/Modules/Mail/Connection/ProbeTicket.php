<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Connection;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\AppFramework\Utility\ITimeFactory;
class ProbeTicket {
    public function __construct(private PlanState $states,private ITimeFactory $clock){}
    public function create(string $uid,int $account,string $protocol,int $connection,string $revision,string $operation): array {
        $t=['uid'=>$uid,'account_id'=>$account,'protocol'=>$protocol,'connection_id'=>$connection,'revision'=>$revision,'operation_id'=>$operation,'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>$this->clock->getTime()+15];
        return $t+['proof'=>$this->states->of('mail_probe_ticket',$uid,$t)];
    }
    public function verify(array $ticket): bool {
        $keys=['uid','account_id','protocol','connection_id','revision','operation_id','nonce','expires_at','proof'];
        if(count($ticket)!==count($keys)||array_diff(array_keys($ticket),$keys)!==[])return false;
        if(!is_string($ticket['uid'])||$ticket['uid']===''||strlen($ticket['uid'])>64||!is_int($ticket['account_id'])||$ticket['account_id']<1
            ||!is_int($ticket['connection_id'])||$ticket['connection_id']<0||!in_array($ticket['protocol'],['imap','smtp'],true)
            ||!is_int($ticket['expires_at'])||$ticket['expires_at']<$this->clock->getTime()||$ticket['expires_at']>$this->clock->getTime()+15)return false;
        foreach(['revision','operation_id','nonce','proof'] as $k)if(!is_string($ticket[$k])||$ticket[$k]===''||strlen($ticket[$k])>128)return false;
        $proof=$ticket['proof'];unset($ticket['proof']);
        // Recreate the issuer's fixed field order instead of trusting JSON object ordering.
        $ordered=[];foreach(array_slice($keys,0,-1) as $key)$ordered[$key]=$ticket[$key];
        return hash_equals($this->states->of('mail_probe_ticket',$ticket['uid'],$ordered),$proof);
    }
}
