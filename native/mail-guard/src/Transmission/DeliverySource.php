<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCA\Mail\Service\{DraftsService,AccountService,AliasesService};
use OCA\Mail\Service\Attachment\AttachmentService;
use OCA\Mail\Drafts\DraftSnapshot;
/** Reads exact local source and identity under the owner's physical lease. No group expansion. */
final class DeliverySource {
    public function __construct(private DraftsService $drafts,private \OCA\Mail\Db\MailAccountMapper $accounts,private AliasesService $aliases,private AttachmentService $attachments){}
    public function account(string $uid,int $accountId):\OCA\Mail\Account {return new \OCA\Mail\Account($this->accounts->find($uid,$accountId));}
    public function message(string $uid,int $draftId):\OCA\Mail\Db\LocalMessage {return $this->drafts->getMessage($draftId,$uid);}
    public function read(string $uid,int $draftId):array {
        $draft=$this->drafts->getMessage($draftId,$uid);$account=$this->account($uid,$draft->getAccountId());
        if(!$draft->getMcpDraftOnly()||$draft->getType()!==1||$draft->getSendAt()!==null||$draft->getSmimeSign()||$draft->getSmimeEncrypt()||$draft->isPgpMime()||$draft->getRequestMdn())throw new \RuntimeException('Only protected ordinary unscheduled drafts can be approved.');
        $sender=['name'=>$account->getName(),'email'=>$account->getEMailAddress()];$aliasRevision=null;
        if($draft->getAliasId()!==null){$alias=$this->aliases->find($draft->getAliasId(),$uid);if($alias->getAccountId()!==$account->getId())throw new \RuntimeException('Sender identity changed.');$sender=['name'=>$alias->getName()??$sender['name'],'email'=>$alias->getAlias()];$aliasRevision=hash('sha256',json_encode($alias,JSON_THROW_ON_ERROR));}
        // The native editor mode owns the active body; never release stale inactive HTML.
        $html=$draft->isHtml();$rich=$html?$draft->getBodyHtml():null;
        $plain=$html&&($rich===null||$rich==='')?null:$draft->getBodyPlain();
        $c=['draftId'=>$draftId,'accountId'=>(int)$account->getId(),'draftRevision'=>DraftSnapshot::of($draft),'accountRevision'=>(int)$account->getMailAccount()->getMcpAuthRevision(),'aliasId'=>$draft->getAliasId(),'aliasRevision'=>$aliasRevision,'sender'=>$sender,'to'=>[],'cc'=>[],'bcc'=>[],'subject'=>$draft->getSubject(),'bodyPlain'=>$plain,'bodyHtml'=>$rich,'replyTo'=>$draft->getInReplyToMessageId(),'aiGenerated'=>$draft->isAiGenerated(),'attachments'=>[]];
        foreach($draft->getRecipients()??[] as $r){$kind=match($r->getType()){1=>'to',2=>'cc',3=>'bcc',default=>null};if($kind!==null){$email=$r->getEmail();if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Expand groups into explicit mailbox recipients before approval.');$c[$kind][]=['name'=>$r->getLabel()??'','email'=>$email];}}
        if(strlen($c['subject']??'')>998||strlen($c['bodyPlain']??'')>80000||strlen($c['bodyHtml']??'')>3145728)throw new \RuntimeException('Message exceeds the approved delivery bound.');
        $total=0;$attachments=$draft->getAttachments()??[];if(count($attachments)>10)throw new \RuntimeException('Attachment bound exceeded.');
        foreach($attachments as $ref){[$a,$file]=$this->attachments->getAttachment($uid,(int)$ref->getId());if($a->getLocalMessageId()!==$draftId||$a->getUserId()!==$uid)throw new \RuntimeException('Attachment association changed.');$size=$file->getSize();if(!is_int($size)||$size<0||$size+$total>26214400)throw new \RuntimeException('Attachment size unavailable.');$bytes=$file->getContent();$total+=strlen($bytes);if(strlen($bytes)!==$size||$total>26214400)throw new \RuntimeException('Attachment bytes changed.');$c['attachments'][]=['id'=>(int)$a->getId(),'name'=>$a->getFileName(),'mime'=>$a->getMimeType(),'contentId'=>$a->getContentId(),'disposition'=>$a->getDisposition(),'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'content'=>$bytes];}
        return $c;
    }
}
