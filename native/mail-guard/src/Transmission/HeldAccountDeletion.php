<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCP\AppFramework\Db\Entity;
use OCA\Mail\Drafts\DraftCoordinator;
trait HeldAccountDeletion {
    public function delete(Entity $entity):Entity {
        $id=(int)$entity->getId();$uid=DeliveryHolds::owner($this->db,$id);
        return DraftCoordinator::forDatabase($this->db)->runOwner($uid,function($scope)use($id,$entity,$uid):Entity {if(DeliveryHolds::owner($this->db,$id)!==$uid)throw new \RuntimeException('Account owner changed.');DeliveryHolds::assertFree($this->db,'account_id',$id);$store=new \OCA\Mail\Lifecycle\MailErasureStore($this->db);return $store->transaction(function()use($scope,$store,$uid,$id,$entity):Entity {$scope->markWrite();$saved=parent::delete($entity);$store->purgeAccount($uid,$id);$scope->assertOwned();return $saved;});});
    }
}
