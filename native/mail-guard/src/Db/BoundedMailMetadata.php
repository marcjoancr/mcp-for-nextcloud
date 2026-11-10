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

    /** @return array{items:list<array>,next_before_id:?int} */
    public function outbox(string $uid,?int $accountId,int $limit,?int $beforeId):array {
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
        if($accountId!==null)$q->andWhere($q->expr()->eq('l.account_id',$q->createNamedParameter($accountId,IQueryBuilder::PARAM_INT)));
        if($beforeId!==null)$q->andWhere($q->expr()->lt('l.id',$q->createNamedParameter($beforeId,IQueryBuilder::PARAM_INT)));
        $rows=$this->rows($q);$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
        $items=[];
        foreach($rows as$row){$subject=self::subject($row);$items[]=[
            'id'=>(int)$row['id'],'account_id'=>(int)$row['account_id'],'subject'=>$subject['subject'],'subject_truncated'=>$subject['subject_truncated'],
            'failed'=>self::boolValue($row['failed']??null),'send_at'=>self::nullableInt($row['send_at']??null),'updated_at'=>self::nullableInt($row['updated_at']??null),
        ];}
        return ['items'=>$items,'next_before_id'=>$hasMore&&$items!==[]?$items[array_key_last($items)]['id']:null];
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
