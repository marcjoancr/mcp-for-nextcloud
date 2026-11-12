<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Snooze;
use OCA\Mail\Db\{MailboxMapper,TagProfile,ExactTagAssociations};
use OCA\Mail\Service\{AccountService,MailManager};
use OCP\{IDBConnection,IConfig};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Scalar cache identity and C3 request construction, without source body or UID guesses. */
final class SnoozeSource {
    public function __construct(private IDBConnection $db,private IConfig $config,private AccountService $accounts,private MailManager $manager,private MailboxMapper $boxes){}
    public static function generation(object $b):string {return ExactTagAssociations::generation(['sync_new_token'=>$b->getSyncNewToken(),'sync_changed_token'=>$b->getSyncChangedToken(),'sync_vanished_token'=>$b->getSyncVanishedToken()])??throw new \RuntimeException('Snooze requires verified native mailbox generations.');}
    private static function cache(object $b):string {return hash('sha256',json_encode([$b->getSyncNewToken(),$b->getSyncChangedToken(),$b->getSyncVanishedToken()],JSON_THROW_ON_ERROR));}
    private static function roles(object $a):array {return array_map(static fn(string $r):?int=>$a->{'get'.$r.'MailboxId'}(),['Snooze','Drafts','Sent','Trash','Junk','Archive']);}
    private static function profile(object $a):string {return hash('sha256',TagProfile::account($a)."\0".$a->getMcpAuthRevision());}
    public function preview(string $uid,int $message,int $at,string $zone,object $fence):array {
        if($at<=time()||$at>time()+31622400||!in_array($zone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true))throw new \InvalidArgumentException('Choose a future wake within one year and an IANA timezone.');
        $identities=(new \OCA\Mail\Db\BoundedMailMetadata($this->db,$this->config))->identities($uid,'message',[$message]);if(count($identities)!==1)throw new \RuntimeException('Snooze message unavailable.');$i=$identities[0];
        $account=$this->accounts->find($uid,$i['account_id']);$a=$account->getMailAccount();$dest=$a->getSnoozeMailboxId();if(!is_int($dest)||$dest<1)throw new \RuntimeException('Configure Snoozed messages in native Mail Account settings / Default folders first.');
        $fence->authorize($uid,['accountId'=>(int)$account->getId(),'sourceMailboxId'=>(int)$i['mailbox_id'],'snoozeMailboxId'=>$dest]);
        $roles=self::roles($a);if(in_array($dest,array_slice($roles,1),true))throw new \RuntimeException('Snooze folder must be distinct from Drafts, Sent, Trash, Junk and Archive.');
        $m=$this->manager->getMessage($uid,$message);$source=$this->boxes->findById($m->getMailboxId());$destination=$this->boxes->findById($dest);
        if($source->getId()===$a->getTrashMailboxId())throw new \RuntimeException('Snooze from Trash cannot approve an inverse move without a separate Trash hook.');
        $request=$this->request($uid,$account,$m,$source,$destination);SnoozeHolds::mailbox($this->db,(int)$source->getId(),(int)$m->getUid());
        $q=$this->db->getQueryBuilder();$q->select('uid')->from('mail_messages_snoozed')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter((int)$source->getId(),IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uid',$q->createNamedParameter((int)$m->getUid(),IQueryBuilder::PARAM_INT)))->setMaxResults(1);$r=$q->executeQuery();try{if($r->fetchOne()!==false)throw new \RuntimeException('A stock snooze schedule already holds this message.');}finally{$r->closeCursor();}
        $plan=['messageId'=>$message,'accountId'=>(int)$account->getId(),'sourceMailboxId'=>(int)$source->getId(),'snoozeMailboxId'=>$dest,'wakeAt'=>$at,'timezone'=>$zone,'request'=>$request];return $plan+['revision'=>hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR))];
    }
    private function request(string $uid,object $account,object $m,object $source,object $dest):array {
        foreach([$source,$dest]as$b)if($b->getAccountId()!==$account->getId()||!$b->getSelectable())throw new \RuntimeException('Snooze requires selectable folders in one owned account.');
        if($source->getId()===$dest->getId()||$source->getName()===$dest->getName()||strlen($source->getName())>255||strlen($dest->getName())>255)throw new \RuntimeException('Snooze folders must be distinct.');
        $g=self::generation($source);$a=$account->getMailAccount();
        if($m->getTagCacheGeneration()!==$g||$m->getTagCacheProfile()!==TagProfile::account($a))throw new \RuntimeException('Message cache provenance is unavailable.');
        return ['account_id'=>(int)$account->getId(),'message_id'=>(int)$m->getId(),'mailbox_id'=>(int)$source->getId(),'mailbox'=>$source->getName(),'generation'=>$g,'uid'=>(string)$m->getUid(),'profile_revision'=>self::profile($a),'cache_revision'=>self::cache($source),'action'=>'move','destination_mailbox_id'=>(int)$dest->getId(),'destination_mailbox'=>$dest->getName(),'destination_generation'=>self::generation($dest),'destination_cache_revision'=>self::cache($dest),'roles_revision'=>hash('sha256',json_encode(self::roles($a),JSON_THROW_ON_ERROR)),'requires_trash_hook'=>false];
    }
    public function verify(array $r):object {
        try{$account=$this->accounts->find($r['user_id'],(int)$r['account_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException $e){throw new \OCA\Mail\Transmission\BeforeWireBlock('Original snooze account disappeared.',0,$e);}$a=$account->getMailAccount();
        if(self::profile($a)!==$r['profile_revision']||hash('sha256',json_encode(self::roles($a),JSON_THROW_ON_ERROR))!==$r['roles_revision']||$a->getSnoozeMailboxId()!==(int)$r['snooze_mailbox_id'])throw new \OCA\Mail\Transmission\BeforeWireBlock('Snooze profile or roles changed.');
        foreach(['source','snooze']as$prefix){try{$b=$this->boxes->findById((int)$r[$prefix.'_mailbox_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException $e){throw new \OCA\Mail\Transmission\BeforeWireBlock('Original snooze folder disappeared.',0,$e);}if($b->getAccountId()!==$account->getId()||!$b->getSelectable()||$b->getName()!==$r[$prefix.'_name']||self::generation($b)!==$r[$prefix.'_generation'])throw new \OCA\Mail\Transmission\BeforeWireBlock('The exact original snooze folder changed.');}return $account;
    }
    public function resolve(array $r):?int {
        $account=$this->verify($r);$b=$this->boxes->findById((int)$r['current_mailbox_id']);if(self::generation($b)!==$r['current_generation'])throw new \RuntimeException('Snooze current generation changed.');
        $q=$this->db->getQueryBuilder();$q->select('id','tag_cache_generation','tag_cache_profile')->from('mail_messages')->where($q->expr()->eq('mailbox_id',$q->createNamedParameter((int)$r['current_mailbox_id'],IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('uid',$q->createNamedParameter((int)$r['current_uid'],IQueryBuilder::PARAM_INT)))->setMaxResults(2);$res=$q->executeQuery();try{$rows=$res->fetchAll();}finally{$res->closeCursor();}
        if($rows===[])return null;if(count($rows)!==1||$rows[0]['tag_cache_generation']!==$r['current_generation']||$rows[0]['tag_cache_profile']!==TagProfile::account($account->getMailAccount()))throw new \RuntimeException('Snooze cache identity is ambiguous or unproven.');return (int)$rows[0]['id'];
    }
    public function wake(array $r):?array {$account=$this->verify($r);$id=$this->resolve($r);if($id===null)return null;return $this->request($r['user_id'],$account,$this->manager->getMessage($r['user_id'],$id),$this->boxes->findById((int)$r['snooze_mailbox_id']),$this->boxes->findById((int)$r['source_mailbox_id']));}
}
