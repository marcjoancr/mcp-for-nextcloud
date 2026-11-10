<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Sync;
use OCA\Mail\Db\PgSessionLease;
use OCA\Mail\Db\PgDirectConnection;
use OCP\{IDBConnection,IConfig};
/** Physical primary-session ownership. A timestamp never grants, expires or releases this mutex. */
final class MailboxLease {
    private bool $released=false;
    private function __construct(private IDBConnection $db,private string $name,private string $variable,private string $token,private string $connection,private ?PgSessionLease $pg=null){}
    private static function scalar(IDBConnection $db,string $sql,array $params=[]):mixed {$r=$db->executeQuery($sql,$params);try{return $r->fetchOne();}finally{$r->closeCursor();}}
    public static function acquire(IDBConnection $db,string $prefix,int $mailbox,?IConfig $config=null):self {
        if($mailbox<1)throw new \RuntimeException('Owned sync requires a primary database session.');
        if($db->getDatabaseProvider()===IDBConnection::PLATFORM_POSTGRES){if($config===null||!PgDirectConnection::verified($db,$config))throw new \RuntimeException('Owned sync requires a directly connected PostgreSQL primary.');$pg=PgSessionLease::acquire($db,$prefix,'mailbox-sync',(string)$mailbox);return new self($db,'','','','',$pg);}
        if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_MYSQL)throw new \RuntimeException('Owned sync requires a primary database session.');
        $hash=hash('sha256',self::scalar($db,'SELECT DATABASE()')."\0".$prefix."\0".$mailbox);$name='mcp_mail_'.$hash;$name=substr($name,0,64);$variable='@mcp_mail_owner_'.substr($hash,0,32);$connection=(string)self::scalar($db,'SELECT CONNECTION_ID()');
        // MySQL permits recursive GET_LOCK on one session; deliberately refuse it.
        if(self::scalar($db,'SELECT IS_USED_LOCK(?)',[$name])!==null||self::scalar($db,'SELECT GET_LOCK(?, 0)',[$name])!=1)throw new \RuntimeException('Mailbox synchronization is already owned.');
        $lease=new self($db,$name,$variable,bin2hex(random_bytes(32)),$connection);
        try{$db->executeStatement('SET '.$variable.' = ?',[$lease->token]);$lease->assertOwned();return $lease;}catch(\Throwable $e){$lease->release();throw $e;}
    }
    public function assertOwned():void {
        if($this->pg!==null){$this->pg->assertOwned();return;}
        if($this->released||(string)self::scalar($this->db,'SELECT CONNECTION_ID()')!==$this->connection||(string)self::scalar($this->db,'SELECT IS_USED_LOCK(?)',[$this->name])!==$this->connection||self::scalar($this->db,'SELECT '.$this->variable)!==$this->token)throw new \RuntimeException('Mailbox session ownership was lost.');
    }
    public function release():void {
        if($this->pg!==null){$this->pg->release();return;}
        if($this->released)return;
        try{$this->assertOwned();self::scalar($this->db,'SELECT RELEASE_LOCK(?)',[$this->name]);}catch(\Throwable){/* A stale owner must never release a successor. */}finally{$this->released=true;}
    }
}
