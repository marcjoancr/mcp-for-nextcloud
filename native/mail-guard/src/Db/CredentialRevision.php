<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCA\Mail\Drafts\{DraftCoordinator,DraftScope};
use OCA\Mail\Exception\DraftAdmissionFailure;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Credential/identity writes and the private revision commit together, without secret digests. */
trait CredentialRevision {
    /** Scoped only to one exact native follow-system entity, never an arbitrary SQL callback. */
    private ?array $mcpSieveFollowingFence=null;
    public function assertSieveFollowing(string $uid,int $id,bool $following,int $revision):void {
        $scope=DraftCoordinator::forDatabase($this->db)->scope();if($scope->uid!==$uid)throw new DraftAdmissionFailure('Sieve owner changed.');$row=$this->mcpCredentialRow($id);
        if($row['user_id']!==$uid||(int)$row['mcp_auth_revision']!==$revision)throw new DraftAdmissionFailure('Sieve account revision changed.');
        $current=$this->find($uid,$id);if($current->getOutOfOfficeFollowsSystem()!==$following)\OCA\Mail\Snooze\SnoozeHolds::account($this->db,$id);$scope->assertOwned();
    }
    public function updateSieveFollowing(#[\SensitiveParameter] MailAccount $entity,object $fence,array $origin):Entity {
        if($this->mcpSieveFollowingFence!==null||array_keys($entity->getUpdatedFields())!==['oooFollowsSystem']||(int)$entity->getId()!==($origin['accountId']??null))throw new DraftAdmissionFailure('Only the exact Sieve following transition is allowed.');
        $uid=$entity->getUserId();$this->assertSieveFollowing($uid,(int)$entity->getId(),$entity->getOutOfOfficeFollowsSystem(),$entity->getMcpAuthRevision());$fence->authorize($uid,$origin);$this->mcpSieveFollowingFence=[$entity,$fence,$origin];
        try{return $this->update($entity);}finally{$this->mcpSieveFollowingFence=null;}
    }
    private function mcpAuthorizeSieveFollowing(#[\SensitiveParameter] Entity $entity):void {
        if($this->mcpSieveFollowingFence===null)return;[$expected,$fence,$origin]=$this->mcpSieveFollowingFence;if($entity!==$expected)throw new DraftAdmissionFailure('Sieve entity changed.');$fence->authorize($entity->getUserId(),$origin);
    }

    private function mcpCredentialFieldsChanged(array $fields):bool {
        if(array_key_exists('mcpAuthRevision',$fields))throw new DraftAdmissionFailure('The private native authorization revision cannot be supplied.');
        return array_intersect(array_keys($fields),['sieveEnabled','sieveHost','sievePort','sieveSslMode','sieveUser','sievePassword','oooFollowsSystem','userId','name','email','protocol','authMethod','inboundPassword','outboundPassword','oauthAccessToken','oauthRefreshToken','oauthTokenTtl','inboundHost','inboundPort','inboundSslMode','inboundUser','outboundHost','outboundPort','outboundSslMode','outboundUser'])!==[];
    }
    private function mcpCredentialRow(int $id,bool $lock=false):array {
        $q=$this->db->getQueryBuilder();$q->select('user_id','mcp_auth_revision')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        (new \OCA\Mail\Lifecycle\AccountVisibility($this->db))->excludePending($q,'id');
        if($lock)$q->forUpdate();$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        if($row===false)throw new DraftAdmissionFailure('The native account no longer exists.');return $row;
    }
    private function mcpStoreCredentialRevision(int $id,int $revision):void {
        $q=$this->db->getQueryBuilder();$q->update('mail_accounts')->set('mcp_auth_revision',$q->createNamedParameter($revision,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        if($q->executeStatement()!==1)throw new \RuntimeException('Native authorization revision could not be stored.');
        $q=$this->db->getQueryBuilder();$q->delete('mail_mcp_body_index')->where($q->expr()->eq('account_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function mcpCredentialWritten(#[\SensitiveParameter] Entity $saved,int $revision):Entity {
        $saved->mcpCaptureAuthRevision($revision);
        // Every dirty field was committed. Later native cache work must not re-submit old credentials.
        $saved->resetUpdatedFields();
        return $saved;
    }
    public function insert(#[\SensitiveParameter] Entity $entity):Entity {
        if(!$entity instanceof MailAccount)return parent::insert($entity);
        $changed=$this->mcpCredentialFieldsChanged($entity->getUpdatedFields());
        $guard=DraftCoordinator::forDatabase($this->db);$uid=$entity->getUserId();
        return \OCA\Mail\Lifecycle\AccountCapture::insert($this->db,$uid,fn() => $guard->runOwner($uid,function(DraftScope $scope)use($entity,$guard,$uid,$changed):Entity {
            $guard->assertNoTransaction();$this->db->beginTransaction();
            try{
                if($entity->getUserId()!==$uid)throw new DraftAdmissionFailure('Native account ownership changed.');
                $scope->markWrite();$saved=parent::insert($entity);
                if($changed)$this->mcpStoreCredentialRevision((int)$saved->getId(),1);
                $scope->assertOwned();$this->db->commit();return $this->mcpCredentialWritten($saved,$changed?1:0);
            }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        }));
    }
    public function update(#[\SensitiveParameter] Entity $entity):Entity {
        if(!$entity instanceof MailAccount)return parent::update($entity);
        if(!$this->mcpCredentialFieldsChanged($entity->getUpdatedFields())){
            $id=(int)$entity->getId();$uid=$this->mcpCredentialRow($id)['user_id'];
            return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function(DraftScope $scope)use($entity,$id,$uid):Entity {if($this->mcpCredentialRow($id)['user_id']!==$uid||$entity->getUserId()!==$uid)throw new DraftAdmissionFailure('Account ownership changed.');if(array_intersect(array_keys($entity->getUpdatedFields()),['snoozeMailboxId','draftsMailboxId','sentMailboxId','trashMailboxId','junkMailboxId','archiveMailboxId'])!==[])\OCA\Mail\Snooze\SnoozeHolds::account($this->db,$id);$scope->markWrite();return parent::update($entity);});
        }
        $id=(int)$entity->getId();$guard=DraftCoordinator::forDatabase($this->db);
        $guard->assertNoTransaction();$uid=$this->mcpCredentialRow($id)['user_id'];
        return $guard->runOwner($uid,function(DraftScope $scope)use($entity,$guard,$uid,$id):Entity {
            $guard->assertNoTransaction();$this->db->beginTransaction();
            try{
                $row=$this->mcpCredentialRow($id,true);
                if($row['user_id']!==$uid||$entity->getUserId()!==$uid||$entity->getId()!==$id)throw new DraftAdmissionFailure('Credential writes must preserve native account ownership.');
                \OCA\Mail\Snooze\SnoozeHolds::account($this->db,$id);
                $current=filter_var($row['mcp_auth_revision'],FILTER_VALIDATE_INT);
                if($current===false||$current<0||$current>=PHP_INT_MAX-1)throw new DraftAdmissionFailure('Native authorization revision unavailable.');
                $scope->markWrite();$saved=parent::update($entity);$this->mcpStoreCredentialRevision($id,$current+1);
                $this->mcpAuthorizeSieveFollowing($saved);$scope->assertOwned();$this->db->commit();return $this->mcpCredentialWritten($saved,$current+1);
            }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        });
    }
}
