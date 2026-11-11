<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Content\ContentSource;
use OCA\Mail\Lifecycle\AccountVisibility;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
final class GuardedDraftSearch {
    public function __construct(private IDBConnection $db,private ContentSource $sources){}
    public function search(string $uid,int $accountId,?string $subject,int $limit,?int $beforeId,object $fence):array {
        $this->sources->primary();if($accountId<1||$limit<1||$limit>50||($beforeId!==null&&$beforeId<1)||($subject!==null&&(mb_strlen($subject)>256||preg_match('/[\x00-\x1f\x7f]/',$subject))))throw new \InvalidArgumentException('Invalid cached draft search.');
        (new AccountVisibility($this->db))->assertVisible($uid,$accountId);
        $q=$this->db->getQueryBuilder();$q->select('drafts_mailbox_id')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)));(new AccountVisibility($this->db))->excludePending($q,'id');$r=$q->executeQuery();try{$id=$r->fetchOne();}finally{$r->closeCursor();}if(!$id)throw new \RuntimeException('Configured Drafts mailbox unavailable.');$box=$this->sources->mailbox($uid,(int)$id,$fence);
        $q=$this->db->getQueryBuilder();$q->select('m.id','m.uid','m.sent_at')->selectAlias($q->func()->substring('m.subject',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),$q->createNamedParameter(257,IQueryBuilder::PARAM_INT)),'subject_sample')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->innerJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('a.id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('m.mailbox_id',$q->createNamedParameter((int)$id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('b.id','a.drafts_mailbox_id'))->andWhere($q->expr()->eq('b.selectable',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)))->andWhere($q->expr()->eq('m.flag_draft',$q->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)))->andWhere($q->expr()->eq('m.flag_deleted',$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL)))->andWhere($q->expr()->eq('m.tag_cache_generation',$q->createNamedParameter($box['generation'])))->andWhere($q->expr()->eq('m.tag_cache_profile',$q->createNamedParameter($box['profile'])))->orderBy('m.id','DESC')->setMaxResults($limit+1);
        (new AccountVisibility($this->db))->excludePending($q,'a.id');if($beforeId!==null)$q->andWhere($q->expr()->lt('m.id',$q->createNamedParameter($beforeId,IQueryBuilder::PARAM_INT)));if($subject!==null)$q->andWhere($q->expr()->iLike('m.subject',$q->createNamedParameter('%'.$this->db->escapeLikeParameter($subject).'%')));
        $r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}$more=count($rows)>$limit;if($more)array_pop($rows);$items=[];foreach($rows as $row){$fence->authorize($uid,$accountId,(int)$id,(int)$row['id']);$items[]=['message_id'=>(int)$row['id'],'account_id'=>$accountId,'mailbox_id'=>(int)$id,'subject'=>mb_substr((string)$row['subject_sample'],0,256),'subject_truncated'=>mb_strlen((string)$row['subject_sample'])>256,'sent_at'=>$row['sent_at']===null?null:(int)$row['sent_at'],'draft'=>true];}
        if($this->sources->mailbox($uid,(int)$id,$fence)!==$box)throw new \RuntimeException('Draft mailbox changed.');
        return ['items'=>$items,'next_before_id'=>$more?$items[array_key_last($items)]['message_id']:null,'source'=>'native_cache','coverage'=>'cached_configured_drafts_only','deleted'=>'excluded','live_draft_proof'=>'required_at_import'];
    }
}
