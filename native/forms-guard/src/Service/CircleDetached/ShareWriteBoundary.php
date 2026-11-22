<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Runs the provider's request-local authority and managed-share boundary immediately before its statement sink. */
final class ShareWriteBoundary {
    private bool $consumed=false;
    private function __construct(
        private readonly \OCA\Forms\Mcp\Service\BulkStore $store,
        private readonly array $job,
        private readonly string $sourceKind,
        private readonly int $sourceRowId,
        private readonly string $sourceRowDigest,
        private readonly int $parentSourceId,
        private readonly int $targetParentId,
        private readonly string $actorUid,
        private readonly string $targetOwner,
        private readonly int $targetFileId,
        private readonly string $circleId,
        private readonly ?array $child,
        private readonly string $mode,
        private readonly int $targetChildId,
    ) {}
    /** Called only by the owned transfer staging methods, after BulkOperations persisted an uncertain step claim. */
    public static function bulkTransferParent(\OCA\Forms\Mcp\Service\BulkStore $store,array $job,array $row,array $mapping):self {
        self::caller(__FUNCTION__,'circleParentWriteCapability');$record=$row['data'];$tuple=$record['approved_tuple']??[];
        return self::claim($store,$job,$row,'managed_shares',(int)$row['id'],(int)$row['id'],0,$tuple['recipient']??null,$mapping,null);
    }
    public static function bulkTransferChild(\OCA\Forms\Mcp\Service\BulkStore $store,array $job,array $row,array $parent,array $mapping):self {
        self::caller(__FUNCTION__,'stageCircleRecipient');$child=$row['data'];$tuple=$parent['approved_tuple']??[];
        return self::claim($store,$job,$row,'share_recipients',(int)$row['id'],(int)$child['parent'],\OCA\Forms\Mcp\Service\CoreShareIdentity::id((string)$mapping['files_share_id'],7),$tuple['recipient']??null,$mapping,$child);
    }
    private static function caller(string $factory,string|array $method):void {
        $call=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,3)[2]??[];
        if(($call['class']??null)!==\OCA\Forms\Mcp\Service\BulkTransferShares::class||!in_array($call['function']??null,(array)$method,true)){throw new \RuntimeException('Bulk transfer share capability issuer is unavailable');}
    }
    private static function claim(\OCA\Forms\Mcp\Service\BulkStore $store,array $job,array $row,string $kind,int $rowId,int $parentSourceId,int $targetParentId,mixed $circleId,array $mapping,?array $child):self {
        if(($job['action']??null)!=='forms_transfer'||($job['state']??null)!=='uncertain'||!in_array($job['phase']??null,['stage_shares','stage_recipients'],true)
            ||($job['claim']['phase']??null)!==$job['phase']||($job['claim']['file_id']??null)!==$rowId||!is_string($job['claim']['step_key']??null)
            ||($job['claim']['step_key']??'')===''||($row['id']??null)!==$rowId||($row['digest']??null)===''||!is_string($circleId)||$circleId===''
            ||($mapping['approved_tuple']['type']??null)!==7||($mapping['approved_tuple']['recipient']??null)!==$circleId
            ||($mapping['approved_tuple']['owner_id']??null)!==($job['input']['owner_id']??null)||!isset($mapping['folder_id'])){throw new \RuntimeException('Bulk transfer Circle claim is malformed');}
        $live=$store->load($job['id']);
        if(($live['state']??null)!=='uncertain'||($live['version']??null)!==($job['version']??null)||($live['claim']??null)!==($job['claim']??null)
            ||($live['uid']??null)!==($job['uid']??null)||($live['phase']??null)!==$job['phase']){throw new \RuntimeException('Bulk transfer Circle claim changed');}
        $source=$store->one($job['id'],$kind,$rowId);if(\OCA\Forms\Mcp\Service\BulkStore::digest($source)!==$row['digest']){throw new \RuntimeException('Bulk transfer Circle source row changed');}
        if($child!==null){$parent=$store->one($job['id'],'staged_shares',$parentSourceId);if($parent!==$mapping){throw new \RuntimeException('Bulk transfer Circle parent mapping changed');}}
        return new self($store,$job,$kind,$rowId,$row['digest'],$parentSourceId,$targetParentId,$job['uid'],$job['input']['owner_id'],(int)$mapping['folder_id'],$circleId,$child,$child===null?'insert-parent':'insert-child',0);
    }
    /** Transfer activation is synchronous inside the existing locked SQL transaction. */
    public static function bulkTransferActivation(\OCA\Forms\Mcp\Service\BulkStore $store,array $job,array $row,array $mapping,?array $child=null):self {
        self::caller(__FUNCTION__,['activate','activateCircle']);
        if(!\OCA\Forms\Mcp\Service\OriginContext::active()||!\OCP\Server::get(\OCP\IDBConnection::class)->inTransaction()
            ||($job['action']??null)!=='forms_transfer'||($job['phase']??null)!=='apply'||($job['state']??null)!=='pending'
            ||isset($job['claim'])||!is_int($job['version']??null)||($row['id']??null)<1||!is_string($row['digest']??null))throw new \RuntimeException('Circle transfer activation is outside the locked final transaction');
        $authority=\OCA\Forms\Mcp\Service\OriginContext::transferSharingAuthority();($authority['fresh'])();($authority['frame'])();
        $live=$store->load($job['id']);
        if(($live['action']??null)!=='forms_transfer'||($live['phase']??null)!=='apply'||($live['state']??null)!=='pending'||($live['version']??null)!==$job['version']
            ||isset($live['claim'])||($live['uid']??null)!==($job['uid']??null)||($live['form']['id']??null)!==($job['form']['id']??null))throw new \RuntimeException('Circle transfer activation frame changed');
        $source=$store->one($job['id'],'managed_shares',(int)$row['id']);
        if(!hash_equals($row['digest'],\OCA\Forms\Mcp\Service\BulkStore::digest($source))||$source!==($row['data']??null)||($source['approved_tuple']['type']??null)!==7)throw new \RuntimeException('Circle transfer activation source changed');
        $staged=$store->one($job['id'],'staged_shares',(int)$row['id']);
        if($staged!==$mapping||($mapping['approved_tuple']['type']??null)!==7||($mapping['approved_tuple']['owner_id']??null)!==($job['input']['owner_id']??null))throw new \RuntimeException('Circle transfer activation mapping changed');
        $sourceParent=\OCA\Forms\Mcp\Service\CoreShareIdentity::id((string)$source['files_share_id'],7);
        $targetParent=\OCA\Forms\Mcp\Service\CoreShareIdentity::id((string)$mapping['files_share_id'],7);
        $targetChild=0;$kind='managed_shares';$sourceId=(int)$row['id'];$digest=$row['digest'];
        if($child!==null){
            $kind='share_recipients';$sourceId=(int)($child['id']??0);$digest=(string)($child['digest']??'');$data=$child['data']??null;
            if($sourceId<1||!is_string($digest)||!is_array($data)||(int)($data['parent']??0)!==$sourceParent||($data['share_type']??null)!==7)throw new \RuntimeException('Circle transfer child activation source changed');
            if($store->one($job['id'],'share_recipients',$sourceId)!==$data||!hash_equals($digest,\OCA\Forms\Mcp\Service\BulkStore::digest($data)))throw new \RuntimeException('Circle transfer child activation digest changed');
            $stagedChild=$store->one($job['id'],'staged_recipients',$sourceId);
            $targetChild=$store->mapped($job['id'],'staged_recipients',$sourceId);
            // recipientState deliberately excludes id; BulkStore::mapped is the sealed identity mapping.
            if($targetChild<1||(int)($stagedChild['permissions']??-1)!==0||(int)($stagedChild['parent']??0)!==$targetParent||($stagedChild['share_type']??null)!==7
                ||($stagedChild['share_with']??null)!==($data['share_with']??null)||(int)($data['permissions']??-1)!==1)throw new \RuntimeException('Circle transfer child activation mapping changed');
        }
        $db=\OCP\Server::get(\OCP\IDBConnection::class);
        if(!$db->inTransaction())throw new \RuntimeException('Circle transfer activation transaction ended');
        return new self($store,$job,$kind,$sourceId,$digest,$sourceParent,$targetParent,$job['uid'],$job['input']['owner_id'],(int)$mapping['folder_id'],$source['approved_tuple']['recipient'],$child===null?null:$child['data'],$child===null?'activate-parent':'activate-child',$targetChild);
    }
    /** Only the named provider lookup is pure; its write/restore methods cannot enter this scope. */
    public function readParent(\Closure $read):mixed {
        $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??[];
        if(($caller['class']??null)!==\OCA\Forms\Mcp\Service\CircleBoundaryProvider::class||($caller['function']??null)!=='getShareById')throw new \RuntimeException('Circle parent read issuer is unavailable');
        return $this->activationRead($read);
    }
    /** Existing child hydration only; the service forbids its creation branch in this scope. */
    public function readChild(\Closure $read):mixed {
        $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??[];
        if(($caller['class']??null)!==\OCA\Forms\Mcp\Service\CircleNativeShareWrapperService::class||($caller['function']??null)!=='getChild')throw new \RuntimeException('Circle child read issuer is unavailable');
        return $this->activationRead($read);
    }
    private function activationRead(\Closure $read):mixed {
        if(!in_array($this->mode,['activate-parent','activate-child'],true))return $read();
        if(($this->job['phase']??null)!=='apply'||!\OCP\Server::get(\OCP\IDBConnection::class)->inTransaction())throw new \RuntimeException('Circle activation read transaction ended');
        \OCA\Forms\Mcp\Service\OriginContext::assertJobInput($this->job);
        if(\OCA\Forms\Mcp\Service\OriginReadAuthority::check())return $read();
        $frame=\OCA\Forms\Mcp\Service\OriginContext::transferSharingAuthority()['frame'];
        return \OCA\Forms\Mcp\Service\OriginContext::verifiedRead($frame,$read);
    }
    public function consume(string $actorUid,string $action,\OCP\Share\IShare $share,bool $create,?array $binding):void {
        $activation=in_array($this->mode,['activate-parent','activate-child'],true);
        $expectedAction=match($this->mode){'insert-parent'=>'insert','insert-child'=>'insert-child','activate-parent'=>'update','activate-child'=>'update-child',default=>throw new \RuntimeException('Bulk transfer Circle capability mode is unknown')};
        $expectedRecipient=$this->mode==='insert-child'?$this->child['share_with']:$this->circleId;
        if($this->consumed||$action!==$expectedAction||$create===$activation||$actorUid!==$this->actorUid||$share->getShareType()!==7||$share->getSharedWith()!==$expectedRecipient
            ||$share->getShareOwner()!==$this->targetOwner||$share->getSharedBy()!==$this->actorUid||(int)$share->getNodeId()!==$this->targetFileId
            ||$share->getPermissions()!==($activation?1:0)){throw new \RuntimeException('Bulk transfer Circle write differs from its claim');}
        $live=$this->store->load($this->job['id']);
        if(($live['state']??null)!==($activation?'pending':'uncertain')||($live['version']??null)!==($this->job['version']??null)
            ||($live['claim']??null)!==($this->job['claim']??null)||($live['phase']??null)!==($this->job['phase']??null)
            ||($activation&&(($live['phase']??null)!=='apply'||!\OCP\Server::get(\OCP\IDBConnection::class)->inTransaction()))
            ||(!$activation&&($live['claim']['step_key']??null)!==($this->job['claim']['step_key']??null))){throw new \RuntimeException('Bulk transfer Circle one-shot claim changed');}
        $source=$this->store->one($this->job['id'],$this->sourceKind,$this->sourceRowId);
        if(!hash_equals($this->sourceRowDigest,\OCA\Forms\Mcp\Service\BulkStore::digest($source))){throw new \RuntimeException('Bulk transfer Circle source row changed');}
        if($this->mode==='insert-child'){
            if($binding===null||array_keys($binding)!==['operation','source_parent_id','source_child_id','source_file_id','target_parent_id','parent_type','parent_share_with','source_share_with','recipient_uid','target_file_id']
                ||$binding['operation']!=='transfer-recipient'||$binding['source_parent_id']!==$this->parentSourceId||$binding['source_child_id']!==(int)$this->child['id']
                ||$binding['source_file_id']!==(int)$this->child['file_source']||$binding['target_parent_id']!==$this->targetParentId||$binding['parent_type']!==7
                ||$binding['parent_share_with']!==$this->circleId||$binding['source_share_with']!==$this->child['share_with']||$binding['target_file_id']!==$this->targetFileId
                ||!is_string($binding['recipient_uid'])||$binding['recipient_uid']===''){throw new \RuntimeException('Bulk transfer Circle child tuple changed');}
            $mapping=$this->store->one($this->job['id'],'staged_shares',$this->parentSourceId);
            if(\OCA\Forms\Mcp\Service\CoreShareIdentity::id((string)$mapping['files_share_id'],7)!==$this->targetParentId){throw new \RuntimeException('Bulk transfer Circle parent ID changed');}
            $parentSource=$this->store->one($this->job['id'],'managed_shares',$this->parentSourceId);$expectedExpiry=$this->child['expiration']??null;$parentExpiry=$parentSource['share_state']['expiration']??null;
            if($parentExpiry!==null&&($expectedExpiry===null||(new \DateTime((string)$expectedExpiry))>(new \DateTime((string)$parentExpiry))))$expectedExpiry=$parentExpiry;
            $actualAttributes=[];foreach($share->getAttributes()?->toArray()??[]as$attribute){$actualAttributes[]=[$attribute['scope'],$attribute['key'],$attribute['value']];}
            $expectedAttributes=($this->child['attributes']??null)===null?[]:json_decode((string)$this->child['attributes'],true,64,JSON_THROW_ON_ERROR);
            if($share->getStatus()!==(int)$this->child['accepted']||$actualAttributes!==$expectedAttributes
                ||$share->getExpirationDate()?->format('Y-m-d H:i:s')!==$expectedExpiry){throw new \RuntimeException('Bulk transfer Circle child restrictions changed');}
        }elseif($this->mode==='activate-child'){
            if(!is_array($binding)||array_keys($binding)!==['child_id']||$binding['child_id']!==$this->targetChildId||$this->targetChildId<1)throw new \RuntimeException('Bulk transfer Circle child activation binding changed');
            $parent=$this->store->one($this->job['id'],'staged_shares',$this->parentSourceId);
            $stagedChild=$this->store->one($this->job['id'],'staged_recipients',$this->sourceRowId);
            if(\OCA\Forms\Mcp\Service\CoreShareIdentity::id((string)$parent['files_share_id'],7)!==$this->targetParentId
                ||$this->store->mapped($this->job['id'],'staged_recipients',$this->sourceRowId)!==$this->targetChildId
                ||(int)($stagedChild['permissions']??-1)!==0||($stagedChild['share_type']??null)!==7||(int)($stagedChild['parent']??0)!==$this->targetParentId
                ||($stagedChild['share_with']??null)!==($this->child['share_with']??null))throw new \RuntimeException('Bulk transfer Circle child activation parent changed');
        }elseif($activation){
            if($binding!==null||self::id($share->getId())!==$this->targetParentId)throw new \RuntimeException('Bulk transfer Circle parent activation binding changed');
        }elseif($binding!==null||$this->targetParentId!==0){throw new \RuntimeException('Bulk transfer Circle parent write binding changed');}
        $this->consumed=true;
    }
    /** Closed SQL tuple for the one sealed child; parent authority remains the Circle share. */
    public function childUpdateDecision(string $actorUid,\OCP\Share\IShare $parent,?array $binding):array {
        if($this->mode!=='activate-child'||$this->consumed||$this->targetChildId<1||$binding!==['child_id'=>$this->targetChildId]||!\OCP\Server::get(\OCP\IDBConnection::class)->inTransaction())throw new \RuntimeException('Circle child SQL decision is unavailable');
        $this->assertTransferParent($actorUid,$parent);
        $recipient=$this->child['share_with']??null;$accepted=$this->child['accepted']??null;
        if(!is_string($recipient)||$recipient===''||strlen($recipient)>255||!in_array($accepted,[0,1,2,'0','1','2'],true))throw new \RuntimeException('Circle child SQL decision changed');
        return ['recipient'=>$recipient,'accepted'=>(int)$accepted];
    }
    /** Native parent propagation would touch no rows while every staged child is quarantined. */
    public function assertQuarantinedParentPropagation(string $actorUid,\OCP\Share\IShare $parent,\OCA\Circles\Model\ShareWrapper $row):void {
        $db=\OCP\Server::get(\OCP\IDBConnection::class);
        if($this->mode!=='activate-parent'||!$this->consumed||!$db->inTransaction())throw new \RuntimeException('Circle parent propagation is outside activation');
        $this->assertTransferParent($actorUid,$parent);
        if($row->getParent()!==0||self::id($row->getId())!==$this->targetParentId||$row->getShareType()!==7||$row->getSharedWith()!==$this->circleId||$row->getShareOwner()!==$this->targetOwner||$row->getSharedBy()!==$this->actorUid||$row->getFileSource()!==$this->targetFileId||$row->getPermissions()!==1||$parent->getPermissions()!==1)throw new \RuntimeException('Circle parent propagation tuple changed');
        $count=$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ? AND permissions > 0',[$this->targetParentId])->fetchOne();
        if($count!==0&&$count!=='0')throw new \RuntimeException('Circle parent propagation has active children');
    }
    public function assertTransferParent(string $actorUid,\OCP\Share\IShare $share):void {
        if($actorUid!==$this->actorUid||$share->getShareType()!==7||$share->getShareOwner()!==$this->targetOwner||$share->getSharedWith()!==$this->circleId
            ||(int)$share->getNodeId()!==$this->targetFileId||$this->targetParentId>0&&self::id($share->getId())!==$this->targetParentId){throw new \RuntimeException('Bulk transfer Circle parent is outside its capability');}
    }
    private static function id(mixed $id):int {if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||($value=filter_var($id,FILTER_VALIDATE_INT))===false||$value<1)throw new \RuntimeException('Bulk transfer Circle parent ID is malformed');return$value;}
    public static function execute(\Closure $assertFresh,\Closure $beforeWrite,mixed $share,bool $create,\Closure $statement):mixed {
        $assertFresh();
        $beforeWrite($share,$create);
        $assertFresh();
        return $statement();
    }
}
