<?php
declare(strict_types=1);
namespace OCA\Circles\Db {class CoreQueryBuilder {}}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/WriteQueryBuilder.php';
use OCA\Forms\Mcp\Service\CircleDetached\WriteQueryBuilder;
final class CircleWriteSqlBindingTest extends \PHPUnit\Framework\TestCase {
 private function binding(string $sql,array $params,string $column='share_type',mixed $value=7):bool {$m=(new ReflectionClass(WriteQueryBuilder::class))->newInstanceWithoutConstructor();return(new ReflectionMethod($m,'hasBinding'))->invoke($m,$sql,$params,$column,$value);}
 public function testNativePgAndMariaQuotedIdentifiersRemainBound():void{foreach(['"share_type" = :p','`share_type` = :p','"s"."share_type" = :p','share_type = :p']as$sql)self::assertTrue($this->binding($sql,['p'=>7]));}
 public function testConflictingAssignmentsCannotPass():void{self::assertFalse($this->binding('SET "uid_owner" = :a WHERE "uid_owner" = :b',['a'=>'target','b'=>'other'],'uid_owner','target'));}
 public function testSubstringsCannotMasqueradeAsColumn():void{foreach(['other_share_type = :p','share_type_suffix = :p','"other_share_type" = :p']as$sql)self::assertFalse($this->binding($sql,['p'=>7]));}
 public function testDeleteCannotBorrowAnotherParentsChildren():void{$sql='DELETE FROM "share" WHERE ("id" = :root OR "parent" = :root) AND ("share_with" = :circle OR "parent" = :scope)';self::assertTrue($this->binding($sql,['root'=>31,'circle'=>'circle','scope'=>31],'parent',31));self::assertFalse($this->binding($sql,['root'=>31,'circle'=>'circle','scope'=>32],'parent',31));}
 public function testMissingOrWrongTypedBindingIsDenied():void{self::assertFalse($this->binding('"share_type" = :p',[]));self::assertFalse($this->binding('"share_type" = :p',['p'=>'7']));}
}
}
