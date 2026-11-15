<?php
declare(strict_types=1);
require __DIR__.'/../standalone/GroupInstallTree.php';
use McpFormsServerExtension\GroupInstallTree as T;
$d=sys_get_temp_dir().'/group-tree-'.bin2hex(random_bytes(8));mkdir($d,0700);$n=0;
try{file_put_contents($d.'/x','abc');chmod($d.'/x',0640);$a=T::snapshot($d);if($a!==T::snapshot($d))throw new RuntimeException('unstable');$n++;file_put_contents($d.'/x','abd');if($a===T::snapshot($d))throw new RuntimeException('bytes ignored');$n++;file_put_contents($d.'/x','abc');chmod($d.'/x',0600);if($a===T::snapshot($d))throw new RuntimeException('mode ignored');$n++;symlink($d.'/x',$d.'/link');$denied=false;try{T::snapshot($d);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('symlink accepted');$n++;unlink($d.'/link');link($d.'/x',$d.'/hard');$denied=false;try{T::snapshot($d);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('hardlink accepted');$n++;unlink($d.'/hard');echo json_encode(['checks'=>$n,'native_bootstraps'=>0]),"\n";}finally{unlink($d.'/x');rmdir($d);}
