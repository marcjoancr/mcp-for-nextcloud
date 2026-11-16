<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP { class IDBConnection {} final class Server { public static object $db; public static function get(string $class):object{return self::$db;} } }
namespace OCP\DB { class IResult {} }
namespace Doctrine\DBAL\Query { final class QueryBuilder { public const INSERT=1,UPDATE=2,DELETE=3; } }
namespace OCA\Circles\Db {
    class CoreQueryBuilder {
        public static string $sql=''; public static int $type=2; public static array $params=[]; public static int $sinks=0;
        private array $guards=[];
        public function __construct(){}
        public function andWhere(mixed $expr):static{$this->guards[]=$expr;return$this;}
        public function expr():object{return new class {public function eq(string $field,string $parameter):string{return$field.' = '.$parameter;}};}
        public function createNamedParameter(mixed $value):string{$name='guard'.count(self::$params);self::$params[$name]=$value;return':'.$name;}
        public function getSQL():string{return self::$sql.($this->guards===[]?'':' AND '.implode(' AND ',$this->guards));}
        public function getType():int{return self::$type;}
        public function getParameters():array{return self::$params;}
        public function executeQuery(?\OCP\IDBConnection $connection=null):\OCP\DB\IResult{throw new \RuntimeException('read not expected');}
        public function executeStatement(?\OCP\IDBConnection $connection=null):int{self::$sinks++;return 1;}
    }
}
namespace OCA\Forms\Mcp\Service\CircleDetached {
    final class Lease {public function __construct(public object $connection){} public function actorUid():string{return'owner';}public function db():object{return$this->connection;}public function checkpoint():void{}}
}
namespace {
    require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
    foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php'] as $api){require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$api;}
    require_once dirname(__DIR__).'/src/Service/CircleDetached/WriteQueryBuilder.php';
    final class CircleWriteQueryBuilderTest extends \PHPUnit\Framework\TestCase {
        private function query(\Closure $assert,\Closure $write):\OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder {
            $connection=new \OCP\IDBConnection();\OCP\Server::$db=$connection;
            \OCA\Circles\Db\CoreQueryBuilder::$sql='UPDATE oc_share SET permissions = :set WHERE id = :id';
            \OCA\Circles\Db\CoreQueryBuilder::$type=\Doctrine\DBAL\Query\QueryBuilder::UPDATE;
            \OCA\Circles\Db\CoreQueryBuilder::$params=['id'=>41];\OCA\Circles\Db\CoreQueryBuilder::$sinks=0;
            $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getSharedWith')->willReturn('circle-a');$share->method('getShareOwner')->willReturn('owner');$share->method('getNodeId')->willReturn(100);
            return new \OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder(new \OCA\Forms\Mcp\Service\CircleDetached\Lease($connection),'update',41,'circle-a','owner',100,$share,false,$assert,$write);
        }
        public function testNativeStatementSinkRunsAfterFreshAuthorityAndManagedCallback():void {
            $trace=[];$query=$this->query(static function()use(&$trace):void{$trace[]='fresh';},static function($share,bool $create)use(&$trace):void{$trace[]='locked';self::assertFalse($create);});
            self::assertSame(1,$query->executeStatement());self::assertSame(['fresh','fresh','locked','fresh'],$trace);self::assertSame(1,\OCA\Circles\Db\CoreQueryBuilder::$sinks);
        }
        public function testCreateInsertMustBindExactTypeCircleOwnerAndFile():void {
            $connection=new \OCP\IDBConnection();\OCP\Server::$db=$connection;
            \OCA\Circles\Db\CoreQueryBuilder::$sql='INSERT INTO oc_share (share_type, share_with, uid_owner, file_source) VALUES (:type, :circle, :owner, :file)';
            \OCA\Circles\Db\CoreQueryBuilder::$type=\Doctrine\DBAL\Query\QueryBuilder::INSERT;
            \OCA\Circles\Db\CoreQueryBuilder::$params=['type'=>7,'circle'=>'circle-a','owner'=>'owner','file'=>100];\OCA\Circles\Db\CoreQueryBuilder::$sinks=0;
            $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getSharedWith')->willReturn('circle-a');$share->method('getShareOwner')->willReturn('owner');$share->method('getNodeId')->willReturn(100);
            $trace=[];$query=new \OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder(new \OCA\Forms\Mcp\Service\CircleDetached\Lease($connection),'insert',0,'circle-a','owner',100,$share,true,static function()use(&$trace):void{$trace[]='fresh';},static function($actual,bool $create)use($share,&$trace):void{self::assertSame($share,$actual);self::assertTrue($create);$trace[]='create-boundary';});
            self::assertSame(1,$query->executeStatement());self::assertSame(['fresh','fresh','create-boundary','fresh'],$trace);self::assertSame(1,\OCA\Circles\Db\CoreQueryBuilder::$sinks);
        }
        public function testChangedAuthorityAfterManagedCallbackStopsBeforeStatement():void {
            $valid=true;$query=$this->query(static function()use(&$valid):void{if(!$valid){throw new \RuntimeException('changed authority');}},static function()use(&$valid):void{$valid=false;});
            try{$query->executeStatement();self::fail('Changed authority must block the native statement');}catch(\RuntimeException $error){self::assertSame('changed authority',$error->getMessage());}
            self::assertSame(0,\OCA\Circles\Db\CoreQueryBuilder::$sinks);
        }
        public function testChildUpdateBindsExactChildAndParentBeforeNativeSink():void {
            $connection=new \OCP\IDBConnection();\OCP\Server::$db=$connection;
            \OCA\Circles\Db\CoreQueryBuilder::$sql='UPDATE oc_share SET permissions = :set WHERE id = :id';
            \OCA\Circles\Db\CoreQueryBuilder::$type=\Doctrine\DBAL\Query\QueryBuilder::UPDATE;
            \OCA\Circles\Db\CoreQueryBuilder::$params=['set'=>1,'id'=>41];\OCA\Circles\Db\CoreQueryBuilder::$sinks=0;
            $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getSharedWith')->willReturn('circle-a');$share->method('getShareOwner')->willReturn('owner');$share->method('getNodeId')->willReturn(100);
            $trace=[];$query=new \OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder(new \OCA\Forms\Mcp\Service\CircleDetached\Lease($connection),'update-child',12,'circle-a','owner',100,$share,false,static function()use(&$trace):void{$trace[]='fresh';},static function($actual,bool $create,?array $binding)use(&$trace):void{self::assertFalse($create);self::assertSame(['child_id'=>41],$binding);$trace[]='boundary';},['child_id'=>41]);
            self::assertSame(1,$query->executeStatement());self::assertSame(['fresh','fresh','boundary','fresh'],$trace);self::assertMatchesRegularExpression('/id = :guard[0-9]+ AND parent = :guard[0-9]+$/',$query->getSQL());self::assertSame(1,\OCA\Circles\Db\CoreQueryBuilder::$sinks);
        }
        public function testChildUpdateRejectsChangedParentBeforeNativeSink():void {
            $connection=new \OCP\IDBConnection();\OCP\Server::$db=$connection;
            \OCA\Circles\Db\CoreQueryBuilder::$sql='UPDATE oc_share SET permissions = :set WHERE id = :id';
            \OCA\Circles\Db\CoreQueryBuilder::$type=\Doctrine\DBAL\Query\QueryBuilder::UPDATE;
            \OCA\Circles\Db\CoreQueryBuilder::$params=['set'=>1,'id'=>41];\OCA\Circles\Db\CoreQueryBuilder::$sinks=0;
            $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getSharedWith')->willReturn('circle-a');$share->method('getShareOwner')->willReturn('owner');$share->method('getNodeId')->willReturn(100);
            $query=new \OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder(new \OCA\Forms\Mcp\Service\CircleDetached\Lease($connection),'update-child',12,'circle-a','owner',100,$share,false,static fn()=>null,static fn()=>null,['child_id'=>42]);
            try{$query->executeStatement();self::fail('Changed child identity must block the native statement');}catch(\RuntimeException $error){self::assertSame('Native Circle write SQL lost its exact row identity',$error->getMessage());}
            self::assertSame(0,\OCA\Circles\Db\CoreQueryBuilder::$sinks);
        }
    }
}
