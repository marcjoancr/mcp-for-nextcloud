<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Settings;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
class MailSettings {
    public function __construct(private MailSettingsGateway $gateway,private PlanState $states){}
    public function preview(array $args,string $uid): array {
        $changes=$args['changes'];
        if($changes===[] || array_diff(array_keys($changes),['editor_mode','signature_above_quote','show_subscribed_only'])!==[])throw new \InvalidArgumentException('Choose supported Mail preferences.');
        if(isset($changes['editor_mode']) && !in_array($changes['editor_mode'],['plaintext','html'],true))throw new \InvalidArgumentException('Unsupported editor mode.');
        foreach(['signature_above_quote','show_subscribed_only'] as $key)if(array_key_exists($key,$changes)&&!is_bool($changes[$key]))throw new \InvalidArgumentException('Invalid Mail preference.');
        $before=$this->gateway->readSettings($uid,(int)$args['account_id']);
        $after=array_replace($before['settings'],$changes);
        $plan=['action'=>'update_account_settings','account_id'=>$before['account_id'],'before'=>$before,'after'=>['settings'=>$after]];
        return $plan+['plan_state'=>$this->states->of('mail_write_account_settings',$uid,$plan)];
    }
    public function write(array $args,string $uid): array {
        PlanState::require($args);$plan=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));
        if(!PlanState::matches($plan['plan_state'],$args))throw new PlanChanged($plan);
        return $this->gateway->writeSettings($uid,(int)$args['account_id'],$args['changes']);
    }
}
