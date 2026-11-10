<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCP\IDBConnection;

/** A non-recursive mutex bound to one physical PostgreSQL backend session. */
final class PgSessionLease {
    private bool $released=false;
    private function __construct(private IDBConnection $db,private int $key1,private int $key2,private string $setting,private string $marker,private string $pid,private string $started){}
    private static function scalar(IDBConnection $db,string $sql,array $params=[]):mixed {$r=$db->executeQuery($sql,$params);try{return $r->fetchOne();}finally{$r->closeCursor();}}
    private static function truthy(mixed $value):bool {return in_array($value,[true,1,'1','t'],true);}
    private static function keys(string $material):array {
        $parts=unpack('Nfirst/Nsecond',substr(hash('sha256',$material,true),0,8));
        $first=(int)$parts['first'];$second=(int)$parts['second'];
        return [$first>2147483647?$first-4294967296:$first,$second>2147483647?$second-4294967296:$second];
    }
    public static function acquire(IDBConnection $db,string $prefix,string $kind,string $owner):self {
        if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES||$owner===''||$kind==='')throw new \RuntimeException('Coordination requires a primary PostgreSQL session.');
        if($db->inTransaction())throw new \RuntimeException('PostgreSQL session ownership must be acquired outside a transaction.');
        $database=(string)self::scalar($db,'SELECT current_database()');
        $material=$database."\0".$prefix."\0".$kind."\0".$owner;[$a,$b]=self::keys($material);
        $unsignedA=$a<0?$a+4294967296:$a;$unsignedB=$b<0?$b+4294967296:$b;
        $setting='mcp_mail.lease_'.substr(hash('sha256',$material),0,40);
        $pid=(string)self::scalar($db,'SELECT pg_backend_pid()');
        $started=(string)self::scalar($db,'SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()');
        $recursive=self::scalar($db,"SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND database=(SELECT oid FROM pg_database WHERE datname=current_database()) AND classid=?::oid AND objid=?::oid AND objsubid=2 AND pid=pg_backend_pid())",[$unsignedA,$unsignedB]);
        if($pid===''||$started===''||self::truthy($recursive))throw new \RuntimeException('Coordination is already owned on this PostgreSQL session.');
        $acquired=self::scalar($db,'SELECT pg_try_advisory_lock(?, ?)',[$a,$b]);
        if(!in_array($acquired,[true,1,'1','t'],true))throw new \RuntimeException('Coordination is already owned.');
        $lease=new self($db,$a,$b,$setting,bin2hex(random_bytes(32)),$pid,$started);
        try {self::scalar($db,'SELECT set_config(?, ?, false)',[$setting,$lease->marker]);$lease->assertOwned();return $lease;}
        catch(\Throwable $error){$lease->unlockAcquisitionIfCurrent();$lease->released=true;throw $error;}
    }
    private function unlockAcquisitionIfCurrent():void {
        try {
            if((string)self::scalar($this->db,'SELECT pg_backend_pid()')!==$this->pid||(string)self::scalar($this->db,'SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()')!==$this->started)return;
            $held=self::scalar($this->db,"SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND database=(SELECT oid FROM pg_database WHERE datname=current_database()) AND classid=?::oid AND objid=?::oid AND objsubid=2 AND granted AND pid=pg_backend_pid() AND mode='ExclusiveLock')",[$this->key1<0?$this->key1+4294967296:$this->key1,$this->key2<0?$this->key2+4294967296:$this->key2]);
            if(self::truthy($held))self::scalar($this->db,'SELECT pg_advisory_unlock(?, ?)',[$this->key1,$this->key2]);
        } catch(\Throwable){/* Session loss releases its own advisory lock. */}
    }
    public function assertOwned():void {
        $unsignedA=$this->key1<0?$this->key1+4294967296:$this->key1;$unsignedB=$this->key2<0?$this->key2+4294967296:$this->key2;
        $held=self::scalar($this->db,"SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND database=(SELECT oid FROM pg_database WHERE datname=current_database()) AND classid=?::oid AND objid=?::oid AND objsubid=2 AND granted AND pid=pg_backend_pid() AND mode='ExclusiveLock')",[$unsignedA,$unsignedB]);
        if($this->released||(string)self::scalar($this->db,'SELECT pg_backend_pid()')!==$this->pid||(string)self::scalar($this->db,'SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()')!==$this->started||self::scalar($this->db,'SELECT current_setting(?, true)',[$this->setting])!==$this->marker||!self::truthy($held))throw new \RuntimeException('PostgreSQL session ownership was lost.');
    }
    public function release():void {
        if($this->released)return;
        try {$this->assertOwned();self::scalar($this->db,'SELECT pg_advisory_unlock(?, ?)',[$this->key1,$this->key2]);}
        catch(\Throwable){/* A stale lease must never unlock a successor. */}
        finally {$this->released=true;}
    }
}
