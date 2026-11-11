<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Content\{ContentSource,DraftCopyComposition};
use OCA\Mail\Db\{LocalMessage,LocalMessageMapper,LocalAttachmentMapper,Recipient,MailAccountMapper};
use OCA\Mail\Drafts\DraftCoordinator;
use OCA\Mail\Lifecycle\AccountVisibility;
use OCA\Mail\Service\Attachment\AttachmentService;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** New protected LOCAL composition only. Never invokes source-draft or delivery events. */
final class GuardedDraftImport {
    public const CONTRACT='mcp-mail-draft-copy-v1';
    public function __construct(private IDBConnection $db,private ContentSource $sources,private GuardedContentReader $reader,private MailAccountMapper $accounts,private AliasesService $aliases,private LocalMessageMapper $messages,private LocalAttachmentMapper $links,private AttachmentService $attachments){}
    private function target(string $uid,int $accountId,?int $aliasId,object $fence):array {
        if($accountId<1||($aliasId!==null&&$aliasId<1))throw new \InvalidArgumentException('Select an owned target sender.');
        (new AccountVisibility($this->db))->assertVisible($uid,$accountId);$fence->authorize($uid,$accountId,0,0);
        $entity=$this->accounts->find($uid,$accountId);$account=new \OCA\Mail\Account($entity);$sender=['name'=>$account->getName(),'email'=>$account->getEMailAddress()];$aliasRevision=null;
        if($aliasId!==null){$alias=$this->aliases->find($aliasId,$uid);if($alias->getAccountId()!==$accountId)throw new \RuntimeException('Sender identity unavailable.');$sender=['name'=>$alias->getName()??$sender['name'],'email'=>$alias->getAlias()];$aliasRevision=hash('sha256',json_encode($alias,JSON_THROW_ON_ERROR));}
        if(!filter_var($sender['email'],FILTER_VALIDATE_EMAIL)||preg_match('/[\x00-\x1f\x7f]/',$sender['name']))throw new \RuntimeException('Unsupported native sender.');
        return ['account_id'=>$accountId,'alias_id'=>$aliasId,'sender'=>$sender,'auth_revision'=>(int)$entity->getMcpAuthRevision(),'profile_revision'=>\OCA\Mail\Db\TagProfile::account($entity),'alias_revision'=>$aliasRevision];
    }
    private function prepare(string $uid,int $id,int $target,?int $alias,object $fence):array {
        $this->sources->primary();$identity=$this->target($uid,$target,$alias,$fence);$read=$this->reader->readDraft($uid,$id,$fence);$composition=DraftCopyComposition::parse($read);
        $preview=['action'=>'copy_to_new_protected_local_draft','source_retained'=>true,'source'=>ContentSource::stable($read['source']),'content_revision'=>$read['content_revision'],'target'=>$identity,'source_from'=>$composition['from'],'to'=>$composition['to'],'cc'=>$composition['cc'],'bcc'=>$composition['bcc'],'subject'=>$composition['subject'],'reply_to'=>$composition['reply_to'],'body'=>['plain_characters'=>mb_strlen($composition['body_plain']),'html_characters'=>mb_strlen($composition['body_html']),'has_plain'=>$composition['has_plain'],'has_html'=>$composition['has_html']],'attachments'=>array_map(static fn(array $a):array=>array_intersect_key($a,array_flip(['part_id','filename','mime_type','content_id','disposition','byte_count','sha256'])),$composition['attachments'])];
        if($this->target($uid,$target,$alias,$fence)!==$identity)throw new \RuntimeException('Target sender changed.');return ['preview'=>$preview,'composition'=>$composition];
    }
    public function preview(string $uid,int $id,int $target,?int $alias,object $fence):array {return $this->prepare($uid,$id,$target,$alias,$fence)['preview'];}
    /** Read-only outcome recovery is independent of a surviving IMAP source or local copy. */
    public function outcome(string $uid,string $key,object $fence):?array {
        $this->sources->primary();if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new \InvalidArgumentException('Invalid copy operation key.');
        $q=$this->db->getQueryBuilder();$q->select('state','local_draft_id','target_account_id','source_refs')->from('mail_mcp_draft_copies')->where($q->expr()->eq('operation_key',$q->createNamedParameter($key)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->setMaxResults(1);$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}if(!$row)return null;
        $source=json_decode($row['source_refs'],true,16,JSON_THROW_ON_ERROR);$fence->authorizeHistorical($uid,$source['account_id'],$source['mailbox_id'],$source['message_id']);$fence->authorizeHistorical($uid,(int)$row['target_account_id'],0,0);
        return ['state'=>$row['state']==='succeeded'?'succeeded':'uncertain','draft_id'=>$row['local_draft_id']===null?null:(int)$row['local_draft_id'],'source_retained'=>true,'replayed'=>true,'safe_to_retry'=>false];
    }
    private function assertDraftSource(string $uid,array $source,object $fence):void {$current=$this->sources->message($uid,$source['message_id'],$fence);if($current['drafts_mailbox_id']!==$source['mailbox_id'])throw new \RuntimeException('Configured source Drafts folder changed.');$this->reader->assertSource($uid,$source,$fence);}
    private function recipients(array $rows,int $type):array {return array_map(static function(array $row)use($type):Recipient {$r=new Recipient();$r->setType($type);$r->setEmail($row['email']);$r->setLabel($row['name']);return $r;},$rows);}
    public function copy(string $uid,array $approved,string $key,object $fence):array {
        return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function(\OCA\Mail\Drafts\DraftScope $scope)use($uid,$approved,$key,$fence):array {
            $old=$this->outcome($uid,$key,$fence);if($old!==null)return $old;
            $s=$approved['source'];$target=$approved['target'];$fresh=$this->prepare($uid,$s['message_id'],$target['account_id'],$target['alias_id'],$fence);if($fresh['preview']!==$approved)throw new \RuntimeException('Approved draft content or sender changed.');$c=$fresh['composition'];
            $fence->checkpoint(['source_tool'=>'mail_import_draft','content_source'=>$s,'target_account_id'=>$target['account_id'],'target_alias_id'=>$target['alias_id'],'native_copy_key'=>$key]);
            $this->assertDraftSource($uid,$s,$fence);$this->target($uid,$target['account_id'],$target['alias_id'],$fence);$scope->markWrite();$row=['operation_key'=>$key,'user_id'=>$uid,'approval_digest'=>$key,'state'=>'prepared','source_refs'=>json_encode($s,JSON_THROW_ON_ERROR),'target_account_id'=>$target['account_id'],'created_at'=>time()];$q=$this->db->getQueryBuilder();$q->insert('mail_mcp_draft_copies');foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));$q->executeStatement();
            // Each ID belongs only to this copy. Never remove snapshots after an uncertain association.
            $staged=[];foreach($c['attachments'] as $a){$this->assertDraftSource($uid,$s,$fence);$this->target($uid,$target['account_id'],$target['alias_id'],$fence);$local=$this->attachments->addFileFromString($uid,$a['filename'],$a['mime_type'],$a['bytes'],$a['content_id'],$a['disposition']);$staged[]=(int)$local->getId();$fence->checkpoint(['staged'=>$staged]);}
            $this->assertDraftSource($uid,$s,$fence);if($this->target($uid,$target['account_id'],$target['alias_id'],$fence)!==$target)throw new \RuntimeException('Target sender changed.');
            $fence->checkpoint(['native_write_started'=>true]);$scope->assertOwned();$this->db->beginTransaction();
            try{
                $draft=new LocalMessage();$draft->setAccountId($target['account_id']);$draft->setAliasId($target['alias_id']);$draft->setType(LocalMessage::TYPE_DRAFT);$draft->setMcpDraftOnly(true);$draft->setFailed(true);$draft->setSendAt(null);$draft->setUpdatedAt(time());$draft->setSubject($c['subject']);$draft->setBodyPlain($c['has_plain']?$c['body_plain']:null);$draft->setBodyHtml($c['has_html']?$c['body_html']:null);$draft->setHtml($c['has_html']);$draft->setEditorBody($c['has_html']?$c['body_html']:$c['body_plain']);$draft->setInReplyToMessageId($c['reply_to']);
                $this->assertDraftSource($uid,$s,$fence);$this->target($uid,$target['account_id'],$target['alias_id'],$fence);$draft=$this->messages->saveWithRecipients($draft,$this->recipients($c['to'],Recipient::TYPE_TO),$this->recipients($c['cc'],Recipient::TYPE_CC),$this->recipients($c['bcc'],Recipient::TYPE_BCC));$id=(int)$draft->getId();
                if($staged!==[]){$this->assertDraftSource($uid,$s,$fence);$this->target($uid,$target['account_id'],$target['alias_id'],$fence);$this->links->saveLocalMessageAttachments($uid,$id,$staged);}
                $this->assertDraftSource($uid,$s,$fence);if($this->target($uid,$target['account_id'],$target['alias_id'],$fence)!==$target)throw new \RuntimeException('Target sender changed.');
                $q=$this->db->getQueryBuilder();$q->update('mail_mcp_draft_copies')->set('state',$q->createNamedParameter('succeeded'))->set('local_draft_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('operation_key',$q->createNamedParameter($key)))->executeStatement();$fence->checkpoint(['draft_id'=>$id,'local_completed'=>true]);$scope->assertOwned();
            }catch(\Throwable $error){try{$this->db->rollBack();}catch(\Throwable){\OCA\Mail\Exception\ContentCommitUnknown::discard($this->db);}$fence->checkpoint(['draft_id'=>null,'local_completed'=>false]);throw new \RuntimeException('Protected draft persistence did not complete.',0,$error);}
            try{$this->db->commit();}catch(\Throwable){\OCA\Mail\Exception\ContentCommitUnknown::discard($this->db);}
            return ['state'=>'succeeded','draft_id'=>$id,'source_retained'=>true,'replayed'=>false,'safe_to_retry'=>false];
        });
    }
}
