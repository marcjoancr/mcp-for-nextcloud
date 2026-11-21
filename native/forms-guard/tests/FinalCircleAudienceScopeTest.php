<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function inTransaction():bool;}class Server {public static object $fence;public static function get(string $class):object{return self::$fence;}}}
namespace OCA\Forms\Mcp\Service {
 class BulkStore {}
 class FormsRevisionFence {public bool $live=true;public function active():bool{return$this->live;}}
 class OriginContext {public static function assertJobInput(array $job):void{}public static function transferSharingAuthority():array{return['frame'=>static fn()=>null];}}
 final class BulkFiles {public function commitWithManagedShares(BulkTransferShares $service,array $job,callable $commit):mixed{return$service->finalAudienceReplay($job,$commit);}}
}
namespace {
require_once __DIR__.'/../src/Service/BulkTransferShares.php';
use OCA\Forms\Mcp\Service\{BulkTransferShares,BulkFiles,BulkStore,FormsRevisionFence};
final class FinalCircleAudienceScopeTest extends \PHPUnit\Framework\TestCase {
 private object $db;private BulkTransferShares $service;private array $job=['id'=>'job','action'=>'forms_transfer','phase'=>'apply'];
 protected function setUp():void{$this->db=new class implements \OCP\IDBConnection {public bool $live=true;public function inTransaction():bool{return$this->live;}};\OCP\Server::$fence=new FormsRevisionFence();$this->service=new BulkTransferShares($this->db,new BulkStore());}
 private function scope():mixed{return(new ReflectionProperty($this->service,'finalAudienceScope'))->getValue($this->service);}
 private function runScope(callable $work):mixed{return(new BulkFiles())->commitWithManagedShares($this->service,$this->job,$work);}
 public function testCommitScopeIsPrivateAndEndsBeforeFinalReplay():void{self::assertSame(9,$this->runScope(function():int{self::assertNotNull($this->scope());return 9;}));self::assertNull($this->scope());}
 public function testFailureCannotLeaveDeferredAudienceEnabled():void{$this->expectException(RuntimeException::class);try{$this->runScope(static function():never{throw new RuntimeException('failure');});}finally{self::assertNull($this->scope());}}
 public function testUnqualifiedIssuerCannotDeferAudience():void{$ran=false;$this->expectException(RuntimeException::class);try{$this->service->finalAudienceReplay($this->job,static function()use(&$ran):void{$ran=true;});}finally{self::assertFalse($ran);self::assertNull($this->scope());}}
 public function testOtherPhasesCannotDeferAudience():void{$this->job['phase']='stage_shares';$this->expectException(RuntimeException::class);$this->runScope(static fn()=>null);}
 public function testEndedTransactionCannotDeferAudience():void{$this->db->live=false;$this->expectException(RuntimeException::class);$this->runScope(static fn()=>null);}
 public function testNestedScopeCannotReplaceOriginalJob():void{$this->expectException(RuntimeException::class);try{$this->runScope(fn()=>$this->runScope(static fn()=>null));}finally{self::assertNull($this->scope());}}
}
}
