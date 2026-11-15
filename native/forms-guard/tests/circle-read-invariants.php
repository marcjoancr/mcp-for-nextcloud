<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
foreach(['CircleReadData','CircleReadConfig','CircleExistingIdentity']as$class){require '/tmp/'.$class.'.php';}
use OCP\Server;
use OCA\Forms\Mcp\Service\{CircleReadData,CircleReadConfig,CircleExistingIdentity};
try {
    $db=Server::get(\OCP\IDBConnection::class);$db->beginTransaction();$db->executeStatement('SET TRANSACTION READ ONLY');
    $tables=['circles_circle','circles_member','circles_membership','circles_event','share','appconfig','preferences'];
    $counts=static function()use($db,$tables):array{$values=[];foreach($tables as$table){$values[$table]=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*'.$table)->fetchOne();}return$values;};
    $beforeCounts=$counts();
    $uid=$db->executeQuery("SELECT user_id FROM *PREFIX*circles_member WHERE user_id LIKE ? AND user_type=1 AND level=9 AND instance=? ORDER BY user_id LIMIT 1",['circle-owner-%',''])->fetchOne();
    if(!is_string($uid)){throw new RuntimeException('Existing synthetic Circle owner fixture is required');}
    $beforeConfig=$db->executeQuery('SELECT configkey,configvalue FROM *PREFIX*appconfig WHERE appid=? ORDER BY configkey',['circles'])->fetchAll();
    $beforeUser=$db->executeQuery('SELECT appid,configkey,configvalue FROM *PREFIX*preferences WHERE userid=? ORDER BY appid,configkey',[$uid])->fetchAll();
    $identity=CircleExistingIdentity::read($db,$uid);
    $checks=['canonical_identity_read'=>$identity['uid']===$uid&&strlen($identity['circle_id'])===31];
    try{CircleExistingIdentity::read($db,'circle-read-missing-'.bin2hex(random_bytes(8)));$checks['missing_identity_refuses_without_enrollment']=false;}catch(RuntimeException$e){$checks['missing_identity_refuses_without_enrollment']=str_contains($e->getMessage(),'identity');}
    $system=[];$config=Server::get(\OCP\IConfig::class);
    foreach([...array_map(static fn(string$key):string=>'circles.'.$key,CircleReadConfig::KEYS),'overwrite.cli.url','trusted_domains']as$key){$value=$config->getSystemValue($key,$key==='trusted_domains'?[]:'');if($value!==''&&$value!==[]){$system[$key]=$value;}}
    $snapshot=CircleReadConfig::read($db,$system);$checks['existing_configuration_read']=$snapshot['local_instance']!=='';
    $raw=$db->executeQuery('SELECT unique_id,settings FROM *PREFIX*circles_circle WHERE unique_id=?',[$identity['circle_id']])->fetch();
    $raw['settings']='{"password_single":"synthetic-legacy"}';
    try{CircleReadData::page([$raw]);$checks['legacy_projection_refuses']=false;}catch(RuntimeException$e){$checks['legacy_projection_refuses']=str_contains($e->getMessage(),'migration');}
    $checks['all_table_counts_unchanged']=$beforeCounts===$counts();
    $checks['circle_configuration_unchanged']=$beforeConfig===$db->executeQuery('SELECT configkey,configvalue FROM *PREFIX*appconfig WHERE appid=? ORDER BY configkey',['circles'])->fetchAll();
    $checks['user_preferences_unchanged']=$beforeUser===$db->executeQuery('SELECT appid,configkey,configvalue FROM *PREFIX*preferences WHERE userid=? ORDER BY appid,configkey',[$uid])->fetchAll();
    $checks['transaction_is_read_only']=$db->executeQuery('SHOW transaction_read_only')->fetchOne()==='on';
    $checks['no_circle_graph_or_mcp_authority_loaded']=!class_exists('OCA\\Forms\\Mcp\\Service\\CircleGraph',false)&&!class_exists('OCA\\Mcp\\Modules\\Forms\\Access\\FormsNativeAuthorization',false);
    $db->rollBack();echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR);exit(in_array(false,$checks,true)?1:0);
}catch(Throwable$e){if(isset($db)&&$db->inTransaction()){$db->rollBack();}echo json_encode(['ok'=>false,'error_class'=>$e::class,'at'=>basename($e->getFile()).':'.$e->getLine(),'checks'=>$checks??[]]);exit(1);}
