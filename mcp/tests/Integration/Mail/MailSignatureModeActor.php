<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IDBConnection};
final class MailSignatureModeActor {
    public static function row(string $table,int $id):array {$q=Server::get(IDBConnection::class)->getQueryBuilder();$q->select('*')->from($table)->where($q->expr()->eq('id',$q->createNamedParameter($id)));$res=$q->executeQuery();try{return $res->fetch();}finally{$res->closeCursor();}}
    public static function run(string $uid):array {
        if(!class_exists('OCA\\Mail\\Service\\GuardedSignatureMode'))return ['implementation_available'=>false];
        $db=Server::get(IDBConnection::class);$accounts=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$account=$accounts->findByUserId($uid)[0];$id=(int)$account->getId();$mode=$account->getSignatureMode();$aliases=Server::get('OCA\\Mail\\Db\\AliasMapper');$alias=new \OCA\Mail\Db\Alias();$alias->setAccountId($id);$alias->setAlias('signature-mode-test@example.test');$alias->setName('Signature mode preservation');$alias->setSignature("<p>literal signature &amp;\nbytes</p>");$alias->setSignatureMode(0);$alias->setSmimeCertificateId(123);$alias=$aliases->insert($alias);$aid=(int)$alias->getId();
        $secondary=MailErasureActor::account($uid);$service=Server::get('OCA\\Mail\\Service\\GuardedSignatureMode');$out=['implementation_available'=>true];$fence=static function():void{};
        $apply=function(?int $target,int $next,string $revision,\Closure $guard)use($service,$uid,$id,$db):array{return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($db)->runOwner($uid,function()use($service,$uid,$id,$target,$next,$revision,$guard,$db):array{$db->beginTransaction();try{$r=$service->write($uid,$id,$target,$next,$revision,$guard);$guard();$db->commit();return $r;}catch(\Throwable $e){$db->rollBack();throw $e;}});};
        try{
            $out['independent_owner_refused']=\OCA\Mail\Drafts\DraftCoordinator::forDatabase($db)->runOwner($uid,fn()=>MailActor::run($uid,'signature-mode-contender',['account_id'=>$id])['refused']);
            $out['owner_released']=isset($service->read($uid,$id,null,$fence)['revision']);
            foreach([null,$aid]as$target){$table=$target===null?'mail_accounts':'mail_aliases';$rid=$target??$id;$before=self::row($table,$rid);$p=$service->read($uid,$id,$target,$fence);$next=1-$p['signature_mode'];$written=$apply($target,$next,$p['revision'],$fence);$after=self::row($table,$rid);unset($before['signature_mode'],$after['signature_mode']);$out[$target===null?'account_preserved':'alias_preserved']=$before===$after&&$written['signature_mode']===$next;$out['serialized_mode']=($target===null?$accounts->find($uid,$id)->toJson():$aliases->find($aid,$uid)->jsonSerialize())['signatureMode']===$next;
                try{$apply($target,1-$next,$p['revision'],$fence);$out['stale_refused']=false;}catch(\Throwable){$out['stale_refused']=true;}
            }
            $out['wrong_owner_refused']=self::refused(fn()=>$service->read(MailActor::BOB,$id,$aid,$fence));$other=$accounts->findByUserId(MailActor::BOB)[0];$out['alias_owner_refused']=self::refused(fn()=>$service->read(MailActor::BOB,(int)$other->getId(),$aid,$fence));$out['wrong_account_refused']=self::refused(fn()=>$service->read($uid,(int)$other->getId(),$aid,$fence));
            $p=$service->read($uid,$id,$aid,$fence);$out['invalid_refused']=true;foreach([-1,2,99]as$n)$out['invalid_refused']=$out['invalid_refused']&&self::refused(fn()=>$apply($aid,$n,$p['revision'],$fence));
            $out['withdrawal_rollback']=true;foreach([1,2,3,4]as$at){$before=self::row('mail_aliases',$aid);$calls=0;$guard=function()use(&$calls,$at):void{if(++$calls===$at)throw new \RuntimeException('withdrawn');};$refused=self::refused(fn()=>$apply($aid,1-$p['signature_mode'],$p['revision'],$guard));$out['withdrawal_rollback']=$out['withdrawal_rollback']&&$refused&&self::row('mail_aliases',$aid)===$before;}
            $out['alias_account_refused']=self::refused(fn()=>$service->read($uid,(int)$secondary->getId(),$aid,$fence));$secondary->setProvisioningId(1);$accounts->update($secondary);$out['provisioned_refused']=self::refused(fn()=>$service->read($uid,(int)$secondary->getId(),null,$fence));
            // Pinned alias schema has no provisioning_id column, although its entity exposes the field.
            // Exercise the native defensive refusal without inventing a column or changing the schema.
            $property=new \ReflectionProperty($service,'aliases');$property->setValue($service,new class($aliases)extends \OCA\Mail\Db\AliasMapper {public function __construct(private object $original){}public function find(int $aliasId,string $currentUserId):\OCA\Mail\Db\Alias {$e=$this->original->find($aliasId,$currentUserId);$e->setProvisioningId(1);return $e;}});
            try{$out['alias_provisioned_refused']=self::refused(fn()=>$service->read($uid,$id,$aid,$fence));}finally{$property->setValue($service,$aliases);}
            $out['operator_off']=!Server::get(\OCP\IConfig::class)->getSystemValueBool('app.mail.mcp-transmission.enabled',false);return $out;
        }finally{$accounts->delete($accounts->find($uid,(int)$secondary->getId()));$alias=$aliases->find($aid,$uid);$aliases->delete($alias);$fresh=$accounts->find($uid,$id);$fresh->setSignatureMode($mode);$accounts->update($fresh);}
    }
    private static function refused(\Closure $work):bool {try{$work();return false;}catch(\Throwable){return true;}}
}
