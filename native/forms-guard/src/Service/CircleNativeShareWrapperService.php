<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCA\Circles\Exceptions\ShareWrapperNotFoundException;
use OCA\Circles\Model\{FederatedUser,ShareWrapper};
use OCP\Files\Folder;
use OCA\Circles\Service\ShareWrapperService;
use OCP\Share\IShare;
use OCA\Forms\Mcp\Service\CircleDetached\WriteShareWrapperRequest;

/** Detached service facade; native SQL request is reached only after the fresh managed-share callback. */
final class CircleNativeShareWrapperService extends ShareWrapperService {
    private ?IShare $currentShare=null;
    private ?string $currentRecipient=null;
    private array $childSnapshots=[];
    private bool $childGrantConsumed=false;
    public function __construct(private readonly \OCA\Circles\Db\ShareWrapperRequest $request,private readonly WriteShareWrapperRequest $writeRequest,private readonly \OCA\Forms\Mcp\Service\CircleDetached\ScopedFederatedUserService $federatedUsers,private readonly ?\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary $transferCapability,private readonly ShareWrapperService $cacheOwner,private readonly \Closure $beforeWrite,private readonly \Closure $assertFresh,private readonly string $actorUid) {
        (new \ReflectionProperty(ShareWrapperService::class,'shareWrapperRequest'))->setValue($this,$writeRequest);
    }
    public function current(IShare $share,?string $recipient=null):void {$this->currentShare=$share;$this->currentRecipient=$recipient;$this->childSnapshots=[];}
    public function searchShare(string $singleId,int $nodeId):ShareWrapper {($this->assertFresh)();return $this->request->searchShare($singleId,$nodeId);}
    public function getShareById(int $shareId,?FederatedUser $federatedUser=null):ShareWrapper {($this->assertFresh)();return $this->request->getShareById($shareId,$federatedUser);}
    public function getChild(IShare $share,FederatedUser $federatedUser):ShareWrapper {
        ($this->assertFresh)();$id=self::positiveId($share->getId());$recipient=$this->currentRecipient??$federatedUser->getUserId();
        if($recipient!==$federatedUser->getUserId()||$federatedUser->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||$federatedUser->getInstance()!==''||$share->getShareType()!==7){throw new \RuntimeException('Native Circle child actor is unqualified');}
        CircleRuntime::assertRecipientMember($this->actorUid,$share->getSharedWith(),$recipient,$federatedUser->getSingleId());
        if($this->childGrant!==null){
            $grant=$this->childGrant;$this->childGrant=null;$this->childGrantConsumed=true;
        if($grant['target_parent_id']!==$id||$grant['circle_id']!==$share->getSharedWith()||$grant['single_id']!==$federatedUser->getSingleId()||$grant['recipient_uid']!==$recipient||$grant['target_file_id']!==(int)$share->getNodeId()||$grant['parent_type']!==7){throw new \RuntimeException('Native Circle child-create grant changed');}
            try{$this->request->getChild($federatedUser,$id);throw new \RuntimeException('Native Circle child already exists');}
            catch(ShareWrapperNotFoundException){}
            $binding=['operation'=>'transfer-recipient','source_parent_id'=>$grant['source_parent_id'],'source_child_id'=>$grant['source_child_id'],
                'source_file_id'=>$grant['source_file_id'],'target_parent_id'=>$id,'parent_type'=>7,'parent_share_with'=>$grant['circle_id'],'source_share_with'=>$grant['single_id'],
                'recipient_uid'=>$grant['recipient_uid'],'target_file_id'=>$grant['target_file_id']];
            $child=$this->writeRequest->withinChild($id,$binding,$grant['source_child'],$grant['proof'],fn():ShareWrapper=>parent::getChild($share,$federatedUser));
        }else{$child=$this->request->getChild($federatedUser,$id);}
        if($child->getParent()!==$id||$child->getShareType()!==7||$child->getSharedWith()!==$federatedUser->getSingleId()||$child->getFileSource()!==$share->getNodeId()||$child->getPermissions()<0||$child->getPermissions()>$share->getPermissions()) {throw new \RuntimeException('Native Circle child row binding changed');}
        $this->childSnapshots[$child->getId()]=['parent'=>$id,'recipient'=>$federatedUser->getSingleId(),'file_source'=>$child->getFileSource(),'share_owner'=>$child->getShareOwner(),'shared_by'=>$child->getSharedBy(),'share_type'=>$child->getShareType(),'permissions'=>$child->getPermissions(),'file_target'=>$child->getFileTarget(),'status'=>$child->getStatus(),'note'=>$child->getShareNote(),'expiration'=>$child->getExpirationDate()?->format('Y-m-d H:i:s')];
        return$child;
    }
    private ?array $childGrant=null;
    /** Single-use path reached only through pinned native getChild/createChild. */
    public function createTransferChild(IShare $parent,array $sourceChild,string $recipientUid,\Closure $proof):ShareWrapper {
        if($this->childGrant!==null){throw new \RuntimeException('Nested native Circle child-create grant is unavailable');}$this->childGrantConsumed=false;
        $parentId=self::positiveId($parent->getId());$sourceParent=self::positiveId($sourceChild['parent']??null);$sourceId=self::positiveId($sourceChild['id']??null);
        $singleId=$sourceChild['share_with']??null;
        $sourceFileId=self::positiveId($sourceChild['file_source']??null);
        if(($sourceChild['share_type']??null)!==7||!is_string($singleId)||$recipientUid===''||strlen($recipientUid)>255
            ||$parent->getShareType()!==7||$parent->getSharedWith()===''||$parent->getPermissions()<0){throw new \RuntimeException('Native Circle transfer child tuple is malformed');}
        ($this->transferCapability??throw new \RuntimeException('Native Circle transfer child capability is unavailable'))->assertTransferParent($this->actorUid,$parent);
        $freshRecipient=$this->federatedUsers->getLocalFederatedUser($recipientUid,true,false);
        if($freshRecipient->getSingleId()!==$singleId||$freshRecipient->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||$freshRecipient->getInstance()!==''){throw new \RuntimeException('Native Circle transfer recipient identity changed');}
        CircleRuntime::assertRecipientMember($this->actorUid,$parent->getSharedWith(),$recipientUid,$singleId);
        $this->childGrant=['target_parent_id'=>$parentId,'source_parent_id'=>$sourceParent,'source_child_id'=>$sourceId,'source_file_id'=>$sourceFileId,'parent_type'=>7,
            'circle_id'=>$parent->getSharedWith(),'single_id'=>$singleId,'recipient_uid'=>$recipientUid,'target_file_id'=>(int)$parent->getNodeId(),'source_child'=>$sourceChild,'proof'=>$proof];
        try{return$this->getChild($parent,$freshRecipient);}finally{$this->childGrant=null;}
    }
    public function clearCache(string $singleId):void {($this->assertFresh)();$this->cacheOwner->clearCache($singleId);}
    public function getSharesInFolder(?FederatedUser $federatedUser,Folder $node,bool $reshares,bool $shallow=true):array {($this->assertFresh)();return $this->request->getSharesInFolder($federatedUser,$node,$reshares,$shallow);}
    public function getSharesBy(FederatedUser $federatedUser,int $nodeId,bool $reshares,int $limit,int $offset,bool $getData=false,bool $completeDetails=false):array {
        ($this->assertFresh)();
        if($federatedUser->getUserId()!==$this->actorUid||$federatedUser->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||$federatedUser->getInstance()!==''||$nodeId<0||$reshares||$limit<1||$limit>1000||$offset<0||$offset>100000||$completeDetails){throw new \RuntimeException('Native Circle share page is outside the detached owner scope');}
        return$this->request->getSharesBy($federatedUser,$nodeId,false,$limit,$offset,$getData,false);
    }
    public function save(IShare $share):void {
        ($this->assertFresh)();$this->cacheOwner->clearCache($share->getSharedWith());
        $this->writeRequest->within('insert',0,$share,true,function()use($share):void{$this->writeRequest->save($share);});
    }
    public function update(ShareWrapper $shareWrapper):void {
        $share=$this->currentShare??throw new \RuntimeException('Native Circle update intent is unavailable');
        if($shareWrapper->getParent()>0){$this->assertChildUpdate($shareWrapper,$share);}
        ($this->assertFresh)();$this->cacheOwner->clearCache($shareWrapper->getSharedWith());
        $this->writeRequest->within('update',self::positiveId($shareWrapper->getId()),$share,false,function()use($shareWrapper):void{$this->writeRequest->update($shareWrapper);});
    }
    public function updateChildPermissions(ShareWrapper $shareWrapper):void {
        $share=$this->currentShare??throw new \RuntimeException('Native Circle child update intent is unavailable');
        $this->assertChildUpdate($shareWrapper,$share);
        $childId=self::positiveId($shareWrapper->getId());$parentId=self::positiveId($share->getId());
        ($this->assertFresh)();$this->cacheOwner->clearCache($shareWrapper->getSharedWith());
        $this->writeRequest->within('update-child',$parentId,$share,false,function()use($shareWrapper):void{$this->writeRequest->updateChildPermissions($shareWrapper);},['child_id'=>$childId]);
    }
    private function assertChildUpdate(ShareWrapper $row,IShare $parent):void {
        $snapshot=$this->childSnapshots[$row->getId()]??throw new \RuntimeException('Native Circle child was not read in this operation');
        if($snapshot['parent']!==self::positiveId($parent->getId())||$row->getParent()!==$snapshot['parent']||$row->getFileSource()!==$snapshot['file_source']||$row->getShareType()!==7||$row->getSharedWith()!==$snapshot['recipient']||$row->getShareOwner()!==$snapshot['share_owner']||$row->getSharedBy()!==$snapshot['shared_by']||$row->getStatus()!==$snapshot['status']||$row->getShareNote()!==$snapshot['note']||$row->getExpirationDate()?->format('Y-m-d H:i:s')!==$snapshot['expiration']){throw new \RuntimeException('Native Circle child update identity changed');}
        $permissionChanged=$row->getPermissions()!==$snapshot['permissions'];$targetChanged=$row->getFileTarget()!==$snapshot['file_target'];
        if($permissionChanged&&$targetChanged||($permissionChanged&&!in_array($row->getPermissions(),[0,$parent->getPermissions()],true))||($targetChanged&&$row->getFileTarget()!==$parent->getTarget())||(!$permissionChanged&&!$targetChanged)){throw new \RuntimeException('Native Circle child mutation is outside move/reject/restore semantics');}
        unset($this->childSnapshots[$row->getId()]);
    }
    private static function positiveId(mixed $id):int {if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||($number=filter_var($id,FILTER_VALIDATE_INT))===false||$number<1)throw new \RuntimeException('Native Circle share ID is malformed');return$number;}
    public function delete(ShareWrapper $shareWrapper):void {
        $share=$this->currentShare??throw new \RuntimeException('Native Circle delete intent is unavailable');
        ($this->assertFresh)();$this->cacheOwner->clearCache($shareWrapper->getSharedWith());
        $this->writeRequest->within('delete',self::positiveId($shareWrapper->getId()),$share,false,function()use($shareWrapper):void{$this->writeRequest->delete(self::positiveId($shareWrapper->getId()));});
    }
}
