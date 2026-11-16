<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require __DIR__.'/../standalone/GroupInstallEnvironment.php';
use McpFormsServerExtension\GroupInstallEnvironment as E;
$values=['PHP_MEMORY_LIMIT'=>'512M','PHP_UPLOAD_LIMIT'=>'512M','PHP_OPCACHE_MEMORY_CONSUMPTION'=>'128'];$prior=[];$checks=0;
try {
 foreach($values as$name=>$value){$prior[$name]=getenv($name);putenv($name.'='.$value);}
 $out=E::child();if(array_keys($out)!==['PATH',...array_keys($values)]||array_intersect_key($out,$values)!==$values)throw new RuntimeException();$checks++;
 foreach(array_keys($values)as$name){putenv($name);$denied=false;try{E::child();}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException();putenv($name.'='.$values[$name]);$checks++;}
 putenv('PHP_MEMORY_LIMIT=0');$denied=false;try{E::child();}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException();$checks++;
 echo json_encode(['ok'=>true,'checks'=>$checks,'credential_variables_inherited'=>0]),"\n";
}finally{foreach($prior as$name=>$value){if($value===false)putenv($name);else putenv($name.'='.$value);}}
