<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Modules\Mail\Integration\MailCapabilities;
use OCA\Mcp\Modules\Mail\Integration\MailBodyPreflight;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\Server;

/** Native characterization only, invoked by the fixture-only Mail actor; never reachable from product tools. */
final class MailLifecycleActor {
    private static function outgoing(string $uid): array {
        return array_map(static fn(object $m):int=>(int)$m->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));
    }
    private static function smtpCapture(): string {
        return hash('sha256',is_file('/tmp/mcp-mail-probe-smtp.log')?(string)file_get_contents('/tmp/mcp-mail-probe-smtp.log'):'');
    }
    /** Establish this legacy synthetic seed's provenance only after a real selected-UID check. */
    public static function seedReply(string $uid,int $messageId):array {
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$m=$manager->getMessage($uid,$messageId);$box=$manager->getMailbox($uid,$m->getMailboxId());$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$box->getAccountId());
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a,false);
        try{$client->openMailbox($box->getName(),\Horde_Imap_Client::OPEN_READONLY);$generation=(new MailBodyPreflight())->uidValidity($client,$box->getName());
            if($generation!==\OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration::of($box))throw new \RuntimeException('Synthetic reply seed generation changed.');
            $query=new \Horde_Imap_Client_Fetch_Query();$query->uid();$query->envelope();$data=$client->fetch($box->getName(),$query,['ids'=>new \Horde_Imap_Client_Ids([$m->getUid()])])->first();
            if($data===null||(int)$data->getUid()!==$m->getUid()||(string)$data->getEnvelope()->message_id!==(string)$m->getMessageId())throw new \RuntimeException('Synthetic reply source identity changed.');
            $before=['generation'=>$m->getTagCacheGeneration(),'profile'=>$m->getTagCacheProfile()];$db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter($generation))->set('tag_cache_profile',$q->createNamedParameter(\OCA\Mail\Db\TagProfile::account($a->getMailAccount())))->where($q->expr()->eq('id',$q->createNamedParameter($messageId)))->executeStatement();return $before;
        }finally{$client->logout();}
    }
    /** A different native PHP request attempts a noncredential write while sync owns the account. */
    public static function syncContender(string $uid,int $accountId):array {
        $mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$a=$mapper->find($uid,$accountId);$before=$a->getSignature();$written=false;
        try{$a->setSignature('mcp-sync-contention-'.bin2hex(random_bytes(8)));try{$mapper->update($a);$written=true;}catch(\OCA\Mail\Exception\DraftAdmissionFailure){}
            return ['written'=>$written,'unchanged'=>$mapper->find($uid,$accountId)->getSignature()===$before];
        }finally{if($written){$a=$mapper->find($uid,$accountId);$a->setSignature($before);$mapper->update($a);}}
    }
    public static function selection(string $uid,int $accountId): array {
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$accountId);
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');
        $factory=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory');
        $mapper=Server::get('OCA\\Mail\\IMAP\\MessageMapper');
        $preflight=new MailBodyPreflight();$name='mcp-c0-'.bin2hex(random_bytes(6));
        $folder=$manager->createMailbox($account,$name);$client=$factory->getClient($account,false);
        $client->setParam('read_timeout',3);$client->setParam('timeout',3);$stage='initial_append';
        $before=self::outgoing($uid);$smtp=self::smtpCapture();
        $message="From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: generation fixture\r\nMessage-ID: <same-c0@example.test>\r\n\r\nFirst generation";
        try {
            $mapper->save($client,$folder,$message,[]);
            $stage='initial_select';$client->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);
            $old=$preflight->uidValidity($client,$name);
            $query=new \Horde_Imap_Client_Fetch_Query();$query->uid();$query->envelope();$query->flags();
            $first=$client->fetch($name,$query,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)])->first();
            $oldUid=$first->getUid();$oldId=(string)$first->getEnvelope()->message_id;
            $client->logout();
            // Recreate only our random fixture folder. Native cache ID deliberately remains the old generation.
            $stage='recreate';$other=$factory->getClient($account,false);$other->setParam('read_timeout',3);$other->setParam('timeout',3);
            try {$other->deleteMailbox($name);$other->createMailbox($name);$mapper->save($other,$folder,str_replace('First generation','Second generation',$message),[]);}
            finally {$other->logout();}
            // Same native object can log in and SELECT another generation with no caller-supplied expected token.
            $stage='reselect_new_generation';$client->openMailbox($name,\Horde_Imap_Client::OPEN_READWRITE);
            $new=$preflight->uidValidity($client,$name);
            $second=$client->fetch($name,$query,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)])->first();
            $newUid=$second->getUid();$newId=(string)$second->getEnvelope()->message_id;
            $client->logout();
            // Public native mapper -> store() logs in and reselects implicitly. No MCP transport or retry is involved.
            $stage='store_after_logout';$mapper->addFlag($client,$folder,[$newUid],\Horde_Imap_Client::FLAG_FLAGGED);
            $after=$client->fetch($name,$query,['ids'=>new \Horde_Imap_Client_Ids([$newUid])])->first();
            $accepted=in_array(\Horde_Imap_Client::FLAG_FLAGGED,$after->getFlags(),true);
            $mapper->removeFlag($client,$folder,[$newUid],\Horde_Imap_Client::FLAG_FLAGGED);
            $client->logout();$stage='fresh_duplicate_identity';
            $duplicateClient=$factory->getClient($account,false);$duplicateClient->setParam('read_timeout',3);$duplicateClient->setParam('timeout',3);
            try {
                $duplicateUid=$mapper->save($duplicateClient,$folder,str_replace('First generation','Duplicate Message-ID',$message),[]);
                $duplicateData=$duplicateClient->fetch($name,$query,['ids'=>new \Horde_Imap_Client_Ids([$duplicateUid])])->first();
                $duplicate=$duplicateData->getUid()!==$newUid&&(string)$duplicateData->getEnvelope()->message_id===$newId;
            } finally {$duplicateClient->logout();}
            // The closed flag boundary never reintroduces the unsafe generic raw-client callback.
            $blocked=!method_exists(\OCA\Mcp\Modules\Mail\Integration\MailMutationSession::class,'run');
            return ['generation_changed'=>$old!==$new,'same_uid'=>$oldUid===$newUid,'same_message_id'=>$oldId===$newId,
                'duplicate_message_id_different_uid'=>$duplicate,'native_store_after_reconnect'=>$accepted,'mcp_admission_refused'=>$blocked,
                'outgoing_unchanged'=>$before===self::outgoing($uid),'smtp_unchanged'=>$smtp===self::smtpCapture(),
                'capabilities'=>Server::get(MailCapabilities::class)->forUser($uid)];
        } catch(\Throwable $e) {throw new \RuntimeException('C0 selection characterization failed at '.$stage,0,$e);}
        finally {
            $client->logout();$manager->deleteMailbox($account,$folder);
        }
    }
    public static function cache(string $uid,int $messageId): array {
        $manager=Server::get('OCA\\Mail\\Service\\MailManager');$message=$manager->getMessage($uid,$messageId);
        $mailbox=$manager->getMailbox($uid,$message->getMailboxId());$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$mailbox->getAccountId());
        $mapper=Server::get('OCA\\Mail\\IMAP\\MessageMapper');$cache=Server::get('OCA\\Mail\\Db\\MessageMapper');
        $client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);
        $original=(bool)$message->getFlagFlagged();$before=self::outgoing($uid);$smtp=self::smtpCapture();
        try {
            $mapper->addFlag($client,$mailbox,[$message->getUid()],\Horde_Imap_Client::FLAG_FLAGGED);
            $afterMapper=$cache->findByUids($mailbox,[$message->getUid()])[0];
            $query=new \Horde_Imap_Client_Fetch_Query();$query->flags();
            $remote=$client->fetch($mailbox->getName(),$query,['ids'=>new \Horde_Imap_Client_Ids([$message->getUid()])])->first();
            $manager->flagMessage($account,$mailbox->getName(),$message->getUid(),'flagged',true);
            $afterEvent=$cache->findByUids($mailbox,[$message->getUid()])[0];
            return ['original'=>$original,'remote_flagged'=>in_array(\Horde_Imap_Client::FLAG_FLAGGED,$remote->getFlags(),true),
                'cache_after_low_level_mapper'=>(bool)$afterMapper->getFlagFlagged(),'cache_after_native_event'=>(bool)$afterEvent->getFlagFlagged(),
                'outgoing_unchanged'=>$before===self::outgoing($uid),'smtp_unchanged'=>$smtp===self::smtpCapture()];
        } finally {$client->logout();$manager->flagMessage($account,$mailbox->getName(),$message->getUid(),'flagged',$original);}
    }
    public static function draft(string $uid,int $draftId,array $options=[]): array {
        $drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$draft=$drafts->getMessage($draftId,$uid);
        $accounts=Server::get('OCA\\Mail\\Service\\AccountService');$account=$accounts->find($uid,$draft->getAccountId());
        $mailboxes=Server::get('OCA\\Mail\\Db\\MailboxMapper');$manager=Server::get('OCA\\Mail\\Service\\MailManager');
        $factory=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory');$nativeAccount=$account->getMailAccount();
        $oldRole=$nativeAccount->getDraftsMailboxId();$names=array_map(static fn(object $m):string=>$m->getName(),$mailboxes->findAll($account));
        $draftOnly=property_exists($draft,'mcpDraftOnly')&&$draft->getMcpDraftOnly();
        $subject=$draft->getSubject();$localParent=$draft->getInReplyToMessageId();$localAlias=$draft->getAliasId();$held=$draft->isFailed();
        $outgoing=self::outgoing($uid);$smtp=self::smtpCapture();$payload=json_decode(json_encode($draft,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
        $controller=Server::get('OCA\\Mail\\Controller\\DraftsController');
        $controller->update(id:$draftId,accountId:$draft->getAccountId(),subject:$subject,bodyPlain:$draft->getBodyPlain(),bodyHtml:$draft->getBodyHtml(),
            editorBody:$draft->getEditorBody(),isHtml:$draft->isHtml(),smimeSign:$draft->getSmimeSign(),smimeEncrypt:$draft->getSmimeEncrypt(),
            to:$payload['to'],cc:$payload['cc'],bcc:$payload['bcc'],attachments:$payload['attachments'],aliasId:$localAlias,inReplyToMessageId:$localParent);
        $updated=$drafts->getMessage($draftId,$uid);$released=!$updated->isFailed();$uiParent=$updated->getInReplyToMessageId();
        $client=$factory->getClient($account,false);$imapUid=null;$draftFolder=null;
        try {
            // Despite its name this native method moves a LOCAL DRAFT to IMAP through a NULL transport. It is not SMTP.
            $drafts->sendMessage($updated,$account);
            $draftFolder=$oldRole===null?$mailboxes->find($account,'Drafts'):$mailboxes->findById($oldRole);
            $name=$draftFolder->getName();$query=new \Horde_Imap_Client_Fetch_Query();$query->uid();$query->envelope();
            $query->headers('c0',['Subject','From','Message-ID','In-Reply-To','References'],['peek'=>true]);
            if(isset($options['attachment_base64']))$query->fullText(['peek'=>true]);
            $found=[];
            foreach($client->fetch($name,$query,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)]) as $data){
                if($data->getEnvelope()->subject!==$subject)continue;
                $imapUid=$data->getUid();$headers=(string)$data->getHeaders('c0');
                $found[]=['has_subject_header'=>preg_match('/^Subject:/mi',$headers)===1,'from_is_account'=>str_contains(strtolower((string)$data->getEnvelope()->from),strtolower($account->getEMailAddress())),'has_parent_header'=>preg_match('/^In-Reply-To:/mi',$headers)===1,'has_references_header'=>preg_match('/^References:/mi',$headers)===1,
                    'from'=>(string)$data->getEnvelope()->from,
                    'in_reply_to'=>preg_match('/^In-Reply-To:[ \t]*(.*)$/mi',$headers,$parentMatch)===1?trim($parentMatch[1]):null,
                    'references'=>preg_match('/^References:[ \t]*(.*)$/mi',$headers,$referencesMatch)===1?trim($referencesMatch[1]):null,
                    'attachment_preserved'=>!isset($options['attachment_base64'])||str_contains((string)$data->getFullMsg(),$options['attachment_base64'])];
            }
            return ['draft_only'=>$draftOnly,'local_parent'=>$localParent,'local_alias_id'=>$localAlias,'initially_held'=>$held,'ui_parent'=>$uiParent,'ui_released_hold'=>$released,
                'imap_drafts'=>$found,'outgoing_unchanged'=>$outgoing===self::outgoing($uid),'smtp_unchanged'=>$smtp===self::smtpCapture()];
        } finally {
            if($imapUid!==null&&$draftFolder!==null)$client->expunge($draftFolder->getName(),['ids'=>new \Horde_Imap_Client_Ids([$imapUid]),'delete'=>true]);
            $client->logout();
            if(!in_array('Drafts',$names,true)&&$draftFolder!==null)$manager->deleteMailbox($account,$draftFolder);
            $nativeAccount=$accounts->find($uid,$account->getId())->getMailAccount();
            if($nativeAccount->getDraftsMailboxId()!==$oldRole){$nativeAccount->setDraftsMailboxId($oldRole);$accounts->update($nativeAccount);}
        }
    }
}
