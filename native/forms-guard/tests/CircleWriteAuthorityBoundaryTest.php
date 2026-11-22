<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function inTransaction():bool;}}
namespace OCP\Files {interface Folder {}}
namespace OCA\Forms\Mcp\Service {
final class BulkStore {}final class BulkDeadline {public static function check():void{}}
final class OriginContext {public static array $events=[];public static bool $revoked=false;public static array $impact=[];public static function current():array{return['file_action'=>'forms_transfer','file_impact'=>self::$impact];}public static function assertJobInput(array $job):void{}public static function assertFileEffect():void{self::$events[]='file';if(self::$revoked)throw new \RuntimeException('revoked');}public static function verifiedRead(\Closure $frame,\Closure $read):void{$frame();self::$events[]='read';/* Pure read body has separate ACL coverage; this test exercises boundary dispatch. */}}
}
namespace {
require_once __DIR__.'/../src/Service/BulkTransferShares.php';
use OCA\Forms\Mcp\Service\{BulkTransferShares,BulkStore,OriginContext};
final class CircleWriteAuthorityBoundaryTest extends \PHPUnit\Framework\TestCase {
 private BulkTransferShares $service;private object $db;private object $folder;private array $job=['id'=>'job','action'=>'forms_transfer','phase'=>'apply'];private array $record=['folder_id'=>11,'approved_tuple'=>['type'=>7,'recipient'=>'circle']];
 protected function setUp():void{$this->db=new class implements \OCP\IDBConnection {public bool $live=true;public function inTransaction():bool{return$this->live;}};$this->folder=new class implements \OCP\Files\Folder {};$this->service=new BulkTransferShares($this->db,new BulkStore());(new ReflectionProperty($this->service,'finalAudienceScope'))->setValue($this->service,['job',static fn()=>null]);OriginContext::$events=[];OriginContext::$revoked=false;OriginContext::$impact=['share_impact'=>['parent'=>['file_id'=>11],'folder_ids'=>[11],'recipient'=>'circle','type'=>7]];}
 private function authorize():void{(new ReflectionMethod($this->service,'circleWriteAuthority'))->invoke($this->service,$this->job,$this->record,$this->folder);}
 public function testOneFreshExternalBoundaryPrecedesEachPureRead():void{$this->authorize();self::assertSame(['file','read'],OriginContext::$events);$this->authorize();self::assertSame(['file','read','file','read'],OriginContext::$events);}
 public function testRevocationCannotReusePreviousAdmission():void{$this->authorize();OriginContext::$revoked=true;$this->expectException(RuntimeException::class);try{$this->authorize();}finally{self::assertSame(['file','read','file'],OriginContext::$events);}}
 public function testWrongFolderImpactIsDeniedBeforeAnyBoundary():void{foreach(['folder_ids'=>[12],'parent'=>['file_id'=>12],'recipient'=>'other','type'=>1]as$key=>$value){$saved=OriginContext::$impact;OriginContext::$impact['share_impact'][$key]=$value;$denied=false;try{$this->authorize();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);OriginContext::$impact=$saved;}self::assertSame([],OriginContext::$events);}
 public function testEndedTransactionIsDenied():void{$this->db->live=false;$this->expectException(RuntimeException::class);$this->authorize();}
 public function testMissingFinalScopeIsDenied():void{(new ReflectionProperty($this->service,'finalAudienceScope'))->setValue($this->service,null);$this->expectException(RuntimeException::class);$this->authorize();}
 public function testDifferentJobAndPhaseCannotUseFinalScope():void{foreach(['id'=>'other','phase'=>'stage_recipients']as$key=>$value){$saved=$this->job;$this->job[$key]=$value;$denied=false;try{$this->authorize();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);$this->job=$saved;}self::assertSame([],OriginContext::$events);}
}
}
