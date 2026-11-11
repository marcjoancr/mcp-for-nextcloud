<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCP\AppFramework\Db\Entity;
use OCA\Mail\Drafts\DraftCoordinator;
trait HeldAliasWrites {
    private function mcpAliasWrite(Entity $entity,\Closure $effect):Entity {
        $id=(int)$entity->getId();$accountId=$this->findAccountIdForAlias($id);$uid=DeliveryHolds::owner($this->db,$accountId);
        return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function($scope)use($id,$accountId,$entity,$effect):Entity {
            if($this->findAccountIdForAlias($id)!==$accountId||$entity->getAccountId()!==$accountId)throw new \RuntimeException('Alias owner changed.');DeliveryHolds::assertFree($this->db,'alias_id',$id);$scope->markWrite();return $effect();
        });
    }
    public function update(Entity $entity):Entity {return $this->mcpAliasWrite($entity,fn():Entity=>parent::update($entity));}
    public function delete(Entity $entity):Entity {return $this->mcpAliasWrite($entity,fn():Entity=>parent::delete($entity));}
}
