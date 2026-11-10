<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Drafts;
use OCA\Mail\Db\PgSessionLease;
use OCP\IDBConnection;
/** Distinct owner mutex: includes drafts that have no ID and unassociated attachments. */
final class DraftOwnerLease {
    private bool $released=false;
    private function __construct(private IDBConnection $db,private string $name,private string $variable,private string $token,private string $connection,public readonly string $uid,private ?PgSessionLease $pg=null){}
    private static function scalar(IDBConnection $db,string $sql,array $params=[]):mixed {$r=$db->executeQuery($sql,$params);try{return $r->fetchOne();}finally{$r->closeCursor();}}
    public static function acquire(IDBConnection $db,string $prefix,string $uid):self {
        if($uid===''||strlen($uid)>1024)throw new \RuntimeException('Draft coordination requires a primary database session.');
        if($db->getDatabaseProvider()===IDBConnection::PLATFORM_POSTGRES){$pg=PgSessionLease::acquire($db,$prefix,'draft-owner',$uid);return new self($db,'','','','', $uid,$pg);}
        if($db->getDatabaseProvider()!==IDBConnection::PLATFORM_MYSQL)throw new \RuntimeException('Draft coordination requires a primary database session.');
        $hash=hash('sha256',self::scalar($db,'SELECT DATABASE()')."\0".$prefix."\0".$uid);$name='mcp_mail_draft_'.substr($hash,0,48);$variable='@mcp_mail_draft_owner_'.substr($hash,0,32);$connection=(string)self::scalar($db,'SELECT CONNECTION_ID()');
        if($connection===''||self::scalar($db,'SELECT IS_USED_LOCK(?)',[$name])!==null||self::scalar($db,'SELECT GET_LOCK(?, 0)',[$name])!=1)throw new \RuntimeException('Draft work for this owner is already active.');
        $lease=new self($db,$name,$variable,bin2hex(random_bytes(32)),$connection,$uid);
        try{$db->executeStatement('SET '.$variable.' = ?',[$lease->token]);$lease->assertOwned();return $lease;}catch(\Throwable $error){$lease->release();throw $error;}
    }
    public function assertOwned():void {
        if($this->pg!==null){$this->pg->assertOwned();return;}
        if($this->released||(string)self::scalar($this->db,'SELECT CONNECTION_ID()')!==$this->connection||(string)self::scalar($this->db,'SELECT IS_USED_LOCK(?)',[$this->name])!==$this->connection||self::scalar($this->db,'SELECT '.$this->variable)!==$this->token)throw new \RuntimeException('Draft session ownership was lost.');
    }
    public function release():void {
        if($this->pg!==null){$this->pg->release();return;}
        if($this->released)return;
        try{$this->assertOwned();self::scalar($this->db,'SELECT RELEASE_LOCK(?)',[$this->name]);}catch(\Throwable){/* Never release a successor's mutex. */}finally{$this->released=true;}
    }
}
