<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCA\Mail\Account;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Exact native tag adapters shared by UI, sync, related-data and classification. */
trait ExactTagMapper {
    private ?TagSnapshotVersion $tagBatch=null;
    public function captureTagSnapshot(Account $account,int $mailbox,string $generation):TagSnapshotVersion {$v=$this->exactAssociations()->capture($account->getUserId(),$mailbox,$generation);if($v->profile!==TagProfile::account($account->getMailAccount()))throw new \RuntimeException('Native connection identity is stale.');return $v;}
    public function beginTagBatch(Account $account,array $messages):void {
        if($this->tagBatch!==null)throw new \RuntimeException('Tag snapshot batch already active.');if($messages===[])return;
        $v=$messages[0]->getTagSnapshotVersion();if($v===null)throw new \RuntimeException('Only freshly fetched messages may establish tag cache.');
        foreach($messages as$m)if($m->getTagSnapshotVersion()!==$v||$m->getMailboxId()!==$v->mailbox||$m->getTagSnapshotGeneration()!==$v->generation||$account->getUserId()!==$v->owner)throw new \RuntimeException('Mixed native tag snapshot batch.');
        $this->exactAssociations()->claim($v);$this->tagBatch=$v;
    }
    public function finishTagBatch(bool $committed):void {if($this->tagBatch!==null){if($committed)$this->tagBatch->committed();else $this->tagBatch->aborted();$this->tagBatch=null;}}
    public function prepareTagWrite(Account $account,Mailbox $mailbox,array $messages):void {
        if($messages===[])throw new \OCA\Mail\Exception\ServiceException('Empty exact tag target.');$v=null;
        foreach($messages as$m)if(($next=$m->getTagSnapshotVersion())!==null){if($v!==null&&$next!==$v)throw new \OCA\Mail\Exception\ServiceException('Mixed native tag snapshots.');$v=$next;}
        $g=$messages[0]->getTagCacheGeneration();if(!is_string($g))throw new \OCA\Mail\Exception\ServiceException('Unknown native tag generation.');
        if($v===null)$v=$this->captureTagSnapshot($account,$mailbox->getId(),$g);
        if($v->owner!==$account->getUserId()||$v->mailbox!==$mailbox->getId()||$v->generation!==$g)throw new \OCA\Mail\Exception\ServiceException('Native tag snapshot scope changed.');
        foreach($messages as$m){$this->exactAssociations()->assertIdentity($account->getUserId(),$m->getId(),$mailbox->getId(),$m->getUid(),$g);$m->setTagSnapshotVersion($v);}$this->exactAssociations()->assertCurrent($v);
    }
    public function assertTagVersion(Message $message):void {if(($v=$message->getTagSnapshotVersion())!==null)$this->exactAssociations()->assertCurrent($v);}
    public function exactAssociations():ExactTagAssociations {return new ExactTagAssociations($this->db);}
    private function ownedSnapshotTag(Tag $tag,string $owner):Tag {
        if($tag->getUserId()!==$owner)throw new \RuntimeException('Native tag owner changed.');
        $tag=clone $tag;$tag->setImapLabel(\OCA\Mail\IMAP\TagKeywords::canonical($tag->getImapLabel()));
        try{return $this->getTagByImapLabel($tag->getImapLabel(),$owner);}catch(DoesNotExistException){$copy=clone $tag;$copy->setUserId($owner);return $this->insert($copy);}
    }
    public function replaceSnapshot(Account $account,Message $message,bool $initial=false):void {
        $generation=$message->getTagSnapshotGeneration();if($generation===null)return;$metadata=[];
        foreach($message->getTags()as$tag){$native=$this->ownedSnapshotTag($tag,$account->getUserId());$metadata[(int)$native->getId()]=$native;}$ids=array_keys($metadata);
        $id=$this->exactAssociations()->replace($account->getUserId(),$message->getMailboxId(),$message->getUid(),$message->getId(),$generation,$ids,$initial,$this->tagBatch);
        $message->setId($id);$message->setTags(array_values($metadata));$message->setTagCacheGeneration($generation);$message->setTagsVerified(true);
    }
    private function tagMessageExact(Tag $tag,Message $message,string $owner):void {
        if($message->getId()===null||!$this->exactAssociations()->verified($owner,$message->getId()))throw new \RuntimeException('Exact tag cache requires native synchronization.');
        $tag=$this->ownedSnapshotTag($tag,$owner);$this->exactAssociations()->set($owner,$message->getId(),$tag->getId(),true,$message->getTagSnapshotVersion());if($tag->getImapLabel()==='$label1')$message->setFlagImportant(true);
    }
    private function untagMessageExact(Tag $tag,Message $message):void {
        if($message->getId()===null)throw new \RuntimeException('Exact cached message unavailable.');try{$tag=$this->getTagByImapLabel(\OCA\Mail\IMAP\TagKeywords::canonical($tag->getImapLabel()),$tag->getUserId());}catch(DoesNotExistException){return;}$this->exactAssociations()->set($tag->getUserId(),$message->getId(),$tag->getId(),false,$message->getTagSnapshotVersion());if($tag->getImapLabel()==='$label1')$message->setFlagImportant(false);
    }
    private function allExactTags(array $messages,string $owner):array {
        $ids=[];foreach($messages as$m)if($m->getId()!==null)$ids[]=$m->getId();$verified=[];foreach(array_chunk(array_values(array_unique($ids)),1000)as$chunk)$verified=array_merge($verified,$this->exactAssociations()->verifiedIds($owner,$chunk));$ids=$verified;if($ids===[])return [];
        $out=[];foreach(array_chunk(array_unique($ids),500)as$chunk){$q=$this->db->getQueryBuilder();$q->select('t.*','mt.cached_message_id')->from('mail_tags','t')->join('t','mail_message_tags','mt',$q->expr()->eq('t.id','mt.tag_id'))
            ->where($q->expr()->eq('t.user_id',$q->createNamedParameter($owner)))->andWhere($q->expr()->in('mt.cached_message_id',$q->createNamedParameter($chunk,IQueryBuilder::PARAM_INT_ARRAY)));
            $r=$q->executeQuery();try{foreach($r->fetchAll()as$row){$id=(int)$row['cached_message_id'];unset($row['cached_message_id']);$out[$id][]=Tag::fromRow($row);}}finally{$r->closeCursor();}}
        return $out;
    }
    private function exactTaggedIds(array $messages,string $owner,string $label):array {$ids=[];foreach($this->allExactTags($messages,$owner)as$id=>$tags)foreach($tags as$t)if($t->getImapLabel()===$label){$ids[]=(int)$id;break;}return $ids;}
}
