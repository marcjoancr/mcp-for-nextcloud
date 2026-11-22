<?php
declare(strict_types=1);
namespace OCP {class Server {public static function get(string $class):object{return new class {public function inTransaction():bool{return true;}};}}}
namespace OCA\Forms\Mcp\Service {
 class OriginContext {public static function active():bool{return true;}public static function transferSharingAuthority():array{return['fresh'=>static fn()=>null,'frame'=>static fn()=>null];}}
 class BulkStore {public array $job;public array $rows;public int $target=41;public function load(string $id):array{return$this->job;}public function one(string $id,string $kind,int $source):array{return$this->rows[$kind];}public function mapped(string $id,string $kind,int $source):int{return$this->target;}public static function digest(array $row):string{return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));}}
 class BulkTransferShares {public static function activateCircle(BulkStore $store,array $job,array $row,array $mapping,array $child):object{return CircleDetached\ShareWriteBoundary::bulkTransferActivation($store,$job,$row,$mapping,$child);}}
}
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/IShare.php';
require_once __DIR__.'/../src/Service/CoreShareIdentity.php';
require_once __DIR__.'/../src/Service/CircleDetached/ShareWriteBoundary.php';
use OCA\Forms\Mcp\Service\{BulkStore,BulkTransferShares};
final class CircleActivationMappingTest extends \PHPUnit\Framework\TestCase {
 private BulkStore $store;private array $job;private array $row;private array $mapping;private array $child;
 protected function setUp():void{$this->job=['id'=>'job','action'=>'forms_transfer','phase'=>'apply','state'=>'pending','version'=>9,'uid'=>'actor','form'=>['id'=>2],'input'=>['owner_id'=>'target']];$source=['files_share_id'=>'ocCircleShare:3','approved_tuple'=>['type'=>7,'recipient'=>'circle']];$this->mapping=['files_share_id'=>'ocCircleShare:31','approved_tuple'=>['type'=>7,'owner_id'=>'target'],'folder_id'=>11];$data=['id'=>4,'parent'=>3,'share_type'=>7,'share_with'=>'single','permissions'=>1];$this->row=['id'=>3,'data'=>$source,'digest'=>BulkStore::digest($source)];$this->child=['id'=>4,'data'=>$data,'digest'=>BulkStore::digest($data)];$this->store=new BulkStore();$this->store->job=$this->job;$this->store->rows=['managed_shares'=>$source,'staged_shares'=>$this->mapping,'share_recipients'=>$data,'staged_recipients'=>['parent'=>31,'share_type'=>7,'share_with'=>'single','permissions'=>0]];}
 private function issue():object{return BulkTransferShares::activateCircle($this->store,$this->job,$this->row,$this->mapping,$this->child);}
 public function testSeparateMappedIdentifierAcceptsTheActualStagedProjection():void{$cap=$this->issue();self::assertSame(41,(new ReflectionProperty($cap,'targetChildId'))->getValue($cap));}
 private function consume(object $cap,?array $binding=null):void{$share=$this->createMock(\OCP\Share\IShare::class);foreach(['getShareType'=>7,'getSharedWith'=>'circle','getShareOwner'=>'target','getSharedBy'=>'actor','getNodeId'=>11,'getPermissions'=>1]as$m=>$v)$share->method($m)->willReturn($v);$cap->consume('actor','update-child',$share,false,$binding??['child_id'=>41]);}
 public function testActualProjectionCanBeConsumedOnce():void{$cap=$this->issue();$this->consume($cap);self::assertTrue((new ReflectionProperty($cap,'consumed'))->getValue($cap));$this->expectException(RuntimeException::class);$this->consume($cap);}
 public function testChangedSeparateMappingIsDeniedAtConsumption():void{$cap=$this->issue();$this->store->target=42;$this->expectException(RuntimeException::class);$this->consume($cap);}
 public function testWrongWriteBindingIsDenied():void{$cap=$this->issue();$this->expectException(RuntimeException::class);$this->consume($cap,['child_id'=>42]);}
 public function testChangedQuarantineIsDeniedAtConsumption():void{$cap=$this->issue();$this->store->rows['staged_recipients']['permissions']=1;$this->expectException(RuntimeException::class);$this->consume($cap);}
 public function testWrongStagedParentIsDenied():void{$this->store->rows['staged_recipients']['parent']=32;$this->expectException(RuntimeException::class);$this->issue();}
 public function testDifferentRecipientIsDenied():void{$this->store->rows['staged_recipients']['share_with']='different';$this->expectException(RuntimeException::class);$this->issue();}
 public function testDifferentProviderIsDenied():void{$this->store->rows['staged_recipients']['share_type']=2;$this->expectException(RuntimeException::class);$this->issue();}
 public function testAlreadyActivatedStagedRecipientIsDenied():void{$this->store->rows['staged_recipients']['permissions']=1;$this->expectException(RuntimeException::class);$this->issue();}
}
}
