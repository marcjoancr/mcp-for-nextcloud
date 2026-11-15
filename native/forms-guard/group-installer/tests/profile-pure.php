<?php
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupInstallState.php';require dirname(__DIR__).'/standalone/GroupInstallProfile.php';
use McpFormsServerExtension\GroupInstallProfile as P;
$d=dirname(__DIR__).'/tests/profile-'.bin2hex(random_bytes(8));mkdir($d,0700);$checks=0;
function check(bool$v):void{global$checks;$checks++;if(!$v)throw new RuntimeException('test failed');}
function deny(Closure$f,string$m):void{$got=null;try{$f();}catch(RuntimeException$e){$got=$e->getMessage();}check($got===$m);}
try{
 file_put_contents($d.'/source.php','synthetic');$h=hash('sha256','synthetic');P::pins($d,['source.php'=>$h]);check(true);
 deny(fn()=>P::pins($d,['source.php'=>str_repeat('0',64)]),'Installer source changed');
 deny(fn()=>P::pins($d,['../source.php'=>$h]),'Installer source inventory malformed');
 mkdir($d.'/sub',0700);symlink($d.'/source.php',$d.'/sub/source.php');deny(fn()=>P::pins($d,['sub/source.php'=>$h]),'Installer symbolic source refused');unlink($d.'/sub/source.php');rmdir($d.'/sub');
 mkdir($d.'/config',0700);file_put_contents($d.'/config/config.php','synthetic');
 $p=['format'=>1,'root'=>$d,'action'=>'install','operation'=>str_repeat('a',32),'source_pins'=>['source.php'=>$h],'config_pins'=>['config.php'=>$h],'migration_versions'=>['old'],'prior_epoch'=>null,'next_epoch'=>str_repeat('b',64),'bundle_manifest'=>str_repeat('c',64),'bundle_preimages'=>[],'maintenance_prior'=>false];
 foreach(['custom_apps/forms','custom_apps/mcp','apps/circles']as$scope){mkdir($d.'/'.$scope,0700,true);file_put_contents($d.'/'.$scope.'/source.php','synthetic');$p['source_pins'][$scope.'/source.php']=$h;}
 file_put_contents($d.'/profile.json',json_encode($p));check(P::read($d,$d.'/profile.json')===$p);
 $p['migration_versions']=['old','old'];file_put_contents($d.'/profile.json',json_encode($p));deny(fn()=>P::read($d,$d.'/profile.json'),'Installer migration profile malformed');
 file_put_contents($d.'/config/unknown.php','synthetic');deny(fn()=>P::complete($d.'/config',['config.php'=>$h]),'Installer complete inventory differs');unlink($d.'/config/unknown.php');
 deny(fn()=>P::helper(),'Installer finite helper profile unavailable');
 echo json_encode(['ok'=>true,'checks'=>$checks,'pure_only'=>true]),"\n";
}finally{foreach(['custom_apps/forms','custom_apps/mcp','apps/circles']as$scope){if(is_file($d.'/'.$scope.'/source.php'))unlink($d.'/'.$scope.'/source.php');if(is_dir($d.'/'.$scope))rmdir($d.'/'.$scope);}foreach(['custom_apps','apps']as$scope)if(is_dir($d.'/'.$scope))rmdir($d.'/'.$scope);unlink($d.'/source.php');unlink($d.'/profile.json');unlink($d.'/config/config.php');rmdir($d.'/config');rmdir($d);}
