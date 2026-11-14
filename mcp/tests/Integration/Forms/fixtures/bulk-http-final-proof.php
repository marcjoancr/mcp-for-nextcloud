<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
try {
    $file=getenv('MCP_FORMS_BULK_HTTP_EXPECTED');
    if(!$file||!is_file($file)||filesize($file)>4096)throw new RuntimeException('An explicit expected HTTP attempt is required.');
    $expected=json_decode(file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
    foreach(['uid','source_bulk_id','restore_bulk_id','source_operation_id','restore_operation_id'] as $key)
        if(!is_string($expected[$key]??null)||$expected[$key]==='')throw new RuntimeException('The expected HTTP attempt is incomplete.');
    if(!str_starts_with($expected['uid'],'bulk-http-'))throw new RuntimeException('Expected disposable HTTP actor missing.');
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$db=Server::get(\OCP\IDBConnection::class);
    if(Server::get(\OCP\IUserSession::class)->getUser()!==null)throw new RuntimeException('Final evidence must use a fresh null-session process.');
    $store=Server::get(\OCA\Forms\Mcp\Service\BulkStore::class);
    $source=$store->load($expected['source_bulk_id']);$restore=$store->load($expected['restore_bulk_id']);
    if($source['uid']!==$expected['uid']||$restore['uid']!==$expected['uid']||$source['action']!=='forms_delete_responses'
        ||$restore['action']!=='forms_restore'||$restore['input']['backup_id']!==$source['id'])
        throw new RuntimeException('Recovery does not belong to the exact expected HTTP attempt.');
    foreach([$source,$restore] as $j)if($j['state']!=='completed'||$j['phase']!=='complete')throw new RuntimeException('The expected HTTP workflow has not completed.');
    if(!$source['sealed'])throw new RuntimeException('Original recovery archive is unsealed.');
    foreach([['source_operation_id','forms_delete_responses',$source],['restore_operation_id','forms_restore',$restore]] as [$key,$tool,$job]) {
        $row=$db->executeQuery('SELECT actor_uid,tool_name,resource_refs FROM *PREFIX*mcp_operations WHERE operation_id = ? AND actor_uid = ?',[$expected[$key],$expected['uid']])->fetch();
        if($row===false||$row['tool_name']!==$tool||(json_decode($row['resource_refs'],true,8,JSON_THROW_ON_ERROR)['bulk_id']??null)!==$job['id'])
            throw new RuntimeException('An exact initial HTTP operation receipt is missing.');
    }
    $old=(int)$source['form']['id'];$new=$restore['restored_form_id'];
    if(!is_int($new)||$new===$old)throw new RuntimeException('Restoration reused the original form.');
    foreach([[$old,0],[$new,103]] as [$form,$responses]) {
        if((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$form])->fetchOne()!==$responses
            ||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_questions WHERE form_id = ?',[$form])->fetchOne()!==11)
            throw new RuntimeException('Exact response or question count differs.');
    }
    // Golden associations come from the sealed ORIGINAL rows, independently of the restoration's mapped_id column.
    $originalGroups=[];$after=0;
    do {
        $page=$store->page($source['id'],'answers',$after);
        foreach($page as $entry) {
            $a=$entry['data'];if(preg_match('/^answer-([0-9]+)-([0-9]+)$/D',$a['text'],$m)!==1)throw new RuntimeException('Unexpected original synthetic answer.');
            $i=(int)$m[1];$q=(int)$m[2];if($i>102||$q>10)throw new RuntimeException('Original synthetic answer is out of range.');
            $question=$store->one($source['id'],'questions',(int)$a['question_id']);
            if($question['text']!=='Bulk field '.$q)throw new RuntimeException('Original question association is invalid.');
            if(isset($originalGroups[$i])&&$originalGroups[$i]!==$a['submission_id'])throw new RuntimeException('Original submission association is invalid.');
            $originalGroups[$i]=$a['submission_id'];$after=$entry['id'];
        }
    }while($store->hasAfter($source['id'],'answers',$after));
    if(count($originalGroups)!==103)throw new RuntimeException('Original source associations are incomplete.');
    $rows=$db->executeQuery('SELECT a.text,a.submission_id,q.text AS question_text,q.form_id AS question_form,s.user_id,s.timestamp FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON s.id = a.submission_id INNER JOIN *PREFIX*forms_v2_questions q ON q.id = a.question_id WHERE s.form_id = ?',[$new])->fetchAll();
    if(count($rows)!==1133)throw new RuntimeException('The complete1133 restored answers are missing.');
    $groups=[];$seen=[];
    foreach($rows as $row) {
        if(preg_match('/^answer-([0-9]+)-([0-9]+)$/D',$row['text'],$m)!==1)throw new RuntimeException('Unexpected restored synthetic value.');
        $i=(int)$m[1];$q=(int)$m[2];$sid=(int)$row['submission_id'];
        if($i>102||$q>10||isset($seen[$i.':'.$q])||(int)$row['question_form']!==$new||$row['question_text']!=='Bulk field '.$q)
            throw new RuntimeException('A restored value belongs to the wrong question or is duplicated.');
        if(isset($groups[$sid])&&$groups[$sid]['source_index']!==$i)throw new RuntimeException('A restored submission mixes original responses.');
        $originalSubmission=$store->one($source['id'],'submissions',(int)$originalGroups[$i]);
        if($row['user_id']!==$originalSubmission['user_id']||(int)$row['timestamp']!==(int)$originalSubmission['timestamp'])
            throw new RuntimeException('Restored submission metadata differs from its original response.');
        $groups[$sid]??=['source_index'=>$i,'questions'=>[]];$groups[$sid]['questions'][]=$q;$seen[$i.':'.$q]=true;
    }
    $indices=[];
    foreach($groups as $group){$questions=$group['questions'];sort($questions);if($questions!==range(0,10))throw new RuntimeException('A restored response lost or gained a question.');$indices[]=$group['source_index'];}
    sort($indices);if($indices!==range(0,102))throw new RuntimeException('Restored responses were merged or duplicated.');
    $form=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id = ?',[$new])->fetch();
    if($form['owner_id']!==$expected['uid']||(int)$form['state']!==1||(int)$form['access_enum']!==0||$form['file_id']!==null||$form['file_format']!==null
        ||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id = ?',[$new])->fetchOne()!==0)
        throw new RuntimeException('Recovery is not owned, private, closed and unlinked with zero restored shares.');
    $receipts=$db->executeQuery('SELECT tool_name,state,resource_refs FROM *PREFIX*mcp_operations WHERE actor_uid = ?',[$expected['uid']])->fetchAll();$finalSource=false;$finalRestore=false;
    foreach($receipts as $receipt) {
        $refs=json_decode($receipt['resource_refs'],true,8,JSON_THROW_ON_ERROR);
        if($receipt['tool_name']!=='forms_continue_operation'||$receipt['state']!=='succeeded')continue;
        if(($refs['bulk_id']??null)===$source['id']&&($refs['backup_id']??null)==='bulk_v1:'.$source['id'])$finalSource=true;
        if(($refs['bulk_id']??null)===$restore['id']&&($refs['restored_form_id']??null)===$new)$finalRestore=true;
    }
    if(!$finalSource||!$finalRestore)throw new RuntimeException('Matching final HTTP operation receipts are missing.');
    echo json_encode(['ok'=>true,'workflow_pairs'=>1,'checks'=>['fresh_null_session'=>true,'sealed_exact_source'=>true,'exact_expected_attempt'=>true,
        'matching_final_mcp_http_receipts'=>true,'source_responses_zero_schema_retained'=>true,
        'restored_103_responses_11_questions_1133_exact_values'=>true,'exact_question_and_submission_bindings'=>true,'private_closed_unlinked_no_shares'=>true]],JSON_THROW_ON_ERROR);
} catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(1);}
