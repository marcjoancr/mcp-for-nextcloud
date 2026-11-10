<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Native exact cache only. Header Message-ID is never an identity or migration hint. */
final class ExactTagAssociations {
    public function __construct(private IDBConnection $db,private ?\OCP\IConfig $config=null){}
    public function requireStorage():void {
        if(!in_array($this->db->getDatabaseProvider(),[IDBConnection::PLATFORM_MYSQL,IDBConnection::PLATFORM_POSTGRES],true))throw new \RuntimeException('This native tag storage provider has not been qualified.');
        if($this->config===null||$this->config->getSystemValue('dbsharding',[])!==[]||$this->config->getSystemValue('dbreplica',[])!==[])throw new \RuntimeException('Exact tag writes require an unsharded primary database.');
        $schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');$m=$schema->getTable($prefix.'mail_messages');$t=$schema->getTable($prefix.'mail_message_tags');$unique=false;$cascade=false;
        if(!$schema->getTable($prefix.'mail_mailboxes')->hasColumn('tag_cache_revision')||!$m->hasColumn('tag_cache_profile')||!$m->hasColumn('tag_cache_generation')||!$t->hasColumn('cached_message_id'))throw new \RuntimeException('Exact tag migration unavailable.');
        foreach($t->getIndexes()as$index){$columns=$index->getColumns();sort($columns);if($index->isUnique()&&$columns===['cached_message_id','tag_id'])$unique=true;}
        foreach($t->getForeignKeys()as$key)if($key->getLocalColumns()===['cached_message_id']&&$key->getForeignColumns()===['id']&&$key->getForeignTableName()===$m->getName()&&strtoupper((string)($key->getOptions()['onDelete']??''))==='CASCADE')$cascade=true;
        if(!$unique||!$cascade)throw new \RuntimeException('Exact tag uniqueness/cascade unavailable.');

        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL)foreach([$m,$t,$schema->getTable($prefix.'mail_mailboxes'),$schema->getTable($prefix.'mail_tags')]as$table)if(strtolower((string)($table->getOptions()['engine']??''))!=='innodb')throw new \RuntimeException('Exact tag cache must be transactional.');
    }
    private function rows(object $q):array {$r=$q->executeQuery();try{return $r->fetchAll();}finally{$r->closeCursor();}}
    private function context(string $owner,int $id):array {
        $q=$this->db->getQueryBuilder();$rows=$this->rows($q->select('m.id','m.mailbox_id','m.uid','m.tag_cache_generation','m.tag_cache_profile','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email','b.sync_new_token','b.sync_changed_token','b.sync_vanished_token')->from('mail_messages','m')
            ->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('m.id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('a.user_id',$q->createNamedParameter($owner))));
        if(count($rows)!==1)throw new \RuntimeException('Exact owned cache identity unavailable.');return $rows[0];
    }
    public static function generation(array $row,bool $initial=false):?string {
        $found=[];$empty=0;
        foreach(['sync_new_token','sync_changed_token','sync_vanished_token']as$key){$token=$row[$key];if($token===null){$empty++;continue;}$decoded=is_string($token)?base64_decode($token,true):false;
            if($decoded===false||preg_match_all('/(?:^|,)V([^,]*)(?=,|$)/D',$decoded,$m)!==1||preg_match('/^[1-9][0-9]{0,9}$/D',$m[1][0])!==1||(float)$m[1][0]>4294967295)return null;$found[$m[1][0]]=true;}
        if($initial&&$empty===3)return 'initial';return $empty===0&&count($found)===1?(string)array_key_first($found):null;
    }
    /** A bounded page of cached identities, qualified with one owned native read. */
    public function verifiedIds(string $owner,array $ids):array {
        if(count($ids)>1000)throw new \RuntimeException('Tag identity page exceeds its bound.');if($ids===[])return [];foreach($ids as$id)if(!is_int($id)||$id<1)throw new \RuntimeException('Invalid cached identity.');
        $q=$this->db->getQueryBuilder();$rows=$this->rows($q->select('m.id','m.tag_cache_generation','m.tag_cache_profile','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email','b.sync_new_token','b.sync_changed_token','b.sync_vanished_token')->from('mail_messages','m')->leftJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))->where($q->expr()->eq('a.user_id',$q->createNamedParameter($owner)))->andWhere($q->expr()->in('m.id',$q->createNamedParameter(array_values(array_unique($ids)),IQueryBuilder::PARAM_INT_ARRAY)))->orderBy('m.id','ASC'));
        $verified=[];foreach($rows as$row){$g=self::generation($row);if($g!==null&&$g===($row['tag_cache_generation']??null)&&($row['tag_cache_profile']??null)===TagProfile::row($row))$verified[]=(int)$row['id'];}return $verified;
    }
    /** Native global tag deletion must not silently ignore an account's still-unverified legacy cache. */
    public function requireAccountReady(string $owner,int $account):void {
        $q=$this->db->getQueryBuilder();$a=$this->rows($q->select('id','inbound_host','inbound_port','inbound_ssl_mode','inbound_user','auth_method','protocol','email')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($owner))));if(count($a)!==1)throw new \RuntimeException('Owned tag account unavailable.');
        $q=$this->db->getQueryBuilder();$boxes=$this->rows($q->select('id','sync_new_token','sync_changed_token','sync_vanished_token')->from('mail_mailboxes')->where($q->expr()->eq('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT))));
        foreach($boxes as$b){foreach([true,false]as$null){$q=$this->db->getQueryBuilder();$q->select('id')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter((int)$b['id'],IQueryBuilder::PARAM_INT)))->setMaxResults(1);$q->andWhere($null?$q->expr()->isNull('tag_cache_profile'):$q->expr()->neq('tag_cache_profile',$q->createNamedParameter(TagProfile::row($a[0]))));if($this->rows($q)!==[])throw new \RuntimeException('Native tag account contains unknown inbound provenance.');}$g=self::generation($b);$q=$this->db->getQueryBuilder();$q->select('id')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter((int)$b['id'],IQueryBuilder::PARAM_INT)))->setMaxResults(1);if($g===null){if($this->rows($q)!==[])throw new \RuntimeException('Native tag account needs synchronization.');continue;}$q->andWhere($q->expr()->isNull('tag_cache_generation'));if($this->rows($q)!==[])throw new \RuntimeException('Native tag account contains legacy unknown cache.');
            $q=$this->db->getQueryBuilder();$q->select('id')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter((int)$b['id'],IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->neq('tag_cache_generation',$q->createNamedParameter($g)))->setMaxResults(1);if($this->rows($q)!==[])throw new \RuntimeException('Native tag account epoch changed.');}
    }
    public function verified(string $owner,int $id):bool {try{$row=$this->context($owner,$id);$g=self::generation($row);return $g!==null&&$g===($row['tag_cache_generation']??null)&&($row['tag_cache_profile']??null)===TagProfile::row($row);}catch(\Throwable){return false;}}
    private function requireMessage(string $owner,int $id):array {$row=$this->context($owner,$id);$g=self::generation($row);if($g===null||($g!==($row['tag_cache_generation']??null)||($row['tag_cache_profile']??null)!==TagProfile::row($row)))throw new \RuntimeException('Tag cache provenance is unknown. Synchronize in native Mail.');return $row;}
    public function assertIdentity(string $owner,int $id,int $mailbox,int $uid,string $generation):void {
        $row=$this->requireMessage($owner,$id);if((int)$row['mailbox_id']!==$mailbox||(int)$row['uid']!==$uid||$row['tag_cache_generation']!==$generation)throw new \RuntimeException('Exact native DTO identity changed.');
    }
    private function requireTag(string $owner,int $id):array {
        $q=$this->db->getQueryBuilder();$rows=$this->rows($q->select('id','imap_label')->from('mail_tags')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($owner))));
        if(count($rows)!==1)throw new \RuntimeException('Owned tag unavailable.');return $rows[0];
    }
    public function has(string $owner,int $message,int $tag):bool {
        $this->requireMessage($owner,$message);$this->requireTag($owner,$tag);$q=$this->db->getQueryBuilder();return $this->rows($q->select('id')->from('mail_message_tags')->where($q->expr()->eq('cached_message_id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('tag_id',$q->createNamedParameter($tag,IQueryBuilder::PARAM_INT))))!==[];
    }
    private function transaction(\Closure $work):mixed {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();try{$r=$work();if($own)$this->db->commit();return $r;}catch(\Throwable $e){if($own)$this->db->rollBack();throw $e;}
    }
    private function lock(string $table,int $id):void {
        $q=$this->db->getQueryBuilder();$q->select('id')->from($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_SQLITE)$q->forUpdate();if(count($this->rows($q))!==1)throw new \RuntimeException('Cache identity disappeared.');
    }
    public function mailboxProfile(int $mailbox):string {
        $q=$this->db->getQueryBuilder();$r=$this->rows($q->select('a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email')->from('mail_mailboxes','b')->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))->where($q->expr()->eq('b.id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT))));if(count($r)!==1)throw new \RuntimeException('Native tag mailbox unavailable.');return TagProfile::row($r[0]);
    }
    public function capture(string $owner,int $mailbox,string $generation,?\OCA\Mail\Sync\MailboxLease $lease=null):TagSnapshotVersion {
        $q=$this->db->getQueryBuilder();$rows=$this->rows($q->select('b.tag_cache_revision','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email')->from('mail_mailboxes','b')->leftJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))->where($q->expr()->eq('b.id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('a.user_id',$q->createNamedParameter($owner))));
        if(count($rows)!==1)throw new \RuntimeException('Owned snapshot mailbox unavailable.');return new TagSnapshotVersion($owner,$mailbox,$generation,(int)$rows[0]['tag_cache_revision'],TagProfile::row($rows[0]),$lease);
    }
    /** First statement inside the snapshot transaction: current locking read, never an old MVCC view. */
    public function claim(TagSnapshotVersion $version):void {
        $version->syncLease?->assertOwned();if(!$this->db->inTransaction())throw new \RuntimeException('Tag snapshot requires a transaction.');
        $q=$this->db->getQueryBuilder();$q->select('tag_cache_revision')->from('mail_mailboxes')->where($q->expr()->eq('id',$q->createNamedParameter($version->mailbox,IQueryBuilder::PARAM_INT)));if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_SQLITE)$q->forUpdate();$rows=$this->rows($q);
        if(count($rows)!==1||(int)$rows[0]['tag_cache_revision']!==$version->expected())throw new \RuntimeException('Tag snapshot was superseded by another cache writer.');if($this->capture($version->owner,$version->mailbox,$version->generation)->profile!==$version->profile)throw new \RuntimeException('Inbound snapshot identity changed.');$next=$version->stage();
        $q=$this->db->getQueryBuilder();if($q->update('mail_mailboxes')->set('tag_cache_revision',$q->createNamedParameter($next,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($version->mailbox,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('tag_cache_revision',$q->createNamedParameter($version->expected(),IQueryBuilder::PARAM_INT)))->executeStatement()!==1)throw new \RuntimeException('Tag snapshot revision changed.');
    }
    public function assertCurrent(TagSnapshotVersion $version):void {
        $version->syncLease?->assertOwned();if($version->pending()!==null||(($current=$this->capture($version->owner,$version->mailbox,$version->generation))->expected()!==$version->expected()||$current->profile!==$version->profile))throw new \RuntimeException('Native tag snapshot changed.');
    }
    public function set(string $owner,int $message,int $tag,bool $value,?TagSnapshotVersion $version=null):void {
        // Locate before beginning an owned transaction; locks precede its first consistent read.
        $row=$this->requireMessage($owner,$message);if($version===null)$version=$this->capture($owner,(int)$row['mailbox_id'],(string)$row['tag_cache_generation']);$own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {
            if($version->owner!==$owner||$version->mailbox!==(int)$row['mailbox_id'])throw new \RuntimeException('Tag snapshot scope changed.');$this->claim($version);$this->lock('mail_messages',$message);$this->lock('mail_tags',$tag);$this->requireMessage($owner,$message);$meta=$this->requireTag($owner,$tag);$this->write($message,$tag,$value);
            if(strtolower($meta['imap_label'])==='$label1'){$q=$this->db->getQueryBuilder();$q->update('mail_messages')->set('flag_important',$q->createNamedParameter($value,IQueryBuilder::PARAM_BOOL))->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();}
            if($own){$this->db->commit();$version->committed();}
        }catch(\Throwable $e){if($own)$this->db->rollBack();$version?->aborted();throw $e;}
    }
    private function write(int $message,int $tag,bool $value):void {
        $q=$this->db->getQueryBuilder();$q->delete('mail_message_tags')->where($q->expr()->eq('cached_message_id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('tag_id',$q->createNamedParameter($tag,IQueryBuilder::PARAM_INT)))->executeStatement();
        if($value){$q=$this->db->getQueryBuilder();$q->insert('mail_message_tags')->setValue('cached_message_id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT))->setValue('tag_id',$q->createNamedParameter($tag,IQueryBuilder::PARAM_INT))->setValue('imap_message_id',$q->createNamedParameter(''))->executeStatement();}
    }
    /** Only freshly fetched native snapshots may establish a complete cache. Resolve missing IDs by owned mailbox+UID. */
    public function replace(string $owner,int $mailbox,int $uid,?int $id,string $generation,array $tags,bool $initial=false,?TagSnapshotVersion $version=null):int {
        if($version===null||$version->pending()===null||$version->owner!==$owner||$version->mailbox!==$mailbox||$version->generation!==$generation)throw new \RuntimeException('Snapshot was not claimed before cache writes.');
        if(preg_match('/^[1-9][0-9]{0,9}$/D',$generation)!==1||(float)$generation>4294967295)throw new \RuntimeException('Invalid snapshot epoch.');
        return $this->transaction(function()use($owner,$mailbox,$uid,$id,$generation,$tags,$initial,$version):int{
            $this->lock('mail_mailboxes',$mailbox);$q=$this->db->getQueryBuilder();$rows=$this->rows($q->select('id')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uid',$q->createNamedParameter($uid,IQueryBuilder::PARAM_INT))));
            if(count($rows)!==1||($id!==null&&(int)$rows[0]['id']!==$id))throw new \RuntimeException('Exact snapshot identity unavailable.');$message=(int)$rows[0]['id'];$this->lock('mail_messages',$message);$row=$this->context($owner,$message);$cached=self::generation($row,$initial&&$id!==null);
            if(($cached!==$generation&&$cached!=='initial')||(($row['tag_cache_generation']??null)!==null&&$row['tag_cache_generation']!==$generation))throw new \RuntimeException('Snapshot/cache generation changed.');
            if(TagProfile::row($row)!==$version->profile||(!$initial&&($row['tag_cache_profile']??null)!==$version->profile))throw new \RuntimeException('Snapshot header provenance is unknown. Rebuild the native mailbox cache.');
            foreach($tags as$tag){if(!is_int($tag)||$tag<1)throw new \RuntimeException('Invalid native tag identity.');$this->requireTag($owner,$tag);}
            $q=$this->db->getQueryBuilder();$q->delete('mail_message_tags')->where($q->expr()->eq('cached_message_id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();foreach(array_unique($tags)as$tag)$this->write($message,$tag,true);
            $q=$this->db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter($generation))->set('tag_cache_profile',$q->createNamedParameter($version->profile))->where($q->expr()->eq('id',$q->createNamedParameter($message,IQueryBuilder::PARAM_INT)))->executeStatement();return $message;
        });
    }
}
