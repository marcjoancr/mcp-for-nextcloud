<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='fixture';$changedQuestion=false;$changedSubmissions=false;$badFile=null;
try {
    $expectedFile=getenv('MCP_FORMS_BULK_HTTP_EXPECTED');$oracle=getenv('MCP_FORMS_BULK_HTTP_FINAL_PROBE');
    if(!$expectedFile||!$oracle)throw new RuntimeException('Explicit attempt and oracle paths are required.');
    $expected=json_decode(file_get_contents($expectedFile),true,16,JSON_THROW_ON_ERROR);
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$db=Server::get(\OCP\IDBConnection::class);
    $restore=Server::get(\OCA\Forms\Mcp\Service\BulkStore::class)->load($expected['restore_bulk_id']);$form=$restore['restored_form_id'];
    if($restore['uid']!==$expected['uid']||$restore['state']!=='completed')throw new RuntimeException('Expected exact completed actor restoration.');
    $run=static function(string $file)use($oracle):array {
        $env=array_replace(getenv(),['MCP_FORMS_BULK_HTTP_EXPECTED'=>$file]);
        $p=proc_open([PHP_BINARY,$oracle],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($p))throw new RuntimeException('Oracle could not start.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        $r=json_decode($out,true,64,JSON_THROW_ON_ERROR);return ['accepted'=>$exit===0&&($r['ok']??false),'code'=>$exit];
    };
    $checks=[];$stage='uncorrupted baseline';$checks['baseline']=$run($expectedFile)['accepted'];
    $badFile=$expectedFile.'.wrong-'.bin2hex(random_bytes(6));$bad=array_replace($expected,['uid'=>'bulk-http-different-attempt']);
    file_put_contents($badFile,json_encode($bad,JSON_THROW_ON_ERROR));chmod($badFile,0600);
    $stage='wrong attempt must not reuse old evidence';$checks['wrong_attempt_denied']=!$run($badFile)['accepted'];
    $rows=$db->executeQuery('SELECT a.id,a.question_id,a.submission_id,a.text FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON s.id = a.submission_id WHERE s.form_id = ? AND a.text IN (?,?)',[$form,'answer-0-0','answer-1-0'])->fetchAll();
    if(count($rows)!==2)throw new RuntimeException('Exact two synthetic test answers are missing.');
    usort($rows,static fn(array $a,array $b):int=>strcmp($a['text'],$b['text']));[$one,$two]=$rows;
    if($one['question_id']!==$two['question_id']||$one['submission_id']===$two['submission_id'])throw new RuntimeException('Unexpected initial answer bindings.');
    $otherQuestion=$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_questions WHERE form_id = ? AND text = ?',[$form,'Bulk field 1'])->fetchOne();
    if($otherQuestion===false)throw new RuntimeException('Exact alternate synthetic question missing.');
    $stage='question association mutation';$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET question_id = ? WHERE id = ? AND question_id = ? AND submission_id = ?',[$otherQuestion,$one['id'],$one['question_id'],$one['submission_id']]);$changedQuestion=true;
    $checks['wrong_question_denied']=!$run($expectedFile)['accepted'];
    $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET question_id = ? WHERE id = ? AND question_id = ?',[$one['question_id'],$one['id'],$otherQuestion]);$changedQuestion=false;
    $stage='submission association mutation';
    $db->beginTransaction();try {
        $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ? AND submission_id = ?',[$two['submission_id'],$one['id'],$one['submission_id']]);
        $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ? AND submission_id = ?',[$one['submission_id'],$two['id'],$two['submission_id']]);
        $db->commit();$changedSubmissions=true;
    }catch(Throwable $e){$db->rollBack();throw $e;}
    $checks['mixed_submissions_denied']=!$run($expectedFile)['accepted'];
    $db->beginTransaction();try {
        $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ? AND submission_id = ?',[$one['submission_id'],$one['id'],$two['submission_id']]);
        $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ? AND submission_id = ?',[$two['submission_id'],$two['id'],$one['submission_id']]);
        $db->commit();$changedSubmissions=false;
    }catch(Throwable $e){$db->rollBack();throw $e;}
    $stage='restored exact baseline';$checks['restored_baseline']=$run($expectedFile)['accepted'];
    $ok=!in_array(false,$checks,true);echo json_encode(['ok'=>$ok,'checks'=>$checks],JSON_THROW_ON_ERROR);$exit=$ok?0:1;
} catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);$exit=1;}
finally {
    if($changedQuestion)$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET question_id = ? WHERE id = ?',[$one['question_id'],$one['id']]);
    if($changedSubmissions){$db->beginTransaction();try{$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ?',[$one['submission_id'],$one['id']]);$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET submission_id = ? WHERE id = ?',[$two['submission_id'],$two['id']]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
    if($badFile!==null&&is_file($badFile))unlink($badFile);
}
exit($exit);
