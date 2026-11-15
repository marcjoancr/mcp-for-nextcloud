<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
use OCP\Files\{File,IRootFolder};
/** Explicit current-state absence, never proof of who removed the historical bytes. */
final class HistoricalFiles {
 public const FORMAT='bulk_history_v2';
 public static function ready():bool {return Server::get(IDBConnection::class)->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261114020000'])->fetchOne()!==false&&NativeHistoryIdentity::ready();}
 public static function requireReady():void {if(!self::ready()){throw new \OCA\Forms\Mcp\BeforeEffect('Qualified historical-file index migration is required');}}
 public static function unavailable(int $formId,int $fileId):?array {
  if($fileId<1){throw new \RuntimeException('Invalid historical file identity');}
  if(($normalized=self::normalized($formId,$fileId))!==null){return $normalized;}
  $db=Server::get(IDBConnection::class);$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId);
  $root=Server::get(IRootFolder::class)->getUserFolder($form->getOwnerId());StorageAdmission::node($root);
  if(!$root->getStorage()->isReadable($root->getInternalPath())){throw new \RuntimeException('Original owner storage is unreadable');}
  $nodes=$root->getById($fileId);
  if($nodes!==[]){foreach($nodes as$node){if(!$node instanceof File){throw new \RuntimeException('Managed reference is not a file');}StorageAdmission::node($node);}return null;}
  self::requireReady();
  $cache=$db->executeQuery('SELECT storage,path,etag FROM *PREFIX*filecache WHERE fileid = ?',[$fileId])->fetch();$absence=['kind'=>'no_owner_node'];
  if($cache!==false){$ownerStorage=$db->executeQuery('SELECT storage FROM *PREFIX*filecache WHERE fileid = ?',[$root->getId()])->fetchOne();if($ownerStorage===false||(int)$cache['storage']!==(int)$ownerStorage||!str_starts_with($cache['path'],'files_trashbin/')){throw new \RuntimeException('Existing managed file is outside original owner resolution');}$absence=['kind'=>'owner_private_trash','storage'=>(int)$cache['storage'],'path'=>$cache['path'],'etag'=>$cache['etag']];}
  // Successful lookup must not turn an unavailable owner mount into historical absence.
  $directory=$root->getStorage()->opendir($root->getInternalPath());if(!is_resource($directory)){throw new \RuntimeException('Original owner storage cannot establish absence');}closedir($directory);
  if($db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE file_id = ? AND form_id <> ? LIMIT 1',[$fileId,$formId])->fetchOne()!==false){throw new \RuntimeException('Missing artifact is not native question history');}
  $hash=hash_init('sha256');$artifact=$db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_artifacts WHERE file_id = ? AND form_id = ?',[$fileId,$formId])->fetchAll();hash_update($hash,json_encode($artifact,JSON_THROW_ON_ERROR));$count=0;$answers=0;$uploads=0;
  foreach(['answers','uploads']as$kind){$after=0;do{
   $sql=$kind==='answers'?'SELECT a.id,a.question_id,a.submission_id,a.text,a.file_id,s.form_id AS submission_form,q.form_id AS question_form,q.type,q.'. ($db->getDatabaseProvider(true)==='postgres'?'"order"':'`order`').' AS question_order FROM *PREFIX*forms_v2_answers a LEFT JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id LEFT JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE a.file_id = ? AND a.id > ? ORDER BY a.id LIMIT 100':'SELECT a.id,a.form_id AS submission_form,a.question_id,a.file_id,a.original_file_name,a.created,q.form_id AS question_form,q.type,q.'.($db->getDatabaseProvider(true)==='postgres'?'"order"':'`order`').' AS question_order FROM *PREFIX*forms_v2_uploaded_files a LEFT JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE a.file_id = ? AND a.id > ? ORDER BY a.id LIMIT 100';
   $page=$db->executeQuery($sql,[$fileId,$after])->fetchAll();foreach($page as$row){BulkDeadline::check();if(++$count>BulkStore::MAX_ROWS||(int)$row['submission_form']!==$formId||(int)$row['question_form']!==$formId||$row['type']!=='file'||(int)$row['question_order']!==0){throw new \RuntimeException('Missing file is not exact hidden-question history');}hash_update($hash,$kind.':'.json_encode($row,JSON_THROW_ON_ERROR));$after=(int)$row['id'];if($kind==='answers'){$answers++;}else{$uploads++;}}
  }while(count($page)===100);}
  if($answers===0){throw new \RuntimeException('Missing file has no exact native submission history');}
  return ['file_id'=>$fileId,'availability'=>'historical_unavailable','form_id'=>$formId,'owner_id'=>$form->getOwnerId(),'answer_count'=>$answers,'upload_count'=>$uploads,'artifact_count'=>count($artifact),'references_digest'=>hash_final($hash),'unavailable_cause'=>'unverified','node_absent'=>true,'absence_evidence'=>$absence];
 }
 public static function isUnavailable(array $meta):bool {return ($meta['availability']??null)==='historical_unavailable';}
 /** Password-stable Forms-owned account incarnation; neither credential hashes nor UID alone prove lifetime. */
 public static function ownerIdentity(string $uid,?string $actor=null):array {
  if($actor===null){$actor=NativeHistoryIdentity::capture($uid);}else{NativeHistoryIdentity::assertCurrent($uid,$actor);}
  return ['uid'=>$uid,'native_actor'=>$actor];
 }
 public static function historyForAnswer(int $formId,int $answerId):?array {
  if(!self::ready()){return null;}
  $db=Server::get(IDBConnection::class);$rows=$db->executeQuery("SELECT bulk_id,mapped_id FROM *PREFIX*forms_mcp_bulk_rows WHERE kind = 'file_history' AND source_id = ? LIMIT 2",[$answerId])->fetchAll();
  if($rows===[]){return null;}if(count($rows)!==1){throw new \RuntimeException('Ambiguous restored file history');}
  $store=Server::get(BulkStore::class);$j=$store->load($rows[0]['bulk_id']);$h=$store->one($j['id'],'file_history',$answerId);
  $native=$j['action']==='native_history_transfer';$transfer=$native||$j['action']==='forms_transfer';
  if((int)$rows[0]['mapped_id']!==($h['source_file_id']??0)||($j['backup_format']??1)!==2||!in_array($j['action'],['forms_restore','forms_transfer','native_history_transfer'],true)||$j['phase']!=='complete'||!in_array($j['state'],$transfer?['completed','partial']:['completed'],true)||($transfer?(int)$j['form']['id']:$j['restored_form_id'])!==$formId||($h['form_id']??null)!==$formId||($h['answer_id']??null)!==$answerId||($h['availability']??null)!=='historical_unavailable'||!is_int($h['source_file_id']??null)||$h['source_file_id']<1){throw new \RuntimeException('Restored file history is unqualified');}
  $b=$transfer?$j:$store->load($j['input']['backup_id']);
  self::assertSeal($b);
  if(($h['source_archive_id']??null)!==$b['id']){throw new \RuntimeException('Historical source archive is unqualified');}
  $source=$store->one($b['id'],'answers',$h['source_answer_id']);$sourceId=(int)($source['file_id']??$source['_mcp_file_history']['source_file_id']??0);if($sourceId!==$h['source_file_id']||!self::isUnavailable($store->one($b['id'],'files',$sourceId))){throw new \RuntimeException('Historical source reference changed');}
  $live=$db->executeQuery('SELECT a.*,q.form_id AS question_form,q.type,s.form_id AS submission_form FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE a.id=?',[$answerId])->fetch();
  if(!$live||$live['file_id']!==null||(int)$live['question_form']!==$formId||(int)$live['submission_form']!==$formId||$live['type']!=='file'){throw new \RuntimeException('Historical answer binding changed');}
  $owner=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId)->getOwnerId();
  if(!$transfer){if($owner!==$j['uid']){throw new \RuntimeException('Restored historical owner changed');}if(isset($h['owner']['native_actor'])){if($h['owner']!==self::ownerIdentity($owner,$h['owner']['native_actor'])){throw new \RuntimeException('Restored historical incarnation changed');}}else{NativeHistoryIdentity::assertLegacy($owner,$j['origin']['actor']);}}
  if($transfer){
   $expected=$source;unset($expected['_mcp_file_history']);$expected['file_id']=null;$actual=$live;unset($actual['question_form'],$actual['submission_form'],$actual['type']);
   $binding=$h['owner']??[];if(isset($binding['native_actor'])){$valid=$binding===self::ownerIdentity($owner,$binding['native_actor'])&&$binding['native_actor']===($j['target_native_actor']??null);}else{NativeHistoryIdentity::assertLegacy($owner,$binding['actor']??'');$valid=($binding['uid']??null)===$owner&&($binding['actor']??null)===($j['target_actor']??null);}
   if(!$valid||$owner!==$j['input']['owner_id']||($h['target_principal']??null)!==$j['target_principal']||$h['source_answer_id']!==$answerId||$expected!==$actual){throw new \RuntimeException('Historical ownership transition changed');}
  }
  return $h;
 }
 private static function assertSeal(array $b):void {
  if(($b['purpose']??null)===NativeHistoryTransition::PURPOSE){if($b['action']!=='native_history_transfer'||!($b['sealed']??false)||!hash_equals(NativeHistoryTransition::seal($b),$b['seal']??'')){throw new \RuntimeException('Native historical transition seal changed');}return;}
  $seal=BulkStore::digest([$b['id'],$b['actor'],$b['counts'],$b['chain'],$b['form'],['backup_format'=>2,'historical_unavailable_files'=>$b['historical_unavailable_files']??-1]]);
  if(($b['backup_format']??1)!==2||!($b['sealed']??false)||!hash_equals($seal,$b['seal']??'')){throw new \RuntimeException('Historical source archive is unqualified');}
 }
 /** Called only inside the ownership transaction, after source-row locks and exact preimage comparison. */
 public static function transferAnswer(array &$j,int $id,array $answer,?array $prior,int $old):void {
  self::assertSeal($j);$store=Server::get(BulkStore::class);$db=Server::get(IDBConnection::class);$form=(int)$j['form']['id'];
  if($prior!==null){if(self::historyForAnswer($form,$id)!==$prior){throw new \RuntimeException('Approved historical provenance changed');}$count=$db->executeStatement("UPDATE *PREFIX*forms_mcp_bulk_rows SET kind='file_history_prior' WHERE kind='file_history' AND source_id=?",[$id]);if($count!==1){throw new \RuntimeException('Historical predecessor changed');}}
  elseif($answer['file_id']===null||self::historyForAnswer($form,$id)!==null){throw new \RuntimeException('Unapproved historical normalization');}
  $sourceId=$old?:$prior['source_file_id'];$h=self::transferProvenance($j,$id,$sourceId);
  $store->putMapping($j,'file_history',$id,$h);$store->map($j['id'],'file_history',$id,$sourceId);
 }
 /** Exact prospective mapping, shared by native pre-effect budgeting and committed mapping writes. */
 public static function transferProvenance(array $j,int $id,int $sourceId):array {return ['form_id'=>(int)$j['form']['id'],'answer_id'=>$id,'availability'=>'historical_unavailable','source_file_id'=>$sourceId,'source_archive_id'=>$j['id'],'source_answer_id'=>$id,'unavailable_cause'=>'unverified','owner'=>self::ownerIdentity($j['input']['owner_id'],$j['target_native_actor']),'target_principal'=>$j['target_principal']];}
 /** Restored NULL references have history identity, not a currently resolvable node ID. */
 public static function normalized(int $formId,int $fileId):?array {
  if(!self::ready()){return null;}
  $db=Server::get(IDBConnection::class);$after=0;$count=0;$hash=hash_init('sha256');
  do{$rows=$db->executeQuery("SELECT a.*,q.type,q.".($db->getDatabaseProvider(true)==='postgres'?'"order"':'`order`')." AS question_order,q.form_id AS question_form FROM *PREFIX*forms_mcp_bulk_rows h INNER JOIN *PREFIX*forms_v2_answers a ON a.id=h.source_id INNER JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id LEFT JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE h.kind='file_history' AND h.mapped_id = ? AND a.file_id IS NULL AND s.form_id = ? AND a.id > ? ORDER BY a.id LIMIT 100",[$fileId,$formId,$after])->fetchAll();foreach($rows as$row){BulkDeadline::check();if(++$count>BulkStore::MAX_ROWS||(int)$row['question_form']!==$formId||$row['type']!=='file'||(int)$row['question_order']!==0){throw new \RuntimeException('Restored historical question changed');}$after=(int)$row['id'];$history=self::historyForAnswer($formId,$after);if($history['source_file_id']!==$fileId){throw new \RuntimeException('Historical file identity changed');}hash_update($hash,json_encode([$row,$history],JSON_THROW_ON_ERROR));}}while(count($rows)===100);
  if($count===0){return null;}
  if($db->executeQuery('SELECT a.file_id FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? AND a.file_id = ? UNION SELECT file_id FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ? AND file_id = ? UNION SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ? AND file_id = ?',[$formId,$fileId,$formId,$fileId,$formId,$fileId])->fetchOne()!==false){throw new \RuntimeException('Historical identity collides with a current form reference');}
  $owner=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($formId)->getOwnerId();return ['file_id'=>$fileId,'availability'=>'historical_unavailable','form_id'=>$formId,'owner_id'=>$owner,'answer_count'=>$count,'upload_count'=>0,'references_digest'=>hash_final($hash),'unavailable_cause'=>'unverified','reference_absent'=>true];
 }
 public static function decorateAnswer(array $row,int $formId):array {
  if($row['file_id']===null){$history=self::historyForAnswer($formId,(int)$row['id']);if($history!==null){$row['_mcp_file_history']=$history;}}return $row;
 }
 /** Private closed marker derives from the actual answer/question/submission join, never answer text. */
 public static function markResponse(array $response,int $formId):array {
  $answers=$response['answers']??[];$ids=array_column($answers,'id');$types=[];$db=Server::get(IDBConnection::class);
  foreach(array_chunk($ids,100)as$page){$rows=$db->executeQuery('SELECT a.id,a.question_id,a.submission_id,q.type,q.form_id AS question_form,s.form_id AS submission_form FROM *PREFIX*forms_v2_answers a LEFT JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id LEFT JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE a.id IN ('.implode(',',array_fill(0,count($page),'?')).')',$page)->fetchAll();foreach($rows as$r){$types[(int)$r['id']]=$r;}}
  foreach($answers as&$answer){$r=$types[(int)$answer['id']]??null;if($r===null||(int)$r['question_form']!==$formId||(int)$r['submission_form']!==$formId||(int)$r['question_id']!==$answer['questionId']||(int)$r['submission_id']!==$answer['submissionId']){throw new \RuntimeException('Response answer identity is unavailable');}if($r['type']==='file'){$answer['_mcp_file_answer']=true;}}unset($answer);$response['answers']=$answers;return $response;
 }
}
