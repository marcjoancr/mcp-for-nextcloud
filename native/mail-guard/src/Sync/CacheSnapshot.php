<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sync;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Mail\Db\{ExactTagAssociations,TagProfile};
/** Bounded current-cache proof. Does not instantiate a client, synchronize, queue or modify a row. */
final class CacheSnapshot {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    private function rows(object $q):array {$r=$q->executeQuery();try{return $r->fetchAll();}finally{$r->closeCursor();}}
    public function requireStorage():void {
        (new ExactTagAssociations($this->db,$this->config))->requireStorage();$schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');$boxes=$schema->getTable($prefix.'mail_mailboxes');
        if(!$boxes->hasColumn('refresh_snapshot')||$boxes->getColumn('refresh_snapshot')->getLength()!==512||$boxes->getColumn('refresh_snapshot')->getNotnull())throw new \RuntimeException('Refresh migration unavailable.');
        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL)foreach(['mail_accounts','mail_recipients']as$table)if(strtolower((string)($schema->getTable($prefix.$table)->getOptions()['engine']??''))!=='innodb')throw new \RuntimeException('Every refresh cache table must be transactional.');
    }
    public function capture(string $owner,int $account,int $mailbox,bool $lock=false):array {
        if($lock&&!$this->db->inTransaction())throw new \LogicException('Refresh locking read requires a transaction.');
        $q=$this->db->getQueryBuilder();$q->select('id','user_id','inbound_host','inbound_port','inbound_ssl_mode','inbound_user','auth_method','protocol','email')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($owner)));if($lock)$q->forUpdate();$a=$this->rows($q);if(count($a)!==1)throw new \RuntimeException('Owned refresh account unavailable.');
        $q=$this->db->getQueryBuilder();$q->select('id','account_id','name','selectable','sync_new_token','sync_changed_token','sync_vanished_token','tag_cache_revision','refresh_snapshot')->from('mail_mailboxes')->where($q->expr()->eq('id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)));if($lock)$q->forUpdate();$b=$this->rows($q);if(count($b)!==1||!(bool)$b[0]['selectable'])throw new \RuntimeException('Owned selectable refresh mailbox unavailable.');$b=$b[0];
        $q=$this->db->getQueryBuilder();$q->select('id','uid','tag_cache_generation','tag_cache_profile','flag_answered','flag_deleted','flag_draft','flag_flagged','flag_seen','flag_forwarded','flag_junk','flag_notjunk','flag_important','flag_mdnsent')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT)))->orderBy('id','ASC')->setMaxResults(RefreshBudget::MAX_MESSAGES+1);if($lock)$q->forUpdate();$messages=$this->rows($q);if(count($messages)>RefreshBudget::MAX_MESSAGES)throw new \RuntimeException('Cached mailbox exceeds refresh budget.');
        foreach($messages as&$m)foreach($m as$k=>&$v)if($v!==null&&!in_array($k,['tag_cache_generation','tag_cache_profile'],true))$v=(int)$v;unset($m,$v);
        $uids=array_column($messages,'uid');if(count(array_unique($uids))!==count($uids))throw new \RuntimeException('Duplicate cached refresh UID.');foreach($uids as$uid)if($uid<1||$uid>4294967295)throw new \RuntimeException('Unsupported cached refresh UID.');
        $profile=TagProfile::row($a[0]);$generation=ExactTagAssociations::generation($b);$reset=$generation===null;foreach($messages as$m)if($m['tag_cache_generation']!==$generation||$m['tag_cache_profile']!==$profile)$reset=true;
        $proof=['owner'=>$owner,'account_id'=>$account,'mailbox_id'=>$mailbox,'mailbox'=>$b['name'],'profile'=>$profile,'generation'=>$generation,'tokens'=>[$b['sync_new_token'],$b['sync_changed_token'],$b['sync_vanished_token']],'tag_revision'=>(int)$b['tag_cache_revision'],'messages'=>$messages];
        return ['fingerprint'=>hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR)),'profile'=>$profile,'generation'=>$generation,'mailbox'=>$b['name'],'needs_reset'=>$reset,'messages'=>$messages,'marker'=>$b['refresh_snapshot']];
    }
    public function freshness(string $owner,int $account,int $mailbox):array {
        try{$s=$this->capture($owner,$account,$mailbox);$marker=is_string($s['marker'])?json_decode($s['marker'],true,16,JSON_THROW_ON_ERROR):null;if(!is_array($marker))return ['status'=>'unknown'];if(($marker['fingerprint']??null)!==$s['fingerprint'])return ['status'=>'stale'];if(!is_int($marker['observed_at']??null)||($marker['generation']??null)!==$s['generation'])return ['status'=>'unknown'];return ['status'=>'snapshot','observed_at'=>$marker['observed_at'],'generation'=>$marker['generation'],'threads_state'=>'native_rebuild_pending'];}catch(\Throwable){return ['status'=>'unknown'];}
    }
}
