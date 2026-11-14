<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$directory=null;
try {
    $driver=getenv('MCP_FORMS_BULK_HTTP_PROBE');if(!$driver)throw new RuntimeException('The exact HTTP driver path is required.');
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');$db=Server::get(\OCP\IDBConnection::class);
    $counts=static fn():array=>[
        (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*users')->fetchOne(),
        (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_forms')->fetchOne(),
        (int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk')->fetchOne()];
    $before=$counts();$directory=sys_get_temp_dir().'/forms-http-evidence-'.bin2hex(random_bytes(10));
    if(!mkdir($directory,0700))throw new RuntimeException('Evidence fixture folder could not be created.');
    $run=static function(string $file)use($driver):bool {
        $env=array_replace(getenv(),['MCP_FORMS_BULK_HTTP_EXPECTED'=>$file,'MCP_FORMS_BULK_HTTP_EVIDENCE_ONLY'=>'1']);
        $p=proc_open([PHP_BINARY,$driver],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($p))throw new RuntimeException('The exact evidence writer could not start.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        $r=json_decode($out,true,64,JSON_THROW_ON_ERROR);return $exit===0&&($r['ok']??false);
    };
    $checks=[];$old=$directory.'/old-attempt.json';$sentinel='{"previous_attempt":true}';
    if(file_put_contents($old,$sentinel)!==strlen($sentinel)||!chmod($old,0400))throw new RuntimeException('Read-only old fixture could not be created.');
    $checks['old_evidence_cannot_be_reused']=!$run($old)&&file_get_contents($old)===$sentinel;
    $checks['failed_create_stops_before_effects']=!$run($directory.'/missing-parent/attempt.json');
    $fresh=$directory.'/new-attempt.json';$checks['fresh_evidence_writes']=$run($fresh);
    $data=json_decode(file_get_contents($fresh),true,8,JSON_THROW_ON_ERROR);
    $checks['exact_current_update']=str_starts_with($data['uid']??'','bulk-http-')
        &&($data['source_bulk_id']??null)===str_repeat('a',64)&&($data['restore_bulk_id']??null)===str_repeat('b',64)
        &&($data['source_operation_id']??null)==='source-operation'&&($data['restore_operation_id']??null)==='restore-operation';
    $checks['no_native_effects']=$before===$counts();
    $ok=!in_array(false,$checks,true);echo json_encode(['ok'=>$ok,'checks'=>$checks],JSON_THROW_ON_ERROR);$code=$ok?0:1;
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e::class,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);$code=1;}
finally {if($directory!==null){foreach(['old-attempt.json','new-attempt.json']as$file)if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);if(is_dir($directory))rmdir($directory);}}
exit($code);
