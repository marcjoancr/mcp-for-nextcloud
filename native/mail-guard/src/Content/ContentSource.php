<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
use OCA\Mail\Db\{TagProfile,ExactTagAssociations,PgDirectConnection};
use OCA\Mail\Lifecycle\AccountVisibility;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\{IDBConnection,IConfig};
/** Closed scalar metadata proofs. Never selects body, recipients, credentials or cached preview text. */
final class ContentSource {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    private function identity(string $uid,string $kind,int $id):array {
        $rows=(new \OCA\Mail\Db\BoundedMailMetadata($this->db,$this->config))->identities($uid,$kind,[$id]);
        if(count($rows)!==1)throw new \RuntimeException('Owned content source unavailable.');return $rows[0];
    }
    public function primary():void {
        if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES||$this->config->getSystemValue('dbreplica',[])!==[]||$this->config->getSystemValue('dbsharding',[])!==[]||!PgDirectConnection::verified($this->db,$this->config))throw new \RuntimeException('Content features require a direct PostgreSQL primary.');
    }
    public static function stable(array $source):array {return array_intersect_key($source,array_flip(['message_id','account_id','mailbox_id','uid','uidvalidity','profile_revision','auth_revision','mailbox_revision']));}
    public function message(string $uid,int $id,object $fence):array {
        $identity=$this->identity($uid,"message",$id);$fence->authorize($uid,$identity["account_id"],$identity["mailbox_id"],$id);
        if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES)throw new \RuntimeException('Qualified content storage is unavailable.');
        if($id<1||$uid==='')throw new \RuntimeException('Invalid content source.');
        $visibility=new AccountVisibility($this->db);$q=$this->db->getQueryBuilder();
        $q->select('m.id','m.uid','m.mailbox_id','m.tag_cache_generation','m.tag_cache_profile','b.account_id','b.name','b.selectable','b.sync_new_token','b.sync_changed_token','b.sync_vanished_token','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email','a.mcp_auth_revision','a.drafts_mailbox_id')
            ->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->innerJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('m.id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)));
        $visibility->excludePending($q,'a.id');$q->setMaxResults(2);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($rows)!==1)throw new \RuntimeException('Owned content source unavailable.');$row=$rows[0];$visibility->assertVisible($uid,(int)$row['account_id']);
        $generation=ExactTagAssociations::generation($row);$profile=TagProfile::row($row);
        if(!$row['selectable']||$generation===null||$generation!==$row['tag_cache_generation']||!hash_equals($profile,(string)$row['tag_cache_profile'])||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$row['uid'])!==1||(float)$row['uid']>4294967295)throw new \RuntimeException('Content source generation is unproven.');
        return ['message_id'=>$id,'account_id'=>(int)$row['account_id'],'mailbox_id'=>(int)$row['mailbox_id'],'uid'=>(string)$row['uid'],'uidvalidity'=>$generation,'profile_revision'=>$profile,'auth_revision'=>(int)$row['mcp_auth_revision'],'mailbox_revision'=>hash('sha256',json_encode([$row['name'],$row['sync_new_token'],$row['sync_changed_token'],$row['sync_vanished_token']],JSON_THROW_ON_ERROR)),'mailbox'=>$row['name'],'drafts_mailbox_id'=>(int)$row['drafts_mailbox_id']];
    }

    public function mailbox(string $uid,int $id,object $fence):array {
        $identity=$this->identity($uid,"mailbox",$id);$fence->authorize($uid,$identity["account_id"],$id,0);
        $this->primary();if($id<1||$uid==='')throw new \InvalidArgumentException('Invalid content mailbox.');
        $q=$this->db->getQueryBuilder();$q->select('b.id','b.account_id','b.selectable','b.sync_new_token','b.sync_changed_token','b.sync_vanished_token','a.mcp_auth_revision','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email')
            ->from('mail_mailboxes','b')->innerJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))->where($q->expr()->eq('b.id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->setMaxResults(1);
        $visible=new AccountVisibility($this->db);$visible->excludePending($q,'a.id');$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        if(!$row||!$row['selectable'])throw new \RuntimeException('Owned mailbox unavailable.');$generation=ExactTagAssociations::generation($row);if($generation===null)throw new \RuntimeException('Mailbox generation unavailable.');
        $fence->authorize($uid,(int)$row['account_id'],$id,0);$visible->assertVisible($uid,(int)$row['account_id']);
        return ['mailbox_id'=>$id,'account_id'=>(int)$row['account_id'],'generation'=>$generation,'profile'=>TagProfile::row($row),'auth_revision'=>(int)$row['mcp_auth_revision']];
    }
}
