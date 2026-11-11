<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Transmission\{AttemptStore,DeliveryState};
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\IMAP\IMAPClientFactory;
/** This service has no SMTP dependency or call path. Unknown APPEND is permanently non-replayable. */
final class SentCopyRepair {
    public function __construct(private AttemptStore $store,private \OCA\Mail\Db\MailAccountMapper $accounts,private MailboxMapper $mailboxes,private IMAPClientFactory $imap,private \OCP\IConfig $config){}
    private function target(string $uid,array $row):array {
        if($row['state']!=='accepted'||$row['sent_state']!=='pending')throw new \RuntimeException('Only accepted messages with an unattempted Sent copy can be repaired.');
        $account=new \OCA\Mail\Account($this->accounts->find($uid,(int)$row['account_id']));$id=$account->getMailAccount()->getSentMailboxId();if($id===null)throw new \RuntimeException('Configure a Sent mailbox before copy repair.');$mailbox=$this->mailboxes->findById($id);if($mailbox->getAccountId()!==$account->getId())throw new \RuntimeException('Sent target ownership changed.');
        if($account->getMailAccount()->getDebug()||$this->config->getSystemValueBool('app.mail.debug',false))throw new \RuntimeException('Sent repair requires private transport logging.');
        $client=$this->imap->getClient($account,false);$artifact=json_decode($row['artifact'],true,512,JSON_THROW_ON_ERROR);$client->mcpBeginCopy(strlen($artifact['fcc']));try{$status=$client->status($mailbox->getName(),\Horde_Imap_Client::STATUS_UIDVALIDITY);}catch(\Throwable $e){try{$client->logout();}catch(\Throwable){}throw $e;}
        $validity=(int)($status['uidvalidity']??0);if($validity<=0){try{$client->logout();}catch(\Throwable){}throw new \RuntimeException('Sent mailbox identity unavailable.');}
        $client->mcpExpectCopyValidity($validity);
        $revision=hash('sha256',json_encode([$account->getId(),(int)$account->getMailAccount()->getMcpAuthRevision(),$id,$mailbox->getName(),$validity],JSON_THROW_ON_ERROR));return [$client,$mailbox,$validity,$revision];
    }
    public function preview(string $uid,string $id):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id):array {$r=$this->store->find($uid,$id);[$client,$mailbox,$validity,$target]=$this->target($uid,$r);try{return DeliveryState::receipt($r)+['targetMailboxId'=>(int)$mailbox->getId(),'targetRevision'=>$target,'repairRevision'=>hash('sha256',$r['revision'].':'.$target)];}finally{try{$client->logout();}catch(\Throwable){}}});
    }
    public function repair(string $uid,string $id,string $approvedRevision,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function($scope)use($uid,$id,$approvedRevision,$fence):array {
            $r=$this->store->find($uid,$id);if($r['state']!=='accepted'||$r['sent_state']!=='pending')return DeliveryState::receipt($r);
            $authorize=function()use($uid,$r,$scope,$fence):void {$scope->assertOwned();$fence->authorize($uid,(int)$r['connection_id'],$r['source_tool'],(int)$r['account_id']);$fence->authorize($uid,(int)$r['connection_id'],'mail_sent_repair',(int)$r['account_id']);$scope->assertOwned();};$authorize();
            [$client,$mailbox,$validity,$target]=$this->target($uid,$r);
            try{
                if(!hash_equals(hash('sha256',$r['revision'].':'.$target),$approvedRevision))throw new \RuntimeException('Sent repair target changed.');
                $artifact=json_decode($r['artifact'],true,512,JSON_THROW_ON_ERROR);if(!hash_equals($artifact['fccHash'],hash('sha256',$artifact['fcc'])))throw new \RuntimeException('Frozen Sent artifact changed.');
                $authorize();if(!$this->store->sent($r,'pending','attempting'))return DeliveryState::receipt($this->store->find($uid,$id));
                try{
                    $authorize();$current=$this->accounts->find($uid,(int)$r['account_id']);$currentBox=$this->mailboxes->findById((int)$mailbox->getId());$currentStatus=$client->status($mailbox->getName(),\Horde_Imap_Client::STATUS_UIDVALIDITY);$currentRevision=hash('sha256',json_encode([(int)$current->getId(),(int)$current->getMcpAuthRevision(),$current->getSentMailboxId(),$currentBox->getName(),(int)($currentStatus['uidvalidity']??0)],JSON_THROW_ON_ERROR));if($currentBox->getAccountId()!==$current->getId()||!hash_equals($target,$currentRevision))throw new \RuntimeException('Current Sent target changed.');
                    $uids=$client->mcpAppendFrozen($mailbox->getName(),$artifact['fcc']);
                    if(!$uids instanceof \Horde_Imap_Client_Ids||count($uids)!==1||($copyUid=(int)$uids->current())<=0)throw new \RuntimeException('APPEND identity evidence unavailable.');
                    $after=$client->status($mailbox->getName(),\Horde_Imap_Client::STATUS_UIDVALIDITY);if((int)($after['uidvalidity']??0)!==$validity)throw new \RuntimeException('Sent mailbox changed.');
                    $client->openMailbox($mailbox->getName(),\Horde_Imap_Client::OPEN_READONLY);$query=new \Horde_Imap_Client_Fetch_Query();$query->fullText(['peek'=>true]);$rows=$client->fetch($mailbox->getName(),$query,['ids'=>new \Horde_Imap_Client_Ids([$copyUid])]);$found=$rows->get($copyUid);$bytes=$found?->getFullMsg();if(!is_string($bytes)||!hash_equals($artifact['fccHash'],hash('sha256',$bytes)))throw new \RuntimeException('Sent copy integrity evidence unavailable.');
                    $scope->assertOwned();if(!$this->store->sent($r,'attempting','verified',['mailboxId'=>(int)$mailbox->getId(),'uidvalidity'=>$validity,'uid'=>$copyUid,'sha256'=>$artifact['fccHash']]))throw new \RuntimeException('Sent copy persistence unavailable.');
                }catch(\Throwable){try{$this->store->sent($r,'attempting','uncertain');}catch(\Throwable){}return DeliveryState::receipt(array_replace($r,['sent_state'=>'uncertain']));}
                return DeliveryState::receipt($this->store->find($uid,$id));
            }finally{try{$client->logout();}catch(\Throwable){}}
        });
    }
}
