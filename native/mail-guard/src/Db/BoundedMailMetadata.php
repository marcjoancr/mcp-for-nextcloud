<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;

use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Small, owner-scoped metadata projections for bounded MCP reads. */
final class BoundedMailMetadata {
    private const MAX_PAGE=50;

    public function __construct(private IDBConnection $db,private IConfig $config) {}

    private function requirePrimary():void {
        if(!in_array($this->db->getDatabaseProvider(),[IDBConnection::PLATFORM_MYSQL,IDBConnection::PLATFORM_POSTGRES],true))throw new \RuntimeException('Bounded Mail metadata requires a qualified primary database.');
        if($this->config->getSystemValue('dbsharding',[])!==[]||$this->config->getSystemValue('dbreplica',[])!==[])throw new \RuntimeException('Bounded Mail metadata requires an unsharded primary database.');
        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_POSTGRES&&!PgDirectConnection::verified($this->db,$this->config))throw new \RuntimeException('Bounded Mail metadata requires a directly connected PostgreSQL primary.');
    }

    private function rows(object $query):array {
        $result=$query->executeQuery();
        try{return $result->fetchAll();}finally{$result->closeCursor();}
    }

    private static function positiveId(mixed $id):bool {return is_int($id)&&$id>0;}
    private static function boolValue(mixed $value):?bool {
        if($value===null||$value==='')return null;
        if(in_array($value,[true,1,'1','t','true'],true))return true;
        if(in_array($value,[false,0,'0','f','false'],true))return false;
        throw new \RuntimeException('Unexpected native Mail metadata boolean.');
    }
    private static function subject(array $row):array {
        $sample=$row['subject_sample']??null;
        if($sample!==null&&!is_string($sample))$sample=(string)$sample;
        $truncated=is_string($sample)&&mb_strlen($sample,'UTF-8')>256;
        return ['subject'=>$truncated?mb_substr($sample,0,256,'UTF-8'):$sample,'subject_truncated'=>$truncated];
    }
    private static function nullableInt(mixed $value):?int {return $value===null||$value===''?null:(int)$value;}

    /** Existence-only admission for account/alias deletion; never materialize another account's draft bodies. */
    public function hasLocalMessages(string $uid,int $account,int $type,?int $alias=null):bool {
        if($uid===''||$account<1||($alias!==null&&$alias<1))throw new \InvalidArgumentException('Invalid local message target.');
        $this->requirePrimary();$q=$this->db->getQueryBuilder();
        $q->select('l.id')->from('mail_local_messages','l')->leftJoin('l','mail_accounts','a',$q->expr()->eq('l.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('a.id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('l.type',$q->createNamedParameter($type,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        if($alias!==null)$q->andWhere($q->expr()->eq('l.alias_id',$q->createNamedParameter($alias,IQueryBuilder::PARAM_INT)));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        return $this->rows($q)!==[];
    }

    /** One scalar association proves a tag is already visible through an admitted owned message. */
    public function tagSource(string $uid,int $tag,array $accounts,array $mailboxes):?array {
        self::ids($accounts,200);self::ids($mailboxes,200);
        if($uid===''||$tag<1)throw new \InvalidArgumentException('Invalid tag visibility request.');
        if($accounts===[]&&$mailboxes===[])return null;
        $this->requirePrimary();$q=$this->db->getQueryBuilder();
        $q->select('b.account_id')->selectAlias('b.id','mailbox_id')->from('mail_message_tags','mt')
            ->leftJoin('mt','mail_tags','t',$q->expr()->eq('mt.tag_id','t.id'))
            ->leftJoin('mt','mail_messages','m',$q->expr()->eq('mt.cached_message_id','m.id'))
            ->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))
            ->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('t.user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('t.id',$q->createNamedParameter($tag,IQueryBuilder::PARAM_INT)))->orderBy('m.id','ASC')->setMaxResults(1);
        $allowed=[];
        if($accounts!==[])$allowed[]=$q->expr()->in('a.id',$q->createNamedParameter($accounts,IQueryBuilder::PARAM_INT_ARRAY));
        if($mailboxes!==[])$allowed[]=$q->expr()->in('b.id',$q->createNamedParameter($mailboxes,IQueryBuilder::PARAM_INT_ARRAY));
        $q->andWhere($q->expr()->orX(...$allowed));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $rows=$this->rows($q);return $rows===[]?null:['account_id'=>(int)$rows[0]['account_id'],'mailbox_id'=>(int)$rows[0]['mailbox_id']];
    }

    /** Owner-only picker metadata; no saved scope, credentials, messages or network. Sentinel bounds precede fetchAll. */
    public function resourceInventory(string $uid):array {
        if($uid==='')throw new \InvalidArgumentException('Invalid owner.');
        $this->requirePrimary();$q=$this->db->getQueryBuilder();
        $q->select('a.id','a.name')->from('mail_accounts','a')->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->orderBy('a.id','ASC')->setMaxResults(1001);
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $accounts=$this->rows($q);if(count($accounts)>1000)throw new \RuntimeException('Native Mail resource inventory exceeds its bound.');
        $items=[];foreach($accounts as $a)$items[]=['id'=>'account:'.$a['id'],'name'=>(string)$a['name'],'path'=>null,'kind'=>'account','excluded'=>false];
        $q=$this->db->getQueryBuilder();$q->select('b.id','b.name')->selectAlias('a.name','account_name')->from('mail_mailboxes','b')->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->orderBy('b.id','ASC')->setMaxResults(10001-count($items));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $boxes=$this->rows($q);if(count($boxes)+count($items)>10000)throw new \RuntimeException('Native Mail resource inventory exceeds its bound.');
        foreach($boxes as $b)$items[]=['id'=>'mailbox:'.$b['id'],'name'=>(string)$b['name'],'path'=>(string)$b['account_name'],'kind'=>'mailbox','excluded'=>false];
        return $items;
    }

    /** @return array{items:list<array>,next_before_id:?int} */
    public function outbox(string $uid,?int $accountId,int $limit,?int $beforeId,?array $allowedAccounts=null):array {
        if($uid===''||$limit<1||$limit>self::MAX_PAGE||($accountId!==null&&$accountId<1)||($beforeId!==null&&$beforeId<1))throw new \InvalidArgumentException('Invalid bounded Mail outbox request.');
        $this->requirePrimary();
        $q=$this->db->getQueryBuilder();
        $q->select('l.id','l.account_id','l.failed','l.send_at','l.updated_at')
            ->selectAlias($q->func()->substring('l.subject',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),$q->createNamedParameter(257,IQueryBuilder::PARAM_INT)),'subject_sample')
            ->from('mail_local_messages','l')
            ->leftJoin('l','mail_accounts','a',$q->expr()->eq('l.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('l.type',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->orderBy('l.id','DESC')->setMaxResults($limit+1);
        if($allowedAccounts!==null){self::ids($allowedAccounts,200);if($allowedAccounts===[])return ['items'=>[],'next_before_id'=>null];$q->andWhere($q->expr()->in('a.id',$q->createNamedParameter($allowedAccounts,IQueryBuilder::PARAM_INT_ARRAY)));}
        if($accountId!==null)$q->andWhere($q->expr()->eq('l.account_id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)));
        if($beforeId!==null)$q->andWhere($q->expr()->lt('l.id',$q->createNamedParameter($beforeId,IQueryBuilder::PARAM_INT)));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $rows=$this->rows($q);$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
        $items=[];
        foreach($rows as$row){$subject=self::subject($row);$items[]=[
            'id'=>(int)$row['id'],'account_id'=>(int)$row['account_id'],'subject'=>$subject['subject'],'subject_truncated'=>$subject['subject_truncated'],
            'failed'=>self::boolValue($row['failed']??null),'send_at'=>self::nullableInt($row['send_at']??null),'updated_at'=>self::nullableInt($row['updated_at']??null),
        ];}
        return ['items'=>$items,'next_before_id'=>$hasMore&&$items!==[]?$items[array_key_last($items)]['id']:null];
    }

    private static function ids(array $ids,int $max=50):void {
        if(!array_is_list($ids)||count($ids)>$max||count(array_unique($ids))!==count($ids))throw new \InvalidArgumentException('Invalid resource bounds.');
        foreach($ids as $id)if(!self::positiveId($id))throw new \InvalidArgumentException('Invalid resource identity.');
    }
    /** Owner-only ID projection for admission before any body, subject, sender, or connection profile is read. */
    public function identities(string $uid,string $kind,array $ids):array {
        self::ids($ids);if($uid===''||!in_array($kind,['account','mailbox','message','draft'],true))throw new \InvalidArgumentException('Invalid identity request.');
        if($ids===[])return [];$this->requirePrimary();$q=$this->db->getQueryBuilder();
        if($kind==='account'){$q->select('a.id')->selectAlias('a.id','account_id')->from('mail_accounts','a');$id='a.id';}
        elseif($kind==='mailbox'){$q->select('b.id','b.account_id')->selectAlias('b.id','mailbox_id')->from('mail_mailboxes','b')->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'));$id='b.id';}
        elseif($kind==='message'){$q->select('m.id','m.mailbox_id','b.account_id')->from('mail_messages','m')->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'));$id='m.id';}
        else{$q->select('l.id','l.account_id')->from('mail_local_messages','l')->leftJoin('l','mail_accounts','a',$q->expr()->eq('l.account_id','a.id'));$id='l.id';}
        $q->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in($id,$q->createNamedParameter($ids,IQueryBuilder::PARAM_INT_ARRAY)))->orderBy($id,'ASC')->setMaxResults(50);
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'account_id'=>(int)$r['account_id'],'mailbox_id'=>(int)($r['mailbox_id']??0)],$this->rows($q));
    }

    /** Query only admitted thread IDs; cap includes one sentinel for the caller's 500-message refusal. */
    public function threadIds(string $uid,int $account,string $root,array $mailboxes):array {
        self::ids($mailboxes,200);if($mailboxes===[])return [];if($account<1||$root===''||strlen($root)>998)throw new \InvalidArgumentException('Invalid thread.');
        $this->requirePrimary();$q=$this->db->getQueryBuilder();
        $q->select('m.id')->from('mail_messages','m')->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('a.id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('m.thread_root_id',$q->createNamedParameter($root)))->andWhere($q->expr()->in('m.mailbox_id',$q->createNamedParameter($mailboxes,IQueryBuilder::PARAM_INT_ARRAY)))->orderBy('m.sent_at','DESC')->addOrderBy('m.id','DESC')->setMaxResults(501);
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        return array_map(static fn(array $r):int=>(int)$r['id'],$this->rows($q));
    }
    /** Bounded local draft IDs. All account predicates precede subject matching, pagination and body fetch. */
    public function draftIds(string $uid,int $type,?int $account,?string $subject,int $limit,int $offset,?array $allowedAccounts):array {
        if($limit<1||$limit>50||$offset<0||$offset>10000||($account!==null&&$account<1)||($subject!==null&&mb_strlen($subject)>256))throw new \InvalidArgumentException('Invalid draft page.');
        if($allowedAccounts!==null){self::ids($allowedAccounts,200);if($allowedAccounts===[])return ['ids'=>[],'has_more'=>false];}
        $this->requirePrimary();$q=$this->db->getQueryBuilder();
        $q->select('l.id')->from('mail_local_messages','l')->leftJoin('l','mail_accounts','a',$q->expr()->eq('l.account_id','a.id'))->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('l.type',$q->createNamedParameter($type,IQueryBuilder::PARAM_INT)))->orderBy('l.updated_at','DESC')->addOrderBy('l.id','ASC')->setMaxResults($limit+1)->setFirstResult($offset);
        if($allowedAccounts!==null)$q->andWhere($q->expr()->in('a.id',$q->createNamedParameter($allowedAccounts,IQueryBuilder::PARAM_INT_ARRAY)));
        if($account!==null)$q->andWhere($q->expr()->eq('a.id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)));
        if($subject!==null)$q->andWhere($q->expr()->iLike('l.subject',$q->createNamedParameter('%'.$this->db->escapeLikeParameter($subject).'%')));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $rows=$this->rows($q);$more=count($rows)>$limit;if($more)array_pop($rows);
        return ['ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$rows),'has_more'=>$more];
    }

    /** @return list<array> */
    public function cachedMessages(string $uid,array $ids):array {
        if($uid==='')throw new \InvalidArgumentException('Invalid bounded Mail cache request.');
        if($ids===[])return [];
        if(count($ids)>self::MAX_PAGE)throw new \InvalidArgumentException('Bounded Mail cache request exceeds its limit.');
        $seen=[];foreach($ids as$id){if(!self::positiveId($id)||isset($seen[$id]))throw new \InvalidArgumentException('Invalid bounded Mail cache identity.');$seen[$id]=true;}
        $this->requirePrimary();
        $q=$this->db->getQueryBuilder();
        $q->select('m.id','b.account_id','m.mailbox_id','m.sent_at','m.flag_seen','m.flag_flagged')
            ->selectAlias($q->func()->substring('m.subject',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT),$q->createNamedParameter(257,IQueryBuilder::PARAM_INT)),'subject_sample')
            ->from('mail_messages','m')->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))
            ->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->in('m.id',$q->createNamedParameter(array_keys($seen),IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('m.id','ASC')->setMaxResults(self::MAX_PAGE);
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'a.id');
        $rows=$this->rows($q);
        $items=[];
        foreach($rows as$row){$subject=self::subject($row);$items[]=[
            'id'=>(int)$row['id'],'account_id'=>(int)$row['account_id'],'mailbox_id'=>(int)$row['mailbox_id'],
            'subject'=>$subject['subject'],'subject_truncated'=>$subject['subject_truncated'],'sent_at'=>self::nullableInt($row['sent_at']??null),
            'seen'=>self::boolValue($row['flag_seen']??null),'flagged'=>self::boolValue($row['flag_flagged']??null),
        ];}
        return $items;
    }
}
