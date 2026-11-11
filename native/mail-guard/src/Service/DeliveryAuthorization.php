<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Transmission\{AttemptStore,BeforeWireBlock};
/** Internal withdrawal lane: bounded metadata and queued-only CAS, with no renderer or transport dependencies. */
final class DeliveryAuthorization {
    public function __construct(private AttemptStore $store){}
    public function context(string $uid,string $id):array {
        $r=$this->store->metadata($uid,$id);
        return ['accountId'=>(int)$r['account_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>$r['source_tool'],'draftId'=>(int)$r['draft_id']];
    }
    public function queuedAfter(string $cursor,int $limit=25):array {return $this->store->queuedAfter($cursor,$limit);}
    public function due(int $limit=25):array {return $this->store->due($limit);}
    public function denied():\Throwable {return new BeforeWireBlock('Delivery authorization was withdrawn before claim.');}
    public function review(string $uid,string $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function($scope)use($uid,$id,$fence):array {
            $r=$this->store->metadata($uid,$id);
            if($r['state']==='queued'){
                $scope->assertOwned();$this->store->assertAccount($uid,(int)$r['account_id']);
                $allowed=$fence->allows($uid,(int)$r['connection_id'],$r['source_tool'],(int)$r['account_id']);
                if(!is_bool($allowed))throw new \RuntimeException('Delivery authorization is unavailable.');
                $scope->assertOwned();
                if(!$allowed){$this->store->transition($r,'blocked');$r=$this->store->metadata($uid,$id);}
            }
            return ['id'=>$r['id'],'state'=>$r['state']];
        });
    }
}
