<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Connection;
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Db\MailProbeSlotsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
class MailConnection {
    public function __construct(private MailSettingsGateway $gateway,private PlanState $states,private OperationJournal $journal,
        private MailProbeSlotsMapper $slots,private NativeConnectionProbe $native,private ITimeFactory $clock,private ?\OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup $setup=null){}
    public function preview(array $args,string $uid): array {
        $protocols=$args['protocols'];if($protocols===[]||count($protocols)>2||array_diff($protocols,['imap','smtp'])!==[]||count(array_unique($protocols))!==count($protocols))throw new \InvalidArgumentException('Choose IMAP, SMTP or both.');
        $context=$this->context($args,$uid);
        $profile=$this->gateway->readConnection($uid,(int)$args['account_id']);
        $p=['action'=>'test_connection','account_id'=>(int)$args['account_id'],'profile'=>$profile,'protocols'=>$protocols,
            'message'=>'Authenticate with the selected native mail servers. No message will be sent. Limit: 10 seconds per protocol, 5 operations per account per minute.'];
        if($context!==null)$p['observed']=$context;
        return $p+['plan_state'=>$this->states->of('mail_test_connection',$uid,$p)];
    }
    public function probe(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $id=(int)$args['account_id'];$operation=$this->journal->activeId($uid);
        if(!$this->slots->reserve(hash('sha256',$uid."\0".$id),$operation,$this->clock->getTime()))throw new BeforeEffectFailure('Mail connection probe rate limit reached. Inspect previous results and wait before a new approved operation.');
        $results=array_fill_keys($args['protocols'],['status'=>'unknown','category'=>'unverified_result']);
        $this->journal->checkpoint(['account_id'=>$id,'protocols'=>$results]+($p['observed']??[]));
        foreach($args['protocols'] as $protocol) {
            try{$fresh=$this->context($args,$uid);}catch(\Throwable){$fresh=false;}
            $results[$protocol]=$fresh===($p['observed']??null)
                ?$this->native->run($uid,$id,$protocol,$p['profile']['revision'],$operation)
                :['status'=>'failed','category'=>'policy_refused'];
            $results=\OCA\Mcp\Mcp\Operations\ProtocolDiagnostics::sanitize($results);
            $this->journal->checkpoint(['protocols'=>$results]);
        }
        $extra=[];
        if(isset($p['observed'])){
            try{$current=$this->context($args,$uid);}catch(\Throwable){$current=null;}
            $ok=$current===$p['observed'];foreach($results as $r)$ok=$ok&&$r['status']==='ok'&&$r['category']==='authenticated';
            $observation=['setup_operation_id'=>$args['setup_operation_id'],'observed_at'=>$this->clock->getTime(),
                'profile_revision'=>$p['profile']['revision'],'status'=>$ok?'authentication_observed':'unverified','credential_change'=>'unverified','setup_completed'=>false];
            $this->journal->checkpoint(['authentication_observation'=>$observation]);$extra['authentication_observation']=$observation;
        }
        return ['account_id'=>$id,'protocols'=>$results,'message_sent'=>false]+$extra;
    }
    private function context(array $args,string $uid):?array {
        if(!array_key_exists('setup_operation_id',$args)&&!array_key_exists('intent',$args))return null;
        if(!isset($args['setup_operation_id'])||!array_key_exists('intent',$args)||!is_array($args['intent'])||$this->setup===null)throw new \InvalidArgumentException('Repeat both setup_operation_id and the original non-secret intent.');
        return $this->setup->diagnosticContext($args,$uid);
    }

}
