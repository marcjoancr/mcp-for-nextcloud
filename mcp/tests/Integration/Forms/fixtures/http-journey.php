<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1'){fwrite(STDERR,"Disposable Forms HTTP boundary must be explicitly enabled.\n");exit(2);}
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';$checks=[];
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $uid='forms-http-'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(24));
    $user=Server::get(\OCP\IUserManager::class)->createUser($uid,$password);
    Server::get(\OCP\IUserSession::class)->setUser($user);
    require '/tmp/origin-fixture.php';formsNativeOrigin($uid,'forms_create',['title'=>'fixture admission']);
    $rpc=function(string $method,array $params=[])use($uid,$password):array {
        $curl=curl_init('http://127.0.0.1/index.php/apps/mcp/');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],
            CURLOPT_USERPWD=>$uid.':'.$password,CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $data=json_decode((string)$body,true);
        if($status!==200||!is_array($data)||isset($data['error']))throw new RuntimeException('HTTP '.$status.' '.substr((string)$body,0,1600));
        return $data['result'];
    };
    $call=function(string $name,array $arguments=[])use($rpc):array {
        $r=$rpc('tools/call',['name'=>$name,'arguments'=>$arguments===[]?(object)[]:$arguments]);
        if($r['isError']??false)throw new RuntimeException($name.': '.json_encode($r));
        return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    };
    $write=function(string $name,array $arguments)use($call):array {
        $p=$call($name,$arguments);
        if(!isset($p['plan_state'],$p['operation_id']))throw new RuntimeException('Missing approval envelope: '.json_encode($p));
        $r=$call($name,$arguments+['confirm'=>true,'confirm_shared'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);
        if(!in_array($r['operation']['state']??null,['succeeded','partial','pending'],true))throw new RuntimeException('Unconfirmed outcome: '.json_encode($r));
        return $r;
    };
    $stage='discovery';$list=$rpc('tools/list');$checks['published_forms']=count(array_filter($list['tools'],fn($t)=>str_starts_with($t['name'],'forms_')));
    $caps=$call('forms_capabilities');if(!$caps['guardedWrites'])throw new RuntimeException('Guard unavailable '.json_encode($caps));
    $stage='create';$created=$write('forms_create',['title'=>'HTTP valid ñ','description'=>'Native MCP lifecycle']);$id=$created['operation']['references']['form_id'];
    $checks['form_id']=$id;
    $stage='settings';$settings=$call('forms_read_settings',['id'=>$id]);if($settings['title']!=='HTTP valid ñ')throw new RuntimeException('Created title did not persist');
    $stage='question';$q=$write('forms_create_question',['id'=>$id,'type'=>'short','text'=>'HTTP question']);$qid=$q['operation']['references']['question_id'];
    $stage='all question creates';$types=[];$fileQuestion=null;$choiceQuestion=null;$questionIds=[];
    foreach(['color','date','dropdown','file','grid','linearscale','long','multiple','multiple_unique','ranking','time'] as $type){
        $r=$write('forms_create_question',['id'=>$id,'type'=>$type,'text'=>'HTTP '.$type]+($type==='grid'?['subtype'=>'number']:[]));
        $questionIds[$type]=$r['operation']['references']['question_id'];
        $types[]=$type;if($type==='file')$fileQuestion=$r['operation']['references']['question_id'];if($type==='dropdown')$choiceQuestion=$r['operation']['references']['question_id'];
    }
    $checks['supported_creates']=count($types)+1;
    $stage='invalid typed answer preflight';
    $invalid=$rpc('tools/call',['name'=>'forms_submit_response','arguments'=>['id'=>$id,'answers'=>[['question_id'=>$choiceQuestion,'values'=>['invalid representation']]]]]);
    if(!($invalid['isError']??false)||isset($invalid['structuredContent']['operation_id']))throw new RuntimeException('Invalid typed answer obtained an approval reservation');
    $checks['invalid_answer_has_no_reservation']=true;
    if(!in_array('datetime',$caps['questionTypes'])||in_array('datetime',$caps['creatableQuestionTypes']))throw new RuntimeException('Legacy datetime classification mismatch');
    $write('forms_update',['id'=>$id,'fields'=>['submitMultiple'=>true]]);
    $stage='submit';$submission=$write('forms_submit_response',['id'=>$id,'answers'=>[['question_id'=>$qid,'values'=>['HTTP synthetic answer']]]]);$sid=$submission['operation']['references']['submission_id'];
    $stage='response';$response=$call('forms_read_response',['id'=>$id,'submission_id'=>$sid]);if(($response['answers'][0]['text']??null)!=='HTTP synthetic answer')throw new RuntimeException('Answer mismatch');
    $stage='complete answer matrix';
    $answers=[['question_id'=>$qid,'values'=>['á zero 0']]];
    $expected=[$qid=>['á zero 0']];
    $questionIds=['short'=>$qid]+$questionIds;
    foreach(['radio','checkbox'] as $subtype) {
        $extra=$write('forms_create_question',['id'=>$id,'type'=>'grid','subtype'=>$subtype,'text'=>'HTTP grid '.$subtype]);
        $questionIds['grid_'.$subtype]=$extra['operation']['references']['question_id'];
    }
    // A legacy import fixture is necessary: native creation rejects datetime.
    $legacy=Server::get(\OCA\Forms\Controller\ApiController::class)->newQuestion($id,'short',null,'Legacy datetime')->getData()['id'];
    $mapper=Server::get(\OCA\Forms\Db\QuestionMapper::class);$entity=$mapper->findById($legacy);$entity->setType('datetime');$mapper->update($entity);
    $questionIds['datetime']=$legacy;
    foreach($questionIds as $type=>$question) {
        if(in_array($type,['short','file'],true))continue;
        if(str_starts_with($type,'grid')) {
            $subtype=$type==='grid'?'number':substr($type,5);
            $write('forms_add_options',['id'=>$id,'question_id'=>$question,'texts'=>['R1','R2'],'option_type'=>'row']);
            $write('forms_add_options',['id'=>$id,'question_id'=>$question,'texts'=>['C1','C2'],'option_type'=>'column']);
            $read=$call('forms_read_question',['id'=>$id,'question_id'=>$question]);
            $rows=array_values(array_filter($read['options'],fn($o)=>$o['optionType']==='row'));
            $columns=array_values(array_filter($read['options'],fn($o)=>$o['optionType']==='column'));
            $r1=$rows[0]['id'];$r2=$rows[1]['id'];$c1=$columns[0]['id'];$c2=$columns[1]['id'];
            $entry=['question_id'=>$question,'rows'=>match($subtype) {
                'number'=>[['row_id'=>$r1,'cells'=>[['column_id'=>$c1,'value'=>'0'],['column_id'=>$c2,'value'=>'3.50']]],['row_id'=>$r2,'cells'=>[['column_id'=>$c1,'value'=>''],['column_id'=>$c2,'value'=>'1e3']]]],
                'radio'=>[['row_id'=>$r1,'column_ids'=>[$c2]]],
                'checkbox'=>[['row_id'=>$r1,'column_ids'=>[$c1,$c2]],['row_id'=>$r2,'column_ids'=>[]]],
            }];
            $expected[$question]=[match($subtype) {
                'number'=>[$r1=>[$c1=>'0',$c2=>'3.50'],$r2=>[$c1=>'',$c2=>'1e3']],
                'radio'=>[$r1=>(string)$c2],
                'checkbox'=>[$r1=>[(string)$c1,(string)$c2],$r2=>[]],
            }];
        } elseif(in_array($type,['dropdown','multiple','multiple_unique','ranking'],true)) {
            $write('forms_add_options',['id'=>$id,'question_id'=>$question,'texts'=>['A','B','C']]);
            $read=$call('forms_read_question',['id'=>$id,'question_id'=>$question]);$choices=array_column($read['options'],'id');
            $selected=match($type){'multiple'=>[$choices[0],$choices[2]],'ranking'=>array_reverse($choices),default=>[$choices[1]]};
            $entry=['question_id'=>$question,'option_ids'=>$selected];
            $expected[$question]=$type==='ranking'?[array_map('strval',$selected)]:($type==='multiple'?['A','C']:['B']);
        } else {
            $settings=match($type){'date'=>['dateRange'=>true,'dateMin'=>'2026-11-01','dateMax'=>'2026-11-30'],'time'=>['timeRange'=>true,'timeMin'=>'09:00','timeMax'=>'17:00'],'linearscale'=>['optionsLowest'=>0,'optionsHighest'=>10],default=>[]};
            if($settings!==[])$write('forms_update_question',['id'=>$id,'question_id'=>$question,'fields'=>['extraSettings'=>$settings]]);
            $values=match($type){'color'=>['#Aa00fF'],'date'=>['2026-11-13','2026-11-14'],'time'=>['09:30','16:45'],'datetime'=>['2026-11-13 09:30'],'long'=>["First line\nSecond line"],'linearscale'=>['0']};
            $entry=['question_id'=>$question,'values'=>$values];$expected[$question]=$values;
        }
        $answers[]=$entry;
    }
    $db=Server::get(\OCP\IDBConnection::class);
    $unchangedState=static function()use($db,$id):array {
        $counts=[];
        foreach(['mcp_operations','forms_v2_submissions','forms_v2_answers','forms_mcp_operations','forms_mcp_effects'] as $table)$counts[$table]=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*'.$table)->fetchOne();
        $counts['uploads']=$db->executeQuery('SELECT handle_hash,consumed FROM *PREFIX*forms_mcp_uploads WHERE form_id = ? ORDER BY handle_hash',[$id])->fetchAll();
        $counts['revision']=Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class)->revision();
        return $counts;
    };
    $before=$unchangedState();$rejected=0;
    foreach([
        ['question_id'=>$questionIds['date'],'values'=>['2026-02-30','2026-11-14']],
        ['question_id'=>$questionIds['time'],'values'=>['24:00','16:45']],
        ['question_id'=>$questionIds['datetime'],'values'=>['2026-11-13T09:30']],
        ['question_id'=>$questionIds['ranking'],'option_ids'=>[]],
        ['question_id'=>$questionIds['color'],'values'=>['red']],
        ['question_id'=>$qid,'values'=>[]],
    ] as $invalidEntry) {
        $r=$rpc('tools/call',['name'=>'forms_submit_response','arguments'=>['id'=>$id,'answers'=>[$invalidEntry]]]);
        if(!($r['isError']??false)||isset($r['structuredContent']['operation_id']))throw new RuntimeException('Native-invalid answer received approval');
        $rejected++;
    }
    if($unchangedState()!==$before)throw new RuntimeException('Rejected answer preview changed journal, native rows or uploads');
    $checks['semantic_preflight_rejections']=$rejected;
    $stage='attachment upload';Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->newFile('forms-source.txt','fixture-bytes');
    $uploaded=$write('forms_upload_response_files',['id'=>$id,'question_id'=>$fileQuestion,'paths'=>['/forms-source.txt']]);
    $handle=$uploaded['data']['files'][0]['handle']??throw new RuntimeException('Opaque upload handle missing');
    $attached=$write('forms_submit_response',['id'=>$id,'answers'=>[...$answers,['question_id'=>$fileQuestion,'file_handles'=>[$handle]]]]);
    $attachedId=$attached['operation']['references']['submission_id'];
    $stage='verify all native answer types';
    $matrixResponse=$call('forms_read_response',['id'=>$id,'submission_id'=>$attachedId,'limit'=>100]);
    $actual=[];
    foreach($matrixResponse['answers'] as $answer)$actual[$answer['questionId']][]=$answer['text'];
    foreach($expected as $question=>$values) {
        $type=array_search($question,$questionIds,true);
        $got=$actual[$question]??[];
        if($type==='ranking'||str_starts_with((string)$type,'grid'))$got=array_map(fn($value)=>json_decode($value,true,64,JSON_THROW_ON_ERROR),$got);
        if($got!==$values)throw new RuntimeException('Persisted answer mismatch for '.$type.': '.json_encode($got));
    }
    if(!$matrixResponse['fileAnswersOmitted'])throw new RuntimeException('File answer projection was not explicit');
    $checks['answer_families_verified']=13;$checks['answer_questions_verified']=15;
    $stage='own response replacement';
    $own=$write('forms_update_own_response',['id'=>$id,'submission_id'=>$sid,'answers'=>[['question_id'=>$qid,'values'=>['Own replacement']]]]);
    $ownResponse=$call('forms_read_response',['id'=>$id,'submission_id'=>$sid]);
    if(($ownResponse['answers'][0]['text']??null)!=='Own replacement')throw new RuntimeException('Own response update failed');
    $checks['own_response_verified']=true;
    $stage='attachment listing';$files=$call('forms_list_response_files',['id'=>$id,'submission_id'=>$attachedId]);$fileId=$files['files'][0]['file_id']??throw new RuntimeException('Visible file reference missing');
    $stage='attachment save';$saved=$write('forms_save_response_file',['id'=>$id,'submission_id'=>$attachedId,'file_id'=>$fileId,'path'=>'/forms-saved.txt']);
    if(Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get('forms-saved.txt')->getContent()!=='fixture-bytes')throw new RuntimeException('Saved attachment bytes mismatch');
    $checks['web_attachment_save_verified']=true;
    $stage='Files export';
    $exportFile=function(array $args)use($write):array {
        $job=$write('forms_export_results',$args);$target=$job['operation']['operation_id'];
        $collected=$write('forms_continue_operation',['target_operation_id'=>$target]);
        if(!($collected['data']['complete']??false))throw new RuntimeException('Small Files export did not finish collection.');
        $delivered=$write('forms_continue_operation',['target_operation_id'=>$target]);
        if(($delivered['data']['state']??null)!=='delivered')throw new RuntimeException('Files export was not verified.');
        return $delivered;
    };
    $exportFile(['id'=>$id,'format'=>'csv','path'=>'/forms-results.csv','include_files'=>true]);
    $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
    if(!str_starts_with($root->get('forms-results.csv')->getContent(),"\xEF\xBB\xBF"))throw new RuntimeException('Files CSV export is missing.');
    $exportFile(['id'=>$id,'format'=>'ods','path'=>'/forms-results.ods']);
    $destination=$root->get('forms-results.ods');$originalId=(int)$destination->getId();$etag=(string)$destination->getEtag();
    $exportFile(['id'=>$id,'format'=>'ods','path'=>'/forms-results.ods','overwrite'=>true,'etag'=>$etag]);
    if((int)$root->get('forms-results.ods')->getId()!==$originalId||!str_starts_with($root->get('forms-results.ods')->getContent(),'PK'))throw new RuntimeException('Verified overwrite changed destination identity or lost bytes.');
    $checks['web_files_export_and_overwrite_verified']=true;
    $stage='export';$job=$write('forms_download_results',['id'=>$id,'format'=>'xlsx']);$target=$job['operation']['operation_id'];
    $step=$write('forms_continue_operation',['target_operation_id'=>$target]);
    if(!($step['data']['complete']??false))throw new RuntimeException('Single-page export did not complete');
    $checks['download_job_complete']=true;
    $stage='download';$link=$write('forms_continue_operation',['target_operation_id'=>$target]);
    if(!isset($link['data']['download_url']))throw new RuntimeException('Download capability missing '.json_encode($link));
    $curl=curl_init($link['data']['download_url']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);$bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($status!==200||!str_starts_with((string)$bytes,"PK"))throw new RuntimeException('Download refused '.$status);
    $checks['xlsx_download_bytes']=strlen($bytes);
    $curl=curl_init($link['data']['download_url']);curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);curl_exec($curl);$replayStatus=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($replayStatus!==404)throw new RuntimeException('Consumed download was reused');$checks['download_single_use']=true;
    $revoked=$write('forms_continue_operation',['target_operation_id'=>$target]);Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant($uid,'forms','download_results',false);
    $curl=curl_init($revoked['data']['download_url']);curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);curl_exec($curl);$revokedStatus=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($revokedStatus!==404)throw new RuntimeException('Revoked authority downloaded results');$checks['download_revocation_enforced']=true;
    echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {echo json_encode(['ok'=>false,'stage'=>$stage,'checks'=>$checks,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
