<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Accounts;
use OCA\Mcp\Db\MailSetupWorkflowsMapper;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,NativeAccountSetupLinks};
use OCP\AppFramework\Utility\ITimeFactory;

/** Credential-free admission and explicit observation of independently completed native Mail setup. */
class MailAccountSetup {
    public function __construct(private MailSettingsGateway $gateway,private MailSetupWorkflowsMapper $workflows,private OperationJournal $journal,
        private PlanState $states,private ITimeFactory $clock,private NativeAccountSetupLinks $links,private \Closure $allowsTool){}

    public function preview(array $args,string $uid): array {
        $intent=$this->intent($args['intent']);$create=!isset($args['account_id']);
        if($create && !isset($intent['email']))throw new \InvalidArgumentException('An email address is required for account setup.');
        $this->authorizeKind($uid,$create);
        $before=$create?null:$this->gateway->readConnection($uid,(int)$args['account_id']);
        if($before!==null && !$before['editable'])throw new ToolFailure('Manage this provisioned account through its administrator.');
        $after=$create?$intent:self::overlay($before,$intent);$this->links->validate($after,!$create);
        $plan=['action'=>$create?'add_account':'configure_connection','requested'=>$intent,'before'=>$before,
            'credential_entry'=>'native_mail','message'=>'Complete credentials and consent in the native Mail assistant. Opening it is not proof of completion.'];
        if(!$create)$plan['account_id']=(int)$args['account_id'];
        $bound=$plan+['before_ids'=>$create?$this->gateway->ownedIds($uid):[]];
        return $plan+['plan_state'=>$this->states->of($create?'mail_add_account':'mail_configure_connection',$uid,$bound)];
    }
    public function begin(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $create=!isset($args['account_id']);$id=$this->journal->activeId($uid);$expiry=$this->clock->getTime()+86400;
        $url=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->links->url($create));
        $this->workflows->create($this->journal->scopeKey($uid),$id,$create?'create_account':'configure_connection', $create?null:(int)$args['account_id'],
            $create?$this->gateway->ownedIds($uid):[],$this->digest($uid,$args['intent']),$p['before']['revision']??'',$this->clock->getTime(),$expiry);
        $this->journal->awaitingUser($expiry);
        $result=['url'=>$url,'expires_at'=>$expiry,'instructions'=>$create?'Open the native Mail assistant and enter credentials there. Then select the resulting account ID for reviewed completion.'
            :'Open native Mail settings, select the approved account and its Mail server section. Enter credentials there, then review the observed account through completion.'];
        if(!$create)$result['account_id']=(int)$args['account_id'];
        return $result;
    }
    public function previewCompletion(array $args,string $uid): array {
        [$flow,$profile,$tool]=$this->observation($args,$uid);
        $plan=['action'=>'complete_account_setup','setup_operation_id'=>$args['setup_operation_id'],'account_id'=>(int)$args['account_id'],
            'requested'=>$args['intent'],'observed'=>$profile,'source_tool'=>$tool,'message'=>'Confirm this observed native configuration. This does not prove message delivery or globally unique account creation.'];
        return $plan+['plan_state'=>$this->states->of('mail_complete_account_setup',$uid,$plan)];
    }
    public function complete(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->previewCompletion($args,$uid));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $scope=$this->journal->scopeKey($uid);$source=$args['setup_operation_id'];$completion=$this->journal->activeId($uid);$account=(int)$args['account_id'];
        $this->journal->checkpoint(['account_id'=>$account,'source_tool'=>$p['source_tool'],'source_operation_id'=>$source]);
        $this->workflows->atomic(function()use($scope,$source,$completion,$account,$uid): void {
            if(!$this->workflows->claim($scope,$source,$completion,$this->clock->getTime())||!$this->workflows->complete($scope,$source,$completion,$account))throw new ToolFailure('Setup is already being bound or has expired; inspect its operation.');
            $this->journal->completeExternal($uid,$source,$account);
        });
        return ['account_id'=>$account,'native_state'=>'observed_in_mail','source'=>'native_ui'];
    }
    private function observation(array $args,string $uid): array {
        $this->intent($args['intent']);
        $f=$this->workflows->get($this->journal->scopeKey($uid),$args['setup_operation_id']);
        if($f===null||$f['phase']!=='pending'||(int)$f['expires_at']<$this->clock->getTime())throw new ToolFailure('Setup operation not found or no longer pending.');
        $create=$f['kind']==='create_account';if(!$create && $f['kind']!=='configure_connection')throw new ToolFailure('Unsupported setup operation.');
        $tool=$create?'mail_add_account':'mail_configure_connection';$this->authorizeKind($uid,$create);
        if(!($this->allowsTool)($uid,'mail_read_connection'))throw new ToolFailure('Reviewing the observed connection is not permitted.');
        if(!hash_equals($f['intent_digest'],$this->digest($uid,$args['intent'])))throw new ToolFailure('Repeat the original non-secret setup intent.');
        $id=(int)$args['account_id'];
        if(($create && in_array($id,$f['before_ids'],true))||(!$create && $id!==$f['target_account_id']))throw new ToolFailure('Select the exact account resulting from this setup.');
        $profile=$this->gateway->readConnection($uid,$id);
        if(!$profile['editable'])throw new ToolFailure('Provisioned connection settings are managed by the administrator.');
        $this->links->validate($profile,true);
        if(!self::matches($args['intent'],$profile))throw new ToolFailure('The native configuration differs from the requested intent. Review a new setup plan.');
        if(!$create && hash_equals($f['before_digest'],$profile['revision']))throw new ToolFailure('An unchanged profile does not verify a credential-only change. Use native Mail; authorization remains unverified.');
        if($create && !$this->gateway->authorizationAvailable($uid,$id))throw new ToolFailure('Native Mail authorization has not been observed. Complete its assistant first.');
        $original=$this->journal->get($uid,$args['setup_operation_id']);
        if($original===null||$original['tool']!==$tool||$original['state']!=='awaiting_user')throw new ToolFailure('Setup admission could not be verified. Inspect its operation.');
        return [$f,$profile,$tool];
    }
    private function authorizeKind(string $uid,bool $create): void {
        if(!($this->allowsTool)($uid,$create?'mail_add_account':'mail_configure_connection'))throw new ToolFailure('This setup action is not permitted for the current connection.');
        if($create && !$this->gateway->creationAllowed())throw new ToolFailure('The administrator has disabled adding Mail accounts.');
    }
    public function intent(array $intent): array {
        if(array_diff(array_keys($intent),['name','email','imap','smtp','auth_method'])!==[])throw new \InvalidArgumentException('Only non-secret Mail setup fields are accepted.');
        foreach(['name'=>255,'email'=>500,'auth_method'=>16] as $key=>$limit)if(array_key_exists($key,$intent)){
            if(!is_string($intent[$key]))throw new \InvalidArgumentException('Invalid Mail setup text.');MailSettingsGateway::text($intent[$key],$limit);
        }
        if(isset($intent['email'])&&!filter_var($intent['email'],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Invalid account email address.');
        foreach(['imap','smtp'] as $key)if(array_key_exists($key,$intent)){
            if(!is_array($intent[$key])||array_diff(array_keys($intent[$key]),['host','port','tls','user'])!==[])throw new \InvalidArgumentException('Only non-secret connection fields are accepted.');
            foreach(['host'=>255,'user'=>500,'tls'=>4] as $field=>$limit)if(array_key_exists($field,$intent[$key])){
                if(!is_string($intent[$key][$field])||$intent[$key][$field]==='')throw new \InvalidArgumentException('Invalid connection text.');MailSettingsGateway::text($intent[$key][$field],$limit);
            }
        }
        $this->links->validate($intent);return $intent;
    }
    private function digest(string $uid,array $intent): string{return $this->states->of('mail_setup_intent',$uid,self::canonical($intent));}
    private static function canonical(array $value): array {ksort($value);foreach($value as &$item)if(is_array($item))$item=self::canonical($item);return $value;}
    private static function overlay(array $before,array $intent): array {foreach($intent as $k=>$v)$before[$k]=is_array($v)?array_replace($before[$k]??[],$v):$v;return $before;}
    private static function matches(array $intent,array $observed): bool {foreach($intent as $k=>$v)if(!array_key_exists($k,$observed)||(is_array($v)?!self::matches($v,$observed[$k]):$v!==$observed[$k]))return false;return true;}
}
