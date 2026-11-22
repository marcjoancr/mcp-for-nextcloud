<?php
declare(strict_types=1);
namespace OCP\Files {interface Folder {public function getPath():string;public function getId():int;public function getOwner():object; } interface IRootFolder {}}
namespace OCP {final class Server {public static array $services=[];public static function get(string $id):object{return self::$services[$id];}}}
namespace OCA\Forms\Mcp\Service {
final class OriginContext {public static int $effects=0;public static string $tool='forms_transfer';public static function assertFileEffect():void{self::$effects++;}public static function current():array{return ['verified'=>['tool'=>self::$tool],'origin'=>['signed'=>true]];}}
final class OriginReadAuthority {public static bool $live=true;public static function check():bool{return self::$live;}}
final class BulkDeadline {public static function check():void{}}
final class StorageAdmission {public static array $nodes=[];public static function node(object $node):void{self::$nodes[]=$node;}}
final class BulkTransferShares {public static function destinationAuthorityRead(\OCP\Files\Folder $folder):void{DestinationFiles::transferAuthorityRead($folder);}}
}
namespace {
require_once __DIR__.'/../src/Service/DestinationFiles.php';
use OCA\Forms\Mcp\Service\{BulkTransferShares,DestinationFiles,OriginContext,OriginReadAuthority,StorageAdmission};
final class CircleDestinationAuthorityReadTest extends \PHPUnit\Framework\TestCase {
 private object $original;private object $fresh;private object $auth;private object $root;
 private function folder(int $id,string $owner):object{return new class($id,$owner) implements \OCP\Files\Folder {public function __construct(private int $id,private string $uid){}public function getPath():string{return '/target/files/managed';}public function getId():int{return$this->id;}public function getOwner():object{return new class($this->uid){public function __construct(private string $uid){}public function getUID():string{return$this->uid;}};}};}
 protected function setUp():void{$this->original=$this->folder(11,'target');$this->fresh=$this->folder(11,'target');$this->root=new class($this->fresh){public int $reads=0;public function __construct(public object $node){}public function get(string $path):object{$this->reads++;return$this->node;}};$this->auth=new class {public bool $allow=true;public array $seen=[];public function assertDestination(array $origin,object $node,bool $create):void{$this->seen[]=[$origin,$node,$create];if(!$this->allow)throw new RuntimeException('revoked');}};\OCP\Server::$services=[\OCP\Files\IRootFolder::class=>$this->root,'OCA\\Mcp\\Modules\\Forms\\Access\\FormsNativeAuthorization'=>$this->auth];OriginContext::$effects=0;OriginContext::$tool='forms_transfer';OriginReadAuthority::$live=true;StorageAdmission::$nodes=[];}
 public function testFreshNodeAndCurrentNativeAclAreAlwaysRead():void{BulkTransferShares::destinationAuthorityRead($this->original);self::assertSame($this->fresh,$this->auth->seen[0][1]);self::assertSame([$this->fresh],StorageAdmission::$nodes);self::assertSame(0,OriginContext::$effects);$this->auth->allow=false;$this->expectException(RuntimeException::class);try{BulkTransferShares::destinationAuthorityRead($this->original);}finally{self::assertSame(2,$this->root->reads);self::assertCount(2,$this->auth->seen);}}
 public function testChangedFreshFolderIdentityIsDenied():void{foreach([[12,'target'],[11,'other']]as[$id,$owner]){$this->root->node=$this->folder($id,$owner);$denied=false;try{BulkTransferShares::destinationAuthorityRead($this->original);}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}self::assertSame([],$this->auth->seen);}
 public function testExpiredPureFrameIsDeniedBeforeResolvingNode():void{OriginReadAuthority::$live=false;$this->expectException(RuntimeException::class);try{BulkTransferShares::destinationAuthorityRead($this->original);}finally{self::assertSame(0,$this->root->reads);}}
 public function testOtherToolIsDenied():void{OriginContext::$tool='forms_restore';$this->expectException(RuntimeException::class);BulkTransferShares::destinationAuthorityRead($this->original);}
 public function testUnqualifiedCallerIsDenied():void{$this->expectException(RuntimeException::class);DestinationFiles::transferAuthorityRead($this->original);}
 public function testOrdinaryAdmissionRetainsItsFileBoundary():void{OriginReadAuthority::$live=false;DestinationFiles::authorize($this->original,true);self::assertSame(1,OriginContext::$effects);self::assertSame($this->fresh,$this->auth->seen[0][1]);}
}
}
