<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Verify the exact sealed source and complete restored relationships, independently of a successful HTTP return. */
function formsBulkRecoveryOracle(array $expected):bool {
 try{
  $db=\OCP\Server::get(\OCP\IDBConnection::class);$store=\OCP\Server::get(\OCA\Forms\Mcp\Service\BulkStore::class);$source=$store->load($expected['source_native_id']);$restore=$store->load($expected['restore_native_id']);
  if($source['uid']!==$expected['uid']||$restore['uid']!==$expected['uid']||!$source['sealed']||$source['action']!=='forms_delete_form'||$source['state']!=='uncertain'||$restore['action']!=='forms_restore'||$restore['state']!=='completed'||$restore['phase']!=='complete'||$restore['input']['backup_id']!==$source['id']||(int)$source['form']['id']!==$expected['source_form_id']||$restore['restored_form_id']!==$expected['restored_form_id']||count($expected['files'])!==2)return false;
  if(!hash_equals($source['seal'],\OCA\Forms\Mcp\Service\BulkStore::digest([$source['id'],$source['actor'],$source['counts'],$source['chain'],$source['form']])))return false;
  $form=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id = ?',[$expected['restored_form_id']])->fetch();
  if(!$form||$form['owner_id']!==$expected['uid']||(int)$form['state']!==1||(int)$form['access_enum']!==0||$form['file_id']!==null||$form['file_format']!==null||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id = ?',[$expected['restored_form_id']])->fetchOne()!==0)return false;
  $questions=$db->executeQuery('SELECT id,form_id,text,type FROM *PREFIX*forms_v2_questions WHERE form_id = ?',[$expected['restored_form_id']])->fetchAll();
  $goldQuestion=$store->one($source['id'],'questions',$expected['source_question_id']);$mappedQuestion=$store->mapped($restore['id'],'questions',$expected['source_question_id']);
  if(count($questions)!==1||(int)$questions[0]['id']!==$mappedQuestion||$mappedQuestion===$expected['source_question_id']||$questions[0]['text']!==$goldQuestion['text']||$questions[0]['type']!==$goldQuestion['type'])return false;
  $goldAnswers=$store->page($source['id'],'answers',0);if(count($goldAnswers)!==2)return false;$goldByName=[];
  foreach($goldAnswers as $row){$answer=$row['data'];$name=$answer['text'];if(isset($goldByName[$name])||!isset($expected['files'][$name]))return false;$file=$expected['files'][$name];
   if((int)$answer['question_id']!==$expected['source_question_id']||(int)$answer['file_id']!==$file['old_file_id']||(int)$answer['submission_id']!==$file['submission_id']||$store->one($source['id'],'files',$file['old_file_id'])['sha256']!==$file['sha256'])return false;$goldByName[$name]=$answer;
  }
  $rows=$db->executeQuery('SELECT a.file_id,a.text,a.question_id,a.submission_id,s.user_id,s.timestamp,q.form_id AS question_form_id FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id LEFT JOIN *PREFIX*forms_v2_questions q ON a.question_id = q.id WHERE s.form_id = ? ORDER BY a.text',[$expected['restored_form_id']])->fetchAll();
  if(count($rows)!==2||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$expected['restored_form_id']])->fetchOne()!==2)return false;
  $names=array_column($rows,'text');$wanted=array_keys($expected['files']);sort($names);sort($wanted);if($names!==$wanted||count(array_unique(array_column($rows,'file_id')))!==2||count(array_unique(array_column($rows,'submission_id')))!==2)return false;
  $home=\OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($expected['uid']);
  foreach($rows as $row){$name=$row['text'];$file=$expected['files'][$name];$goldSubmission=$store->one($source['id'],'submissions',$file['submission_id']);
   if((int)$row['question_form_id']!==$expected['restored_form_id']||(int)$row['question_id']!==$mappedQuestion||(int)$row['submission_id']!==$store->mapped($restore['id'],'submissions',$file['submission_id'])||$row['user_id']!==$goldSubmission['user_id']||(int)$row['timestamp']!==(int)$goldSubmission['timestamp']||(int)$row['file_id']!==$store->mapped($restore['id'],'files',$file['old_file_id'])||in_array((int)$row['file_id'],array_column($expected['files'],'old_file_id'),true))return false;
   $nodes=$home->getById((int)$row['file_id']);$copy=$nodes[0]??null;if(!$copy instanceof \OCP\Files\File||hash('sha256',$copy->getContent())!==$file['sha256'])return false;
  }return true;
 }catch(Throwable){return false;}
}
/** Change only one answer in the exact synthetic restored attempt, restoring it after each observation. */
function formsBulkRecoveryMutationProof(array $expected):array {
 $db=\OCP\Server::get(\OCP\IDBConnection::class);$store=\OCP\Server::get(\OCA\Forms\Mcp\Service\BulkStore::class);$source=$store->load($expected['source_native_id']);$restore=$store->load($expected['restore_native_id']);
 if(preg_match('/^recovery-http-[a-f0-9]{10}-owner$/D',$expected['uid'])!==1||$source['uid']!==$expected['uid']||$restore['uid']!==$expected['uid']||$source['action']!=='forms_delete_form'||!$source['sealed']||$restore['action']!=='forms_restore'||$restore['state']!=='completed'||$restore['phase']!=='complete'||$restore['input']['backup_id']!==$source['id']||(int)$source['form']['id']!==$expected['source_form_id']||$restore['restored_form_id']!==$expected['restored_form_id'])throw new RuntimeException('Exact synthetic recovery attempt is required.');
 $rows=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id WHERE s.form_id = ? ORDER BY a.text',[$expected['restored_form_id']])->fetchAll();
 if(count($rows)!==2)throw new RuntimeException('Expected exactly two synthetic restored answers.');$first=$rows[0];$second=$rows[1];$names=array_column($rows,'text');$wanted=array_keys($expected['files']);sort($names);sort($wanted);if($names!==$wanted)throw new RuntimeException('Synthetic answer targets differ from fixed expectations.');
 $checks=['baseline'=>formsBulkRecoveryOracle($expected)];if(!$checks['baseline'])return $checks;
 $write=static function(array $row)use($db,$second):void {$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET file_id = ?,text = ?,question_id = ?,submission_id = ? WHERE id = ?',[$row['file_id'],$row['text'],$row['question_id'],$row['submission_id'],$second['id']]);};
 foreach(['duplicate_first'=>array_replace($second,['file_id'=>$first['file_id'],'text'=>$first['text']]),'foreign_question'=>array_replace($second,['question_id'=>$expected['source_question_id']]),'wrong_submission'=>array_replace($second,['submission_id'=>$first['submission_id']])] as $case=>$mutation){try{$write($mutation);$checks[$case.'_rejected']=!formsBulkRecoveryOracle($expected);}finally{$write($second);}}
 $checks['restored_baseline']=formsBulkRecoveryOracle($expected);return $checks;
}
