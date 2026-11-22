<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\Files\{Folder,Node};
use OCP\Share\{IShare,IShareProvider,IPartialShareProvider,IShareProviderGetUsers,IShareProviderSupportsAllSharesInFolder};

/** Narrow provider-7 adapter; every call builds a cold native graph inside one Circle lease. */
final class CircleBoundaryProvider implements IPartialShareProvider,IShareProviderGetUsers,IShareProviderSupportsAllSharesInFolder {
    private array $created=[];
    public function __construct(private readonly string $uid,private readonly \Closure $run,private readonly \Closure $beforeWrite,private readonly \Closure $assertFresh,private readonly ?string $intentKey,private readonly ?\Closure $oneShotProof=null,private readonly ?\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary $transferCapability=null) {}
    private function share(IShare $share,bool $requireId=true):void {if($share->getShareType()!==7||($requireId&&$share->getId()===null)||$share->getSharedWith()===''){throw new \RuntimeException('Native Circle share identity is malformed');}}
    private function invoke(?IShare $share,?string $recipient,bool $event,\Closure $work):mixed {($this->assertFresh)();return($this->run)($share,$recipient,$event,static fn(\OCA\Circles\ShareByCircleProvider $native,\OCA\Forms\Mcp\Service\CircleNativeShareWrapperService $wrapper):mixed=>$work($native,$wrapper));}
    public function identifier(){return 'ocCircleShare';}
    public function create(IShare $share){$this->share($share,false);$result=$this->invoke($share,null,true,static fn($native)=>$native->create($share));$this->created[$result->getFullId()]=true;return$result;}
    public function update(IShare $share){$this->share($share);return$this->invoke($share,null,false,static fn($native)=>$native->update($share));}
    public function delete(IShare $share){$this->share($share);$this->invoke($share,null,true,static function($native)use($share):void{$native->delete($share);});}
    public function createdHere(string $id):bool{return isset($this->created[$id]);}
    public function createTransferChild(IShare $parent,array $sourceChild,string $recipientUid,\Closure $proof):int {
        $this->share($parent);$parentId=self::positiveId($parent->getId());
        if($recipientUid===''||strlen($recipientUid)>255||($parent->getParent()??0)!==0){throw new \RuntimeException('Native Circle transfer parent is unqualified');}
        ($this->transferCapability??throw new \RuntimeException('Native Circle transfer capability is unavailable'))->assertTransferParent($this->uid,$parent);
        return$this->invoke($parent,$recipientUid,false,static function($native,CircleNativeShareWrapperService $wrapper)use($parent,$parentId,$sourceChild,$recipientUid,$proof):int {
            $fresh=$native->getShareById($parentId,$recipientUid);
            if($fresh->getShareType()!==7||$fresh->getShareOwner()!==$parent->getShareOwner()||$fresh->getSharedWith()!==$parent->getSharedWith()||$fresh->getNodeId()!==$parent->getNodeId()||($fresh->getParent()??0)!==0){throw new \RuntimeException('Native Circle transfer parent changed');}
            $wrapper->current($fresh,$recipientUid);
            return self::positiveId($wrapper->createTransferChild($fresh,$sourceChild,$recipientUid,$proof)->getId());
        });
    }
    private function recipient(string $uid):void {if($uid===''||strlen($uid)>255||str_contains($uid,"\0")){throw new \RuntimeException('Native Circle recipient identity is malformed');}CircleExistingIdentity::read(\OCP\Server::get(\OCP\IDBConnection::class),$uid);}
    public function deleteFromSelf(IShare $share,$recipient){$this->share($share);$uid=(string)$recipient;$this->recipient($uid);return$this->invoke($share,$uid,false,static fn($native)=>$native->deleteFromSelf($share,$recipient));}
    public function restore(IShare $share,string $recipient):IShare{$this->share($share);$this->recipient($recipient);return$this->invoke($share,$recipient,false,static fn($native)=>$native->restore($share,$recipient));}
    public function move(IShare $share,$recipient){$this->share($share);$uid=(string)$recipient;$this->recipient($uid);return$this->invoke($share,$uid,false,static fn($native)=>$native->move($share,$recipient));}
    public function getShareById($id,$recipientId=null):IShare {
        ($this->assertFresh)();if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||filter_var($id,FILTER_VALIDATE_INT)===false){throw new \RuntimeException('Native Circle share ID is malformed');}
        if($recipientId!==null){$this->recipient((string)$recipientId);}
        $read=fn():IShare=>$this->invoke(null,$recipientId===null?null:(string)$recipientId,false,static fn($native)=>$native->getShareById($id,$recipientId));
        $share=$this->transferCapability===null?$read():$this->transferCapability->readParent($read);
        $this->share($share);if($share->getId()!==(string)$id&&$share->getId()!==$id){throw new \RuntimeException('Native Circle share row changed');}
        if($this->transferCapability===null){if($share->getShareOwner()!==$this->uid){throw new \RuntimeException('Native Circle share owner changed');}}
        else{$this->transferCapability->assertTransferParent($this->uid,$share);}
        return$share;
    }
    private static function positiveId(mixed $id):int {if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||($number=filter_var($id,FILTER_VALIDATE_INT))===false||$number<1)throw new \RuntimeException('Native Circle share ID is malformed');return$number;}
    public function getSharesBy($userId,$shareType,$node,$reshares,$limit,$offset):array {
        ($this->assertFresh)();if($userId!==$this->uid||$shareType!==7||$reshares!==false||!$node instanceof Folder||!is_int($limit)||$limit<1||$limit>1000||!is_int($offset)||$offset<0||$offset>100000){throw new \RuntimeException('Native Circle share query is outside its bounded owner scope');}
        // This method is intentionally closed until an IShare context exists for the scoped provider graph.
        throw new \RuntimeException('Native Circle bounded enumeration requires managed file context');
    }
    public function getSharesInFolder($userId,Folder $node,$reshares,$shallow=true){throw new \RuntimeException('Native Circle folder share enumeration is unavailable');}
    public function getSharesByPath(Node $path){throw new \RuntimeException('Native Circle path enumeration is unavailable');}
    public function getSharedWith($userId,$shareType,$node,$limit,$offset){throw new \RuntimeException('Native Circle recipient enumeration is unavailable');}
    public function getShareByToken(string $token){throw new \RuntimeException('Native Circle token lookup is unavailable');}
    public function userDeleted($uid,$shareType){throw new \RuntimeException('Native Circle account cleanup is unavailable');}
    public function groupDeleted($gid){throw new \RuntimeException('Native Circle group cleanup is unavailable');}
    public function userDeletedFromGroup($uid,$gid){throw new \RuntimeException('Native Circle group cleanup is unavailable');}
    public function getAccessList($nodes,$currentAccess){throw new \RuntimeException('Native Circle access-list enumeration is unavailable');}
    public function getAllShares():iterable{throw new \RuntimeException('Native Circle full enumeration is unavailable');}
    public function getChildren(IShare $parent){throw new \RuntimeException('Native Circle child enumeration requires detached recipient authority');}
    public function getSharedWithByPath(string $userId,int $shareType,string $path,bool $forChildren,int $limit,int $offset):iterable{throw new \RuntimeException('Native Circle recipient path enumeration is unavailable');}
    public function getUsersForShare(IShare $share):iterable{throw new \RuntimeException('Native Circle recipient enumeration is unavailable');}
    public function getAllSharesInFolder(Folder $node):array{throw new \RuntimeException('Native Circle folder-wide enumeration is unavailable');}
}
