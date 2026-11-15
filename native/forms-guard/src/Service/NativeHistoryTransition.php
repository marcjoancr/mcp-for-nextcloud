<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};use OCA\Forms\Db\Form;
/** Metadata-only native owner handoff. No MCP service, public recovery capability or Files byte effect. */
final class NativeHistoryTransition {
 public const PURPOSE='native_history_transition_v1';
 public static function seal(array $j):string{return BulkStore::digest([self::PURPOSE,$j['id'],$j['form'],$j['uid'],$j['source_native_actor'],$j['input']['owner_id'],$j['target_native_actor'],$j['target_principal'],$j['counts'],$j['chain'],$j['historical_unavailable_files']]);}
 public static function run(Form $before,Form $after,callable $work):mixed {
  if($before->getOwnerId()===$after->getOwnerId()){return $work();}
  return BulkDeadline::run(static function()use($before,$after,$work):mixed{
   $db=Server::get(IDBConnection::class);$fence=Server::get(FormsRevisionFence::class);if(!$fence->active()){throw new \LogicException('Native ownership history requires the owner transaction');}
   $ids=Server::get(BulkFiles::class)->history($before->getId());if($ids===[]){return $work();}HistoricalFiles::requireReady();
   $old=$before->getOwnerId();$target=$after->getOwnerId();if(Server::get(\OCP\IUserSession::class)->getUser()?->getUID()!==$old){throw new \RuntimeException('Native history transfer requires the authenticated owner');}
   $users=$db->executeQuery('SELECT uid FROM *PREFIX*users WHERE uid IN (?,?) ORDER BY uid FOR UPDATE',[$old,$target])->fetchAll();if(count($users)!==2){throw new \RuntimeException('Native history transfer principals changed');}
   (new FreshCore($old))->assertPrincipal(false,false);$oldActor=NativeHistoryIdentity::capture($old);$newActor=NativeHistoryIdentity::capture($target);$principal=\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($target);$form=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_forms WHERE id=? FOR UPDATE',[$before->getId()])->fetch();if(!$form||$form['owner_id']!==$old){throw new \RuntimeException('Native history source owner changed');}
   $meta=[];foreach($ids as$id){BulkDeadline::check();$meta[$id]=HistoricalFiles::unavailable($before->getId(),$id)??throw new \RuntimeException('Native historical absence changed');}
   $j=['id'=>bin2hex(random_bytes(32)),'key'=>'native-history-'.bin2hex(random_bytes(24)),'actor'=>$oldActor,'uid'=>$old,'action'=>'native_history_transfer','purpose'=>self::PURPOSE,'form'=>$form,'input'=>['owner_id'=>$target],'source_native_actor'=>$oldActor,'target_native_actor'=>$newActor,'target_principal'=>$principal,'backup_format'=>2,'historical_unavailable_files'=>count($ids),'state'=>'pending','phase'=>'apply','counts'=>[],'chain'=>'','captured'=>0,'bytes'=>0,'sealed'=>false];$store=Server::get(BulkStore::class);$store->create($j);

   // Project only rows whose unavailable identity actually crosses the owner boundary.
   // Parent rows bind those exact answers/uploads; unrelated responses never enter this archive.
   $in=implode(',',array_fill(0,count($ids),'?'));
   $affectedAnswers="SELECT a.id,a.question_id,a.submission_id FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id=? AND (a.file_id IN ($in) OR (a.file_id IS NULL AND EXISTS (SELECT 1 FROM *PREFIX*forms_mcp_bulk_rows h WHERE h.kind='file_history' AND h.source_id=a.id AND h.mapped_id IN ($in))))";
   $answerParams=[$before->getId(),...$ids,...$ids];
   $affectedUploads="SELECT u.id,u.question_id FROM *PREFIX*forms_v2_uploaded_files u WHERE u.form_id=? AND u.file_id IN ($in)";
   $uploadParams=[$before->getId(),...$ids];$projectedBytes=0;
   foreach(['form','questions','submissions','answers','uploads','artifacts']as$kind){
    [$sql,$params,$pk]=match($kind){
     'form'=>['SELECT x.* FROM *PREFIX*forms_v2_forms x WHERE x.id=?',[$before->getId()],'x.id'],
     'questions'=>["SELECT x.* FROM *PREFIX*forms_v2_questions x WHERE x.id IN (SELECT affected.question_id FROM ($affectedAnswers) affected UNION SELECT uploaded.question_id FROM ($affectedUploads) uploaded)",[...$answerParams,...$uploadParams],'x.id'],
     'submissions'=>["SELECT x.* FROM *PREFIX*forms_v2_submissions x WHERE x.id IN (SELECT affected.submission_id FROM ($affectedAnswers) affected)",$answerParams,'x.id'],
     'answers'=>["SELECT x.* FROM *PREFIX*forms_v2_answers x WHERE x.id IN (SELECT affected.id FROM ($affectedAnswers) affected)",$answerParams,'x.id'],
     'uploads'=>["SELECT x.* FROM *PREFIX*forms_v2_uploaded_files x WHERE x.id IN (SELECT uploaded.id FROM ($affectedUploads) uploaded)",$uploadParams,'x.id'],
     'artifacts'=>["SELECT x.* FROM *PREFIX*forms_mcp_artifacts x WHERE x.form_id=? AND x.file_id IN ($in)",$uploadParams,'x.file_id'],
    };
    $cursor=0;$j['counts'][$kind]=0;
    do{
     $rows=$db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 100 FOR UPDATE',[...$params,$cursor])->fetchAll();
     foreach($rows as$row){
      BulkDeadline::check();
      if($kind==='submissions'&&$j['counts'][$kind]>=100){throw new \OCA\Forms\Mcp\BeforeEffect('Native ownership history exceeds submission budget');}
      if($kind==='answers'){
       $row=HistoricalFiles::decorateAnswer($row,$before->getId());
       $sourceId=(int)($row['file_id']??$row['_mcp_file_history']['source_file_id']??0);
       self::budget($projectedBytes,HistoricalFiles::transferProvenance($j,(int)$row['id'],$sourceId));
      }
      if($kind==='uploads'){unset($row['upload_token']);}
      self::budget($projectedBytes,$row);
      if($j['captured']>=BulkStore::MAX_ROWS){throw new \OCA\Forms\Mcp\BeforeEffect('Native ownership history exceeds row budget');}
      $cursor=(int)$row[$kind==='artifacts'?'file_id':'id'];$store->put($j,$kind,$cursor,$row);$j['counts'][$kind]++;
     }
    }while(count($rows)===100);
   }
   $j['counts']['files']=count($meta);
   foreach($meta as$id=>$m){self::budget($projectedBytes,$m);if($j['captured']>=BulkStore::MAX_ROWS){throw new \OCA\Forms\Mcp\BeforeEffect('Native history row budget exceeded');}$store->put($j,'files',$id,$m);if(HistoricalFiles::unavailable($before->getId(),$id)!==$m){throw new \RuntimeException('Native history preimage changed');}}
   // Include the final private job envelope as well as all preimages and outgoing mappings.
   self::budget($projectedBytes,array_replace($j,['sealed'=>true,'seal'=>str_repeat('0',64),'state'=>'completed','phase'=>'complete','bytes'=>BulkStore::MAX_BYTES]));
   $j['sealed']=true;$j['seal']=self::seal($j);self::normalize($j,$meta);
   NativeHistoryIdentity::assertCurrent($old,$oldActor);NativeHistoryIdentity::assertCurrent($target,$newActor);(new FreshCore($old))->assertPrincipal(false,false);if(!hash_equals($principal,\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($target))){throw new \RuntimeException('Native history target changed before ownership commit');}BulkDeadline::check();$result=$work();
   if($db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id=?',[$before->getId()])->fetchOne()!==$target){throw new \RuntimeException('Native history ownership update was not verified');}$j['state']='completed';$j['phase']='complete';$store->save($j);BulkDeadline::check();return $result;
  });
 }
 private static function budget(int &$bytes,array $row):void {$bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if($bytes>1048576){throw new \OCA\Forms\Mcp\BeforeEffect('Native ownership history exceeds cumulative projection budget');}}
 private static function normalize(array &$j,array $meta):void {
  $db=Server::get(IDBConnection::class);$store=Server::get(BulkStore::class);
  foreach(['answers','uploads','artifacts']as$kind){$cursor=0;while($rows=$store->page($j['id'],$kind,$cursor)){foreach($rows as$r){BulkDeadline::check();$cursor=$r['id'];$row=$r['data'];$old=(int)($row['file_id']??0);$prior=$row['_mcp_file_history']??null;if(!isset($meta[$old])&&$prior===null){continue;}if($kind==='answers'){HistoricalFiles::transferAnswer($j,$r['id'],$row,$prior,$old);}if($old>0){$table=match($kind){'answers'=>'forms_v2_answers','uploads'=>'forms_v2_uploaded_files','artifacts'=>'forms_mcp_artifacts'};$count=$kind==='artifacts'?$db->executeStatement('DELETE FROM *PREFIX*'.$table.' WHERE form_id=? AND file_id=?',[$j['form']['id'],$old]):$db->executeStatement('UPDATE *PREFIX*'.$table.' SET file_id=NULL WHERE id=? AND file_id=?',[$r['id'],$old]);if($count!==1){throw new \RuntimeException('Exact native history normalization changed');}}}}}
 }
}
