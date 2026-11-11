<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Content\{ContentSource,BodyIndexPolicy};
use OCA\Mail\Drafts\DraftCoordinator;
use OCA\Mail\Lifecycle\AccountVisibility;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Explicit complete-only derived text. No provider SEARCH, implicit crawl or attachment text. */
final class GuardedBodyIndex {
    public const CONTRACT='mcp-mail-pg-body-index-v1';
    public function __construct(private IDBConnection $db,private IConfig $config,private GuardedContentReader $reader,private ContentSource $sources){}
    private function ids(array $ids):void {if($ids===[]||count($ids)>20||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new \InvalidArgumentException('Select one to twenty distinct native IDs.');foreach($ids as $id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Invalid native ID.');}
    private function readRows(object $q):array {$r=$q->executeQuery();try{return $r->fetchAll();}finally{$r->closeCursor();}}
    private function prepare(string $uid,array $ids,object $fence):array {
        $this->sources->primary();$this->ids($ids);$sources=[];
        // Authorize the entire explicit selection before reading any private content.
        foreach($ids as $id){$s=ContentSource::stable($this->sources->message($uid,$id,$fence));$fence->authorize($uid,$s['account_id'],$s['mailbox_id'],$id);$sources[]=$s;}
        $items=[];$texts=[];
        foreach($sources as $source){$read=null;try{$read=$this->reader->readIndexText($uid,$source['message_id'],$fence);}catch(\Throwable){$this->reader->assertSource($uid,$source,$fence);}
            $policy=$read===null?['state'=>'refused_content','text'=>'']:BodyIndexPolicy::extract($read);
            if($read!==null&&ContentSource::stable($read['source'])!==$source)throw new \RuntimeException('Indexed source changed.');
            if($policy['state']==='complete'){
                // PostgreSQL drops lexemes >=2047 bytes and positions >16383. Refuse instead.
                $r=$this->db->executeQuery("SELECT COALESCE(MAX(octet_length(token)),0) AS max_token, COUNT(*) AS tokens FROM ts_parse('default', ?)",[$policy['text']]);try{$tokens=$r->fetch();}finally{$r->closeCursor();}
                if((int)$tokens['max_token']>=2047||(int)$tokens['tokens']>16383)$policy=['state'=>'refused_lexeme','text'=>''];
            }
            $items[]=['source'=>$source,'coverage_state'=>$policy['state'],'content_revision'=>$read['content_revision']??'','text_bytes'=>strlen($policy['text']),'text_sha256'=>$policy['state']==='complete'?hash('sha256',$policy['text']):null];$texts[]=$policy['text'];
        }
        return ['plan'=>['policy'=>BodyIndexPolicy::VERSION,'coverage'=>'supported_text_parts_only','items'=>$items],'texts'=>$texts];
    }
    public function preview(string $uid,array $ids,object $fence):array {return $this->prepare($uid,$ids,$fence)['plan'];}
    public function index(string $uid,array $approved,object $fence):array {
        return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function(\OCA\Mail\Drafts\DraftScope $scope)use($uid,$approved,$fence):array {
            $ids=array_map(static fn(array $item):int=>$item['source']['message_id'],$approved['items']??[]);$fresh=$this->prepare($uid,$ids,$fence);
            if($fresh['plan']!==$approved)throw new \RuntimeException('Approved indexed content changed.');
            $fence->checkpoint(['source_tool'=>'mail_index_messages','content_sources'=>array_column($approved['items'],'source'),'index_policy'=>BodyIndexPolicy::VERSION]);
            $scope->markWrite();if($this->db->inTransaction())throw new \RuntimeException('Index requires an independent transaction.');$this->db->beginTransaction();
            try{foreach($approved['items'] as $n=>$item){$s=$item['source'];$this->reader->assertSource($uid,$s,$fence);
                $q=$this->db->getQueryBuilder();$q->delete('mail_mcp_body_index')->where($q->expr()->eq('message_id',$q->createNamedParameter($s['message_id'],IQueryBuilder::PARAM_INT)))->executeStatement();
                $row=['message_id'=>$s['message_id'],'user_id'=>$uid,'account_id'=>$s['account_id'],'mailbox_id'=>$s['mailbox_id'],'imap_uid'=>$s['uid'],'generation'=>$s['uidvalidity'],'profile'=>$s['profile_revision'],'auth_revision'=>$s['auth_revision'],'content_revision'=>$item['content_revision'],'body_text'=>$fresh['texts'][$n],'policy_version'=>BodyIndexPolicy::VERSION,'coverage_state'=>$item['coverage_state'],'indexed_at'=>time()];
                $this->reader->assertSource($uid,$s,$fence);$q=$this->db->getQueryBuilder();$q->insert('mail_mcp_body_index');foreach($row as $key=>$value)$q->setValue($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));$q->executeStatement();
            }foreach($approved['items'] as $item)$this->reader->assertSource($uid,$item['source'],$fence);$fence->checkpoint(['index_completed'=>true]);$scope->assertOwned();}
            catch(\Throwable $error){try{$this->db->rollBack();}catch(\Throwable){\OCA\Mail\Exception\ContentCommitUnknown::discard($this->db);}$fence->checkpoint(['index_completed'=>false]);throw new \RuntimeException('Index transaction did not complete.',0,$error);}
            try{$this->db->commit();}catch(\Throwable){\OCA\Mail\Exception\ContentCommitUnknown::discard($this->db);}
            return ['policy'=>BodyIndexPolicy::VERSION,'items'=>array_map(static fn(array $item):array=>['message_id'=>$item['source']['message_id'],'coverage_state'=>$item['coverage_state']],$approved['items']),'coverage'=>'supported_text_parts_only'];
        });
    }
    private function boxes(string $uid,array $ids,object $fence):array {$this->ids($ids);$rows=[];foreach($ids as $id)$rows[]=$this->sources->mailbox($uid,$id,$fence);return $rows;}
    private function query(string $uid,array $boxes):object {
        $q=$this->db->getQueryBuilder();$q->from('mail_mcp_body_index','i')->innerJoin('i','mail_messages','m',$q->expr()->eq('i.message_id','m.id'))->innerJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->innerJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('i.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('i.account_id','a.id'))->andWhere($q->expr()->eq('i.mailbox_id','b.id'))->andWhere($q->expr()->eq('i.imap_uid',$q->createFunction('CAST(m.uid AS VARCHAR)')))->andWhere($q->expr()->eq('i.generation','m.tag_cache_generation'))->andWhere($q->expr()->eq('i.profile','m.tag_cache_profile'))->andWhere($q->expr()->eq('i.auth_revision','a.mcp_auth_revision'))->andWhere($q->expr()->eq('i.policy_version',$q->createNamedParameter(BodyIndexPolicy::VERSION)));
        $allowed=[];foreach($boxes as $box)$allowed[]=$q->expr()->andX($q->expr()->eq('i.mailbox_id',$q->createNamedParameter($box['mailbox_id'],IQueryBuilder::PARAM_INT)),$q->expr()->eq('i.generation',$q->createNamedParameter($box['generation'])),$q->expr()->eq('i.profile',$q->createNamedParameter($box['profile'])),$q->expr()->eq('i.auth_revision',$q->createNamedParameter($box['auth_revision'],IQueryBuilder::PARAM_INT)));
        $q->andWhere($q->expr()->orX(...$allowed));(new AccountVisibility($this->db))->excludePending($q,'a.id');return $q;
    }
    private function unchanged(string $uid,array $boxes,object $fence):void {foreach($boxes as $box)if($this->sources->mailbox($uid,$box['mailbox_id'],$fence)!==$box)throw new \RuntimeException('Indexed mailbox changed.');}
    public function search(string $uid,array $mailboxIds,string $query,int $limit,?int $beforeId,object $fence):array {
        if($limit<1||$limit>50||($beforeId!==null&&$beforeId<1)||trim($query)===''||mb_strlen($query)>256||strlen($query)>1024||preg_match('/[\x00-\x1f\x7f]/',$query))throw new \InvalidArgumentException('Invalid bounded index search.');
        $boxes=$this->boxes($uid,$mailboxIds,$fence);$q=$this->query($uid,$boxes);
        $q->select('i.message_id','i.account_id','i.mailbox_id','i.indexed_at')->selectAlias($q->func()->substring('m.subject',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),$q->createNamedParameter(257,IQueryBuilder::PARAM_INT)),'subject_sample')
            ->andWhere($q->expr()->eq('i.coverage_state',$q->createNamedParameter('complete')))->andWhere($q->createFunction("to_tsvector('simple'::regconfig, i.body_text) @@ plainto_tsquery('simple'::regconfig, ".$q->createNamedParameter($query).')'))->orderBy('i.message_id','DESC')->setMaxResults($limit+1);
        if($beforeId!==null)$q->andWhere($q->expr()->lt('i.message_id',$q->createNamedParameter($beforeId,IQueryBuilder::PARAM_INT)));
        $rows=$this->readRows($q);$more=count($rows)>$limit;if($more)array_pop($rows);$items=[];
        foreach($rows as $row){$fence->authorize($uid,(int)$row['account_id'],(int)$row['mailbox_id'],(int)$row['message_id']);$items[]=['message_id'=>(int)$row['message_id'],'account_id'=>(int)$row['account_id'],'mailbox_id'=>(int)$row['mailbox_id'],'subject'=>mb_substr((string)$row['subject_sample'],0,256),'subject_truncated'=>mb_strlen((string)$row['subject_sample'])>256,'indexed_at'=>(int)$row['indexed_at']];}
        $this->unchanged($uid,$boxes,$fence);
        return ['items'=>$items,'next_before_id'=>$more?$items[array_key_last($items)]['message_id']:null,'semantics'=>'simple_lexical_all_terms','coverage'=>$this->coverage($uid,$mailboxIds,$fence)];
    }
    public function coverage(string $uid,array $mailboxIds,object $fence):array {
        $boxes=$this->boxes($uid,$mailboxIds,$fence);$q=$this->query($uid,$boxes);$q->select('i.coverage_state')->selectAlias($q->func()->count('i.message_id'),'total')->groupBy('i.coverage_state');$counts=[];foreach($this->readRows($q) as $row)$counts[$row['coverage_state']]=(int)$row['total'];$this->unchanged($uid,$boxes,$fence);
        return ['policy'=>BodyIndexPolicy::VERSION,'scope'=>'explicitly_indexed_current_messages','complete'=>$counts['complete']??0,'refused'=>array_sum($counts)-($counts['complete']??0),'mailbox_complete'=>false,'unindexed_or_stale'=>'not_searched'];
    }
}
