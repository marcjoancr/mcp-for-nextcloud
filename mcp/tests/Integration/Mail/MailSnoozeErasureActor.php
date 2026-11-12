<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection,IUserManager,IConfig};
/** Disposable owner and deliberately orphaned intent. No transport or transmission switch. */
final class MailSnoozeErasureActor {
    private static function count(string $uid,string $table):int {$q=Server::get(IDBConnection::class)->getQueryBuilder();$r=$q->selectAlias($q->func()->count('*'),'n')->from($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeQuery();try{return (int)$r->fetchOne();}finally{$r->closeCursor();}}
    private static function seed(string $uid,int $account):void {
        $id=bin2hex(random_bytes(32));$r=[];foreach(['id','operation_key','approval','profile_revision','roles_revision','active_source']as$key)$r[$key]=$id;
        foreach(['account_id'=>$account,'connection_id'=>1,'revision'=>1,'created_at'=>time(),'wake_at'=>time()+3600,'source_mailbox_id'=>100000001,'source_message_id'=>100000001,'snooze_mailbox_id'=>100000002,'current_mailbox_id'=>100000001]as$key=>$value)$r[$key]=$value;
        foreach(['source_generation','source_uid','snooze_generation','current_generation','current_uid']as$key)$r[$key]='1';foreach(['placement_request','wake_request','placement_proof','wake_proof']as$key)$r[$key]='{}';
        $r+=['user_id'=>$uid,'timezone'=>'UTC','state'=>'placing','placement_phase'=>'wire_may_start','wake_phase'=>'prepared','source_name'=>'Synthetic original','snooze_name'=>'Synthetic snooze','reason'=>''];$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->insert('mail_mcp_snoozes');foreach($r as$key=>$value)$q->setValue($key,$q->createNamedParameter($value));$q->executeStatement();
    }
    public static function run():array {
        $db=Server::get(IDBConnection::class);$uid='mcp-it-erasure-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$user=$users->createUser($uid,bin2hex(random_bytes(24)));$account=MailErasureActor::account($uid);$other=self::count(MailActor::BOB,'mail_accounts');$lease=null;$independent=null;
        try{
            self::seed($uid,$account->getId());self::seed($uid,$account->getId()+100000000);$out=['ordinary_refused'=>false];try{Server::get('OCA\\Mail\\Service\\AccountService')->delete($uid,$account->getId());}catch(\Throwable){$out['ordinary_refused']=true;}
            $system=Server::get(\OC\SystemConfig::class);$independent=new \OC\DB\ConnectionAdapter((new \OC\DB\ConnectionFactory($system))->getConnection((string)$system->getValue('dbtype',''),[]));$r=$independent->executeQuery('SELECT pg_backend_pid()');try{$pid=(int)$r->fetchOne();}finally{$r->closeCursor();}$r=$db->executeQuery('SELECT pg_backend_pid()');try{$out['independent']=$pid!==(int)$r->fetchOne();}finally{$r->closeCursor();}
            $config=Server::get(IConfig::class);$lease=\OCA\Mail\Drafts\DraftOwnerLease::acquire($independent,$config->getSystemValueString('dbtableprefix','oc_'),$uid,$config);$out['deleted']=$user->delete();$out['busy_snoozes']=self::count($uid,'mail_mcp_snoozes');$out['quarantined']=false;
            try{Server::get('OCA\\Mail\\Snooze\\SnoozeStore')->assertAccount($uid,$account->getId());}catch(\OCA\Mail\Transmission\BeforeWireBlock){$out['quarantined']=true;}
            $lease->release();$lease=null;$independent->close();$independent=null;$job=new \OCA\Mail\BackgroundJob\MailErasureJob(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),Server::get('OCA\\Mail\\Lifecycle\\MailErasureService'));$job->start(Server::get(\OCP\BackgroundJob\IJobList::class));
            return $out+['after_snoozes'=>self::count($uid,'mail_mcp_snoozes'),'after_accounts'=>self::count($uid,'mail_accounts'),'unrelated'=>self::count(MailActor::BOB,'mail_accounts')===$other];
        }finally{$lease?->release();$independent?->close();foreach(['mail_mcp_snoozes','mail_accounts']as$table){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();}if($users->userExists($uid))$user->delete();}
    }
}
