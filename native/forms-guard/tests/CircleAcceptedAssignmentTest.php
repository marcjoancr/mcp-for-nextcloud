<?php
declare(strict_types=1);
namespace OCP\DB\QueryBuilder {interface IQueryBuilder {public const PARAM_INT=1;}}
namespace OCA\Circles\Db {class CoreQueryBuilder {public array $parts=[];public array $parameters=[];public array $types=[];public function getQueryPart(string $name):array{return$this->parts;}public function getParameters():array{return$this->parameters;}public function setParameter(string $key,mixed $value,int $type):void{$this->parameters[$key]=$value;$this->types[$key]=$type;}}}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/WriteQueryBuilder.php';
use OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder;
final class CircleAcceptedAssignmentTest extends \PHPUnit\Framework\TestCase {
 private function qb(array $set,array $params):WriteQueryBuilder{$q=(new ReflectionClass(WriteQueryBuilder::class))->newInstanceWithoutConstructor();$q->parts=$set;$q->parameters=$params;return$q;}
 private function replace(WriteQueryBuilder $q,int $decision):void{(new ReflectionMethod($q,'replaceAcceptedDecision'))->invoke($q,$decision);}
 public function testNativeAssignmentIsReboundWithoutAddingAnotherSet():void{foreach(['"accepted" = :dc5','`accepted` = :dc5','accepted = :dc5']as$accepted){$parts=['"permissions" = :dc6',$accepted,'"note" = :dc7'];$q=$this->qb($parts,['dc5'=>1,'dc6'=>1,'dc7'=>'note']);$this->replace($q,2);self::assertSame($parts,$q->parts);self::assertSame(['dc5'=>2,'dc6'=>1,'dc7'=>'note'],$q->parameters);self::assertSame(['dc5'=>1],$q->types);}}
 public function testDuplicateAcceptedAssignmentsAreDenied():void{$q=$this->qb(['accepted = :a','"accepted" = :b'],['a'=>1,'b'=>1]);$this->expectException(RuntimeException::class);$this->replace($q,2);}
 public function testMissingAcceptedAssignmentIsDenied():void{$q=$this->qb(['permissions = :a'],['a'=>1]);$this->expectException(RuntimeException::class);$this->replace($q,2);}
 public function testMissingParameterIsDenied():void{$q=$this->qb(['accepted = :a'],[]);$this->expectException(RuntimeException::class);$this->replace($q,2);}
}
}
