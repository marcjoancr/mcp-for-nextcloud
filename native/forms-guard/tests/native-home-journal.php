<?php
declare(strict_types=1);
require $argv[1].'/src/Service/NativeHomeJournal.php';use OCA\Forms\Mcp\Service\NativeHomeJournal as J;
$e=['protocol'=>1,'job_id'=>str_repeat('a',64),'uid'=>'actor','target_uid'=>'recipient','files_path'=>'/recipient/files','home_phase'=>['nonce'=>str_repeat('b',64),'phase'=>'copy_files','uid'=>'actor','action'=>'forms_transfer','target_uid'=>'recipient','step_key'=>str_repeat('s',16),'version'=>7]];$checks=0;
$f=tmpfile();$j=J::open($f,$e);$t=$j->intent('mkdir','files');$j->complete($t);$r=$j->assertComplete();if($r['effects']!==1)throw new RuntimeException('wrong effect count');$checks++;$j->seal('committed');$r=J::read($f,$e);if($r['state']!=='committed'||$r['pending']!==[]||$r['effects']!==1)throw new RuntimeException('wrong receipt');$checks++;
$denied=static function(Closure $c):void{$ok=false;try{$c();}catch(RuntimeException){$ok=true;}if(!$ok)throw new RuntimeException('expected refusal');};
$denied(fn()=>$j->intent('mkdir','files/again'));$checks++;$bad=$e;$bad['home_phase']['nonce']=str_repeat('c',64);$denied(fn()=>J::read($f,$bad));$checks++;
$f2=tmpfile();$j2=J::open($f2,$e);$t=$j2->intent('write','files/a',str_repeat('d',64));$denied(fn()=>$j2->assertComplete());$checks++;$r=J::read($f2,$e);if($r['state']!=='uncertain'||count($r['pending'])!==1)throw new RuntimeException('pending effect lost');$checks++;$j2->fail($t);$denied(fn()=>$j2->assertComplete());$checks++;$denied(fn()=>$j2->intent('mkdir','files/b'));$checks++;$j2->seal('uncertain');$r=J::read($f2,$e);if($r['state']!=='uncertain'||!$r['failed'])throw new RuntimeException('failure lost');$checks++;
foreach(['../other','/absolute','files/../../other','files//a',"files/a\0b"]as$p){$g=tmpfile();$x=J::open($g,$e);$denied(fn()=>$x->intent('mkdir',$p));fclose($g);$checks++;}
$f3=tmpfile();$x=J::open($f3,$e);$t=$x->intent('mkdir','files');$x->complete($t);$denied(fn()=>$x->complete($t));$checks++;
$f4=tmpfile();$x=J::open($f4,$e);$t=$x->intent('mkdir','files');$x->complete($t);$x->seal('committed');fseek($f4,-1,SEEK_END);ftruncate($f4,ftell($f4));$denied(fn()=>J::read($f4,$e));$checks++;
$f5=tmpfile();$x=J::open($f5,$e);$limited=false;for($i=0;$i<130;$i++){try{$t=$x->intent('scan','files/'.$i);$x->complete($t);}catch(RuntimeException){$limited=true;break;}}if(!$limited)throw new RuntimeException('no record bound');$checks++;
echo json_encode(['ok'=>true,'checks'=>$checks]),"\n";
