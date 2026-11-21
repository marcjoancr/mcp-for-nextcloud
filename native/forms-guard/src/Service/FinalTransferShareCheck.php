<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Transaction-local final read collector, consumed only by the joint file/sharing resolver. */
final class FinalTransferShareCheck {
    private FinalShareCommitment $commitment;
    private ?FinalReadWindow $window=null;
    private ?FinalReadConnection $readDb=null;
    private bool $snapshotVerified=false;
    private int $count=0;
    private int $last=0;
    private ?array $expiry=null;
    private ?array $finalExpiryPolicy=null;
    private array $finalExpirations=[];
    private array $expectedFolders=[];
    private bool $sealed=false;
    private bool $closed=false;
    private bool $collectStarted=false;
    private bool $collected=false;
    private bool $renewed=false;
    private array $policies=[];
    private array $destinations=[];
    private array $sourceFolders=[];
    public function __construct(private readonly \OCP\IDBConnection $db,private readonly BulkStore $store,
        private readonly array $job,private readonly \Closure $fresh,private readonly \Closure $frame) {
        ($this->fresh)();$context=OriginContext::current();OriginContext::assertJobInput($job);
        if (($context['verified']['uid']??null)!==$job['uid'] || ($context['verified']['tool']??null)!=='forms_transfer') {throw new \RuntimeException('Final sharing job frame differs');}
        $this->commitment=new FinalShareCommitment();$budget=new FinalShareBudget($frame);
        foreach ($this->read('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? ORDER BY folder_id LIMIT 1001',[$job['form']['id']],1000,$budget) as $row) {$this->expectedFolders[(int)$row['folder_id']]=true;}
    }
    /** Only already-verified native activation results are appended; full rows are not retained. */
    public function verified(int $sourceId,array $row,?array $expiry=null):void {
        if ($this->sealed || $this->closed || $sourceId<=$this->last || ++$this->count>1000) {throw new \RuntimeException('Final sharing activation capture is invalid');}
        $this->last=$sourceId;$this->commitment->append('mapping',$sourceId,$row);
        unset($this->expectedFolders[$row['source_folder']]);
        if (!($row['absent']??false)) {$this->expectedFolders[(int)$row['mapping']['folder_id']]=true;}
        if ($expiry!==null) {
            if ($this->expiry!==null && ($this->expiry['policy']!==$expiry['policy'] || $expiry['at']<$this->expiry['at'])) {throw new \RuntimeException('Transfer activation expiration inputs changed');}
            $this->expiry=$expiry;
        }
    }
    public function seal():void {
        if ($this->sealed || $this->count!==(int)$this->job['counts']['managed_shares']) {throw new \RuntimeException('Final sharing activation inventory changed');}
        $this->commitment->seal();$this->sealed=true;
    }
    private function read(string $sql,array $params,int $maximum,FinalShareBudget $budget):array {
        ($this->fresh)();$result=($this->readDb??$this->db)->executeQuery($sql,$params);
        $rows=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),$maximum);
        ($this->fresh)();return $rows;
    }
    private function one(string $sql,array $params,FinalShareBudget $budget):array {
        $rows=$this->read($sql,$params,1,$budget);
        if (count($rows)!==1) {throw new \RuntimeException('Final sharing row disappeared');}return $rows[0];
    }
    private function backupRows(string $kind,FinalShareBudget $budget):array {
        if (!in_array($kind,['share_recipients','staged_recipients'],true)) {throw new \LogicException('Final sharing backup kind is unqualified');}
        $cursor=0;$out=[];
        do {
            ($this->fresh)();$page=$this->store->page($this->job['id'],$kind,$cursor);($this->fresh)();
            foreach($page as $row) {
                if($row['id']<=$cursor || count($out)>=1000)throw new \RuntimeException('Final sharing child inventory order changed');
                $cursor=$row['id'];$budget->record($row);$out[$cursor]=$row;
            }
        } while($page!==[]);
        return $out;
    }
    private function share(int $id,FinalShareBudget $budget):array {return $this->one('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE id = ?',[$id],$budget);}
    /** All methods here may wait and MUST finish before the unique final native ACL resolver. */
    public function collect(FinalShareBudget $budget):array {
        if (!$this->sealed || $this->closed || $this->collectStarted) {throw new \RuntimeException('Final sharing collector escaped or repeated');}
        $this->collectStarted=true;
        try {
        ($this->fresh)();$j=$this->job;
        if (!$this->db->inTransaction() || $this->db::class!==\OC\DB\ConnectionAdapter::class) {throw new \RuntimeException('Final sharing transaction is unavailable');}
        $this->window=new FinalReadWindow($this->db,$budget,$this->frame);$this->readDb=$this->window->reader();
        $form=$this->one('SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?',[$j['form']['id']],$budget);
        FinalShareRows::form($form,$this->store->one($j['id'],'form',(int)$j['form']['id']),$j['input']['owner_id']);
        $target=FinalReadCore::fresh($j['input']['owner_id'],$this->readDb);$target->assertPrincipal(false,false);
        if (!hash_equals($j['target_principal'],FinalReadCore::principal($j['input']['owner_id'],$this->readDb))) {throw new \RuntimeException('Final sharing target incarnation changed');}
        $root=\OCP\Server::get(\OCP\Files\IRootFolder::class);$cursor=0;$seen=0;$maps=[];$folders=[];$effects=[];
        $expiryPolicy=null;
        if ($this->expiry!==null) {
            $manager=clone SharingIdentity::manager();
            (new \ReflectionProperty(\OC\Share20\Manager::class,'config'))->setValue($manager,new SharingConfig(FinalReadCore::fresh($j['uid'],$this->readDb)));
            $expiryPolicy=TransferExpiryInputs::policy($manager,ScopedTransferTimeZone::capture($this->readDb),$this->readDb);$this->finalExpiryPolicy=$expiryPolicy;
        }
        $childRecords=$this->backupRows('share_recipients',$budget);$childSource=[];foreach($childRecords as $id=>$row){$childSource[(int)$row['data']['parent']][$id]=$row;}
        $childTargets=$this->backupRows('staged_recipients',$budget);
        if(array_keys($childRecords)!==array_keys($childTargets) || count($childRecords)!==(int)$j['counts']['share_recipients'])throw new \RuntimeException('Final sharing complete child inventory changed');
        $mappedIds=[];foreach($childTargets as $target){$id=$target['mapped'];if($id<1||isset($mappedIds[$id]))throw new \RuntimeException('Final sharing child mapping is invalid');$mappedIds[$id]=true;}
        $stream=(function()use($j,$root,$budget,$expiryPolicy,$childSource,$childTargets,&$cursor,&$seen,&$maps,&$folders,&$effects):\Generator {
        while ($page=$this->store->page($j['id'],'managed_shares',$cursor)) {
            foreach ($page as $item) {
                ($this->fresh)();$id=$item['id'];
                if ($id<=$cursor || ++$seen>1000) {throw new \RuntimeException('Final sharing source inventory changed');}
                $cursor=$id;$r=$item['data'];$budget->record($item);
                if (!($r['live']??false)) {
                    if ($this->read('SELECT id FROM *PREFIX*share WHERE id = ?',[FinalShareRows::id($r['files_share_id'])],1,$budget)!==[]) {throw new \RuntimeException('Final sharing absent source reappeared');}
                    yield ['mapping',$id,['source_folder'=>(int)$r['folder_id'],'absent'=>true]];
                    continue;
                }
                $source=$this->share(FinalShareRows::id($r['files_share_id']),$budget);FinalShareRows::original($source,$r);
                $native=$this->one('SELECT * FROM *PREFIX*forms_v2_shares WHERE id = ?',[$r['native_share_id']],$budget);
                if ($native!==$this->store->one($j['id'],'shares',(int)$r['native_share_id'])) {throw new \RuntimeException('Final sharing Forms audience changed');}
                $m=$this->store->one($j['id'],'staged_shares',$id);
                if ($r['approved_tuple']['type']===1) {NativeGroupHistory::assertCurrent($r['approved_tuple']['recipient'],$r['group_lifetime'],$this->readDb);}
                $folders[(int)$m['folder_id']]=true;
                if ($m['files_share_id']===null) {
                    if ($r['approved_tuple']['type']!==0 || $r['approved_tuple']['recipient']!==$j['input']['owner_id']) {throw new \RuntimeException('Final sharing target-self mapping changed');}
                    $parent=null;$children=[];
                    if(($childSource[(int)$source['id']]??[])!==[] || $this->read('SELECT id FROM *PREFIX*share WHERE parent = ? LIMIT 1',[(int)$source['id']],1,$budget)!==[])throw new \RuntimeException('Final sharing target-self child set changed');
                } else {
                    $parent=$this->share(FinalShareRows::id($m['files_share_id']),$budget);FinalShareRows::target($parent,$r,$m,$j);
                    if ($this->expiry===null || $expiryPolicy===null) {throw new \RuntimeException('Transfer activation inputs are unavailable');}
                    TransferExpiryInputs::assertCurrent($this->expiry,$expiryPolicy,$parent['expiration'],time());$this->finalExpirations[]=$parent['expiration'];
                    $children=$this->read('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE parent = ? ORDER BY id LIMIT 1001',[(int)$parent['id']],1000,$budget);
                    $sourceChildren=$this->read('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE parent = ? ORDER BY id LIMIT 1001',[(int)$source['id']],1000,$budget);
                    $sealed=[];$targetById=[];foreach($children as $targetChild){$key=(int)$targetChild['id'];if(isset($targetById[$key]))throw new \RuntimeException('Final sharing child identity duplicated');$targetById[$key]=$targetChild;}
                    $verifyChildren=function()use($j,$r,$source,$parent,$childSource,$childTargets,&$sealed,&$targetById):void {
                    $actorGroup=null;$checkedGroupMembers=[];
                    foreach ($childSource[(int)$source['id']]??[] as $childRecord) {
                        $child=$childRecord['data'];$selected=[];
                        foreach (explode(',',FinalShareRows::COLUMNS) as $column) {$selected[$column]=$child[$column]??null;}
                        $sealed[]=$selected;
                        $mappedId=$childTargets[$childRecord['id']]['mapped']??0;
                        $actualChild=$targetById[$mappedId]??null;
                        if ($mappedId<1 || $actualChild===null) {throw new \RuntimeException('Final sharing child mapping disappeared');}
                        FinalShareRows::child($actualChild,$child,$mappedId,$parent,$j);unset($targetById[$mappedId]);
                        if($r['approved_tuple']['type']===7){
                            $circleId=$r['approved_tuple']['recipient'];$singleId=$child['share_with'];
                            if((int)$child['share_type']!==7||!is_string($singleId))throw new \RuntimeException('Final Circle recipient child identity changed');
                            $recipientUid=CircleRuntime::recipientUid($j['uid'],$circleId,$singleId,$this->readDb);
                            CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,$singleId,$this->readDb);
                        }else{
                            $memberUid=$child['share_with'];$member=FinalReadCore::fresh($memberUid,$this->readDb);$memberKey='uid:'.$memberUid;
                            if(isset($checkedGroupMembers[$memberKey])){$group=(FinalReadCore::fresh($j['uid'],$this->readDb))->groups()->get($r['approved_tuple']['recipient']);}
                            else{$actorGroup??=(FinalReadCore::fresh($j['uid'],$this->readDb))->groups()->get($r['approved_tuple']['recipient']);$group=$actorGroup;}
                            if ($group===null || !$group->inGroup($member->user())) {throw new \RuntimeException('Final sharing recipient group membership changed');}
                            $checkedGroupMembers[$memberKey]=true;
                        }
                        ($this->fresh)();
                    }
                    };
                    $verifyChildren();
                    if ($targetById!==[]) {throw new \RuntimeException('Final sharing target child set changed');}
                    if ($sourceChildren!==$sealed || count($children)!==count($sealed)) {throw new \RuntimeException('Final sharing original child set changed');}
                    $maps[]=['files_share_id'=>$m['files_share_id'],'form_id'=>$j['form']['id'],'native_share_id'=>$m['native_share_id'],'folder_id'=>$m['folder_id']];
                }
                yield ['mapping',$id,['source_folder'=>(int)$r['folder_id'],'absent'=>false,'mapping'=>$m,'parent'=>$parent,'children'=>$children]];
                // Source and target Core policies use their actual subjects; neither becomes MCP authority.
                foreach ([[$j['uid'],(int)$r['folder_id'],$r['folder_path'],$source],[$j['input']['owner_id'],(int)$m['folder_id'],$m['folder_path'],$parent]] as [$subject,$nodeId,$path,$actualShare]) {
                                        $core=FinalReadCore::fresh($subject,$this->readDb);$home=$root->getUserFolder($subject);FinalReadFiles::capture($home,$budget,$this->readDb);
                    $views=FinalShareViews::read($root,$core->user(),$nodeId,$budget,$this->fresh,$this->readDb);
                    if (!in_array($path,array_column($views->identities(),'path'),true)) {throw new \RuntimeException('Final sharing folder path changed');}
                    if ($subject===$j['input']['owner_id']) {$this->destinations[$nodeId]=[$views,$core->user(),$path];}
                    else {$this->sourceFolders[$nodeId]=true;}
                    if ($subject===$j['input']['owner_id'] && $m['files_share_id']===null) {continue;}
                    $policy=FinalSharePolicyFactory::collect($subject,$r['approved_tuple']['type'],$r['approved_tuple']['recipient'],$nodeId,$home,$root,$views->node($path),$actualShare,$budget,$this->readDb,$this->fresh,$this->frame);
                    $this->policies[]=[$policy,$views,$core->user(),$nodeId,$path];$effects[]=['subject'=>$subject,'node_id'=>$nodeId,'path'=>$path,'views'=>$views->identities()];
                }
            }
        }
        })();
        $this->commitment->assertMatches($stream);
        if ($seen!==$this->count) {throw new \RuntimeException('Final sharing source inventory changed');}
        $actual=$this->read('SELECT files_share_id,form_id,native_share_id,folder_id FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ? ORDER BY files_share_id LIMIT 1001',[$j['form']['id']],1000,$budget);
        usort($maps,static fn($a,$b):int=>$a['files_share_id']<=>$b['files_share_id']);
        if (FinalShareBudget::encode($actual)!==FinalShareBudget::encode($maps)) {throw new \RuntimeException('Final sharing managed mapping set changed');}
        $folderRows=$this->read('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? ORDER BY folder_id LIMIT 1001',[$j['form']['id']],1000,$budget);
        $actualFolders=array_map(static fn(array $row):int=>(int)$row['folder_id'],$folderRows);$expectedFolders=array_keys($this->expectedFolders);sort($expectedFolders,SORT_NUMERIC);
        if ($actualFolders!==$expectedFolders) {throw new \RuntimeException('Final sharing managed folder set changed');}
        ($this->fresh)();$this->collected=true;return $effects;
        } catch (\Throwable $error) {$this->close();throw $error;}
    }
    /** The unique resolver refreshes ALL native resource metadata before either family makes ACL decisions. */
    public function renewViews(FinalShareBudget $budget):void {
        if ($this->closed || !$this->collected || $this->renewed) {throw new \RuntimeException('Final sharing policy projection is unavailable');}
        $this->renewed=true;
        try {
        $root=\OCP\Server::get(\OCP\Files\IRootFolder::class);
        $refreshed=[];
        foreach ($this->policies as $key=>[$policy,$prior,$subject,$id,$path]) {
            $cacheKey=$subject->getUID().':'.$id;
            $fresh=$refreshed[$cacheKey]??$prior->renew($budget,$this->frame);
            if ($fresh->identities()!==$prior->identities()) {throw new \RuntimeException('Final sharing complete view set changed');}
            if(!isset($refreshed[$cacheKey])){$fresh->preparePermissions();$refreshed[$cacheKey]=$fresh;}
            $this->policies[$key]=[$policy,$fresh,$subject,$id,$path];
        }
        foreach($this->destinations as $id=>[$prior,$subject,$path]) {
            $key=$subject->getUID().':'.$id;$fresh=$refreshed[$key]??$prior->renew($budget,$this->frame);
            if($fresh->identities()!==$prior->identities())throw new \RuntimeException('Final sharing destination views changed');
            FinalNativeAcl::initialized($fresh->node($path));$this->destinations[$id]=[$fresh,$subject,$path];
        }
        } catch (\Throwable $error) {$this->close();throw $error;}
    }
    public function captureFile(\OCP\Files\Node $node,FinalShareBudget $budget):void {
        if(!$this->collected||$this->closed||$this->readDb===null)throw new \RuntimeException('Final sharing collector escaped or repeated');
        FinalReadFiles::capture($node,$budget,$this->readDb);
    }
    public function admitSnapshot():void {
        if(!$this->renewed||$this->closed||$this->window===null)throw new \RuntimeException('Final sharing collector escaped or repeated');
        $this->window->admit();
    }
    public function verifySnapshot():void {
        if($this->snapshotVerified||$this->closed||$this->window===null)throw new \RuntimeException('Final sharing collector escaped or repeated');
        $this->window->verify();$this->snapshotVerified=true;
    }
    public function resourceProjection():array {
        if (!$this->collected || $this->closed) {throw new \RuntimeException('Final sharing policy projection is unavailable');}
        $files=[];foreach(array_keys($this->sourceFolders) as $id){$files[]=['file_id'=>$id];}
        $parents=[];foreach($this->destinations as [$views,$subject,$path]){$parents[]=$views->node($path);}
        return [$files,$parents];
    }
    public function resolve():void {
        if ($this->closed || !$this->collected || !$this->renewed || !$this->snapshotVerified) {throw new \RuntimeException('Final sharing collector escaped or repeated');}
        $this->closed=true;
        try {
            if($this->finalExpirations!==[]){if($this->expiry===null||$this->finalExpiryPolicy===null)throw new \RuntimeException('Transfer activation inputs are unavailable');foreach($this->finalExpirations as$expiration)TransferExpiryInputs::assertCurrent($this->expiry,$this->finalExpiryPolicy,$expiration,time());}
            foreach($this->destinations as [$views,$subject,$path]){FinalNativeAcl::readable($views->node($path),true);}
            foreach ($this->policies as [$policy,$views,$subject,$id,$path]) {$policy->resolve($views->node($path),$views->permissions());}}
        finally {foreach ($this->policies as [$policy]) {$policy->close();}$this->policies=[];}
    }
    public function close():void {$this->window?->close();$this->closed=true;foreach ($this->policies as [$policy]) {$policy->close();}$this->policies=[];}
}
