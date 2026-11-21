<?php
declare(strict_types=1);
namespace OCP {class Server {public static object $db;public static function get(string $class):object{return self::$db;}}}
namespace OCA\Forms\Mcp\Service {class BulkStore {}}
namespace OCA\Circles\Model {
 class ShareWrapper {public function __construct(private int $id=31){}public function getParent():int{return 0;}public function getId():string{return(string)$this->id;}public function getShareType():int{return 7;}public function getSharedWith():string{return 'circle';}public function getShareOwner():string{return 'target';}public function getSharedBy():string{return 'actor';}public function getFileSource():int{return 11;}public function getPermissions():int{return 1;}}
}
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/IShare.php';
require_once __DIR__.'/../src/Service/CircleDetached/ShareWriteBoundary.php';
use OCA\Forms\Mcp\Service\{BulkStore,CircleDetached\ShareWriteBoundary};
final class CircleActivationBoundaryTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{\OCP\Server::$db=new class {public bool $transaction=true;public mixed $count=0;public ?int $parent=null;public function inTransaction():bool{return$this->transaction;}public function executeQuery(string $sql,array $params):object{$this->parent=$params[0];return new class($this->count){public function __construct(private mixed $count){}public function fetchOne():mixed{return$this->count;}};}};}
 private function capability(string $mode,bool $consumed):ShareWriteBoundary{$r=new ReflectionClass(ShareWriteBoundary::class);$c=$r->newInstanceWithoutConstructor();$r->getConstructor()->invokeArgs($c,[new BulkStore(),['id'=>'job'],'share_recipients',1,str_repeat('a',64),3,31,'actor','target',11,'circle',['share_with'=>'single','accepted'=>2],$mode,41]);(new ReflectionProperty($c,'consumed'))->setValue($c,$consumed);return$c;}
 private function parent():\OCP\Share\IShare{$p=$this->createMock(\OCP\Share\IShare::class);foreach(['getId'=>'31','getShareType'=>7,'getSharedWith'=>'circle','getShareOwner'=>'target','getSharedBy'=>'actor','getNodeId'=>11,'getPermissions'=>1]as$m=>$v)$p->method($m)->willReturn($v);return$p;}
 public function testConsumedParentCanOnlySuppressZeroRowPropagation():void{$this->capability('activate-parent',true)->assertQuarantinedParentPropagation('actor',$this->parent(),new \OCA\Circles\Model\ShareWrapper());self::assertSame(31,\OCP\Server::$db->parent);}
 public function testActiveChildPreventsSuppression():void{\OCP\Server::$db->count=1;$this->expectException(RuntimeException::class);$this->capability('activate-parent',true)->assertQuarantinedParentPropagation('actor',$this->parent(),new \OCA\Circles\Model\ShareWrapper());}
 public function testUnconsumedParentCannotSuppressPropagation():void{$this->expectException(RuntimeException::class);$this->capability('activate-parent',false)->assertQuarantinedParentPropagation('actor',$this->parent(),new \OCA\Circles\Model\ShareWrapper());}
 public function testWrongParentCannotSuppressPropagation():void{$this->expectException(RuntimeException::class);$this->capability('activate-parent',true)->assertQuarantinedParentPropagation('actor',$this->parent(),new \OCA\Circles\Model\ShareWrapper(32));}
 public function testChildSqlUsesItsSealedRecipientAndRejection():void{self::assertSame(['recipient'=>'single','accepted'=>2],$this->capability('activate-child',false)->childUpdateDecision('actor',$this->parent(),['child_id'=>41]));}
 public function testDifferentChildCannotBorrowDecision():void{$this->expectException(RuntimeException::class);$this->capability('activate-child',false)->childUpdateDecision('actor',$this->parent(),['child_id'=>42]);}
 public function testEndedTransactionCannotExposeDecision():void{\OCP\Server::$db->transaction=false;$this->expectException(RuntimeException::class);$this->capability('activate-child',false)->childUpdateDecision('actor',$this->parent(),['child_id'=>41]);}
}
}
