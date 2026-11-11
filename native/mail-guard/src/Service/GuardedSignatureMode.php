<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Db\{MailAccountMapper,AliasMapper};
use OCA\Mail\Drafts\DraftCoordinator;
use OCP\{IDBConnection,IConfig};
/** A local, owner-serialized dirty-field transition. No transport or content conversion. */
final class GuardedSignatureMode {
    public function __construct(private MailAccountMapper $accounts,private AliasMapper $aliases,private IDBConnection $db,private IConfig $config){}
    private function entities(string $uid,int $account,?int $alias):array {
        $a=$this->accounts->find($uid,$account);
        if($a->getUserId()!==$uid||$a->getProvisioningId()!==null)throw new \RuntimeException('Signature identity unavailable or provisioned.');
        $e=$alias===null?$a:$this->aliases->find($alias,$uid);
        if($alias!==null&&((int)$e->getAccountId()!==$account||$e->getProvisioningId()!==null))throw new \RuntimeException('Signature identity unavailable or provisioned.');
        if(!in_array($e->getSignatureMode(),[0,1],true)||strlen($e->getSignature()??'')>2097152)throw new \RuntimeException('Native signature mode or size unsupported.');
        return [$a,$e];
    }
    private function row(string $uid,int $account,?int $alias,#[\SensitiveParameter] object $a,#[\SensitiveParameter] object $e):array {
        $key=$this->config->getSystemValueString('secret');if($key==='')throw new \RuntimeException('Signature revision unavailable.');
        $revision=[$uid,$account,$alias,$a->getMcpAuthRevision(),$a->getName(),$a->getEmail(),$a->getProvisioningId(),$a->getSmimeCertificateId(),$a->getEditorMode(),$a->isSignatureAboveQuote(),$a->getSignatureMode(),$a->getSignature(),$e->getSignatureMode(),$e->getSignature(),$e->getSmimeCertificateId(),$e->getProvisioningId()];
        if($alias!==null)$revision=array_merge($revision,[$e->getAccountId(),$e->getName(),$e->getAlias()]);
        return ['account_id'=>$account,'alias_id'=>$alias,'signature_mode'=>$e->getSignatureMode(),'revision'=>hash_hmac('sha256',serialize($revision),$key)];
    }
    public function read(string $uid,int $account,?int $alias,\Closure $authorize):array {
        return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function()use($uid,$account,$alias,$authorize):array{$authorize();[$a,$e]=$this->entities($uid,$account,$alias);$row=$this->row($uid,$account,$alias,$a,$e);$authorize();return $row;});
    }
    /** Caller owns the shared journal transaction and must fence immediately before its commit. */
    public function write(string $uid,int $account,?int $alias,int $mode,string $revision,\Closure $authorize):array {
        if(!in_array($mode,[0,1],true))throw new \InvalidArgumentException('Only native signature modes 0 and 1 are supported.');
        $scope=DraftCoordinator::forDatabase($this->db)->scope();if($scope->uid!==$uid||!$this->db->inTransaction())throw new \RuntimeException('Signature changes require the owned journal transaction.');
        $authorize();[$a,$e]=$this->entities($uid,$account,$alias);$before=$this->row($uid,$account,$alias,$a,$e);
        if(!hash_equals($before['revision'],$revision))throw new \RuntimeException('Signature identity changed. Review a new plan.');
        $authorize();$scope->assertOwned();$e->setSignatureMode($mode);
        if($alias===null)$this->accounts->update($e);else $this->aliases->update($e);
        [$a,$e]=$this->entities($uid,$account,$alias);$after=$this->row($uid,$account,$alias,$a,$e);
        if($after['signature_mode']!==$mode)throw new \RuntimeException('Signature mode readback differs.');
        $authorize();$scope->assertOwned();return $after;
    }
    public function assertOwner(string $uid):void {$scope=DraftCoordinator::forDatabase($this->db)->scope();if($scope->uid!==$uid)throw new \RuntimeException('Signature owner changed.');$scope->assertOwned();}
}
