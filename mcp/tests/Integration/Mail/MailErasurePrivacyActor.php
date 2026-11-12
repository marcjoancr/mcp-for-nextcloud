<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection};
/** Observes actual native read/use boundaries while original account erasure is pending. */
final class MailErasurePrivacyActor {
    private static function insert(string $table,array $values):int {$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->insert($table);foreach($values as $k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT:(is_bool($v)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));$q->executeStatement();return (int)$q->getLastInsertId();}
    private static function rows(int $account):array {
        $entity=Server::get('OCA\\Mail\\Db\\MailAccountMapper')->findById($account);$profile=\OCA\Mail\Db\TagProfile::account($entity);$token=base64_encode('V42,U2,H1,M1');
        $box=self::insert('mail_mailboxes',['account_id'=>$account,'name'=>'INBOX','name_hash'=>hash('sha256','INBOX'),'messages'=>1,'unseen'=>1,'selectable'=>true,'sync_new_token'=>$token,'sync_changed_token'=>$token,'sync_vanished_token'=>$token]);
        $message=self::insert('mail_messages',['mailbox_id'=>$box,'uid'=>1,'sent_at'=>time(),'subject'=>'Synthetic account cache metadata','tag_cache_generation'=>'42','tag_cache_profile'=>$profile]);
        self::bodyIndex($entity,$box,$message);
        $outbox=self::outbox($account);return ['mail_mailboxes'=>$box,'mail_messages'=>$message,'mail_local_messages'=>$outbox];
    }
    private static function bodyIndex(object $account,int $box,int $message):void {
        $row=['message_id'=>$message,'user_id'=>$account->getUserId(),'account_id'=>$account->getId(),'mailbox_id'=>$box,'imap_uid'=>'1','generation'=>'42','profile'=>\OCA\Mail\Db\TagProfile::account($account),'auth_revision'=>$account->getMcpAuthRevision(),'content_revision'=>hash('sha256','synthetic-index'),'body_text'=>'synthetic index body','policy_version'=>'complete-text-v1','coverage_state'=>'complete','indexed_at'=>time()];$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->insert('mail_mcp_body_index');foreach($row as $k=>$v)$q->setValue($k,$q->createNamedParameter($v,is_int($v)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR));$q->executeStatement();
    }
    private static function indexed(int $id):int {$q=Server::get(IDBConnection::class)->getQueryBuilder();$r=$q->select('message_id')->from('mail_mcp_body_index')->where($q->expr()->eq('message_id',$q->createNamedParameter($id,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery();try{return $r->fetch()===false?0:1;}finally{$r->closeCursor();}}
    private static function outbox(int $account):int {return self::insert('mail_local_messages',['account_id'=>$account,'type'=>0,'subject'=>'Synthetic account outbox metadata','html'=>false,'failed'=>true,'updated_at'=>time()]);}
    private static function ids(array $accounts):array {return array_map(static fn($a):int=>(int)$a->getId(),$accounts);}
    private static function missing(\Closure $read):bool {try{$read();return false;}catch(\OCP\AppFramework\Db\DoesNotExistException|\OCA\Mail\Exception\ClientException){return true;}}
    public static function prepare(string $uid,object $old):array {
        $service=clone Server::get('OCA\\Mail\\Service\\AccountService');$service->findByUserId($uid);
        return ['old'=>$old,'cached'=>$service,'retained'=>clone $service,'rows'=>[self::rows($old->getId())]];
    }
    private static function transportDenied(object $entity,string $kind):bool {
        $copy=clone $entity;$copy->setInboundPassword(null);$copy->setOutboundPassword(null);$account=new \OCA\Mail\Account($copy);
        return self::missing(fn()=>$kind==='imap'?Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($account,false):Server::get('OCA\\Mail\\SMTP\\SmtpClientFactory')->create($account));
    }
    public static function observe(string $uid,object $new,array &$context):array {
        $old=$context['old'];$oldId=$old->getId();$newId=$new->getId();$context['rows'][]=self::rows($newId);
        // Put another old outbox row above the new one: SQL exclusion must precede LIMIT/cursor projection.
        $context['rows'][]=['mail_local_messages'=>self::outbox($oldId)];
        $mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$cached=$context['cached'];$reader=Server::get('OCA\\Mail\\Db\\BoundedMailMetadata');
        $out=['mapper_old_denied'=>self::missing(fn()=>$mapper->find($uid,$oldId)),'mapper_id_denied'=>self::missing(fn()=>$mapper->findById($oldId)),
            'mapper_list_new_only'=>self::ids($mapper->findByUserId($uid))===[$newId],'mapper_address_new_only'=>self::ids($mapper->findByUserIdAndAddress($uid,$new->getEmail()))===[$newId],
            'cached_old_denied'=>self::missing(fn()=>$cached->find($uid,$oldId)),'cached_new_visible'=>!self::missing(fn()=>$cached->find($uid,$newId)),
            'cached_list_new_only'=>self::ids($cached->findByUserId($uid))===[$newId],'cached_address_new_only'=>self::ids($cached->findByUserIdAndAddress($uid,$new->getEmail()))===[$newId],
            'imap_old_denied'=>self::transportDenied($old,'imap'),'smtp_old_denied'=>self::transportDenied($old,'smtp'),
            'imap_new_allowed'=>!self::transportDenied($new,'imap'),'smtp_new_allowed'=>!self::transportDenied($new,'smtp')];
        $controller=clone Server::get('OCA\\Mail\\Controller\\AccountsController');(new \ReflectionProperty($controller,'userId'))->setValue($controller,$uid);(new \ReflectionProperty($controller,'accountService'))->setValue($controller,$cached);
        $out['controller_new_only']=array_column($controller->index()->getData(),'accountId')===[$newId];
        $page=$reader->outbox($uid,null,1,null);$out['outbox_new_only']=array_column($page['items'],'account_id')===[$newId]&&$page['next_before_id']===null;
        $out['outbox_old_empty']=$reader->outbox($uid,$oldId,1,null)['items']===[];
        $messages=$reader->cachedMessages($uid,[$context['rows'][0]['mail_messages'],$context['rows'][1]['mail_messages']]);$out['cache_new_only']=array_column($messages,'account_id')===[$newId];
        $index=Server::get('OCA\\Mail\\Service\\GuardedBodyIndex');$fence=new class {public function authorize(string $uid,int $account,int $box,int $message):void {}};$out['index_pending_old_denied']=false;try{$index->search($uid,[$context['rows'][0]['mail_mailboxes']],'synthetic',1,null,$fence);}catch(\Throwable){$out['index_pending_old_denied']=true;}
        $out['index_new_only']=array_column($index->search($uid,[$context['rows'][1]['mail_mailboxes']],'synthetic',1,null,$fence)['items'],'account_id')===[$newId];
        $old->setName('Synthetic rejected pending change');$out['update_old_denied']=self::missing(fn()=>$mapper->update($old));$new->setName('Synthetic permitted new change');$out['update_new_allowed']=$mapper->update($new)->getId()===$newId;
        self::bodyIndex($new,$context['rows'][1]['mail_mailboxes'],$context['rows'][1]['mail_messages']);
        return $out;
    }
    public static function after(string $uid,object $new,array $context):array {
        $old=$context['old'];$cached=$context['retained'];$newId=$new->getId();return ['old_index_erased'=>self::indexed($context['rows'][0]['mail_messages'])===0,'recreated_index_retained'=>self::indexed($context['rows'][1]['mail_messages'])===1,'purged_cached_old_denied'=>self::missing(fn()=>$cached->find($uid,$old->getId())),
            'purged_cached_new_visible'=>!self::missing(fn()=>$cached->find($uid,$newId)),'purged_cached_list_new_only'=>self::ids($cached->findByUserId($uid))===[$newId],
            'purged_imap_old_denied'=>self::transportDenied($old,'imap'),'purged_smtp_old_denied'=>self::transportDenied($old,'smtp')];
    }
    public static function clean(array $context):void {foreach($context['rows'] as $rows)foreach(['mail_messages','mail_mailboxes','mail_local_messages'] as $table)if(isset($rows[$table])){$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('id',$q->createNamedParameter($rows[$table],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();}}
}
