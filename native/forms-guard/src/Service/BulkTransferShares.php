<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
use OCP\Files\{Folder,IRootFolder};
use OCP\Lock\ILockingProvider;
/** Original-actor transfer capability, never a target-user session or arbitrary share authority. */
final class BulkTransferShares {
 public function __construct(private IDBConnection $db,private BulkStore $store){}
 public static function relative(string $owner,string $path):string {
  $prefix='/'.$owner.'/files/';if(!str_starts_with($path,$prefix)){throw new \RuntimeException('Transfer source is outside original owner storage');}$relative=substr($path,strlen($prefix));if($relative===''||in_array('..',explode('/',$relative),true)){throw new \RuntimeException('Invalid transfer source path');}return $relative;
 }
 public static function path(array $j,string $sourcePath):string {
  $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);
  return Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form).'/bulk-'.$j['id'].'/source/'.self::relative($j['form']['owner_id'],$sourcePath);
 }
 /** Exact native recipient acceptance/restriction metadata; never infer new consent. */
 public static function shareState(string $id):array {
  $identity=CoreShareIdentity::parse($id);SharingIdentity::assertType($identity['provider']==='ocCircleShare'?7:0);$db=Server::get(IDBConnection::class);
  $state=$db->executeQuery('SELECT accepted,expiration,attributes FROM *PREFIX*share WHERE id = ?',[$identity['id']])->fetch();if(!$state){throw new \RuntimeException('Transfer share disappeared');}
  if(strlen(json_encode($state,JSON_THROW_ON_ERROR))>1040000){throw new \OCA\Forms\Mcp\BeforeEffect('Transfer share state exceeds byte budget');}
  return $state;
 }
 /** Native expiration may shorten access; no event may widen the approved decision. */
 private static function assertMetadata(\OCP\Share\IShare $share,array $state):void {
  $attributes=[];foreach($share->getAttributes()?->toArray()??[]as$a){$attributes[]=[$a['scope'],$a['key'],$a['value']];}
  $expected=$state['attributes']===null?[]:json_decode($state['attributes'],true,64,JSON_THROW_ON_ERROR);
  $expiration=$share->getExpirationDate();
  if($share->getStatus()!==(int)$state['accepted']||$attributes!==$expected||($state['expiration']!==null&&($expiration===null||$expiration->getTimestamp()>(new \DateTime($state['expiration']))->getTimestamp()))){throw new \RuntimeException('Transfer provider changed approved recipient restrictions');}
 }
 private static function stagedIdentity(\OCP\Share\IShare $share,array $mapping,int $permissions):void {
  if($share->getPermissions()!==$permissions){throw new \RuntimeException('Transfer quarantine permissions changed');}$validation=clone $share;$validation->setPermissions(1);ManagedShares::assertIdentity($validation,$mapping['approved_tuple'],$mapping);
 }
 public static function capture(array $row,array $j):array {
  $row['live']=!ManagedShares::isAbsent($row['files_share_id']);
  if(!$row['live']){return $row;}
  FinalShareProfile::assertCurrent();
  $folder=Server::get(IRootFolder::class)->getUserFolder($j['form']['owner_id'])->getById((int)$row['folder_id'])[0]??null;
  if(!$folder instanceof Folder||$folder->getOwner()?->getUID()!==$j['form']['owner_id']){throw new \RuntimeException('Managed transfer folder is unavailable');}
  StorageAdmission::node($folder);self::relative($j['form']['owner_id'],$folder->getPath());$row['folder_path']=$folder->getPath();$row['share_state']=self::shareState($row['files_share_id']);
  $result=Server::get(IDBConnection::class)->executeQuery('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE id = ?',[FinalShareRows::id($row['files_share_id'])]);try{$row['core_state']=$result->fetch();}finally{$result->closeCursor();}
  if(!is_array($row['core_state'])){throw new \RuntimeException('Transfer original share disappeared');}
  if($row['approved_tuple']['type']===1){$row['group_lifetime']=NativeGroupHistory::capture($row['approved_tuple']['recipient'],$j);}
  if($row['approved_tuple']['type']===7){$row['circle_audience']=CircleRuntime::linkedAudienceEvidence($j['uid'],$row['approved_tuple']['recipient'],CoreShareIdentity::id($row['files_share_id'],7),(int)$row['folder_id']);}
  return $row;
 }
 private function authority(array $j,array $r,?Folder $folder=null):void {
  OriginContext::assertFresh();$v=OriginContext::current()['verified'];
  if($v['tool']!=='forms_transfer'||$v['uid']!==$j['uid']||(int)($v['references']['form_id']??0)!==(int)$j['form']['id']||!in_array('share',$v['references']['files_operations']??[],true)){throw new \RuntimeException('Original transfer and sharing authority unavailable');}
  $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);
  if($form->getOwnerId()!==$j['uid']||$form->getState()===2){throw new \RuntimeException('Original transfer owner authority changed');}
  $native=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$r['native_share_id']);
  if($native->getFormId()!==$form->getId()||$native->getShareType()!==$r['approved_tuple']['type']||$native->getShareWith()!==$r['approved_tuple']['recipient']||!in_array('results',$native->getPermissions(),true)){throw new \RuntimeException('Approved transfer audience changed');}
  (new FreshCore($j['input']['owner_id']))->assertPrincipal(false,false);
  if(!hash_equals($j['target_principal'],\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($j['input']['owner_id']))){throw new \RuntimeException('Transfer target incarnation changed');}
  SharingIdentity::assertType($r['approved_tuple']['type']);ManagedShares::assertIdentity(SharingIdentity::manager()->getShareById($r['files_share_id'],onlyValid:false),$r['approved_tuple'],$r);
  if(self::shareState($r['files_share_id'])!==$r['share_state']){throw new \RuntimeException('Original recipient acceptance or restrictions changed');}
  if($r['approved_tuple']['type']===7){$audience=CircleRuntime::linkedAudienceEvidence($j['uid'],$r['approved_tuple']['recipient'],CoreShareIdentity::id($r['files_share_id'],7),(int)$r['folder_id']);if($audience!==($r['circle_audience']??null)){throw new \RuntimeException('Original Circle audience changed');}}
  $source=Server::get(IRootFolder::class)->getUserFolder($j['uid'])->getById((int)$r['folder_id'])[0]??null;
  if(!$source instanceof Folder||$source->getPath()!==$r['folder_path']||$source->getOwner()?->getUID()!==$j['uid']){throw new \RuntimeException('Transfer source folder changed');}StorageAdmission::node($source);
  if($folder!==null){DestinationFiles::authorize($folder,true);StorageAdmission::node($folder);if($folder->getOwner()?->getUID()!==$j['input']['owner_id']||$folder->getPath()!=='/'.$j['input']['owner_id'].'/files/'.self::path($j,$r['folder_path'])){throw new \RuntimeException('Transfer destination folder changed');}}
  BulkDeadline::check();
 }
 public function stage(array &$j,array $row,?callable $boundary):void {
  $r=$row['data'];if(!($r['live']??false)){return;}$this->authority($j,$r);
  $impact=['files'=>[],'files_share'=>true,'share_impact'=>['parent'=>['file_id'=>(int)$r['folder_id']],'folder_ids'=>[(int)$r['folder_id']],'recipient'=>$r['approved_tuple']['recipient'],'type'=>$r['approved_tuple']['type'],'create'=>true,'permissions'=>['read']],'destination_owner'=>$j['input']['owner_id'],'destination_path'=>self::path($j,$r['folder_path']),'source_shares'=>'retained'];
  OriginContext::withFileBoundary($boundary,function()use(&$j,$r,$row,$impact):void{OriginContext::withFileImpact('forms_transfer',$impact,function()use(&$j,$r,$row):void{
   $root=Server::get(IRootFolder::class)->getUserFolder($j['input']['owner_id']);if($j['cursor']===0&&$j['counts']['files']===0){$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);$base=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);if($root->nodeExists($base)){throw new \RuntimeException('Transfer destination already exists');}}$path=self::path($j,$r['folder_path']);
   $wasPresent=$root->nodeExists($path);$folder=NewFileStorage::folder($root,$path,function()use($j,$r):void{$this->authority($j,$r);},destination:DestinationFiles::callback());$folder->lock(ILockingProvider::LOCK_EXCLUSIVE);
   try{
    $this->authority($j,$r,$folder);OriginContext::assertFileEffect([(int)$r['folder_id']]);
    $tuple=['owner_id'=>$j['input']['owner_id'],'recipient'=>$r['approved_tuple']['recipient'],'type'=>$r['approved_tuple']['type']];
    $mapped=['files_share_id'=>null,'folder_id'=>(int)$folder->getId(),'folder_path'=>$folder->getPath(),'native_share_id'=>(int)$r['native_share_id'],'approved_tuple'=>$tuple];
    if(!($tuple['type']===0&&$tuple['recipient']===$j['input']['owner_id'])){
     $authorize=function(\OCP\Share\IShare $share,bool $create)use($j,$r,$folder,$tuple):void{
      OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);self::assertMetadata($share,$r['share_state']);
      if(!$create||$share->getSharedBy()!==$j['uid']||$share->getShareOwner()!==$tuple['owner_id']||$share->getSharedWith()!==$tuple['recipient']||$share->getShareType()!==$tuple['type']||(int)$share->getNodeId()!==(int)$folder->getId()||!in_array($share->getPermissions(),[0,1],true)){throw new \RuntimeException('Transfer provider tuple differs from approval');}
     };
     $source=Server::get(IRootFolder::class)->getUserFolder($j['uid'])->getById((int)$r['folder_id'])[0]??null;if(!$source instanceof Folder){throw new \RuntimeException('Transfer source folder unavailable');}
     $transferCircle=$tuple['type']===7;$writeCapability=$transferCircle?\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary::bulkTransferParent($this->store,$j,$row,$mapped):null;
     $eventProof=$transferCircle?$this->circleEventProof($j,$r,$row,$mapped,$folder,'create'):null;
     $manager=ScopedShareManager::create($j['uid'],$authorize,null,$eventProof,$writeCapability);
     if(!$transferCircle){$manager=TransferShareManager::scoped($manager,$source,$authorize);ScopedShareManager::quarantineTransfer($manager);}
     $share=$manager->newShare();$share->setShareType($tuple['type']);$share->setSharedWith($tuple['recipient']);$share->setSharedBy($j['uid']);$share->setShareOwner($tuple['owner_id']);$share->setNode($folder);$share->setPermissions($transferCircle?0:1);$share->setStatus((int)$r['share_state']['accepted']);if($r['share_state']['expiration']!==null){$share->setExpirationDate(new \DateTime($r['share_state']['expiration']));}if($r['share_state']['attributes']!==null){$attributes=$share->newAttributes();foreach(json_decode($r['share_state']['attributes'],true,64,JSON_THROW_ON_ERROR)as$a){$attributes->setAttribute($a[0],$a[1],$a[2]);}$share->setAttributes($attributes);}
     if($transferCircle){$j['share_events']=true;$j['event_phase']='uncertain';}
     $created=$manager->createShare($share);if(!ScopedShareManager::createdHere($manager,$created->getFullId())){throw new \RuntimeException('Transfer cannot adopt a pre-existing destination share');}
     $mapped['files_share_id']=$created->getFullId();$actual=$manager->getShareById($created->getFullId(),onlyValid:false);self::stagedIdentity($actual,$mapped,0);self::assertMetadata($actual,$r['share_state']);$mapped['share_state']=self::shareState($created->getFullId());if($actual->getSharedBy()!==$j['uid']){throw new \RuntimeException('Transfer share initiator changed');}
     $j['share_events']=true;$j['event_phase']='attempted_unverified';$j['retained_shares']=($j['retained_shares']??0)+1;
    }
    $this->authority($j,$r,$folder);if(!$wasPresent){$j['retained_folders']=($j['retained_folders']??0)+1;}$this->store->putMapping($j,'staged_shares',$row['id'],$mapped);if($mapped['files_share_id']!==null){$this->store->map($j['id'],'staged_shares',$row['id'],CoreShareIdentity::id($mapped['files_share_id'],$tuple['type']));}
   }finally{$folder->unlock(ILockingProvider::LOCK_EXCLUSIVE);}
  });});
 }
 private function recipientState(int $id):array {
  $row=$this->db->executeQuery('SELECT share_with,accepted,permissions,expiration,attributes,parent,uid_owner,uid_initiator,file_source,share_type FROM *PREFIX*share WHERE id = ?',[$id])->fetch();if(!$row){throw new \RuntimeException('Recipient state disappeared');}return $row;
 }
 private function assertRecipientSource(array $row):void {
  foreach($this->recipientState((int)$row['id'])as$key=>$value){if($row[$key]!==$value){throw new \RuntimeException('Original recipient decision changed');}}
 }
 private function circleEventProof(array $j,array $r,array $row,array $mapped,Folder $folder,string $operation):\Closure {
  return function(?array $eventBinding)use($j,$r,$row,$mapped,$folder,$operation):array {
   $this->authority($j,$r,$folder);OriginContext::assertFileEffect([(int)$r['folder_id']]);
   $source=$this->store->one($j['id'],'managed_shares',(int)$row['id']);
   if(!hash_equals((string)$row['digest'],BulkStore::digest($source))||$source!==$r||$mapped['approved_tuple']['type']!==7||$mapped['approved_tuple']['recipient']!==$r['approved_tuple']['recipient']){throw new \RuntimeException('Bulk Circle event source claim changed');}
   if($eventBinding!==null){
    if(!array_is_list($eventBinding)||count($eventBinding)!==8||$eventBinding[0]!==($operation==='create'?\OCA\Circles\FederatedItems\Files\FileShare::class:\OCA\Circles\FederatedItems\Files\FileUnshare::class)
      ||$eventBinding[1]!==$r['approved_tuple']['recipient']||(int)$eventBinding[5]!==(int)$folder->getId()||(int)$eventBinding[6]!==0){throw new \RuntimeException('Bulk Circle event does not match its staged transfer');}
    $shareId=(int)$eventBinding[4];$native=$this->db->executeQuery('SELECT id,share_type,share_with,uid_owner,uid_initiator,file_source,item_type,parent,permissions FROM *PREFIX*share WHERE id = ? LIMIT 2',[$shareId])->fetchAll();
    if(count($native)!==1||(int)$native[0]['id']!==$shareId||(int)$native[0]['share_type']!==7||$native[0]['share_with']!==$r['approved_tuple']['recipient']
      ||$native[0]['uid_owner']!==$mapped['owner_id']||$native[0]['uid_initiator']!==$j['uid']||(int)$native[0]['file_source']!==(int)$folder->getId()
      ||$native[0]['item_type']!=='folder'||(int)$native[0]['parent']!==0||(int)$native[0]['permissions']!==0){throw new \RuntimeException('Bulk Circle event row does not match its staged transfer');}
   }
   return ['operation'=>$operation,'type'=>7,'owner_id'=>$j['uid'],'share_owner'=>$mapped['owner_id'],'recipient'=>$r['approved_tuple']['recipient'],'file_id'=>(int)$folder->getId()];
  };
 }
 public function stageRecipient(array &$j,array $row,?callable $boundary):void {
  $child=$row['data'];$r=$this->store->one($j['id'],'managed_shares',(int)$child['parent']);$mapped=$this->store->one($j['id'],'staged_shares',(int)$child['parent']);
  if($mapped['files_share_id']===null||!in_array($r['approved_tuple']['type'],[1,7],true)){throw new \RuntimeException('Recipient parent is not a staged group or Circle share');}
  $folder=Server::get(IRootFolder::class)->getUserFolder($j['input']['owner_id'])->getById((int)$mapped['folder_id'])[0]??null;if(!$folder instanceof Folder){throw new \RuntimeException('Staged recipient folder unavailable');}
  $impact=['files'=>[],'files_share'=>true,'share_impact'=>['parent'=>['file_id'=>(int)$r['folder_id']],'folder_ids'=>[(int)$r['folder_id']],'recipient'=>$r['approved_tuple']['recipient'],'type'=>$r['approved_tuple']['type'],'create'=>true,'permissions'=>['read']],'preserve_recipient_state'=>true];
  OriginContext::withFileBoundary($boundary,function()use(&$j,$row,$child,$r,$mapped,$folder,$impact):void{OriginContext::withFileImpact('forms_transfer',$impact,function()use(&$j,$row,$child,$r,$mapped,$folder):void{
   $folder->lock(ILockingProvider::LOCK_EXCLUSIVE);try{
    if($r['approved_tuple']['type']===7){$this->stageCircleRecipient($j,$row,$child,$r,$mapped,$folder);return;}
    $authorize=function(\OCP\Share\IShare $share,bool $create)use($j,$child,$r,$mapped,$folder):void{OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);$this->assertRecipientSource($child);$member=new FreshCore($child['share_with']);$group=(new FreshCore($j['uid']))->groups()->get($r['approved_tuple']['recipient']);if($group===null||!$group->inGroup($member->user())){throw new \RuntimeException('Recipient group authority changed at provider boundary');}self::stagedIdentity($share,$mapped,$share->getPermissions());if(!in_array($share->getPermissions(),[0,1],true)||self::shareState($mapped['files_share_id'])!==$mapped['share_state']){throw new \RuntimeException('Staged parent state changed');}self::assertMetadata($share,$r['share_state']);if(!$create||$share->getSharedBy()!==$j['uid']){throw new \RuntimeException('Recipient transfer initiator changed');}};
    $source=Server::get(IRootFolder::class)->getUserFolder($j['uid'])->getById((int)$r['folder_id'])[0]??null;if(!$source instanceof Folder){throw new \RuntimeException('Recipient source folder unavailable');}
    $manager=TransferShareManager::scoped(ScopedShareManager::create($j['uid'],$authorize),$source,$authorize);$share=$manager->getShareById($mapped['files_share_id'],onlyValid:false);$share->setNode($folder);$manager->validateTransfer($share);
    $ids=ScopedShareManager::preserveTransferRecipients($manager,$share,[$child]);$id=$ids[0]??throw new \RuntimeException('Recipient state was not staged');$actual=$this->recipientState($id);
    foreach(['share_with','accepted','expiration','attributes']as$key){if($actual[$key]!==$child[$key]){throw new \RuntimeException('Recipient decision was not preserved');}}
    if((int)$actual['permissions']!==0||(int)$actual['parent']!==(int)$share->getId()||$actual['uid_owner']!==$j['input']['owner_id']||$actual['uid_initiator']!==$j['uid']||(int)$actual['file_source']!==(int)$folder->getId()){throw new \RuntimeException('Recipient staging identity changed');}
    $this->store->putMapping($j,'staged_recipients',$row['id'],$actual);$this->store->map($j['id'],'staged_recipients',$row['id'],$id);$j['retained_recipients']=($j['retained_recipients']??0)+1;
   }finally{$folder->unlock(ILockingProvider::LOCK_EXCLUSIVE);}
  });});
 }
 private function stageCircleRecipient(array &$j,array $row,array $child,array $r,array $mapped,Folder $folder):void {
  $circleId=$r['approved_tuple']['recipient'];$singleId=$child['share_with']??null;
  if(!is_string($singleId)||($child['share_type']??null)!==7||(int)($child['parent']??0)!==CoreShareIdentity::id($r['files_share_id'],7))throw new \RuntimeException('Original Circle child identity changed');
  $recipientUid=CircleRuntime::recipientUid($j['uid'],$circleId,$singleId);
  CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,$singleId);
  $capability=\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary::bulkTransferChild($this->store,$j,$row,$r,$mapped);
  $proof=function()use($j,$row,$child,$r,$mapped,$folder,$recipientUid,$circleId,$singleId):void {
   $this->authority($j,$r,$folder);$this->assertRecipientSource($child);OriginContext::assertFileEffect([(int)$r['folder_id']]);
   CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,$singleId);
   if($this->store->one($j['id'],'staged_shares',(int)$child['parent'])!==$mapped){throw new \RuntimeException('Staged Circle parent mapping changed');}
   $parentId=CoreShareIdentity::id($mapped['files_share_id'],7);$rows=$this->db->executeQuery('SELECT id,share_type,share_with,uid_owner,uid_initiator,file_source,parent,permissions FROM *PREFIX*share WHERE id = ? LIMIT 2',[$parentId])->fetchAll();
   if(count($rows)!==1||(int)$rows[0]['id']!==$parentId||(int)$rows[0]['share_type']!==7||$rows[0]['share_with']!==$circleId||$rows[0]['uid_owner']!==$j['input']['owner_id']
      ||$rows[0]['uid_initiator']!==$j['uid']||(int)$rows[0]['file_source']!==(int)$folder->getId()||(int)$rows[0]['parent']!==0||(int)$rows[0]['permissions']!==0){throw new \RuntimeException('Staged Circle parent row changed');}
   if($this->db->executeQuery('SELECT id FROM *PREFIX*share WHERE parent = ? AND share_type = 7 AND share_with = ? LIMIT 1',[$parentId,$singleId])->fetchOne()!==false){throw new \RuntimeException('Staged Circle recipient already exists');}
  };
  $authorize=function(\OCP\Share\IShare $share,bool $create,?array $binding=null)use($j,$row,$child,$r,$mapped,$folder,$recipientUid,$circleId,$singleId):void {
   OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);$this->assertRecipientSource($child);
   CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,$singleId);
   if(!$create||$share->getShareType()!==7||$share->getSharedWith()!==$singleId||$share->getShareOwner()!==$j['input']['owner_id']||$share->getSharedBy()!==$j['uid']
      ||(int)$share->getNodeId()!==(int)$folder->getId()||$share->getPermissions()!==0||$binding===null||$binding['source_parent_id']!==(int)$child['parent']
      ||$binding['source_child_id']!==(int)$child['id']||$binding['target_parent_id']!==CoreShareIdentity::id($mapped['files_share_id'],7)||$binding['parent_share_with']!==$circleId){throw new \RuntimeException('Circle child write differs from its transfer claim');}
  };
  $manager=ScopedShareManager::create($j['uid'],$authorize,null,null,$capability);$provider=ScopedShareManager::circleProvider($manager);
  $parent=$manager->getShareById($mapped['files_share_id'],onlyValid:false);
  self::stagedIdentity($parent,$mapped,0);$this->authority($j,$r,$folder);
  $childSeed=$child;$parentExpiry=$r['share_state']['expiration']??null;
  if($parentExpiry!==null&&(($childSeed['expiration']??null)===null||(new \DateTime((string)$childSeed['expiration']))>(new \DateTime((string)$parentExpiry))))$childSeed['expiration']=$parentExpiry;
  $created=$provider->createTransferChild($parent,$childSeed,$recipientUid,$proof);$id=(int)$created->getId();
  if($id<1){throw new \RuntimeException('Native Circle child creation returned an invalid ID');}
  $actual=$this->recipientState($id);
  foreach(['share_with','accepted','attributes','expiration'] as$key){if($actual[$key]!==$childSeed[$key])throw new \RuntimeException('Circle recipient decision was not preserved');}
  $parentId=CoreShareIdentity::id($mapped['files_share_id'],7);
  if((int)$actual['permissions']!==0||(int)$actual['parent']!==$parentId||(int)$actual['share_type']!==7||$actual['uid_owner']!==$j['input']['owner_id']
    ||$actual['uid_initiator']!==$j['uid']||(int)$actual['file_source']!==(int)$folder->getId()){throw new \RuntimeException('Circle child staging identity changed');}
  $this->store->putMapping($j,'staged_recipients',$row['id'],$actual);$this->store->map($j['id'],'staged_recipients',$row['id'],$id);$j['retained_recipients']=($j['retained_recipients']??0)+1;
 }
 private function verifyRecipients(array $j):void {
  $cursor=0;while($rows=$this->store->page($j['id'],'share_recipients',$cursor)){foreach($rows as$row){BulkDeadline::check();$cursor=$row['id'];$id=$this->store->mapped($j['id'],'staged_recipients',$row['id']);foreach([$row['id'],$id]as$lock){$this->db->executeQuery('SELECT id FROM *PREFIX*share WHERE id = ? FOR UPDATE',[$lock])->fetchOne();}$this->assertRecipientSource($row['data']);if($this->recipientState($id)!==$this->store->one($j['id'],'staged_recipients',$row['id'])){throw new \RuntimeException('Staged recipient decision changed');}}}
 }
 /** Native policy checks and exact provider SQL activate quarantined rows in the final transaction. */
 private function activate(array $j,array $r,array $m,Folder $folder,array $managedRow):array {
  $expiry=null;
  $impact=['files'=>[],'files_share'=>true,'share_impact'=>['parent'=>['file_id'=>(int)$r['folder_id']],'folder_ids'=>[(int)$r['folder_id']],'recipient'=>$r['approved_tuple']['recipient'],'type'=>$r['approved_tuple']['type'],'create'=>true,'permissions'=>['read']],'destination_owner'=>$j['input']['owner_id'],'destination_path'=>self::path($j,$r['folder_path'])];
  OriginContext::withFileImpact('forms_transfer',$impact,function()use($j,$r,$m,$folder,$managedRow,&$expiry):void{
   $sourceParent=CoreShareIdentity::id($r['files_share_id'],$r['approved_tuple']['type']);$childType=$r['approved_tuple']['type']===7?7:2;$expected=(int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ? AND share_type = ?',[$sourceParent,$childType])->fetchOne();$parent=CoreShareIdentity::id($m['files_share_id'],$m['approved_tuple']['type']);
   if((int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ?',[$parent])->fetchOne()!==$expected){throw new \RuntimeException('Unapproved or duplicate staged recipient rows');}
   if($r['approved_tuple']['type']===7){$expiry=$this->activateCircle($j,$r,$m,$folder,$managedRow,$sourceParent,$parent);$this->authority($j,$r,$folder);if((int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ?',[$parent])->fetchOne()!==$expected){throw new \RuntimeException('Circle recipient set changed during activation');}return;}
   $authorize=function(\OCP\Share\IShare $share,bool $create)use($j,$r,$m,$folder):void{OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);self::stagedIdentity($share,$m,1);self::assertMetadata($share,$r['share_state']);if(!$create||$share->getSharedBy()!==$j['uid']){throw new \RuntimeException('Transfer activation initiator changed');}};
   $source=Server::get(IRootFolder::class)->getUserFolder($j['uid'])->getById((int)$r['folder_id'])[0]??null;if(!$source instanceof Folder){throw new \RuntimeException('Transfer activation source unavailable');}
   $manager=TransferShareManager::scoped(ScopedShareManager::create($j['uid'],$authorize),$source,$authorize);$share=$manager->getShareById($m['files_share_id'],onlyValid:false);$share->setNode($folder);$share->setPermissions(1);ScopedShareManager::activateTransfer($manager,$share);$active=$manager->getShareById($m['files_share_id'],onlyValid:false);self::stagedIdentity($active,$m,1);self::assertMetadata($active,$r['share_state']);
   $cursor=0;while($rows=$this->store->page($j['id'],'share_recipients',$cursor)){$batch=[];foreach($rows as$row){$cursor=$row['id'];$child=$row['data'];if((int)$child['parent']!==$sourceParent){continue;}BulkDeadline::check();$id=$this->store->mapped($j['id'],'staged_recipients',$row['id']);$expiration=$child['expiration'];$parentExpiry=$active->getExpirationDate();if($parentExpiry!==null&&($expiration===null||(new \DateTime($expiration))>$parentExpiry)){$expiration=$parentExpiry->format('Y-m-d H:i:s');}$batch[]=['id'=>$id,'source'=>$child,'expiration'=>$expiration];}
    if($batch===[]){continue;}ScopedShareManager::activateTransferRecipients($manager,$active,$batch,fn(array $child)=> $this->assertRecipientSource($child));
    foreach($batch as$row){$actual=$this->recipientState($row['id']);foreach(['share_with','accepted','permissions','attributes']as$key){if($actual[$key]!==$row['source'][$key]){throw new \RuntimeException('Activated recipient differs from approved decision');}}if($actual['expiration']!==$row['expiration']){throw new \RuntimeException('Activated recipient expiry differs from approved restriction');}}
   }
   $this->authority($j,$r,$folder);if((int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ?',[$parent])->fetchOne()!==$expected){throw new \RuntimeException('Recipient set changed during activation');}
   $expiry=$manager->activationExpiry();
  });
  return $expiry??throw new \RuntimeException('Transfer activation inputs were not recorded');
 }
 private function activateCircle(array $j,array $r,array $m,Folder $folder,array $managedRow,int $sourceParent,int $targetParent):array {
  $source=Server::get(IRootFolder::class)->getUserFolder($j['uid'])->getById((int)$r['folder_id'])[0]??null;if(!$source instanceof Folder)throw new \RuntimeException('Circle activation source folder unavailable');
  $parentId=CoreShareIdentity::id($m['files_share_id'],7);$circleId=$r['approved_tuple']['recipient'];
  $nativeChecks=function(\OCP\Share\IShare $share,bool $create)use($j,$r,$m,$folder,$parentId):void {OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);if(!$create||$share->getShareType()!==7||$share->getSharedWith()!==$r['approved_tuple']['recipient']||$share->getShareOwner()!==$j['input']['owner_id']||$share->getSharedBy()!==$j['uid']||(int)$share->getNodeId()!==(int)$folder->getId()||(int)$share->getId()!==$parentId||$share->getPermissions()!==1)throw new \RuntimeException('Circle transfer native activation tuple changed');self::assertMetadata($share,$r['share_state']);};
  $checkManager=ScopedShareManager::create($j['uid'],$nativeChecks,null,null,CircleDetached\ShareWriteBoundary::bulkTransferActivation($this->store,$j,$managedRow,$m));
  $transfer=TransferShareManager::scoped($checkManager,$source,$nativeChecks);$staged=$checkManager->getShareById($m['files_share_id'],onlyValid:false);self::stagedIdentity($staged,$m,0);$staged->setPermissions(1);$transfer->validateActivation($staged);$expiry=$transfer->activationExpiry();
  $writeCapability=CircleDetached\ShareWriteBoundary::bulkTransferActivation($this->store,$j,$managedRow,$m);
  $writeBoundary=function(\OCP\Share\IShare $share,bool $create,?array $binding=null)use($j,$r,$m,$folder,$parentId):void {OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);if($create||$share->getShareType()!==7||$share->getSharedWith()!==$r['approved_tuple']['recipient']||$share->getShareOwner()!==$j['input']['owner_id']||$share->getSharedBy()!==$j['uid']||(int)$share->getNodeId()!==(int)$folder->getId()||(int)$share->getId()!==$parentId||$share->getPermissions()!==1||$binding!==null)throw new \RuntimeException('Circle transfer parent activation differs from its claim');self::assertMetadata($share,$r['share_state']);};
  $writeManager=ScopedShareManager::create($j['uid'],$writeBoundary,null,null,$writeCapability);$provider=ScopedShareManager::circleProvider($writeManager);
  $parent=$provider->getShareById($parentId);self::stagedIdentity($parent,$m,0);$this->authority($j,$r,$folder);$parent->setPermissions(1);$provider->update($parent);
  $active=$provider->getShareById($parentId);self::stagedIdentity($active,$m,1);self::assertMetadata($active,$r['share_state']);
  $cursor=0;while($rows=$this->store->page($j['id'],'share_recipients',$cursor))foreach($rows as$row){$cursor=$row['id'];$child=$row['data'];if((int)$child['parent']!==$sourceParent)continue;BulkDeadline::check();$targetChild=$this->store->mapped($j['id'],'staged_recipients',$row['id']);$expectedExpiry=$child['expiration']??null;$parentExpiry=$r['share_state']['expiration']??null;if($parentExpiry!==null&&($expectedExpiry===null||(new \DateTime($expectedExpiry))>(new \DateTime($parentExpiry))))$expectedExpiry=$parentExpiry;
   $recipientUid=CircleRuntime::recipientUid($j['uid'],$circleId,(string)$child['share_with']);CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,(string)$child['share_with']);$this->assertRecipientSource($child);
   if((int)$child['permissions']===1){$childCapability=CircleDetached\ShareWriteBoundary::bulkTransferActivation($this->store,$j,$managedRow,$m,$row);$childBoundary=function(\OCP\Share\IShare $share,bool $create,?array $binding=null)use($j,$r,$folder,$parentId,$recipientUid,$circleId,$child,$targetChild):void {OriginContext::assertFileEffect([(int)$r['folder_id']]);$this->authority($j,$r,$folder);$this->assertRecipientSource($child);CircleRuntime::assertRecipientMember($j['uid'],$circleId,$recipientUid,(string)$child['share_with']);if($create||$share->getShareType()!==7||$share->getSharedWith()!==$circleId||$share->getShareOwner()!==$j['input']['owner_id']||$share->getSharedBy()!==$j['uid']||(int)$share->getNodeId()!==(int)$folder->getId()||(int)$share->getId()!==$parentId||$share->getPermissions()!==1||$binding!==['child_id'=>$targetChild])throw new \RuntimeException('Circle recipient activation differs from its claim');};$recipientManager=ScopedShareManager::create($j['uid'],$childBoundary,null,null,$childCapability);$recipientProvider=ScopedShareManager::circleProvider($recipientManager);$recipientParent=$recipientProvider->getShareById($parentId);$recipientProvider->restore($recipientParent,$recipientUid);}
   elseif((int)$child['permissions']!==0)throw new \RuntimeException('Circle recipient permission decision changed');
   $actual=$this->recipientState($targetChild);foreach(['share_with','accepted','attributes']as$key)if($actual[$key]!==$child[$key])throw new \RuntimeException('Activated Circle recipient decision changed');if((int)$actual['permissions']!==(int)$child['permissions']||(int)$actual['parent']!==$parentId||(int)$actual['share_type']!==7||$actual['expiration']!==$expectedExpiry||$actual['uid_owner']!==$j['input']['owner_id']||$actual['uid_initiator']!==$j['uid']||(int)$actual['file_source']!==(int)$folder->getId())throw new \RuntimeException('Activated Circle recipient identity changed');
  }
  return$expiry;
 }
 /** All mappings switch within the existing final SQL transaction. Original shares remain untouched. */
 public function commit(array $j):?FinalTransferShareCheck {
  if($j['counts']['managed_shares']===0){if($j['counts']['share_recipients']!==0)throw new \RuntimeException('Transfer recipient inventory has no managed parent');return null;}
  $capability=OriginContext::transferSharingAuthority();$final=new FinalTransferShareCheck($this->db,$this->store,$j,$capability['fresh'],$capability['frame']);
  $this->verifyRecipients($j);$cursor=0;while($rows=$this->store->page($j['id'],'managed_shares',$cursor)){foreach($rows as$row){
   BulkDeadline::check();$cursor=$row['id'];$r=$row['data'];$expiry=null;$m=null;
   if($r['live']??false){$m=$this->store->one($j['id'],'staged_shares',$row['id']);foreach([$r['files_share_id'],$m['files_share_id']]as$shareId){if($shareId!==null){$this->db->executeQuery('SELECT id FROM *PREFIX*share WHERE id = ? FOR UPDATE',[CoreShareIdentity::id($shareId,$r['approved_tuple']['type'])])->fetchOne();}}$folder=Server::get(IRootFolder::class)->getUserFolder($j['input']['owner_id'])->getById((int)$m['folder_id'])[0]??null;if(!$folder instanceof Folder){throw new \RuntimeException('Staged transfer folder unavailable');}$this->authority($j,$r,$folder);
    if($m['files_share_id']!==null){$share=SharingIdentity::manager()->getShareById($m['files_share_id'],onlyValid:false);self::stagedIdentity($share,$m,0);if($share->getSharedBy()!==$j['uid']||self::shareState($m['files_share_id'])!==$m['share_state']){throw new \RuntimeException('Staged transfer identity or recipient state changed');}
     $expiry=$this->activate($j,$r,$m,$folder,$row);
     $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_shares (files_share_id,form_id,native_share_id,folder_id) VALUES (?,?,?,?)',[$m['files_share_id'],$j['form']['id'],$m['native_share_id'],$m['folder_id']]);
    }
    $exists=$this->db->executeQuery('SELECT folder_id FROM *PREFIX*forms_mcp_folders WHERE form_id = ? AND folder_id = ?',[$j['form']['id'],$m['folder_id']])->fetchOne();if($exists===false){$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_folders (form_id,folder_id) VALUES (?,?)',[$j['form']['id'],$m['folder_id']]);}
   }
   $count=$this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ? AND form_id = ? AND native_share_id = ? AND folder_id = ?',[$r['files_share_id'],$j['form']['id'],$r['native_share_id'],$r['folder_id']]);if($count!==1){throw new \RuntimeException('Original managed share mapping changed');}
   $this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_folders WHERE form_id = ? AND folder_id = ?',[$j['form']['id'],$r['folder_id']]);
   if($m===null){$final->verified($row['id'],['source_folder'=>(int)$r['folder_id'],'absent'=>true]);}
   else{$final->verified($row['id'],$this->verifiedActivation($j,$r,$m),$expiry);}
  }}
  $final->seal();return $final;
 }
 private function verifiedActivation(array $j,array $r,array $m):array {
  $parent=null;$children=[];
  if($m['files_share_id']!==null){
   $result=$this->db->executeQuery('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE id = ?',[FinalShareRows::id($m['files_share_id'])]);try{$parent=$result->fetch();}finally{$result->closeCursor();}if(!is_array($parent)){throw new \RuntimeException('Activated transfer share disappeared');}FinalShareRows::target($parent,$r,$m,$j);
   $budget=new FinalShareBudget(static fn()=>BulkDeadline::check());$result=$this->db->executeQuery('SELECT '.FinalShareRows::COLUMNS.' FROM *PREFIX*share WHERE parent = ? ORDER BY id LIMIT 1001',[(int)$parent['id']]);$children=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),1000);
   $approved=[];$cursor=0;while($page=$this->store->page($j['id'],'share_recipients',$cursor)){foreach($page as$item){$cursor=$item['id'];$child=$item['data'];if((int)$child['parent']!==FinalShareRows::id($r['files_share_id'])){continue;}$id=$this->store->mapped($j['id'],'staged_recipients',$item['id']);if(isset($approved[$id])){throw new \RuntimeException('Duplicate activated recipient mapping');}$approved[$id]=$child;}}
   if(count($approved)!==count($children)){throw new \RuntimeException('Activated recipient inventory changed');}
   foreach($children as$child){$id=(int)$child['id'];if(!isset($approved[$id])){throw new \RuntimeException('Unapproved activated recipient');}FinalShareRows::child($child,$approved[$id],$id,$parent,$j);unset($approved[$id]);}
   if($approved!==[]){throw new \RuntimeException('Activated recipient inventory changed');}
  }elseif($r['approved_tuple']['type']!==0||$r['approved_tuple']['recipient']!==$j['input']['owner_id']){throw new \RuntimeException('Transfer ownership mapping changed');}
  return ['source_folder'=>(int)$r['folder_id'],'absent'=>false,'mapping'=>$m,'parent'=>$parent,'children'=>$children];
 }
}
