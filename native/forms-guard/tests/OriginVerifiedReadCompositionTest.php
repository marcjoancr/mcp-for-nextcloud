<?php
declare(strict_types=1);
namespace OCP {class Server {public static object $authorization;public static function get(string $class):object{return self::$authorization;}}}
namespace {
require_once __DIR__.'/../src/Service/OriginContext.php';
use OCA\Forms\Mcp\SourceIdentity;
use OCA\Forms\Mcp\Service\{OriginContext,OriginReadAuthority};
final class OriginVerifiedReadCompositionTest extends \PHPUnit\Framework\TestCase {
 private static string $root;
 public static function setUpBeforeClass():void{self::$root=sys_get_temp_dir().'/forms-composed-read-'.bin2hex(random_bytes(8));mkdir(self::$root.'/lib/Mcp',0700,true);$source=file_get_contents(__DIR__.'/../src/SourceIdentity.php');file_put_contents(self::$root.'/lib/Mcp/SourceIdentity.php',$source);$hashes=['lib/Mcp/SourceIdentity.php'=>hash('sha256',$source)];for($i=0;$i<55;$i++){$name='file'.$i.'.php';file_put_contents(self::$root.'/'.$name,'<?php return 1;');$hashes[$name]=hash_file('sha256',self::$root.'/'.$name);}file_put_contents(self::$root.'/mcp-guard-installed.json',json_encode(['protocol'=>1,'formsVersion'=>'5.4.2','sha256'=>$hashes],JSON_THROW_ON_ERROR));require self::$root.'/lib/Mcp/SourceIdentity.php';}
 public static function tearDownAfterClass():void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir(self::$root);}
 protected function setUp():void{(new ReflectionProperty(OriginContext::class,'current'))->setValue(null,['origin'=>[]]);\OCP\Server::$authorization=new class {public int $calls=0;public bool $revoked=false;public array $codeFrames=[];public ?Closure $during=null;public function assert(array $origin):void{++$this->calls;$this->codeFrames[]=(new ReflectionProperty(SourceIdentity::class,'verifiedReadFrame'))->getValue()!==null;if($this->during!==null)($this->during)();if($this->revoked)throw new RuntimeException('revoked');if(!SourceIdentity::verify())throw new RuntimeException('source');}};}
 protected function tearDown():void{file_put_contents(self::$root.'/file0.php','<?php return 1;');(new ReflectionProperty(OriginContext::class,'current'))->setValue(null,null);self::assertFalse(OriginReadAuthority::check());self::assertNull((new ReflectionProperty(SourceIdentity::class,'verifiedReadFrame'))->getValue());}
 public function testFullAuthorizationStillRunsAtBothBoundariesInsideCodeFrame():void{$result=OriginContext::verifiedRead(static fn()=>null,static function():int{self::assertTrue(OriginReadAuthority::check());return 42;});self::assertSame(42,$result);self::assertSame(2,\OCP\Server::$authorization->calls);self::assertSame([true,true],\OCP\Server::$authorization->codeFrames);}
 public function testRevocationDuringReadPreventsPublishingResult():void{$this->expectException(RuntimeException::class);try{OriginContext::verifiedRead(static fn()=>null,static function():int{\OCP\Server::$authorization->revoked=true;return 42;});}finally{self::assertSame(2,\OCP\Server::$authorization->calls);}}
 public function testCodeChangedDuringFreshAuthorizationPreventsPublication():void{\OCP\Server::$authorization->during=static function():void{file_put_contents(self::$root.'/file0.php','<?php return 2;');};$this->expectException(RuntimeException::class);OriginContext::verifiedRead(static fn()=>null,static fn()=>42);}
 public function testReadFailureClosesBothFramesAndStillRechecksAuthority():void{$this->expectException(RuntimeException::class);try{OriginContext::verifiedRead(static fn()=>null,static function():never{throw new RuntimeException('read failure');});}finally{self::assertSame(2,\OCP\Server::$authorization->calls);}}
}
}
