<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,Files\IRootFolder};
final class MailDraftConcurrencyActor {
    private static function data(ToolReply $reply):array {
        if(!$reply->ok())throw new \RuntimeException('Draft concurrency fixture tool failed: '.$reply->describe());
        return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
    private static function write(McpClient $client,array $args):array {
        $plan=self::data($client->tool('mail_write_draft',$args));
        return self::data($client->tool('mail_write_draft',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]));
    }
    public static function stale(string $uid,string $mode):array {
        if($uid!==MailActor::ALICE||!in_array($mode,['automatic','explicit'],true))throw new \RuntimeException('Unexpected draft concurrency fixture.');
        $policy=Server::get(GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$policy->setGrant($uid,'files','read',true);
        ToolPermissionFixture::assign($uid,array_fill_keys(['mail_write_draft','mail_attach_files','mail_read_drafts','files_read'],'allow'));
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$mapper=Server::get('OCA\\Mail\\Db\\LocalMessageMapper');
        $client=new McpClient(LoginFactory::basic($uid));$token=bin2hex(random_bytes(8));$subject='mcp-v6-concurrency-'.$token;
        $folder=Server::get(IRootFolder::class)->getUserFolder($uid);$files=[];$draftId=null;$imapIds=[];$imap=null;$box=null;
        $safety=MailNativeMutationActor::safety($uid);
        try{
            foreach(['old','new'] as $label){$file=$folder->newFile('mcp-v6-concurrency-'.$token.'-'.$label.'.bin');$bytes=$label." attachment\0".random_bytes(24);$file->putContent($bytes);$files[$label]=['file'=>$file,'bytes'=>$bytes];}
            if($mode==='automatic'){
                $draft=new \OCA\Mail\Db\LocalMessage();$draft->setType(1);$draft->setAccountId($account->getId());$draft->setSubject($subject);$draft->setBodyPlain('Old background body');$draft->setBodyHtml('');$draft->setEditorBody('Old background body');$draft->setHtml(false);$draft->setFailed(false);$draft->setUpdatedAt(time()-600);
                $draft=$drafts->saveMessage($account,$draft,[['email'=>'recipient@example.test']],[],[]);$draftId=$draft->getId();$snapshot=null;
                foreach($mapper->findDueDrafts(time()+600) as $candidate)if($candidate->getId()===$draftId)$snapshot=$candidate;
                if($snapshot===null)throw new \RuntimeException('The native background snapshot was not eligible.');
            }else{
                $saved=self::write($client,['account_id'=>$account->getId(),'subject'=>$subject,'body_plain'=>'Old explicit body','to'=>[['email'=>'recipient@example.test']],'add_files'=>['/'.$files['old']['file']->getName()]]);$draftId=$saved['draft']['id'];$snapshot=$drafts->getMessage($draftId,$uid);
            }
            self::write($client,['account_id'=>$account->getId(),'draft_id'=>$draftId,'subject'=>$subject,'body_plain'=>'New protected body '.$token,'add_files'=>['/'.$files['new']['file']->getName()]]);
            $refused=false;
            try{$drafts->sendMessage($snapshot,$account,$mode==='automatic');}catch(\OCA\Mail\Exception\ClientException|\OCA\Mail\Exception\ServiceException){$refused=true;}
            $remaining=null;try{$remaining=$drafts->getMessage($draftId,$uid);}catch(\OCP\AppFramework\Db\DoesNotExistException){}
            $imap=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);$boxes=Server::get('OCA\\Mail\\Db\\MailboxMapper')->findAll($account);
            foreach($boxes as $candidate)if($candidate->getName()==='Drafts')$box=$candidate;
            if($box!==null){$q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->envelope();foreach($imap->fetch($box->getName(),$q,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)]) as $message)if($message->getEnvelope()->subject===$subject)$imapIds[]=$message->getUid();}
            $unchanged=true;foreach($files as $f)$unchanged=$unchanged&&$f['file']->getContent()===$f['bytes'];
            return ['refused'=>$refused,'new_body_retained'=>$remaining?->getBodyPlain()==='New protected body '.$token,
                'new_attachments_retained'=>$remaining!==null&&count($remaining->getAttachments())===($mode==='automatic'?1:2),'imap_appends'=>count($imapIds),'files_unchanged'=>$unchanged,'no_send'=>$safety===MailNativeMutationActor::safety($uid)];
        }finally{
            if($imap!==null){if($box!==null&&$imapIds!==[])$imap->expunge($box->getName(),['ids'=>new \Horde_Imap_Client_Ids($imapIds),'delete'=>true]);$imap->logout();}
            if($draftId!==null)try{$remaining=$drafts->getMessage($draftId,$uid);if($remaining->getSubject()===$subject)$drafts->deleteMessage($uid,$remaining);}catch(\OCP\AppFramework\Db\DoesNotExistException){}
            foreach($files as $f)$f['file']->delete();
        }
    }
    /** Runs in a separate native PHP request while the parent owns its physical session. */
    public static function contender(string $uid,array $in):array {
        $drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$attachments=Server::get('OCA\\Mail\\Service\\Attachment\\AttachmentService');
        $db=Server::get(\OCP\IDBConnection::class);
        if(isset($in['kill'])){
            $id=$in['kill'];if(!is_int($id)||$id<1)throw new \RuntimeException('Invalid fixture connection.');
            $db->executeStatement('KILL CONNECTION '.$id);
        }
        $message=$drafts->getMessage((int)$in['draft_id'],$uid);$account=Server::get('OCA\\Mail\\Service\\AccountService')->find($uid,$message->getAccountId());
        $message->setBodyPlain('Successor native body');$message->setEditorBody('Successor native body');
        $local=array_map(static fn($a):array=>['type'=>'local','id'=>$a->getId()],$message->getAttachments());
        $write=false;$cleanup=false;
        try{$drafts->updateMessage($account,$message,[['email'=>'recipient@example.test']],[],[],$local);$write=true;}catch(\OCA\Mail\Exception\DraftAdmissionFailure){}
        try{$cleanup=$attachments->deleteUnassociatedAttachment($uid,(int)$in['attachment_id']);}catch(\OCA\Mail\Exception\DraftAdmissionFailure){}
        return ['write'=>$write,'cleanup'=>$cleanup];
    }
    public static function coordinated(string $uid,string $mode):array {
        if($uid!==MailActor::ALICE||!in_array($mode,['stage','append','loss_before','loss_after','attachments'],true))throw new \RuntimeException('Unexpected coordination fixture.');
        $drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$attachments=Server::get('OCA\\Mail\\Service\\Attachment\\AttachmentService');$am=Server::get('OCA\\Mail\\Db\\LocalAttachmentMapper');
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$db=Server::get(\OCP\IDBConnection::class);$events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
        $subject='mcp-v6-owner-'.bin2hex(random_bytes(8));$bytes="Native protected snapshot\0".random_bytes(16);$draftId=null;$otherId=null;$orphan=null;$imap=null;$box=null;$imapIds=[];$fired=false;$child=[];
        $safety=MailNativeMutationActor::safety($uid);
        try{
            $a=$attachments->addFileFromString($uid,$subject.'.bin','application/octet-stream',$bytes,null,'attachment');
            $m=new \OCA\Mail\Db\LocalMessage();$m->setType(1);$m->setAccountId($account->getId());$m->setSubject($subject);$m->setBodyPlain('Initial body');$m->setBodyHtml('');$m->setEditorBody('Initial body');$m->setHtml(false);$m->setFailed(true);$m->setMcpDraftOnly(true);$m->setUpdatedAt(time());
            $m=$drafts->saveMessage($account,$m,[['email'=>'recipient@example.test']],[],[],[['type'=>'local','id'=>$a->getId()]]);$draftId=$m->getId();
            $refused=false;$wrongParent=false;$orphanRetained=false;
            if($mode==='attachments'){
                $other=new \OCA\Mail\Db\LocalMessage();$other->setType(1);$other->setAccountId($account->getId());$other->setSubject($subject.'-other');$other->setBodyPlain('Other');$other->setBodyHtml('');$other->setEditorBody('Other');$other->setHtml(false);$other->setUpdatedAt(time());$other=$drafts->saveMessage($account,$other,[['email'=>'recipient@example.test']],[],[]);$otherId=$other->getId();
                try{$am->saveLocalMessageAttachments($uid,$otherId,[$a->getId()]);}catch(\OCA\Mail\Exception\DraftAdmissionFailure){$refused=true;}
                try{$attachments->deleteLocalMessageAttachmentsById($uid,$otherId,[$a->getId()]);}catch(\OCA\Mail\Exception\DraftAdmissionFailure){$wrongParent=true;}
                $orphan=$attachments->addFileFromString($uid,$subject.'-orphan','text/plain','Orphan',null,'attachment');
                // Simulate eligibility read followed by native association; the atomic cleanup must recheck.
                [$read]=$attachments->getAttachment($uid,$orphan->getId());if($read->getLocalMessageId()!==null)throw new \RuntimeException('Fixture orphan already attached.');
                $attachments->saveLocalMessageAttachments($uid,$draftId,[$orphan->getId()]);
                $orphanRetained=!$attachments->deleteUnassociatedAttachment($uid,$orphan->getId());
            }else{
                $event=in_array($mode,['stage','loss_before'],true)?\OCA\Mail\Events\BeforeImapClientCreated::class:\OCA\Mail\Events\DraftSavedEvent::class;
                $connection=(int)$db->executeQuery('SELECT CONNECTION_ID()')->fetchOne();
                $events->addListener($event,static function($event)use(&$fired,&$child,$uid,$draftId,$a,$mode,$connection):void {
                    if($fired||$event->getAccount()->getUserId()!==$uid)return;$fired=true;
                    $input=['draft_id'=>$draftId,'attachment_id'=>$a->getId()];if(str_starts_with($mode,'loss_'))$input['kill']=$connection;
                    $child=MailActor::run($uid,'draft-contender',$input);
                });
                if($mode==='stage'){
                    $m=$drafts->getMessage($draftId,$uid);$m->setBodyPlain('Parent staged body');$m->setEditorBody('Parent staged body');
                    $drafts->updateMessage($account,$m,[['email'=>'recipient@example.test']],[],[],[['type'=>'local','id'=>$a->getId()]]);
                }else{
                    try{$drafts->sendMessage($drafts->getMessage($draftId,$uid),$account);}catch(\Throwable){$refused=true;}
                    // A killed wrapper must be explicitly reconnected before independent readback/fixture cleanup.
                    if(str_starts_with($mode,'loss_'))$db->close();
                }
            }
            $remaining=null;try{$remaining=$drafts->getMessage($draftId,$uid);}catch(\OCP\AppFramework\Db\DoesNotExistException){}
            $actualBytes=false;if($remaining!==null){[$meta,$file]=$attachments->getAttachment($uid,$a->getId());$actualBytes=$meta->getLocalMessageId()===$draftId&&$file->getContent()===$bytes;}
            $imap=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false);
            foreach(Server::get('OCA\\Mail\\Db\\MailboxMapper')->findAll($account) as $candidate)if($candidate->getName()==='Drafts')$box=$candidate;
            if($box!==null){$q=new \Horde_Imap_Client_Fetch_Query();$q->uid();$q->envelope();foreach($imap->fetch($box->getName(),$q,['ids'=>new \Horde_Imap_Client_Ids(\Horde_Imap_Client_Ids::ALL)]) as $message)if($message->getEnvelope()->subject===$subject)$imapIds[]=$message->getUid();}
            return ['fired'=>$fired,'child'=>$child,'refused'=>$refused,'wrong_parent'=>$wrongParent,'orphan_retained'=>$orphanRetained,'exists'=>$remaining!==null,'body'=>$remaining?->getBodyPlain(),'bytes_retained'=>$actualBytes,'imap_appends'=>count($imapIds),'no_send'=>$safety===MailNativeMutationActor::safety($uid)];
        }finally{
            if($imap!==null){if($box!==null&&$imapIds!==[])$imap->expunge($box->getName(),['ids'=>new \Horde_Imap_Client_Ids($imapIds),'delete'=>true]);$imap->logout();}
            foreach([$draftId,$otherId] as $id)if($id!==null)try{$drafts->deleteMessage($uid,$drafts->getMessage($id,$uid));}catch(\OCP\AppFramework\Db\DoesNotExistException){}
            if($orphan!==null)$attachments->deleteUnassociatedAttachment($uid,$orphan->getId());
        }
    }

}
