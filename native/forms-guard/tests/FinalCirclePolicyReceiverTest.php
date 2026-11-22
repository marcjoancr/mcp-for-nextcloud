<?php
declare(strict_types=1);
namespace OCP {interface IUser {public function getUID():string;}interface IUserManager {}}
namespace OCP\Files {class Node {public function getId():int{return 11;}}}
namespace OC\Files\Node {class Root {}}
namespace OC\Share20 {class Manager {private mixed $mountManager=null;protected function pathCreateChecks(\OCP\Files\Node $node):void{\OCA\Forms\Mcp\Service\TestState::$paths++;}}}
namespace OCA\Forms\Mcp\Service\CircleDetached {class CircleView {public function __construct(private string $id){}public function getSingleId():string{return$this->id;}}}
namespace OCA\Forms\Mcp\Service {
final class TestState {public static array $calls=[];public static int $paths=0;public static string $mountSubject='';public static bool $closed=false;}
final class OriginContext {public static function current():array{return['action'=>'forms_transfer','verified'=>['uid'=>'source','tool'=>'forms_transfer'],'input'=>['owner_id'=>'target']];}}
final class FinalShareBudget {}final class FinalReadConnection {}
final class CircleRuntime {public static function assertAvailable(string $uid):void{TestState::$calls[]=['available',$uid];}public static function assertCircleShareIdentity(string $uid,string $circle,int $id,int $file,object $db):void{TestState::$calls[]=['identity',$uid,$circle,$id,$file,$db];}}
final class CircleReadRuntime {public static mixed $profile;public static function scoped(string $uid,bool $deferred,?object $connection=null):\Closure{return static function(string $method,array $args)use($uid,$connection):mixed{TestState::$calls[]=[$method,$uid,$connection,$args];return self::$profile;};}}
final class FinalSharePathMounts {public static function read(object $root,object $subject,object $node,object $budget,\Closure $frame):object{TestState::$mountSubject=$subject->getUID();return new class {public function close():void{TestState::$closed=true;}};}}
}
namespace {
require_once __DIR__.'/../src/Service/FinalSharePolicyFactory.php';require_once __DIR__.'/../src/Service/FinalShareNativeChecks.php';
use OCA\Forms\Mcp\Service\{TestState,FinalSharePolicyFactory,FinalShareNativeChecks,FinalReadConnection,FinalShareBudget,CircleReadRuntime,CircleDetached\CircleView};
final class FinalCirclePolicyReceiverTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{TestState::$calls=[];TestState::$paths=0;TestState::$mountSubject='';TestState::$closed=false;CircleReadRuntime::$profile=new CircleView('circle');}
 private function evidence(string $subject,FinalReadConnection $db):string{return(new ReflectionMethod(FinalSharePolicyFactory::class,'circleReceiverEvidence'))->invoke(null,$subject,'circle',$db);}
 public function testReceiverReadsFreshProfileWithOriginalActorAndRecorder():void{$db=new FinalReadConnection();self::assertSame('source',$this->evidence('target',$db));self::assertSame([['available','source'],['getCircle','source',$db,['circleId'=>'circle']]],TestState::$calls);}
 public function testMismatchedOrMissingCircleProfileIsDenied():void{foreach([null,new CircleView('other')]as$profile){CircleReadRuntime::$profile=$profile;$denied=false;try{$this->evidence('target',new FinalReadConnection());}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
 public function testUnrelatedSubjectCannotReadCircleMetadata():void{$this->expectException(RuntimeException::class);try{$this->evidence('other',new FinalReadConnection());}finally{self::assertSame([],TestState::$calls);}}
 private function nativeCheck(?string $actor):void{$subject=new class implements \OCP\IUser {public function getUID():string{return'target';}};$users=new class implements \OCP\IUserManager {};FinalShareNativeChecks::collect(new \OC\Share20\Manager(),$users,new \OC\Files\Node\Root(),$subject,new \OCP\Files\Node(),['id'=>31,'share_type'=>7,'share_with'=>'circle','uid_owner'=>'target','file_source'=>11],new FinalShareBudget(),new FinalReadConnection(),static fn()=>null,static fn()=>null,$actor);}
 public function testNativePathPolicyRetainsTargetSubjectWhileMetadataUsesOriginalActor():void{$this->nativeCheck('source');self::assertSame('source',TestState::$calls[0][1]);self::assertSame('target',TestState::$mountSubject);self::assertSame(1,TestState::$paths);self::assertTrue(TestState::$closed);}
 public function testDestinationUidCannotBecomeMetadataActor():void{$this->expectException(RuntimeException::class);try{$this->nativeCheck('target');}finally{self::assertSame([],TestState::$calls);self::assertSame(0,TestState::$paths);}}
 public function testNativeCircleCheckCannotFallBackToSubjectAsActor():void{$this->expectException(RuntimeException::class);try{$this->nativeCheck(null);}finally{self::assertSame([],TestState::$calls);self::assertSame(0,TestState::$paths);}}
}
}
