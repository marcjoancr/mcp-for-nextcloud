<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function getDatabaseProvider(bool $strict=false):string;}}
namespace {require $argv[1].'/src/Service/CoreShareIdentity.php';use OCA\Forms\Mcp\Service\CoreShareIdentity as I;
$checks=0;foreach([['ocinternal:1',0,1],['ocinternal:2532',1,2532],['ocCircleShare:2531',7,2531],['ocCircleShare:'.PHP_INT_MAX,7,PHP_INT_MAX]]as[$full,$type,$id]){if(I::id($full,$type)!==$id)throw new RuntimeException();$checks++;}
foreach([['ocinternal:01',0],['ocinternal:0',0],['ocCircleShare:+1',7],['ocCircleShare:'.PHP_INT_MAX.'0',7],['ocinternal:1',7],['ocCircleShare:1',1],['alias:1',0],['ocCircleShare:1;DELETE',7]]as[$full,$type]){$denied=false;try{I::parse($full,$type);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Invalid provider accepted');$checks++;}
foreach(['postgres'=>'BIGINT','mariadb'=>'SIGNED']as$provider=>$cast){$db=new class($provider)implements \OCP\IDBConnection{public function __construct(private string $provider){}public function getDatabaseProvider(bool $strict=false):string{return$this->provider;}};$sql=I::sql($db,'x');if(!str_contains($sql,"SUBSTRING(x.files_share_id,15) AS $cast")||!str_contains($sql,"SUBSTRING(x.files_share_id,12) AS $cast"))throw new RuntimeException();$checks++;}
$denied=false;try{I::sql($db,'x;DELETE');}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException();$checks++;echo json_encode(['ok'=>true,'checks'=>$checks]),"\n";}
