<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Drafts;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Exception\DraftAdmissionFailure;
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** One physical session across native SQL, filesystem and conversion work; no ambient transactions. */
final class DraftCoordinator {
    private static ?self $shared=null;
    private ?DraftScope $active=null;
    public static function forDatabase(IDBConnection $db):self {
        self::$shared??=new self($db,\OCP\Server::get(IConfig::class));self::$shared->assertDatabase($db);return self::$shared;
    }
    public function __construct(private IDBConnection $db,private IConfig $config){}
    public function assertDatabase(IDBConnection $db):void {if($db!==$this->db)throw new DraftAdmissionFailure('Draft work requires the authoritative primary connection.');}
    public function assertNoTransaction():void {if($this->db->inTransaction())throw new DraftAdmissionFailure('Filesystem work cannot inherit a draft transaction.');}
    private function primary():void {
        $provider=$this->db->getDatabaseProvider();if(!in_array($provider,[IDBConnection::PLATFORM_MYSQL,IDBConnection::PLATFORM_POSTGRES],true)||$this->config->getSystemValue('dbreplica',[])!==[]||$this->config->getSystemValue('dbsharding',[])!==[])throw new DraftAdmissionFailure('Draft coordination requires unsharded primary transactional storage.');
        $schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');
        if($provider===IDBConnection::PLATFORM_MYSQL)foreach(['mail_accounts','mail_local_messages','mail_recipients','mail_attachments'] as $name)if(strtolower((string)($schema->getTable($prefix.$name)->getOptions()['engine']??''))!=='innodb')throw new DraftAdmissionFailure('Draft participants must use transactional primary storage.');
    }
    public function runOwner(string $uid,\Closure $effect):mixed {
        if($this->active!==null){if($this->active->uid!==$uid)throw new DraftAdmissionFailure('Nested draft work cannot change owner.');$this->active->assertOwned();return $effect($this->active);}
        $this->assertNoTransaction();$this->primary();
        try{$lease=DraftOwnerLease::acquire($this->db,$this->config->getSystemValueString('dbtableprefix','oc_'),$uid);}catch(\Throwable $error){throw new DraftAdmissionFailure('Draft work is busy or its primary session is unavailable.',0,$error);}
        $scope=new DraftScope($uid,$lease);$this->active=$scope;
        try{$scope->assertOwned();$result=$effect($scope);$scope->assertOwned();$this->assertNoTransaction();return $result;}catch(DraftAdmissionFailure $error){if($scope->hasWritten())throw new \RuntimeException('Native draft work stopped after mutation admission.',0,$error);throw $error;}finally{$this->active=null;$lease->release();}
    }
    public function scope():DraftScope {if($this->active===null)throw new DraftAdmissionFailure('No native draft scope is active.');$this->active->assertOwned();return $this->active;}
    private function row(string $table,array $columns,int $id):array {
        $q=$this->db->getQueryBuilder();$q->select(...$columns)->from($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}if($row===false)throw new DraftAdmissionFailure('The native draft resource no longer exists.');return $row;
    }
    public function ownerForMessage(object $message):string {
        if($this->active===null)$this->assertNoTransaction();
        $account=$message->getId()===null?$message->getAccountId():(int)$this->row('mail_local_messages',['account_id'],(int)$message->getId())['account_id'];
        $uid=$this->row('mail_accounts',['user_id'],(int)$account)['user_id'];if(!is_string($uid)||$uid==='')throw new DraftAdmissionFailure('Draft owner unavailable.');return $uid;
    }
    public function runMessage(object $message,\Closure $effect):mixed {
        $uid=$this->ownerForMessage($message);
        return $this->runOwner($uid,function(DraftScope $scope)use($message,$uid,$effect):mixed {if($this->ownerForMessage($message)!==$uid)throw new DraftAdmissionFailure('Draft ownership changed.');return $effect($scope);});
    }
    public function current(LocalMessageMapper $mapper,int $id,string $uid):object {
        $scope=$this->scope();if($scope->uid!==$uid)throw new DraftAdmissionFailure('Draft owner changed.');
        $row=$this->row('mail_local_messages',['type'], $id);
        return $mapper->findById($id,$uid,(int)$row['type']);
    }
    public function admit(object $message,LocalMessageMapper $mapper,string $uid,string $purpose,bool $automatic=false):void {
        $scope=$this->scope();if($scope->uid!==$uid)throw new DraftAdmissionFailure('Draft owner changed.');
        if($message->getId()===null){if($purpose!=='write')throw new DraftAdmissionFailure('A persisted draft is required.');return;}
        $id=(int)$message->getId();
        if($scope->permits($id,[$purpose]))return;
        $fresh=$this->current($mapper,$id,$uid);$expected=$message->mcpOriginalSnapshot();
        if($expected===null||!hash_equals($expected,DraftSnapshot::of($fresh)))throw new DraftAdmissionFailure('The draft changed; reopen its current version.');
        if($purpose==='convert'&&($fresh->getType()!==1||($automatic&&($fresh->getMcpDraftOnly()||$fresh->isFailed()))))throw new DraftAdmissionFailure('Automatic conversion cannot consume a held draft.');
        if($purpose==='send'&&($fresh->getType()!==0||$fresh->getMcpDraftOnly()))throw new DraftAdmissionFailure('Draft-only content cannot enter the sender.');
        $scope->admit($id,$purpose);
    }
}
