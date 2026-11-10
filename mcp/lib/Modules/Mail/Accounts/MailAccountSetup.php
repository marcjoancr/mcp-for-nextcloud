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
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,NativeAccountSetupLinks,NativeSetupEvidence};
use OCP\AppFramework\Utility\ITimeFactory;

/** Credential-free admission and explicit observation of independently completed native Mail setup. */
class MailAccountSetup {
    public function __construct(private MailSettingsGateway $gateway,private MailSetupWorkflowsMapper $workflows,private OperationJournal $journal,
        private PlanState $states,private ITimeFactory $clock,private NativeAccountSetupLinks $links,private \Closure $allowsTool,private ?NativeSetupEvidence $evidence=null){}

    public function preview(array $args,string $uid): array {
        $intent=$this->intent($args['intent']);$create=!isset($args['account_id']);
        if($create && !isset($intent['email']))throw new \InvalidArgumentException('An email address is required for account setup.');
        $this->authorizeKind($uid,$create);
        $before=$create?null:$this->gateway->readConnection($uid,(int)$args['account_id']);
        if($before!==null && !$before['editable'])throw new ToolFailure('Manage this provisioned account through its administrator.');
        $after=$create?$intent:self::overlay($before,$intent);$this->links->validate($after,!$create);
        $plan=['action'=>$create?'add_account':'configure_connection','requested'=>$intent,'before'=>$before,
            'credential_entry'=>'native_mail','message'=>'Complete credentials and consent in the native Mail assistant. Opening it is not proof of completion.'];
        if($this->evidence?->available())$plan['credential_evidence']='native_manual_write';
        if(!$create)$plan['account_id']=(int)$args['account_id'];
        $bound=$plan+['before_ids'=>$create?$this->gateway->ownedIds($uid):[]];
        return $plan+['plan_state'=>$this->states->of($create?'mail_add_account':'mail_configure_connection',$uid,$bound)];
    }
    public function begin(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $create=!isset($args['account_id']);$id=$this->journal->activeId($uid);$expiry=$this->clock->getTime()+86400;
        $url=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->links->url($create));
        $context=null;
        if(isset($p['credential_evidence'])){
            $context=$this->evidence->register($uid,$this->journal->scopeKey($uid),$id,$create?'create_account':'configure_connection',$create?null:(int)$args['account_id'],$p['requested'],$expiry);
            $url=$this->links->url($create,$context);
        }
        $this->workflows->create($this->journal->scopeKey($uid),$id,$create?'create_account':'configure_connection', $create?null:(int)$args['account_id'],
            $create?$this->gateway->ownedIds($uid):[],$this->digest($uid,$args['intent']),$p['before']['revision']??'',$this->clock->getTime(),$expiry,$context);
        $this->journal->awaitingUser($expiry);
        $result=['url'=>$url,'expires_at'=>$expiry,'instructions'=>$create?'Open the native Mail assistant and enter credentials there. Then select the resulting account ID for reviewed completion.'
            :'Open native Mail settings, select the approved account and its Mail server section. Enter credentials there, then review the observed account through completion.'];
        if(!$create)$result['account_id']=(int)$args['account_id'];
        return $result;
    }
    public function previewCompletion(array $args,string $uid): array {
        [$flow,$profile,$tool,$proof]=$this->observation($args,$uid);
        $plan=['action'=>'complete_account_setup','setup_operation_id'=>$args['setup_operation_id'],'account_id'=>(int)$args['account_id'],
            'requested'=>$args['intent'],'observed'=>$profile,'source_tool'=>$tool,'message'=>'Confirm this observed native configuration. This does not prove message delivery or globally unique account creation.'];
        if($proof!==null){$plan['credential_write']=$proof;$plan['message']='Confirm the observed native credential write. Credential change, current authentication and message delivery remain unverified.';}
        return $plan+['plan_state'=>$this->states->of('mail_complete_account_setup',$uid,$plan)];
    }
    public function complete(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->previewCompletion($args,$uid));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $scope=$this->journal->scopeKey($uid);$source=$args['setup_operation_id'];$completion=$this->journal->activeId($uid);$account=(int)$args['account_id'];
        $this->journal->checkpoint(['account_id'=>$account,'source_tool'=>$p['source_tool'],'source_operation_id'=>$source]);
        $proof=$p['credential_write']??null;
        $finish=function()use($args,$p,$scope,$source,$completion,$account,$uid,$proof):void {
            $this->workflows->atomic(function(\OCP\IDBConnection $db)use($args,$p,$scope,$source,$completion,$account,$uid,$proof):void {
                // Current permissions, ownership, intent and evidence are checked under the native owner scope.
                $fresh=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->previewCompletion($args,$uid));
                if(!hash_equals($fresh['plan_state'],$p['plan_state']))throw new PlanChanged($fresh);
                if($proof!==null){
                    $flow=$this->workflows->get($scope,$source);
                    $this->evidence->claim($db,$uid,$flow['native_context_id'],$account,$proof['revision'],$scope,$source,$completion);
                }
                if(!$this->workflows->claim($scope,$source,$completion,$this->clock->getTime())||!$this->workflows->complete($scope,$source,$completion,$account))throw new ToolFailure('Setup is already being bound or has expired; inspect its operation.');
                $this->journal->completeExternal($uid,$source,$account);
                if($proof!==null)$this->evidence->assertCompletion($db,$uid);
            });
        };
        if($proof!==null)$this->evidence->withCompletion($uid,$finish);else $finish();
        $result=['account_id'=>$account,'native_state'=>'observed_in_mail','source'=>'native_ui'];
        if($proof!==null)$result['credential_write']=$proof;return $result;
    }
    /** An observation context does not complete the workflow or attest to secret changes. */
    public function diagnosticContext(array $args,string $uid):array {
        [$flow,$profile,$tool]=$this->observation($args,$uid,true);
        return ['source_operation_id'=>$args['setup_operation_id'],'source_tool'=>$tool,
            'source_tools'=>[$tool,'mail_read_connection'],'profile_revision'=>$profile['revision'],'credential_change'=>'unverified'];
    }
    private function observation(array $args,string $uid,bool $diagnostic=false): array {
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
        if(!$diagnostic && $create && !$this->gateway->authorizationAvailable($uid,$id))throw new ToolFailure('Native Mail authorization has not been observed. Complete its assistant first.');
        $original=$this->journal->get($uid,$args['setup_operation_id']);
        if($original===null||$original['tool']!==$tool||$original['state']!=='awaiting_user')throw new ToolFailure('Setup admission could not be verified. Inspect its operation.');
        $proof=null;
        if(!$diagnostic&&$f['native_context_id']!==null&&$this->evidence?->available())$proof=$this->evidence->observe($uid,$f['native_context_id'],$id,$this->journal->scopeKey($uid),$args['setup_operation_id']);
        if(!$diagnostic&&!$create&&hash_equals($f['before_digest'],$profile['revision'])&&$proof===null)throw new ToolFailure('An unchanged profile requires current correlated native manual-write evidence. Authorization remains unverified.');
        return [$f,$profile,$tool,$proof];
    }
    /** Recover the same pending native handoff without registration, activation or credential work. */
    public function pendingNativeEntry(string $uid,string $operation):?array {
        try{
            if(!$this->evidence?->available())return null;
            $scope=$this->journal->scopeKey($uid);$f=$this->workflows->get($scope,$operation);
            if($f===null||$f['native_context_id']===null||$f['phase']!=='pending'||(int)$f['expires_at']<$this->clock->getTime())return null;
            $create=$f['kind']==='create_account';if(!$create&&$f['kind']!=='configure_connection')return null;
            $this->authorizeKind($uid,$create);if(!($this->allowsTool)($uid,'mail_read_connection'))return null;
            $source=$this->journal->get($uid,$operation);
            if($source===null||$source['state']!=='awaiting_user'||$source['tool']!==($create?'mail_add_account':'mail_configure_connection'))return null;
            $description=$this->evidence->describe($uid,$f['native_context_id'],$scope,$operation);
            if($description===null||$description['kind']!==$f['kind']||$description['expires_at']!==(int)$f['expires_at'])return null;
            $target=$description['target_account_id'];
            if(!$create&&$target!==$f['target_account_id'])return null;
            // Opening the native editor must remain possible to repair an unsafe profile; completion still validates TLS/host policy.
            if($target!==null){$profile=$this->gateway->readConnection($uid,$target);if(!$profile['editable'])return null;}
            $result=['url'=>$this->links->url($target===null,$f['native_context_id']),'expires_at'=>(int)$f['expires_at']];
            if($target!==null)$result['account_id']=$target;return $result;
        }catch(\Throwable){return null;}
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
