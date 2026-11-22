<?php
declare(strict_types=1);
namespace OCA\Circles\Exceptions {class ShareWrapperNotFoundException extends \RuntimeException {}}
namespace OCA\Forms\Mcp\Service\CircleDetached {class Lease {public static bool $reading=false;public array $row=['id'=>31,'parent'=>0,'type'=>7,'circle'=>'circle','file'=>11];public ?\Throwable $error=null;public int $queries=0;public function metadataRead(\Closure $read):mixed{self::$reading=true;try{return$read();}finally{self::$reading=false;}}public function shareWrappers():object{return new class($this){public function __construct(private Lease $lease){}public function searchShare(string $circle,int $file):object{$this->lease->queries++;if($this->lease->error!==null)throw$this->lease->error;return new class($this->lease->row){public function __construct(private array $row){}private function value(string $key):mixed{if(!Lease::$reading)throw new \LogicException('leased model escaped read');return$this->row[$key];}public function getId():mixed{return$this->value('id');}public function getParent():mixed{return$this->value('parent');}public function getShareType():mixed{return$this->value('type');}public function getSharedWith():mixed{return$this->value('circle');}public function getFileSource():mixed{return$this->value('file');}};}};}}}
namespace {
require_once __DIR__.'/../src/Service/CircleRuntime.php';
use OCA\Forms\Mcp\Service\{CircleRuntime,CircleDetached\Lease};
final class CircleNativeShareIdentityReadTest extends \PHPUnit\Framework\TestCase {
 private function read(Lease $lease):mixed{return(new ReflectionMethod(CircleRuntime::class,'readShareIdentity'))->invoke(null,$lease,'circle',31,11);}
 protected function tearDown():void{self::assertFalse(Lease::$reading);}
 public function testOnlyIdentityLeavesTheClosedRead():void{$lease=new Lease();self::assertNull($this->read($lease));self::assertSame(1,$lease->queries);}
 public function testEveryNativeParentBindingIsStillChecked():void{foreach(['id'=>32,'parent'=>3,'type'=>1,'circle'=>'other','file'=>12]as$key=>$value){$lease=new Lease();$lease->row[$key]=$value;$denied=false;try{$this->read($lease);}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
 public function testNotFoundKeepsNativeNoDuplicateDecision():void{$lease=new Lease();$lease->error=new \OCA\Circles\Exceptions\ShareWrapperNotFoundException();self::assertNull($this->read($lease));self::assertSame(1,$lease->queries);}
 public function testOtherReadFailureStillClosesTheFrame():void{$lease=new Lease();$lease->error=new LogicException();$this->expectException(LogicException::class);$this->read($lease);}
}
}
