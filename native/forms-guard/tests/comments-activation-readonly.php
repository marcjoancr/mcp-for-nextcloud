<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP {final class Server {public static object $config; public static function get(string $class):object{return self::$config;}}}
namespace {
final class OC {public static string $configDir;}
$root=sys_get_temp_dir().'/forms-comments-approval-'.bin2hex(random_bytes(8));
$app=$root.'/forms';$dir=$root.'/config';mkdir($app.'/lib/Mcp/Comments',0700,true);mkdir($dir,0700);
copy(dirname(__DIR__).'/src/Comments/CommentsRuntime.php',$app.'/lib/Mcp/Comments/CommentsRuntime.php');
require $app.'/lib/Mcp/Comments/CommentsRuntime.php';OC::$configDir=$dir;
\OCP\Server::$config=new class {public bool $readonly=true;public string $factory='McpFormsServerExtension\\CommentsFactory';public function getSystemValueBool(string $key,bool $default=false):bool{return $this->readonly;}public function getSystemValue(string $key):string{return $this->factory;}};
file_put_contents($app.'/mcp-guard-installed.json','exact compiled native marker');$hashes=[];
foreach(['mcp_forms_comments_bootstrap.php','mcp-forms-comments.config.php']as$name){file_put_contents($dir.'/'.$name,'exact approved '.$name);$hashes[$name]=hash_file('sha256',$dir.'/'.$name);}
$meta=['active'=>true,'native_manifest'=>hash_file('sha256',$app.'/mcp-guard-installed.json'),'hashes'=>$hashes];
$write=static function(array $value)use($dir):void{file_put_contents($dir.'/mcp_forms_comments_install.json',json_encode($value,JSON_THROW_ON_ERROR));};$write($meta);
$approved=static fn():bool=>\OCA\Forms\Mcp\Comments\CommentsRuntime::approved();$checks=[];
try {
 chmod($dir.'/mcp_forms_comments_install.json',0440);chmod($dir,0550);
 $checks['readonly_exact_activation_approved']=$approved();
 chmod($dir,0700);chmod($dir.'/mcp_forms_comments_install.json',0600);
 \OCP\Server::$config->readonly=false;$checks['writable_activation_still_approved']=$approved();
 \OCP\Server::$config->readonly=true;
 $bad=$meta;$bad['active']=false;$write($bad);$checks['inactive_refused']=!$approved();
 $bad=$meta;$bad['native_manifest']=str_repeat('0',64);$write($bad);$checks['different_native_marker_refused']=!$approved();
 $bad=$meta;$bad['hashes']['mcp_forms_comments_bootstrap.php']=str_repeat('0',64);$write($bad);$checks['different_bootstrap_refused']=!$approved();
 $write($meta);\OCP\Server::$config->factory='OC\\Comments\\ManagerFactory';$checks['different_factory_refused']=!$approved();\OCP\Server::$config->factory='McpFormsServerExtension\\CommentsFactory';
 rename($dir.'/mcp_forms_comments_install.json',$dir.'/prior.json');$checks['missing_activation_refused']=!$approved();
 symlink($dir.'/prior.json',$dir.'/mcp_forms_comments_install.json');$checks['symlink_activation_refused']=!$approved();
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} finally {
 $remove=static function(string $path)use(&$remove):void{if(is_link($path)||is_file($path)){unlink($path);return;}chmod($path,0700);foreach(new \FilesystemIterator($path)as$f)$remove($f->getPathname());rmdir($path);};$remove($root);
}
exit(in_array(false,$checks,true)?1:0);
}
