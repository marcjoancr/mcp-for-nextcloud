<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IUserManager,IConfig};
/** Exact local erasure and quarantine of sensitive candidates, including orphaned account IDs. */
final class MailSieveErasureActor {
    private static function count(string $uid,string $table):int {$q=Server::get(IDBConnection::class)->getQueryBuilder();$r=$q->selectAlias($q->func()->count('*'),'n')->from($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}}
    private static function seed(string $uid,int $account):void {
        $id=bin2hex(random_bytes(32));$r=['id'=>$id,'user_id'=>$uid,'account_id'=>$account,'connection_id'=>1,'operation_key'=>$id,'source_tool'=>'mail_write_filters','approval'=>$id,'revision'=>1,'state'=>'activation_attempting','candidate'=>'mcp-'.$id,'candidate_hash'=>$id,'original_name'=>'original','original_hash'=>$id,'profile'=>$id,'observed_profile'=>'','created_at'=>time(),'follow_system'=>0,'program'=>'# PRIVATE_ERASURE_PROGRAM'];$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->insert('mail_mcp_sieve');foreach($r as$k=>$v)$q->setValue($k,$q->createNamedParameter($v));$q->executeStatement();
    }
    public static function run():array {
        $uid='mcp-it-erasure-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$user=$users->createUser($uid,bin2hex(random_bytes(24)));$a=MailErasureActor::account($uid);$db=Server::get(IDBConnection::class);$lease=null;$independent=null;$other=self::count(MailActor::BOB,'mail_accounts');
        try{
            self::seed($uid,$a->getId());self::seed($uid,$a->getId()+100000000);$system=Server::get(\OC\SystemConfig::class);$independent=new \OC\DB\ConnectionAdapter((new \OC\DB\ConnectionFactory($system))->getConnection((string)$system->getValue('dbtype',''),[]));$config=Server::get(IConfig::class);$lease=\OCA\Mail\Drafts\DraftOwnerLease::acquire($independent,$config->getSystemValueString('dbtableprefix','oc_'),$uid,$config);$deleted=$user->delete();$busy=self::count($uid,'mail_mcp_sieve');$quarantined=false;try{(new \OCA\Mail\Lifecycle\MailErasureStore($db))->assertAccount($uid,$a->getId());}catch(\OCA\Mail\Transmission\BeforeWireBlock){$quarantined=true;}
            $lease->release();$lease=null;$independent->close();$independent=null;$job=new \OCA\Mail\BackgroundJob\MailErasureJob(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),Server::get('OCA\\Mail\\Lifecycle\\MailErasureService'));$job->start(Server::get(\OCP\BackgroundJob\IJobList::class));return ['deleted'=>$deleted,'busy'=>$busy,'quarantined'=>$quarantined,'after'=>self::count($uid,'mail_mcp_sieve'),'accounts'=>self::count($uid,'mail_accounts'),'unrelated'=>self::count(MailActor::BOB,'mail_accounts')===$other];
        }finally{$lease?->release();$independent?->close();foreach(['mail_mcp_sieve','mail_accounts']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}if($users->userExists($uid))$user->delete();}
    }
}
