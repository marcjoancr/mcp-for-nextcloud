<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function inTransaction():bool;public function executeQuery(string $sql,array $params):object;}}
namespace OCA\Forms\Mcp\Service {class BulkStore {}class OriginContext {public static function assertJobInput(array $job):void{}}}
namespace {
require_once __DIR__.'/../src/Service/CoreShareIdentity.php';require_once __DIR__.'/../src/Service/FinalShareRows.php';require_once __DIR__.'/../src/Service/BulkTransferShares.php';
use OCA\Forms\Mcp\Service\{BulkTransferShares,BulkStore};
final class CircleLockedParentStateTest extends \PHPUnit\Framework\TestCase {
 private object $db;private BulkTransferShares $service;private array $job=['id'=>'job','uid'=>'actor','action'=>'forms_transfer','phase'=>'apply','input'=>['owner_id'=>'target']];private array $record=['approved_tuple'=>['type'=>7,'recipient'=>'circle'],'share_state'=>['accepted'=>1,'attributes'=>null,'expiration'=>null]];private array $mapping=['files_share_id'=>'ocCircleShare:31','folder_id'=>11];
 protected function setUp():void{$this->db=new class implements \OCP\IDBConnection {public bool $transaction=true;public int $reads=0;public int $closed=0;public array|false $row=['id'=>31,'share_type'=>7,'share_with'=>'circle','uid_owner'=>'target','uid_initiator'=>'actor','file_source'=>11,'item_type'=>'folder','parent'=>null,'permissions'=>0,'accepted'=>1,'expiration'=>null,'attributes'=>null,'stime'=>123];public function inTransaction():bool{return$this->transaction;}public function executeQuery(string $sql,array $params):object{$this->reads++;return new class($this,$this->row){public function __construct(private object $db,private array|false $row){}public function fetch():array|false{return$this->row;}public function closeCursor():void{$this->db->closed++;}};}};$this->service=new BulkTransferShares($this->db,new BulkStore());}
 private function assertParent(int $permission):void{(new ReflectionMethod($this->service,'assertCircleParentState'))->invoke($this->service,$this->job,$this->record,$this->mapping,$permission);}
 public function testQuarantineIdentityIsCheckedWithoutGrantingPermissions():void{$this->assertParent(0);self::assertSame(0,$this->db->row['permissions']);self::assertSame(1,$this->db->closed);}
 public function testActiveParentIsReadAgainBeforeEachChild():void{$this->db->row['permissions']=1;$this->assertParent(1);$this->db->row['uid_owner']='changed';$this->expectException(RuntimeException::class);try{$this->assertParent(1);}finally{self::assertSame(2,$this->db->reads);self::assertSame(2,$this->db->closed);}}
 public function testQuarantinedParentCannotBeUsedAsActive():void{$this->expectException(RuntimeException::class);$this->assertParent(1);}
 public function testWrongFileAndRecipientAndRootAreDenied():void{foreach(['file_source'=>12,'share_with'=>'other','parent'=>40,'share_type'=>1,'accepted'=>2,'uid_initiator'=>'other']as$key=>$value){$before=$this->db->row;$this->db->row[$key]=$value;$denied=false;try{$this->assertParent(0);}catch(RuntimeException){$denied=true;}self::assertTrue($denied);$this->db->row=$before;}}
 public function testMissingRowIsDenied():void{$this->db->row=false;$this->expectException(RuntimeException::class);$this->assertParent(0);}
 public function testEndedTransactionCannotUseTheParent():void{$this->db->transaction=false;$this->expectException(RuntimeException::class);$this->assertParent(0);}
}
}
