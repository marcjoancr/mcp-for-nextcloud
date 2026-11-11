<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Snooze;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Protects remote identities, never ordinary removal of vanished cache rows. */
final class SnoozeHolds {
    private static ?\WeakMap $clients=null;
    private static function query(IDBConnection $db):object {$q=$db->getQueryBuilder();return $q->select('id')->from('mail_mcp_snoozes')->where($q->expr()->isNotNull('active_source'));}
    private static function refuse(object $q):void {$r=$q->setMaxResults(1)->executeQuery();try{$held=$r->fetchOne()!==false;}finally{$r->closeCursor();}if($held)throw new \OCA\Mail\Exception\DraftAdmissionFailure('An approved guarded snooze holds this resource. Use its snooze status, wake or cancel action.');}
    public static function account(IDBConnection $db,int $account):void {$q=self::query($db);self::refuse($q->andWhere($q->expr()->eq('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT))));}
    /** A stock snooze must be able to add its timer after moving, without an unknown-target conflict. */
    public static function incoming(IDBConnection $db,int $mailbox):void {$q=self::query($db);self::refuse($q->andWhere(self::potential($q,$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT))));}
    private static function potential(object $q,\OCP\DB\QueryBuilder\IParameter $mailbox):object {return $q->expr()->orX($q->expr()->andX($q->expr()->eq('snooze_mailbox_id',$mailbox),$q->expr()->eq('placement_phase',$q->createNamedParameter('wire_may_start'))),$q->expr()->andX($q->expr()->eq('source_mailbox_id',$mailbox),$q->expr()->eq('wake_phase',$q->createNamedParameter('wire_may_start'))));}
    public static function mailbox(IDBConnection $db,int $mailbox,?int $uid=null):void {
        $q=self::query($db);$p=$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT);
        if($uid===null)$condition=$q->expr()->orX($q->expr()->eq('source_mailbox_id',$p),$q->expr()->eq('snooze_mailbox_id',$p),$q->expr()->eq('current_mailbox_id',$p));
        else {
            $u=$q->createNamedParameter((string)$uid);
            $condition=$q->expr()->orX($q->expr()->andX($q->expr()->eq('current_mailbox_id',$p),$q->expr()->eq('current_uid',$u)),
                $q->expr()->andX($q->expr()->eq('source_mailbox_id',$p),$q->expr()->eq('source_uid',$u),$q->expr()->eq('state',$q->createNamedParameter('placing'))),
                self::potential($q,$p));
        }self::refuse($q->andWhere($condition));
    }
    public static function named(IDBConnection $db,int $account,string $name,?int $uid=null):void {
        $q=$db->getQueryBuilder();$q->select('id','name')->from('mail_mailboxes')->where($q->expr()->eq('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('name',$q->createNamedParameter($name)))->setMaxResults(2);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}if(count($rows)!==1)throw new \RuntimeException('Native mailbox identity is unavailable.');self::mailbox($db,(int)$rows[0]['id'],$uid);
    }
    /** A rename/delete affects this folder and its native namespace subtree, including uncached intent refs. */
    public static function tree(IDBConnection $db,int $account,int $mailbox,string $name,?string $delimiter):void {
        $q=self::query($db);$conditions=[];
        foreach(['source','snooze']as$prefix){$conditions[]=$q->expr()->eq($prefix.'_mailbox_id',$q->createNamedParameter($mailbox,IQueryBuilder::PARAM_INT));$conditions[]=$q->expr()->eq($prefix.'_name',$q->createNamedParameter($name));if($delimiter!==null&&$delimiter!=='')$conditions[]=$q->expr()->like($prefix.'_name',$q->createNamedParameter($db->escapeLikeParameter($name.$delimiter).'%'));}
        self::refuse($q->andWhere($q->expr()->eq('account_id',$q->createNamedParameter($account,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->orX(...$conditions)));
    }
    /** Independent stock timer rows may skip a protected tuple, before their first flag or MOVE. */
    public static function permits(IDBConnection $db,int $mailbox,int $uid):bool {try{self::mailbox($db,$mailbox,$uid);return true;}catch(\OCA\Mail\Exception\DraftAdmissionFailure){return false;}}
    public static function runAccount(object $account,\Closure $work):mixed {$db=\OCP\Server::get(IDBConnection::class);return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($db)->runOwner($account->getUserId(),fn()=>$work($db));}
    public static function bindClient(object $client,object $account):void {self::$clients??=new \WeakMap();self::$clients[$client]=['uid'=>$account->getUserId(),'account'=>(int)$account->getId()];}
    public static function lower(object $client,string $mailbox,array $uids,\Closure $work,bool $destructive=true):mixed {
        $owner=self::$clients[$client]??null;if($owner===null)throw new \RuntimeException('Remote mutation requires an owned native client.');$db=\OCP\Server::get(IDBConnection::class);
        return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($db)->runOwner($owner['uid'],function()use($db,$owner,$mailbox,$uids,$work,$destructive):mixed {if($destructive)foreach($uids as$uid)self::named($db,$owner['account'],$mailbox,(int)$uid);return $work();});
    }
}
