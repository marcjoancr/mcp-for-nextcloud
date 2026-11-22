<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace Psr\Container {interface ContainerInterface {public function get(string $id);public function has(string $id):bool;}}
namespace OCP\Comments {interface ICommentsManagerFactory {public function getManager();}}
namespace OC\Comments {final class ManagerFactory {public static int $created=0;public function __construct(mixed $container){}public function getManager():object{self::$created++;return new \stdClass();}}}
namespace OCP {final class Server {public static object $config;public static function get(string $class):object{return self::$config;}}}
namespace {
final class OC {public static string $configDir;}
$root=sys_get_temp_dir().'/forms-comments-approval-'.bin2hex(random_bytes(8));$app=$root.'/forms';$dir=$root.'/config';$data=$root.'/data';mkdir($app.'/lib/Mcp/Comments',0700,true);mkdir($dir,0700);mkdir($data,0700);
copy($argv[1]??dirname(__DIR__).'/src/Comments/CommentsRuntime.php',$app.'/lib/Mcp/Comments/CommentsRuntime.php');require $app.'/lib/Mcp/Comments/CommentsRuntime.php';OC::$configDir=$dir;
\OCP\Server::$config=new class($data) {public bool $readonly=true;public string $factory='McpFormsServerExtension\\CommentsFactory';public function __construct(private string $data){}public function getSystemValueBool(string $key,bool $default=false):bool{return $this->readonly;}public function getSystemValue(string $key,mixed $default=null):mixed{return match($key){'datadirectory'=>$this->data,'instanceid'=>'activationtest01',default=>$this->factory};}};
file_put_contents($app.'/mcp-guard-installed.json','exact compiled native marker');$hashes=[];
foreach(['mcp_forms_comments_bootstrap.php','mcp-forms-comments.config.php','CommentsActivationState.php']as$name){copy(dirname(__DIR__).'/standalone/'.$name,$dir.'/'.$name);$hashes[$name]=hash_file('sha256',$dir.'/'.$name);}
require $dir.'/mcp_forms_comments_bootstrap.php';
$meta=['active'=>true,'native_manifest'=>hash_file('sha256',$app.'/mcp-guard-installed.json'),'hashes'=>$hashes];
$write=static function(array $value)use($dir):void{chmod($dir,0700);if(is_file($dir.'/mcp_forms_comments_install.json'))chmod($dir.'/mcp_forms_comments_install.json',0600);file_put_contents($dir.'/mcp_forms_comments_install.json',json_encode($value,JSON_THROW_ON_ERROR));chmod($dir.'/mcp_forms_comments_install.json',0440);chmod($dir,0550);};
\McpFormsServerExtension\CommentsActivationState::activate($meta);$write($meta);$approved=static fn():bool=>\OCA\Forms\Mcp\Comments\CommentsRuntime::approved();$checks=[];
$factory=new \McpFormsServerExtension\CommentsFactory(new class implements \Psr\Container\ContainerInterface {public function get(string $id){return null;}public function has(string $id):bool{return false;}});
try {
 $checks['readonly_exact_activation_approved']=$approved();
 chmod($dir,0700);chmod($dir.'/mcp_forms_comments_install.json',0600);\OCP\Server::$config->readonly=false;$checks['writable_activation_still_approved']=$approved();\OCP\Server::$config->readonly=true;$write($meta);
 $old=$meta;$bytes=file_get_contents($dir.'/mcp_forms_comments_install.json');$stock=$factory->getManager();
 $checks['readonly_fallback_returns_stock']=$stock instanceof \stdClass;
 $checks['readonly_fallback_preserves_configuration']=$bytes===file_get_contents($dir.'/mcp_forms_comments_install.json');
 $checks['readonly_fallback_durably_retires_activation']=!$approved();
 \McpFormsServerExtension\CommentsActivationState::activate($meta);$write($meta);$checks['explicit_fresh_activation_restores_runtime']=$approved();
 $write($old);$checks['old_metadata_cannot_replay_retired_activation']=!$approved();$write($meta);
 $state=$data.'/appdata_activationtest01/mcp_forms_comments_state';chmod($state,0500);$calls=\OC\Comments\ManagerFactory::$created;$thrown=false;try{$factory->getManager();}catch(\RuntimeException){$thrown=true;}
 $checks['gap_acknowledgement_failure_blocks_stock']=$thrown&&\OC\Comments\ManagerFactory::$created===$calls;$checks['unwritable_witness_withholds_capability']=!$approved();chmod($state,0700);
 $checks['failed_fallback_did_not_retire_or_write_comments']=$approved();
 $bad=$meta;$bad['active']=false;$write($bad);$checks['inactive_refused']=!$approved();
 $bad=$meta;$bad['native_manifest']=str_repeat('0',64);$write($bad);$checks['different_native_marker_refused']=!$approved();
 $bad=$meta;$bad['hashes']['mcp_forms_comments_bootstrap.php']=str_repeat('0',64);$write($bad);$checks['different_bootstrap_refused']=!$approved();
 $write($meta);\OCP\Server::$config->factory='OC\\Comments\\ManagerFactory';$checks['different_factory_refused']=!$approved();\OCP\Server::$config->factory='McpFormsServerExtension\\CommentsFactory';
 chmod($dir,0700);rename($dir.'/mcp_forms_comments_install.json',$dir.'/prior.json');$checks['missing_activation_refused']=!$approved();symlink($dir.'/prior.json',$dir.'/mcp_forms_comments_install.json');$checks['symlink_activation_refused']=!$approved();
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} finally {$remove=static function(string $path)use(&$remove):void{if(is_link($path)||is_file($path)){unlink($path);return;}chmod($path,0700);foreach(new \FilesystemIterator($path)as$f)$remove($f->getPathname());rmdir($path);};$remove($root);}
exit(in_array(false,$checks,true)?1:0);
}
