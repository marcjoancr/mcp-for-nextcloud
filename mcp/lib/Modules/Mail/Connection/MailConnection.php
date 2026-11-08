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
        private MailProbeSlotsMapper $slots,private NativeConnectionProbe $native,private ITimeFactory $clock){}
    public function preview(array $args,string $uid): array {
        $protocols=$args['protocols'];if($protocols===[]||count($protocols)>2||array_diff($protocols,['imap','smtp'])!==[]||count(array_unique($protocols))!==count($protocols))throw new \InvalidArgumentException('Choose IMAP, SMTP or both.');
        $profile=$this->gateway->readConnection($uid,(int)$args['account_id']);
        $p=['action'=>'test_connection','account_id'=>(int)$args['account_id'],'profile'=>$profile,'protocols'=>$protocols,
            'message'=>'Authenticate with the selected native mail servers. No message will be sent. Limit: 10 seconds per protocol, 5 operations per account per minute.'];
        return $p+['plan_state'=>$this->states->of('mail_test_connection',$uid,$p)];
    }
    public function probe(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $id=(int)$args['account_id'];$operation=$this->journal->activeId($uid);
        if(!$this->slots->reserve(hash('sha256',$uid."\0".$id),$operation,$this->clock->getTime()))throw new BeforeEffectFailure('Mail connection probe rate limit reached. Inspect previous results and wait before a new approved operation.');
        $results=array_fill_keys($args['protocols'],['status'=>'unknown','category'=>'unverified_result']);
        $this->journal->checkpoint(['account_id'=>$id,'protocols'=>$results]);
        foreach($args['protocols'] as $protocol) {
            $results[$protocol]=$this->native->run($uid,$id,$protocol,$p['profile']['revision'],$operation);
            $results=\OCA\Mcp\Mcp\Operations\ProtocolDiagnostics::sanitize($results);
            $this->journal->checkpoint(['protocols'=>$results]);
        }
        return ['account_id'=>$id,'protocols'=>$results,'message_sent'=>false];
    }
}
