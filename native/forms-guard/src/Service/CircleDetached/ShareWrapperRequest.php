<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Native Circle share query predicates over this request's leased, bounded query builder. */
final class ShareWrapperRequest extends \OCA\Circles\Db\ShareWrapperRequest {
    public function __construct(private readonly Lease $lease) {}
    public function getQueryBuilder(): CoreQueryBuilder {
        $this->lease->assertActive();
        return $this->lease->query();
    }
    /** Only the service's existing-parent/child hydration callbacks may enter this metadata scope. */
    public function readModel(\Closure $read):mixed {
        $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]??[];
        if(($caller['class']??null)!==\OCA\Forms\Mcp\Service\CircleNativeShareWrapperService::class||!in_array($caller['function']??null,['getChildRead','getParentRead'],true))throw new \RuntimeException('Circle model read issuer is unavailable');
        return $this->lease->metadataRead($read);
    }
    /** Exact native parent-share predicate with a sentinel row fetched before model hydration. */
    public function managedParents(string $uid,int $nodeId,int $limit=1000):array {
        $this->lease->checkpoint();
        if($uid!==$this->lease->actorUid()||$nodeId<1||$limit<1||$limit>1000){throw new \RuntimeException('Managed Circle parent page is outside its owner scope');}
        $rows=parent::getSharesBy($this->lease->identity($uid),$nodeId,false,$limit+1,0,false,false);
        if(count($rows)>$limit){throw new \RuntimeException('Managed Circle parent page overflowed');}
        $out=[];$ids=[];$bytes=0;
        foreach($rows as$row){
            $this->lease->checkpoint();
            if(!$row instanceof \OCA\Circles\Model\ShareWrapper||$row->getParent()!==0||$row->getShareType()!==7||$row->getFileSource()!==$nodeId||$row->getShareOwner()!==$uid||$row->getSharedWith()==='') {throw new \RuntimeException('Managed Circle parent row changed');}
            $id=(string)$row->getId();if(!preg_match('/^[1-9][0-9]*$/D',$id)||isset($ids[$id])){throw new \RuntimeException('Managed Circle parent IDs are malformed');}$ids[$id]=true;
            $out[]=['id'=>$id,'file_source'=>$row->getFileSource(),'share_type'=>$row->getShareType(),'circle_id'=>$row->getSharedWith(),'owner_uid'=>$row->getShareOwner(),'permissions'=>$row->getPermissions(),'file_target'=>$row->getFileTarget()];
            $bytes+=strlen($id)+strlen($row->getSharedWith())+strlen($row->getShareOwner())+strlen((string)$row->getFileTarget())+80;
            if($bytes>1048576){throw new \RuntimeException('Managed Circle parent page byte budget exceeded');}
        }
        ($encoded=json_encode($out,JSON_THROW_ON_ERROR));if(strlen($encoded)>1048576){throw new \RuntimeException('Managed Circle parent page byte budget exceeded');}
        return$out;
    }
}
