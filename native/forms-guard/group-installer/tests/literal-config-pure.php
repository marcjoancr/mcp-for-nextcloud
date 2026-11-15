<?php
declare(strict_types=1);
require __DIR__.'/../standalone/GroupInstallLiteralConfig.php';
use McpFormsServerExtension\GroupInstallLiteralConfig as C;
$n=0;$data=['dbhost'=>'private-db','maintenance'=>false,'nested'=>['a'=>"quote'\\slash",'n'=>3,'bool'=>true,'empty'=>null]];$s="<?php\n".'$CONFIG = '.var_export($data,true).";\n";
if(C::read($s)!==$data)throw new RuntimeException('roundtrip');$n++;
$next=$data;$next['maintenance']=true;if(C::read(C::maintenance($s,true))!==$next)throw new RuntimeException('maintenance');$n++;
foreach(['<?php $CONFIG = getenv(\'SECRET\');','<?php $CONFIG = array(\'x\'=>1,\'x\'=>2);','<?php $CONFIG = array(); exit;','<?php $CONFIG = array(\'x\'=>PHP_VERSION);','<?php $CONFIG = array(\'x\'=>"double");']as$bad){$denied=false;try{C::read($bad);}catch(Throwable){$denied=true;}if(!$denied)throw new RuntimeException('literal accepted');$n++;}
echo json_encode(['checks'=>$n,'config_execution'=>0]),"\n";
