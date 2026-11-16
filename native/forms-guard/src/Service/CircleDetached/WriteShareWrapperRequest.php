<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

use OCA\Circles\Model\ShareWrapper;
use OCP\Share\IShare;

/** The pinned write request API can only obtain an operation-scoped WriteQueryBuilder. */
final class WriteShareWrapperRequest extends \OCA\Circles\Db\ShareWrapperRequest {
    private ?array $intent=null;
    public function __construct(private readonly Lease $lease,private readonly ShareWrapperRequest $reader,private readonly \Closure $assertFresh,private readonly \Closure $beforeWrite,private readonly ?ShareWriteBoundary $transferCapability=null) {}
    public function within(string $action,int $targetId,IShare $share,bool $create,\Closure $write,?array $binding=null,?\Closure $proof=null):mixed {
        if($this->intent!==null){throw new \RuntimeException('Nested native Circle write intents are unavailable');}
        $this->intent=['action'=>$action,'target'=>$targetId,'share'=>$share,'create'=>$create,'binding'=>$binding,'proof'=>$proof];
        try{return$write();}finally{$this->intent=null;}
    }
    public function withinChild(int $targetParentId,array $binding,array $sourceChild,\Closure $proof,\Closure $write):mixed {
        if($this->intent!==null||$targetParentId<1){throw new \RuntimeException('Nested native Circle child-create intents are unavailable');}
        $this->intent=['action'=>'insert-child','target'=>$targetParentId,'share'=>null,'create'=>true,'binding'=>$binding,'proof'=>$proof,'source_child'=>$sourceChild,'saved'=>false];
        try{return$write();}finally{$this->intent=null;}
    }
    public function getQueryBuilder(): WriteQueryBuilder {
        $intent=$this->intent??throw new \RuntimeException('Native Circle write query has no managed intent');
        $share=$intent['share']??throw new \RuntimeException('Native Circle write share is unavailable');
        return new WriteQueryBuilder($this->lease,$intent['action'],$intent['target'],$share->getSharedWith(),$share->getShareOwner(),$share->getNodeId(),$share,$intent['create'],$this->assertFresh,$this->beforeWrite,$intent['binding'],$intent['proof'],$this->transferCapability);
    }
    public function save(IShare $share,int $parentId=0):int {
        $intent=$this->intent??throw new \RuntimeException('Native Circle write query has no managed intent');
        if($intent['action']==='insert-child'){
            if($intent['saved']||$parentId!==$intent['target'])throw new \RuntimeException('Native Circle child-create request changed');
            $child=$intent['source_child'];
            if(!is_array($child)||($child['share_type']??null)!==7||($child['share_with']??null)!==$intent['binding']['source_share_with'])throw new \RuntimeException('Native Circle source child seed changed');
            $share->setPermissions(0);$share->setStatus((int)$child['accepted']);
            if(($child['expiration']??null)!==null){$share->setExpirationDate(new \DateTime((string)$child['expiration']));}
            if(($child['attributes']??null)!==null){$attributes=$share->newAttributes();foreach(json_decode((string)$child['attributes'],true,64,JSON_THROW_ON_ERROR)as$attribute){if(!is_array($attribute)||count($attribute)!==3)throw new \RuntimeException('Native Circle child attributes are malformed');$attributes->setAttribute($attribute[0],$attribute[1],$attribute[2]);}$share->setAttributes($attributes);}else{$share->setAttributes(null);}
            $this->intent['saved']=true;$this->intent['share']=$share;
            return parent::save($share,$parentId);
        }
        if($intent['action']==='insert')return parent::save($share,$parentId);
        throw new \RuntimeException('Native Circle request save is outside the active write');
    }
    public function getShareById(int $shareId,?\OCA\Circles\Model\FederatedUser $federatedUser=null):\OCA\Circles\Model\ShareWrapper {return$this->reader->getShareById($shareId,$federatedUser);}
    public function getChild(\OCA\Circles\Model\FederatedUser $federatedUser,int $shareId):\OCA\Circles\Model\ShareWrapper {return$this->reader->getChild($federatedUser,$shareId);}
    public function searchShare(string $singleId,int $nodeId):\OCA\Circles\Model\ShareWrapper {return$this->reader->searchShare($singleId,$nodeId);}
    public function getSharesInFolder(?\OCA\Circles\Model\FederatedUser $federatedUser,\OCP\Files\Folder $node,bool $reshares,bool $shallow=true):array {return$this->reader->getSharesInFolder($federatedUser,$node,$reshares,$shallow);}
    public function getItemFromRequest(\OCA\Circles\Db\CoreQueryBuilder $qb): ShareWrapper {throw new \RuntimeException('Circle write request cannot hydrate models');}
    public function getItemsFromRequest(\OCA\Circles\Db\CoreQueryBuilder $qb): array {throw new \RuntimeException('Circle write request cannot hydrate models');}
}
