<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Signatures;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
class MailSignatures {
    public function __construct(private MailSettingsGateway $gateway,private PlanState $states){}
    public function read(array $args,string $uid): array{return $this->gateway->readSignature($uid,(int)$args['account_id'],$args['alias_id']??null);}
    public function preview(array $args,string $uid): array {
        if(!array_key_exists('signature',$args)||($args['signature']!==null && (!is_string($args['signature'])||mb_strlen($args['signature'])>20000)))throw new \InvalidArgumentException('Provide a signature of at most 20000 characters, or null to remove it.');
        $before=$this->read($args,$uid);
        $plan=['action'=>'update_signature','account_id'=>$before['account_id'],'alias_id'=>$before['alias_id'],'before'=>$before,'after'=>['signature'=>$args['signature']]];
        return $plan+['plan_state'=>$this->states->of('mail_write_signature',$uid,$plan)];
    }
    public function write(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        return $this->gateway->writeSignature($uid,(int)$args['account_id'],$args['alias_id']??null,$args['signature']);
    }
}
