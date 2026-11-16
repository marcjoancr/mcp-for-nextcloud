<?php
declare(strict_types=1);
require __DIR__.'/../standalone/GroupInstallProtocol.php';
use McpFormsServerExtension\GroupInstallProtocol as P;
$n=0;$r=['sequence'=>1,'phase'=>'schema','profile'=>[]];if(P::request(json_encode($r),1)!==$r)throw new RuntimeException('request');$n++;
foreach([[json_encode($r),2],[str_repeat(' ',1048577),1],[json_encode(['sequence'=>1,'phase'=>'shell','profile'=>[]]),1],[json_encode(['sequence'=>1,'phase'=>'schema','profile'=>false]),1]]as[$line,$count]){$denied=false;try{P::request($line,$count);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('not denied');$n++;}
P::response(json_encode(['ok'=>true,'stage'=>'schema','error_class'=>null]),0,'schema');$n++;
foreach([['ok'=>false,'stage'=>'schema','error_class'=>null],['ok'=>true,'stage'=>'other','error_class'=>null],['ok'=>true,'stage'=>'schema','error_class'=>'SecretError']]as$x){$denied=false;try{P::response(json_encode($x),0,'schema');}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('not denied');$n++;}
$snapshot=['ok'=>true,'stage'=>'snapshot','error_class'=>null,'data_digest'=>str_repeat('a',64),'data_count'=>27711,'data_bytes'=>1167983041];P::response(json_encode($snapshot),0,'snapshot');$n++;
foreach([['data_count',32769],['data_bytes',2147483649]]as[$key,$value]){$bad=$snapshot;$bad[$key]=$value;$denied=false;try{P::response(json_encode($bad),0,'snapshot');}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('unbounded snapshot accepted');$n++;}
echo json_encode(['checks'=>$n,'children_started'=>0]),"\n";
