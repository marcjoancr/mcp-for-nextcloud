<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCA\Mail\Drafts\{DraftCoordinator,DraftScope};
use OCA\Mail\Exception\DraftAdmissionFailure;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** All association/metadata mutations share the lifecycle's owner session. */
trait GuardedDraftAttachments {
    public function draftCoordinator():DraftCoordinator {return DraftCoordinator::forDatabase($this->db);}
    private function mcpAttachmentRow(int $id):?array {
        $q=$this->db->getQueryBuilder();$q->select('id','user_id','local_message_id')->from('mail_attachments')->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}return $row===false?null:$row;
    }
    public function mcpAttachmentState(string $uid,int $id):?array {
        if($this->draftCoordinator()->scope()->uid!==$uid)throw new DraftAdmissionFailure('Attachment owner changed.');
        $row=$this->mcpAttachmentRow($id);if($row!==null&&$row['user_id']!==$uid)throw new DraftAdmissionFailure('Attachment ownership changed.');return $row;
    }
    public function mcpAttachmentParent(string $uid,int $id):?array {
        \OCA\Mail\Transmission\DeliveryHolds::assertFree($this->db,'draft_id',$id);
        $q=$this->db->getQueryBuilder();$q->select('m.id','m.mcp_draft_only','a.user_id')->from('mail_local_messages','m')->innerJoin('m','mail_accounts','a',$q->expr()->eq('a.id','m.account_id'))->where($q->expr()->eq('m.id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)));
        $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
        if($row===false)return null;
        if($row['user_id']!==$uid)throw new DraftAdmissionFailure('Attachment target is not owned by this user.');return $row;
    }
    public function mcpValidateAttachmentSet(string $uid,int $target,array $ids):void {
        $scope=$this->draftCoordinator()->scope();if($scope->uid!==$uid)throw new DraftAdmissionFailure('Attachment owner changed.');
        $parent=$this->mcpAttachmentParent($uid,$target);if($parent===null)throw new DraftAdmissionFailure('Attachment target no longer exists.');
        $protected=(bool)$parent['mcp_draft_only'];
        if($protected&&!$scope->permits($target,['write','attachment']))$scope->admit($target,'attachment');
        foreach(array_unique($ids) as $id){
            $row=$this->mcpAttachmentRow((int)$id);
            if($row===null||$row['user_id']!==$uid)throw new DraftAdmissionFailure('An attachment is no longer owned or available.');
            $source=$row['local_message_id']===null?null:(int)$row['local_message_id'];
            if($source!==null&&$source!==$target){
                $sourceParent=$this->mcpAttachmentParent($uid,$source);
                if($protected||$sourceParent===null||(bool)$sourceParent['mcp_draft_only'])throw new DraftAdmissionFailure('A protected attachment cannot be taken by another message.');
            }
        }
    }
    public function mcpAdmitAttachmentRemoval(string $uid,int $parent,bool $explicitFile=false):void {
        $row=$this->mcpAttachmentParent($uid,$parent);$scope=$this->draftCoordinator()->scope();
        if($row!==null&&(bool)$row['mcp_draft_only']&&!$scope->permits($parent,['write','convert','delete','attachment'])){if(!$explicitFile)throw new DraftAdmissionFailure('Protected attachment removal requires its current native lifecycle.');$scope->admit($parent,'attachment');}
    }
    public function insert(Entity $entity):Entity {
        if(!$entity instanceof LocalAttachment)return parent::insert($entity);
        $uid=$entity->getUserId();return $this->draftCoordinator()->runOwner($uid,function(DraftScope $scope)use($entity,$uid):Entity {
            if($entity->getLocalMessageId()!==null){$parent=$this->mcpAttachmentParent($uid,(int)$entity->getLocalMessageId());if($parent===null||((bool)$parent['mcp_draft_only']&&!$scope->permits((int)$parent['id'],['write'])))throw new DraftAdmissionFailure('Attachment insertion requires its current message.');}
            $scope->markWrite();return parent::insert($entity);
        });
    }
    public function update(Entity $entity):Entity {
        if(!$entity instanceof LocalAttachment)return parent::update($entity);
        $uid=$entity->getUserId();return $this->draftCoordinator()->runOwner($uid,function(DraftScope $scope)use($entity,$uid):Entity {
            $row=$this->mcpAttachmentRow((int)$entity->getId());if($row===null||$row['user_id']!==$uid)throw new DraftAdmissionFailure('Attachment ownership changed.');
            $old=$row['local_message_id']===null?null:(int)$row['local_message_id'];$new=$entity->getLocalMessageId();
            if($new!==null)$this->mcpValidateAttachmentSet($uid,(int)$new,[(int)$entity->getId()]);
            elseif($old!==null)$this->mcpAdmitAttachmentRemoval($uid,$old);
            $scope->markWrite();return parent::update($entity);
        });
    }
    public function delete(Entity $entity):Entity {
        if(!$entity instanceof LocalAttachment)return parent::delete($entity);
        $uid=$entity->getUserId();return $this->draftCoordinator()->runOwner($uid,function(DraftScope $scope)use($entity,$uid):Entity {
            $row=$this->mcpAttachmentRow((int)$entity->getId());
            if($row!==null){if($row['user_id']!==$uid)throw new DraftAdmissionFailure('Attachment ownership changed.');if($row['local_message_id']!==null)$this->mcpAdmitAttachmentRemoval($uid,(int)$row['local_message_id']);}
            $scope->markWrite();return parent::delete($entity);
        });
    }
}
