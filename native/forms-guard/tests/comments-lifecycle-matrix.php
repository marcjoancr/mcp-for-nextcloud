<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if (getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1') {throw new RuntimeException('Disposable native cell required');}
function command(array $args,string $input='',bool $success=true): array {
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if ($success && $exit!==0) {throw new RuntimeException($error.$out);}
    return ['exit'=>$exit,'out'=>$out,'error'=>$error];
}
function install(string $action,bool $success=true): array {return command([PHP_BINARY,'/tmp/forms-comments-extension/install.php','--root=/var/www/html','--action='.$action],'',$success);}
function state(string $input=''): array {return json_decode(command([PHP_BINARY,'/tmp/comments-lifecycle-probe.php'],$input)['out'],true,64,JSON_THROW_ON_ERROR);}
function selector(?string $value): void {
    command($value===null?[PHP_BINARY,'/var/www/html/occ','config:system:delete','comments.managerFactory']:[PHP_BINARY,'/var/www/html/occ','config:system:set','comments.managerFactory','--value='.$value]);
}
$owned='McpFormsServerExtension\\CommentsFactory';$default='OC\\Comments\\ManagerFactory';$guarded='OCA\\Forms\\Mcp\\Comments\\GuardedCommentsManager';
$dir='/var/www/html/config';$fixture=$dir.'/comments-test-lifecycle.config.php';$backup='/tmp/forms-comments-keep-data-'.bin2hex(random_bytes(6));
$checks=[];
try {
    command([PHP_BINARY,'-r','define("OC_CONSOLE",true);require "/var/www/html/lib/base.php";foreach(["forms","mcp"] as $app){\\OCP\\Server::get(\\OCP\\IAppConfig::class)->updateType($app,"enabled",\\OCP\\IAppConfig::VALUE_MIXED);}']);
    install('validate');
    $before=file_get_contents($dir.'/config.php');
    $dry=command([PHP_BINARY,'/tmp/forms-comments-extension/install.php','--root=/var/www/html','--action=install','--dry-run']);
    $checks['dry_run_unchanged']=$before===file_get_contents($dir.'/config.php');
    $early=state('{"mode":"early"}');$next=state();
    $checks['early_stock_and_shared_instance']=$early['manager']==='OC\\Comments\\Manager' && $early['same_instance'] && !$early['comments'] && $early['ordinary_crud'];
    $checks['gap_withheld_after_reactivation']=$next['manager']===$guarded && !$next['comments'] && $next['qualified'];
    install('validate');$checks['explicit_reactivation']=state()['comments'];
    file_put_contents($fixture,'<?php class CommentsLifecycleCustomFactory extends \\OC\\Comments\\ManagerFactory {}');
    selector('CommentsLifecycleCustomFactory');
    $before=file_get_contents($dir.'/config.php');$custom=install('install',false);$rollback=install('rollback');
    $checks['custom_factory_preserved']=$custom['exit']!==0 && $before===file_get_contents($dir.'/config.php') && state()['ordinary_crud'];
    selector($owned);unlink($fixture);install('validate');
    file_put_contents($fixture,'<?php $CONFIG = ["config_is_read_only"=>true];');
    $readonly=install('validate',false);$readstate=state();
    $checks['readonly_publication_closed']=$readonly['exit']!==0 && !$readstate['comments'] && $readstate['ordinary_crud'];
    unlink($fixture);install('validate');
    file_put_contents($fixture,'<?php $CONFIG = ["comments.managerFactory"=>"OC\\\\Comments\\\\ManagerFactory"];');
    $competing=install('validate',false);$competeState=state();
    $checks['competing_fragment_detected']=$competing['exit']!==0 && $competeState['manager']==='OC\\Comments\\Manager' && !$competeState['comments'] && $competeState['ordinary_crud'];
    unlink($fixture);install('validate');
    command([PHP_BINARY,'/var/www/html/occ','app:disable','mcp']);
    $native=json_decode(command([PHP_BINARY,'/tmp/comments-probe.php'])['out'],true,64,JSON_THROW_ON_ERROR);
    $checks['mcp_disabled_native_comments']=$native['ok'];
    command([PHP_BINARY,'/var/www/html/occ','app:enable','mcp']);
    command([PHP_BINARY,'/var/www/html/occ','app:disable','forms']);$disabled=state();
    $checks['forms_disabled_stock']=$disabled['manager']==='OC\\Comments\\Manager' && $disabled['ordinary_crud'] && !$disabled['comments'];
    command([PHP_BINARY,'/var/www/html/occ','app:enable','forms']);$checks['reenable_requires_validation']=!state()['comments'];install('validate');
    command(['cp','-a','/var/www/html/custom_apps/forms',$backup]);
    command([PHP_BINARY,'/var/www/html/occ','app:remove','--keep-data','forms']);$missing=state();
    $checks['keep_data_removal_stock']=$missing['manager']==='OC\\Comments\\Manager' && $missing['ordinary_crud'] && !$missing['comments'];
    command(['mv',$backup,'/var/www/html/custom_apps/forms']);
    command([PHP_BINARY,'/var/www/html/occ','app:enable','forms']);
    $checks['restored_app_requires_validation']=!state()['comments'];install('validate');
    install('rollback');$restored=state();
    $checks['rollback_stock']=$restored['manager']==='OC\\Comments\\Manager' && $restored['ordinary_crud'] && !$restored['comments'];
    install('install');install('rollback');selector($default);install('install');install('rollback');
    $explicit=json_decode(install('status')['out'],true,64,JSON_THROW_ON_ERROR);
    $checks['explicit_default_restored']=$explicit['selector']===$default;
    install('install');
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['ok'=>false,'checks'=>$checks,'error'=>$error->getMessage()]);exit(1);}
finally {
    if (is_file($fixture)) {unlink($fixture);}
    if (is_dir($backup) && !is_dir('/var/www/html/custom_apps/forms')) {command(['mv',$backup,'/var/www/html/custom_apps/forms']);}
    command([PHP_BINARY,'/var/www/html/occ','app:enable','forms'],'',false);
    command([PHP_BINARY,'/var/www/html/occ','app:enable','mcp'],'',false);
    selector($owned);install('validate',false);
}
