<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP { class IDBConnection {} final class Server { public static object $db; public static function get(string $class):object{return self::$db;} } }
namespace OCP\DB { class IResult {} }
namespace Doctrine\DBAL\Query { final class QueryBuilder { public const INSERT=1,UPDATE=2,DELETE=3; } }
namespace OCA\Circles\Db {
    class CoreQueryBuilder {
        public static string $sql=''; public static int $type=2; public static array $params=[]; public static int $sinks=0;public static array $trace=[];
        private array $guards=[];
        public function __construct(){}
        public function andWhere(mixed $expr):static{$this->guards[]=$expr;return$this;}
        public function expr():object{return new class {public function eq(string $field,string $parameter):string{return$field.' = '.$parameter;}};}
        public function createNamedParameter(mixed $value):string{$name='guard'.count(self::$params);self::$params[$name]=$value;return':'.$name;}
        public function getSQL():string{return self::$sql.($this->guards===[]?'':' AND '.implode(' AND ',$this->guards));}
        public function getType():int{return self::$type;}
        public function getParameters():array{return self::$params;}
        public function executeQuery(?\OCP\IDBConnection $connection=null):\OCP\DB\IResult{throw new \RuntimeException('read not expected');}
        public function executeStatement(?\OCP\IDBConnection $connection=null):int{self::$sinks++;self::$trace[]='sql';return 1;}
    }
}
namespace OCA\Forms\Mcp\Service\CircleDetached {
    final class Lease {public static bool $valid=true;public static bool $metadata=false;public function __construct(public object $connection){}public function actorUid():string{return'owner';}public function db():object{return$this->connection;}public function assertNoMetadataWrite():void{if(self::$metadata)throw new \RuntimeException('read cannot write');}public function checkpoint():void{\OCA\Circles\Db\CoreQueryBuilder::$trace[]='lease';if(!self::$valid)throw new \RuntimeException('revoked');}}final class ShareWriteBoundary {public function consume(mixed ...$args):void{\OCA\Circles\Db\CoreQueryBuilder::$trace[]='consume';}}
}
namespace {
    require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
    foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php'] as $api){require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$api;}
    require_once dirname(__DIR__).'/src/Service/CircleDetached/WriteQueryBuilder.php';

 final class CircleTransferWriteCheckpointTest extends \PHPUnit\Framework\TestCase {
 private function query(bool $transfer,?Closure $callback=null):\OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder{$db=new \OCP\IDBConnection();\OCP\Server::$db=$db;\OCA\Circles\Db\CoreQueryBuilder::$sql='UPDATE oc_share SET permissions = :set WHERE id = :id';\OCA\Circles\Db\CoreQueryBuilder::$type=2;\OCA\Circles\Db\CoreQueryBuilder::$params=['id'=>41];\OCA\Circles\Db\CoreQueryBuilder::$sinks=0;\OCA\Circles\Db\CoreQueryBuilder::$trace=[];\OCA\Forms\Mcp\Service\CircleDetached\Lease::$valid=true;\OCA\Forms\Mcp\Service\CircleDetached\Lease::$metadata=false;$share=$this->createMock(\OCP\Share\IShare::class);$share->method('getSharedWith')->willReturn('circle-a');$share->method('getShareOwner')->willReturn('owner');$share->method('getNodeId')->willReturn(100);return new \OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder(new \OCA\Forms\Mcp\Service\CircleDetached\Lease($db),'update',41,'circle-a','owner',100,$share,false,static function():void{\OCA\Circles\Db\CoreQueryBuilder::$trace[]='fresh';},static function()use($callback):void{\OCA\Circles\Db\CoreQueryBuilder::$trace[]='callback';if($callback!==null)$callback();},null,null,$transfer?new \OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary():null);}
 public function testBoundTransferKeepsTwoFullLeaseChecksAndManagedCallback():void{self::assertSame(1,$this->query(true)->executeStatement());self::assertSame(['lease','fresh','callback','consume','lease','fresh','sql'],\OCA\Circles\Db\CoreQueryBuilder::$trace);}
 public function testRevocationAtManagedCallbackCannotReachSql():void{$q=$this->query(true,static function():void{\OCA\Forms\Mcp\Service\CircleDetached\Lease::$valid=false;});$this->expectException(RuntimeException::class);try{$q->executeStatement();}finally{self::assertSame(0,\OCA\Circles\Db\CoreQueryBuilder::$sinks);}}
 public function testMetadataReadCannotReachSqlBeforeOrAfterCallback():void{foreach([false,true]as$late){$q=$this->query(true,$late?static function():void{\OCA\Forms\Mcp\Service\CircleDetached\Lease::$metadata=true;}:null);if(!$late)\OCA\Forms\Mcp\Service\CircleDetached\Lease::$metadata=true;$denied=false;try{$q->executeStatement();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);self::assertSame(0,\OCA\Circles\Db\CoreQueryBuilder::$sinks);}}
 public function testOrdinaryPathRetainsOriginalExplicitAssertions():void{$this->query(false)->executeStatement();self::assertSame(['lease','fresh','fresh','callback','lease','fresh','sql'],\OCA\Circles\Db\CoreQueryBuilder::$trace);}
 public function testTransferStatementCannotBeReused():void{$q=$this->query(true);$q->executeStatement();$this->expectException(RuntimeException::class);try{$q->executeStatement();}finally{self::assertSame(1,\OCA\Circles\Db\CoreQueryBuilder::$sinks);}}
 }
}
