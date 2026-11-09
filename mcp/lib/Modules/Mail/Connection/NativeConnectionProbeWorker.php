<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Connection;
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,MailServices,NativeAccountSetupLinks};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCP\IConfig;
/** Runs only in the bounded native CLI child. No send, envelope, mailbox selection or outbox API is used. */
class NativeConnectionProbeWorker {
    public function __construct(private MailSettingsGateway $gateway,private MailServices $native,private NativeAccountSetupLinks $hosts,
        private IConfig $config,private ProbeTicket $tickets,private OperationJournal $journal,private \Closure $authorize){}
    public function run(array $ticket): array {
        try {
            if(!$this->tickets->verify($ticket)||!($this->authorize)($ticket['uid'],$ticket['connection_id']))return ['status'=>'failed','category'=>'policy_refused'];
            $uid=$ticket['uid'];$id=$ticket['account_id'];$protocol=$ticket['protocol'];
            $operation=$this->journal->get($uid,$ticket['operation_id']);
            if($operation===null||$operation['tool']!=='mail_test_connection'||$operation['state']!=='running')return ['status'=>'failed','category'=>'policy_refused'];
            if(!($this->authorize)($ticket['uid'],$ticket['connection_id'],$operation['source_tools']??[]))return ['status'=>'failed','category'=>'policy_refused'];
            $p=$this->gateway->readConnection($uid,$id);
            if(!hash_equals($p['revision'],$ticket['revision']))return ['status'=>'unknown','category'=>'revision_changed'];
            $account=$this->gateway->account($uid,$id);
            if(!$p['editable']||!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true)
                ||$this->config->getSystemValueBool('app.mail.debug',false)||$account->getMailAccount()->getDebug())return ['status'=>'unsupported','category'=>'unsupported_transport'];
            $this->hosts->validate([$protocol=>$p[$protocol]]);
            if($protocol==='imap'){
                $client=$this->native->clientFactory($uid)->getClient($account,false);
                $client->login();$client->logout();
            }else{
                $transport=$this->native->smtpFactory($uid)->create($account);
                if(!is_a($transport,'Horde_Mail_Transport_Smtphorde'))return ['status'=>'unsupported','category'=>'unsupported_transport'];
                $client=$transport->getSMTPObject();$client->logout();
            }
            return ['status'=>'ok','category'=>'authenticated'];
        }catch(\Throwable){return ['status'=>'failed','category'=>'connection_or_authentication_failed'];}
    }
}
