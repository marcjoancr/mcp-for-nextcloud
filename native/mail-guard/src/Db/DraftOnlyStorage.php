<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
use OCP\AppFramework\Db\{Entity,DoesNotExistException};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\Mail\Exception\ClientException;

/** Persist draft-only provenance under the same row lock as the native mapper update. */
trait DraftOnlyStorage {
    private function requireOrdinaryDraft(object $draft,array $stored=[]):void {
        if($draft->getSendAt()!==null||($stored['send_at']??null)!==null
            ||$draft->getSmimeSign()||$draft->getSmimeEncrypt()||$draft->isPgpMime()
            ||($stored['smime_sign']??false)||($stored['smime_encrypt']??false)||($stored['pgp_mime']??false)){
            throw new ClientException('Draft-only editing requires an ordinary unscheduled draft.');
        }
    }
    public function draftCoordinator():\OCA\Mail\Drafts\DraftCoordinator {
        return \OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->db);
    }
    public function insert(Entity $entity):Entity {
        if(!$entity instanceof LocalMessage)return parent::insert($entity);
        return $this->draftCoordinator()->runMessage($entity,function($scope)use($entity):Entity {
            if($entity->getMcpDraftOnly()){
                $this->requireOrdinaryDraft($entity);
                if($entity->getType()!==LocalMessage::TYPE_DRAFT)throw new ClientException('Draft-only content cannot enter the outbox.');
                $entity->setFailed(true);
            }
            $scope->markWrite();$result=parent::insert($entity);$scope->admit((int)$result->getId(),'write');return $result;
        });
    }
    public function update(Entity $entity):Entity {
        if(!$entity instanceof LocalMessage)return parent::update($entity);
        return $this->draftCoordinator()->runMessage($entity,function($scope)use($entity):Entity {
            \OCA\Mail\Transmission\DeliveryHolds::assertFree($this->db,'draft_id',(int)$entity->getId());
            $this->db->beginTransaction();
            try {
                $q=$this->db->getQueryBuilder();$q->select('mcp_draft_only','type','account_id','send_at','smime_sign','smime_encrypt','pgp_mime')->from('mail_local_messages')->where($q->expr()->eq('id',$q->createNamedParameter($entity->getId(),IQueryBuilder::PARAM_INT)));
                $q->forUpdate();$r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
                if($row===false)throw new DoesNotExistException('Native draft source no longer exists.');
                $protected=(bool)$row['mcp_draft_only'];
                if($protected||$entity->getMcpDraftOnly()){
                    if(!$scope->permits((int)$entity->getId(),['write','convert','attachment']))throw new \OCA\Mail\Exception\DraftAdmissionFailure('Protected content requires its coordinated native lifecycle.');
                    $this->requireOrdinaryDraft($entity,$row);
                    if((int)$row['type']!==LocalMessage::TYPE_DRAFT||$entity->getType()!==LocalMessage::TYPE_DRAFT)throw new ClientException('Draft-only content cannot enter the outbox.');
                    if(!$protected&&(int)$row['account_id']!==$entity->getAccountId())throw new ClientException('Draft identity changed before protection.');
                    $entity->setMcpDraftOnly(true);$entity->setFailed(true);
                }
                $scope->markWrite();$result=parent::update($entity);$this->db->commit();return $result;
            }catch(\Throwable $error){$this->db->rollBack();throw $error;}
        });
    }
    public function delete(Entity $entity):Entity {
        if(!$entity instanceof LocalMessage)return parent::delete($entity);
        return $this->draftCoordinator()->runMessage($entity,function($scope)use($entity):Entity {
            \OCA\Mail\Transmission\DeliveryHolds::assertFree($this->db,'draft_id',(int)$entity->getId());
            $q=$this->db->getQueryBuilder();$q->select('mcp_draft_only')->from('mail_local_messages')->where($q->expr()->eq('id',$q->createNamedParameter($entity->getId(),IQueryBuilder::PARAM_INT)));
            $r=$q->executeQuery();try{$row=$r->fetch();}finally{$r->closeCursor();}
            if($row!==false&&(bool)$row['mcp_draft_only']&&!$scope->permits((int)$entity->getId(),['delete','convert']))throw new \OCA\Mail\Exception\DraftAdmissionFailure('Protected deletion requires its current native lifecycle.');
            $scope->markWrite();return parent::delete($entity);
        });
    }
}
