<?php
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupInstallFiles.php';
use McpFormsServerExtension\GroupInstallFiles as F;
$root=dirname(__DIR__).'/tests/private-'.bin2hex(random_bytes(8));$checks=0;
function check(bool$ok):void{global$checks;$checks++;if(!$ok)throw new RuntimeException('test failed');}
function deny(Closure$f,string$m):void{$got=null;try{$f();}catch(RuntimeException$e){$got=$e->getMessage();}check($got===$m);}
try{
 F::directory($root);check((fileperms($root)&0777)===0700);$f=new F($root);
 $f->atomic('receipt.json','old',null);check((fileperms($root.'/receipt.json')&0777)===0600);check(file_get_contents($root.'/receipt.json')==='old');
 deny(fn()=>$f->atomic('receipt.json','bad',str_repeat('0',64)),'Installer foreign artifact preserved');check(file_get_contents($root.'/receipt.json')==='old');
 deny(fn()=>$f->atomic('../escape','bad',null),'Installer artifact name refused');
 symlink($root.'/receipt.json',$root.'/link');deny(fn()=>$f->atomic('link','bad',null),'Installer foreign artifact preserved');unlink($root.'/link');
 foreach(['opened','written','synced','before_rename']as$point){$writer=new F($root,static function(string$p,string$tmp)use($point):void{if($p==='opened')check((fileperms($tmp)&0777)===0600);if($p===$point)throw new RuntimeException('injected');});deny(fn()=>$writer->atomic('receipt.json','new',hash('sha256','old')),'injected');check(file_get_contents($root.'/receipt.json')==='old');}
 $f->atomic('receipt.json','new',hash('sha256','old'));check(file_get_contents($root.'/receipt.json')==='new');
 deny(fn()=>$f->remove('receipt.json',hash('sha256','old')),'Installer foreign artifact preserved');
 $f->remove('receipt.json',hash('sha256','new'));check(!file_exists($root.'/receipt.json'));
 mkdir($root.'/public-readable',0755);new F($root.'/public-readable');check(true);
 F::directory($root.'/checkpoint');deny(fn()=>F::directory($root.'/checkpoint'),'Installer private directory unavailable');
 echo json_encode(['ok'=>true,'checks'=>$checks,'pure_only'=>true]),"\n";
}finally{if(is_dir($root)){foreach(glob($root.'/*')as$p){if(is_dir($p))rmdir($p);else unlink($p);}rmdir($root);}}
