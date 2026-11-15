<?php
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupInstallState.php';
use McpFormsServerExtension\GroupInstallState as S;
$checks=0;
function check(bool $ok):void {global$checks;$checks++;if(!$ok)throw new RuntimeException('test failed');}
function deny(Closure$f,string$message):void{$got=null;try{$f();}catch(RuntimeException$e){$got=$e->getMessage();}check($got===$message);}
$a=str_repeat('a',64);$b=str_repeat('b',64);
check(S::metadata($a,$b)===['format'=>1,'active'=>true,'epoch'=>$a,'manifest_sha256'=>$b]);
deny(fn()=>S::metadata('bad',$b),'Invalid installer identity');
check(S::epochAction(null,$a)===['insert',null,$a]);
check(S::epochAction($a,$b)===['replace',$a,$b]);
deny(fn()=>S::epochAction($a,$a),'Installer epoch must change');
$s=new S('install');foreach(['checkpoint','schema','staged','epoch','verified','turnover1','maintenance','turnover2','health','active']as$p)$s->advance($p);check($s->phase()==='active');
deny(fn()=>$s->advance('checkpoint'),'Installer transition refused');
$s=new S('rollback');foreach(['checkpoint','retired','entry_removed','turnover1','maintenance','turnover2','health','inactive']as$p)$s->advance($p);check($s->phase()==='inactive');
$s=new S('install');deny(fn()=>$s->advance('epoch'),'Installer transition refused');check($s->phase()==='hold');deny(fn()=>$s->advance('checkpoint'),'Installer transition refused');
check(S::compatiblePriorEntry(null,null,$a,$b)===false);
check(S::compatiblePriorEntry($a,['sha256'=>$a,'role'=>'owned_group_entry','manifest_sha256'=>$b],$a,$b)===true);
check(S::compatiblePriorEntry($a,['sha256'=>$a,'role'=>'foreign','manifest_sha256'=>$b],$a,$b)===false);
check(S::compatiblePriorEntry($a,['sha256'=>$b,'role'=>'owned_group_entry','manifest_sha256'=>$b],$a,$b)===false);
check(S::compatiblePriorEntry($a,['sha256'=>$a,'role'=>'owned_group_entry','manifest_sha256'=>$b],$a,$a)===false);
check(S::compatiblePriorEntry($a,['sha256'=>$a,'role'=>'owned_group_entry','manifest_sha256'=>$b],$b,$b)===false);
$before=['core'=>['hash'=>'x'],'epoch'=>null,'history'=>['hash'=>'y'],'sequences'=>['hash'=>'z'],'migrations'=>['old']];
$after=$before;$after['epoch']=$a;S::unchanged($before,$after,$a,null);check(true);
$after['history']['hash']='changed';deny(fn()=>S::unchanged($before,$after,$a,null),'Installer protected state changed');
$after=$before;$after['epoch']=$a;$after['migrations'][]='050402Date20261115020000';S::unchanged($before,$after,$a,'050402Date20261115020000');check(true);
$after['migrations'][]='other';deny(fn()=>S::unchanged($before,$after,$a,'050402Date20261115020000'),'Installer migration delta changed');
echo json_encode(['ok'=>true,'checks'=>$checks,'pure_only'=>true]),"\n";
