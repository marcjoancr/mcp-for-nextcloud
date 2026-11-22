<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {}}namespace OCP\DB {interface IResult {}}
namespace Doctrine\DBAL\Query {class QueryBuilder {public const SELECT=0;}}
namespace OC\DB\QueryBuilder {class QueryBuilder {private object $backend;private string $sql='SELECT id FROM *PREFIX*circles_circle';public function __construct(object $db,object $system,object $logger){$this->backend=$db->getInner();}public function getSQL():string{return$this->sql;}public function getParameters():array{return[];}public function getParameterTypes():array{return[];}public function getType():int{return 0;}public function executeQuery(?\OCP\IDBConnection $connection=null):\OCP\DB\IResult{throw new \LogicException('native query execution bypass');}}}
namespace OCA\Circles\Model {class Member {public const LEVEL_MEMBER=1;}}
namespace OCA\Circles\Db {class CoreQueryBuilder extends \OC\DB\QueryBuilder\QueryBuilder {}}namespace OCA\Circles\Tools\Traits {trait TArrayTools {}}
namespace OCA\Forms\Mcp\Service {
final class FinalReadConnection {public int $queries=0;public int $inners=0;public bool $foreign=false;public function getInner():never{$this->inners++;throw new \LogicException('raw connection remains closed');}public function getQueryBuilder():object{$physical=new class {public function getInner():object{return new \stdClass();}};$q=new \OC\DB\QueryBuilder\QueryBuilder($physical,new \stdClass(),new \stdClass());return$this->foreign?$q:FinalReadQuery::capture($q,$this);}public function executeQuery(string $sql,array $params,array $types):\OCP\DB\IResult{$this->queries++;if($sql!=='SELECT id FROM *PREFIX*circles_circle')throw new \LogicException('different sql');return new class implements \OCP\DB\IResult {};}}
}
namespace OCA\Forms\Mcp\Service\CircleDetached {class Lease {public int $checks=0;public function __construct(public object $connection){}public function db():object{return$this->connection;}public function system():object{return new \stdClass();}public function logger():object{return new \stdClass();}public function config():object{return new \stdClass();}public function assertActive():void{}public function checkpoint():void{$this->checks++;}}}
namespace {
require_once __DIR__.'/../src/Service/FinalReadQuery.php';require_once __DIR__.'/../src/Service/CircleDetached/CoreQueryBuilder.php';
use OCA\Forms\Mcp\Service\{FinalReadConnection,CircleDetached\CoreQueryBuilder,CircleDetached\Lease};
final class CircleFinalReadBuilderTest extends \PHPUnit\Framework\TestCase {
 private function builder(object $db):CoreQueryBuilder{$q=(new ReflectionClass(CoreQueryBuilder::class))->newInstanceWithoutConstructor();$q->bindCircleLease(new Lease($db));$q->__construct();return$q;}
 public function testFinalBuilderConstructsWithoutOpeningRawConnection():void{$db=new FinalReadConnection();self::assertSame('SELECT id FROM *PREFIX*circles_circle',$this->builder($db)->getSQL());self::assertSame(0,$db->inners);}
 public function testOwnedSelectStillRunsThroughRecorder():void{$db=new FinalReadConnection();$q=$this->builder($db);(new ReflectionMethod($q,'executeOwnedSelect'))->invoke($q);self::assertSame(1,$db->queries);self::assertSame(0,$db->inners);}
 public function testAlternateBuilderIsRejected():void{$db=new FinalReadConnection();$db->foreign=true;$this->expectException(RuntimeException::class);$this->builder($db);}
 public function testRawConnectionAndStatementExecutionRemainClosed():void{$q=$this->builder(new FinalReadConnection());foreach(['getConnection','executeQuery','executeStatement']as$method){$denied=false;try{$q->$method();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
 public function testOrdinaryLeaseStillUsesNativeConstructor():void{$db=new class {public int $inners=0;public function getInner():object{$this->inners++;return new stdClass();}};$this->builder($db);self::assertSame(1,$db->inners);}
}
}
